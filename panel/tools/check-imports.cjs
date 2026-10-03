// node --check ловит только синтаксис. Битые пути в импортах он не видит,
// а из-за них модуль вообще не загружается — так и вышло: server-manager.js
// импортировал '../query/index.js', хотя файл лежит в src/query/index.js,
// и агент не стартовал ни разу.
const fs = require('fs');
const path = require('path');

const ROOT = 'C:/hostingpl';
const ROOTS = [ROOT + '/agent', ROOT + '/bot'];
const SRC_DIRS = ['src', 'bin', 'tests'];
const SKIP_DIRS = new Set(['node_modules', '.git']);

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP_DIRS.has(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (/\.(js|mjs|cjs)$/.test(e.name)) out.push(full);
  }
  return out;
}

let broken = 0;
let checked = 0;

for (const root of ROOTS) {
  const files = [];
  for (const d of SRC_DIRS) walk(path.join(root, d), files);

  console.log(`\n=== ${path.basename(root)}: файлов ${files.length} ===`);

  for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    const dir = path.dirname(file);

    for (const m of src.matchAll(/(?:^|\n)\s*(?:import|export)[^;]*?from\s+['"]([^'"]+)['"]/g)) {
      const spec = m[1];
      if (!spec.startsWith('.') && !spec.startsWith('/')) continue;   // node: и пакеты

      checked++;
      const target = path.resolve(dir, spec);
      if (!fs.existsSync(target)) {
        broken++;
        console.log(`  БИТЫЙ ИМПОРТ  ${file.replace(/\\/g, '/').replace(ROOT.replace(/\\/g, '/') + '/', '')}`);
        console.log(`               → ${spec}`);
        console.log(`               ищем: ${target.replace(/\\/g, '/').replace(ROOT.replace(/\\/g, '/') + '/', '')}`);
      }
    }
  }
}

console.log(`\n=== ИТОГ ===`);
console.log(`  относительных импортов проверено: ${checked}`);
console.log(`  битых: ${broken}`);
process.exit(broken === 0 ? 0 : 1);