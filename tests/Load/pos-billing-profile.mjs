import { createHash } from 'node:crypto';

const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const iterations = Number(process.env.POS_LOAD_ITERATIONS || 5);
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 30000);
const terminalId = process.env.POS_LOAD_TERMINAL_ID || 'profile-billing-terminal';

if (!token) {
    console.error('POS_LOAD_TOKEN is required.');
    process.exit(2);
}

function percentile(values, fraction) {
    const sorted = [...values].sort((left, right) => left - right);
    return sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)] || 0;
}

function timingValue(header, metric) {
    const part = String(header || '')
        .split(',')
        .map((value) => value.trim())
        .find((value) => value.startsWith(`${metric};dur=`));

    return part ? Number(part.slice(`${metric};dur=`.length)) : null;
}

async function api(method, path, body = null, headers = {}) {
    const started = performance.now();
    const response = await fetch(`${baseUrl}${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            Authorization: `Bearer ${token}`,
            'X-NexDine-Device-Id': terminalId,
            ...headers,
        },
        body: body ? JSON.stringify(body) : null,
        signal: AbortSignal.timeout(timeoutMs),
    });
    const text = await response.text();

    return {
        status: response.status,
        ok: response.ok,
        client_ms: performance.now() - started,
        app_ms: timingValue(response.headers.get('server-timing'), 'app'),
        db_ms: timingValue(response.headers.get('server-timing'), 'db'),
        query_count: Number(response.headers.get('x-query-count') || 0),
        bytes: Buffer.byteLength(text),
        json: text ? JSON.parse(text) : null,
    };
}

function stats(values) {
    const numbers = values.filter((value) => Number.isFinite(value));

    return {
        avg: Number((numbers.reduce((total, value) => total + value, 0) / numbers.length).toFixed(2)),
        p50: Number(percentile(numbers, 0.5).toFixed(2)),
        p95: Number(percentile(numbers, 0.95).toFixed(2)),
        p99: Number(percentile(numbers, 0.99).toFixed(2)),
        max: Number(Math.max(...numbers).toFixed(2)),
    };
}

async function createOrder() {
    const cartId = crypto.randomUUID();
    const config = await api('GET', `/pos/viewer/${cartId}/configuration?lightweight=1`);
    const data = config.json.body || {};
    const menuId = data.menu_id || data.menus?.[0]?.id;
    const branchId = data.branch_id;
    const registerId = data.register_id;
    const sessionId = data.session_id;
    const orderType = data.order_types?.some((type) => type.id === 'takeaway') ? 'takeaway' : data.order_types?.[0]?.id;
    let tableId = null;

    if (orderType === 'dine_in') {
        tableId = process.env.POS_LOAD_TABLE_ID ? Number(process.env.POS_LOAD_TABLE_ID) : null;
        if (! tableId) {
            const tables = await api('GET', '/tables/viewer');
            tableId = tables.json?.body?.tables
                ?.find((table) => table.status?.id === 'available' && Number(table.capacity || 0) >= 1)
                ?.id || null;
        }
    }

    const menuParams = new URLSearchParams({ order_type: orderType });
    if (tableId) {
        menuParams.set('table_id', String(tableId));
    }

    const menu = await api('GET', `/pos/viewer/${cartId}/menu-items/${menuId}?${menuParams}`);
    const productId = menu.json?.body?.products?.find((product) => product.is_available)?.id;

    await api('POST', `/cart/${cartId}/order-types/${orderType}`, tableId ? { table_id: tableId } : null);
    await api('POST', `/cart/${cartId}/items`, {
        product_id: productId,
        qty: 1,
        ...(tableId ? { table_id: tableId } : {}),
    });

    const order = await api('POST', `/orders/${cartId}`, {
        submit_action: 'hold_order',
        menu_id: menuId,
        branch_id: branchId,
        type: orderType,
        guest_count: 1,
        ...(tableId ? { table_id: tableId } : {}),
        register_id: registerId,
        session_id: sessionId,
    }, {
        'Idempotency-Key': `profile-billing-order-${crypto.randomUUID()}`,
    });

    if (!order.ok) {
        throw new Error(`Order create failed: ${order.status} ${JSON.stringify(order.json)}`);
    }

    return {
        orderRef: order.json?.body?.order_id,
        registerId,
        sessionId,
    };
}

const metaSamples = [];
const paymentSamples = [];

for (let index = 0; index < iterations; index++) {
    const context = await createOrder();
    const meta = await api('GET', `/orders/${context.orderRef}/payments/meta`);

    if (!meta.ok) {
        console.error('Payment meta failed.', meta.status, meta.json);
        process.exit(1);
    }

    metaSamples.push(meta);

    const dueAmount = Number(meta.json?.body?.order?.due_amount?.amount || 0);
    const payment = await api('POST', `/orders/${context.orderRef}/payments`, {
        payments: [{ method: 'cash', amount: dueAmount }],
        payment_mode: 'full',
        with_print: false,
        customer_given_amount: dueAmount,
        change_return: 0,
        register_id: context.registerId,
        session_id: context.sessionId,
    }, {
        'Idempotency-Key': `profile-payment-${createHash('sha256').update(`${context.orderRef}-${index}-${Date.now()}`).digest('hex')}`,
    });

    if (!payment.ok) {
        console.error('Payment failed.', payment.status, payment.json);
        process.exit(1);
    }

    paymentSamples.push(payment);
}

console.log(JSON.stringify({
    iterations,
    payment_meta_ms: {
        client: stats(metaSamples.map((sample) => sample.client_ms)),
        app: stats(metaSamples.map((sample) => sample.app_ms)),
        db: stats(metaSamples.map((sample) => sample.db_ms)),
        query_count: stats(metaSamples.map((sample) => sample.query_count)),
        response_bytes: stats(metaSamples.map((sample) => sample.bytes)),
    },
    payment_store_ms: {
        client: stats(paymentSamples.map((sample) => sample.client_ms)),
        app: stats(paymentSamples.map((sample) => sample.app_ms)),
        db: stats(paymentSamples.map((sample) => sample.db_ms)),
        query_count: stats(paymentSamples.map((sample) => sample.query_count)),
        response_bytes: stats(paymentSamples.map((sample) => sample.bytes)),
    },
}, null, 2));
