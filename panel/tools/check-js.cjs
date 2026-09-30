// Синтаксическая проверка всех .js/.mjs файлов через node --check.
// node --check парсит файл как CommonJS; для ESM этого достаточно,
// потому что import/export в .js внутри пакета с "type": "module"
// проверяются как скрипты — мы ловим именно синтаксис.
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const roots = process.argv.slice(2);
const files = [];

function walk(dir) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        if (['node_modules', '.git', 'vendor', 'public'].includes(e.name)) continue;
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (/\.(js|mjs|cjs)$/.test(e.name)) files.push(p);
    }
}

for (const r of roots) {
    if (!fs.existsSync(r)) continue;
    walk(r);
}

let failed = 0;

for (const file of files) {
    try {
        execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
    } catch (e) {
        failed += 1;
        const out = (e.stderr ? e.stderr.toString() : '') + (e.stdout ? e.stdout.toString() : '');
        const first = out.split('\n').find((l) => /SyntaxError/.test(l)) || out.split('\n')[0];
        const loc = out.split('\n')[0];

        console.log(`\x1b[31mFAIL\x1b[0m ${file}`);
        console.log(`     ${loc.trim()}`);
        console.log(`     ${first.trim()}`);
    }
}

console.log(failed ? `\n${failed} из ${files.length} файлов с ошибками` : `OK: ${files.length} файлов разбираются`);
process.exit(failed ? 1 : 0);
