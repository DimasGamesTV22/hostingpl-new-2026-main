#!/usr/bin/env node
/**
 * Тесты безопасности путей (PathGuard) — самое критичное место агента:
 * панель не должна иметь возможности выйти за пределы каталога игрового сервера.
 *
 *   node --test tests/
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';

import { PathGuard } from '../src/utils/fsx.js';

const ROOT = path.resolve('/home/gamedock/servers/42');

/** isSafe() в агенте нет — проверяем ровно то, что делает панель: try/catch. */
const isSafe = (guard, p) => {
    try {
        guard.resolve(p);
        return true;
    } catch {
        return false;
    }
};

test('путь внутри корня принимается', () => {
    const guard = new PathGuard(ROOT);

    for (const p of [
        '.',
        '',
        'server.properties',
        'plugins/EssentialsX.jar',
        'world/region/r.0.0.mca',
        'logs/latest.log',
    ]) {
        const resolved = guard.resolve(p);
        assert.ok(
            resolved === ROOT || resolved.startsWith(ROOT + path.sep),
            `${p} должен остаться внутри корня, получен ${resolved}`,
        );
    }
});

test('resolve(".") возвращает сам корень', () => {
    const guard = new PathGuard(ROOT);
    assert.equal(guard.resolve('.'), ROOT);
    assert.equal(guard.resolve(''), ROOT);
    assert.equal(guard.resolve(null), ROOT);
    assert.equal(guard.resolve(undefined), ROOT);
});

test('обход через .. отклоняется', () => {
    const guard = new PathGuard(ROOT);

    for (const p of [
        '../43/server.properties',
        '../../etc/passwd',
        'plugins/../../43',
        'a/b/../../../c',
        '../../../../../../etc/shadow',
    ]) {
        assert.equal(isSafe(guard, p), false, `${p} не должен проходить проверку`);
    }
});

test('короткий префикс пути (/home/gamedock/servers/4) не проходит', () => {
    const guard = new PathGuard(ROOT);

    // Классическая ошибка: сравнение строк вместо разбора путей.
    assert.equal(isSafe(guard, '../4/secret'), false);
});

test('нулевой байт отклоняется', () => {
    const guard = new PathGuard(ROOT);

    assert.equal(isSafe(guard, 'server.properties\0.txt'), false);
    assert.equal(isSafe(guard, '\0'), false);
});

test('абсолютный путь трактуется как относительный (не выходит наружу)', () => {
    const guard = new PathGuard(ROOT);

    // Панель иногда присылает «/etc/passwd» — это должно стать <root>/etc/passwd,
    // а не доступом к настоящему /etc/passwd.
    const resolved = guard.resolve('/etc/passwd');
    assert.equal(resolved, path.join(ROOT, 'etc', 'passwd'));
    assert.ok(resolved.startsWith(ROOT + path.sep));
});

test('обратные слэши нормализуются', () => {
    const guard = new PathGuard(ROOT);

    assert.equal(guard.resolve('plugins\\EssentialsX.jar'), path.join(ROOT, 'plugins', 'EssentialsX.jar'));
    assert.equal(isSafe(guard, 'plugins\\..\\..\\..\\etc'), false);
});

test('relative() возвращает путь от корня', () => {
    const guard = new PathGuard(ROOT);

    assert.equal(guard.relative(path.join(ROOT, 'plugins', 'a.jar')), 'plugins/a.jar');
    assert.equal(guard.relative(ROOT), '.');
});

test('resolve() бросает исключение с понятным сообщением', () => {
    const guard = new PathGuard(ROOT);

    assert.throws(() => guard.resolve('../../../etc/passwd'), /за пределы/);
    assert.throws(() => guard.resolve('a\0b'), /нулевой байт/);
});
