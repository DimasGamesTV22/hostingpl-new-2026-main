#!/usr/bin/env node
/**
 * Статическая проверка PHP без интерпретатора.
 *
 * На машине разработки PHP может отсутствовать, а `php -l` тогда недоступен.
 * Этот скрипт закрывает самые дорогие классы ошибок:
 *
 *   1. balance   — незакрытые скобки (тот же баг, что ломал agent/src/runtimes/docker.js);
 *   2. imports   — `use` на класс, которого нет в проекте;
 *   3. constants — обращение к Model::CONST, которого модель не объявляет;
 *   4. opens     — незакрытый <?php и потерянные кавычки в строках.
 *
 * Использование: node panel/tools/check-php.cjs [корень...]
 */
const fs = require('node:fs');
const path = require('node:path');

const roots = process.argv.slice(2);
if (roots.length === 0) {
    roots.push('panel/app', 'panel/database', 'panel/tests', 'panel/routes', 'panel/config', 'panel/bootstrap');
}

const panelRoot = path.resolve('panel');
const problems = [];
const note = (file, line, msg) => problems.push({ file: rel(file), line, msg });

function rel(p) {
    const r = path.relative(process.cwd(), p);
    return r.startsWith('..') ? p : r;
}

function walk(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (['vendor', 'node_modules', '.git'].includes(entry.name)) continue;
            walk(full, out);
        } else if (entry.name.endsWith('.php')) {
            out.push(full);
        }
    }
    return out;
}

// ── Лексер: убирает комментарии и содержимое строк ───────────────────────

/**
 * Возвращает код с заменёнными строками и комментариями (пробелами),
 * чтобы можно было спокойно считать скобки.
 * lineAt — массив, где lineAt[i] соответствует строке i+1.
 */
function stripLiterals(src) {
    const out = [];
    let i = 0;
    const n = src.length;
    let line = 1;
    let unterminated = null;

    while (i < n) {
        const c = src[i];
        const next = src[i + 1];

        if (c === '\n') { line += 1; out.push('\n'); i += 1; continue; }

        // Однострочные комментарии
        if (c === '/' && next === '/') {
            while (i < n && src[i] !== '\n') i += 1;
            continue;
        }
        if (c === '#' && next !== '[') {
            while (i < n && src[i] !== '\n') i += 1;
            continue;
        }
        // Блочные комментарии
        if (c === '/' && next === '*') {
            const end = src.indexOf('*/', i + 2);
            if (end === -1) { unterminated = `незакрытый /* на строке ${line}`; break; }
            for (let k = i; k < end; k += 1) if (src[k] === '\n') line += 1;
            i = end + 2;
            out.push(' ');
            continue;
        }
        // Heredoc / Nowdoc
        if (c === '<' && src.startsWith('<<<', i)) {
            const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/.exec(src.slice(i));
            if (m) {
                const label = m[2];
                const endRe = new RegExp('^[ \\t]*' + label + '\\b', 'm');
                const rest = src.slice(i);
                const found = endRe.exec(rest);
                if (!found) { unterminated = `незакрытый heredoc <<<${label} на строке ${line}`; break; }
                const body = rest.slice(0, found.index + found[0].length);
                for (const ch of body) if (ch === '\n') line += 1;
                i += body.length;
                out.push(' ');
                continue;
            }
        }
        // Строки
        if (c === "'" || c === '"') {
            const quote = c;
            let j = i + 1;
            while (j < n) {
                if (src[j] === '\\') { j += 2; continue; }
                if (src[j] === quote) break;
                j += 1;
            }
            if (j >= n) { unterminated = `незакрытая строка на строке ${line}`; break; }
            for (let k = i + 1; k < j; k += 1) if (src[k] === '\n') line += 1;
            out.push('""');
            i = j + 1;
            continue;
        }

        out.push(c);
        i += 1;
    }

    return { code: out.join(''), unterminated };
}

// ── 1. Баланс скобок ─────────────────────────────────────────────────────

const PAIRS = { '(': ')', '[': ']', '{': '}' };
const CLOSERS = { ')': '(', ']': '[', '}': '{' };

