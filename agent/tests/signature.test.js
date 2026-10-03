/**
 * Подпись сообщений: сверка с панелью.
 *
 * Ключевая проверка здесь — не «совпадает ли HMAC», а «та ли строка
 * подаётся на HMAC». HMAC-SHA256 в PHP и Node считается одинаково, а вот
 * каноническая форма раньше различалась, и именно это ломало протокол.
 *
 * Ожидаемые строки ниже выписаны так, как их выдаст PHP:
 *   ksort($message);
 *   json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

import { canonicalPayload, canonicalValue, sign, verify } from '../src/signature.js';

const SECRET = 'node-token-тест-123';

test('каноническая строка совпадает с тем, что делает PHP', () => {
    // Верхний уровень сортируется: data, event, nonce, ts
    const message = {
        event: 'sync.request',
        data: {},
        ts: 1750000000,
        nonce: 'ab12',
    };

    // PHP: json_decode('{}', true) даёт пустой массив, поэтому пустой
    // объект сериализуется как []
    assert.equal(
        canonicalPayload(message),
        '{"data":[],"event":"sync.request","nonce":"ab12","ts":1750000000}'
    );
});

test('вложенные ключи НЕ сортируются — ksort не рекурсивный', () => {
    const message = {
        ts: 1750000001,
        event: 'console.output',
        data: { line: 'привет/мир', id: 7 },
        nonce: 'cd34',
    };

    // Порядок внутри data — как задал отправитель: сначала line, потом id.
    // Неэкранированные UTF-8 и «/» — благодаря JSON_UNESCAPED_UNICODE
    // и JSON_UNESCAPED_SLASHES.
    assert.equal(
        canonicalPayload(message),
        '{"data":{"line":"привет/мир","id":7},"event":"console.output","nonce":"cd34","ts":1750000001}'
    );

    // Если бы сортировка была рекурсивной, ключи встали бы в алфавитном
    // порядке (id перед line) и подпись не совпала бы.
    assert.notEqual(
        canonicalPayload(message),
        '{"data":{"id":7,"line":"привет/мир"},"event":"console.output","nonce":"cd34","ts":1750000001}'
    );
});

test('поле sig не входит в подписываемую строку', () => {
    const message = { event: 'ping', ts: 1, nonce: 'n' };

    const before = canonicalPayload(message);
    message.sig = 'любая подпись';
    const after = canonicalPayload(message);

    assert.equal(before, after);
});

test('подпись стабильна и проверяется', () => {
    const message = { event: 'hello', data: { node_id: 4 }, ts: 1750000002, nonce: 'ef56' };

    const signature = sign(message, SECRET);

    assert.match(signature, /^[0-9a-f]{64}$/);
    assert.ok(verify({ ...message, sig: signature }, SECRET));
});

test('изменение любого поля ломает подпись', () => {
    const message = { event: 'hello', data: { node_id: 4 }, ts: 1750000002, nonce: 'ef56' };
    const signature = sign(message, SECRET);

    assert.equal(verify({ ...message, data: { node_id: 5 }, sig: signature }, SECRET), false);
    assert.equal(verify({ ...message, ts: 1750000003, sig: signature }, SECRET), false);
    assert.equal(verify({ ...message, event: 'goodbye', sig: signature }, SECRET), false);
});

test('чужой секрет не подходит', () => {
    const message = { event: 'hello', ts: 1, nonce: 'n' };
    const signature = sign(message, SECRET);

    assert.equal(verify({ ...message, sig: signature }, 'другой-токен'), false);
});

test('подпись неверной длины не роняет процесс', () => {
    // Раньше здесь бросался RangeError из timingSafeEqual, мимо try/catch
    // обработчика входящих сообщений.
    const message = { event: 'hello', ts: 1, nonce: 'n' };

    for (const bad of ['', 'x', 'abc', 'a'.repeat(63), 'a'.repeat(65), 'a'.repeat(200)]) {
        assert.equal(verify({ ...message, sig: bad }, SECRET), false, `длина ${bad.length}`);
    }
});

test('сообщение без подписи или с мусором отвергается молча', () => {
    assert.equal(verify({ event: 'hello', ts: 1 }, SECRET), false);
    assert.equal(verify({ event: 'hello', ts: 1, sig: null }, SECRET), false);
    assert.equal(verify({ event: 'hello', ts: 1, sig: 123 }, SECRET), false);
    assert.equal(verify(null, SECRET), false);
    assert.equal(verify('строка', SECRET), false);
});

test('пустой объект и пустой массив дают одинаковую каноническую форму', () => {
    // PHP их не различает, значит и подписи должны совпадать.
    // deepEqual: структурное сравнение. assert.equal из assert/strict —
    // strictEqual, и два разных массива он счёл бы неравными по ссылке.
    assert.deepEqual(canonicalValue({}), canonicalValue([]));
    assert.equal(
        canonicalPayload({ event: 'a', data: {}, ts: 1 }),
        canonicalPayload({ event: 'a', data: [], ts: 1 })
    );
});

test('вложенные пустые объекты тоже приводятся к массивам', () => {
    const message = {
        event: 'server.create',
        payload: { server: { ports: {}, env: { pass: 'x' } } },
        ts: 5,
    };

    assert.equal(
        canonicalPayload(message),
        // Порядок внутри server — как задал отправитель: ports, потом env.
        // ksort в PHP не рекурсивный, значит и мы не сортируем.
        '{"event":"server.create","payload":{"server":{"ports":[],"env":{"pass":"x"}}},"ts":5}'
    );
});

test('числовые ключи верхнего уровня сортируются как в PHP', () => {
    // SORT_REGULAR сравнивает числовые строки как числа: «9» раньше «10»,
    // хотя в алфавитном порядке было бы наоборот. Настоящие протокольные
    // ключи числовыми не бывают, но правило зафиксировано целиком, чтобы
    // совпадение не зависело от того, какие ключи встретятся завтра.
    const message = { 10: 'a', 9: 'b', event: 'x' };

    // JSON.stringify переставит целочисленные ключи сам, поэтому порядок
    // читаем из уже разобранного результата.
    const keys = Object.keys(JSON.parse(canonicalPayload(message)));

    assert.deepEqual(keys.slice(0, 2), ['9', '10']);
});

test('эталон: подпись считается из известной строки', () => {
    // Считаем HMAC явно из строки, которую описали выше, — так проверка
    // не зависит от самой реализации sign() и ловит подмену канонизации.
    const payload = '{"data":[],"event":"sync.request","nonce":"ab12","ts":1750000000}';
    const expected = crypto
        .createHmac('sha256', SECRET)
        .update(payload)
        .digest('hex');

    assert.equal(
        sign({ event: 'sync.request', data: {}, ts: 1750000000, nonce: 'ab12' }, SECRET),
        expected
    );
});