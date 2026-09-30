#!/usr/bin/env node
/**
 * Тесты бота: конфигурация, экранирование, разбор callback-данных.
 *
 *   node --test tests/
 */

import test from 'node:test';
import assert from 'node:assert/strict';

process.env.TELEGRAM_BOT_TOKEN ||= 'test-token';
process.env.GD_PANEL_URL ||= 'https://panel.example.com';
process.env.GD_PANEL_TOKEN ||= 'test-panel-token';
process.env.GD_ADMIN_CHATS ||= '-1001234567890, 42';

const { config, describeConfig } = await import('../src/config.js');
const { esc, statusLabel, statusEmoji } = await import('../src/handlers.js');

test('конфигурация читается из окружения', () => {
    assert.equal(config.token, 'test-token');
    assert.equal(config.panelUrl, 'https://panel.example.com');
});

test('adminChats парсятся из CSV в числа', () => {
    assert.ok(config.adminChats.includes(-1001234567890), 'отрицательный id должен стать числом');
    assert.ok(config.adminChats.includes(42));
    assert.equal(config.adminChats.length, 2);
});

test('секреты не попадают в describeConfig', () => {
    const described = JSON.stringify(describeConfig());

    assert.ok(!described.includes('test-token'), 'токен Telegram утёк');
    assert.ok(!described.includes('test-panel-token'), 'токен панели утёк');
    assert.ok(described.includes('***'));
});

test('HTML-экранирование блокирует инъекции', () => {
    assert.equal(esc('<b>жирный</b>'), '&lt;b&gt;жирный&lt;/b&gt;');
    assert.equal(esc('a & b'), 'a &amp; b');
    assert.equal(esc(null), '');
    assert.equal(esc(undefined), '');
});

test('экранирование защищает от закрытия тега parse_mode', () => {
    // Если бы esc пропускал </b>, пользователь сломал бы разметку сообщения
    const hostile = '</b><script>alert(1)</script>';
    const safe = esc(hostile);

    assert.ok(!safe.includes('</b>'));
    assert.ok(!safe.includes('<script>'));
});

test('статусы сервера переводятся на русский', () => {
    assert.equal(statusLabel({ status: 'running' }), 'работает');
    assert.equal(statusLabel({ status: 'crashed' }), 'упал');
    assert.equal(statusLabel({ status: 'что-то' }), 'что-то');
});

test('статусы имеют эмодзи', () => {
    assert.equal(statusEmoji('running'), '🟢');
    assert.equal(statusEmoji('crashed'), '🔴');
    assert.equal(statusEmoji('unknown'), '⚪');
});
