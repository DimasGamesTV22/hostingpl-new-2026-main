#!/usr/bin/env bash
# Проверка синтаксиса всех shell-скриптов репозитория и переводов строк.
# Запуск:  bash panel/tools/check-shell.sh
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

total=0
bad=0
crlf=0
heredoc_bad=0
logic_bad=0
menu_bad=0
sys_bad=0
integrity_bad=0

# Логика определения ОС в install.sh — чистые функции bash, их можно проверить
# настоящим интерпретатором (нужен лишь bash, без дистрибутива).
#
# Вывод пишем во временный файл и код возврата проверяем ОТДЕЛЬНО от вывода:
# в конвейере `test | tail | sed` статус берётся у последней команды (sed),
# из-за чего падение теста проглатывалось и проверка рапортовала об успехе.
LOGIC_TEST=panel/tools/test-install-logic.sh
MENU_TEST=panel/tools/test-menu.sh
SYS_TEST=panel/tools/test-system-checks.sh

# Прогоняет bash-тест и печатает хвост вывода. Код возврата берётся у самого
# теста, а не у пайпа: в `test | tail | sed` статус считается у sed, и провал
# проглатывался — проверка рапортовала об успехе.
run_bash_test() {
    local title="$1" script="$2"
    printf '%s\n' "$title"

    if [[ ! -f $script ]]; then
        printf '  \033[31mFAIL\033[0m нет файла %s\n' "$script"
        return 1
    fi

    local out rc
    out="$(bash "$script" 2>&1)"
    rc=$?

    if (( rc == 0 )); then
        printf '%s\n' "$out" | tail -2 | sed 's/^/  /'
        return 0
    fi

    printf '%s\n' "$out" | grep -E 'FAIL' | sed 's/^/  /' | head -10
    return 1
}

if command -v bash >/dev/null 2>&1; then
    run_bash_test 'Логика install.sh (матрица, apt, PHP-пакеты)…' "$LOGIC_TEST" || logic_bad=1
    printf '\n'

    run_bash_test 'Меню установщика (пункты, подменю, гейт)…' "$MENU_TEST" || menu_bad=1
    printf '\n'

    run_bash_test 'Проверки окружения (диск, лог-файл)…' "$SYS_TEST" || sys_bad=1
    printf '\n'
fi

# bash -n не ловит потерянные скобки и потерянные обратные слэши: такой код
# остаётся синтаксически валидным, но означает не то, что задумано.
# Нашёлся именно такой случай — установщик молча уходил не туда.
if command -v node >/dev/null 2>&1; then
    printf 'Целостность скриптов (скобки, продолжения строк)…\n'
    if node panel/tools/check-integrity.cjs >/tmp/gamedock_integrity_out 2>&1; then
        printf '  OK\n'
    else
        integrity_bad=1
        grep -E 'подозрительных|строка' /tmp/gamedock_integrity_out | sed 's/^/  /' | head -10
    fi
    rm -f /tmp/gamedock_integrity_out
    printf '\n'
fi

# Сначала подробная проверка heredoc'ов — bash -n на неё ругается невнятно
# («unexpected EOF while looking for matching `'» на строке с телом).
# Node нужен только для этого шага; без него работаем на одном bash -n.
if command -v node >/dev/null 2>&1; then
    while IFS= read -r f; do
        if ! node panel/tools/check-heredocs.cjs "$f" >/tmp/gamedock_heredoc_out 2>&1; then
            heredoc_bad=$((heredoc_bad + 1))
            cat /tmp/gamedock_heredoc_out
        fi
    done < <(find . -name '*.sh' -not -path './node_modules/*' -not -path './vendor/*' -not -path './panel/tools/*' | sort)
    rm -f /tmp/gamedock_heredoc_out
else
    printf 'Внимание: node не найден, проверка heredoc пропущена\n\n'
fi

while IFS= read -r f; do
    total=$((total + 1))

    if ! bash -n "$f" 2>/tmp/gamedock_shell_err; then
        bad=$((bad + 1))
        printf 'СИНТАКСИС  %s\n' "$f"
        sed 's/^/           /' /tmp/gamedock_shell_err | head -5
    fi

    # Скрипты установки исполняются на Linux — CRLF там ломает shebang и аргументы.
    if LC_ALL=C grep -qU $'\r' "$f"; then
        crlf=$((crlf + 1))
        printf 'CRLF       %s\n' "$f"
    fi
done < <(find . -name '*.sh' -not -path './node_modules/*' -not -path './vendor/*' -not -path './panel/tools/*' | sort)

rm -f /tmp/gamedock_shell_err

printf '\nПроверено: %d, ошибок синтаксиса: %d, heredoc: %d, CRLF: %d, логика: %d, меню: %d, окружение: %d, целостность: %d\n' \
    "$total" "$bad" "$heredoc_bad" "$crlf" "$logic_bad" "$menu_bad" "$sys_bad" "$integrity_bad"

if [ "$bad" -gt 0 ] || [ "$crlf" -gt 0 ] || [ "$heredoc_bad" -gt 0 ] \
   || [ "$logic_bad" -gt 0 ] || [ "$menu_bad" -gt 0 ] || [ "$sys_bad" -gt 0 ] \
   || [ "$integrity_bad" -gt 0 ]; then
    exit 1
fi

printf 'OK   все shell-скрипты разбираются и используют LF\n'
