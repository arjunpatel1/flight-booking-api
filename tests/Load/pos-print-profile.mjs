const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const iterations = Number(process.env.POS_LOAD_ITERATIONS || 3);
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 30000);
const terminalId = process.env.POS_LOAD_TERMINAL_ID || 'profile-print-terminal';

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
        'Idempotency-Key': `profile-print-order-${crypto.randomUUID()}`,
    });

    if (!order.ok) {
        throw new Error(`Order create failed: ${order.status} ${JSON.stringify(order.json)}`);
    }

    return {
        orderRef: order.json?.body?.order_id,
        registerId,
    };
}

const printSamples = [];
const failures = [];

for (let index = 0; index < iterations; index++) {
    const context = await createOrder();
    const print = await api('POST', `/orders/${context.orderRef}/print/bill`, {
        specific_id: context.registerId,
    }, {
        'Idempotency-Key': `profile-print-${crypto.randomUUID()}`,
    });

    if (!print.ok) {
        failures.push({ status: print.status, body: print.json });
        continue;
    }

    printSamples.push(print);
}

console.log(JSON.stringify({
    iterations,
    successful_print_dispatches: printSamples.length,
    failures,
    print_dispatch_ms: printSamples.length ? {
        client: stats(printSamples.map((sample) => sample.client_ms)),
        app: stats(printSamples.map((sample) => sample.app_ms)),
        db: stats(printSamples.map((sample) => sample.db_ms)),
        query_count: stats(printSamples.map((sample) => sample.query_count)),
        response_bytes: stats(printSamples.map((sample) => sample.bytes)),
    } : null,
}, null, 2));
