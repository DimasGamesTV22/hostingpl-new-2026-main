/**
 * Спецификация сервера для рантаймов.
 *
 * Проверяем ровно то, что было сломано: драйверы читают spec.game,
 * spec.startup, spec.ports, spec.resources, spec.env и spec.path, а панель
 * присылает их внутри server.*. Без приведения эти поля были undefined, и
 * docker монтировал «undefined:/home/server», native падал на
 * mkdir(undefined), а игра стартовала командой по умолчанию.
 */

import test from 'node:test';
import assert from 'node:assert/strict';

import { buildRuntimeSpec, decodeEnv, decodePayloadEnv } from '../src/spec.js';

const PANEL_PAYLOAD = {
    server: {
        id: 42,
        uuid: 'abc-uuid',
        name: 'Мой сервер',
        runtime: 'docker',
        game: {
            slug: 'minecraft-java',
            family: 'minecraft',
            image: 'debian:12-slim',
            working_user: 'gamedock',
        },
        startup: { exec: 'java', args: ['-Xms1G', '-jar', 'server.jar'] },
        env: { RCON_PASSWORD: 'секрет' },
        resources: { memory_mb: 2048, cpu_percent: 100 },
        ports: { game: 25565, query: 25566, rcon: 25575 },
        slots: 60,
        watchdog: { enabled: true, max_restarts: 5 },
    },
    spec: {},
};

const DIR = '/home/gamedock/servers/42';

test('поля, которые читают драйверы, появляются на верхнем уровне', () => {
    const spec = buildRuntimeSpec(PANEL_PAYLOAD, DIR, 1);

    assert.equal(spec.path, DIR);
    assert.deepEqual(spec.game, PANEL_PAYLOAD.server.game);
    assert.deepEqual(spec.startup, PANEL_PAYLOAD.server.startup);
    assert.deepEqual(spec.ports, PANEL_PAYLOAD.server.ports);
    assert.deepEqual(spec.resources, PANEL_PAYLOAD.server.resources);
    assert.deepEqual(spec.env, PANEL_PAYLOAD.server.env);
    assert.equal(spec.slots, 60);
    assert.deepEqual(spec.watchdog, PANEL_PAYLOAD.server.watchdog);
});

test('вложенный server сохраняется без изменений', () => {
    const spec = buildRuntimeSpec(PANEL_PAYLOAD, DIR, 1);

    assert.deepEqual(spec.server, PANEL_PAYLOAD.server);
    assert.equal(spec.server.id, 42);
    assert.equal(spec.server.runtime, 'docker');
});

test('служебные данные из spec разворачиваются на верхний уровень', () => {
    // Панель присылает их отдельным объектом spec, а рантаймы и остальной
    // код агента читают spec.name, spec.user_id и так далее напрямую.
    const spec = buildRuntimeSpec({
        server: PANEL_PAYLOAD.server,
        spec: {
            name: 'Мой сервер',
            user_id: 7,
            created_at: '2026-01-01T00:00:00+00:00',
            servers_root: '/home/gamedock/servers',
            node: { id: 3, host: '10.0.0.5' },
        },
    }, DIR, 1);

    assert.equal(spec.name, 'Мой сервер');
    assert.equal(spec.user_id, 7);
    assert.equal(spec.servers_root, '/home/gamedock/servers');
    assert.deepEqual(spec.node, { id: 3, host: '10.0.0.5' });

    // И при этом вложенный server не потерялся: он нужен агенту.
    assert.equal(spec.server.id, 42);
});

test('нода подставляется, если панель её не прислала', () => {
    const spec = buildRuntimeSpec({ server: PANEL_PAYLOAD.server, spec: {} }, DIR, 9);
    assert.deepEqual(spec.node, { id: 9 });

    const noFallback = buildRuntimeSpec({ server: PANEL_PAYLOAD.server }, DIR);
    assert.deepEqual(noFallback.node, {});
});

test('пустой или кривой вход не роняет агента', () => {
    for (const bad of [null, undefined, {}, 'строка', { server: null }]) {
        const spec = buildRuntimeSpec(bad, DIR, 1);
        assert.equal(spec.path, DIR);
        assert.equal(spec.game, undefined);
        assert.deepEqual(spec.server, {});
    }
});

test('env расшифровывается до и после приведения', () => {
    const payload = {
        server: { ...PANEL_PAYLOAD.server, env: { RCON_PASSWORD: 'enc:тайна', GAME_PORT: '25565' } },
        spec: {},
    };

    // Порядок, как в handleServerCreate: сначала снятие префикса…
    payload.server.env = decodeEnv(payload.server.env);

    const spec = buildRuntimeSpec(payload, DIR, 1);

    // …потом приведение. Оба уровня должны быть расшифрованы.
    assert.equal(spec.env.RCON_PASSWORD, 'тайна');
    assert.equal(spec.server.env.RCON_PASSWORD, 'тайна');
    assert.equal(spec.env.GAME_PORT, '25565');
});

test('decodeEnv трогает только строки с префиксом', () => {
    const out = decodeEnv({
        A: 'enc:да',
        B: 'нет',
        C: 25565,
        D: null,
        E: true,
        F: 'enc:',
    });

    assert.equal(out.A, 'да');
    assert.equal(out.B, 'нет');
    assert.equal(out.C, 25565);
    assert.equal(out.D, null);
    assert.equal(out.E, true);
    assert.equal(out.F, '');
});

test('decodeEnv терпим к мусору', () => {
    assert.deepEqual(decodeEnv(null), {});
    assert.deepEqual(decodeEnv('строка'), {});
    assert.deepEqual(decodeEnv(undefined), {});
});

test('спецификация не делится с исходным объектом', () => {
    const payload = {
        server: { ...PANEL_PAYLOAD.server, game: { ...PANEL_PAYLOAD.server.game } },
        spec: { name: 'Мой сервер' },
    };
    const spec = buildRuntimeSpec(payload, DIR, 1);

    // Раньше spec.game был той же ссылкой, что и server.game, и правка
    // спецификации молча портила входной payload вызывающего кода.
    spec.game.slug = 'изменено';
    assert.equal(payload.server.game.slug, 'minecraft-java');

    spec.server.name = 'переименован';
    assert.equal(PANEL_PAYLOAD.server.name, 'Мой сервер');
});
test('decodePayloadEnv расшифровывает env сервера на месте', () => {
    const payload = {
        server: { id: 1, env: { RCON_PASSWORD: 'enc:тайна', PORT: '25565' } },
    };

    decodePayloadEnv(payload);

    assert.equal(payload.server.env.RCON_PASSWORD, 'тайна');
    assert.equal(payload.server.env.PORT, '25565');
});

test('decodePayloadEnv терпим к мусору и возвращает тот же объект', () => {
    // Контракт: возвращаем тот же payload, чтобы вызывать в цепочке.
    // Для {} это {} — undefined тут был неверным ожиданием в тесте.
    const empty = {};
    assert.equal(decodePayloadEnv(empty), empty);

    assert.equal(decodePayloadEnv(null), null);

    // Без server объект не трогаем.
    const withoutServer = { spec: {} };
    assert.equal(decodePayloadEnv(withoutServer), withoutServer);
    assert.deepEqual(withoutServer, { spec: {} });

    // server есть, env нет — подставляется пустой объект:
    // decodeEnv(undefined) даёт {}, и спецификация всегда получает env
    // как объект. Рантаймы проверяют его на undefined не будут.
    const noEnv = { server: { id: 1 } };
    assert.deepEqual(decodePayloadEnv(noEnv).server.env, {});
});
