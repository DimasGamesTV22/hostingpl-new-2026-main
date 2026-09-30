#!/usr/bin/env node
/**
 * Проверяет, что каждому `<<МЕТКА` соответствует строка-терминатор МЕТКА.
 *
 * Незакрытый heredoc «съедает» весь код до следующего совпадения и сдвигает
 * сообщение об ошибке на десятки строк вниз — самая коварная ошибка в
 * больших install.sh. Здесь мы показываем пары открыватель/закрыватель.
 *
 * Использование: node panel/tools/check-heredocs.cjs deploy/install.sh
 */
const fs = require('node:fs');

const files = process.argv.slice(2);
if (files.length === 0) {
    console.error('Укажите файлы: node check-heredocs.cjs deploy/install.sh');
    process.exit(1);
}

let failures = 0;

for (const file of files) {
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    const open = []; // стек heredoc'ов: { label, line, quoted, dash }
    const pairs = [];
    const unbalanced = [];

    lines.forEach((raw, idx) => {
        const lineNo = idx + 1;

        // Если мы внутри heredoc — ищем только терминатор.
        if (open.length > 0) {
            const top = open[open.length - 1];
            const re = top.dash
                ? new RegExp(`^[ \\t]*${top.label}\\s*$`)
                : new RegExp(`^${top.label}\\s*$`);

            if (re.test(raw)) {
                top.end = lineNo;
                pairs.push(top);
                open.pop();
            }
            return;
        }

        // Ищем открыватели. Нас интересует только случай <<МЕТКА в конце строки
        // ( cat > file <<'EOF' ), а не <<<here-string и не Mid << heredoc.
        //
        // ВАЖНО: bash требует, чтобы слово-разделитель было полным. Форма
        // `<<'EOF` (кавычка открыта, но не закрыта) — синтаксическая ошибка,
        // хотя её легко принять за обычный heredoc. Проверяем это отдельно.
        const loose = /<<(-?)\s*(?!<)(.*)$/.exec(raw);
        if (loose) {
            const tail = loose[2];
            // Считаем кавычки в «хвосте» после <<
            let sq = 0, dq = 0;
            for (let k = 0; k < tail.length; k += 1) {
                if (tail[k] === '\\') { k += 1; continue; }
                if (tail[k] === "'") sq += 1;
                if (tail[k] === '"') dq += 1;
            }
            if (sq % 2 !== 0 || dq % 2 !== 0) {
                unbalanced.push({
                    line: idx + 1,
                    raw: raw.trim(),
                    sq,
                    dq,
                });
                return;
            }
        }

        const re = /<<(-?)\s*(?!<)\s*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\2([^<]*)$/;
        const m = re.exec(raw);
        if (!m) return;

        // Если в остатке строки есть открывающая кавычка — это не наш случай
        const rest = m[4].trim();
        if (rest !== '') return;

        open.push({ label: m[3], line: lineNo, dash: m[1] === '-', end: null });
    });

    for (const u of unbalanced) {
        failures += 1;
        console.log(`НЕЗАКРЫТАЯ КАВЫЧКА В РАЗДЕЛИТЕЛЕ  ${file}:${u.line}`);
        console.log(`    ${u.raw}`);
        console.log(`    bash требует полное слово: <<'EOF' или <<EOF, а не <<'EOF\n`);
    }

    for (const h of open) {
        failures += 1;
        console.log(`НЕЗАКРЫТЫЙ HEREDOC  ${file}:${h.line}  <<${h.dash ? '-' : ''}${h.label}`);

        const from = Math.max(0, h.line - 2);
        console.log('  контекст открытия:');
        for (let k = from; k < h.line + 2 && k < lines.length; k += 1) {
            console.log(`    ${String(k + 1).padStart(5)}| ${lines[k]}`);
        }
        console.log('');
    }

    for (const p of pairs) {
        console.log(`  ok  ${file}:${p.line} -> ${p.end}  (${p.label})`);
    }
}

process.exit(failures > 0 ? 1 : 0);