function checkBalance(file, code) {
    const stack = [];
    const lines = code.split('\n');
    let inLine = 0;

    lines.forEach((text, idx) => {
        for (const ch of text) {
            if (PAIRS[ch]) {
                stack.push({ ch, line: idx + 1 });
            } else if (CLOSERS[ch]) {
                const top = stack.pop();
                if (!top) {
                    note(file, idx + 1, `лишняя «${ch}»`);
                } else if (top.ch !== CLOSERS[ch]) {
                    note(file, idx + 1, `«${ch}» закрывает «${top.ch}», открытую на строке ${top.line}`);
                }
            }
            inLine = idx + 1;
        }
    });

    for (const left of stack) {
        note(file, left.line, `незакрытая «${left.ch}» (открыта здесь)`);
    }
    void inLine;
}

// ── 2. Разрешение use-импортов ───────────────────────────────────────────

/** Строит карту FQCN → файл по всему panel/. */
function buildClassMap() {
    const map = new Map();
    const rootsToScan = ['app', 'database', 'tests', 'config', 'routes', 'bootstrap'];
    for (const r of rootsToScan) {
        const dir = path.join(panelRoot, r);
        if (!fs.existsSync(dir)) continue;
        for (const file of walk(dir)) {
            const src = fs.readFileSync(file, 'utf8');
            const nsMatch = /^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;/m.exec(src);
            const clsMatch = /^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m.exec(src);
            if (!clsMatch) continue;
            const fqcn = nsMatch ? `${nsMatch[1]}\\${clsMatch[1]}` : clsMatch[1];
            map.set(fqcn, file);
        }
    }
    return map;
}

/** Классы PHP, вендоров и фасады — их нет в проекте, и это нормально. */
const EXTERNAL = [
    // Глобальные классы PHP
    'Closure', 'RuntimeException', 'LogicException', 'InvalidArgumentException',
    'Exception', 'ErrorException', 'Throwable', 'DateTimeImmutable', 'DateTimeInterface',
    'DateTimeZone', 'Generator', 'Traversable', 'ArrayAccess', 'JsonSerializable',
    'Stringable', 'Countable', 'Iterator', 'IteratorAggregate', 'JsonException',
    // Вендорные пакеты
    'Monolog', 'Psr', 'Symfony', 'PHPUnit', 'Mockery', 'Faker', 'Doctrine', 'Carbon', 'Zend',
    // Фасады и классы фреймворка с собственными константами
    'Password', 'Hash', 'Crypt', 'Str', 'Arr', 'Gate', 'Route', 'Schema', 'DB', 'Cache',
    'Log', 'Mail', 'Http', 'URL', 'Validator', 'RateLimiter', 'Notification', 'Storage',
    'Artisan', 'Blade', 'Lang', 'Config', 'Event', 'File', 'Queue', 'Redirect', 'Response',
    'Session', 'View', 'Cookie', 'Encrypt', 'Date', 'Number', 'Broadcast', 'Process',
];

/** Константы фреймворка, которые не перечислены в нашем коде. */
const FRAMEWORK_CONST_PREFIXES = ['Password::', 'RateLimiter::', 'Str::', 'Arr::'];

function checkImports(file, src, classMap) {
    const re = /^use\s+(?!function\s|const\s)([A-Za-z0-9_\\]+)\s*(?:as\s+([A-Za-z0-9_]+))?\s*;/gm;
    let m;
    while ((m = re.exec(src)) !== null) {
        const fqcn = m[1];
        const short = fqcn.split('\\').pop();
        // Внешние пакеты (Laravel, Illuminate, PHPUnit, ...) не проверяем
        if (/^(Illuminate|Laravel|PhpUnit|Symfony|Carbon|Psr|Doctrine|Faker|Mockery|Zend|Monolog)\\/i.test(fqcn)) continue;
        if (EXTERNAL.includes(fqcn.split('\\')[0]) || EXTERNAL.includes(short)) continue;        if (!classMap.has(fqcn)) {
            const line = src.slice(0, m.index).split('\n').length;
            note(file, line, `use ${fqcn} — такого класса в проекте нет`);
        }
    }
}

// ── 3. Константы моделей ─────────────────────────────────────────────────

