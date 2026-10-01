const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const bootstrapCartId = process.env.POS_LOAD_CART_ID || crypto.randomUUID();
const virtualUsers = Number(process.env.POS_LOAD_USERS || 20);
const iterations = Number(process.env.POS_LOAD_ITERATIONS || 10);
const terminalPrefix = process.env.POS_LOAD_TERMINAL_PREFIX || 'load-read-terminal';
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 10000);

if (!token) {
    console.error('POS_LOAD_TOKEN is required.');
    process.exit(2);
}

const latencies = [];
const statuses = new Map();
let failures = 0;
let timedOut = 0;

async function api(path, terminalId) {
    const started = performance.now();
    try {
        const response = await fetch(`${baseUrl}${path}`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                'X-NexDine-Device-Id': terminalId,
            },
            signal: AbortSignal.timeout(timeoutMs),
        });

        latencies.push(performance.now() - started);
        statuses.set(response.status, (statuses.get(response.status) || 0) + 1);

        if (!response.ok) {
            failures++;
            return { response, json: null };
        }

        return { response, json: await response.json() };
    } catch (error) {
        latencies.push(performance.now() - started);
        failures++;
        if (error.name === 'TimeoutError') {
            timedOut++;
            statuses.set('timeout', (statuses.get('timeout') || 0) + 1);
        } else {
            statuses.set('network_error', (statuses.get('network_error') || 0) + 1);
        }

        return { response: null, json: null };
    }
}

function percentile(values, fraction) {
    if (!values.length) {
        return 0;
    }

    const sorted = [...values].sort((left, right) => left - right);
    return sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)];
}

const bootstrapTerminal = `${terminalPrefix}-bootstrap`;
const configurationResult = await api(`/pos/viewer/${bootstrapCartId}/configuration`, bootstrapTerminal);
if (!configurationResult.json) {
    console.error('Unable to read POS configuration for load setup.');
    process.exit(1);
}

const configuration = configurationResult.json.body;
const menuId = configuration.menu_id || configuration.menus?.[0]?.id;
const orderType = configuration.order_types?.[0]?.id || 'takeaway';
const tableResult = await api('/tables/viewer', bootstrapTerminal);
const tableId = tableResult.json?.body?.tables?.[0]?.id;

if (!menuId) {
    console.error('No active menu is available for POS read-load verification.');
    process.exit(1);
}

const menuParameters = new URLSearchParams({ order_type: orderType });
if (orderType === 'dine_in' && tableId) {
    menuParameters.set('table_id', String(tableId));
}

function endpointsForCart(cartId) {
    return [
        `/pos/viewer/${cartId}/configuration`,
        `/pos/viewer/${cartId}/menu-items/${menuId}?${menuParameters}`,
        '/tables/viewer',
    ];
}

await Promise.all(Array.from({ length: virtualUsers }, async (_, userIndex) => {
    const terminalId = `${terminalPrefix}-${userIndex + 1}`;
    const endpoints = endpointsForCart(crypto.randomUUID());

    for (let iteration = 0; iteration < iterations; iteration++) {
        await Promise.all(endpoints.map((endpoint) => api(endpoint, terminalId)));
    }
}));

const report = {
    virtual_users: virtualUsers,
    iterations_per_user: iterations,
    tested_endpoints: endpointsForCart('{terminal-cart-id}'),
    total_requests: latencies.length,
    failed_requests: failures,
    timed_out_requests: timedOut,
    timeout_ms: timeoutMs,
    statuses: Object.fromEntries([...statuses.entries()].sort()),
    latency_ms: {
        average: Number((latencies.reduce((total, value) => total + value, 0) / latencies.length).toFixed(2)),
        p50: Number(percentile(latencies, 0.5).toFixed(2)),
        p95: Number(percentile(latencies, 0.95).toFixed(2)),
        max: Number(Math.max(...latencies).toFixed(2)),
    },
};

console.log(JSON.stringify(report, null, 2));
process.exit(failures === 0 ? 0 : 1);
