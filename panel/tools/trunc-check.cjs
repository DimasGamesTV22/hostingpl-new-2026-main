#!/usr/bin/env node
/**
 * Диагностика: ищет первую строку, после которой файл перестаёт парситься.
 *
 * В отличие от «простого» bisect, здесь мы не просто режем файл, а подставляем
 * недостающие закрывающие скобки по уровню вложенности. Такой префикс синтаксически
 * корректен (если исходный код до точки обреза был корректен), поэтому
 * бинарный поиск даёт настоящего виновника, а не симптом.
 *
 * Использование: node tools/trunc-check.cjs <file.js> [startLine]
 */
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const file = path.resolve(process.argv[2] || 'agent/src/runtimes/docker.js');
const src = fs.readFileSync(file, 'utf8');
const lines = src.split(/\r?\n/);

const OPEN = { '(': ')', '[': ']', '{': '}' };

/**
 * Грубый, но достаточный сканер: идёт по строкам, пропускает содержимое
 * строковых литералов и комментариев, считает только «кодовые» скобки.
 * Регулярные выражения не учитываются — на этом файле их единицы и они
 * сбалансированы, поэтому на результат не влияют.
 */
function stackAt(text) {
    const stack = [];
    let i = 0;
    const n = text.length;
    let inLineComment = false;

    while (i < n) {
        const c = text[i];
        const next = text[i + 1];

        if (inLineComment) {
            if (c === '\n') inLineComment = false;
            i += 1;
            continue;
        }
        if (c === '/' && next === '/') { inLineComment = true; i += 2; continue; }
        if (c === '/' && next === '*') {
            const end = text.indexOf('*/', i + 2);
            i = end === -1 ? n : end + 2;
            continue;
        }
        if (c === '"' || c === "'") {
            i += 1;
            while (i < n && text[i] !== c) { if (text[i] === '\\') i += 1; i += 1; }
            i += 1;
            continue;
        }
        if (c === '`') {
            // template literal: пропускаем, но учитываем ${ } внутри
            i += 1;
            let depth = 0;
            while (i < n) {
                if (text[i] === '\\') { i += 2; continue; }
                if (depth === 0 && text[i] === '`') { i += 1; break; }
                if (depth === 0 && text[i] === '$' && text[i + 1] === '{') { depth += 1; i += 2; continue; }
                if (depth > 0) {
                    if (text[i] === '{') depth += 1;
                    else if (text[i] === '}') depth -= 1;
                }
                i += 1;
            }
            continue;
        }
        if (OPEN[c]) {
            // запоминаем, каким ключевым словом открыт блок —
            // блоки try/else/finally нельзя резать «на середине»
            let before = '';
            for (let k = i - 1; k >= 0; k -= 1) {
                const ch = text[k];
                if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') continue;
                if (ch === '{' || ch === '}' || ch === '(' || ch === ')' || ch === '[' || ch === ']' || ch === ';') break;
                before = ch + before;
                if (before.length >= 5) break;
            }
            stack.push({ c, before });
            i += 1;
            continue;
        }
        if (c === ')' || c === ']' || c === '}') { stack.pop(); i += 1; continue; }
        i += 1;
    }
    return stack;
}

/** Точку обреза считаем безопасной, если ни один открытый блок не ждёт catch/else. */
function isSafeCut(stack) {
    return stack.every((s) => !/^(try|else|do|finally)$/.test(s.before));
}

function closerFor(stack) {
    return stack.slice().reverse().map((s) => OPEN[s.c]).join('\n');
}

const tmp = path.join(os.tmpdir(), `trunc-check-${process.pid}.mjs`);

function check(text) {
    fs.writeFileSync(tmp, text, 'utf8');
    try {
        execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' });
        return null;
    } catch (e) {
        const out = (e.stderr ? e.stderr.toString() : e.stdout ? e.stdout.toString() : e.message);
        const m = out.match(/SyntaxError: (.+)/);
        return { msg: m ? m[1] : out.trim().split('\n')[0], raw: out };
    }
}

function prefixFor(endLine) {
    const text = lines.slice(0, endLine).join('\n');
    const stack = stackAt(text);
    if (!isSafeCut(stack)) return null;
    return text + '\n' + closerFor(stack) + '\n';
}

// --- находим «безопасные» точки обреза: конец любого блока ---
const cutPoints = [];
lines.forEach((l, idx) => {
    if (/^\s*(\}|\))/ .test(l) && l.trim() !== '') cutPoints.push(idx + 1);
});
cutPoints.push(lines.length);

const bad = [];
let checked = 0;
for (const cp of cutPoints) {
    const text = prefixFor(cp);
    if (text === null) continue; // небезопасная точка обреза
    checked += 1;
    const err = check(text);
    if (err) bad.push({ line: cp, err: err.msg });
}

console.log(`Файл: ${file} (${lines.length} строк)`);
console.log(`Точек обреза безопасно: ${checked}, из них с ошибкой: ${bad.length}`);

if (bad.length === 0) {
    const full = check(src);
    console.log(full
        ? `Файл не парсится целиком (${full.msg}), но все усечённые варианты валидны — проблема в самом конце файла.`
        : 'Файл валиден.');
} else {
    console.log('\nПервая точка обреза, на которой появляется ошибка:');
    const first = bad[0];
    console.log(`  строка ${first.line}: ${first.err}`);
    const from = Math.max(0, first.line - 14);
    console.log(`\nКонтекст (строки ${from + 1}–${first.line}):`);
    for (let i = from; i < first.line; i += 1) {
        console.log(`${String(i + 1).padStart(5)}| ${lines[i]}`);
    }
}

try { fs.unlinkSync(tmp); } catch { /* ок */ }
