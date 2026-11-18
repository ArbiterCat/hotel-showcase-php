import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { setTimeout as delay } from 'node:timers/promises';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const source = process.env.SUPPLIER_URL ?? 'http://127.0.0.1:9000';
const city = 'TST';
const id = n => 'h-' + String(n).padStart(7, '0');
const h = (n, fields = {}) => ({ id: id(n), name: 'Исеть & SPA', city, price: '54.55', ...fields });
const replies = new Map();
const calls = new Map();
const mock = createServer(async (req, res) => {
    const hotel = new URL(req.url, 'http://localhost').searchParams.get('id');
    calls.set(hotel, (calls.get(hotel) ?? 0) + 1);
    const reply = replies.get(hotel) ?? 'missing';
    if (reply === 'hang') return;
    if (reply === '502') {
        res.writeHead(502, { 'Content-Type': 'text/html' }).end('<html>Gateway</html>');
        return;
    }
    if (reply === 'busy') {
        res.writeHead(503, { 'Content-Type': 'application/xml', 'Retry-After': '2' })
            .end('<HotelResponse><Error code="BUSY">Try later</Error></HotelResponse>');
        return;
    }
    res.setHeader('Content-Type', 'application/xml; charset=utf-8');
    if (reply === 'missing') {
        res.end('<HotelResponse><Error code="HOTEL_NOT_FOUND">Not found</Error></HotelResponse>');
        return;
    }
    await delay(reply.delay ?? 0);
    res.end(
        '<HotelResponse><Hotel id="' + hotel + '" city="TST"><Name>Отель &quot;Икс&quot; &amp; SPA</Name>'
        + '<Price>' + reply.price + '</Price>'
        + (reply.amenities ?? '') + '</Hotel></HotelResponse>',
    );
});
mock.listen(9000, '0.0.0.0');
await new Promise(resolve => mock.once('listening', resolve));

async function send(path, { method = 'GET', json, body } = {}) {
    const start = performance.now();
    const res = await fetch(base + path, {
        method,
        headers: json === undefined && body === undefined ? {} : { 'Content-Type': 'application/json' },
        body: json === undefined ? body : JSON.stringify(json),
        signal: AbortSignal.timeout(10_000),
    });
    const raw = await res.text();
    assert.match(res.headers.get('content-type') ?? '', /^application\/json\b/, path + ' ' + raw);
    return { status: res.status, headers: res.headers, json: JSON.parse(raw), ms: performance.now() - start };
}

async function expect(path, status, options) {
    const res = await send(path, options);
    assert.equal(res.status, status, path + ' ' + JSON.stringify(res.json));
    return res;
}

const card = n => '/hotels/' + id(n);
const stats = async () => (await expect('/stats', 200)).json;
const load = (hotels, status = 200) => expect('/import', status, { method: 'POST', json: { hotels } });
const setSource = () => expect('/supplier/source', 200, { method: 'POST', json: { url: source } });
const search = async (params = '') => (await expect('/hotels?city=' + city + params, 200)).json;
const contract = '/hotels/c-724';

