const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const cartId = process.env.POS_LOAD_CART_ID || crypto.randomUUID();
const iterations = Number(process.env.POS_LOAD_ITERATIONS || 30);
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 10000);
const terminalId = process.env.POS_LOAD_TERMINAL_ID || 'profile-menu-terminal';

if (!token) {
    console.error('POS_LOAD_TOKEN is required.');
    process.exit(2);
}

function percentile(values, fraction) {
    if (!values.length) {
        return 0;
    }

    const sorted = [...values].sort((left, right) => left - right);
    return sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)];
}

function timingValue(header, metric) {
    const part = String(header || '')
        .split(',')
        .map((value) => value.trim())
        .find((value) => value.startsWith(`${metric};dur=`));

    return part ? Number(part.slice(`${metric};dur=`.length)) : null;
}

async function api(path) {
    const started = performance.now();
    const response = await fetch(`${baseUrl}${path}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            'X-NexDine-Device-Id': terminalId,
        },
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

const configuration = await api(`/pos/viewer/${cartId}/configuration?lightweight=1`);
if (!configuration.ok) {
    console.error('Unable to read POS configuration.', configuration.status, configuration.json);
    process.exit(1);
}

const body = configuration.json.body || {};
const menuId = body.menu_id || body.menus?.[0]?.id;
const orderType = body.order_types?.[0]?.id || 'takeaway';

if (!menuId) {
    console.error('No active menu id found.');
    process.exit(1);
}

let tableId = null;
if (orderType === 'dine_in') {
    const tables = await api('/tables/viewer');
    tableId = tables.json?.body?.tables?.[0]?.id || null;
}

const params = new URLSearchParams({ order_type: orderType });
if (tableId) {
    params.set('table_id', String(tableId));
}

const endpoint = `/pos/viewer/${cartId}/menu-items/${menuId}?${params}`;
const samples = [];

for (let index = 0; index < iterations; index++) {
    const sample = await api(endpoint);

    if (!sample.ok) {
        console.error('Menu sample failed.', sample.status, sample.json);
        process.exit(1);
    }

    samples.push(sample);
}

const firstPayload = samples[0]?.json?.body || {};
const report = {
    endpoint,
    iterations,
    payload: {
        total_bytes: samples[0]?.bytes || 0,
        categories_bytes: Buffer.byteLength(JSON.stringify(firstPayload.categories || [])),
        products_bytes: Buffer.byteLength(JSON.stringify(firstPayload.products || [])),
        pricing_bytes: Buffer.byteLength(JSON.stringify(firstPayload.pricing || {})),
        categories_count: firstPayload.categories?.length || 0,
        products_count: firstPayload.products?.length || 0,
    },
    client_ms: stats(samples.map((sample) => sample.client_ms)),
    app_ms: stats(samples.map((sample) => sample.app_ms)),
    db_ms: stats(samples.map((sample) => sample.db_ms)),
    query_count: stats(samples.map((sample) => sample.query_count)),
};

console.log(JSON.stringify(report, null, 2));
