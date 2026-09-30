#!/usr/bin/env node
/**
 * Сверяет docs/api.md с фактическим routes/api.php.
 *
 * Документация API расходится с кодом медленно и незаметно: метод убрали,
 * а таблица осталась. Здесь вылавливаем расхождения в обе стороны.
 */
const fs = require('node:fs');

const doc = fs.readFileSync('docs/api.md', 'utf8');
const routes = fs.readFileSync('panel/routes/api.php', 'utf8');

// ── Эндпоинты из документации ───────────────────────────────────────────
const docEndpoints = new Set();
for (const m of doc.matchAll(/`(GET|POST|PUT|PATCH|DELETE)\s+(\/api\/[A-Za-z0-9_\-{}\/.:]+)`/gi)) {
    docEndpoints.add(`${m[1].toUpperCase()} ${m[2]}`);
}
// Однострочный вид: `GET /api/servers`
// Пропускаем префиксы-звёздочки (`/api/v1/*`) — это группы, не эндпоинты.
for (const m of doc.matchAll(/`\/?(api\/[A-Za-z0-9_\-{}\/.:]+)`/g)) {
    docEndpoints.add(`? ${m[1]}`);
}
for (const p of [...docEndpoints]) {
    if (p.startsWith('? ') && /\/\*$/.test(p.slice(2))) docEndpoints.delete(p);
}

// ── Эндпоинты из routes/api.php ─────────────────────────────────────────
const routeEndpoints = [];
let groupPrefix = [];
for (const raw of routes.split(/\r?\n/)) {
    const line = raw.trim();

    const gm = /^Route::prefix\(([^)]*)\)->name\([^)]*\)->(?:middleware\([^)]*\)->)?group/.exec(line);
    if (gm) {
        const parts = [...gm[1].matchAll(/'([^']*)'/g)].map((m) => m[1]);
        groupPrefix = parts;
    }
    if (/^}\);/.test(line) && groupPrefix.length && !/Route::/.test(line)) {
        // закрываем внешнюю группу — приблизительно: сбрасываем префикс
    }

    const rm = /^Route::(get|post|put|patch|delete)\((?:\s*)'([^']*)'/.exec(line);
    if (rm) {
        const method = rm[1].toUpperCase();
        // Важно: схлопываем слэши ПОСЛЕ склейки, иначе ведущий «/» удваивается.
        const path = ('/' + [...groupPrefix, rm[2]].filter(Boolean).join('/')).replace(/\/{2,}/g, '/');
        routeEndpoints.push({ method, path, raw: line });
    }
}

console.log(`В routes/api.php найдено эндпоинтов: ${routeEndpoints.length}`);
console.log(`В docs/api.md упомянуто путей: ${docEndpoints.size}\n`);

const norm = (x) => x
    .replace(/\{[^}]*\}/g, '{}')   // {id} и {server} — одно и то же место
    .replace(/^\/+/, '')
    .replace(/\/+$/, '');

// ── 1. Пути из документа, которых нет в роутах ─────────────────────────
const knownPaths = new Set(routeEndpoints.map((e) => norm(`/api${e.path}`)));

const unknown = [...docEndpoints]
    .filter((e) => e.startsWith('? '))
    .map((e) => e.slice(2))
    .map(norm)
    .filter((p) => {
        if (knownPaths.has(p)) return false;
        // Объявленный префикс группы (`/api/v1`, `/api/agent`) — не эндпоинт
        if ([...knownPaths].some((k) => k.startsWith(p + '/'))) return false;
        return true;
    });

if (unknown.length) {
    console.log('Документировано, но НЕТ в routes/api.php:');
    for (const p of unknown) console.log(`  ${p}`);
    console.log('');
} else {
    console.log('OK   все документированные пути существуют в routes/api.php\n');
}

// ── 2. Эндпоинты в роутах, которых нет в документации ──────────────────
const docNorm = norm(doc);
const missing = routeEndpoints.filter((e) => {
    const full = norm(`/api${e.path}`);
    // Параметр в документации может называться иначе ({id} против {server})
    const variants = [
        full,
        full.replace(/\/\{server\}\//g, '/{id}/').replace(/\/\{server\}$/, '/{id}'),
        full.replace(/\/\{id\}\//g, '/{server}/').replace(/\/\{id\}$/, '/{server}'),
    ];
    return !variants.some((v) => docNorm.includes(v));
});

if (missing.length) {
    console.log('Есть в routes/api.php, но НЕ описано в docs/api.md:');
    for (const e of missing) console.log(`  ${e.method} /api${e.path}`);
    console.log('');
} else {
    console.log('OK   все эндпоинты routes/api.php описаны в документации');
}
