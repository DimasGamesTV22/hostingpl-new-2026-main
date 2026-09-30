#!/usr/bin/env node
/**
 * Сверяет матрицу поддерживаемых ОС в установщиках с документацией.
 *
 * SUPPORTED_SYSTEMS в deploy/install.sh и deploy/agent.sh — единственный
 * источник правды, но он легко разъезжается с docs/installation.md и
 * docs/requirements.md. Здесь ловим расхождения в обе стороны, а заодно
 * проверяем, что матрица одинакова в обоих установщиках.
 *
 * Использование: node panel/tools/check-distros.cjs
 */
const fs = require('node:fs');

const REPO = process.argv[2] || '.';
const read = (p) => fs.readFileSync(`${REPO}/${p}`, 'utf8');

const problems = [];
const note = (m) => problems.push(m);

// ── 1. Вытаскиваем матрицы из установщиков ──────────────────────────────

function parseMatrix(file) {
    const src = read(file);
    const m = /SUPPORTED_SYSTEMS=\(([\s\S]*?)\n\)/.exec(src);
    if (!m) return null;

    const rows = [];
    for (const line of m[1].split('\n')) {
        const q = /"([^"]+)"/.exec(line);
        if (!q) continue;
        const [id, ver, code, php] = q[1].split('|');
        if (id && ver && code) rows.push({ id, ver, code, php });
    }
    return rows;
}

const panel = parseMatrix('deploy/install.sh');
const agent = parseMatrix('deploy/agent.sh');

if (!panel) {
    console.log('FAIL  в deploy/install.sh не найден массив SUPPORTED_SYSTEMS');
    process.exit(1);
}
if (!agent) {
    console.log('FAIL  в deploy/agent.sh не найден массив SUPPORTED_SYSTEMS');
    process.exit(1);
}

const key = (r) => `${r.id}|${r.ver}`;
const panelMap = new Map(panel.map((r) => [key(r), r]));

// ── 2. Матрицы установщиков должны совпадать ────────────────────────────

for (const r of agent) {
    const p = panelMap.get(key(r));
    if (!p) {
        note(`deploy/agent.sh знает ${key(r)} (${r.code}), а deploy/install.sh — нет`);
    } else if (p.code !== r.code || p.php !== r.php) {
        note(`${key(r)}: расхождение между установщиками — agent.sh ${r.code}/${r.php} против install.sh ${p.code}/${p.php}`);
    }
}
for (const p of panel) {
    if (!agent.some((r) => key(r) === key(p))) {
        note(`deploy/install.sh знает ${key(p)} (${p.code}), а deploy/agent.sh — нет`);
    }
}

console.log(`Матрица install.sh: ${panel.length} систем, agent.sh: ${agent.length}`);

// ── 3. Обязательные системы из задания ──────────────────────────────────

const REQUIRED = [
    ['debian', '11'], ['debian', '12'], ['debian', '13'],
    ['ubuntu', '22.04'], ['ubuntu', '24.04'],
];

for (const [id, ver] of REQUIRED) {
    if (!panelMap.has(`${id}|${ver}`)) {
        note(`нет обязательной системы ${id} ${ver}`);
    }
}

for (const r of panel) {
    if (r.id === 'debian' && r.id === 'ubuntu') continue;
    if (!['debian', 'ubuntu'].includes(r.id)) {
        note(`${key(r)}: неожиданный ID дистрибутива «${r.id}»`);
    }
    if (!/^\d+(\.\d+)?$/.test(r.ver)) {
        note(`${key(r)}: версия «${r.ver}» не похожа на номер релиза`);
    }
    if (!/^[a-z]+$/.test(r.code)) {
        note(`${key(r)}: кодовое имя «${r.code}» подозрительно`);
    }
    if (!/^\d+\.\d+$/.test(r.php)) {
        note(`${key(r)}: PHP «${r.php}» должен быть major.minor`);
    }
}

// PHP в дистрибутиве должен быть >= 7.4, иначе версия в таблице неверна
for (const r of panel) {
    const [maj, min] = r.php.split('.').map(Number);
    if (maj < 7 || (maj === 7 && min < 4)) {
        note(`${key(r)}: PHP ${r.php} в дистрибутиве слишком стар для Laravel 11 — вероятно, опечатка`);
    }
}

// ── 4. Документация должна перечислять те же системы ────────────────────

