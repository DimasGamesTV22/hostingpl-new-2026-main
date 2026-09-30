#!/usr/bin/env node
/**
 * Проверки целостности GameDock (без PHP — чистый Node).
 *
 *   node panel/tools/check.js            # все проверки
 *   node panel/tools/check.js ru         # только ключи перевода
 *
 * Проверяет:
 *   1. Все view('…') из контроллеров существуют как blade-файлы.
 *   2. Все @include('…') в шаблонах существуют.
 *   3. Blade-директивы сбалансированы (@if/@endif, @forelse/@empty/@endforelse, …).
 *   4. Все route('…') в blade и PHP соответствуют маршрутам.
 *   5. Все ключи __('…') есть в resources/lang/ru (и en).
 *
 * Запуск: после клонирования, в CI и перед деплоем.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const PANEL = path.join(ROOT, 'panel');
const VIEWS = path.join(PANEL, 'resources', 'views');

let failures = 0;

const ok = (msg) => console.log(`\x1b[32mOK\x1b[0m   ${msg}`);
const fail = (msg, items) => {
  failures += 1;
  console.log(`\x1b[31mFAIL\x1b[0m ${msg}`);
  for (const [k, v] of items) console.log(`       - ${k}  ←  ${[...v].slice(0, 3).join(', ')}`);
};

function walk(dir, exts, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', 'vendor', '.git', 'storage'].includes(entry.name)) continue;
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p, exts, out);
    else if (exts.some((x) => entry.name.endsWith(x))) out.push(p);
  }
  return out;
}

const rel = (p) => path.relative(ROOT, p).split(path.sep).join('/');

/* ── 1 + 2: views и partials ───────────────────────────────────────────── */
{
  const missing = new Map();
  const add = (view, file) => {
    const p = path.join(VIEWS, view.split('.').join(path.sep) + '.blade.php');
    if (!fs.existsSync(p)) {
      if (!missing.has(view)) missing.set(view, new Set());
      missing.get(view).add(rel(file));
    }
  };

  for (const f of walk(path.join(PANEL, 'app', 'Http', 'Controllers'), ['.php'])) {
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/view\(\s*'([^']+)'/g)) add(m[1], f);
  }
  missing.size ? fail('view() without a blade file', missing) : ok('all controller views exist');

  const missingPartials = new Map();
  for (const f of walk(VIEWS, ['.blade.php'])) {
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/@include\(\s*'([^']+)'\s*(?:,[^)]*)?\)/g)) {
      const view = m[1];
      if (view.endsWith('.')) continue; // динамический @include
      const p = path.join(VIEWS, view.split('.').join(path.sep) + '.blade.php');
      if (!fs.existsSync(p)) {
        if (!missingPartials.has(view)) missingPartials.set(view, new Set());
        missingPartials.get(view).add(rel(f));
      }
    }
  }
  missingPartials.size
    ? fail('@include() without a partial', missingPartials)
    : ok('all @include partials exist');
}

