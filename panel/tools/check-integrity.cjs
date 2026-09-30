// Ищет следы порчи, из-за которой установщик ведёт себя не как задумано:
//  1. ${ без закрывающей }  (напр. "${which/,/^}/p" вместо "${which},/^}/p")
//  2. непарная кавычка в строке
//  3. строка, заканчивающаяся пробелом там, где должна быть "\" — потерянное
//     продолжение строки (признак того же самого)
const fs = require('fs');

const files = [
  'C:/hostingpl/deploy/install.sh',
  'C:/hostingpl/deploy/agent.sh',
  'C:/hostingpl/panel/tools/test-system-checks.sh',
  'C:/hostingpl/panel/tools/test-install-logic.sh',
  'C:/hostingpl/panel/tools/test-menu.sh',
];

let bad = 0;

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const lines = src.split('\n');
  const name = f.split('/').pop();
  const found = [];

  lines.forEach((line, i) => {
    // 1. ${ без парной } в пределах строки
    const opens = (line.match(/\$\{/g) || []).length;
    const closes = (line.match(/\}/g) || []).length;
    if (opens > closes) {
      found.push(`строка ${i + 1}: ${opens} открывающих \${  против ${closes} закрывающих }  →  ${line.trim()}`);
    }
  });

  // 2. потерянное продолжение строки: строка кончается пробелом/табом
  //    и следующая не начинается с логического продолжения
  lines.forEach((line, i) => {
    if (i + 1 >= lines.length) return;
    if (/[ \t]$/.test(line) && line.trim() !== '' && !/\\$/.test(line)) {
      const nxt = lines[i + 1].trim();
      // нормально: закрывающая скобка, "then", "else", оператор, комментарий
      if (nxt === '' || nxt.startsWith('#')) return;
      found.push(`строка ${i + 1}: возможно потеряно продолжение →  «${line.trim()}» / далее: «${nxt}»`);
    }
  });

  if (found.length) {
    bad += found.length;
    console.log(`\n${name}: подозрительных мест — ${found.length}`);
    for (const x of found) console.log('   ' + x);
  } else {
    console.log(`${name}: чисто`);
  }
}

console.log(bad === 0 ? '\nOK   следов порчи нет' : `\nFAIL  подозрительных мест: ${bad}`);
process.exit(bad === 0 ? 0 : 1);
