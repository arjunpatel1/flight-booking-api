import { readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';

const baseUrl = (process.env.POS_LOAD_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
const token = process.env.POS_LOAD_TOKEN;
const manifestPath = process.env.POS_LOAD_MANIFEST || '/tmp/nexdine-load-manifest.json';
const ledgerPath = process.env.POS_LOAD_LEDGER || '/tmp/nexdine-load-ledger.json';
const menuCachePath = process.env.POS_LOAD_MENU_CACHE || `/tmp/${manifestSafeName(manifestPath)}-menu.json`;
const start = Number(process.env.POS_LOAD_START || 1);
const count = Number(process.env.POS_LOAD_COUNT || 1);
const concurrency = Math.max(1, Number(process.env.POS_LOAD_CONCURRENCY || 1));
const timeoutMs = Number(process.env.POS_LOAD_TIMEOUT_MS || 60000);

if (!token) {
    console.error('POS_LOAD_TOKEN is required.');
    process.exit(2);
}

function manifestSafeName(path) {
    return path.split('/').pop().replace(/[^a-zA-Z0-9_-]/g, '-');
}

const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
let ledger = { run_id: manifest.run_id, orders: [] };
try {
    ledger = JSON.parse(await readFile(ledgerPath, 'utf8'));
} catch {
    // First segment of this run.
}

function timingValue(header, metric) {
    const part = String(header || '').split(',').map((value) => value.trim())
        .find((value) => value.startsWith(`${metric};dur=`));
    return part ? Number(part.slice(`${metric};dur=`.length)) : null;
}

async function api(method, path, body = null, idempotencyKey = null) {
    const started = performance.now();
    try {
        const response = await fetch(`${baseUrl}${path}`, {
            method,
            headers: {
                Accept: 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
                Authorization: `Bearer ${token}`,
                'X-NexDine-Device-Id': `${manifest.run_id}-terminal`,
                ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
            },
            body: body ? JSON.stringify(body) : null,
            signal: AbortSignal.timeout(timeoutMs),
        });
        const text = await response.text();
        let json = null;
        try { json = text ? JSON.parse(text) : null; } catch { json = { raw: text.slice(0, 500) }; }
        return {
            ok: response.ok,
            status: response.status,
            client_ms: Number((performance.now() - started).toFixed(2)),
            app_ms: timingValue(response.headers.get('server-timing'), 'app'),
            db_ms: timingValue(response.headers.get('server-timing'), 'db'),
            query_count: Number(response.headers.get('x-query-count') || 0),
            json,
        };
    } catch (error) {
        return {
            ok: false,
            status: 0,
            client_ms: Number((performance.now() - started).toFixed(2)),
            error: error instanceof Error ? error.message : String(error),
        };
    }
}

function modifierPayload(product, index) {
    const options = {};
    for (const option of product.options || []) {
        const value = option.values?.[index % option.values.length];
        if (!value || (!option.is_required && (index + Number(option.id)) % 2 !== 0)) continue;
        options[String(option.id)] = ['checkbox', 'multiple_select'].includes(option.type?.id)
            ? [value.id]
            : value.id;
    }
    return options;
}

function deterministicCartId(index) {
    const hex = createHash('sha256').update(`${manifest.run_id}:cart:${index}`).digest('hex').slice(0, 32);
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-4${hex.slice(13, 16)}-a${hex.slice(17, 20)}-${hex.slice(20)}`;
}

let menuBody;
try {
    menuBody = JSON.parse(await readFile(menuCachePath, 'utf8'));
} catch {
    const menuResponse = await api(
        'GET',
        `/pos/viewer/${crypto.randomUUID()}/menu-items/${manifest.menu_id}?order_type=dine_in&table_id=${manifest.table_ids[0]}`,
    );
    if (!menuResponse.ok) {
        throw new Error(`Menu bootstrap failed: ${menuResponse.status} ${JSON.stringify(menuResponse.json)}`);
    }
    menuBody = menuResponse.json?.body || {};
    await writeFile(menuCachePath, `${JSON.stringify(menuBody)}\n`);
}
const products = (menuBody.products || []).filter((product) => product.is_available);
if (products.length < 5) throw new Error('At least five available products are required.');
const productDetails = new Map();

async function runOrder(index) {
    const cartId = deterministicCartId(index);
    const tableId = manifest.table_ids[(index - 1) % manifest.table_ids.length];
    const selected = Array.from({ length: 5 }, (_, offset) => products[(index * 7 + offset * 13) % products.length]);
    const calls = {};
    const fail = (step, response) => ({
        index, cart_id: cartId, table_id: tableId, success: false, failed_step: step,
        failure: { status: response.status, error: response.error, body: response.json }, calls,
    });

    calls.cart = await api('POST', `/cart/${cartId}/order-types/dine_in`, { table_id: tableId });
    if (!calls.cart.ok) return fail('cart', calls.cart);

    const selectedDetails = [];
    for (const product of selected) {
        if (!product.has_options || (product.options || []).length > 0) {
            selectedDetails.push(product);
            continue;
        }

        if (!productDetails.has(product.id)) {
            const details = await api(
                'GET',
                `/pos/viewer/${crypto.randomUUID()}/menu-items/${manifest.menu_id}/products/${product.id}?order_type=dine_in&table_id=${tableId}`,
            );
            if (!details.ok) return fail('product_details', details);
            productDetails.set(product.id, details.json?.body || product);
        }

        selectedDetails.push(productDetails.get(product.id));
    }

    calls.items = await api('POST', `/cart/${cartId}/items/batch`, {
        table_id: tableId,
        items: selectedDetails.map((product, offset) => ({
            product_id: product.id,
            qty: 1 + ((index + offset) % 3),
            options: modifierPayload(product, index + offset),
        })),
    });
    if (!calls.items.ok) return fail('items', calls.items);

    const orderKey = `${manifest.run_id}-order-${index}`;
    calls.create = await api('POST', `/orders/${cartId}`, {
        menu_id: manifest.menu_id,
        branch_id: manifest.branch_id,
        table_id: tableId,
        type: 'dine_in',
        order_type: 'dine_in',
        register_id: manifest.register_id,
        session_id: manifest.session_id,
        submit_action: 'send_to_kitchen',
        guest_count: 1,
        notes: `QA load note ${manifest.run_id} order ${index}`,
    }, orderKey);
    if (!calls.create.ok) return fail('create', calls.create);

    const reference = calls.create.json?.body?.order_id;
    calls.show = await api('GET', `/orders/${reference}/show`);
    if (!calls.show.ok) return fail('show', calls.show);
    const orderId = calls.show.json?.body?.id;

    calls.payment_meta = await api('GET', `/orders/${reference}/payments/meta`);
    if (!calls.payment_meta.ok) return fail('payment_meta', calls.payment_meta);
    const due = Number(calls.payment_meta.json?.body?.order?.due_amount?.amount || 0);
    calls.payment = await api('POST', `/orders/${reference}/payments`, {
        payments: [{ method: 'cash', amount: due }],
        payment_mode: 'full',
        with_print: false,
        customer_given_amount: due,
        change_return: 0,
        register_id: manifest.register_id,
        session_id: manifest.session_id,
    }, `${manifest.run_id}-payment-${index}`);
    if (!calls.payment.ok) return fail('payment', calls.payment);

    calls.preview = await api('GET', `/orders/${reference}/print/bill/preview`);
    if (!calls.preview.ok) return fail('preview', calls.preview);
    calls.print = await api('POST', `/orders/${reference}/print/bill`, {
        specific_id: manifest.register_id,
    }, `${manifest.run_id}-print-${index}`);
    if (!calls.print.ok) return fail('print', calls.print);

    calls.finalize = await api('POST', `/orders/${orderId}/finalize-kot`, {}, `${manifest.run_id}-finalize-${index}`);
    if (!calls.finalize.ok) return fail('finalize', calls.finalize);

    return {
        index, cart_id: cartId, table_id: tableId, order_id: orderId,
        reference, expected_lines: 5, success: true, calls,
    };
}

const pending = Array.from({ length: count }, (_, offset) => start + offset)
    .filter((index) => !ledger.orders.some((order) => order.index === index && order.success));

for (let cursor = 0; cursor < pending.length; cursor += concurrency) {
    const results = await Promise.all(pending.slice(cursor, cursor + concurrency).map(runOrder));
    for (const result of results) {
        ledger.orders = ledger.orders.filter((order) => order.index !== result.index);
        ledger.orders.push(result);
        console.log(JSON.stringify({ index: result.index, success: result.success, failed_step: result.failed_step }));
    }
    ledger.orders.sort((left, right) => left.index - right.index);
    await writeFile(ledgerPath, `${JSON.stringify(ledger, null, 2)}\n`);
}

const segment = ledger.orders.filter((order) => order.index >= start && order.index < start + count);
console.log(JSON.stringify({
    run_id: manifest.run_id,
    requested: count,
    completed: segment.filter((order) => order.success).length,
    failures: segment.filter((order) => !order.success).map((order) => ({ index: order.index, step: order.failed_step, failure: order.failure })),
    ledger: ledgerPath,
}, null, 2));