/* ── 3: баланс blade-директив ──────────────────────────────────────────── */
{
  const PAIRS = {
    if: 'endif', unless: 'endunless', isset: 'endisset', empty: 'endempty',
    foreach: 'endforeach', forelse: 'endforelse', for: 'endfor', while: 'endwhile',
    switch: 'endswitch', php: 'endphp', push: 'endpush', auth: 'endauth', guest: 'endguest',
    can: 'endcan', cannot: 'endcannot', env: 'endenv', production: 'endproduction',
    error: 'enderror', verbatim: 'endverbatim',
  };
  const BRANCH = new Set(['else', 'elseif', 'case', 'default', 'break', 'continue']);

  const problems = new Map();

  for (const file of walk(VIEWS, ['.blade.php'])) {
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    const stack = [];

    lines.forEach((line, i) => {
      for (const m of line.matchAll(/@([a-zA-Z]+)(\(|\b)/g)) {
        const dir = m[1];
        if (BRANCH.has(dir)) continue;

        if (dir === 'section') {
          if (m[2] === '(') {
            const rest = line.slice(m.index + dir.length + 1);
            if (/^\(\s*'[^']+'\s*,/.test(rest)) continue; // однострочный @section
            stack.push({ dir, line: i + 1 });
          }
          continue;
        }
        if (dir === 'yield') continue;

        if (dir === 'empty' && stack.length && stack[stack.length - 1].dir === 'forelse') continue;

        if (PAIRS[dir]) {
          stack.push({ dir, line: i + 1 });
        } else if (dir.startsWith('end')) {
          const want = dir.slice(3);
          const top = stack.pop();
          if (!top || top.dir !== want) {
            const key = `${rel(file)}:${i + 1}`;
            const prev = problems.get(key) || new Set();
            prev.add(`@${dir} закрывает @${top ? top.dir + ' (строка ' + top.line + ')' : 'ничего'}`);
            problems.set(key, prev);
          }
        }
      }
    });

    for (const un of stack) {
      const key = `${rel(file)}:${un.line}`;
      const prev = problems.get(key) || new Set();
      prev.add(`@${un.dir} не закрыт`);
      problems.set(key, prev);
    }
  }

  problems.size ? fail('blade blocks are not balanced', problems) : ok('blade blocks are balanced');
}

/* ── 4: имена маршрутов ────────────────────────────────────────────────── */
{
  const src = ['web.php', 'api.php']
    .map((f) => fs.readFileSync(path.join(PANEL, 'routes', f), 'utf8'))
    .join('\n');

  const PREFIXES = ['', 'panel.', 'admin.', 'server.', 'panel.server.', 'webhooks.', 'api.', 'auth.'];
  const names = new Set();

  for (const m of src.matchAll(/->name\(\s*'([^']+)'/g)) {
    for (const p of PREFIXES) names.add(p + m[1]);
  }
  for (const res of ['servers', 'tickets', 'users', 'nodes', 'games', 'tariffs', 'promo', 'referrals']) {
    for (const p of PREFIXES) {
      names.add(p + res);
      for (const a of ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']) {
        names.add(`${p}${res}.${a}`);
      }
    }
  }

  const files = [...walk(VIEWS, ['.blade.php']), ...walk(path.join(PANEL, 'app'), ['.php'])];
  const missing = new Map();

  for (const f of files) {
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/route\(\s*'([^']+)'/g)) {
      if (names.has(m[1])) continue;
      if (!missing.has(m[1])) missing.set(m[1], new Set());
      missing.get(m[1]).add(rel(f));
    }
  }

  missing.size ? fail("route() names not found in routes/", missing) : ok('all route() names resolve');
}

/* ── 5: ключи перевода ─────────────────────────────────────────────────── */
function langKeys(locale) {
  const dir = path.join(PANEL, 'resources', 'lang', locale);
  const keys = new Set();

  for (const name of fs.readdirSync(dir)) {
    if (!name.endsWith('.php')) continue;
    const stack = [name.replace(/\.php$/, '')];

    for (const line of fs.readFileSync(path.join(dir, name), 'utf8').split('\n')) {
      const m = line.match(/^\s*'([A-Za-z0-9_]+)'\s*=>\s*(\[|null|true|false|[-'"0-9]|$)/);

      if (!m) {
        if (/^\s*\],?\s*$/.test(line) && stack.length > 1) stack.pop();
        continue;
      }

      keys.add([...stack, m[1]].join('.'));

      if (m[2] === '[' && /\[\s*$/.test(line)) stack.push(m[1]);
    }
  }

  return keys;
}

const files = [...walk(VIEWS, ['.blade.php']), ...walk(path.join(PANEL, 'app'), ['.php'])];

for (const locale of ['ru', 'en']) {
  const keys = langKeys(locale);
  const missing = new Map();

  for (const f of files) {
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/__\(\s*'([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)'/g)) {
      if (keys.has(m[1])) continue;
      if (!missing.has(m[1])) missing.set(m[1], new Set());
      missing.get(m[1]).add(rel(f));
    }
  }

  missing.size
    ? fail(`missing translation keys (${locale})`, missing)
    : ok(`all translation keys exist in ${locale}`);
}

console.log('');
process.exit(failures ? 1 : 0);
