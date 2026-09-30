// Ловит ошибки, которые пропускают баланс скобок и проверка use-импортов:
//   1) класс, на который ссылается код (внедрение, ::class, new), но файла нет;
//   2) понижение видимости метода относительно родителя (private/protected
//      метод с тем же именем, что и публичный у базового класса);
//   3) continue внутри switch — PHP считает его break и ругается;
//   4) строки с нечётным числом одинарных кавычек — частый признак
//      пропавших символов.
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = 'C:/hostingpl/panel';

function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === 'vendor' || e.name === '.git') continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, acc);
    else if (e.name.endsWith('.php')) acc.push(full);
  }
  return acc;
}

const files = walk(ROOT);
const problems = [];

console.log(`Проверяю ${files.length} PHP-файлов\n`);

// ── 1. Какие классы объявлены в проекте ──────────────────────────────
const declared = new Set();
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const m = src.match(/^\s*namespace\s+([^;]+);/m);
  const ns = m ? m[1].trim() : '';
  for (const cm of src.matchAll(/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/gm)) {
    declared.add(ns ? `${ns}\\${cm[1]}` : cm[1]);
  }
}

// ── 2. Ссылки на несуществующие классы ───────────────────────────────
// Пропускаем всё, что не App\ — там только сторонние библиотеки.
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const lines = src.split('\n');
  lines.forEach((line, i) => {
    const t = line.trim();
    if (t.startsWith('//') || t.startsWith('*') || t.startsWith('/*')) return;

    const refs = new Set();
    // \App\Foo\Bar::class
    for (const m of line.matchAll(/\\?(App\\[A-Za-z0-9_\\]+)::class/g)) {
      refs.add(m[1].replace(/^\\/, ''));
    }
    // new \App\Foo\Bar(
    for (const m of line.matchAll(/new\s+\\?(App\\[A-Za-z0-9_\\]+)\s*\(/g)) {
      refs.add(m[1].replace(/^\\/, ''));
    }
    // в type-hint: private readonly \App\Foo\Bar $x   /  : \App\Foo\Bar
    for (const m of line.matchAll(/:\s*\\?(App\\[A-Za-z0-9_\\]+)\s*[\$?]/g)) {
      refs.add(m[1].replace(/^\\/, ''));
    }
    // app(\App\Foo\Bar::class)
    for (const m of line.matchAll(/\\?(App\\[A-Za-z0-9_\\]+)::class/g)) {
      refs.add(m[1].replace(/^\\/, ''));
    }

    for (const r of refs) {
      if (!declared.has(r)) {
        problems.push({
          kind: 'НЕТ КЛАССА',
          file: f.replace(ROOT + '/', ''),
          line: i + 1,
          text: `${r} — файла нет`,
        });
      }
    }
  });
}

// ── 3. Понижение видимости относительно трейтов базового Controller ──
// Базовый Controller использует AuthorizesRequests и ValidatesRequests —
// они дают публичные authorize() и validate().
const baseController = fs.readFileSync(path.join(ROOT, 'app/Http/Controllers/Controller.php'), 'utf8');
const baseMethods = new Set();
for (const m of baseController.matchAll(/^\s*use\s+([A-Za-z]+);/gm)) baseMethods.add(m[1]);
// Методы, которые приходят из трейтов Laravel (публичные)
const TRAIT_METHODS = new Set(['authorize', 'validate', 'validateWith', 'validateWithBag']);

for (const f of files) {
  if (!f.includes('/Controllers/')) continue;
  const src = fs.readFileSync(f, 'utf8');
  lines_loop: for (const m of src.matchAll(
    /^\s*(private|protected)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/gm)) {
    if (TRAIT_METHODS.has(m[2])) {
      problems.push({
        kind: 'ВИДИМОСТЬ',
        file: f.replace(ROOT + '/', ''),
        text: `${m[1]} function ${m[2]}() понижает видимость публичного метода трейта ValidatesRequests — PHP падает при загрузке классов`,
      });
    }
  }
}

