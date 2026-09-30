// Ищет дубли индексов в миграциях: колонка объявлена с ->index() или
// ->unique(), и на неё отдельно вызывается $table->index(...) / $table->unique(...).
// MySQL отвечает на это «Duplicate key name», и миграция падает.
'use strict';

const fs = require('fs');
const path = require('path');

const DIR = 'C:/hostingpl/panel/database/migrations';
const files = fs.readdirSync(DIR).filter((f) => f.endsWith('.php')).sort();

const problems = [];

for (const f of files) {
  const lines = fs.readFileSync(path.join(DIR, f), 'utf8').split('\n');

  let table = null;
  let inlineCols = [];   // колонки с ->index()/->unique() прямо в объявлении
  let sepCalls = [];     // отдельные вызовы index/unique

  const flush = () => {
    if (!table) return;
    for (const [col, kind, line] of inlineCols) {
      const dup = sepCalls.find((s) => s.col === col && s.kind === kind);
      if (dup) {
        problems.push({
          f, line: dup.line,
          msg: `таблица ${table}: ${kind} на «${col}» объявлен дважды — строкой ${line} (${kind} внутри объявления колонки) и строкой ${dup.line} ($table->${kind}('${col}'))`,
        });
      }
    }
    table = null;
    inlineCols = [];
    sepCalls = [];
  };

  lines.forEach((line, i) => {
    const create = line.match(/Schema::create\(\s*'([a-z0-9_]+)'/);
    if (create) {
      flush();
      table = create[1];
      return;
    }

    if (!table) return;

    // конец блока: }); на своём уровне
    if (/^\s{8}\}\);\s*$/.test(line)) {
      flush();
      return;
    }

    // колонка с встроенным индексом или уникальностью:
    // $table->timestamp('expires_at')->nullable()->index();
    const col = line.match(/^\s*\$table->\w+\(\s*'([a-z0-9_]+)'/);
    if (col && /->(index|unique)\(\)/.test(line)) {
      const kind = line.includes('->unique()') ? 'unique' : 'index';
      inlineCols.push([col[1], kind, i + 1]);
    }

    // отдельный вызов: $table->index('expires_at'); или $table->index(['a','b']);
    const sep = line.match(/\$table->(index|unique)\(\s*(.+?)\s*\);/);
    if (sep) {
      const kind = sep[1];
      const arg = sep[2];
      // одиночная колонка в виде строки — именно она даёт дубль с встроенным
      const single = arg.match(/^'([a-z0-9_]+)'$/);
      if (single) {
        sepCalls.push({ col: single[1], kind, line: i + 1 });
      }
    }
  });

  flush();
}

if (problems.length === 0) {
  console.log('OK   дублирующихся индексов в миграциях нет');
  process.exit(0);
}

console.log(`ДУБЛЕЙ ИНДЕКСОВ: ${problems.length}\n`);
for (const p of problems) {
  console.log(`  ${p.f}:${p.line}`);
  console.log(`      ${p.msg}`);
  console.log();
}
process.exit(1);