for (const doc of ['docs/installation.md', 'docs/requirements.md']) {
    let text;
    try {
        text = read(doc);
    } catch {
        note(`${doc}: файл не найден`);
        continue;
    }

    // Ищем упоминания вроде «Debian 12», «Ubuntu 24.04» и кодовые имена
    for (const r of panel) {
        const name = r.id === 'debian' ? 'Debian' : 'Ubuntu';
        const mentioned = new RegExp(`${name}[^\\n]{0,20}\\b${r.ver.replace('.', '\\.')}\\b`, 'i')
            .test(text)
            || text.toLowerCase().includes(r.code.toLowerCase());

        if (!mentioned) {
            note(`${doc}: не упомянута ${name} ${r.ver} (${r.code})`);
        }
    }
}

// ── 5. Ссылки на репозиторий ────────────────────────────────────────────

const installSrc = read('deploy/install.sh');
const repoMatch = /GAMEDOCK_REPO_URL="\$\{GAMEDOCK_REPO:-([^}]+)\}"/.exec(installSrc);
if (!repoMatch) {
    note('deploy/install.sh: нет переменной GAMEDOCK_REPO_URL с URL по умолчанию');
} else if (/your-org|example\.com/.test(repoMatch[1])) {
    note(`deploy/install.sh: в GAMEDOCK_REPO_URL остался шаблонный адрес — ${repoMatch[1]}`);
} else {
    console.log(`Репозиторий по умолчанию: ${repoMatch[1]}`);
}

// ── 6. Имена PHP-пакетов ────────────────────────────────────────────────
//
// Пакеты PHP называются с точкой: php8.3-fpm, php8.3-redis. Служба, сокет
// и каталоги — тоже с точкой. Подстановка ${PHP_VERSION/./} в списке
// apt-пакетов молча превращала их в несуществующие php83-fpm.

if (/php\$\{PHP_VERSION\/\.\/\}/.test(installSrc)) {
    note('deploy/install.sh: найдена подстановка ${PHP_VERSION/./} — пакеты PHP нужно называть с точкой (php8.3-fpm)');
}

// Промежуточная переменная вроде php_ver="${PHP_VERSION/./}" тоже ломает имя.
// Если переменная просто копирует $PHP_VERSION — всё в порядке, поэтому
// проверяем присваивание, а не само упоминание имени переменной.
const verAssign = [...installSrc.matchAll(/([A-Za-z_][A-Za-z0-9_]*ver[A-Za-z0-9_]*)="\$\{PHP_VERSION(?:\/\.\/[^}]*)?\}"/gi)];
for (const m of verAssign) {
    if (m[0].includes('/./')) {
        note(`deploy/install.sh: ${m[0]} убирает точку из версии PHP — имя пакета получится неверным`);
    }
}

// Служба и сокет обязаны быть с точкой
if (!/php\$\{PHP_VERSION\}-fpm/.test(installSrc)) {
    note('deploy/install.sh: не найдено обращение к службе php${PHP_VERSION}-fpm (с точкой)');
}

if (!/\/etc\/php\/\$PHP_VERSION\//.test(installSrc)) {
    note('deploy/install.sh: не найден путь /etc/php/$PHP_VERSION/ (с точкой)');
}

// Функции, вызываемые через $(… ), не должны ничего печатать в stdout:
// иначе текст попадёт в имя пакета и apt-get его не найдёт.
const phpPkgBody = /php_pkg\(\)\s*\{([\s\S]*?)\n\}/.exec(installSrc);
if (phpPkgBody) {
    const body = phpPkgBody[1];
    // log/warn/ok/echo без перенаправления в stderr
    const noisy = [...body.matchAll(/(log|warn|ok|echo|printf)\b[^\n]*$/gm)]
        .filter((m) => !m[0].includes('>&2') && !m[0].includes(">/dev/null"));
    for (const m of noisy) {
        if (m[0].trimStart().startsWith('printf')) continue; // printf '%s' — это результат
        note(`deploy/install.sh: php_pkg() печатает в stdout (${m[0].trim()}) — вызывается как $(php_pkg …), текст попадёт в имя пакета`);
    }
}

// ── 7. cgroup v2 ────────────────────────────────────────────────────────
//
// Debian 11 по умолчанию cgroup v1: установщик обязан это учитывать,
// иначе рантайм native «установится», но не запустится.

if (!/cgroup\.controllers/.test(installSrc)) {
    note('deploy/install.sh: нет проверки cgroup v2 (/sys/fs/cgroup/cgroup.controllers)');
}
if (!/unified_cgroup_hierarchy/.test(installSrc)) {
    note('deploy/install.sh: нет подсказки про systemd.unified_cgroup_hierarchy=1 для cgroup v1');
}

// ── Итог ────────────────────────────────────────────────────────────────

if (problems.length === 0) {
    console.log('\nOK   матрица ОС согласована между установщиками и документацией');
    process.exit(0);
}

console.log(`\nНайдено проблем: ${problems.length}\n`);
for (const p of problems) console.log(`  • ${p}`);
process.exit(1);