function buildConstantMap(classMap) {
    const map = new Map();
    for (const [fqcn, file] of classMap) {
        const src = fs.readFileSync(file, 'utf8');
        const consts = new Set();
        for (const m of src.matchAll(/public\s+const\s+([A-Z_][A-Z0-9_]*)\s*=/g)) {
            consts.add(m[1]);
        }
        // Свойства-константы и магические методы тоже полезны
        map.set(fqcn, consts);
    }
    return map;
}

function checkConstants(file, src, constantMap, classMap) {
    // PromoCode::TYPE_DISCOUNT, Server::STATUS_RUNNING, ...
    const re = /\b([A-Z][A-Za-z0-9_]*)(?=::)/g;
    const shortToFqcn = new Map();
    for (const fqcn of classMap.keys()) {
        const short = fqcn.split('\\').pop();
        if (!shortToFqcn.has(short)) shortToFqcn.set(short, []);
        shortToFqcn.get(short).push(fqcn);
    }

    const useRe = /^use\s+([A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/gm;
    const aliases = new Map();
    let um;
    while ((um = useRe.exec(src)) !== null) {
        const fqcn = um[1];
        const alias = um[2] || fqcn.split('\\').pop();
        aliases.set(alias, fqcn);
    }

    const selfNs = /^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;/m.exec(src);
    const ownClass = selfNs ? `${selfNs[1]}\\${path.basename(file, '.php')}` : null;

    let m;
    while ((m = re.exec(src)) !== null) {
        const short = m[1];
        if (EXTERNAL.includes(short)) continue;
        // Пропускаем PHP-встроенные и внешние
        if (['PHP_EOL', 'PHP_INT_MAX', 'PHP_VERSION', 'DIRECTORY_SEPARATOR', 'E_ALL', 'M_PI', 'SORT_REGULAR', 'COUNT_NORMAL', 'JSON_THROW_ON_ERROR'].includes(short)) continue;

        const candidates = [];
        if (ownClass && classMap.has(ownClass)) candidates.push(ownClass);
        if (aliases.has(short)) candidates.push(aliases.get(short));
        for (const c of shortToFqcn.get(short) || []) if (!candidates.includes(c)) candidates.push(c);

        if (candidates.length === 0) continue; // внешний класс — не наша забота

        // Ищем реальное обращение: Short::CONST
        const after = src.slice(m.index + m[0].length);
        const cm = /^::([A-Z_][A-Z0-9_]*)/.exec(after);
        if (!cm) continue;
        const constName = cm[1];

        const known = candidates.some((fqcn) => (constantMap.get(fqcn) || new Set()).has(constName));
        if (!known) {
            const line = src.slice(0, m.index).split('\n').length;
            note(file, line, `${short}::${constName} — константа не найдена в ${candidates.join(', ')}`);
        }
    }
}

// ── Запуск ───────────────────────────────────────────────────────────────

const files = [];
for (const r of roots) {
    if (!fs.existsSync(r)) {
        console.error(`Каталог не найден: ${r}`);
        process.exit(1);
    }
    walk(r, files);
}

const classMap = buildClassMap();
const constantMap = buildConstantMap(classMap);

let checks = 0;
for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');

    if (!src.includes('<?php')) {
        note(file, 1, 'нет открывающего <?php');
        continue;
    }

    const { code, unterminated } = stripLiterals(src);
    if (unterminated) {
        note(file, 1, unterminated);
        continue;
    }

    checkBalance(file, code);
    checkImports(file, src, classMap);
    checkConstants(file, src, constantMap, classMap);
    checks += 1;
}

console.log(`Проверено PHP-файлов: ${checks} (классов в проекте: ${classMap.size})`);

if (problems.length === 0) {
    console.log('OK   скобки сбалансированы, все use-импорты и константы существуют');
    process.exit(0);
}

const byFile = new Map();
for (const p of problems) {
    if (!byFile.has(p.file)) byFile.set(p.file, []);
    byFile.get(p.file).push(p);
}

console.log(`\nНайдено проблем: ${problems.length}\n`);
for (const [file, list] of byFile) {
    console.log(file);
    for (const p of list) console.log(`  ${String(p.line).padStart(5)}| ${p.msg}`);
    console.log('');
}

process.exit(1);
