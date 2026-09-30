#!/usr/bin/env node
/**
 * Тесты конфигурации агента: приоритет файлов, env и флагов,
 * автоопределение WSS-адреса и валидация.
 *
 *   node --test tests/
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { loadConfig, DEFAULTS } from '../src/config.js';

/** Кладём временный конфиг и делаем его первым в списке поиска. */
function withConfigFile(data, fn) {
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'gd-cfg-')), 'agent.json');
    fs.writeFileSync(file, JSON.stringify(data));

    const previous = process.env.GD_AGENT_CONFIG;
    process.env.GD_AGENT_CONFIG = file;

    try {
        return fn();
    } finally {
        if (previous === undefined) delete process.env.GD_AGENT_CONFIG;
        else process.env.GD_AGENT_CONFIG = previous;
        fs.rmSync(path.dirname(file), { recursive: true, force: true });
    }
}

test('значения по умолчанию применяются', () => {
    withConfigFile({ panel: 'https://p.example.com', token: 't', nodeId: 1 }, () => {
        const config = loadConfig([]);

        assert.equal(config.panel, 'https://p.example.com');
        assert.equal(config.token, 't');
        assert.equal(config.nodeId, 1);
        assert.equal(config.runtime, DEFAULTS.runtime);
        assert.equal(config.serversRoot, DEFAULTS.serversRoot);
    });
});

test('WSS выводится из https автоматически', () => {
    withConfigFile({ panel: 'https://panel.example.com', token: 't' }, () => {
        const config = loadConfig([]);
        assert.match(config.wsUrl, /^wss:\/\/panel\.example\.com/);
    });

    withConfigFile({ panel: 'http://localhost:8000', token: 't' }, () => {
        const config = loadConfig([]);
        assert.match(config.wsUrl, /^ws:\/\/localhost:8000/);
    });
});

test('флаги командной строки перекрывают файл', () => {
    withConfigFile({ panel: 'https://file.example.com', token: 'file', runtime: 'docker' }, () => {
        const config = loadConfig(['--panel=https://cli.example.com', '--runtime=native', '--nodeId=7']);

        assert.equal(config.panel, 'https://cli.example.com');
        assert.equal(config.runtime, 'native');
        assert.equal(config.nodeId, 7);
        assert.equal(config.token, 'file', 'токен не должен затираться флагом');
    });
});

test('числа и булевы значения разбираются', () => {
    withConfigFile({ panel: 'https://p.example.com', token: 't' }, () => {
        const config = loadConfig(['--nodeId=12', '--reconnectDelay=2500', '--reconnect=false']);

        assert.strictEqual(config.nodeId, 12);
        assert.strictEqual(config.reconnectDelay, 2500);
        assert.strictEqual(config.reconnect, false);
    });
});

test('дефис в имени флага превращается в camelCase', () => {
    withConfigFile({ panel: 'https://p.example.com', token: 't' }, () => {
        const config = loadConfig(['--servers-root=/srv/games']);
        assert.equal(config.serversRoot, '/srv/games');
    });
});

test('вложенные параметры рантайма мерджатся, а не заменяются', () => {
    withConfigFile({
        panel: 'https://p.example.com',
        token: 't',
        docker: { socket: '/custom/docker.sock' },
    }, () => {
        const config = loadConfig([]);

        assert.equal(config.docker.socket, '/custom/docker.sock');
        // Остальные ключи docker остались от значений по умолчанию
        assert.equal(config.docker.networkPrefix, DEFAULTS.docker.networkPrefix);
    });
});

test('без токена loadConfig бросает понятную ошибку', () => {
    withConfigFile({ panel: 'https://p.example.com', nodeId: 1 }, () => {
        assert.throws(() => loadConfig([]), /токен ноды/i);
    });
});

test('неизвестный рантайм отвергается', () => {
    withConfigFile({ panel: 'https://p.example.com', token: 't', runtime: 'квантум' }, () => {
        assert.throws(() => loadConfig([]), /Неизвестный рантайм/);
    });
});

test('путь к конфигу сохраняется в config.configFile', () => {
    withConfigFile({ panel: 'https://p.example.com', token: 't' }, () => {
        const config = loadConfig([]);
        assert.ok(config.configFile?.endsWith('agent.json'));
    });
});
