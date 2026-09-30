#!/usr/bin/env node
/**
 * Сверяет матрицу поддерживаемых ОС в deploy/install.sh с документацией.
 *
 * Матрица живёт в переменной SUPPORTED_SYSTEMS — единственном источнике
 * правды. Документация рассказывает о поддержке своими словами, и рано или
 * поздно разъезжается: выходит Debian 14, а в README всё ещё «12/13».
 *
 * Использование: node panel/tools/check-distros.cjs
 */
const fs = require('node:fs');

const installer = fs.readFileSync('deploy/install.sh', 'utf8');

// ── Матрица из установщика ──────────────────────────────────────────────
const block = /SUPPORTED_SYSTEMS=\(([\s\S]*?)\n\)/.exec(installer);
if (!block) {
    console.error('В deploy/install.sh не найден массив SUPPORTED_SYSTEMS');
    process.exit(1);
}

const supported = [];
for (const m of block[1].matchAll(/"([a-z]+)\|([0-9.]+)\|([a-z]+)\|([0-9.]+)"/g)) {
    supported.push({ id: m[1], version: m[2], codename: m[3], php: m[4] });
}

if (supported.length === 0) {
    console.error('SUPPORTED_SYSTEMS пуст или имеет неожиданный формат');
    process.exit(1);
}

console.log(`Матрица установщика (${supported.length}):`);
for (const s of supported) {
    console.log(`  ${s.id} ${s.version} (${s.codename}) — PHP ${s.php}`);
}

// ── Документы, которые обязаны её повторять ─────────────────────────────
const docs = [
    'README.md',
    'docs/installation.md',
    'docs/requirements.md',
];

let problems = 0;

/**
 * Упомянута ли система в тексте.
 *
 * Ловим оба написания:
 *   «Debian 13 (trixie)»      — полная форма
 *   «Debian 11, 12, 13»       — список, где дальше идут только цифры и разделители
 */
function mentions(text, id, version) {
    if (new RegExp(`${id}\\s+${version.replace('.', '\\.')}\\b`, 'i').test(text)) {
        return true;
    }

    // Собираем хвост после названия дистрибутива, пока идут версии.
    // Разделители: запятая, дефис, тире, косая черта («Debian 12/13», «11–13»).
    const re = new RegExp(
        `${id}\\s+(\\d+(?:\\.\\d+)?(?:\\s*[,–—/-]\\s*\\d+(?:\\.\\d+)?)*)`,
        'gi',
    );

    let m;
    while ((m = re.exec(text)) !== null) {
        const versions = m[1].match(/\d+(?:\.\d+)?/g) || [];
        if (versions.includes(version)) return true;
    }
    return false;
}

for (const file of docs) {
    if (!fs.existsSync(file)) {
        console.log(`\n${file}: файла нет — пропускаю`);
        continue;
    }

    const text = fs.readFileSync(file, 'utf8');
    const missing = supported.filter((s) => !mentions(text, s.id, s.version));

    if (missing.length) {
        problems += 1;
        console.log(
            `\n${file}: НЕ упомянуты — ${missing.map((s) => `${s.id} ${s.version}`).join(', ')}`,
        );
    } else {
        console.log(`\n${file}: все ${supported.length} систем упомянуты`);
    }
}

// ── Устаревшие утверждения ─────────────────────────────────────────────
// Установщик перестал считать Debian 11 устаревшим и Ubuntu «не основной
// платформой» — такие формулировки в документации вводят в заблуждение.
const stale = [
    { file: 'README.md', re: /Debian 12\/13/i, why: 'устарело: поддерживаются 11, 12 и 13' },
    { file: 'docs/installation.md', re: /Debian 12\/13/i, why: 'устарело: поддерживаются 11, 12 и 13' },
    { file: 'docs/installation.md', re: /не основная платформа/i, why: 'Ubuntu теперь полностью поддерживается' },
];

for (const s of stale) {
    if (!fs.existsSync(s.file)) continue;
    const text = fs.readFileSync(s.file, 'utf8');
    const m = s.re.exec(text);
    if (m) {
        problems += 1;
        console.log(`\n${s.file}: «${m[0]}» — ${s.why}`);
    }
}

if (problems > 0) {
    console.log(`\nНайдено расхождений: ${problems}`);
    process.exit(1);
}

console.log('\nOK   документация совпадает с матрицей установщика');
