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

# Логика определения ОС в install.sh — чистые функции bash, их можно проверить
# настоящим интерпретатором (нужен лишь bash, без дистрибутива).
if command -v bash >/dev/null 2>&1; then
    printf 'Проверяю логику install.sh (матрица систем, версии, detect_distro)…\n'
    if ! bash panel/tools/test-install-logic.sh 2>&1 | tail -2 | sed 's/^/  /'; then
        logic_bad=1
    fi
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

printf '\nПроверено: %d, ошибок синтаксиса: %d, проблем с heredoc: %d, с CRLF: %d, ошибок логики: %d\n' \
    "$total" "$bad" "$heredoc_bad" "$crlf" "$logic_bad"

if [ "$bad" -gt 0 ] || [ "$crlf" -gt 0 ] || [ "$heredoc_bad" -gt 0 ] || [ "$logic_bad" -gt 0 ]; then
    exit 1
fi

printf 'OK   все shell-скрипты разбираются и используют LF\n'