try {
    assert.deepEqual((await expect('/health', 200)).json, { status: 'healthy' });
    await expect('/supplier/source', 422, { method: 'POST', json: { url: 'file:///bad' } });
    await setSource();
    await load([]);
    await send(contract, { method: 'DELETE' });
    await expect(contract, 422, { method: 'PUT', json: { city, name: 'Строгие типы', price_minor: '123' } });
    await expect(card(10), 422, { method: 'PUT', json: {} });
    assert.deepEqual((await expect(contract, 200, {
        method: 'PUT', json: { city, name: 'Договорной', price_minor: 5455 },
    })).json.amenities, []);

    const imported = await load([
        h(10, { price: '1.2' }), h(11, { stars: 4 }), null, [],
        h(12, { stars: '4' }), h(13, { amenities: { a: 'wifi' } }), h(10, { price: '54.55', address: 'Адрес' }),
    ]);
    assert.deepEqual(imported.json, { imported: 2, skipped: 4, dropped: 0 });
    await expect('/import', 400, { method: 'POST', body: '{"hotels":[{}], "broken":}' });
    await load({}, 400);
    assert.equal((await search()).total, 3);
    assert.deepEqual((await search('&max_price=5455&offset=1&limit=1')).items.map(x => x.id), [id(10)]);
    assert.deepEqual((await search('&stars_min=4')).items.map(x => x.id), [id(11)]);
    assert.equal((await search('&offset=999999999999999999999')).total, 3);
    for (const query of ['city=lower', 'city=TST&limit=0', 'city=TST&offset=-1', 'city=TST&stars_min[]=1']) {
        await expect('/hotels?' + query, 422);
    }
    const counters = await stats();
    assert.equal((await expect(contract, 200)).headers.get('x-source'), 'LOCAL');
    assert.equal((await expect(card(10), 200)).headers.get('x-source'), 'LOCAL');
    assert.equal((await stats()).served_local - counters.served_local, 2);
    assert.equal((await stats()).supplier_requests, counters.supplier_requests);

    replies.set(id(11), { price: '55' });
    await expect(card(11), 200, { method: 'DELETE' });
    await expect(card(11), 404);
    await expect(card(11), 404, { method: 'DELETE' });
    assert.equal(calls.get(id(11)), undefined);
    assert.deepEqual((await load([h(10)])).json, { imported: 1, skipped: 0, dropped: 0 });
    await expect(card(11), 404);
    assert.deepEqual((await load([h(11)])).json, { imported: 1, skipped: 0, dropped: 1 });
    assert.equal((await expect(card(11), 200)).headers.get('x-source'), 'LOCAL');

    await expect(card(20), 404);
    replies.set(id(20), { price: '5400.55', delay: 120 });
    const before = await stats();
    const cards = await Promise.all(Array.from({ length: 24 }, () => expect(card(20), 200)));
    for (const result of cards) {
        assert.equal(result.headers.get('x-source'), 'SUPPLIER');
        assert.equal(result.json.price_minor, 540055);
        assert.equal(result.json.name, 'Отель "Икс" & SPA');
        assert.equal(result.json.stars, null);
    }
    assert.equal(calls.get(id(20)), 2);
    const after = await stats();
    assert.equal(after.supplier_requests - before.supplier_requests, 1);
    assert.equal(after.served_from_supplier - before.served_from_supplier, 24);
    assert.equal((await expect(card(20), 200)).headers.get('x-source'), 'LOCAL');

    replies.set(id(21), 'hang');
    const hanging = expect(card(21), 503);
    await delay(60);
    assert.ok((await expect('/health', 200)).ms < 700);
    assert.ok((await expect('/hotels?city=TST', 200)).ms < 700);
    const timedOut = await hanging;
    assert.ok(timedOut.ms < 2_000, 'timeout took ' + timedOut.ms);
    assert.match(timedOut.headers.get('retry-after'), /^\d+$/);
    console.log('Поставщик завис: ответ за %d мс', Math.round(timedOut.ms));

    replies.set(id(22), '502');
    await expect(card(22), 503);
    replies.set(id(23), 'busy');
    const busy = await expect(card(23), 503);
    assert.equal(busy.headers.get('retry-after'), '2');
    await expect(card(24), 503);
    assert.equal(calls.get(id(24)), undefined);
    await setSource();

    for (let i = 0; i < 2; i++) {
        const previous = await search('&limit=1');
        const first = 100_000 + i * 100_000;
        const large = Array.from({ length: 50_000 }, (_, i) => h(first + i, { address: 'x'.repeat(207) }));
        const body = JSON.stringify({ hotels: large });
        assert.ok(Buffer.byteLength(body) <= 15_000_000);
        const snapshots = [];
        const pending = expect('/import', 200, { method: 'POST', body });
        for (let j = 0; j < 8; j++) {
            const snapshot = await expect('/hotels?city=TST&offset=1&limit=1', 200);
            assert.ok(snapshot.ms < 1_000, 'search during import took ' + snapshot.ms);
            snapshots.push(snapshot.json);
            await delay(20);
        }
        const result = await pending;
        assert.equal(result.json.imported, 50_000);
        if (i === 1) {
            assert.equal(result.json.dropped, 50_000);
        }
        const published = await search('&offset=1&limit=1');
        assert.equal(published.total, 50_001);
        assert.equal(published.items[0].id, id(first));
        for (const snapshot of snapshots) {
            assert.ok([previous.total, published.total].includes(snapshot.total));
            if (i === 1) {
                assert.ok([id(100_000), published.items[0].id].includes(snapshot.items[0].id));
            }
        }
        console.log('Импорт %d: %d записей, %d байт, %d мс',
            i + 1, large.length, Buffer.byteLength(body), Math.round(result.ms));
    }
    assert.ok((await expect('/health', 200)).ms < 700);
    assert.equal((await stats()).hotels, 50_001);

    await load([h(30, { stars: 4, address: 'Адрес', amenities: ['wifi'] }), h(31), h(32), h(33)]);
    replies.set(id(30), { price: '55.5' });
    replies.set(id(31), 'missing');
    replies.set(id(32), 'hang');
    replies.set(id(33), { price: '55.5', delay: 350 });
    assert.equal((await expect(card(30), 200)).json.price_minor, 5455);
    console.log('Проверяю истечение кэша по реальным часам (51 секунда).');
    await delay(51_000);
    const removedDuringRefresh = expect(card(33), 404);
    for (let i = 0; i < 50 && calls.get(id(33)) === undefined; i++) {
        await delay(5);
    }
    assert.equal(calls.get(id(33)), 1);
    await expect(card(33), 200, { method: 'DELETE' });
    await removedDuringRefresh;
    await expect(card(33), 404);
    const fresh = await expect(card(30), 200);
    assert.equal(fresh.headers.get('x-source'), 'SUPPLIER');
    assert.equal(fresh.json.price_minor, 5550);
    assert.equal(fresh.json.stars, 4);
    assert.equal(fresh.json.address, 'Адрес');
    assert.deepEqual(fresh.json.amenities, ['wifi']);
    await expect(card(31), 404);
    replies.set(id(31), { price: '10' });
    await expect(card(31), 200);
    const stale = await expect(card(32), 200);
    assert.equal(stale.headers.get('x-source'), 'SUPPLIER');
    assert.equal(stale.json.price_minor, 5455);
    assert.ok(stale.ms < 2_000);
    await expect(contract, 200, { method: 'DELETE' });
    await expect(contract, 404);
    console.log('Все проверки пройдены.');
} finally {
    mock.closeAllConnections();
    mock.close();
}
