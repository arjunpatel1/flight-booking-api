const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const iterations = Number(process.env.POS_LOAD_ITERATIONS || 10);
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 30000);
const terminalId = process.env.POS_LOAD_TERMINAL_ID || 'profile-order-terminal';

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

async function bootstrap() {
    const cartId = crypto.randomUUID();
    const config = await api('GET', `/pos/viewer/${cartId}/configuration?lightweight=1`);
    if (!config.ok) {
        throw new Error(`Configuration failed: ${config.status} ${JSON.stringify(config.json)}`);
    }

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

    const params = new URLSearchParams({ order_type: orderType });
    if (tableId) {
        params.set('table_id', String(tableId));
    }

    const menu = await api('GET', `/pos/viewer/${cartId}/menu-items/${menuId}?${params}`);
    const productId = menu.json?.body?.products?.find((product) => product.is_available)?.id;

    if (!menuId || !branchId || !registerId || !sessionId || !orderType || !productId || (orderType === 'dine_in' && !tableId)) {
        throw new Error(`Incomplete bootstrap context: ${JSON.stringify({ menuId, branchId, registerId, sessionId, orderType, productId, tableId })}`);
    }

    return { cartId, menuId, branchId, registerId, sessionId, orderType, productId, tableId };
}

const setupSamples = [];
const createSamples = [];
const orders = [];

for (let index = 0; index < iterations; index++) {
    const context = await bootstrap();
    const setupStarted = performance.now();

    const orderTypeResponse = await api(
        'POST',
        `/cart/${context.cartId}/order-types/${context.orderType}`,
        context.tableId ? { table_id: context.tableId } : null
    );
    const itemResponse = await api('POST', `/cart/${context.cartId}/items`, {
        product_id: context.productId,
        qty: 1,
        ...(context.tableId ? { table_id: context.tableId } : {}),
    });

    setupSamples.push({
        client_ms: performance.now() - setupStarted,
        app_ms: (orderTypeResponse.app_ms || 0) + (itemResponse.app_ms || 0),
        db_ms: (orderTypeResponse.db_ms || 0) + (itemResponse.db_ms || 0),
        query_count: (orderTypeResponse.query_count || 0) + (itemResponse.query_count || 0),
    });

    if (!orderTypeResponse.ok || !itemResponse.ok) {
        console.error('Cart setup failed.', orderTypeResponse.status, itemResponse.status, orderTypeResponse.json, itemResponse.json);
        process.exit(1);
    }

    const createResponse = await api('POST', `/orders/${context.cartId}`, {
        submit_action: 'hold_order',
        menu_id: context.menuId,
        branch_id: context.branchId,
        type: context.orderType,
        guest_count: 1,
        ...(context.tableId ? { table_id: context.tableId } : {}),
        register_id: context.registerId,
        session_id: context.sessionId,
    }, {
        'Idempotency-Key': `profile-order-${crypto.randomUUID()}`,
    });

    if (!createResponse.ok) {
        console.error('Order create failed.', createResponse.status, createResponse.json);
        process.exit(1);
    }

    createSamples.push(createResponse);
    orders.push(createResponse.json?.body?.order_id || null);
}

console.log(JSON.stringify({
    iterations,
    created_orders: orders.filter(Boolean).length,
    cart_setup_ms: {
        client: stats(setupSamples.map((sample) => sample.client_ms)),
        app: stats(setupSamples.map((sample) => sample.app_ms)),
        db: stats(setupSamples.map((sample) => sample.db_ms)),
        query_count: stats(setupSamples.map((sample) => sample.query_count)),
    },
    order_create_ms: {
        client: stats(createSamples.map((sample) => sample.client_ms)),
        app: stats(createSamples.map((sample) => sample.app_ms)),
        db: stats(createSamples.map((sample) => sample.db_ms)),
        query_count: stats(createSamples.map((sample) => sample.query_count)),
        response_bytes: stats(createSamples.map((sample) => sample.bytes)),
    },
}, null, 2));