// ── 4. continue внутри switch ────────────────────────────────────────
// Переходы внутри switch ищутся по реальным границам блока (счёт фигурных
// скобок), а не «есть ли где-то выше switch»: иначе любой continue в цикле
// под switch'ем даёт ложное срабатывание.
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const lines = src.split('\n');

  // границы блоков switch в номерах строк
  const stack = [];
  const switchRanges = [];
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    if (/\bswitch\s*\(/.test(line)) {
      // идём вперёд, считая скобки до закрытия тела switch
      let depth = 0;
      let started = false;
      for (let j = i; j < lines.length; j++) {
        for (const ch of lines[j]) {
          if (ch === '{') { depth++; started = true; }
          else if (ch === '}') {
            depth--;
            if (started && depth === 0) {
              switchRanges.push([i, j]);
              i = j; // внешний цикл продолжит с j
              break;
            }
          }
        }
        if (switchRanges.length && switchRanges[switchRanges.length - 1][1] === j) break;
      }
    }
    void stack;
  }

  for (const [from, to] of switchRanges) {
    for (let i = from + 1; i < to; i++) {
      const line = lines[i];
      const t = line.trim();
      if (t.startsWith('//') || t.startsWith('*') || t.startsWith('#')) continue;
      if (/(?<![0-9])continue\s*;/.test(line) && !/continue\s+[2-9]/.test(line)) {
        problems.push({
          kind: 'CONTINUE',
          file: f.replace(ROOT + '/', ''),
          line: i + 1,
          text: 'continue внутри switch PHP считает равным break — нужен continue 2',
        });
      }
    }
  }
}

// ── 5. Нечётное число кавычек (эвристика пропущенного символа) ───────
// Пропускаем содержимое комментариев целиком: в комментариях апострофы
// попадаются постоянно (например «heartbeat'ам») и это не ошибка.
for (const f of files) {
  const lines = fs.readFileSync(f, 'utf8').split('\n');
  let inBlockComment = false;

  lines.forEach((line, i) => {
    const t = line.trim();

    if (inBlockComment) {
      if (t.includes('*/')) inBlockComment = false;
      return;
    }
    if (t.startsWith('/*')) {
      if (!t.includes('*/')) inBlockComment = true;
      return;
    }
    if (t.startsWith('//') || t.startsWith('*') || t.startsWith('#') || t.startsWith('{{')) return;
    if (/\\"/.test(line) || /"[^"]*'/.test(line)) return;

    const q = (line.match(/'/g) || []).length;
    if (q % 2 === 1) {
      problems.push({
        kind: 'КАВЫЧКИ',
        file: f.replace(ROOT + '/', ''),
        line: i + 1,
        text: `нечётное число кавычек (${q}) — возможно пропущен символ`,
      });
    }
  });
}

// ── Вывод ────────────────────────────────────────────────────────────
const byKind = {};
for (const p of problems) (byKind[p.kind] ||= []).push(p);

for (const [kind, list] of Object.entries(byKind)) {
  console.log(`${kind}: ${list.length}`);
  for (const p of list.slice(0, 25)) {
    console.log(`  ${p.file}${p.line ? ':' + p.line : ''}`);
    console.log(`      ${p.text}`);
  }
  if (list.length > 25) console.log(`  … ещё ${list.length - 25}`);
  console.log();
}

// КАВЫЧКИ — эвристика с ложными срабатываниями, показываем, но не роняем
const fatal = problems.filter((p) => p.kind !== 'КАВЫЧКИ').length;

if (fatal === 0) {
  console.log('OK   несуществующих классов, конфликтов видимости и continue в switch не найдено');
  process.exit(0);
}
console.log(`FAIL серьёзных проблем: ${fatal} (плюс ${(byKind['КАВЫЧКИ'] || []).length} подозрительных на кавычки)`);
process.exit(1);
