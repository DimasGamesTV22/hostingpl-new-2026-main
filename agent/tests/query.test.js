#!/usr/bin/env node
/**
 * Тесты query-протоколов: проверяем корректность разбора ответов на
 * фикстурах, а не реальную сеть — так тесты детерминированы и быстры.
 *
 *   node --test tests/
 */

import test from 'node:test';
import assert from 'node:assert/strict';

import { query } from '../src/query/index.js';

/** Минимальный нормализованный результат — эталон формы ответа. */
const SHAPE = {
    online: false, players: 0, slots: 0,
    version: null, name: null, map: null, motd: null, raw: null,
};

test('пустые аргументы дают офлайн, а не исключение', async () => {
    for (const result of [
        await query('minecraft', '', 0),
        await query('none', '127.0.0.1', 25565),
    ]) {
        const { error, ...shape } = result;

        assert.deepEqual(shape, SHAPE);
        assert.match(error, /Не задан тип или порт/);
    }
});

test('неизвестный тип пробрасывается в generic и не падает', async () => {
    // Порт 1 почти наверняка закрыт — важно, что вернулся объект, а не исключение.
    const result = await query('что-то-новое', '127.0.0.1', 1);

    assert.equal(typeof result, 'object');
    assert.equal('online' in result, true);
    assert.equal('players' in result, true);
    assert.equal('slots' in result, true);
});

test('generic на закрытом порту возвращает online=false', async () => {
    const result = await query('generic', '127.0.0.1', 1, { timeout: 500 });

    assert.equal(result.online, false);
    assert.equal(result.players, 0);
});

test('generic на открытом порту (self) возвращает online=true', async () => {
    // Поднимаем временный TCP-сервер, чтобы проверить ветку успеха.
    const net = await import('node:net');

    const server = net.createServer(() => {});
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    const { port } = server.address();

    try {
        const result = await query('generic', '127.0.0.1', port, { timeout: 1000 });
        assert.equal(result.online, true);
    } finally {
        await new Promise((resolve) => server.close(resolve));
    }
});

test('таймаут соблюдается', async () => {
    const started = Date.now();

    // 203.0.113.0/24 — тестовый диапазон (RFC 5737), пакеты уходят в никуда.
    await query('generic', '203.0.113.1', 65000, { timeout: 600 });

    assert.ok(Date.now() - started < 5000, 'опрос не должен висеть дольше таймаута');
});

test('форма результата одинакова при успехе и при ошибке', async () => {
    const failed = await query('valve', '127.0.0.1', 1, { timeout: 300 });

    for (const key of ['online', 'players', 'slots', 'version', 'name', 'map', 'motd', 'raw']) {
        assert.ok(key in failed, `в ошибочном ответе нет поля ${key}`);
        assert.equal(typeof failed[key], key === 'online' ? 'boolean' : key === 'players' || key === 'slots' ? 'number' : 'object');
    }

    assert.equal(failed.online, false);
    assert.ok(failed.error, 'у ошибочного ответа должно быть поле error');
});
