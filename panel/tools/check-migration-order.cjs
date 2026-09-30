// Ищет внешние ключи, которые ссылаются на таблицы, ещё не созданные
// этой миграцией или более поздней. MySQL отвечает на это errno 150
// «Foreign key constraint is incorrectly formed», и падает вся миграция.
//
// Пример: game_template_installs создаётся в 000200, а внешний ключ на
// servers.id — таблица появляется только в 000500.
'use strict';

const fs = require('fs');
const path = require('path');

const DIR = 'C:/hostingpl/panel/database/migrations';
const files = fs.readdirSync(DIR).filter((f) => f.endsWith('.php')).sort();

// Кто какую таблицу создаёт
const createdBy = new Map();      // таблица -> файл
for (const f of files) {
  const src = fs.readFileSync(path.join(DIR, f), 'utf8');
  for (const m of src.matchAll(/Schema::create\(\s*'([a-z0-9_]+)'/g)) {
    if (!createdBy.has(m[1])) createdBy.set(m[1], f);
  }
}

console.log(`Миграций: ${files.length}, таблиц: ${createdBy.size}\n`);

const problems = [];

for (const f of files) {
  const src = fs.readFileSync(path.join(DIR, f), 'utf8');
  const lines = src.split('\n');

  lines.forEach((line, i) => {
    // Только реальные внешние ключи: foreignId(...), у которых в той же
    // цепочке вызовов встречается ->constrained(). Обычная колонка
    // unsignedBigInteger('disk_mb') внешним ключом не является.
    // Внимание: группу с именем таблицы нельзя делать необязательной —
    // тогда регулярка матчится пустотой и пропускает настоящие ключи.
    const re = /foreignId(?:For)?\(\s*'([a-z0-9_]+)'\s*\)([^;]*);/g;
    for (const m of line.matchAll(re)) {
      const col = m[1];
      const rest = m[2] || '';

      if (!/->constrained\(/.test(rest)) continue;

      // явное имя таблицы или выведенное из колонки: server_id -> servers
      const tm = rest.match(/->constrained\(\s*'([a-z0-9_]+)'\s*\)/);
      const inferred = tm ? tm[1] : col.replace(/_id$/, 's');
      if (!inferred) continue;

      const target = createdBy.get(inferred);
      if (!target) {
        problems.push({
          f, line: i + 1,
          msg: `${col} → ${inferred}: таблица не создаётся ни одной миграцией`,
        });
      } else if (target > f) {
        problems.push({
          f, line: i + 1,
          msg: `${col} → ${inferred}: таблица создаётся в ${target}, позже этой миграции`,
        });
      }
    }
  });
}

// ── 2. Методы Blueprint, которых нет в Laravel 11 ─────────────────────
// Метод, которого нет, роняет миграцию в рантайме: «Method
// Illuminate\Database\Schema\Blueprint::unsignedDecimal does not exist».
// Проверяем по списку реальных методов схемы.
const BLUEPRINT_OK = new Set([
  'id', 'bigIncrements', 'increments', 'uuid', 'ulid', 'string', 'char', 'text',
  'mediumText', 'longText', 'json', 'jsonb', 'integer', 'bigInteger',
  'unsignedInteger', 'unsignedBigInteger', 'unsignedSmallInteger',
  'unsignedTinyInteger', 'smallInteger', 'tinyInteger', 'float', 'double',
  'decimal', 'boolean', 'enum', 'date', 'dateTime', 'time', 'timestamp',
  'binary', 'uuidMorphs', 'nullableMorphs', 'morphs', 'foreignId',
  'foreignIdFor', 'foreignUlid', 'ipAddress', 'macAddress', 'geometry',
  'year', 'softDeletes', 'softDeletesTz', 'timestamps', 'timestampsTz',
  'rememberToken', 'softDeletes', 'computed', 'virtualAs', 'storedAs',
  'fulltext', 'spatialIndex', 'index', 'unique', 'primary', 'comment',
]);

// Методы-«приставки», которые есть в Laravel 11
const KNOWN_ABSENT = {
  unsignedDecimal: 'в Laravel есть decimal()->unsigned(), но не unsignedDecimal()',
  unsignedFloat: 'в Laravel есть float()->unsigned(), но не unsignedFloat()',
  unsignedDouble: 'в Laravel есть double()->unsigned(), но не unsignedDouble()',
};

const missingMethods = [];

for (const f of files) {
  const src = fs.readFileSync(path.join(DIR, f), 'utf8');
  const lines = src.split('\n');
  lines.forEach((line, i) => {
    for (const m of line.matchAll(/\$table->([a-zA-Z]+)\(/g)) {
      const name = m[1];
      if (BLUEPRINT_OK.has(name)) continue;
      if (KNOWN_ABSENT[name]) {
        missingMethods.push({
          f, line: i + 1,
          msg: `${name}() — ${KNOWN_ABSENT[name]}`,
        });
      } else if (!/^(where|comment|primary|index|unique|fulltext|spatialIndex)$/.test(name)) {
        // неизвестный метод: сообщаем, но мягко — список может быть неполным
        missingMethods.push({
          f, line: i + 1,
          msg: `${name}() — метода нет среди известных Blueprint (проверьте вручную)`,
        });
      }
    }
  });
}

if (missingMethods.length) {
  console.log(`МЕТОДЫ, КОТОРЫХ НЕТ В LARAVEL: ${missingMethods.length}\n`);
  const byFile2 = {};
  for (const p of missingMethods) (byFile2[p.f] ||= []).push(p);
  for (const [f, list] of Object.entries(byFile2)) {
    console.log(`  ${f}`);
    for (const p of list) console.log(`    строка ${p.line}: ${p.msg}`);
    console.log();
  }
}

if (problems.length === 0 && missingMethods.length === 0) {
  console.log('OK   порядок внешних ключей верный, методы Blueprint существуют');
  process.exit(0);
}

console.log(`НАРУШЕНИЙ ПОРЯДКА: ${problems.length}`);
if (problems.length) {
  const byFile = {};
  for (const p of problems) (byFile[p.f] ||= []).push(p);
  for (const [f, list] of Object.entries(byFile)) {
    console.log(`  ${f}`);
    for (const p of list) console.log(`    строка ${p.line}: ${p.msg}`);
    console.log();
  }
}
process.exit(1);
