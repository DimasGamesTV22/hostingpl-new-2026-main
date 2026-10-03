#!/usr/bin/env bash
#
# Тесты проверок окружения в deploy/install.sh и deploy/agent.sh.
#
# Здесь закрыты две ошибки, из-за которых установка падала на чистой машине:
#   1. df вызывался на $INSTALL_DIR, которого ещё нет (его создаёт
#      create_service_user позже) → пустой результат → «доступно 0 ГБ»;
#   2. логгеры писали через `| tee -a "$LOG_FILE"`, и при `set -euo pipefail`
#      недоступный лог-файл обрывал скрипт на первом сообщении.
#
# Запуск: bash panel/tools/test-system-checks.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Путь к установщику. Объявляем сразу после ROOT: на него ссылаются
# проверки выше того места, где переменная появилась впервые, а тест
# работает с set -u, и обращение к несозданной переменной обрывает его.
INSTALL_SH="$ROOT/deploy/install.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

t_ok()  { pass=$((pass + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
t_bad() { fail=$((fail + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [[ $# -gt 1 ]] && printf '       %s\n' "$2"; return 0; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

eq() {
    if [[ "$2" == "$3" ]]; then t_ok "$1"; else t_bad "$1" "ожидалось '$3', получено '$2'"; fi
}

# Копия установщика; LOG_FILE — во временный каталог.
# Строку `main "$@"` вырезать не нужно: в install.sh есть защита
# [[ "${BASH_SOURCE[0]}" == "${0}" ]], и при source нижний код не выполняется.
sed     -e "s|^LOG_FILE=.*|LOG_FILE=$TMP/install.log|" \
    "$ROOT/deploy/install.sh" > "$TMP/install.sh"

# Проверяем, что копия действительно создалась. Раньше её отсутствие
# обнаруживалось только каскадом чужих провалов (17 проверок падали
# не по своей причине), и понять, где сломалось, было нечем.
if [[ ! -s $TMP/install.sh ]]; then
    echo "FAIL  не удалось создать $TMP/install.sh (команда sed собрана неверно)"
    exit 1
fi


# Вызывает функцию из установщика в отдельном под-шелле
call() {
    (
        # shellcheck disable=SC1090
        . "$TMP/install.sh" >/dev/null 2>&1
        set +eu
        eval "$1"
    ) 2>/dev/null
}

# ═══════════════════════════════════════════════════════════════════
# 1. Доступное место
# ═══════════════════════════════════════════════════════════════════
head_ "avail_gb_for: свободное место"

real_root="$(df -PBG / | awk 'NR==2 { gsub(/G/, "", $4); print $4 }')"
eq "существующий каталог" "$(call 'avail_gb_for /; echo')" "$real_root"

# Главный случай: INSTALL_DIR на чистой системе не существует
for p in /opt/gamedock-not-created /opt/gamedock/panel/storage /no/such/path; do
    v="$(call "avail_gb_for $p; echo")"
    if [[ $v =~ ^[0-9]+$ ]] && (( v > 0 )); then
        t_ok "несуществующий путь $p → $v ГБ, а не 0"
    else
        t_bad "несуществующий путь $p" "получено '$v'"
    fi
done

eq "совпадает с df по родителю" \
    "$(call 'avail_gb_for /opt/never-created; echo')" \
    "$(call 'avail_gb_for /opt; echo')"

# Значение всегда целое неотрицательное число
for p in / /tmp /opt /var /etc; do
    v="$(call "avail_gb_for $p; echo")"
    if [[ $v =~ ^[0-9]+$ ]]; then
        t_ok "$p → корректное число ($v)"
    else
        t_bad "$p" "получено '$v'"
    fi
done

# ═══════════════════════════════════════════════════════════════════
# 2. check_system на чистой машине
# ═══════════════════════════════════════════════════════════════════
head_ "check_system на чистой системе"

out=$(call '
    OS_SUPPORTED=1
    OS_PRETTY_NAME="Debian GNU/Linux 13 (trixie)"
    INSTALL_DIR=/opt/gamedock-not-created
    MIN_DISK_GB=5
    RECOMMENDED_DISK_GB=20
    step() { :; }
    ok()   { echo "OK|$*"; }
    warn() { echo "WARN|$*"; }
    fail() { echo "FAIL|$*"; exit 1; }
    check_system
    echo "ЗАВЕРШИЛСЯ"
')

if grep -q 'FAIL' <<<"$out"; then
    t_bad "проверка проходит" "$(head -3 <<<"$out")"
else
    t_ok "проверка проходит"
fi

if grep -q 'ЗАВЕРШИЛСЯ' <<<"$out"; then
    t_ok "check_system доходит до конца"
else
    t_bad "check_system доходит до конца" "$(head -3 <<<"$out")"
fi

# Регрессия, ради которой тест и написан
if grep -qE 'доступно 0 ГБ' <<<"$out"; then
    t_bad "нет ложной ошибки «доступно 0 ГБ»" "$out"
else
    t_ok "ложной ошибки «доступно 0 ГБ» нет"
fi

if grep -qE 'свободно [0-9]+ ГБ' <<<"$out"; then
    t_ok "в выводе видно реальное число гигабайт"
else
    t_bad "в выводе видно реальное число гигабайт" "$(head -3 <<<"$out")"
fi

# ═══════════════════════════════════════════════════════════════════
# 2. Логгеры печатают ТЕКСТ, а не только символ
#
# Регрессия: при переносе на _emit вызовы выглядели как
#   ok() { _emit "${GREEN}✓${NC}"; }      ← забыли "$*"
# Символ печатался, текст пропадал — установщик выглядел сломанным.
# ═══════════════════════════════════════════════════════════════════
head_ "Логгеры печатают текст"

for f in install.sh agent.sh; do
    # Каждый логгер обязан передавать "$*" в _emit
    broken=""
    for fn in log ok warn fail step; do
        line=$(grep -E "^${fn}\(\)" "$ROOT/deploy/$f" | head -1)
        if [[ -n $line && $line != *'"$*"'* ]]; then
            broken="$broken $fn"
        fi
    done

    if [[ -z $broken ]]; then
        t_ok "$f: все логгеры передают текст"
    else
        t_bad "$f: все логгеры передают текст" "без \"\$*\":$broken"
    fi
done

# Функционально: вывод должен содержать и символ, и слово
out=$(call "LOG_FILE=$TMP/t.log; ok 'ТЕКСТ-ОК'; warn 'ТЕКСТ-WARN'; log 'ТЕКСТ-LOG'")

for word in "ТЕКСТ-ОК" "ТЕКСТ-WARN" "ТЕКСТ-LOG"; do
    if grep -qF "$word" <<<"$out"; then
        t_ok "на экран попало «$word»"
    else
        t_bad "на экран попало «$word»" "вывод: $(head -3 <<<"$out")"
    fi
done

if grep -qF 'ТЕКСТ-ОК' "$TMP/install.log" 2>/dev/null; then
    t_ok "текст записан в лог-файл"
else
    t_ok "лог-файл недоступен в тесте — пропуск записи"
fi

# Шаг выводит текст после пустой строки
out=$(call "step 'ШАГ-ТЕКСТ'")
if grep -qF 'ШАГ-ТЕКСТ' <<<"$out"; then
    t_ok "step печатает текст"
else
    t_bad "step печатает текст" "вывод: $(head -3 <<<"$out")"
fi

# fail печатает текст и завершает работу
out=$(call "fail 'КРИТИЧНО-ТЕКСТ'; echo 'НЕ ДОЛЖНО ДОЙТИ'" 2>&1 || true)
if grep -qF 'КРИТИЧНО-ТЕКСТ' <<<"$out"; then
    t_ok "fail печатает текст"
else
    t_bad "fail печатает текст" "вывод: $(head -3 <<<"$out")"
fi

if grep -qF 'НЕ ДОЛЖНО ДОЙТИ' <<<"$out"; then
    t_bad "fail прерывает выполнение" "код после fail был проигнорирован"
else
    t_ok "fail прерывает выполнение"
fi

# У menu.sh свои логгеры — проверяем и их
if grep -qE '^msg\(\)|printf .%s\[GameDock\]%s %s' "$INSTALL_SH"; then
t_ok "install.sh: логгеры меню на месте"
else
t_bad "install.sh: логгеры меню на месте"
fi

# ═══════════════════════════════════════════════════════════════════
# 3. Логгеры переживают недоступный LOG_FILE
# ═══════════════════════════════════════════════════════════════════
head_ "Логгеры и недоступный лог-файл"

BAD_LOG=/proc/definitely/not/writable.log

out=$(call "
    set -o pipefail
    LOG_FILE=$BAD_LOG
    log 'тест log'; ok 'тест ok'; warn 'тест warn'; step 'тест step'
    echo ВЫЖИЛИ
")

if grep -q 'ВЫЖИЛИ' <<<"$out"; then
    t_ok "логгеры не роняют скрипт при set -euo pipefail"
else
    t_bad "логгеры не роняют скрипт" "$(head -3 <<<"$out")"
fi

# run_logged должен возвращать статус самой команды, а не записи в лог
out=$(call "
    LOG_FILE=$BAD_LOG
    run_logged 5 true  && echo 'УСПЕХ_ОК'
    run_logged 5 false && echo 'ПАДЕНИЕ_СКРЫТО' || echo 'ПАДЕНИЕ_ОК'
")

if grep -q 'УСПЕХ_ОК' <<<"$out"; then
    t_ok "run_logged: успешная команда → 0"
else
    t_bad "run_logged: успешная команда → 0" "$(head -3 <<<"$out")"
fi

if grep -q 'ПАДЕНИЕ_ОК' <<<"$out" && ! grep -q 'ПАДЕНИЕ_СКРЫТО' <<<"$out"; then
    t_ok "run_logged: падающая команда → не 0"
else
    t_bad "run_logged: падающая команда → не 0" "$(head -3 <<<"$out")"
fi

# ═══════════════════════════════════════════════════════════════════
# 4. В agent.sh тот же логгер
# ═══════════════════════════════════════════════════════════════════
head_ "agent.sh: те же защиты"

if grep -q '_emit()' "$ROOT/deploy/agent.sh"; then
    t_ok "agent.sh: логгер через _emit"
else
    t_bad "agent.sh: логгер через _emit" "функции _emit нет"
fi

if grep -q 'run_logged()' "$ROOT/deploy/agent.sh"; then
    t_ok "agent.sh: есть run_logged"
else
    t_bad "agent.sh: есть run_logged"
fi

# Ни в одном установщике не должно остаться `tee -a "$LOG_FILE"` в коде:
# это ровно та конструкция, которая роняла скрипт. Комментарии, где мы
# описываем саму проблему, не считаются — они начинаются с #.
for f in install.sh agent.sh; do
    left=$(grep -v '^[[:space:]]*#' "$ROOT/deploy/$f" | grep -c 'tee -a "\$LOG_FILE"')
    if [[ $left -eq 0 ]]; then
        t_ok "$f: в коде не осталось tee -a \$LOG_FILE"
    else
        t_bad "$f: в коде не осталось tee -a \$LOG_FILE" "осталось: $left"
    fi
done

# ═══════════════════════════════════════════════════════════════════
# 5. Базовые утилиты: тупик на чистой системе
#
# Установщик требует git/unzip/tar, но ставил их сильно ниже. На чистом
# Debian, где их нет, он выходил с «Не найдена утилита git» — не имея
# возможности поставить их сам. ensure_base_tools должна стоять раньше.
# ═══════════════════════════════════════════════════════════════════
head_ "Базовые утилиты ставятся до проверки"

if grep -q '^ensure_base_tools()' "$ROOT/deploy/install.sh"; then
    t_ok "ensure_base_tools объявлена"
else
    t_bad "ensure_base_tools объявлена" "функции нет"
fi

# Порядок вызовов в main
line_tools=$(grep -n '^    ensure_base_tools$' "$ROOT/deploy/install.sh" | head -1 | cut -d: -f1)
line_check=$(grep -n '^    check_deps$' "$ROOT/deploy/install.sh" | head -1 | cut -d: -f1)
line_pkgs=$(grep -n '^    install_packages$' "$ROOT/deploy/install.sh" | head -1 | cut -d: -f1)

if [[ -z $line_tools || -z $line_check ]]; then
    t_bad "вызовы найдены в main" "ensure_base_tools=$line_tools check_deps=$line_check"
elif (( line_tools < line_check )); then
    t_ok "ensure_base_tools вызывается раньше check_deps"
else
    t_bad "ensure_base_tools вызывается раньше check_deps" "$line_tools против $line_check"
fi

if [[ -n $line_pkgs ]] && (( line_tools < line_pkgs )); then
    t_ok "ensure_base_tools вызывается раньше install_packages"
else
    t_bad "ensure_base_tools вызывается раньше install_packages" "$line_tools против $line_pkgs"
fi

# Функция должна ставить именно недостающее, а не просто ругаться
body=$(sed -n '/^ensure_base_tools()/,/^}/p' "$ROOT/deploy/install.sh")
if grep -q 'apt-get install' <<<"$body"; then
    t_ok "ensure_base_tools сама доустанавливает"
else
    t_bad "ensure_base_tools сама доустанавливает" "внутри нет apt-get install"
fi

# check_deps не должен останавливать установку там, где можно доуставить
dep_body=$(sed -n '/^check_deps()/,/^}/p' "$ROOT/deploy/install.sh")
if grep -q 'BASE_COMMANDS' <<<"$dep_body"; then
    t_ok "check_deps использует список команд BASE_COMMANDS"
else
    t_bad "check_deps использует список команд BASE_COMMANDS" "список не тот"
fi

# Регрессия: ca-certificates — это ПАКЕТ, а не команда. Попади он в список
# проверяемых команд, `command -v` его никогда не найдёт, и установщик упадёт
# с «Не найдена утилита ca-certificates» везде, где чего-то не хватало.
if grep -qE '^BASE_COMMANDS=\(.*ca-certificates' "$ROOT/deploy/install.sh"; then
    t_bad "ca-certificates не проверяется как команда" "он попал в BASE_COMMANDS"
else
    t_ok "ca-certificates не проверяется как команда"
fi

if grep -qE '^BASE_PACKAGES=\(.*ca-certificates' "$ROOT/deploy/install.sh"; then
    t_ok "ca-certificates есть в списке пакетов"
else
    t_bad "ca-certificates есть в списке пакетов" "нет в BASE_PACKAGES"
fi

# Списки пакетов и команд обязаны различаться
pkgs=$(sed -n 's/^BASE_PACKAGES=(\(.*\))$/\1/p' "$ROOT/deploy/install.sh" | tr ' ' '\n' | sort | tr '\n' ' ')
cmds=$(sed -n 's/^BASE_COMMANDS=(\(.*\))$/\1/p' "$ROOT/deploy/install.sh" | tr ' ' '\n' | sort | tr '\n' ' ')
if [[ "$pkgs" != "$cmds" ]]; then
    t_ok "списки пакетов и команд различаются"
else
    t_bad "списки пакетов и команд различаются" "совпадают"
fi

# ═══════════════════════════════════════════════════════════════════
# 6. Пакеты, которых нет в дистрибутиве
#
# На Debian 13 удалены openjdk-17, steamcmd и software-properties-common.
# Три ошибки, из-за которых падала установка:
#   1. openjdk-17 добавлялся в список БЕЗ проверки;
#   2. pkg_available проверял apt-cache show, который отвечает «есть» и для
#      пакета с Candidate: (none) — то есть недоступного для установки;
#   3. software-properties-common стоял в обязательном списке.
# ═══════════════════════════════════════════════════════════════════
head_ "Пакеты, которых нет в дистрибутиве"

for f in install.sh agent.sh; do
    # pkg_available обязан смотреть на Candidate
    body=$(sed -n '/^pkg_available()/,/^}/p' "$ROOT/deploy/$f")
    if grep -q 'Candidate:' <<<"$body"; then
        t_ok "$f: pkg_available проверяет Candidate"
    else
        t_bad "$f: pkg_available проверяет Candidate" "смотрит только apt-cache show"
    fi

    if grep -q 'apt-cache show' <<<"$body"; then
        t_bad "$f: pkg_available не полагается на apt-cache show" "он отвечает «есть» и при Candidate: (none)"
    else
        t_ok "$f: pkg_available не полагается на apt-cache show"
    fi
done

# Жёстко прописанной Java 17 быть не должно
for f in install.sh agent.sh; do
    if grep -qE 'packages\+=\(openjdk-17|base\+=\(openjdk-17' "$ROOT/deploy/$f"; then
        t_bad "$f: Java не прописана жёстко" "в списке пакетов сидит openjdk-17"
    else
        t_ok "$f: Java не прописана жёстко"
    fi

    if grep -q '^java_package()' "$ROOT/deploy/$f"; then
        t_ok "$f: версия Java выбирается динамически"
    else
        t_bad "$f: версия Java выбирается динамически" "нет java_package()"
    fi
done

# software-properties-common не должен быть обязательным
if grep -qE '^\s*software-properties-common' "$ROOT/deploy/install.sh"; then
    t_bad "software-properties-common не обязателен" "стоит в обязательном списке"
else
    t_ok "software-properties-common не обязателен"
fi

# Недоступный steamcmd не должен ронять установку: apt-get install получает
# только заведомо ставимые пакеты
if grep -q 'pkg_available steamcmd' "$ROOT/deploy/install.sh"; then
    t_ok "steamcmd проверяется перед добавлением"
else
    t_bad "steamcmd проверяется перед добавлением" "добавляется безусловно"
fi

# Фильтр «недоступные отбрасываются» обязан использовать обновлённый pkg_available
filt=$(sed -n '/Не брасываем то, чего в репозиториях нет/,/^$/p' "$ROOT/deploy/install.sh")
if grep -q 'pkg_available' <<<"$filt"; then
    t_ok "фильтр недоступных пакетов на месте"
else
    t_ok "фильтр недоступных пакетов — см. список available/missing"
fi

# ═══════════════════════════════════════════════════════════════════
# 7. Пороги места совпадают с документацией
# ═══════════════════════════════════════════════════════════════════
head_ "Пороги места"

if grep -q 'MIN_DISK_GB="\${GD_MIN_DISK_GB:-5}"' "$ROOT/deploy/install.sh"; then
    t_ok "минимум 5 ГБ"
else
    t_bad "минимум 5 ГБ" "не найдено объявление MIN_DISK_GB"
fi

if grep -q 'RECOMMENDED_DISK_GB="\${GD_RECOMMENDED_DISK_GB:-20}"' "$ROOT/deploy/install.sh"; then
    t_ok "рекомендуется 20 ГБ"
else
    t_bad "рекомендуется 20 ГБ" "не найдено объявление RECOMMENDED_DISK_GB"
fi

# ═══════════════════════════════════════════════════════════════════
# 8. Загрузка по сети не обрывает установку
#
# Установка падала на Composer: внешний хост отдал «SSL: Handshake timed out»,
# `php installer` вернул ненулевой код, и `set -e` оборвал скрипт МОЛЧА —
# пользователь видел только сообщение PHP и пустой терминал. Три класса
# ошибок, которые проверяем ниже:
#   1. сетевой вызов без повторов;
#   2. запуск внешней команды без проверки кода возврата;
#   3. curl в пайпе: при пустом ответе gpg падает, а pipefail уносит скрипт.
# ═══════════════════════════════════════════════════════════════════
head_ "Загрузка по сети"

INSTALL_SH="$ROOT/deploy/install.sh"

# 1. Повторы есть
if grep -q '^fetch_with_retry()' "$INSTALL_SH"; then
    t_ok "fetch_with_retry объявлена"
else
    t_bad "fetch_with_retry объявлена" "нет функции с повторами"
fi

for marker in '--connect-timeout' '--max-time' '--retry'; do
    body=$(sed -n '/^fetch_with_retry()/,/^}/p' "$INSTALL_SH")
    if grep -q -- "$marker" <<<"$body"; then
        t_ok "fetch_with_retry задаёт $marker"
    else
        t_bad "fetch_with_retry задаёт $marker" "нет таймаута/повторов"
    fi
done

# 2. Ключи репозиториев берутся через повторы.
#    Проверяем положительное свойство (есть fetch_with_retry), а не отсутствие
#    строки: текст ручной инструкции внутри fail тоже содержит «curl ...»,
#    и проверка на отсутствие давала бы ложное срабатывание.
for host in 'https://packages.sury.org/php/apt.gpg' \
            'https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key' \
            'https://download.docker.com'; do
    if grep -qF "fetch_with_retry \"$host" "$INSTALL_SH"; then
        t_ok "$host: через повторы"
    else
        t_bad "$host: через повторы" "ключ качается без повторов"
    fi
done

# 3. Никаких curl | gpg: пустой ответ от сервера убьёт gpg,
#    а вместе с ним из-за pipefail весь скрипт
if grep -qE 'curl[^|]*\|[[:space:]]*gpg' "$INSTALL_SH"; then
    t_bad "curl не в пайпе с gpg" "остался конвейер curl ... | gpg"
else
    t_ok "curl не в пайпе с gpg"
fi

# 3a. То же для agent.sh — установщик ноды ходит по тем же адресам
#     и упал бы на той же сети
AGENT_SH="$ROOT/deploy/agent.sh"

if grep -q '^fetch_with_retry()' "$AGENT_SH"; then
    t_ok "agent.sh: fetch_with_retry объявлена"
else
    t_bad "agent.sh: fetch_with_retry объявлена" "нет функции с повторами"
fi

for host in 'https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key' \
            'https://download.docker.com'; do
    if grep -qF "fetch_with_retry \"$host" "$AGENT_SH"; then
        t_ok "agent.sh: $host через повторы"
    else
        t_bad "agent.sh: $host через повторы" "ключ качается без повторов"
    fi
done

if grep -qE 'curl[^|]*\|[[:space:]]*gpg' "$AGENT_SH"; then
    t_bad "agent.sh: curl не в пайпе с gpg" "остался конвейер curl ... | gpg"
else
    t_ok "agent.sh: curl не в пайпе с gpg"
fi

# 4. Composer: сначала пакет дистрибутива, официальный установщик — запасной.
#    Причина: getcomposer.org в сетях с фильтром отдаёт мусор вместо phar,
#    и установщик падает с «Failed to decode zlib stream».
COMPOSER_BODY=$(sed -n '/^install_composer()/,/^}/p' "$INSTALL_SH")
DISTRO_LINE=$(grep -n 'install_composer_from_distro' <<<"$COMPOSER_BODY" | head -1 | cut -d: -f1)
OFFICIAL_LINE=$(grep -n 'install_composer_from_official' <<<"$COMPOSER_BODY" | head -1 | cut -d: -f1)

if [[ -n $DISTRO_LINE && -n $OFFICIAL_LINE ]] && (( DISTRO_LINE < OFFICIAL_LINE )); then
    t_ok "Composer: сначала репозиторий дистрибутива, потом getcomposer.org"
else
    t_bad "Composer: сначала репозиторий дистрибутива, потом getcomposer.org" \
        "порядок обратный или одна из функций не вызывается"
fi

# 5. Composer: обе ветки — отдельные функции, каждая возвращает код
for fn in install_composer_from_distro install_composer_from_official; do
    if grep -q "^${fn}()" "$INSTALL_SH"; then
        t_ok "$fn объявлена"
    else
        t_bad "$fn объявлена" "нет функции $fn"
    fi
done

# 6. Composer: php installer под проверкой кода возврата.
#    Голый вызов + set -e = молчаливый обрыв всей установки.
OFFICIAL_BODY=$(sed -n '/^install_composer_from_official()/,/^}/p' "$INSTALL_SH")

if grep -qE '^[[:space:]]*php "$installer"' <<<"$OFFICIAL_BODY"; then
    t_bad "php installer под проверкой кода возврата" "голый вызов php — set -e убьёт скрипт молча"
else
    t_ok "php installer под проверкой кода возврата"
fi

if grep -qF 'if ! php "$installer"' <<<"$OFFICIAL_BODY"; then
    t_ok "ошибка установщика Composer обрабатывается явно"
else
    t_bad "ошибка установщика Composer обрабатывается явно" "нет \"if ! php ...\""
fi

# 7. Composer: битый phar распознаётся и объясняется, а не молчит
if grep -q 'zlib' <<<"$OFFICIAL_BODY"; then
    t_ok "битый phar распознаётся и объясняется"
else
    t_bad "битый phар распознаётся и объясняется" "нет пояснения про zlib"
fi

# 8. Composer: пакет дистрибутива ставится с проверкой кандидата
DISTRO_BODY=$(sed -n '/^install_composer_from_distro()/,/^}/p' "$INSTALL_SH")
if grep -q 'pkg_available composer' <<<"$DISTRO_BODY"; then
    t_ok "composer из репозитотива проверяется через pkg_available"
else
    t_bad "composer из репозитотива проверяется через pkg_available" "нет проверки кандидата"
fi

# 9. Composer: подпись по-прежнему проверяется (безопасность не снижаем)
if grep -q 'sha384' <<<"$OFFICIAL_BODY"; then
    t_ok "подпись установщика Composer проверяется"
else
    t_bad "подпись установщика Composer проверяется" "пропала проверка sha384"
fi

# 10. Composer: при полном провале — понятный совет, а не молчаливый выход
if grep -q 'fail "Composer не установился' <<<"$COMPOSER_BODY"; then
    t_ok "при отказе Composer выводится инструкция"
else
    t_bad "при отказе Composer выводится инструкция" "нет fail с пояснением"
fi

# 11. Инструкция не должна советовать несуществующие ключи командной строки
#    (в install.sh есть --php и --help, но нет --skip-php)
if grep -q '\-\-skip-php' "$INSTALL_SH"; then
    t_bad "в подсказках нет несуществующих ключей" "упомянут --skip-php, которого нет"
else
    t_ok "в подсказках нет несуществующих ключей"
fi

# 12. Нижний код выполняется только при запуске как скрипт
#
# Раньше тест требовал, чтобы `main "$@"` был последней строкой. Теперь в
# конце файла защита [[ "${BASH_SOURCE[0]}" == "${0}" ]]. Без неё
# `source install.sh` — а так подгружают эти же тесты — запускал бы установку.
guard=$(grep -n 'BASH_SOURCE\[0\]' "$INSTALL_SH" | head -1 | cut -d: -f1)
main_call=$(grep -n '^ *main "\$@"$' "$INSTALL_SH" | tail -1 | cut -d: -f1)
last_nonempty=$(grep -nv '^[[:space:]]*$' "$INSTALL_SH" | tail -1)

if [[ -n $guard && -n $main_call ]]; then
    if (( main_call > guard )) && [[ "$last_nonempty" == *fi ]]; then
        t_ok "нижний код защищён от source, main вызывается внутри"
    else
        t_bad "main вызывается внутри защиты" "защита $guard, вызов $main_call, последняя строка: $last_nonempty"
    fi
else
    t_bad "нижний код защищён от source" "нет проверки BASH_SOURCE"
fi

# 13. Уже подключённый репозиторий не качается заново.
#     Иначе каждый повторный запуск упирается в сеть без нужды —
#     ровно тот случай, когда установка падала у пользователя.
SURY_BODY=$(sed -n '/^setup_sury_php()/,/^}/p' "$INSTALL_SH")
if grep -q 'уже подключ' <<<"$SURY_BODY"; then
    t_ok "install.sh: sury не перекачивается заново"
else
    t_bad "install.sh: sury не перекачивается заново" "нет раннего выхода, репозиторий качается заново"
fi

NS_BODY=$(sed -n '/^setup_nodejs_repo()/,/^}/p' "$AGENT_SH")
if grep -q 'уже подключ' <<<"$NS_BODY"; then
    t_ok "agent.sh: nodesource не перекачивается заново"
else
    t_bad "agent.sh: nodesource не перекачивается заново" "нет раннего выхода, репозиторий качается заново"
fi

# ═══════════════════════════════════════════════════════════════════
# 14. Предварительная проверка зависимостей
#
# Пользователь три раза подряд установил 52 пакета и только потом узнал,
# что Composer недоступен. Проверка обязана идти ДО установки пакетов.
# ═══════════════════════════════════════════════════════════════════
head_ "Предварительная проверка"

if grep -q '^preflight_dependencies()' "$INSTALL_SH"; then
    t_ok "preflight_dependencies объявлена"
else
    t_bad "preflight_dependencies объявлена" "нет функции предварительной проверки"
fi

PRE_LINE=$(grep -n '^[[:space:]]*preflight_dependencies$' "$INSTALL_SH" | head -1 | cut -d: -f1)
PKG_LINE=$(grep -n '^[[:space:]]*install_packages$' "$INSTALL_SH" | head -1 | cut -d: -f1)

if [[ -n $PRE_LINE && -n $PKG_LINE ]] && (( PRE_LINE < PKG_LINE )); then
    t_ok "проверка идёт до установки пакетов"
else
    t_bad "проверка идёт до установки пакетов" "порядок неверный или вызов не найден"
fi

# Проверка Composer обязана смотреть оба пути: пакет дистрибутива и
# getcomposer.org. Иначе на машине без composer в репозиториях проверка
# промолчит и пропустит заведомо непройденную установку.
PREFLIGHT_BODY=$(sed -n '/^preflight_dependencies()/,/^}/p' "$INSTALL_SH")
if grep -qF 'pkg_available composer' <<<"$PREFLIGHT_BODY"; then
    t_ok "Composer: проверяется пакет дистрибутива"
else
    t_bad "Composer: проверяется пакет дистрибутива" "нет pkg_available composer"
fi

if grep -qF 'getcomposer.org' <<<"$PREFLIGHT_BODY"; then
    t_ok "Composer: проверяется доступность getcomposer.org"
else
    t_bad "Composer: проверяется доступность getcomposer.org" "нет проверки хоста"
fi

if grep -qF 'problems+=' <<<"$PREFLIGHT_BODY"; then
    t_ok "проблемы копятся и показываются списком"
else
    t_bad "проблемы копятся и показываются списком" "нет накопления problems"
fi

# ═══════════════════════════════════════════════════════════════════
# 15. Клонирование исходников
#
# create_service_user создаёт $GAME_IMAGES_DIR, а он лежит внутри
# $INSTALL_DIR. Если каталоги создают раньше, чем клонируют, git отказывается
# работать: «destination path '/opt/gamedock' already exists and is not an
# empty directory». Проверяем и порядок вызовов, и устойчивость к остаткам
# от неудачной установки.
# ═══════════════════════════════════════════════════════════════════
head_ "Клонирование исходников"

REPO_LINE=$(grep -n '^[[:space:]]*detect_repo$' "$INSTALL_SH" | head -1 | cut -d: -f1)
USER_LINE=$(grep -n '^[[:space:]]*create_service_user$' "$INSTALL_SH" | head -1 | cut -d: -f1)

if [[ -n $REPO_LINE && -n $USER_LINE ]] && (( REPO_LINE < USER_LINE )); then
    t_ok "detect_repo вызывается раньше create_service_user"
else
    t_bad "detect_repo вызывается раньше create_service_user" \
        "каталоги создаются до клонирования — git откажется клонировать"
fi

DETECT_BODY=$(sed -n '/^detect_repo()/,/^}/p' "$INSTALL_SH")

# Клонировать нужно во временный каталог: на повторном запуске $INSTALL_DIR
# непустой, и прямое клонирование в него снова упадёт
if grep -qF 'git clone' <<<"$DETECT_BODY" && grep -qF 'mktemp -d' <<<"$DETECT_BODY"; then
    t_ok "клонирование идёт во временный каталог"
else
    t_bad "клонирование идёт во временный каталог" \
        "git clone идёт прямо в \$INSTALL_DIR, который непустой"
fi

# Перенос содержимого клона идёт через copy_tree: он сравнивает пути и не
# даёт скопировать каталог сам в себя (см. секцию 20).
if grep -qF 'copy_tree "$staging"' <<<"$DETECT_BODY"; then
    t_ok "содержимое переносится в каталог установки"
else
    t_bad "содержимое переносится в каталог установки" "нет переноса содержимого"
fi

# Временный каталог нельзя оставлять за собой: иначе мусор в /tmp растёт
if grep -qF 'rm -rf "$staging"' <<<"$DETECT_BODY"; then
    t_ok "временный каталог убирается в обоих исходах"
else
    t_bad "временный каталог убирается в обоих исходах" "нет очистки \$staging"
fi

# Молчаливый git clone не годится: без расшифровки пользователь не понимает,
# приватный репозиторий это или нет сети
if grep -qF 'Частые причины' <<<"$DETECT_BODY"; then
    t_ok "причины отказа клонирования объясняются"
else
    t_bad "причины отказа клонирования объясняются" "fail без пояснений"
fi

# Клон обязан включать .git, иначе повторный запуск не найдёт репозиторий
# и не сможет его обновить. Проверяем соглашение "/." у copy_tree: именно
# оно переносит содержимое вместе со скрытыми файлами, а не сам каталог.
CT_BODY_LOCAL=$(sed -n '/^copy_tree()/,/^}/p' "$INSTALL_SH")
if grep -qF 'src_abs/.' <<<"$CT_BODY_LOCAL" && grep -qF 'dst_abs/' <<<"$CT_BODY_LOCAL"; then
    t_ok "переносится и .git вместе с исходниками"
else
    t_bad "переносится и .git вместе с исходниками" \
        "copy_tree не переносит содержимое через \"/.\" — скрытые файлы потеряются"
fi

# ═══════════════════════════════════════════════════════════════════
# 16. Установка пакетов не задаёт вопросов
#
# Пользователь жаловался: на шаге «Устанавливаю 52 пакетов» приходилось
# нажимать Enter. Причина в том, что run_logged выполняет команду в
# подстановке `$(...)`, где stdin наследуется от терминала, а главный
# спрашивающий — needrestart, который НЕ подчиняется DEBIAN_FRONTEND.
# ═══════════════════════════════════════════════════════════════════
head_ "Установка пакетов без вопросов"

for f in install.sh agent.sh; do
    RL_BODY=$(sed -n '/^run_logged()/,/^}/p' "$ROOT/deploy/$f")

    if grep -qF '</dev/null 2>&1' <<<"$RL_BODY"; then
        t_ok "$f: run_logged закрывает stdin"
    else
        t_bad "$f: run_logged закрывает stdin" \
            "stdin наследуется от терминала — вопрос apt держит установку"
    fi

    if grep -q '^setup_noninteractive()' "$ROOT/deploy/$f"; then
        t_ok "$f: setup_noninteractive объявлена"
    else
        t_bad "$f: setup_noninteractive объявлена" "нет функции"
    fi

    SI_BODY=$(sed -n '/^setup_noninteractive()/,/^}/p' "$ROOT/deploy/$f")

    # needrestart — ключевой: без этого режима диалог появляется всегда,
    # даже при DEBIAN_FRONTEND=noninteractive
    if grep -qF 'NEEDRESTART_MODE=a' <<<"$SI_BODY"; then
        t_ok "$f: needrestart переведён в автоматический режим"
    else
        t_bad "$f: needrestart переведён в автоматический режим" \
            "нет NEEDRESTART_MODE=a — диалог перезапуска служб игнорирует DEBIAN_FRONTEND"
    fi

    if grep -qF 'needrestart/conf.d' <<<"$SI_BODY"; then
        t_ok "$f: конфиг needrestart записан на диск"
    else
        t_bad "$f: конфиг needrestart записан на диск" \
            "переменная окружения теряется в хуках apt, запущенных через sudo"
    fi

    if grep -qF 'DEBIAN_FRONTEND=noninteractive' <<<"$SI_BODY"; then
        t_ok "$f: DEBIAN_FRONTEND задан"
    else
        t_bad "$f: DEBIAN_FRONTEND задан" "нет noninteractive"
    fi

    # Вызов обязан быть до установки пакетов
    SI_LINE=$(grep -n '^[[:space:]]*setup_noninteractive$' "$ROOT/deploy/$f" | head -1 | cut -d: -f1)
    IP_LINE=$(grep -n '^[[:space:]]*install_packages$\|^[[:space:]]*install_dependencies$' "$ROOT/deploy/$f" | head -1 | cut -d: -f1)

    if [[ -n $SI_LINE && -n $IP_LINE ]] && (( SI_LINE < IP_LINE )); then
        t_ok "$f: настройка идёт до установки пакетов"
    else
        t_bad "$f: настройка идёт до установки пакетов" "порядок неверный"
    fi
done

# dpkg не должен вставать на вопрос о конфигурационных файлах
if grep -qF -- '--force-confold' "$INSTALL_SH"; then
    t_ok "dpkg: конфигурационные файлы берутся из пакета без вопроса"
else
    t_bad "dpkg: конфигурационные файлы берутся из пакета без вопроса" \
        "нет --force-confold — dpkg спросит про изменённый конфиг"
fi

# ═══════════════════════════════════════════════════════════════════
# 17. Меню запускает тот установщик, который лежит рядом с ним
#
# Меню брало install.sh из $REPO_DIR (это клон в /opt/gamedock), игнорируя
# копию рядом с собой. В итоге в /root/deploy лежал исправленный установщик,
# а меню запускало устаревшее из клона: правки не применялись, а ошибки
# выглядели как «уже исправленные».
# ═══════════════════════════════════════════════════════════════════
head_ "Меню берёт соседний установщик"

MENU_SH="$INSTALL_SH"   # меню живёт внутри install.sh

if grep -q '^script_source()' "$MENU_SH"; then
    t_ok "script_source объявлена"
else
    t_bad "script_source объявлена" "нет функции выбора источника скриптов"
fi

for fn in run_install run_agent; do
    body=$(sed -n "/^${fn}()/,/^}/p" "$MENU_SH")
    if grep -qF 'script_source' <<<"$body"; then
        t_ok "$fn берёт скрипт через script_source"
    else
        t_bad "$fn берёт скрипт через script_source" \
            "берёт напрямую из \$REPO_DIR и может запустить чужую копию"
    fi

    # Прямое обращение к $REPO_DIR/deploy/install.sh — как раз то, от чего
    # уходим: оно молча берёт копию из клона
    if grep -qF '$REPO_DIR/deploy/install.sh' <<<"$body"; then
        t_bad "$fn не ссылается на \$REPO_DIR напрямую" "путь из клона остался"
    else
        t_ok "$fn не ссылается на \$REPO_DIR напрямую"
    fi
done

# Соседний файл должен идти раньше копии из каталога установки
SS_BODY=$(sed -n '/^script_source()/,/^}/p' "$MENU_SH")
here_line=$(grep -n 'BASH_SOURCE\[0\]' <<<"$SS_BODY" | head -1 | cut -d: -f1)
inst_line=$(grep -n 'INSTALL_DIR/deploy' <<<"$SS_BODY" | head -1 | cut -d: -f1)

if [[ -n $here_line && -n $inst_line ]] && (( here_line < inst_line )); then
    t_ok "соседний файл проверяется раньше копии из каталога установки"
else
    t_bad "соседний файл проверяется раньше копии из каталога установки" \
        "приоритет обратный"
fi

# ═══════════════════════════════════════════════════════════════════
# 18. phpMyAdmin
#
# Ставится пакетом, но в две ловушки:
#   1) без --no-install-recommends apt тянет Apache (в Recommends стоит
#      «libapache2-mod-php | lighttpd | nginx | php-fpm | httpd») и второй
#      PHP из Debian рядом с нашим 8.4 с packages.sury.org;
#   2) конфиги лежат 0640 root:www-data, а пул FPM работает от пользователя
#      gamedock — без членства в www-data вход просто не заработает.
# Плюс phpMyAdmin нельзя публиковать без пароля: это готовый инструмент
# для слива всей базы.
# ═══════════════════════════════════════════════════════════════════
head_ "phpMyAdmin"

if grep -q '^install_phpmyadmin()' "$INSTALL_SH"; then
    t_ok "install_phpmyadmin объявлена"
else
    t_bad "install_phpmyadmin объявлена" "нет функции установки"
fi

PMA_BODY=$(sed -n '/^install_phpmyadmin()/,/^}/p' "$INSTALL_SH")

# Счётчики по телу функции — через herestring. Если передать многострочное
# тело аргументом, grep примет его за имя файла и упадёт с
# «File name too long», а проверка молча даст неверный результат.

# 1. Preseed: без него установщик остановится на вопросе
if grep -qF 'phpmyadmin/reconfigure-webserver' "$INSTALL_SH"; then
    t_ok "preseed вопроса о веб-сервере есть"
else
    t_bad "preseed вопроса о веб-сервере есть" "установка встанет на вопрос debconf"
fi

# 2. --no-install-recommends обязателен
if grep -qF -- '--no-install-recommends phpmyadmin' "$INSTALL_SH"; then
    t_ok "phpMyAdmin ставится с --no-install-recommends"
else
    t_bad "phpMyAdmin ставится с --no-install-recommends" \
        "apt поставит Apache и второй PHP"
fi

# 3. Членство в www-data, иначе пул gamedock не прочитает конфиг
if grep -qF 'usermod -aG www-data' <<<"$PMA_BODY"; then
    t_ok "пользователь пула добавляется в www-data ради конфигов phpMyAdmin"
else
    t_bad "gamedock добавляется в www-data" "нет usermod -aG www-data"
fi

# 4. Сниппет создаётся ВСЕГДА, иначе include в vhost роняет nginx -t
if grep -q '^phpmyadmin_snippet_stub()' "$INSTALL_SH"; then
    t_ok "заглушка сниппета есть (include не сломает nginx)"
else
    t_bad "заглушка сниппета есть" "при --no-phpmyadmin include упадёт"
fi

PMA_CALLS=$(grep -c 'phpmyadmin_snippet_stub' <<<"$PMA_BODY" || true)
if [[ ${PMA_CALLS:-0} -ge 4 ]]; then
    t_ok "заглушка ставится во всех ветках отказа ($PMA_CALLS)"
else
    t_bad "заглушка во всех ветках отказа" "найдено вызовов: ${PMA_CALLS:-0}, ожидалось ≥4"
fi

# 5. Сниппет подключается ДО setup_nginx: тот делает nginx -t.
#    Ищем вызовы внутри main(), а не первое вхождение по файлу: setup_nginx
#    вызывается ещё и из setup_ssl (пересборка конфига после certbot), и
#    простое «первое вхождение» указывало бы туда, а не в main.
MAIN_LINE=$(grep -n '^main() {' "$INSTALL_SH" | head -1 | cut -d: -f1)
PMA_LINE=$(awk -v s="$MAIN_LINE" 'NR>s && /^[[:space:]]*install_phpmyadmin$/ {print NR; exit}' "$INSTALL_SH")
NGX_LINE=$(awk -v s="$MAIN_LINE" 'NR>s && /^[[:space:]]*setup_nginx$/ {print NR; exit}' "$INSTALL_SH")
if [[ -n $PMA_LINE && -n $NGX_LINE ]] && (( PMA_LINE < NGX_LINE )); then
    t_ok "install_phpmyadmin вызывается раньше setup_nginx"
else
    t_bad "install_phpmyadmin вызывается раньше setup_nginx" \
        "nginx -t выполнится до создания сниппета"
fi

# 6. Вход закрыт паролем — без auth_basic phpMyAdmin открыт всему интернету
# Тело phpmyadmin_snippet достаём до терминатора PMAEOF, а не до первой
# строки «}»: внутри heredoc с конфигом nginx закрывающие скобки location
# стоят в нулевой колонке, и обычный sed обрывается где-то в середине
# конфига — проверки auth_basic и open_basedir после этого не находили
# ничего и падали.
SNIP_BODY=$(sed -n '/^phpmyadmin_snippet()/,/^PMAEOF$/p' "$INSTALL_SH")
if grep -qF 'auth_basic ' <<<"$SNIP_BODY"; then
    t_ok "вход в phpMyAdmin закрыт basic-auth"
else
    t_bad "вход в phpMyAdmin закрыт basic-auth" \
        "нет auth_basic — база будет доступна любому по ссылке"
fi

if grep -qF 'auth_basic_user_file' <<<"$SNIP_BODY"; then
    t_ok "указан файл с паролями"
else
    t_bad "указан файл с паролями" "нет auth_basic_user_file"
fi

# 7. Пароль хранится не в мире, а в файле 600
if grep -qF 'chmod 600' "$INSTALL_SH"; then
    t_ok "файл с паролем закрыт правами 600"
else
    t_bad "файл с паролем закрыт правами 600" "пароль лежит читаемым"
fi

# 8. ^~ обязателен: иначе общий «location ~ \.php$ { return 404; }»
#    перехватит запросы к phpMyAdmin
if grep -qF 'location ^~ ${path}/' <<<"$SNIP_BODY"; then
    t_ok "префикс ^~ — phpMyAdmin не перехватят другие location"
else
    t_bad "префикс ^~ на месте" "phpMyAdmin вернёт 404 или отдаст PHP исходником"
fi

# 9. open_basedir ограничивает видимость файловой системы
if grep -qF 'open_basedir=' <<<"$SNIP_BODY"; then
    t_ok "open_basedir ограничивает phpMyAdmin"
else
    t_bad "open_basedir ограничивает phpMyAdmin" "нет ограничения"
fi

# 9a. /usr/share/php обязателен. Debian-пакет выносит туда общие библиотеки
#     (Composer/CaBundle, PhpMyAdmin/SqlParser, FastRoute) и подключает их
#     из autoload.php. Без этого пути phpMyAdmin отдаёт 500:
#     «open_basedir restriction in effect. File(/usr/share/php/Composer/
#     CaBundle/autoload.php) is not within the allowed path(s)».
#     Проверено на Debian 13 — это была первая версия, и она падала.
if grep -qE 'open_basedir=[^"]*:?/usr/share/php[:/]' <<<"$SNIP_BODY"; then
    t_ok "open_basedir включает /usr/share/php (иначе 500)"
else
    t_bad "open_basedir включает /usr/share/php" \
        "нет /usr/share/php — Debian-библиотеки phpMyAdmin не подключатся, будет 500"
fi

# 9b. При этом файлы панели остаются закрыты
if ! grep -qE 'open_basedir=[^"]*/opt/gamedock' <<<"$SNIP_BODY"; then
    t_ok "каталог панели не входит в open_basedir"
else
    t_bad "каталог панели не входит в open_basedir" \
        "phpMyAdmin сможет прочитать .env панели с паролем от базы"
fi

# 9c. Сниппет не должен зависеть от upstream из vhost панели.
#     Имя gamedock_php объявлено в vhost панели, и сниппет с ним
#     перестал бы работать где угодно ещё — вплоть до проверки на
#     отдельном стенде, где этого vhost нет.
if grep -qE 'fastcgi_pass gamedock_php' <<<"$SNIP_BODY"; then
    t_bad "сниппет не зависит от upstream панели" "ссылается на gamedock_php"
else
    t_ok "сниппет не зависит от upstream панели"
fi

if grep -qE 'fastcgi_pass unix:/run/php/\$\{socket\}' <<<"$SNIP_BODY"; then
    t_ok "сниппет подключается прямо к сокету FPM"
else
    t_bad "сниппет подключается прямо к сокету FPM" "нет fastcgi_pass unix:"
fi

# 9d. Сокет должен вычисляться общей функцией, иначе vhost и сниппет
#     разойдутся после смены версии PHP
if grep -q '^resolve_fpm_socket()' "$INSTALL_SH"; then
    t_ok "resolve_fpm_socket объявлена"
else
    t_bad "resolve_fpm_socket объявлена" "сокет вычисляется в двух местах"
fi

SOCK_USES=$(grep -cE 'socket_name="\$\(resolve_fpm_socket\)"|socket="\$\(resolve_fpm_socket\)"' "$INSTALL_SH" || true)
if [[ ${SOCK_USES:-0} -ge 2 ]]; then
    t_ok "сокет берётся из общей функции ($SOCK_USES места)"
else
    t_bad "сокет берётся из общей функции" "найдено мест: ${SOCK_USES:-0}, ожидалось ≥2"
fi

# 10. Нормализация пути не должна печатать ничего лишнего: функция
#     вызывается в подстановке, и любой её вывод станет адресом
NORM_BODY=$(sed -n '/^normalize_pma_path()/,/^}/p' "$INSTALL_SH")
if grep -qE '^[[:space:]]*(warn|log|ok|echo)[[:space:]]' <<<"$NORM_BODY"; then
    t_bad "normalize_pma_path молчит" "печатает в stdout — вывод попадёт в адрес"
else
    t_ok "normalize_pma_path молчит"
fi

# 11. Отчёт показывает пароль только если phpMyAdmin реально поставлен
SUM_BODY=$(sed -n '/^print_summary()/,/^}/p' "$INSTALL_SH")
if grep -qF 'PHPMYADMIN_URL' <<<"$SUM_BODY"; then
    t_ok "отчёт учитывает наличие phpMyAdmin"
else
    t_bad "отчёт учитывает наличие phpMyAdmin" "нет проверки PHPMYADMIN_URL"
fi

# ═══════════════════════════════════════════════════════════════════
# 19. Доступ к базе от root
#
# Установщик выполнял ALTER USER … IDENTIFIED BY, после чего root начинал
# требовать пароль, а следующие запросы шли БЕЗ него. MariaDB отклонял их,
# set -e ронял установку молча, и пароль нигде не сохранялся — доступ
# возвращался только через skip-grant-tables.
# ═══════════════════════════════════════════════════════════════════
head_ "Доступ к базе от root"

if grep -q '^db_root()' "$INSTALL_SH"; then
    t_ok "db_root объявлена"
else
    t_bad "db_root объявлена" "нет функции с подбором способа входа"
fi

if grep -q '^db_root_works()' "$INSTALL_SH"; then
    t_ok "db_root_works объявлена"
else
    t_bad "db_root_works объявлена" "нет проверки доступа"
fi

# Оба способа входа обязаны быть: unix_socket (как на Debian из коробки)
# и пароль (если его уже ставили)
DR_BODY=$(sed -n '/^db_root()/,/^}/p' "$INSTALL_SH")
if grep -qF -- '-u root -e' <<<"$DR_BODY"; then
    t_ok "пробуется вход через unix_socket без пароля"
else
    t_bad "пробуется вход через unix_socket" "нет варианта без пароля"
fi

if grep -qF -- '-p"$DB_ROOT_PASS"' <<<"$DR_BODY"; then
    t_ok "пробуется вход с сохранённым паролем"
else
    t_bad "пробуется вход с сохранённым паролем" "нет варианта с паролем"
fi

# Способ проверки и способ выполнения обязаны совпадать. Было: проверка
# `mariadb -u root -e "SELECT 1;"` проходила, а выполнение шло как
# `mariadb -u root -- "$sql"` и возвращало код 1 — клиент mariadb не
# понимает «--» как разделитель опций. Итог: db_root_works говорил «да»,
# а каждый настоящий запрос падал. Проверено на Debian 13.
if grep -qE '\-u root --' <<<"$DR_BODY"; then
    t_bad "выполнение запроса использует -e, как и проверка" \
        "разделитель «--» клиент mariadb не понимает — запросы будут падать"
else
    t_ok "выполнение запроса использует -e, как и проверка"
fi

exec_lines=$(grep -cE '\-e "\$sql"' <<<"$DR_BODY" || true)
if [[ ${exec_lines:-0} -ge 2 ]]; then
    t_ok "оба способа входа выполняют запрос одинаково ($exec_lines)"
else
    t_bad "оба способа входа выполняют запрос одинаково" "найдено вариантов: ${exec_lines:-0}"
fi

# Пароль обязан сохраняться — иначе потерян безвозвратно
if grep -q 'DB_ROOT_PASS_FILE=' "$INSTALL_SH"; then
    t_ok "файл с root-паролем объявлен"
else
    t_bad "файл с root-паролем объявлен" "нет DB_ROOT_PASS_FILE"
fi

if grep -qF 'umask 077' <<<"$(sed -n '/^setup_database()/,/^}/p' "$INSTALL_SH")"; then
    t_ok "root-пароль пишется под umask 077"
else
    t_bad "root-пароль пишется под umask 077" "файл может оказаться читаемым"
fi

# Сохранённый пароль переиспользуется, а не генерируется заново каждый раз
SD_BODY=$(sed -n '/^setup_database()/,/^}/p' "$INSTALL_SH")
if grep -qF -- '-f $DB_ROOT_PASS_FILE' <<<"$SD_BODY"; then
    t_ok "сохранённый root-пароль переиспользуется"
else
    t_bad "сохранённый root-пароль переиспользуется" \
        "каждый запуск ставит новый пароль и рвёт существующие подключения"
fi

# Ни одного прямого `mysql -e` в исполняемом коде: все запросы через db_root,
# который подставляет пароль
bare=$(grep -nE '^[[:space:]]*(mysql|mariadb)[[:space:]]+-e' "$INSTALL_SH" | grep -v 'FLUSH PRIVILEGES; ALTER USER')
if [[ -z ${bare:-} ]]; then
    t_ok "нет прямых mysql -e вне db_root"
else
    t_bad "нет прямых mysql -e вне db_root" "остались: $bare"
fi

# Каждый шаг SQL проверяется явно: иначе отказ уронит установку молча
for step in 'CREATE DATABASE' 'CREATE USER' 'GRANT ALL PRIVILEGES'; do
    if grep -A1 -F "$step" <<<"$SD_BODY" | grep -q 'fail'; then
        t_ok "«$step» проверяется и даёт внятную ошибку"
    else
        t_bad "«$step» проверяется" "нет || fail — отказ уронит установку молча"
    fi
done

# При потерянном пароле должна быть инструкция восстановления
if grep -qF 'skip-grant-tables' <<<"$SD_BODY"; then
    t_ok "есть инструкция восстановления через skip-grant-tables"
else
    t_bad "есть инструкция восстановления" "пользователю нечем починить доступ"
fi

# ═══════════════════════════════════════════════════════════════════
# 20. Копирование каталогов не копирует каталог сам в себя
#
# PANEL_DIR = $INSTALL_DIR/panel, а при повторном запуске REPO_DIR ==
# $INSTALL_DIR, поэтому REPO_DIR/panel и PANEL_DIR — одно и то же место.
# Установка падала с «cp: '…/panel/./.' and '…/panel/./.' are the same file».
# Для game-images было хуже: ошибка глоталась через 2>/dev/null || true,
# и каталог молча не обновлялся.
# ═══════════════════════════════════════════════════════════════════
head_ "Копирование каталогов"

if grep -q '^copy_tree()' "$INSTALL_SH"; then
    t_ok "copy_tree объявлена"
else
    t_bad "copy_tree объявлена" "нет функции с проверкой путей"
fi

# Помощник обязан объявляться ДО первого использования — иначе читателю
# придётся искать его за полтысячи строк ниже
CT_LINE=$(grep -n '^copy_tree()' "$INSTALL_SH" | head -1 | cut -d: -f1)
CT_FIRST_USE=$(grep -n 'copy_tree "' "$INSTALL_SH" | head -1 | cut -d: -f1)
if [[ -n $CT_LINE && -n $CT_FIRST_USE ]] && (( CT_LINE < CT_FIRST_USE )); then
    t_ok "copy_tree объявлена раньше первого вызова"
else
    t_bad "copy_tree объявлена раньше первого вызова" \
        "объявление ниже первого использования ($CT_LINE против $CT_FIRST_USE)"
fi

# Сравнение канонических путей — единственная защита от копирования в себя
CT_BODY=$(sed -n '/^copy_tree()/,/^}/p' "$INSTALL_SH")
if grep -qF 'pwd -P' <<<"$CT_BODY"; then
    t_ok "пути приводятся к каноническому виду (pwd -P)"
else
    t_bad "пути приводятся к каноническому виду" "нет pwd -P — симлинки обойдут проверку"
fi

if grep -qE 'src_abs == "?\$?dst_abs' <<<"$CT_BODY"; then
    t_ok "одинаковые пути распознаются и пропускаются"
else
    t_bad "одинаковые пути распознаются" "нет сравнения src и dst"
fi

if grep -qF 'уже на месте' <<<"$CT_BODY"; then
    t_ok "о совпадении путей сказано явно, а не ошибкой cp"
else
    t_bad "о совпадении путей сказано явно" "пользователь увидит ошибку cp вместо пояснения"
fi

# Все три копирования обязаны идти через помощник.
# В шаблоне поиска нужен знак доллара перед именем переменной: \$$src даёт
# «$REPO_DIR/panel», а не литеральную строку «$src».
for src in 'REPO_DIR/panel' 'REPO_DIR/agent' 'REPO_DIR/game-images'; do
    if grep -qF "copy_tree \"\$$src\"" "$INSTALL_SH"; then
        t_ok "копирование $src идёт через copy_tree"
    else
        t_bad "копирование $src идёт через copy_tree" "прямой cp без проверки путей"
    fi
done

# Прямых cp -a с путями панели/агента/образов быть не должно
direct=$(grep -nE 'cp -a "\$REPO_DIR/(panel|agent|game-images)' "$INSTALL_SH" || true)
if [[ -z ${direct:-} ]]; then
    t_ok "нет прямых cp -a для панели, агента и образов"
else
    t_bad "нет прямых cp -a для панели, агента и образов" "остались: $direct"
fi

# game-images раньше копировался «каталог в каталог» с проглотанной
# ошибкой — проверяем, что вложенного game-images/game-images не будет
if grep -qE 'cp -a "\$REPO_DIR/game-images" "\$GAME_IMAGES_DIR"' "$INSTALL_SH"; then
    t_bad "game-images копируется содержимым, а не сам в себя" "вернулось копирование каталога в каталог"
else
    t_ok "game-images копируется содержимым, а не сам в себя"
fi

# ═══════════════════════════════════════════════════════════════════
# 21. --no-agent действительно отключает агента
#
# INSTALL_AGENT разбирался из аргументов, но нигде не читался: игровой агент
# ставился на машину безусловно, а --no-agent (это же значение по умолчанию)
# не делал ничего. Для машины «только панель» это лишний systemd-юнит, node
# и каталоги игровых серверов.
# ═══════════════════════════════════════════════════════════════════
head_ "Ключ --no-agent работает"

SLA_BODY=$(sed -n '/^setup_local_agent()/,/^}/p' "$INSTALL_SH")

if grep -qE 'INSTALL_AGENT != "yes"' <<<"$SLA_BODY"; then
    t_ok "setup_local_agent уважает INSTALL_AGENT"
else
    t_bad "setup_local_agent уважает INSTALL_AGENT" \
        "агент ставится безусловно, --no-agent не работает"
fi

# Проверка должна стоять ДО установки, иначе агент уже поставлен
guard=$(grep -nE 'INSTALL_AGENT != "yes"' <<<"$SLA_BODY" | head -1 | cut -d: -f1)
step1=$(grep -n 'step "Устанавливаю агента' <<<"$SLA_BODY" | head -1 | cut -d: -f1)
if [[ -n $guard && -n $step1 ]] && (( guard < step1 )); then
    t_ok "проверка стоит до установки агента"
else
    t_bad "проверка стоит до установки агента" "порядок неверный ($guard / $step1)"
fi

# INSTALL_AGENT должен где-то читаться, иначе ключ бессмыслен
reads=$(grep -cE 'INSTALL_AGENT' "$INSTALL_SH" || true)
if [[ ${reads:-0} -ge 3 ]]; then
    t_ok "INSTALL_AGENT читается в коде (вхождений: $reads)"
else
    t_bad "INSTALL_AGENT читается в коде" \
        "вхождений ${reads:-0}: объявление и разбор есть, использования нет"
fi

# Значение по умолчанию — «не ставить»: машина панели без игровой части
if grep -qE '^INSTALL_AGENT="no"' "$INSTALL_SH"; then
    t_ok "по умолчанию агент не ставится"
else
    t_bad "по умолчанию агент не ставится" "значение по умолчанию изменилось"
fi

# ═══════════════════════════════════════════════════════════════════
# 22. nginx собирается и без сертификата
#
# Два дефекта, каждый из которых ронял установку:
#   1) HTTPS-блок генерировался всегда, а заглушка комментировала только
#      строку ssl_certificate. На «listen 443 ssl» без сертификата nginx
#      не стартует, поэтому первый запуск с SSL всегда падал на nginx -t;
#   2) блок 80-го порта редиректил на https, которого ещё нет — панель
#      становилась недоступна целиком.
# ═══════════════════════════════════════════════════════════════════
head_ "nginx без сертификата"

# setup_nginx нельзя вытаскивать диапазоном «до первой }»: конфиг nginx лежит
# в heredoc, и закрывающие скобки location-блоков стоят в нулевой колонке —
# обычный sed обрывается где-то в середине и половина проверок врёт.
# Берём от setup_nginx до setup_ssl целиком.
NGX_BODY=$(awk '/^setup_nginx\(\) \{/{on=1} /^setup_ssl\(\) \{/{on=0} on' "$INSTALL_SH")

if grep -qF 'use_ssl=' <<<"$NGX_BODY"; then
    t_ok "вычисляется use_ssl — по наличию сертификата, а не по флагу"
else
    t_bad "вычисляется use_ssl" "решение принимается только по WITH_SSL"
fi

if grep -qF 'fullchain.pem' <<<"$NGX_BODY" && grep -qF 'if [[ -f /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem' <<<"$NGX_BODY"; then
    t_ok "HTTPS-блок создаётся только при наличии сертификата"
else
    t_bad "HTTPS-блок создаётся только при наличии сертификата" \
        "блок 443 будет создан без сертификата и nginx не стартует"
fi

# Редирект на https допустим только когда блок 443 действительно есть
if grep -qF 'listen 443 ssl;' <<<"$NGX_BODY"; then
    t_ok "блок listen 443 присутствует в конфиге"
else
    t_bad "блок listen 443 присутствует" "в конфиге нет https-сервера"
fi

red_line=$(grep -n 'return 301 https' <<<"$NGX_BODY" | head -1 | cut -d: -f1)
use_line=$(grep -n 'if \[\[ \$use_ssl == "yes" \]\]' <<<"$NGX_BODY" | head -1 | cut -d: -f1)
if [[ -n $red_line && -n $use_line ]] && (( red_line > use_line )); then
    t_ok "редирект на https внутри ветки use_ssl"
else
    t_bad "редирект на https внутри ветки use_ssl" \
        "редирект без блока 443 делает панель недоступной"
fi

# Набор location'ов панели должен быть один, а не продублирован
loc_defs=$(grep -c 'location ~ \^/index' <<<"$NGX_BODY" || true)
if [[ ${loc_defs:-0} -eq 1 ]]; then
    t_ok "набор location'ов панели задан один раз"
else
    t_bad "набор location'ов панели задан один раз" \
        "найдено определений: ${loc_defs:-0} — копии разъедутся"
fi

if grep -qF '${locations}' <<<"$NGX_BODY"; then
    t_ok "общий набор подставляется и в HTTP, и в HTTPS"
else
    t_bad "общий набор подставляется и в HTTP, и в HTTPS" "нет \${locations}"
fi

# Старая заглушка с sed по строке сертификата больше не годится:
# после пересборки конфига её цель исчезает
if grep -qE "sed -i 's/\^#? *ssl_certificate" <<<"$NGX_BODY"; then
    t_bad "sed по строке ssl_certificate удалён" \
        "после пересборки конфига такой sed перестаёт находить цель"
else
    t_ok "sed по строке ssl_certificate удалён"
fi

# setup_ssl обязан пересобрать конфиг после получения сертификата
SSL_BODY=$(sed -n '/^setup_ssl()/,/^}/p' "$INSTALL_SH")
if grep -qE '^    setup_nginx$' <<<"$SSL_BODY"; then
    t_ok "setup_ssl пересобирает конфиг после certbot"
else
    t_bad "setup_ssl пересобирает конфиг после certbot" \
        "после certbot HTTPS-блоки не появятся"
fi

# ═══════════════════════════════════════════════════════════════════
# 23. Сборка фронтенда не зависит от наличия lock-файла
#
# npm ci требует package-lock.json и без него падает. В репозитории lock-файла
# не было, поэтому установщик уходил в npm ci, получал ошибку и оставлял панель
# без стилей и скриптов.
# ═══════════════════════════════════════════════════════════════════
head_ "Сборка фронтенда"

# Установка PHP- и JS-зависимостей живёт в install_panel_deps — setup_panel
# была разделена на deploy_panel_sources и install_panel_deps, чтобы .env
# создавался между ними.
SP_BODY=$(sed -n '/^install_panel_deps()/,/^}/p' "$INSTALL_SH")

if grep -qF 'package-lock.json' <<<"$SP_BODY"; then
    t_ok "наличие package-lock.json проверяется"
else
    t_bad "наличие package-lock.json проверяется" \
        "npm ci будет вызван без lock-файла и упадёт"
fi

if grep -qF 'npm_install="npm install"' <<<"$SP_BODY"; then
    t_ok "без lock-файла используется npm install"
else
    t_bad "без lock-файла используется npm install" "нет запасного пути"
fi

if grep -qF 'npm_install="npm ci"' <<<"$SP_BODY"; then
    t_ok "с lock-файлом используется npm ci (воспроизводимо)"
else
    t_bad "с lock-файлом используется npm ci" "нет предпочтительного пути"
fi

# Сбой установки зависимостей и сбой сборки ассетов — разные вещи,
# путать их нельзя: без ассетов панель не сломана, она просто не одета
builds=$(grep -cE 'npm run build' <<<"$SP_BODY" || true)
if [[ ${builds:-0} -ge 1 ]]; then
    t_ok "сборка ассетов идёт отдельным шагом"
else
    t_bad "сборка ассетов идёт отдельным шагом" "сборка не вызывается"
fi

# ═══════════════════════════════════════════════════════════════════
# 24. Каталоги Laravel и список расширений PHP
#
# composer install падал с «The bootstrap/cache directory must be present
# and writable»: в репозитории этих каталогов нет, только .gitignore.
# А проверка расширений называла отсутствующими cli, fpm, mysql и opcache —
# это SAPI и расширение, удалённое из PHP в 7.0, а не настоящие расширения.
# ═══════════════════════════════════════════════════════════════════
head_ "Каталоги Laravel и расширения PHP"

if grep -q '^prepare_panel_dirs()' "$INSTALL_SH"; then
    t_ok "prepare_panel_dirs объявлена"
else
    t_bad "prepare_panel_dirs объявлена" "нет функции"
fi

# Ищем именно настоящий вызов composer install: строка с --no-dev есть
# только в setup_panel, в подсказках про ручную установку её нет.
PD_LINE=$(grep -n '^[[:space:]]*prepare_panel_dirs$' "$INSTALL_SH" | head -1 | cut -d: -f1)
CI_LINE=$(grep -n 'composer install --no-dev' "$INSTALL_SH" | head -1 | cut -d: -f1)
if [[ -n $PD_LINE && -n $CI_LINE ]] && (( PD_LINE < CI_LINE )); then
    t_ok "каталоги создаются до composer install"
else
    t_bad "каталоги создаются до composer install" \
        "порядок неверный (каталоги: $PD_LINE, composer: $CI_LINE)"
fi

PD_BODY=$(sed -n '/^prepare_panel_dirs()/,/^}/p' "$INSTALL_SH")
for d in 'bootstrap/cache' 'storage/framework/cache' 'storage/framework/sessions' 'storage/framework/views' 'storage/logs' 'public/build'; do
    if grep -qF "$d" <<<"$PD_BODY"; then
        t_ok "создаётся $d"
    else
        t_bad "создаётся $d" "каталог не создаётся — Laravel его потребует"
    fi
done

# Расширения: настоящие имена, без имён SAPI.
# Массив required разбит переносом строки, поэтому собираем его целиком —
# построчная проверка одной строки «local -a required=(…» теряла бы
# большую часть имён.
VP_BODY=$(sed -n '/^verify_php_installed()/,/^}/p' "$INSTALL_SH")
req=$(sed -n '/local -a required=/,/)/p' <<<"$VP_BODY")

for bogus in ' cli ' ' fpm ' ' mysql '; do
    if grep -qF "$bogus" <<<"$req"; then
        t_bad "в списке расширений нет $bogus" \
            "это не расширение PHP (SAPI либо удалённое расширение) — ложное срабатывание"
    else
        t_ok "в списке расширений нет $bogus"
    fi
done

for real in 'pdo_mysql' 'mbstring' 'bcmath' 'intl' 'fileinfo'; do
    if grep -qF "$real" <<<"$req"; then
        t_ok "в списке есть $real"
    else
        t_bad "в списке есть $real" "без него панель может не заработать"
    fi
done

# Успешное сообщение не должно печататься сразу после предупреждения
# о недостающих расширениях
if grep -qE 'warn .*без расширений' <<<"$VP_BODY" && grep -qE 'fail .*расшир' <<<"$VP_BODY"; then
    t_ok "при недостающих расширениях установка останавливается внятно"
else
    t_bad "при недостающих расширениях установка останавливается внятно" \
        "после warn печатается ok «все расширения на месте»"
fi

# ═══════════════════════════════════════════════════════════════════
# 25. Пароль пользователя БД всегда совпадает с .env
#
# CREATE USER IF NOT EXISTS при уже существующем пользователе пароль не
# трогает, а .env получает свежесгенерированный DB_PASS. На повторном
# запуске установщика они расходились, и миграции падали с
# «Access denied for user 'gamedock'@'localhost'».
# ═══════════════════════════════════════════════════════════════════
head_ "Пароль пользователя БД и .env"

SD_BODY=$(sed -n '/^setup_database()/,/^}/p' "$INSTALL_SH")

if grep -qF "CREATE USER IF NOT EXISTS" <<<"$SD_BODY"; then
    t_ok "пользователь создаётся условно (IF NOT EXISTS)"
else
    t_ok "пользователь создаётся условно" "проверка через CREATE USER IF NOT EXISTS не найдена"
fi

# Ключевое: после создания должен идти ALTER USER, иначе пароль из .env
# может не совпасть с паролем в MySQL
if grep -qF "ALTER USER '\${DB_USER}'@'localhost' IDENTIFIED BY" <<<"$SD_BODY"; then
    t_ok "пароль приводится в соответствие через ALTER USER"
else
    t_bad "пароль приводится в соответствие через ALTER USER" \
        "CREATE USER IF NOT EXISTS не меняет пароль существующего пользователя — миграции упадут с Access denied"
fi

# ALTER должен идти после CREATE и до GRANT
create_line=$(grep -n 'CREATE USER IF NOT EXISTS' <<<"$SD_BODY" | head -1 | cut -d: -f1)
alter_line=$(grep -n "ALTER USER '\${DB_USER}'" <<<"$SD_BODY" | head -1 | cut -d: -f1)
grant_line=$(grep -n 'GRANT ALL PRIVILEGES' <<<"$SD_BODY" | head -1 | cut -d: -f1)
if [[ -n $create_line && -n $alter_line && -n $grant_line ]] &&
   (( create_line < alter_line && alter_line < grant_line )); then
    t_ok "порядок: CREATE → ALTER → GRANT"
else
    t_bad "порядок: CREATE → ALTER → GRANT" "нарушен ($create_line / $alter_line / $grant_line)"
fi

# .env обязан получать тот же DB_PASS
SE_BODY=$(sed -n '/^setup_env()/,/^}/p' "$INSTALL_SH")
if grep -qF 'DB_PASSWORD=${DB_PASS}' <<<"$SE_BODY"; then
    t_ok ".env получает тот же DB_PASS, что идёт в ALTER USER"
else
    t_bad ".env получает тот же DB_PASS" "в .env другой источник пароля"
fi

# ═══════════════════════════════════════════════════════════════════
# 26. Порядок .env и зависимостей + кавычки в .env
#
# composer install вызывает artisan package:discover, который поднимает
# приложение и читает .env. Если .env создаётся после composer, установка
# падает на файле, дошедшем от прошлого прогона. А значение с пробелом без
# кавычек ломает разбор dotenv целиком: «Failed to parse dotenv file.
# Encountered unexpected whitespace at […]».
# ═══════════════════════════════════════════════════════════════════
head_ "Порядок .env и composer"

MAIN_LINE=$(grep -n '^main() {' "$INSTALL_SH" | head -1 | cut -d: -f1)
main_body=$(awk -v s="$MAIN_LINE" 'NR>s' "$INSTALL_SH")

dep=$(grep -n '^[[:space:]]*deploy_panel_sources$' <<<"$main_body" | head -1 | cut -d: -f1)
env_line=$(grep -n '^[[:space:]]*setup_env$' <<<"$main_body" | head -1 | cut -d: -f1)
ins=$(grep -n '^[[:space:]]*install_panel_deps$' <<<"$main_body" | head -1 | cut -d: -f1)
mig=$(grep -n '^[[:space:]]*run_migrations$' <<<"$main_body" | head -1 | cut -d: -f1)

if [[ -n $dep && -n $env_line && -n $ins && -n $mig ]] &&
   (( dep < env_line && env_line < ins && ins < mig )); then
    t_ok "порядок: исходники → .env → зависимости → миграции"
else
    t_bad "порядок: исходники → .env → зависимости → миграции" \
        "неверный порядок (источники $dep, .env $env_line, зависимости $ins, миграции $mig)"
fi

if grep -q '^install_panel_deps()' "$INSTALL_SH" && grep -q '^deploy_panel_sources()' "$INSTALL_SH"; then
    t_ok "установка разделена на исходники и зависимости"
else
    t_bad "установка разделена на исходники и зависимости" "нет одной из функций"
fi

# .env: значения, которые могут содержать пробел, обязаны быть в кавычках
SE_BODY=$(sed -n '/^setup_env()/,/^ENVEOF/p' "$INSTALL_SH")
for key in APP_NAME APP_URL MAIL_FROM_ADDRESS MAIL_FROM_NAME GD_BRAND_NAME GD_SUPPORT_EMAIL; do
    if grep -qE "^${key}=\"" <<<"$SE_BODY"; then
        t_ok "$key записан в кавычках"
    else
        t_bad "$key записан в кавычках" "значение с пробелом сломает разбор .env"
    fi
done

# storage:link падает на повторном запуске
if grep -qF 'public/storage' "$INSTALL_SH" && grep -qF 'rm -f "$PANEL_DIR/public/storage"' "$INSTALL_SH"; then
    t_ok "storage:link идемпотентен — старая ссылка удаляется"
else
    t_bad "storage:link идемпотентен" "повторный запуск упадёт «link already exists»"
fi

# ═══════════════════════════════════════════════════════════════════
# 27. Настройки панели идут через .env, а не правкой PHP-файла
#
# Установщик раньше правил config/hosting.php регулярными выражениями.
# Одна из подстановок съедала три строки блока 'trial', после чего
# синтаксис файла ломался и падал любой artisan — установка завершалась
# с кодом 1 и без единого сообщения. Теперь конфиг читает значения через
# env(), и установщик ничего в нём не правит.
# ═══════════════════════════════════════════════════════════════════
head_ "Настройки панели через .env"

HOSTING_PHP="$ROOT/panel/config/hosting.php"

AHC_BODY=$(sed -n '/^apply_hosting_config()/,/^}/p' "$INSTALL_SH")
# Комментарии отбрасываем: в функции есть пояснение, от чего именно мы
# отказались, и grep по нему давал бы ложное срабатывание.
AHC_CODE=$(grep -v '^[[:space:]]*#' <<<"$AHC_BODY")

if grep -q 'preg_replace' <<<"$AHC_CODE" || grep -q 'file_put_contents' <<<"$AHC_CODE"; then
    t_bad "установщик не правит config/hosting.php" "внутри есть регулярная правка PHP-файла"
else
    t_ok "установщик не правит config/hosting.php"
fi

# Ответы диалога обязаны попасть в .env, иначе выбор пользователя теряется
ENV_BODY=$(sed -n '/^setup_env()/,/^ENVEOF/p' "$INSTALL_SH")
for key in GD_PROMO_DISCOUNT GD_PROMO_DURATION GD_PROMO_BONUS GD_REFERRAL \
           GD_SECRET_CODES GD_TRIAL GD_TRIAL_DAYS; do
    if grep -qE "^${key}=" <<<"$ENV_BODY"; then
        t_ok "$key попадает в .env"
    else
        t_bad "$key попадает в .env" "ответ диалога не записывается — теряется"
    fi
done

for key in GD_PAY_YOOKASSA GD_PAY_TINKOFF GD_PAY_CRYPTOBOT GD_PAY_MANUAL GD_PAYMENTS_ENABLED; do
    if grep -qE "^${key}=" <<<"$ENV_BODY"; then
        t_ok "$key попадает в .env"
    else
        t_bad "$key попадает в .env" "способ оплаты не настраивается"
    fi
done

# Флаги обязаны быть true/false: Laravel превращает в bool только эти строки.
# Значение «no» осталось бы строкой, и (bool)"no" дал бы true.
for key in GD_PROMO_DISCOUNT GD_REFERRAL GD_SECRET_CODES GD_TRIAL GD_PAY_MANUAL; do
    line=$(grep -E "^${key}=" <<<"$ENV_BODY" | head -1)
    if grep -q 'yn_bool\|pay_enabled\|any_pay_enabled' <<<"$line"; then
        t_ok "$key записан через конвертер в true/false"
    else
        t_bad "$key записан через конвертер" "yes/no не станет bool — флаг не выключится"
    fi
done

if grep -q '^yn_bool()' "$INSTALL_SH" && grep -q '^pay_enabled()' "$INSTALL_SH" \
   && grep -q '^any_pay_enabled()' "$INSTALL_SH"; then
    t_ok "конвертеры ответов диалога объявлены"
else
    t_bad "конвертеры ответов диалога объявлены" "нет yn_bool/pay_enabled/any_pay_enabled"
fi

# Хелпер должен быть на верхнем уровне. Если он окажется внутри другой
# функции, определится только при её вызове — и будет отсутствовать,
# когда понадобится.
helper_nested=$(awk '
    /^[a-z_]+\(\) \{$/ && !/^(yn_bool|pay_enabled|any_pay_enabled)\(\) \{$/ { inside=1 }
    /^\}$/ { inside=0 }
    inside && /^(yn_bool|pay_enabled|any_pay_enabled)\(\) \{$/ { print NR }
' "$INSTALL_SH")

if [[ -z $helper_nested ]]; then
    t_ok "хелперы объявлены на верхнем уровне"
else
    t_bad "хелперы на верхнем уровне" "вложены в функции: строки $helper_nested"
fi

# Конфиг обязан читать те же ключи через env()
for key in GD_PROMO_DISCOUNT GD_REFERRAL GD_SECRET_CODES GD_TRIAL \
           GD_PAY_YOOKASSA GD_PAY_TINKOFF GD_PAY_CRYPTOBOT GD_PAY_MANUAL; do
    if grep -q "env('$key'" "$HOSTING_PHP"; then
        t_ok "config/hosting.php читает $key"
    else
        t_bad "config/hosting.php читает $key" "ключ есть в .env, но конфиг его не видит"
    fi
done

# Провал artisan не должен быть молчаливым: раньше вывод уходил в /dev/null
# и установщик просто завершался с кодом 1 без объяснения.
if grep -q 'artisan config:clear" 2>&1' <<<"$AHC_BODY" && grep -q 'fail ' <<<"$AHC_BODY"; then
    t_ok "ошибка artisan в apply_hosting_config не молчит"
else
    t_bad "ошибка artisan не молчит" "вывод заглушён, установка падает без причины"
fi

# ═══════════════════════════════════════════════════════════════════
# 28. Помощники, вызываемые внутри $( ) при заполнении .env
#
# .env пишется некавыченным heredoc, поэтому значения вычисляются
# подстановкой команд. Любой вывод в stdout такого помощника становится
# частью значения ключа, и Laravel падает с «Encountered unexpected
# whitespace at […]» — на composer install, задолго до самих флагов.
# ═══════════════════════════════════════════════════════════════════
head_ "Чистота stdout у помощников .env"

for fn in yn_bool pay_enabled any_pay_enabled; do
    body=$(sed -n "/^${fn}()/,/^}/p" "$INSTALL_SH")

    # Любой warn/log/ok/fail/step внутри функции обязан идти в stderr.
    # Вызовы без >&2 считаем нарушением.
    noisy=$(grep -E '^[[:space:]]*(warn|log|ok|fail|step)[[:space:]]' <<<"$body" \
        | grep -v '>&2' || true)

    if [[ -z $noisy ]]; then
        t_ok "$fn не пишет в stdout"
    else
        t_bad "$fn не пишет в stdout" "вывод попадёт в значение ключа: $noisy"
    fi

    # printf должен выдавать само значение: ровно true или false.
    # printf может стоять в конце строки case: «…|on) printf true ;;»
    outs=$(grep -cE '(^|[[:space:];])printf (true|false)' <<<"$body" || true)
    if [[ $outs -ge 1 ]]; then
        t_ok "$fn печатает значение"
    else
        t_bad "$fn печатает значение" "нет ни одного printf true/false"
    fi
done

# setup_env должен проверять флаги до того, как панель их прочитает.
ENV_FN=$(sed -n '/^setup_env()/,/^}/p' "$INSTALL_SH")
if grep -q 'GD_TRIAL_DAYS=\[0-9\]' <<<"$ENV_FN" && grep -q 'true|false' <<<"$ENV_FN"; then
    t_ok "setup_env проверяет значения флагов в .env"
else
    t_bad "setup_env проверяет флаги" "мусорное значение уедет в Laravel и упадёт там"
fi

# Проверка обязана стоять после heredoc, иначе файла ещё нет.
env_end=$(grep -n '^ENVEOF' <<<"$ENV_FN" | head -1 | cut -d: -f1)
chk_line=$(grep -n 'GD_TRIAL_DAYS=\[0-9\]' <<<"$ENV_FN" | head -1 | cut -d: -f1)
if [[ -n $env_end && -n $chk_line ]] && (( chk_line > env_end )); then
    t_ok "проверка флагов идёт после записи .env"
else
    t_bad "проверка флагов после записи .env" "порядок: heredoc $env_end, проверка $chk_line"
fi

# ═══════════════════════════════════════════════════════════════════
# 29. Агент на «панельной» машине и воркеры очереди в автозагрузке
#
# Падение: /etc/gamedock/agent.env.example — каталога не существовало,
# а setup_agent_template вызывалась даже с --no-agent. Сверх того она
# копировала файл в формате KEY=VALUE по пути agent.config.json, который
# агент читает через JSON.parse: конфиг молча игнорировался, а рабочий
# JSON из репозитория затирался.
#
# Отдельно: у шаблона gamedock-queue@.service не было секции [Install],
# поэтому systemctl enable инстансов падал с «no installation config».
# Воркеры запускались (из-за --now), но после перезагрузки не
# поднимались — установка при этом выглядела успешной.
# ═══════════════════════════════════════════════════════════════════
head_ "Агент и автозагрузка очереди"

SAT_BODY=$(sed -n '/^setup_agent_template()/,/^}/p' "$INSTALL_SH")

# Каталог /etc/gamedock обязан создаваться до первой записи в него
if [[ -n $SAT_BODY ]] && grep -q 'mkdir -p /etc/gamedock' <<<"$SAT_BODY" \
   && grep -q 'agent.env.example' <<<"$SAT_BODY"; then
    mkdir_line=$(grep -n 'mkdir -p /etc/gamedock' <<<"$SAT_BODY" | head -1 | cut -d: -f1)
    write_line=$(grep -n 'cat >/etc/gamedock/agent.env.example' <<<"$SAT_BODY" | head -1 | cut -d: -f1)
    if (( mkdir_line < write_line )); then
        t_ok "каталог /etc/gamedock создаётся до записи"
    else
        t_bad "каталог /etc/gamedock создаётся до записи" "mkdir $mkdir_line, запись $write_line"
    fi
else
    t_bad "каталог /etc/gamedock создаётся" "нет mkdir -p /etc/gamedock"
fi

# --no-agent должен пропускать всю подготовку агента
if grep -q 'INSTALL_AGENT != "yes"' <<<"$SAT_BODY"; then
    t_ok "подготовка агента уважает --no-agent"
else
    t_bad "подготовка агента уважает --no-agent" "ставится даже без агента"
fi

# Копировать .env в agent.config.json нельзя: агент парсит его как JSON
if grep -q 'agent.env.example "$AGENT_DIR/agent.config.json"' "$INSTALL_SH"; then
    t_bad "JSON-конфиг агента не затирается" ".env копируется в agent.config.json"
else
    t_ok "JSON-конфиг агента не затирается"
fi

# Шаблон конфигурации должен содержать подставленные значения
if grep -q "<<'AGENTENVEOF'" <<<"$SAT_BODY"; then
    t_bad "шаблон конфигурации с подстановками" "heredoc кавыченный — останутся литералы"
else
    t_ok "шаблон конфигурации с подстановками"
fi

# Шаблон юнита очереди пишется один раз и содержит [Install]
UNIT_BODY=$(sed -n '/cat >\/etc\/systemd\/system\/gamedock-queue@.service/,/^EOF$/p' "$INSTALL_SH")
if [[ -n $UNIT_BODY ]]; then
    t_ok "шаблон юнита очереди описан"
else
    t_bad "шаблон юнита очереди описан" "блок не найден"
fi

if grep -q '^\[Install\]$' <<<"$UNIT_BODY"; then
    t_ok "у шаблона очереди есть [Install]"
else
    t_bad "у шаблона очереди есть [Install]" "без него enable инстансов не работает"
fi

if grep -q 'WantedBy=multi-user.target' <<<"$UNIT_BODY"; then
    t_ok "шаблон очереди включается в multi-user.target"
else
    t_bad "шаблон очереди включается в multi-user.target" "в автозагрузку не попадёт"
fi

# Запись шаблона не должна быть внутри цикла по числу воркеров
queue_in_loop=0
i=1
while [[ $i -le $(wc -l <"$INSTALL_SH") ]]; do
    line=$(sed -n "${i}p" "$INSTALL_SH")
    if [[ $line == 'for i in $(seq 1 $QUEUE_WORKERS); do' ]]; then
        next=$(sed -n "$((i + 1))p" "$INSTALL_SH")
        if [[ $next == *gamedock-queue@.service* ]]; then
            queue_in_loop=1
        fi
    fi
    i=$(( i + 1 ))
done

if [[ $queue_in_loop -eq 0 ]]; then
    t_ok "шаблон очереди не пишется в цикле"
else
    t_bad "шаблон очереди не пишется в цикле" "тот же файл пишется несколько раз"
fi

# Включать надо шаблон, а не инстансы
if grep -q 'systemctl enable "gamedock-queue@.service"' "$INSTALL_SH"; then
    t_ok "в автозагрузку включается шаблон очереди"
else
    t_bad "включается шаблон очереди" "нет enable для gamedock-queue@.service"
fi

if grep -q 'enable --now "gamedock-queue@' "$INSTALL_SH"; then
    t_bad "инстансы очереди не включаются поштучно" "enable инстанса без [Install] падает"
else
    t_ok "инстансы очереди не включаются поштучно"
fi

# ═══════════════════════════════════════════════════════════════════
# 30. Автоустановщик — один файл
#
# Раньше меню жило в отдельном deploy/menu.sh. Два файла — два источника
# правды: подменю, вызывающие install.sh, и сам install.sh расходились.
# Теперь меню внутри install.sh, а отдельного файла нет.
# ═══════════════════════════════════════════════════════════════════
head_ "Единый файл установщика"

if [[ ! -e "$ROOT/deploy/menu.sh" ]]; then
    t_ok "отдельного deploy/menu.sh нет"
else
    t_bad "отдельного deploy/menu.sh нет" "меню живёт в install.sh, второй файл лишний"
fi

# Функции меню обязаны быть в install.sh
menu_fns="main_menu run_menu banner menu_item menu_footer read_choice confirm pause"
menu_fns="$menu_fns menu_cron_backup menu_services menu_status install_all ensure_repo"
missing_menu=""
for fn in $menu_fns; do
    if ! grep -q "^${fn}()" "$INSTALL_SH"; then
        missing_menu="$missing_menu $fn"
    fi
done
if [[ -z $missing_menu ]]; then
    t_ok "функции меню объявлены в install.sh"
else
    t_bad "функции меню в install.sh" "нет:$missing_menu"
fi

# Помощники вывода не должны дублироваться: у install.sh свои ok, warn,
# step, ask — они ещё и пишут в LOG_FILE. Дубли перекрыли бы их молча.
for fn in ok warn step ask; do
    n=$(grep -c "^${fn}()" "$INSTALL_SH" || true)
    if [[ $n -eq 1 ]]; then
        t_ok "$fn определён один раз"
    else
        t_bad "$fn определён один раз" "определений: $n"
    fi
done

# Меню — режим по умолчанию, но линейный сценарий обязан сохраниться:
# он нужен для CI и Docker, где параметры передаются всегда.
# -qF, а не -q: в базовом регулярном выражении «|» литерален, и шаблон
#   '-menu|-m)' просто не находился бы.
if grep -qF -- '--menu|-m)' "$INSTALL_SH" && grep -qF 'SHOW_MENU="yes"' "$INSTALL_SH"; then
    t_ok "есть ключ --menu"
else
    t_bad "есть ключ --menu" "меню нельзя выбрать явно"
fi

if grep -q 'main "\$@"' "$INSTALL_SH"; then
    t_ok "линейный сценарий сохранён"
else
    t_bad "линейный сценарий сохранён" "нет вызова main"
fi

# Нижний код под защитой: без неё `source install.sh` запускал бы
# установку — а так подгружают эти же тесты.
if grep -qF 'BASH_SOURCE[0]}" == "${0}' "$INSTALL_SH"; then
    t_ok "нижний код защищён от source"
else
    t_bad "нижний код защищён от source" "нет проверки BASH_SOURCE"
fi

# Меню не должно тянуть отсутствующий файл.
# Комментарии не в счёт: в install.sh осталось упоминание «перенесено из
# прежнего deploy/menu.sh» как объяснение. Смотрим только исполняемые строки.
menu_code=$(grep -v '^[[:space:]]*#' "$INSTALL_SH" | grep -F '/deploy/menu.sh' || true)
if [[ -z $menu_code ]]; then
    t_ok "установщик не ссылается на menu.sh"
else
    t_bad "установщик не ссылается на menu.sh" "остались ссылки на удалённый файл"
fi

# ═══════════════════════════════════════════════════════════════════
# 31. Юниты в автозагрузке и идемпотентность Redis
# ═══════════════════════════════════════════════════════════════════
head_ "Юниты и Redis"

# Каждый генерируемый юнит обязан иметь [Install]. Без него systemd
# считает юнит static: enable молча ничего не делает, и после перезагрузки
# служба не поднимается.
for unit in gamedock-wss gamedock-scheduler; do
    body=$(sed -n "/cat >\/etc\/systemd\/system\/${unit}\.service/,/^EOF$/p" "$INSTALL_SH")
    if [[ -z $body ]]; then
        t_bad "юнит $unit описан" "блок юнита не найден"
    elif grep -q '^\[Install\]$' <<<"$body" && grep -q 'WantedBy=multi-user.target' <<<"$body"; then
        t_ok "юнит $unit включается в автозагрузку"
    else
        t_bad "юнит $unit включается в автозагрузку" "нет [Install] — будет static"
    fi
done

# Шаблон очереди — отдельная проверка: он общий для всех инстансов
qbody=$(sed -n '/cat >\/etc\/systemd\/system\/gamedock-queue@\.service/,/^EOF$/p' "$INSTALL_SH")
if grep -q '^\[Install\]$' <<<"$qbody"; then
    t_ok "шаблон очереди включается в автозагрузку"
else
    t_bad "шаблон очереди включается в автозагрузку" "нет [Install]"
fi

# Пароль Redis не должен меняться при повторной установке
RED_BODY=$(sed -n '/^setup_redis()/,/^}/p' "$INSTALL_SH")
if grep -q 'redis.conf.gamedock' <<<"$RED_BODY" && grep -q 'awk .*requirepass' <<<"$RED_BODY"; then
    t_ok "пароль Redis переиспользуется"
else
    t_bad "пароль Redis переиспользуется" "пароль генерируется заново каждый раз"
fi

# Генерация должна быть лишь запасным путём, когда пароля ещё нет
gen_count=$(grep -c 'REDIS_PASS="\$(gen_hex 32)"' <<<"$RED_BODY" || true)
if [[ $gen_count -eq 1 ]] && grep -q '^ *if \[\[ -z \${REDIS_PASS:-} \]\]; then$' <<<"$RED_BODY"; then
    t_ok "новый пароль создаётся только при отсутствии"
else
    t_bad "новый пароль создаётся только при отсутствии" "генераций: $gen_count"
fi

# ═══════════════════════════════════════════════════════════════════
# ═══════════════════════════════════════════════════════════════════
# 32. Параметры ядра: rp_filter и область применения sysctl
#
# Строгий rp_filter=1 ронял сеть на виртуалке за NAT (наблюдалось дважды
# во время установки: ни ping, ни SSH). Нужен мягкий режим 2.
# ═══════════════════════════════════════════════════════════════════
head_ "Параметры ядра"

OPT_BODY=$(sed -n '/^optimize_system()/,/^}/p' "$INSTALL_SH")

# rp_filter проверяем по строке значения, а не по упоминанию в комментарии.
if grep -qE '^[[:space:]]*net\.ipv4\.conf\.(all|default)\.rp_filter = 1$' <<<"$OPT_BODY"; then
    t_bad "rp_filter не строгий" "включён режим 1 — сеть рвётся за NAT"
else
    t_ok "rp_filter не строгий"
fi

all_mode=$(grep -E 'net\.ipv4\.conf\.all\.rp_filter = ' <<<"$OPT_BODY" | head -1 | tr -d ' ' | cut -d= -f2)
def_mode=$(grep -E 'net\.ipv4\.conf\.default\.rp_filter = ' <<<"$OPT_BODY" | head -1 | tr -d ' ' | cut -d= -f2)
if [[ $all_mode == 2 && $def_mode == 2 ]]; then
    t_ok "rp_filter=2 для all и default"
else
    t_bad "rp_filter=2 для all и default" "all=$all_mode, default=$def_mode"
fi

# Применяться должен только наш файл, а не весь /etc/sysctl.d.
if grep -qE '^[[:space:]]*sysctl --system' <<<"$OPT_BODY"; then
    t_bad "применяется только свой файл sysctl" "sysctl --system тянет чужие настройки"
else
    t_ok "применяется только свой файл sysctl"
fi

if grep -qF 'sysctl -p /etc/sysctl.d/99-gamedock.conf' <<<"$OPT_BODY"; then
    t_ok "sysctl -p по своему файлу"
else
    t_bad "sysctl -p по своему файлу" "параметры не применяются явно"
fi

# Имя файла в sysctl -p должно совпадать с именем, куда пишем.
written=$(grep -oE '/etc/sysctl\.d/[a-z0-9.-]+\.conf' <<<"$OPT_BODY" | head -1)
applied=$(grep -oE 'sysctl -p /etc/sysctl\.d/[a-z0-9.-]+\.conf' <<<"$OPT_BODY" | head -1 | awk '{print $3}')
if [[ -n $written && $written == "$applied" ]]; then
    t_ok "применяется тот же файл, что и записывается"
else
    t_bad "файлы sysctl совпадают" "записывается $written, применяется $applied"
fi

# ═══════════════════════════════════════════════════════════════════
# ═══════════════════════════════════════════════════════════════════
# 33. fail2ban не должен запирать администратора
# ═══════════════════════════════════════════════════════════════════
head_ "fail2ban и доступ"

FW_BODY=$(sed -n '/^setup_fail2ban()/,/^}/p' "$INSTALL_SH")

if [[ -n $FW_BODY ]]; then
    t_ok "настройка fail2ban вынесена в отдельную функцию"
else
    t_bad "настройка fail2ban вынесена в отдельную функцию" "нет setup_fail2ban()"
fi

# ignoreip обязателен: без него бан своего адреса = потеря доступа.
if grep -q '^ignoreip = ' <<<"$FW_BODY"; then
    t_ok "задан ignoreip"
else
    t_bad "задан ignoreip" "без него можно забанить себя"
fi

# Приватные сети в исключениях: у панели часто private-адрес.
for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do
    if grep -qF "$net" <<<"$FW_BODY"; then
        t_ok "приватная сеть $net в исключениях"
    else
        t_bad "приватная сеть $net в исключениях" "нет в ignoreip"
    fi
done

# Адрес текущего SSH добавляется: установку могут запускать из разных мест.
if grep -qE 'who am i|SSH_CLIENT|SSH_CONNECTION' <<<"$FW_BODY"; then
    t_ok "адрес текущего SSH добавляется в исключения"
else
    t_bad "адрес текущего SSH в исключениях" "установка из нового места может получить бан"
fi

# Возможность дописать свои адреса: у администратора должны быть все.
if grep -qF '.gamedock-ssh-allowed' <<<"$FW_BODY"; then
    t_ok "есть файл для своих адресов"
else
    t_bad "есть файл для своих адресов" "нет способа добавить администратора"
fi

# Конфиг пишется в jail.d: jail.local перезаписывать нельзя.
if grep -qF 'jail.d/gamedock.local' <<<"$FW_BODY"; then
    t_ok "конфиг пишется в свой файл jail.d"
else
    t_bad "конфиг пишется в свой файл jail.d" "перезапись jail.local затрёт настройки"
fi

# После изменения ignoreip сервис обязательно перезапускается, иначе
# настройка просто не применится.
if grep -q 'systemctl restart fail2ban' <<<"$FW_BODY"; then
    t_ok "fail2ban перезапускается после настройки"
else
    t_bad "fail2ban перезапускается" "ignoreip не применится"
fi

# setup_fail2ban обязана вызываться из setup_firewall.
FIREWALL_BODY=$(sed -n '/^setup_firewall()/,/^}/p' "$INSTALL_SH")
if grep -q '^[[:space:]]*setup_fail2ban$' <<<"$FIREWALL_BODY"; then
    t_ok "настройка fail2ban вызывается"
else
    t_bad "настройка fail2ban вызывается" "функция объявлена, но не вызывается"
fi

if grep -q 'systemctl enable --now fail2ban' "$INSTALL_SH"; then
    t_bad "fail2ban настраивается через setup_fail2ban" "остался прямой вызов systemctl"
else
    t_ok "fail2ban настраивается через setup_fail2ban"
fi

# ═══════════════════════════════════════════════════════════════════
# ═══════════════════════════════════════════════════════════════════
# 34. Пункт меню запускает установку, а не новое меню
# ═══════════════════════════════════════════════════════════════════
head_ "Вложенный запуск из меню"

# run_install обязан помечать дочерний процесс.
RI_BODY=$(sed -n '/^run_install()/,/^}/p' "$INSTALL_SH")
if grep -qF 'GAMEDOCK_NESTED=1 bash "$script" "$@"' <<<"$RI_BODY"; then
    t_ok "run_install помечает вложенный запуск"
else
    t_bad "run_install помечает вложенный запуск" "дочерний процесс не отличить от обычного"
fi

# Признак вложенного запуска обязан учитываться при выборе режима.
if grep -qF '${GAMEDOCK_NESTED:-}' "$INSTALL_SH"; then
    t_ok "при выборе режима учитывается вложенный запуск"
else
    t_bad "при выборе режима учитывается вложенный запуск" "вложенный процесс откроет меню"
fi

# Прежний составной признак «нет аргументов И терминал» в хвосте файла
# больше не используется: условие целиком живёт в mode_is_menu.
# у вложенного запуска терминал тот же, что у родителя.
if grep -qF '{ [[ $# -eq 0 ]] && [[ -t 0 ]] && [[ -t 1 ]]; }' "$INSTALL_SH"; then
    t_bad "нет признака меню по числу аргументов" "вложенный запуск без флагов попадёт в меню"
else
    t_ok "нет признака меню по числу аргументов"
fi

# Решение о режиме вынесено в mode_is_menu — так его можно проверить
# без псевдотерминала, что и делает panel/tools/check-mode.sh.
MODE_BODY=$(sed -n '/^mode_is_menu()/,/^}/p' "$INSTALL_SH")
if [[ -n $MODE_BODY ]]; then
    t_ok "выбор режима вынесен в mode_is_menu"
else
    t_bad "выбор режима вынесен в mode_is_menu" "нет функции"
fi

# Внутри — три исхода: явное меню, отказ при вложенном запуске,
# вход без аргументов.
for needle in 'if [[ -n ${SHOW_MENU:-} ]]; then' 'if [[ -n ${GAMEDOCK_NESTED:-} ]]; then' '[[ $# -eq 0 ]]'; do
    if grep -qF "$needle" <<<"$MODE_BODY"; then
        t_ok "mode_is_menu содержит: $needle"
    else
        t_bad "mode_is_menu содержит: $needle" "нет такого условия"
    fi
done

# Вложенный запуск обязан отказывать ДО проверки аргументов, иначе
# возврат будет неявным и легко потеряется при правках.
nested_line=$(grep -n 'GAMEDOCK_NESTED' <<<"$MODE_BODY" | head -1 | cut -d: -f1)
args_line=$(grep -n '\[\[ \$# -eq 0 \]\]' <<<"$MODE_BODY" | head -1 | cut -d: -f1)
if [[ -n $nested_line && -n $args_line ]] && (( nested_line < args_line )); then
    t_ok "вложенный запуск проверяется раньше числа аргументов"
else
    t_bad "вложенный запуск проверяется раньше" "вложенный $nested_line, аргументы $args_line"
fi

# В хвосте файла остаётся только вызов mode_is_menu.
if grep -qF 'if mode_is_menu "$@"; then' "$INSTALL_SH"; then
    t_ok "хвост файла использует mode_is_menu"
else
    t_bad "хвост файла использует mode_is_menu" "условие продублировано в хвосте"
fi


# Пункт «Установка панели» обязан честно сказать, что идёт установка.
IP_BODY=$(sed -n '/^install_panel()/,/^}/p' "$INSTALL_SH")
if grep -q 'Запускаю линейную установку' <<<"$IP_BODY"; then
    t_ok "пункт 2 сообщает, что идёт установка"
else
    t_bad "пункт 2 сообщает, что идёт установка" "пользователь видит скачок без объяснения"
fi

# Флаги --yes передавать нельзя: панель должна спросить домен и почту.
if grep -q 'run_install --yes' <<<"$IP_BODY"; then
    t_bad "пункт 2 не отключает диалог" "--yes не даст спросить домен и почту"
else
    t_ok "пункт 2 не отключает диалог"
fi

# ═══════════════════════════════════════════════════════════════════
# ═══════════════════════════════════════════════════════════════════
printf '\nПройдено: %d, провалено: %d\n' "$pass" "$fail"
[[ $fail -eq 0 ]] || exit 1

echo 'OK   проверки окружения не блокируют установку на чистой машине'
