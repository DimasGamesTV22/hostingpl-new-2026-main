#!/usr/bin/env bash
#
# GameDock — автоустановщик панели и агентов
#
# Поддерживаемые системы:
#   Debian  11 (bullseye)   PHP 7.4 из дистрибутива → ставится 8.3 с packages.sury.org
#   Debian  12 (bookworm)   PHP 8.2 из дистрибутива
#   Debian  13 (trixie)     PHP 8.4 из дистрибутива
#   Ubuntu  22.04 (jammy)   PHP 8.1 из дистрибутива → ставится 8.3 с packages.sury.org
#   Ubuntu  24.04 (noble)   PHP 8.3 из дистрибутива
#
# Требуется PHP >= 8.2 (Laravel 11) и Node.js >= 20 (агент). Там, где в дистрибутиве
# подходящей версии нет, установщик сам подключает packages.sury.org и NodeSource
# и выбирает версию по таблице выше.
#
# Запуск:
#   curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/install.sh | sudo bash
#
# Или с параметрами:
#   sudo ./install.sh --domain panel.example.com --email admin@example.com --runtime docker
#
# Что делает:
#   1. Определяет дистрибутив и подключает нужные репозитории
#   2. Проверяет систему и ставит зависимости
#   3. Генерирует пароли и ключи
#   4. Разворачивает панель (Laravel), БД, Redis
#   5. Настраивает nginx, PHP-FPM, Let's Encrypt
#   6. Создаёт WSS-сервер панели, очереди, планировщик (systemd)
#   7. Спрашивает про платежи, маркетинг и режим нод — и записывает в config/hosting.php
#   8. Опционально ставит локальный агент (если эта машина ещё и нода)
#
# Скрипт написан на bash: массивы, [[ ]], process substitution, for (( … )).
# Если его запустили через sh (на Debian это dash), он бы упал с
# «[: not found» и «Bad for loop variable». Поэтому перезапускаем себя под bash.
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi

set -euo pipefail

# ═══════════════════════════════════════════════════════════════════
# Константы
# ═══════════════════════════════════════════════════════════════════

GAMEDOCK_VERSION="${GAMEDOCK_VERSION:-1.0.0}"
GAMEDOCK_REPO_URL="${GAMEDOCK_REPO:-https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git}"
GAMEDOCK_BRANCH="${GAMEDOCK_BRANCH:-main}"
INSTALL_DIR="${GAMEDOCK_INSTALL_DIR:-/opt/gamedock}"
PANEL_DIR="$INSTALL_DIR/panel"
AGENT_DIR="$INSTALL_DIR/agent"
GAME_IMAGES_DIR="${GAME_IMAGES_DIR:-/opt/gamedock/game-images}"
STATE_DIR=/var/lib/gamedock
LOG_FILE=/var/log/gamedock-install.log

# phpMyAdmin — веб-интерфейс к базам. Ставится по умолчанию, но доступ
# закрыт паролем nginx, а не открыт всему интернету.
WITH_PHPMYADMIN="${GD_WITH_PHPMYADMIN:-yes}"
PHPMYADMIN_PATH="${GD_PHPMYADMIN_PATH:-/phpmyadmin}"
PHPMYADMIN_ALLOW="${GD_PHPMYADMIN_ALLOW:-}"
PHPMYADMIN_USER="${GD_PHPMYADMIN_USER:-gamedock}"
PHPMYADMIN_SNIPPET=/etc/nginx/snippets/gamedock-phpmyadmin.conf
PHPMYADMIN_HTPASSWD=/etc/nginx/.htpasswd-gamedock
PHPMYADMIN_CREDS=/root/.gamedock-phpmyadmin.txt
PHPMYADMIN_URL=""

DB_NAME="${GD_DB_NAME:-gamedock}"
DB_USER="${GD_DB_USER:-gamedock}"
DB_PASS=""
DB_ROOT_PASS=""

REDIS_PASS=""

SERVICE_USER=gamedock
SERVICE_GROUP=gamedock

# Значения, собранные в диалоге
PANEL_DOMAIN=""
PANEL_EMAIL=""
PANEL_NAME="GameDock"
NODE_MODE="auto"
DEFAULT_RUNTIME="docker"
INSTALL_AGENT="no"
WITH_NGINX="yes"
WITH_SSL="yes"
SSL_EMAIL=""
INSTALL_DB="mysql"
INSTALL_REDIS="yes"

# Версия PHP. Пусто = выбрать автоматически (см. resolve_php_version).
# Laravel 11 требует >= 8.2.
PHP_VERSION="${GD_PHP_VERSION:-}"
MIN_PHP_MAJOR=8
MIN_PHP_MINOR=2

# Требования к месту. MIN — жёсткий минимум, без которого установка не имеет
# смысла; RECOMMENDED — сколько нужно, чтобы ещё влезли файлы игр и бэкапы.
MIN_DISK_GB="${GD_MIN_DISK_GB:-5}"
RECOMMENDED_DISK_GB="${GD_RECOMMENDED_DISK_GB:-20}"

# Версия Node.js для агента. Агент требует >= 20.
NODE_MAJOR="${GD_NODE_MAJOR:-20}"

# Параметры ОС, заполняются в detect_distro()
OS_ID=""
OS_VERSION_ID=""
OS_CODENAME=""
OS_PRETTY_NAME=""
OS_SUPPORTED=0
OS_NEEDS_SURY=0
OS_DISTRO_PHP=""

QUEUE_WORKERS=2

# Маркетинг
PROMO_DISCOUNT="yes"
PROMO_DURATION="yes"
PROMO_BONUS="yes"
REFERRAL="yes"
SECRET_CODES="yes"
TRIAL="yes"
TRIAL_DAYS=3

# Платежи
PAY_YOOKASSA="yes"
PAY_TINKOFF="no"
PAY_CRYPTOBOT="yes"
PAY_MANUAL="yes"
YOOKASSA_SHOP_ID=""
YOOKASSA_SECRET=""
TINKOFF_TERMINAL=""
TINKOFF_PASSWORD=""
CRYPTOBOT_TOKEN=""
SUPPORT_EMAIL=""

# Telegram
TELEGRAM_BOT_TOKEN=""
TELEGRAM_ADMIN_CHAT=""
BOT_USERNAME=""

TELEMETRY="no"

# ═══════════════════════════════════════════════════════════════════
# Утилиты
# ═══════════════════════════════════════════════════════════════════

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m'; CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'

# Печать в консоль и в лог-файл.
#
# Раньше здесь был `echo … | tee -a "$LOG_FILE"`. При `set -euo pipefail`
# недоступный лог-файл (нет прав на /var/log, read-only FS, не-root в
# контейнере) обрывал установку ещё на первом сообщении — с невнятным
# «Permission denied» вместо установки. Теперь недоступный лог не мешает.
_emit() {
    local color="$1"
    shift

    echo -e "${color}$*${NC}"

    if [[ -n ${LOG_FILE:-} ]] && : >>"$LOG_FILE" 2>/dev/null; then
        echo -e "$*" >>"$LOG_FILE" 2>/dev/null || true
    fi
}

# Выполнить команду, показать хвост вывода, полный вывод записать в лог.
#
# Нужна вместо `cmd | tee -a "$LOG_FILE" | tail -N`: при `set -o pipefail`
# падение tee из-за недоступного лог-файла делало пайп «неуспешным», и
# успешная установка пакетов выглядела как ошибка. Здесь вывод команды
# сначала забирается целиком, и статус берётся у самой команды.
#
# $1 — сколько строк показать в консоль, остальное — команда.
run_logged() {
    local tail_n="$1"
    shift
    local -a cmd=("$@")

    local out rc=0
    # stdin закрываем: команда выполняется в подстановке `$(...)`, где stdin
    # иначе наследуется от терминала, и любой вопрос (debconf, needrestart)
    # держит установку, пока пользователь не нажмёт Enter. Здесь нужен отказ
    # сразу, а не ожидание ввода: все команды, проходящие через run_logged
    # (apt-get, nginx -t, certbot --agree-tos), интерактивного ввода не имеют.
    out="$("${cmd[@]}" </dev/null 2>&1)" || rc=$?

    if [[ -n ${LOG_FILE:-} ]] && : >>"$LOG_FILE" 2>/dev/null; then
        printf '%s\n' "$out" >>"$LOG_FILE" 2>/dev/null || true
    fi

    if [[ -n $out ]]; then
        printf '%s\n' "$out" | tail -n "$tail_n"
    fi

    return "$rc"
}

# Пробел-разделитель лежит ВНУТРИ цвета — иначе он попал бы под следующий
# прогон и выглядел бы как отступ, а не как разделитель символа и текста.
log()   { _emit "${BLUE}[GameDock]${NC} " "$*"; }
ok()    { _emit "${GREEN}✓${NC} " "$*"; }
warn()  { _emit "${YELLOW}!${NC} " "$*"; }
fail()  { _emit "${RED}✗${NC} " "$*"; exit 1; }
step()  { echo; _emit "${BOLD}${CYAN}▸ ${NC}" "$*"; }

ask() {
    local prompt="$1" default="$2" answer
    if [[ $INTERACTIVE -eq 0 ]]; then
        echo "$default"
        return
    fi

    read -rp "$(echo -e "${BOLD}${prompt}${NC}")$( [[ -n $default ]] && echo " [${default}]" ) " answer
    echo "${answer:-$default}"
}

ask_yes_no() {
    local prompt="$1" default="$2" answer
    if [[ $INTERACTIVE -eq 0 ]]; then
        echo "$default"
        return
    fi

    read -rp "$(echo -e "${BOLD}${prompt}${NC} [y/n] (${default}) ")" answer
    answer="${answer:-$default}"

    [[ $answer =~ ^[YyДд] ]]
}

ask_password() {
    local prompt="$1" answer
    if [[ $INTERACTIVE -eq 0 ]]; then
        echo ""
        return
    fi

    read -rsp "$(echo -e "${BOLD}${prompt}${NC}") " answer
    echo
    echo "$answer"
}

gen_password() {
    openssl rand -base64 24 | tr -d '/+=' | cut -c1-${1:-24}
}

gen_hex() {
    openssl rand -hex ${1:-32}
}

INTERACTIVE=1
if [[ ! -t 0 ]]; then
    INTERACTIVE=0
    warn "Нет TTY — отвечаю значениями по умолчанию (--help для параметров)"
fi

# ═══════════════════════════════════════════════════════════════════
# Разбор аргументов
# ═══════════════════════════════════════════════════════════════════

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --domain)        PANEL_DOMAIN="$2"; shift 2 ;;
            --email)         PANEL_EMAIL="$2"; shift 2 ;;
            --name)          PANEL_NAME="$2"; shift 2 ;;
            --node-mode)     NODE_MODE="$2"; shift 2 ;;
            --runtime)       DEFAULT_RUNTIME="$2"; shift 2 ;;
            --with-agent)    INSTALL_AGENT="yes"; shift ;;
            --no-agent)      INSTALL_AGENT="no"; shift ;;
            --no-nginx)      WITH_NGINX="no"; shift ;;
            --no-ssl)        WITH_SSL="no"; shift ;;
            --with-tinkoff)  PAY_TINKOFF="yes"; shift ;;
            --db)            INSTALL_DB="$2"; shift 2 ;;
            --php)           PHP_VERSION="$2"; shift 2 ;;
            --node-major)    NODE_MAJOR="$2"; shift 2 ;;
            --queue-workers) QUEUE_WORKERS="$2"; shift 2 ;;
            --dir)           INSTALL_DIR="$2"; shift 2 ;;
            --no-phpmyadmin) WITH_PHPMYADMIN="no"; shift ;;
            --pma-path)      PHPMYADMIN_PATH="$2"; shift 2 ;;
            --pma-user)      PHPMYADMIN_USER="$2"; shift 2 ;;
            --pma-allow)     PHPMYADMIN_ALLOW="$2"; shift 2 ;;
            --yes|-y)        INTERACTIVE=0; shift ;;
            --help|-h)       show_help; exit 0 ;;
            *) fail "Неизвестный параметр: $1 (см. --help)" ;;
        esac
    done
}

show_help() {
    cat <<'HELP'
GameDock — установщик панели управления игровым хостингом

  sudo ./install.sh [опции]

Поддерживаемые системы:
  Debian  11 (bullseye)   12 (bookworm)   13 (trixie)
  Ubuntu  22.04 (jammy)    24.04 (noble)

  Нужен PHP >= 8.2 и Node.js >= 20. Там, где в дистрибутиве подходящей версии
  нет (Debian 11, Ubuntu 22.04), подключаются packages.sury.org и NodeSource.

Основные:
  --domain <домен>       Домен панели (например panel.example.com)
  --email <email>         Email администратора (логин в панели)
  --name <имя>            Название панели (по умолчанию GameDock)
  --node-mode <режим>     single | manual | auto
                         single — одна нода, manual — выбирает админ, auto — автораспределение
  --runtime <рантайм>     docker | podman | lxc | native
  --with-agent            Установить агент на этой же машине
  --no-nginx              Не ставить и не настраивать nginx
  --no-ssl                Не получать сертификат Let's Encrypt
  --with-tinkoff          Включить приём оплаты через Т-Банк
  --db <mysql|external>   mysql — поставить локально, external — подключить существующую
  --php <версия>          Версия PHP (по умолчанию — лучшая доступная в системе)
  --node-major <N>        Старшая версия Node.js для агента (по умолчанию 20)
  --queue-workers <N>     Количество процессов очереди (по умолчанию 2)
  --dir <путь>            Каталог установки (по умолчанию /opt/gamedock)

phpMyAdmin (веб-доступ к базам MySQL/MariaDB):
  --no-phpmyadmin         Не ставить phpMyAdmin
  --pma-path <путь>       Адрес phpMyAdmin (по умолчанию /phpmyadmin)
  --pma-user <имя>        Имя учётки для входа (по умолчанию gamedock)
  --pma-allow "IP,IP"     Пускать только с этих IP (по умолчанию — с любого)
                         Вход в phpMyAdmin всегда закрыт паролем, который
                         установщик создаёт и печатает в конце.
  -y, --yes               Без диалога, все ответы по умолчанию

Пример (полностью автоматически):
  sudo ./install.sh --domain panel.example.com --email me@example.com --with-agent -y

Если ОС не поддерживается, поставьте панель через docker compose:
  docker compose up -d
HELP
}

# ═══════════════════════════════════════════════════════════════════
# Матрица поддерживаемых систем
# ═══════════════════════════════════════════════════════════════════
#
# Единственный источник правды: SUPPORTED_SYSTEMS. По нему же проверяется
# docs/installation.md (panel/tools/check-distros.cjs), чтобы матрица в
# документации не разъехалась с установщиком.
#
# Формат:  ID|VERSION|CODENAME|PHP_В_ДИСТРИБУТИВЕ
#   PHP_В_ДИСТРИБУТИВЕ — major.minor, который даёт «apt install php-fpm».
#                      Если он ниже MIN_PHP_*, подключается packages.sury.org.
SUPPORTED_SYSTEMS=(
    "debian|11|bullseye|7.4"
    "debian|12|bookworm|8.2"
    "debian|13|trixie|8.4"
    "ubuntu|22.04|jammy|8.1"
    "ubuntu|24.04|noble|8.3"
)

# ── Дистрибутив ────────────────────────────────────────────────────

# Ищет строку матрицы по ID и версии. Печатает поле $2 (1-based) или пусто.
matrix_lookup() {
    local id="$1" version="$2" field="$3" row
    for row in "${SUPPORTED_SYSTEMS[@]}"; do
        IFS='|' read -r r_id r_ver r_code r_php <<<"$row"
        if [[ $r_id == "$id" && $r_ver == "$version" ]]; then
            case $field in
                1) printf '%s' "$r_id" ;;
                2) printf '%s' "$r_ver" ;;
                3) printf '%s' "$r_code" ;;
                4) printf '%s' "$r_php" ;;
            esac
            return 0
        fi
    done
    return 1
}

matrix_has() {
    matrix_lookup "$1" "$2" 1 >/dev/null 2>&1
}

# Список поддерживаемых систем в виде строк «ID version (codename)»
matrix_list() {
    local row
    for row in "${SUPPORTED_SYSTEMS[@]}"; do
        IFS='|' read -r r_id r_ver r_code _r_php <<<"$row"
        printf '%s %s (%s)\n' "$r_id" "$r_ver" "$r_code"
    done
}

# Сравнение версий: version_ge 8.2 8.1 → true
version_ge() {
    [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" == "$2" ]]
}

detect_distro() {
    step "Определяю систему"

    # Подменяется в тестах; в бою — обычный /etc/os-release.
    local os_release="${OS_RELEASE_FILE:-/etc/os-release}"

    if [[ ! -f $os_release ]]; then
        fail "Не найден $os_release — это не Debian/Ubuntu"
        return 1
    fi

    # shellcheck disable=SC1090
    . "$os_release"

    OS_ID="${ID:-unknown}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_CODENAME="${VERSION_CODENAME:-}"
    OS_PRETTY_NAME="${PRETTY_NAME:-${ID:-?} ${VERSION_ID:-?}}"

    local codename
    codename="$(matrix_lookup "$OS_ID" "$OS_VERSION_ID" 3 || true)"

    if [[ -z $codename ]]; then
        fail "$OS_PRETTY_NAME не поддерживается.

Поддерживаются:
$(matrix_list | sed 's/^/  /')

Поддержка других дистрибутивов возможна, но потребует правок скрипта.
Установку в Docker на любой ОС смотрите docker-compose.yml."
        return 1
    fi

    OS_SUPPORTED=1
    OS_DISTRO_PHP="$(matrix_lookup "$OS_ID" "$OS_VERSION_ID" 4)"

    if [[ -z $OS_CODENAME ]]; then
        OS_CODENAME="$codename"
    fi

    if [[ $OS_CODENAME != "$codename" ]]; then
        warn "Кодовое имя из /etc/os-release ($OS_CODENAME) отличается от ожидаемого ($codename)"
    fi

    # Нужен ли сторонний репозиторий PHP
    if ! version_ge "$OS_DISTRO_PHP" "${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}"; then
        OS_NEEDS_SURY=1
    fi

    ok "$OS_PRETTY_NAME — поддерживается"
    log "  PHP в дистрибутиве: ${OS_DISTRO_PHP}, нужно >= ${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}"

    if (( OS_NEEDS_SURY )); then
        warn "PHP в дистрибутиве слишком старый — подключу packages.sury.org (официальный репозиторий PHP)"
    fi
}

# ── cgroups ───────────────────────────────────────────────────────

# cgroup v2 обязателен только для рантайма native. Docker и Podman с v1
# работают, поэтому трогать GRUB ради них нельзя.
ensure_cgroup_v2() {
    if [[ -f /sys/fs/cgroup/cgroup.controllers ]]; then
        ok "cgroup v2 доступен"
        return
    fi

    if [[ $DEFAULT_RUNTIME != "native" ]]; then
        # На Debian 11 по умолчанию cgroup v1. Рантайм $DEFAULT_RUNTIME
        # (docker/podman/lxc) с этим работает — предупреждаем и идём дальше.
        warn "cgroup v1 — рантайм native на этой системе работать не будет"
        log "  Рантайм $DEFAULT_RUNTIME от cgroup v2 не зависит, установка продолжается"
        log "  Чтобы включить v2: добавьте systemd.unified_cgroup_hierarchy=1 в /etc/default/grub"
        return
    fi

    warn "cgroup v1, а выбран рантайм native — без cgroup v2 он не заработает"

    # systemd умеет включать v2 сам, если это умеет ядро
    if [[ -d /sys/fs/cgroup/unified ]]; then
        ok "Найден гибридный /sys/fs/cgroup/unified — systemd переключит систему на v2"
        return
    fi

    fail "Для рантайма native нужен cgroup v2, а система его не имеет.

Что сделать:
  1. echo 'GRUB_CMDLINE_LINUX=\"\$GRUB_CMDLINE_LINUX systemd.unified_cgroup_hierarchy=1\"' \\
       >> /etc/default/grub
  2. update-grub
  3. reboot
  4. запустить установку заново

Либо поставьте панель с рантаймом docker:  --runtime docker"
}

# ═══════════════════════════════════════════════════════════════════
# Проверки
# ═══════════════════════════════════════════════════════════════════

check_root() {
    [[ $EUID -eq 0 ]] || fail "Запускать от root: sudo ./install.sh"
}

# Сколько гигабайт свободно на разделе, который примет указанный каталог.
#
# Сам INSTALL_DIR на чистой системе ещё не создан: его создаёт create_service_user,
# а проверка места идёт раньше. Если спросить df прямо у несуществующего пути,
# он вернёт ошибку, результат окажется пустым, и установщик честно напишет
# «доступно 0 ГБ» на машине с полным диском. Поэтому поднимаемся до ближайшего
# существующего родителя.
#
# Используем df -P: флаг POSIX гарантирует ровно одну строку на файловую систему,
# поэтому «строка 2» — это всегда сводная строка, независимо от ширины терминала.
avail_gb_for() {
    local dir="${1:-/}"
    local parent

    while [[ -n $dir && ! -d $dir ]]; do
        parent="${dir%/*}"
        # корень или каталог без слеша — дальше идти некуда
        [[ -z $parent || $parent == "$dir" ]] && break
        dir="$parent"
    done

    [[ -d $dir ]] || dir="/"

    local avail
    avail=$(df -PBG "$dir" 2>/dev/null | awk 'NR==2 { gsub(/G/, "", $4); print $4 }')

    # Не число (пусто, «-», ошибка) — честно считаем нулём, но вызывающий
    # код увидит, что измерять нечего, и скажет об этом пользователю.
    [[ $avail =~ ^[0-9]+$ ]] || avail=0

    printf '%s' "$avail"
}

check_system() {
    step "Проверка системы"

    [[ $OS_SUPPORTED -eq 1 ]] || fail "Система не определена — сначала detect_distro"

    local kernel
    kernel="$(uname -r)"

    # Проверка свободного места
    local probe_dir avail_gb
    probe_dir="$INSTALL_DIR"
    while [[ -n $probe_dir && ! -d $probe_dir ]]; do
        probe_dir="${probe_dir%/*}"
        [[ -z $probe_dir ]] && probe_dir="/"
    done

    avail_gb="$(avail_gb_for "$INSTALL_DIR")"

    if (( avail_gb < MIN_DISK_GB )); then
        fail "Мало места: на разделе ${probe_dir} свободно ${avail_gb} ГБ, нужно минимум ${MIN_DISK_GB} ГБ.

Каталог установки: ${INSTALL_DIR}
Рекомендуется ${RECOMMENDED_DISK_GB} ГБ — там разместятся панель, база и файлы игр."
    fi

    if (( avail_gb < RECOMMENDED_DISK_GB )); then
        warn "Свободно ${avail_gb} ГБ — установка пройдёт, но для игр лучше ${RECOMMENDED_DISK_GB} ГБ"
    fi

    ok "$OS_PRETTY_NAME, ядро ${kernel}, свободно ${avail_gb} ГБ на ${probe_dir}"
}

# Минимальный набор, без которого установщик не может начать.
#
# Раньше эти утилиты только проверялись, а ставились сильно ниже — на чистой
# Debian (где git и unzip не входят в минимальный образ) установщик упирался
# в «Не найдена утилита git» и выходил, не имея возможности поставить его сам.
# Теперь доставляем недостающее автоматически.
#
# Пакеты и команды — разные списки, и это важно. `command -v ca-certificates`
# не найдёт ничего: ca-certificates — это пакет, а команда называется
# update-ca-certificates. Смешав списки, мы ловили «Не найдена утилита
# ca-certificates» на любой машине, где чего-то не хватало.
BASE_PACKAGES=(ca-certificates curl git tar unzip)
BASE_COMMANDS=(curl git tar unzip)

ensure_base_tools() {
    step "Базовые утилиты"

    local missing=()
    local tool
    for tool in "${BASE_COMMANDS[@]}"; do
        command -v "$tool" >/dev/null 2>&1 || missing+=("$tool")
    done

    if (( ${#missing[@]} == 0 )); then
        ok "Базовые утилиты на месте"
        return 0
    fi

    warn "Не хватает: ${missing[*]} — доустанавливаю"

    export DEBIAN_FRONTEND=noninteractive

    if ! apt-get update -qq; then
        warn "apt-get update не отработал — пробую поставить без обновления индексов"
    fi

    # К недостающему добавляем ca-certificates: без него HTTPS к
    # packages.sury.org и NodeSource не установится.
    local -a need=("${missing[@]}")
    local p
    for p in "${BASE_PACKAGES[@]}"; do
        [[ " ${need[*]} " == *" $p "* ]] || need+=("$p")
    done

    if ! apt-get install -y -qq --no-install-recommends "${need[@]}" </dev/null; then
        fail "Не удалось поставить: ${need[*]}

Поставьте их вручную и запустите установщик снова:
  apt update && apt install -y ${need[*]}"
    fi

    ok "Базовые утилиты установлены"
}

check_deps() {
    step "Проверка зависимостей"

    local cmd
    for cmd in "${BASE_COMMANDS[@]}"; do
        command -v "$cmd" >/dev/null 2>&1 || fail "Не найдена утилита $cmd"
    done

    ok "Все зависимости на месте"
}

# Скопировать каталог, если источник и приёмник не одно и то же место.
#
# Обычное дело, а не редкий случай: когда исходники уже лежат в каталоге
# установки (REPO_DIR == INSTALL_DIR), то PANEL_DIR == REPO_DIR/panel и
# AGENT_DIR == REPO_DIR/agent. Копирование панели в саму себя давало
# «cp: '…/panel/./.' and '…/panel/./.' are the same file» и обрывало
# установку. Для game-images было хуже: там стояло `2>/dev/null || true`,
# ошибка проглатывалась, и каталог молча не обновлялся.
copy_tree() {
    local src="$1" dst="$2" label="${3:-каталог}"
    local src_abs dst_abs

    src_abs="$(cd "$src" 2>/dev/null && pwd -P)" || {
        warn "$label: нет исходников в $src — пропускаю"
        return 1
    }
    mkdir -p "$dst"
    dst_abs="$(cd "$dst" 2>/dev/null && pwd -P)" || {
        warn "$label: не удалось подготовить $dst"
        return 1
    }

    if [[ $src_abs == "$dst_abs" ]]; then
        log "$label: уже на месте ($dst_abs) — копирование не нужно"
        return 0
    fi

    # Хвостовой "/." переносит содержимое, включая скрытые файлы, внутрь
    # приёмника, а не сам каталог.
    cp -a "$src_abs/." "$dst_abs/" || {
        warn "$label: не удалось скопировать $src_abs → $dst_abs"
        return 1
    }
}

detect_repo() {
    step "Определение исходников"

    # Порядок источников важен:
    #   1) уже установленный репозиторий в $INSTALL_DIR;
    #   2) исходники рядом с самим скриптом (локальная сборка из git clone);
    #   3) официальный репозиторий — это нужно для `curl … | sudo bash`,
    #      где скрипт запускается из /dev/stdin и соседей не видно;
    #   4) в крайнем случае спрашиваем у пользователя.
    if [[ -d "$INSTALL_DIR/.git" ]]; then
        REPO_DIR="$INSTALL_DIR"
        ok "Использую локальный репозиторий: $REPO_DIR"
        return
    fi

    if [[ -n ${BASH_SOURCE[0]:-} && -f "${BASH_SOURCE[0]}" ]]; then
        local script_dir
        script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        local candidate
        candidate="$(dirname "$script_dir")"

        if [[ -d "$candidate/panel" && -d "$candidate/agent" ]]; then
            REPO_DIR="$candidate"
            ok "Исходники найдены рядом с установщиком: $REPO_DIR"
            return
        fi
    fi

    local url="$GAMEDOCK_REPO_URL"
    local branch="$GAMEDOCK_BRANCH"

    [[ -n $url ]] || read -rp "URL репозиторий GameDock: " url
    [[ -n $url ]] || fail "Не указан URL репозитория"

    REPO_DIR="$INSTALL_DIR"
    mkdir -p "$REPO_DIR"

    # Клонируем во временный каталог, а не прямо в $REPO_DIR.
    #
    # git отказывается клонировать в непустой каталог, а $INSTALL_DIR на
    # повторном запуске непустой: там уже может лежать game-images от
    # предыдущей неудачной попытки. Обход — клонируем отдельно и переносим
    # содержимое, ничего не удаляя.
    local staging
    staging="$(mktemp -d /tmp/gamedock-clone.XXXXXX)" || {
        fail "Не удалось создать временный каталог для клонирования"
    }

    if [[ -n "$(ls -A "$REPO_DIR" 2>/dev/null)" ]]; then
        warn "Каталог $REPO_DIR не пуст — перенесу исходники поверх, ничего не удаляя"
    fi

    log "Клонирую репозиторий $url…"

    if ! git clone --depth 1 --branch "$branch" "$url" "$staging"; then
        rm -rf "$staging"
        fail "Не удалось клонировать репозиторий

  Адрес: $url
  Ветка: $branch

Частые причины:

  1) Репозиторий приватный. Проверьте, что он открыт, либо положите
     исходники рядом с установщиком — тогда он возьмёт их с диска
     и в интернет не пойдёт.

  2) Адрес неверный. Уточните его и запустите с подстановкой:
       GAMEDOCK_REPO_URL=https://github.com/ВАШ-АККАУНТ/ВАШ-РЕПО.git \\
           bash install.sh ...

  3) Нет доступа в интернет. Тогда скопируйте репозиторий на сервер
     через scp и запустите установщик из его корня:
       scp -r gamedock root@СЕРВЕР:/root/gamedock
       cd /root/gamedock && bash deploy/install.sh ..."
    fi

    # Переносим содержимое клона (включая .git) в каталог установки.
    # Через copy_tree: он и сравнивает канонические пути, и не даёт скопировать
    # каталог сам в себя, если REPO_DIR вдруг совпадёт с временным.
    copy_tree "$staging" "$REPO_DIR" "исходники" || {
        rm -rf "$staging"
        fail "Не удалось перенести исходники в $REPO_DIR — проверьте права и свободное место"
    }

    rm -rf "$staging"
    ok "Исходники развёрнуты в $REPO_DIR"
}

# ═══════════════════════════════════════════════════════════════════
# Установка пакетов
# ═══════════════════════════════════════════════════════════════════

apt_update() {
    log "Обновляю список пакетов…"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
}

# Добавляет компонент репозитория (main/contrib/universe/…) в sources-файл.
# Возвращает 0, если файл изменён, 1 — если компонент уже был.
#
# Поддерживаются оба формата apt:
#   • классический  — `deb http://… jammy main restricted`  (Ubuntu 22.04 и старше);
#   • deb822        — блок `Components: main restricted`  (Ubuntu 24.04 / noble).
# На noble старый парсер молчал бы, и universe/multiverse не включились бы.
apt_add_component() {
    local file="$1" comp="$2"

    # ── deb822: поле Components: ─────────────────────────────────────
    if grep -qE '^[[:space:]]*Components:[[:space:]]' "$file"; then
        if grep -qE "^[[:space:]]*Components:.*\\b${comp}\\b" "$file"; then
            return 1
        fi

        # Дописываем компонент в каждую строку Components:
        sed -i -E "s/^([[:space:]]*Components:[[:space:]]*)(.*)\$/\\1\\2 ${comp}/" "$file"
        return 0
    fi

    # ── классический формат ─────────────────────────────────────────
    # Компонент уже есть в активной строке — ничего не делаем
    if grep -qE "^[[:space:]]*deb(-src)?[[:space:]].*\b${comp}\b" "$file"; then
        return 1
    fi

    # Компонент есть в закомментированной строке — раскомментируем её
    if grep -qE "^[[:space:]]*#[[:space:]]*deb(-src)?[[:space:]].*\b${comp}\b" "$file"; then
        sed -i -E "s|^[[:space:]]*#[[:space:]]*(deb(-src)?[[:space:]].*)$|\1|" "$file"
        return 0
    fi

    # Иначе допишем компонент в конец первой АКТИВНОЙ строки deb.
    # Именно активной: дописывать к закомментированной бессмысленно.
    if grep -qE "^[[:space:]]*deb(-src)?[[:space:]]" "$file"; then
        sed -i -E "0,/^[[:space:]]*(deb(-src)?[[:space:]]+.*)$/s//\1 ${comp}/" "$file"
        return 0
    fi

    return 1
}

# Включает дополнительные компоненты репозитория: без них не находятся
# steamcmd, php-redis и часть заголовков для сборки игр.
#
# Сначала правим sources-файлы сами — это работает и на классическом формате,
# и на deb822, и не зависит от software-properties-common (которого на
# минимальной системе ещё нет на этом шаге). add-apt-repository оставлен
# как запасной путь.
enable_apt_components() {
    log "Включаю дополнительные компоненты репозитория…"

    local -a want
    if [[ $OS_ID == "ubuntu" ]]; then
        want=(main restricted universe multiverse)
    else
        want=(main contrib non-free non-free-firmware)
    fi

    local file comp changed=0

    for file in /etc/apt/sources.list \
                /etc/apt/sources.list.d/*.sources \
                /etc/apt/sources.list.d/*.list; do
        [[ -f $file ]] || continue

        for comp in "${want[@]}"; do
            if apt_add_component "$file" "$comp"; then
                log "  + ${comp} → $(basename "$file")"
                changed=1
            fi
        done
    done

    if [[ $changed -eq 0 ]] && command -v add-apt-repository >/dev/null 2>&1; then
        for comp in "${want[@]}"; do
            add-apt-repository -y "$comp" >/dev/null 2>&1 || true
        done
    fi

    apt_update
    ok "Компоненты репозитория включены"
}

# Пакет ДОСТУПЕН ДЛЯ УСТАНОВКИ?
#
# `apt-cache show` отвечает «есть» и для пакета, который в индексе есть, но
# установить его нельзя: на Debian 13 openjdk-17-jre-headless и steamcmd именно
# такие — строки в индексе есть, а Candidate: (none). Проверяем кандидата.
pkg_available() {
    local candidate
    candidate="$(apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ {print $2; exit}')"
    [[ -n $candidate && $candidate != "(none)" ]]
}

# Первая доступная версия Java.
#
# На Debian 13 (trixie) OpenJDK 17 удалён из репозиториев — остались 21 и 25.
# На Debian 11/12 наоборот, 21 может не быть. Поэтому не фиксируем версию,
# а берём первую, которая реально ставится: 21 предпочтительна (Paper и
# новые версии Minecraft требуют именно её), 17 — запасной вариант.
java_package() {
    local v
    for v in 21 17 25; do
        if pkg_available "openjdk-${v}-jre-headless"; then
            printf 'openjdk-%s-jre-headless' "$v"
            return 0
        fi
    done
    return 1
}

# Имя пакета расширения PHP для текущей $PHP_VERSION.
#
# Каноническое имя — с точкой: php8.3-fpm, php8.3-redis. Именно так их называют
# и дистрибутивы, и packages.sury.org. Но встречаются сборки без точки
# (php83-fpm), поэтому проверяем оба варианта и берём существующий —
# так установщик не зависит от особенностей конкретного репозитория.
# Если не найден ни один, возвращаем каноническое имя: его затем отфильтрует
# общий список «доступных пакетов» и покажет в предупреждении.
#
# ВАЖНО: функцию зовут как $(php_pkg …), поэтому всё, что она печатает,
# становится именем пакета. Диагностика уходит в stderr, а не в stdout.
php_pkg() {
    local ext="$1"
    local dotted="php${PHP_VERSION}-${ext}"
    local flat="php${PHP_VERSION//./}-${ext}"

    if [[ $flat == "$dotted" ]]; then
        printf '%s' "$dotted"
        return
    fi

    if pkg_available "$dotted"; then
        printf '%s' "$dotted"
    elif pkg_available "$flat"; then
        log "  пакет ${ext}: ${dotted} нет, беру ${flat}" >&2
        printf '%s' "$flat"
    else
        printf '%s' "$dotted"
    fi
}

# ── Репозиторий PHP (packages.sury.org) ───────────────────────────
#
# Нужен там, где в дистрибутиве PHP старее 8.2: Debian 11 (7.4), Ubuntu 22.04 (8.1).
# Это официальный репозиторий, который ведёт Debian и Ubuntu.
setup_sury_php() {
    if [[ $OS_NEEDS_SURY -eq 0 && -z $PHP_VERSION ]]; then
        return
    fi

    # Репозиторий уже подключён — не качаем ключ заново. Иначе каждый повторный
    # запуск упирается в сеть, хотя подключать уже нечего.
    if [[ -s /usr/share/keyrings/sury-php.gpg && -s /etc/apt/sources.list.d/sury-php.list ]]; then
        log "Репозиторий packages.sury.org уже подключён"
        return 0
    fi

    log "Подключаю packages.sury.org для PHP…"

    apt-get install -y -qq --no-install-recommends ca-certificates apt-transport-https \
        lsb-release curl gnupg >/dev/null </dev/null

    # Через повторы: если внешний хост не ответит с первого раза, установка
    # не должна обрываться. Плюс скачиваем во временный файл, а не в пайп —
    # при пустом входе gpg падает, а под pipefail это уронило бы весь скрипт.
    local sury_key
    sury_key="$(mktemp)"
    if fetch_with_retry "https://packages.sury.org/php/apt.gpg" "$sury_key"; then
        install -m 0644 "$sury_key" /usr/share/keyrings/sury-php.gpg
    else
        rm -f "$sury_key"
        fail "Не удалось скачать ключ packages.sury.org.

Через него подключается репозиторий PHP. Без него панель работать не будет:
Laravel 11 требует PHP 8.2 или новее, а в самом дистрибутиве бывает 7.4.

Что делать:
  1) Проверьте доступ в интернет и запустите установщик снова.
  2) Если хост недоступен из вашей сети — скачайте ключ на любой машине
     и положите на сервер, затем повторите:
       curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
  3) Узнать заранее, какую версию PHP поставит установщик:
       bash install.sh --php 8.3 --help"
    fi
    rm -f "$sury_key"

    local codename="$OS_CODENAME"
    [[ -n $codename ]] || { warn "Нет кодового имени — репозиторий PHP не подключён"; return; }

    cat >/etc/apt/sources.list.d/sury-php.list <<SURYEOF
deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${codename} main
SURYEOF

    apt_update
    ok "Репозиторий packages.sury.org подключён"
}

# ── Репозиторий Node.js (NodeSource) ───────────────────────────────
#
# Агенту нужен Node >= 20. В дистрибутивах его нет: Debian 11 даёт 12,
# Debian 12 и Ubuntu 24.04 — 18, Ubuntu 22.04 — 12/18.
setup_nodejs_repo() {
    log "Подключаю NodeSource для Node.js ${NODE_MAJOR}.x…"

    apt-get install -y -qq --no-install-recommends ca-certificates curl gnupg >/dev/null </dev/null

    mkdir -p /etc/apt/keyrings
    local ns_key
    ns_key="$(mktemp)"
    if fetch_with_retry "https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key" "$ns_key"; then
        gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg <"$ns_key"
    else
        rm -f "$ns_key"
        warn "Не удалось скачать ключ NodeSource — репозиторий Node.js не подключён"
        return
    fi
    rm -f "$ns_key"
    chmod a+r /etc/apt/keyrings/nodesource.gpg

    local codename="$OS_CODENAME"
    [[ -n $codename ]] || { warn "Нет кодового имени — репозиторий Node.js не подключён"; return; }

    cat >/etc/apt/sources.list.d/nodesource.list <<NODESOURCEEOF
deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main
NODESOURCEEOF

    apt_update
    ok "Репозиторий NodeSource подключён"
}

# Какая версия PHP ставится. Пустая PHP_VERSION = выбрать автоматически.
resolve_php_version() {
    if [[ -n $PHP_VERSION ]]; then
        log "PHP задан явно: $PHP_VERSION"
        return
    fi

    local target="$OS_DISTRO_PHP"

    if (( OS_NEEDS_SURY )); then
        # 8.3 есть для всех пяти поддерживаемых систем
        target="8.3"
    fi

    PHP_VERSION="$target"
    log "Выбрана версия PHP: $PHP_VERSION (в дистрибутиве ${OS_DISTRO_PHP})"
}

install_packages() {
    step "Устанавливаю системные пакеты"

    export DEBIAN_FRONTEND=noninteractive

    resolve_php_version
    enable_apt_components
    setup_sury_php
    setup_nodejs_repo

    # Базовое
    local -a base=(
        curl ca-certificates gnupg apt-transport-https
        # software-properties-common на Debian 13 удалён из репозиториев, и он
        # нам не нужен: enable_apt_components правит sources-файлы напрямую.
        # Поэтому в обязательный список он не входит, а общий фильтр ниже
        # отбросит его молча, если пакет всё же недоступен.
        git unzip zip tar xz-utils bzip2
        jq less vim htop wget
        ufw fail2ban
        # sudo — документированный способ запуска; adduser — создание
        # системного пользователя gamedock. На минимальных образах их нет.
        sudo adduser
    )

    # PHP версионными пакетами: так единообразно для всех дистрибутивов.
    #
    # Имя пакета в дистрибутивах и у packages.sury.org — С ТОЧКОЙ:
    # php8.3-fpm, php8.3-cli. Без точки (php83-fpm) таких пакетов нет.
    # Раньше здесь была подстановка ${PHP_VERSION/./}, из-за чего все
    # PHP-пакеты молча уходили в «не найдены» и установка падала позже.
    local ext
    for ext in fpm cli mysql mbstring xml curl zip gd bcmath intl opcache soap redis; do
        base+=("$(php_pkg "$ext")")
    done

    # База данных и Redis
    if [[ $INSTALL_DB == "mysql" ]]; then
        base+=(mariadb-server mariadb-client)
    fi

    if [[ $INSTALL_REDIS == "yes" ]]; then
        base+=(redis-server redis-tools)
    fi

    # Node.js — агенту
    base+=(nodejs)

    # Всё для игр
    base+=(
        build-essential cmake pkg-config
        libssl-dev libcurl4-openssl-dev libicu-dev
        libsdl2-dev
        screen tmux cron
    )

    # ncurses: на Debian 13 пакет называется libncurses-dev, раньше был
    # libncursesw5-dev. Нужен для нативной сборки CRMP/RAGEMP/MTA.
    if pkg_available libncursesw5-dev; then
        base+=(libncursesw5-dev)
    elif pkg_available libncurses-dev; then
        base+=(libncurses-dev)
    else
        warn "Заголовки ncurses недоступны — нативная сборка CRMP/RAGEMP может не собраться"
    fi

    # Java: версию определяем по тому, что реально ставится. На Debian 13
    # OpenJDK 17 удалён, на Debian 11/12 может не быть 21.
    local java_pkg
    if java_pkg="$(java_package)"; then
        base+=("$java_pkg")
        log "  Java: $java_pkg"
    else
        warn "Ни одна версия OpenJDK не найдена — поставлю вручную (нужна для Minecraft и CS2)"
    fi

    # SteamCMD — для CS2, Rust, Unturned, ARK
    if ! command -v steamcmd >/dev/null 2>&1 && [[ ! -x /usr/games/steamcmd/steamcmd ]]; then
        if pkg_available steamcmd; then
            base+=(steamcmd)
        else
            # На Debian 13 пакет удалён из репозиториев. Установку не роняем:
            # игры на Steam либо ставятся в контейнерах, либо SteamCMD
            # доустанавливается вручную.
            warn "Пакет steamcmd недоступен в репозиториях $OS_PRETTY_NAME"
            warn "  Он нужен для CS2, Rust, Unturned и ARK. Варианты:"
            warn "    • запускать эти игры в контейнерах (рантайм docker) — рекомендуется"
            warn "    • поставить SteamCMD вручную: mkdir -p /usr/games/steamcmd && \\"
            warn "      curl -sL https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz \\"
            warn "      | tar -xz -C /usr/games/steamcmd"
        fi
    fi

    # nginx и сертификаты
    if [[ $WITH_NGINX == "yes" ]]; then
        base+=(nginx certbot python3-certbot-nginx)
    fi

    # Отбрасываем то, чего в репозиториях нет: под set -e одна отсутствующая
    # позиция обрушила бы весь apt-get install
    local -a available=()
    local -a missing=()
    local pkg
    for pkg in "${base[@]}"; do
        if pkg_available "$pkg"; then
            available+=("$pkg")
        else
            missing+=("$pkg")
        fi
    done

    if (( ${#missing[@]} > 0 )); then
        warn "Не найдены в репозиториях и будут пропущены: ${missing[*]}"
    fi

    (( ${#available[@]} > 0 )) || fail "Ни один пакет не найден — проверьте репозитории"

    log "Устанавливаю ${#available[@]} пакетов (это займёт несколько минут)…"

    # force-confdef/confold: при обновлении конфигурационных файлов dpkg по
    # умолчанию показывает вопрос с выбором. Здесь берём версию из пакета и
    # не спрашиваем — иначе установка встанет и будет ждать Enter.
    # apt_quiet_flags подставляется как отдельные слова, поэтому массив
    # строится, а не пишется строкой.
    local -a apt_flags=()
    read -r -a apt_flags <<<"$(apt_quiet_flags)"

    if ! run_logged 5 apt-get install "${apt_flags[@]}" -qq "${available[@]}"; then
        fail "apt-get install не удался. Подробности: $LOG_FILE"
    fi

    install_composer

    ok "Пакеты установлены"
}

# Скачать файл с повторами.
#
# getcomposer.org из части сетей отдаёт «SSL: Handshake timed out», и одной
# попытки мало: внешний хост периодически не отвечает. curl сам повторяет
# дважды, плюс мы делаем три попытки с растущей паузой.
fetch_with_retry() {
    local url="$1" out="$2" attempt

    for attempt in 1 2 3; do
        if curl -fsSL \
            --connect-timeout 15 \
            --max-time 180 \
            --retry 2 \
            --retry-delay 3 \
            --retry-connrefused \
            "$url" -o "$out"; then
            return 0
        fi

        [[ $attempt -lt 3 ]] || break
        warn "  попытка ${attempt}/3 не удалась, повторяю…"
        sleep $((attempt * 3))
    done

    return 1
}

# Composer нужен для panel/composer.json.
#
# Порядок именно такой — сначала пакет из репозитория дистрибутива, и только
# потом официальный установщик с getcomposer.org. На это две причины:
#
#   1. composer есть в main во всех пяти поддерживаемых системах
#      (Debian 11/12/13, Ubuntu 22.04/24.04), и версия оттуда подходит:
#      панели нужен PHP ^8.2 и Laravel 11, то есть composer-runtime-api ^2.2;
#   2. getcomposer.org в сетях с фильтрами отдаёт мусор вместо phar —
#      установщик молча падает с «Failed to decode zlib stream». Проверять
#      подпись полезно только тогда, когда сам файл доехал целым.
#
# Раньше было наоборот, плюс `php installer` вызывался без проверки кода
# возврата — при ошибке `set -e` обрывал установку молча.
install_composer_from_distro() {
    pkg_available composer || return 1

    local -a apt_flags=()
    read -r -a apt_flags <<<"$(apt_quiet_flags)"
    run_logged 3 apt-get install "${apt_flags[@]}" -qq composer || return 1
    command -v composer >/dev/null 2>&1
}

install_composer_from_official() {
    local installer=/tmp/composer-setup.php
    local sigfile=/tmp/composer-setup.sig
    local expected actual

    fetch_with_retry "https://getcomposer.org/installer" "$installer" || {
        warn "getcomposer.org недоступен"
        return 1
    }

    fetch_with_retry "https://composer.github.io/installer.sig" "$sigfile" || {
        warn "Не удалось получить подпись установщика Composer"
        rm -f "$installer"
        return 1
    }

    expected="$(tr -d ' \t\r\n' <"$sigfile")"

    if command -v sha384sum >/dev/null 2>&1; then
        actual="$(sha384sum "$installer" | cut -d' ' -f1)"
    else
        warn "sha384sum недоступен — считаю подпись через PHP"
        actual="$(php -r 'echo hash_file("sha384", $argv[1]);' "$installer" 2>/dev/null || true)"
    fi

    if [[ -z $expected ]]; then
        warn "Пустая подпись — не доверяю установщику и пропускаю"
        rm -f "$installer" "$sigfile"
        return 1
    fi

    if [[ $actual != "$expected" ]]; then
        warn "Подпись установщика не совпала — не доверяю и пропускаю"
        rm -f "$installer" "$sigfile"
        return 1
    fi

    # Проверяем код возврата: при ошибке загрузки phar установщик возвращает
    # ненулевой код, и без проверки set -e оборвал бы скрипт без объяснений.
    if ! php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer; then
        warn "Официальный установщик не отработал — скорее всего, phar пришёл битым"
        warn "  (прокси или фильтр вместо архива отдают HTML, и PHP пишет"
        warn "   «Failed to decode zlib stream»)"
        rm -f "$installer" "$sigfile"
        return 1
    fi

    rm -f "$installer" "$sigfile"
    command -v composer >/dev/null 2>&1
}

install_composer() {
    if command -v composer >/dev/null 2>&1; then
        ok "Composer уже установлен: $(composer --version 2>/dev/null | head -1)"
        return 0
    fi

    log "Устанавливаю Composer…"

    if install_composer_from_distro; then
        ok "Composer установлен из репозитория: $(composer --version 2>/dev/null | head -1)"
        return 0
    fi

    warn "Composer из репозитория не поставился, пробую официальный установщик"
    if install_composer_from_official; then
        ok "Composer установлен: $(composer --version 2>/dev/null | head -1)"
        return 0
    fi

    fail "Composer не установился.

Что делать (любой из вариантов), затем запустите установщик снова:

  1) Из репозитория дистрибутива — самый надёжный путь:
       apt-get update && apt-get install -y composer

  2) Положить phar на место вручную. ВАЖНО: если в ответ приходит не
     архив, а HTML-заглушка прокси, файл будет битым, и Composer не запустится.
     Проверьте, что скачался именно phar:
       curl -fsSL https://getcomposer.org/composer-stable.phar -o /usr/local/bin/composer
       chmod +x /usr/local/bin/composer
       php /usr/local/bin/composer --version

  3) Если getcomposer.org отдаёт мусор — его режет фильтр или провайдер.
     Скачайте phar на машине без фильтра и перенесите на сервер по scp."
}

create_service_user() {
    step "Создаю системного пользователя"

    if id -u "$SERVICE_USER" >/dev/null 2>&1; then
        ok "Пользователь $SERVICE_USER уже существует"
    else
        adduser --system --group --home /home/gamedock --shell /usr/sbin/nologin "$SERVICE_USER"
        ok "Создан пользователь $SERVICE_USER"
    fi

    # Каталоги
    mkdir -p "$INSTALL_DIR" "$STATE_DIR" "$GAME_IMAGES_DIR" \
             /home/gamedock/servers /home/gamedock/backups /home/gamedock/plugins

    chown -R "$SERVICE_USER:$SERVICE_USER" /home/gamedock
    chmod 750 /home/gamedock/servers /home/gamedock/backups
    chmod 755 "$INSTALL_DIR"
}

# ═══════════════════════════════════════════════════════════════════
# База данных и Redis
# ═══════════════════════════════════════════════════════════════════

# Клиент MariaDB: в Debian 13 это mariadb, но mysql тоже есть и привычнее.
db_client() {
    if command -v mariadb >/dev/null 2>&1; then
        printf 'mariadb'
    else
        printf 'mysql'
    fi
}

DB_ROOT_PASS_FILE=/root/.gamedock-db-root.txt
DB_AUTH_MODE=""

# Выполнить SQL от root, подбирая рабочий способ входа.
#
# Способы проверяются по порядку, и первый сработавший запоминается:
#   1) unix_socket — так MariaDB на Debian настроена из коробки;
#   2) пароль из сохранённого файла — если его ставил прошлый запуск;
#   3) только что сгенерированный пароль.
#
# Раньше было так: установщик выполнял ALTER USER … IDENTIFIED BY, после
# чего root начинал требовать пароль, а все следующие запросы шли БЕЗ него.
# MariaDB отклонял их, и set -e ронял установку молча — кроме
# предупреждения про пароль ничего не печаталось. А сам пароль нигде не
# сохранялся, и вернуть доступ можно было только через skip-grant-tables.
db_root() {
    local sql="$1" c
    c="$(db_client)"

    if [[ -z $DB_AUTH_MODE ]]; then
        if $c -u root -e "SELECT 1;" >/dev/null 2>&1; then
            DB_AUTH_MODE=socket
        elif [[ -f $DB_ROOT_PASS_FILE ]]; then
            # shellcheck disable=SC1090
            . "$DB_ROOT_PASS_FILE"
            if [[ -n ${DB_ROOT_PASS:-} ]] &&
               $c -u root -p"$DB_ROOT_PASS" -e "SELECT 1;" >/dev/null 2>&1; then
                DB_AUTH_MODE=pass
            fi
        fi
    fi

    # Разделителя «--» здесь быть не должно: клиент mariadb его не понимает
    # и молча уходит в ошибку. Проверка выше использует -e, значит и выполнение
    # обязано использовать -e — иначе db_root_works проходит, а сам запрос
    # возвращает код 1.
    case "$DB_AUTH_MODE" in
        socket) $c -u root -e "$sql" ;;
        pass)   $c -u root -p"$DB_ROOT_PASS" -e "$sql" ;;
        *)      return 1 ;;
    esac
}

db_root_works() {
    db_root "SELECT 1;" >/dev/null 2>&1
}

setup_database() {
    step "Настройка базы данных"

    if [[ $INSTALL_DB == "external" ]]; then
        log "Используется внешняя БД — пропускаю установку"
        return
    fi

    systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql

    # ── root-пароль ────────────────────────────────────────────
    # Сохранённый переиспользуем: новый пароль при каждом запуске ломал бы
    # и существующие подключения, и сам доступ.
    if [[ -z ${DB_ROOT_PASS:-} && -f $DB_ROOT_PASS_FILE ]]; then
        # shellcheck disable=SC1090
        . "$DB_ROOT_PASS_FILE"
    fi

    if ! db_root_works; then
        if [[ -z ${DB_ROOT_PASS:-} ]]; then
            DB_ROOT_PASS="$(gen_password 28)"
        fi

        if db_root "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';"; then
            DB_AUTH_MODE=pass
            umask 077
            {
                printf '# root от MariaDB. Установщик GameDock.\n'
                printf '# Нужен для обслуживания и для входа в phpMyAdmin от root.\n'
                printf '# Права 600, владелец root.\n'
                printf 'DB_ROOT_PASS=%q\n' "$DB_ROOT_PASS"
            } >"$DB_ROOT_PASS_FILE"
            ok "Пароль root от базы задан и сохранён: $DB_ROOT_PASS_FILE"
        else
            fail "Нет доступа к MySQL от root, и пароль установить не удалось.

У MariaDB на Debian учётка root ходит через unix_socket, а не по паролю. Если
пароль уже был установлен прошлым запуском и потерян, вернуть доступ можно
только вручную:

  systemctl stop mariadb
  systemctl start mariadb --skip-grant-tables --skip-networking
  mysql -e \"FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY 'НОВЫЙ_ПАРОЛЬ';\"
  systemctl restart mariadb

После этого запустите установку снова: пароль сохранится в
$DB_ROOT_PASS_FILE, и вход будет работать."
        fi
    else
        ok "Доступ к MySQL от root есть (${DB_AUTH_MODE:-pass})"
    fi

    DB_PASS="$(gen_password 28)"

    db_root "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" ||
        fail "Не удалось создать базу $DB_NAME"

    # CREATE USER IF NOT EXISTS при уже существующем пользователе молча
    # ничего не делает — пароль остаётся прежним. А .env ниже получает
    # свежесгенерированный DB_PASS, и на повторном запуске установщика они
    # расходятся: миграции падают с «Access denied for user gamedock@localhost».
    # Поэтому создаём, а затем ALTER USER — он всегда приводит пароль в
    # соответствие с .env.
    db_root "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" ||
        fail "Не удалось создать пользователя $DB_USER"
    db_root "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" ||
        fail "Не удалось задать пароль пользователю $DB_USER"

    db_root "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';" ||
        fail "Не удалось выдать права пользователю $DB_USER"
    db_root "FLUSH PRIVILEGES;"

    ok "База ${DB_NAME} создана, пользователь ${DB_USER}"
}

setup_redis() {
    step "Настройка Redis"

    if [[ $INSTALL_REDIS != "yes" ]]; then
        log "Redis не устанавливается — укажите внешний в .env"
        return
    fi

    REDIS_PASS="$(gen_hex 32)"

    systemctl enable --now redis-server

    cat >/etc/redis/redis.conf.gamedock <<REDISEOF
# Дополнение GameDock к /etc/redis/redis.conf
bind 127.0.0.1 ::1
requirepass ${REDIS_PASS}
maxmemory 512mb
maxmemory-policy allkeys-lru
appendonly no
save 900 1
REDISEOF

    grep -q "redis.conf.gamedock" /etc/redis/redis.conf 2>/dev/null \
        || echo "include /etc/redis/redis.conf.gamedock" >> /etc/redis/redis.conf

    systemctl restart redis-server

    ok "Redis настроен (localhost:6379)"
}

# ═══════════════════════════════════════════════════════════════════
# Установка панели

# ═══════════════════════════════════════════════════════════════════
# Вспомогательные функции
# ═══════════════════════════════════════════════════════════════════

# Переводит ответ диалога (yes/no, 1/0, true/false, on/off) в строку,
# которую понимает env() в Laravel. Преобразуются только «true» и
# «false»: значение «no» осталось бы строкой, и (bool)"no" дал бы true —
# то есть выключенный флаг оказался бы включённым.
yn_bool() {
    case "${1,,}" in
        yes|y|1|true|on) printf true ;;
        *) printf false ;;
    esac
}

# Способ оплаты включается, только если на него согласились И ключи
# введены. Иначе панель предлагает способ, который не сработает.
pay_enabled() {
    local answer="$1" keys="$2"
    if [ "$(yn_bool "$answer")" != true ]; then
        printf false
        return
    fi
    if [ -z "$keys" ]; then
        warn "Способ оплаты выбран, но ключи не введены — оставляю его выключенным"
        printf false
        return
    fi
    printf true
}

# Общий флаг оплаты: true, если доступен хоть один способ.
any_pay_enabled() {
    if [ "$(yn_bool "$PAY_MANUAL")" = true ] || [ "$(yn_bool "$PAY_YOOKASSA")" = true ]; then
        printf true
        return
    fi
    printf false
}

# ═══════════════════════════════════════════════════════════════════

setup_php() {
    step "Настройка PHP"

    verify_php_installed

    local ini_dir="/etc/php/$PHP_VERSION/fpm/conf.d"
    if [[ ! -d $ini_dir ]]; then
        warn "Каталог $ini_dir не найден"
        fail "PHP $PHP_VERSION не установлен. Проверьте: dpkg -l | grep php"
    fi

    cat >"$ini_dir/99-gamedock.ini" <<'INI'
; GameDock — настройки PHP
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_time = 300
max_input_vars = 5000

; Ресурсы под Symfony/Laravel
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.save_comments = 1

realpath_cache_size = 4096K
realpath_cache_ttl = 600

; Безопасность
expose_php = Off
display_errors = Off
INI

    # FPM-пул под панель
    cat >/etc/php/$PHP_VERSION/fpm/pool.d/gamedock.conf <<POOL
[gamedock]
user = ${SERVICE_USER}
group = ${SERVICE_GROUP}
listen = /run/php/gamedock-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

php_admin_value[error_log] = /var/log/php/gamedock-error.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 512M
POOL

    mkdir -p /run/php /var/log/php
    chown www-data:www-data /run/php

    # opcache в CLI
    if [[ -d /etc/php/$PHP_VERSION/cli/conf.d ]]; then
        cp "$ini_dir/99-gamedock.ini" /etc/php/$PHP_VERSION/cli/conf.d/ 2>/dev/null || true
    fi

    systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || systemctl restart php-fpm

    ok "PHP $PHP_VERSION настроен"
}

# Сверяет PHP_VERSION с тем, что реально встало, и сверяет версию с требуемой.
# Без этого конфиг nginx и пул FPM уехали бы в сторону от установленного PHP —
# ровно тот класс ошибок, что ловится только в проде.
verify_php_installed() {
    local -a candidates=()

    # Какие версии PHP вообще есть в системе
    local dir
    for dir in /etc/php/*/; do
        [[ -d $dir ]] || continue
        local v
        v="$(basename "$dir")"
        [[ $v =~ ^[0-9]+\.[0-9]+$ ]] && candidates+=("$v")
    done

    if [[ -n $PHP_VERSION ]] && printf '%s\n' "${candidates[@]}" | grep -qx "$PHP_VERSION"; then
        :
    else
        # Подставляем то, что реально нашлось
        local fallback
        fallback="$(printf '%s\n' "${candidates[@]}" | sort -V | tail -1)"

        if [[ -n $PHP_VERSION && -n $fallback ]]; then
            warn "Ожидался PHP $PHP_VERSION, но в системе $fallback — настраиваю $fallback"
        fi

        PHP_VERSION="$fallback"
    fi

    [[ -n $PHP_VERSION ]] || fail "PHP не найден. Установите вручную: apt install php${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}-fpm"

    if ! version_ge "$PHP_VERSION" "${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}"; then
        fail "Нужен PHP >= ${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}, а установлен ${PHP_VERSION} (Laravel 11 не запустится)"
    fi

    # Расширения, без которых панель не поднимется.
    #
    # Список прежде содержал cli, fpm, mysql и opcache, и это были ложные
    # срабатывания: cli и fpm — не расширения, а SAPI, расширения с таким
    # именем не существует; mysql удалён из PHP начиная с 7.0 (нужен
    # pdo_mysql); opcache для SAPI cli штатно выключен. Панель сообщала
    # «PHP без расширений: cli fpm mysql opcache», а следующей строкой —
    # «все нужные расширения на месте».
    local -a required=(mbstring xml curl zip gd bcmath intl pdo_mysql \
                       fileinfo tokenizer ctype openssl exif)
    local -a missing=()
    local ext

    for ext in "${required[@]}"; do
        if ! php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
            missing+=("$ext")
        fi
    done

    if (( ${#missing[@]} > 0 )); then
        warn "PHP ${PHP_VERSION} без расширений: ${missing[*]}"
        warn "Установите их и повторите настройку:"
        for ext in "${missing[@]}"; do
            printf '     apt-get install -y php%s-%s\n' "$PHP_VERSION" "$ext" | sed 's/^/  /'
        done
        fail "Панель без этих расширений не запустится. Установите их и прогоните установку снова."
    fi

    ok "PHP ${PHP_VERSION} найден, все нужные расширения на месте"
}


# Создаёт служебные каталоги Laravel. В репозитории их нет — только
# .gitignore, — и composer install падает на post-autoload-dump, требуя
# bootstrap/cache. Права выдаём пользователю панели: composer и artisan
# пишут туда от его имени.
prepare_panel_dirs() {
    local d
    for d in \
        "$PANEL_DIR/bootstrap/cache" \
        "$PANEL_DIR/storage/app/public" \
        "$PANEL_DIR/storage/framework/cache/data" \
        "$PANEL_DIR/storage/framework/sessions" \
        "$PANEL_DIR/storage/framework/testing" \
        "$PANEL_DIR/storage/framework/views" \
        "$PANEL_DIR/storage/logs" \
        "$PANEL_DIR/public/build"
    do
        mkdir -p "$d" || warn "Не удалось создать $d"
    done

    chown -R "$SERVICE_USER:$SERVICE_USER" \
        "$PANEL_DIR/bootstrap/cache" \
        "$PANEL_DIR/storage" \
        "$PANEL_DIR/public/build" 2>/dev/null || true

    chmod -R ug+rwx "$PANEL_DIR/bootstrap/cache" "$PANEL_DIR/storage" 2>/dev/null || true

    ok "Служебные каталоги Laravel созданы"
}

# Копирует исходники панели и создаёт служебные каталоги.
# Отделён от install_panel_deps, чтобы .env успевал появиться между ними.
deploy_panel_sources() {
    step "Разворачиваю панель"


    mkdir -p "$PANEL_DIR"
    copy_tree "$REPO_DIR/panel" "$PANEL_DIR" "панель" \
        || fail "Не удалось развернуть панель из $REPO_DIR/panel"
    chown -R "$SERVICE_USER:$SERVICE_USER" "$PANEL_DIR"

    # Каталоги, которые Laravel создаёт сам при развёртывании, а в
    # репозитории их нет — только .gitignore. Без них composer install
    # падает на post-autoload-dump: «The bootstrap/cache directory must be
    # present and writable».
    prepare_panel_dirs

    cd "$PANEL_DIR"


    ok "Исходники панели на месте"
}

# Ставит зависимости PHP и JS. Запускается ПОСЛЕ setup_env:
# composer install вызывает artisan package:discover, который загружает
# приложение и читает .env — без корректного .env он падает ещё здесь.
install_panel_deps() {
    step "Ставлю зависимости панели"

    log "Ставлю зависимости Composer…"
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && composer install --no-dev --optimize-autoloader --no-interaction" 2>&1 | tail -8

    if [[ ! -f vendor/autoload.php ]]; then
        fail "composer install не удался. Проверьте, что панели доступен интернет
для загрузки пакетов с repo.packagist.org и github.com, и что пользователю
$SERVICE_USER хватает прав на запись в $PANEL_DIR."
    fi

    # Фронтенд
    #
    # npm ci требует package-lock.json и без него просто падает — панель
    # остаётся без стилей и скриптов. Поэтому при наличии lock-файла
    # используем ci (воспроизводимая установка), а без него install.
    log "Собираю фронтенд…"
    local npm_install="npm install"
    if [[ -f "$PANEL_DIR/package-lock.json" ]]; then
        npm_install="npm ci"
    else
        warn "package-lock.json нет — ставлю через npm install вместо npm ci"
    fi

    if su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && $npm_install --silent --no-audit --no-fund" 2>&1 | tail -5; then
        su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && npm run build" 2>&1 | tail -5 \
            || warn "Сборка ассетов не удалась — интерфейс будет без стилей"
    else
        warn "Установка зависимостей фронтенда не удалась — интерфейс будет без стилей"
    fi

    # Каталоги
    # storage:link падает с «The [public/storage] link already exists» при
    # повторном запуске, когда symlink уже создан. Линк в suchom случае
    # просто пересоздаём, а не считаем ошибкой.
    if [[ -L "$PANEL_DIR/public/storage" || -e "$PANEL_DIR/public/storage" ]]; then
        rm -f "$PANEL_DIR/public/storage"
    fi
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan storage:link" 2>&1 | tail -2 \
        || warn "Не удалось создать ссылку public/storage — загруженные файлы будут недоступны"
    chown -R "$SERVICE_USER:$SERVICE_USER" storage bootstrap/cache
    chmod -R ug+rwx storage bootstrap/cache

    ok "Панель развёрнута в $PANEL_DIR"

    ok "Зависимости панели установлены"
}

setup_env() {
    step "Создаю .env"

    cd "$PANEL_DIR"

    local app_key
    app_key="base64:$(openssl rand -base64 32)"

    # Настройки из диалога.
    #
    # Всё, что пришло от пользователя, пишем в кавычках. Без них любое
    # значение с пробелом ломает разбор .env: Laravel падает с «Failed to
    # parse dotenv file. Encountered unexpected whitespace at […]», и это
    # происходит уже на composer install, до миграций.
    cat >.env <<ENVEOF
APP_NAME="${PANEL_NAME}"
APP_ENV=production
APP_KEY=${app_key}
APP_DEBUG=false
APP_URL="https://${PANEL_DOMAIN}"
APP_TIMEZONE=Europe/Moscow
APP_LOCALE=ru
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=${REDIS_PASS}
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=10080
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@${PANEL_DOMAIN}"
MAIL_FROM_NAME="${PANEL_NAME}"

# Режимы работы
GD_NODE_MODE=${NODE_MODE}
GD_RUNTIME=${DEFAULT_RUNTIME}
GD_DOCKER_SOCKET=/var/run/docker.sock
GD_SERVERS_ROOT=/home/gamedock/servers
GD_BACKUPS_ROOT=/home/gamedock/backups
GD_SYSTEM_USER="${SERVICE_USER}"
GD_SYSTEM_GROUP="${SERVICE_GROUP}"
GD_AGENT_INBOUND=true

# Маркетинг: ответы диалога установщика. Именно true/false — только эти
# значения Laravel превращает в bool.
GD_PROMO_DISCOUNT=$(yn_bool "$PROMO_DISCOUNT")
GD_PROMO_DURATION=$(yn_bool "$PROMO_DURATION")
GD_PROMO_BONUS=$(yn_bool "$PROMO_BONUS")
GD_REFERRAL=$(yn_bool "$REFERRAL")
GD_SECRET_CODES=$(yn_bool "$SECRET_CODES")
GD_TRIAL=$(yn_bool "$TRIAL")
GD_TRIAL_DAYS=${TRIAL_DAYS:-3}

GD_TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN}"
GD_TELEGRAM_ADMIN_CHAT_ID="${TELEGRAM_ADMIN_CHAT}"

# Оплата. Общий флаг включается, если доступен хотя бы один способ.
# Провайдер включаем только если ключи от него действительно введены:
# включённый, но не настроенный способ подводит клиента к ошибке оплаты.
GD_PAY_YOOKASSA=$(pay_enabled "$PAY_YOOKASSA" "$YOOKASSA_SHOP_ID")
GD_PAY_TINKOFF=$(pay_enabled "$PAY_TINKOFF" "$TINKOFF_TERMINAL")
GD_PAY_CRYPTOBOT=$(pay_enabled "$PAY_CRYPTOBOT" "$CRYPTOBOT_TOKEN")
GD_PAY_MANUAL=$(yn_bool "$PAY_MANUAL")
GD_PAY_WALLET=true
GD_PAYMENTS_ENABLED=$(any_pay_enabled)
GD_YOOKASSA_SHOP_ID="${YOOKASSA_SHOP_ID}"
GD_YOOKASSA_SECRET_KEY="${YOOKASSA_SECRET}"
GD_TINKOFF_TERMINAL_KEY="${TINKOFF_TERMINAL}"
GD_TINKOFF_PASSWORD="${TINKOFF_PASSWORD}"
GD_CRYPTOBOT_TOKEN="${CRYPTOBOT_TOKEN}"
GD_PAYMENT_SUCCESS_URL="https://${PANEL_DOMAIN}/payment/success"
GD_PAYMENT_CANCEL_URL="https://${PANEL_DOMAIN}/payment/cancel"

GD_BRAND_NAME="${PANEL_NAME}"
GD_SUPPORT_EMAIL="${SUPPORT_EMAIL}"
ENVEOF

    chown "$SERVICE_USER:$SERVICE_USER" .env
    chmod 640 .env

    ok ".env создан"
}

run_migrations() {
    step "Миграции и начальные данные"

    cd "$PANEL_DIR"

    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan migrate --force --no-interaction" 2>&1 | tail -15

    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan config:clear && php artisan route:clear && php artisan view:clear" >/dev/null

    ok "Миграции выполнены (каталог игр, тарифы, тестовый тариф)"
}

# ═══════════════════════════════════════════════════════════════════
# Конфигурация панели (config/hosting.php) по ответам диалога
# ═══════════════════════════════════════════════════════════════════

apply_hosting_config() {
    step "Настраиваю режимы панели по вашим ответам"

    cd "$PANEL_DIR"

    # Настройки передаются через .env, который создал setup_env, а читаются
    # в config/hosting.php через env().
    #
    # Раньше здесь был PHP-скрипт с preg_replace, который правил сам
    # config/hosting.php по регулярным выражениям. Это оказалось хрупким
    # способом: одна из подстановок съедала три строки блока 'trial' и ломала
    # синтаксис файла, после чего падал любой artisan — установщик завершался
    # с кодом 1 и без единого сообщения. Правкой PHP-файла установщику
    # заниматься нечего.
    log "  node_mode=$NODE_MODE runtime=$DEFAULT_RUNTIME промо=$([ "$PROMO_DISCOUNT" = yes ] && echo да || echo нет) \
рефералка=$([ "$REFERRAL" = yes ] && echo да || echo нет) коды=$([ "$SECRET_CODES" = yes ] && echo да || echo нет) \
тест=$([ "$TRIAL" = yes ] && echo "${TRIAL_DAYS} дн" || echo нет)"

    # Проверяем, что конфиг вообще читается. Раньше этот вызов шёл с
    # >/dev/null 2>&1, и его провал ронял установку молча.
    local clear_out
    if ! clear_out="$(su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan config:clear" 2>&1)"; then
        fail "Панель не читает свою конфигурацию:

$clear_out

Обычно это синтаксическая ошибка в config/hosting.php. Проверьте:
  php -l $PANEL_DIR/config/hosting.php"
    fi

    ok "Режимы панели настроены"
}


# ═══════════════════════════════════════════════════════════════════
# phpMyAdmin
# ═══════════════════════════════════════════════════════════════════

# Приводит путь к виду /phpmyadmin — без слэшей по краям и с ведущим.
# Пустой или «/» запрещаем: это означало бы, что phpMyAdmin отдаётся на весь
# сайт и перехватывает панель.
normalize_pma_path() {
    local p="$1"
    p="${p// /}"
    p="${p#/}"
    p="${p%/}"

    [[ -n $p ]] || p="phpmyadmin"
    [[ $p =~ ^[A-Za-z0-9._/-]+$ ]] || p="phpmyadmin"

    # Отсекаем пути, которые nginx всё равно не отдаст на phpMyAdmin
    case "$p" in
        phpmyadmin|assets|storage|panel|api|login|logout) p="phpmyadmin" ;;
    esac

    printf '/%s' "$p"
}

# Учётная запись для входа в phpMyAdmin.
#
# Это не база данных: отдельная пара «логин-пароль» в htpasswd, чтобы
# интерфейс нельзя было достать перебором по самой базе. Пароль генерируется
# один раз и хранится в файле с правами 600 — при повторном запуске
# установщика не меняется, иначе ссылка из итогового отчёта перестала бы
# работать.
phpmyadmin_credentials() {
    local user="$1" pass hash

    if [[ -f $PHPMYADMIN_CREDS ]]; then
        # shellcheck disable=SC1090
        . "$PHPMYADMIN_CREDS"
        user="${PMA_USER:-$user}"
        pass="${PMA_PASS:-}"
    fi

    if [[ -z $pass ]]; then
        # hex — чтобы не вышло кавычек или служебных символов, которые
        # сломали бы htpasswd и сам пароль
        pass="$(openssl rand -hex 12)"
        umask 077
        {
            printf '# Учётная запись для входа в phpMyAdmin. Не удаляйте,\n'
            printf '# пока хотите вход под тем же паролем.\n'
            printf 'PMA_USER=%q\n' "$user"
            printf 'PMA_PASS=%q\n' "$pass"
        } >"$PHPMYADMIN_CREDS"
        chmod 600 "$PHPMYADMIN_CREDS"
    fi

    # nginx понимает crypt(3), а htpasswd из apache2-utils в системе может
    # не быть — поэтому хэш делаем openssl
    hash="$(openssl passwd -6 "$pass" 2>/dev/null)" || return 1

    umask 077
    printf '%s:%s\n' "$user" "$hash" >"$PHPMYADMIN_HTPASSWD"
    chmod 640 "$PHPMYADMIN_HTPASSWD"
    chown root:www-data "$PHPMYADMIN_HTPASSWD" 2>/dev/null || true

    printf '%s\n%s\n' "$user" "$pass"
}

# Пишет конфиг nginx для phpMyAdmin.
#
# Отдельным файлом, а не в основной vhost: так его можно перегенерировать
# независимо и не трогать большой heredoc панели.
# Какой сокет PHP-FPM использовать.
#
# Пул gamedock кладёт сокет в /run/php/gamedock-fpm.sock. Если его ещё нет
# (например, php-fpm не перезапускался), берём сокет версии из дистрибутива.
# Значение нужно и vhost'у панели, и сниппету phpMyAdmin, поэтому оно
# глобальное, а не локальная переменная внутри setup_nginx.
resolve_fpm_socket() {
    local name="gamedock-fpm.sock"
    [[ -S "/run/php/$name" ]] || name="php${PHP_VERSION}-fpm.sock"
    printf '%s' "$name"
}

phpmyadmin_snippet() {
    local path="$1"
    local -a rules=()
    local ip entry
    local socket
    socket="$(resolve_fpm_socket)"

    # allow/deny вставляем внутрь location. Список IP не задан — доступ
    # открыт всем, но по паролю; это осознанный выбор, а не недосмотр.
    if [[ -n $PHPMYADMIN_ALLOW ]]; then
        IFS=',' read -ra parts <<<"$PHPMYADMIN_ALLOW"
        for ip in "${parts[@]}"; do
            entry="$(printf '%s' "$ip" | tr -d '[:space:]')"
            [[ -n $entry ]] || continue
            rules+=("    allow ${entry};")
        done
        rules+=("    deny all;")
    else
        rules+=("    # Список IP не задан: вход открыт с любого адреса,")
        rules+=("    # но защищён паролем nginx (htpasswd).")
    fi

    mkdir -p "$(dirname "$PHPMYADMIN_SNIPPET")"

    # Heredoc без кавычек — подставляются переменные. Все доллары nginx
    # экранированы как \$, иначе bash попытается их разобрать.
    cat >"$PHPMYADMIN_SNIPPET" <<PMAEOF
# phpMyAdmin — веб-интерфейс к базам MySQL/MariaDB.
# Создаётся установщиком GameDock, правьте через --pma-* при установке.
# Подключается из vhost панели строкой
#   include $PHPMYADMIN_SNIPPET;

# Без завершающего слэша браузер не поймёт, куда смотреть
location = ${path} {
    return 301 ${path}/;
}

# Префикс ^~: без него запросы к phpMyAdmin перехватил бы общий
# «location ~ \.php\$ { return 404; }» ниже в конфиге панели.
location ^~ ${path}/ {
    # root, а не alias: тогда \$fastcgi_script_name сам даёт путь
    # /usr/share/phpmyadmin/… и SCRIPT_FILENAME собирается без
    # хрупкой подстановки вручную.
    root /usr/share;
    index index.php;

    location ~ \.php\$ {
        include fastcgi_params;
        # Прямо в сокет, а не через upstream gamedock_php: имя upstream живёт
        # в vhost панели, и сниппет перестал бы работать в любом другом
        # месте — в том числе в тестовом стенде.
        fastcgi_pass unix:/run/php/${socket};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT /usr/share/phpmyadmin;

        # phpMyAdmin не должен видеть файлы панели и служебные файлы
        # системы. Но /usr/share/php обязателен: Debian-пакет выносит
        # туда общие библиотеки (Composer/CaBundle, PhpMyAdmin/SqlParser,
        # FastRoute и другие) и подключает их из autoload.php. Без этого
        # пути phpMyAdmin падает с 500: «open_basedir restriction in
        # effect. File(/usr/share/php/Composer/CaBundle/autoload.php)».
        # /etc/ssl/certs нужен для подключения к базе по TLS,
        # каталог сессий — чтобы phpMyAdmin смог сохранять настройки.
        fastcgi_param PHP_ADMIN_VALUE "open_basedir=/usr/share/phpmyadmin:/usr/share/php:/etc/phpmyadmin:/var/lib/phpmyadmin:/etc/ssl/certs:/var/lib/php/sessions:/var/www/certbot:/tmp";
        fastcgi_read_timeout 300;
    }

    # Точечные файлы и служебные каталоги наружу не отдаём
    location ~ /\.(?!well-known) {
        deny all;
    }

    # Вход закрыт паролем nginx — это отдельная учётка, не база данных
    auth_basic "GameDock — phpMyAdmin";
    auth_basic_user_file $PHPMYADMIN_HTPASSWD;

$(printf '%s\n' "${rules[@]}")

    access_log /var/log/nginx/phpmyadmin-access.log;
    error_log  /var/log/nginx/phpmyadmin-error.log;
}
PMAEOF

    # Единственная настоящая ссылка, по которой phpMyAdmin доступен.
    # Ещё раз: без auth_basic ниже интерфейс был бы открыт всему интернету.
    chmod 644 "$PHPMYADMIN_SNIPPET"
}

# Заглушка, чтобы include в vhost никогда не ломал nginx.
phpmyadmin_snippet_stub() {
    mkdir -p "$(dirname "$PHPMYADMIN_SNIPPET")"
    cat >"$PHPMYADMIN_SNIPPET" <<'STUBEOF'
# phpMyAdmin отключён при установке (--no-phpmyadmin).
# Файл создан, чтобы include в конфиге панели оставался рабочим.
STUBEOF
}

install_phpmyadmin() {
    local requested="$PHPMYADMIN_PATH"
    local path
    path="$(normalize_pma_path "$requested")"

    # normalize_pma_path вызывается в подстановке, поэтому предупредить
    # внутри неё нельзя — весь вывод функции стал бы значением $path.
    # Сообщаем здесь, если путь пришлось заменить.
    if [[ $path != "/${requested#/}" ]]; then
        warn "Путь phpMyAdmin «${requested}» занят или некорректен — публикую по $path"
    fi

    if [[ $WITH_PHPMYADMIN != "yes" ]]; then
        log "phpMyAdmin пропущен (--no-phpmyadmin)"
        phpmyadmin_snippet_stub
        return 0
    fi

    if [[ $WITH_NGINX != "yes" ]]; then
        warn "phpMyAdmin публикуется через nginx, а он отключён — пропускаю"
        phpmyadmin_snippet_stub
        return 0
    fi

    step "Ставлю phpMyAdmin"

    if ! pkg_available phpmyadmin; then
        warn "Пакет phpmyadmin недоступен в $OS_PRETTY_NAME — пропускаю"
        phpmyadmin_snippet_stub
        return 0
    fi

    # ── Preseed ───────────────────────────────────────────────
    # У пакета ровно один вопрос: phpmyadmin/reconfigure-webserver,
    # multiselect с вариантами apache2 и lighttpd. nginx среди них
    # нет, поэтому не выбираем ничего — конфиг для веб-сервера мы всё
    # равно пишем свой. Проверено на Debian 13: с этой строкой
    # установка идёт без единого вопроса.
    debconf-set-selections <<'PMACONF' 2>/dev/null || true
phpmyadmin phpmyadmin/reconfigure-webserver multiselect
PMACONF

    # --no-install-recommends здесь обязателен, а не аккуратностью.
    # В Recommends пакета стоит «libapache2-mod-php | lighttpd | nginx |
    # php-fpm | httpd», и без этого флага apt ставит Apache, который
    # занимает порт 80 и конфликтует с нашим nginx. Проверено:
    # с флагом не ставится ничего лишнего, без него — Apache и PHP 8.5
    # из Debian рядом с нашим 8.4 с packages.sury.org.
    if ! run_logged 3 apt-get install -y -qq --no-install-recommends phpmyadmin; then
        warn "Не удалось поставить phpMyAdmin — панель продолжит без него"
        phpmyadmin_snippet_stub
        return 0
    fi

    if [[ ! -f /usr/share/phpmyadmin/index.php ]]; then
        warn "phpMyAdmin стоит, но /usr/share/phpmyadmin/index.php не найден — пропускаю"
        phpmyadmin_snippet_stub
        return 0
    fi

    # ── Права доступа к конфигу ───────────────────────────────
    # Пул gamedock работает от пользователя gamedock, а файлы
    # phpMyAdmin лежат с правами 0640 root:www-data. Без членства
    # в www-data пул не прочитает ни базу настроек, ни ключ
    # шифрования — и вход не заработает.
    if ! id -nG "$SERVICE_USER" 2>/dev/null | tr ' ' '\n' | grep -qx www-data; then
        usermod -aG www-data "$SERVICE_USER" \
            || warn "Не удалось добавить $SERVICE_USER в группу www-data"
    fi

    # ── Учётная запись и конфиг nginx ─────────────────────────
    local creds
    if ! creds="$(phpmyadmin_credentials "$PHPMYADMIN_USER")"; then
        warn "Не удалось создать пароль для phpMyAdmin — интерфейс не публикую"
        phpmyadmin_snippet_stub
        return 0
    fi

    phpmyadmin_snippet "$path"

    ok "phpMyAdmin: https://${PANEL_DOMAIN}${path}/"
    PHPMYADMIN_URL="https://${PANEL_DOMAIN}${path}/"
    # shellcheck disable=SC1090
    . "$PHPMYADMIN_CREDS"
}

# ═══════════════════════════════════════════════════════════════════
# nginx и SSL
# ═══════════════════════════════════════════════════════════════════

setup_nginx() {
    if [[ $WITH_NGINX != "yes" ]]; then
        warn "nginx пропущен — настройте веб-сервер самостоятельно"
        return
    fi

    step "Настраиваю nginx"

    # Тот же сокет, что и в сниппете phpMyAdmin — см. resolve_fpm_socket
    local socket_name
    socket_name="$(resolve_fpm_socket)"

    # HTTPS-блоки создаём только при наличии настоящего сертификата.
    #
    # Проверки «WITH_SSL=yes, но сертификата ещё нет» тут недостаточно:
    # nginx не стартует, если на «listen 443 ssl» не задан ssl_certificate,
    # а старая заглушка комментировала только строку сертификата и оставляла
    # сам listen. Из-за этого первый запуск с SSL всегда падал на nginx -t.
    # Решение: пока сертификата нет — обслуживаем панель по HTTP, а после
    # certbot вызываем setup_nginx ещё раз, и конфиг пересобирается с HTTPS.
    local use_ssl="no"
    if [[ $WITH_SSL == "yes" ]]; then
        if [[ -f /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem ]]; then
            use_ssl="yes"
        else
            warn "Сертификата ещё нет — сначала обслуживаем панель по HTTP"
        fi
    fi

    # ── Набор location'ов панели ───────────────────────────────────
    # Держим в переменной, потому что он нужен и в HTTP-блоке, и в HTTPS.
    # Раньше он жил только в HTTPS-блоке, и при --no-ssl панель оказывалась
    # недоступна: блок 80-го порта редиректил на https, которого не было.
    local locations
    locations="$(cat <<'LOCEOF'

    client_max_body_size 128M;

    # Ассеты
    location /assets/ {
        alias @@PANEL_DIR@@/public/build/;
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # phpMyAdmin. Файл создаётся всегда — заглушкой, если он выключен,
    # поэтому include не может сломать проверку конфигурации.
    include @@PHPMYADMIN_SNIPPET@@;

    location /storage/ {
        alias @@PANEL_DIR@@/storage/app/public/;
        expires 7d;
    }

    # SSE-поток консоли игрового сервера
    location ~ ^/panel/servers/\d+/console/stream$ {
        proxy_pass http://gamedock_php;
        proxy_http_version 1.1;
        proxy_set_header Connection '';
        proxy_buffering off;
        proxy_cache off;
        chunked_transfer_encoding off;
        gzip off;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_pass gamedock_php;
        fastcgi_param SCRIPT_FILENAME @@PANEL_DIR@@/public/index.php;
        fastcgi_param DOCUMENT_ROOT @@PANEL_DIR@@/public;
        fastcgi_read_timeout 3600;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Всё, что не index.php, исполнять нельзя: иначе .php из
    # подкаталогов отдаётся как текст
    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
LOCEOF
)"

    # ── Общая часть: заголовок, map, upstream ───────────────────────
    cat >/etc/nginx/sites-available/gamedock <<NGINXEOF
# GameDock — панель управления
# Агент: WSS на 9222 (см. ниже) — держим SSE без буферизации
# Сгенерировано установщиком. Правьте через ключи --domain/--no-ssl.

map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

upstream gamedock_php {
    server unix:/run/php/${socket_name};
    keepalive 16;
}

# Лимит SSE-потока консоли: держим соединение подольше
proxy_read_timeout 3600s;
NGINXEOF

    # ── Порт 80 ─────────────────────────────────────────────────────
    # Условие именно use_ssl, а не WITH_SSL: пока сертификата нет, редирект
    # на https увёл бы посетителя в никуда — блока 443 тогда ещё не создано,
    # и панель была бы недоступна целиком. В этом состоянии обслуживаем
    # панель прямо по HTTP.
    if [[ $use_ssl == "yes" ]]; then
        # Редирект на https. Само обслуживание панели живёт в блоке 443.
        cat >>/etc/nginx/sites-available/gamedock <<NGINXEOF

server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN} ${PANEL_DOMAIN}.www;

    # Актуальные IP для Cloudflare
    real_ip_header CF-Connecting-IP;
    real_ip_recursive on;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINXEOF
    else
        # Сертификата нет и не будет: блок https создавать незачем, а
        # редирект на него увёл бы посетителя в никуда. Отдаём панель
        # прямо по HTTP.
        warn "SSL выключен — панель работает по HTTP, https-блок не создаётся"
        cat >>/etc/nginx/sites-available/gamedock <<NGINXEOF

server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN} ${PANEL_DOMAIN}.www;

    real_ip_header CF-Connecting-IP;
    real_ip_recursive on;
${locations}
}
NGINXEOF
    fi

    # ── Порт 443 ────────────────────────────────────────────────────
    # Только при настоящем сертификате: без него nginx не стартует,
    # и установка падала бы на «nginx -t».
    if [[ $use_ssl == "yes" ]]; then
        cat >>/etc/nginx/sites-available/gamedock <<NGINXEOF

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${PANEL_DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${PANEL_DOMAIN}/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_stapling on;
    ssl_stapling_verify on;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
${locations}
}
NGINXEOF
    fi

    # ── WSS-эндпоинт агентов ───────────────────────────────────────
    # Основной режим — отдельный процесс gamedock-wss на 9222, этот блок
    # нужен для входящих подключений. Без SSL слушает 9443 открытым текстом.
    if [[ $use_ssl == "yes" ]]; then
        cat >>/etc/nginx/sites-available/gamedock <<NGINXEOF

server {
    listen 9443 ssl;
    listen [::]:9443 ssl;
    http2 on;
    server_name ${PANEL_DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${PANEL_DOMAIN}/privkey.pem;
NGINXEOF
    else
        cat >>/etc/nginx/sites-available/gamedock <<NGINXEOF

server {
    listen 9443;
    listen [::]:9443;
    server_name ${PANEL_DOMAIN};
NGINXEOF
    fi

    cat >>/etc/nginx/sites-available/gamedock <<'NGINXEOF'

    location /agent/ws {
        proxy_pass http://127.0.0.1:9222;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-GameDock-Token $http_x_gamedock_token;
        proxy_set_header X-Node-Id $http_x_node_id;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
        proxy_buffering off;
    }
}
NGINXEOF

    # Подставляем пути в общий набор location'ов. Отдельным шагом, чтобы в
    # heredoc не пришлось смешивать экранирование nginx (там $host) и
    # подстановку переменных установщика (там $PANEL_DIR).
    sed -i \
        -e "s|@@PANEL_DIR@@|${PANEL_DIR}|g" \
        -e "s|@@PHPMYADMIN_SNIPPET@@|${PHPMYADMIN_SNIPPET}|g" \
        /etc/nginx/sites-available/gamedock

    ln -sf /etc/nginx/sites-available/gamedock /etc/nginx/sites-enabled/gamedock
    rm -f /etc/nginx/sites-enabled/default

    if ! run_logged 10 nginx -t; then
        fail "Конфигурация nginx некорректна. Смотрите /etc/nginx/sites-available/gamedock"
    fi

    systemctl enable --now nginx
    systemctl reload nginx 2>/dev/null || systemctl restart nginx

    ok "nginx настроен"
}

setup_ssl() {
    if [[ $WITH_SSL != "yes" ]]; then
        warn "SSL пропущен"
        return
    fi

    step "Получаю SSL-сертификат (Let's Encrypt)"

    # HTTP должен работать для проверки
    systemctl restart nginx 2>/dev/null || true
    sleep 2

    local email="${SSL_EMAIL:-$PANEL_EMAIL}"

    if ! run_logged 8 certbot certonly --webroot -w /var/www/certbot \
        -d "$PANEL_DOMAIN" -d "${PANEL_DOMAIN}.www" \
        --email "$email" \
        --agree-tos \
        --no-eff-email \
        --non-interactive; then
        warn "Не удалось получить сертификат автоматически"
        warn "Проверьте, что домен ${PANEL_DOMAIN} указывает на ${PUBLIC_IP:-этот сервер}"
        warn "После исправления DNS выполните: certbot certonly --webroot -w /var/www/certbot -d ${PANEL_DOMAIN}"
        return
    fi

    # Конфиг nginx пересобираем целиком, а не правим sed'ом.
    # Раньше здесь стояло раскомментирование строки ssl_certificate, но:
    #   - блока listen 443 ssl на тот момент в файле не было вовсе — он не
    #     создавался, пока сертификата нет;
    #   - поэтому раскомментировать было нечего, и HTTPS просто не включался;
    #   - а на следующем продлении сертификата хук искал строку, которой
    #     в файле уже не было, и тихо ничего не делал.
    # setup_nginx идемпотентен: сертификат появился — появятся и HTTPS-блоки.
    setup_nginx

    # Хук продления: пересобираем конфиг, чтобы новый сертификат подхватился
    cat >/etc/letsencrypt/renewal-hooks/deploy/gamedock-nginx <<'HOOKEOF'
#!/bin/sh
# Продление сертификата: пересобрать конфиг nginx и перезагрузить его.
# Раньше здесь был sed по строке ssl_certificate, который переставал находить
# цель после первой же пересборки конфига.
if nginx -t 2>/dev/null; then
    systemctl reload nginx
else
    systemctl restart nginx
fi
HOOKEOF
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/gamedock-nginx

    ok "SSL-сертификат получен, автопродление включено"
}

# ═══════════════════════════════════════════════════════════════════
# systemd: WSS-сервер, очереди, планировщик
# ═══════════════════════════════════════════════════════════════════

setup_systemd() {
    step "Настраиваю systemd-сервисы"

    # WSS-сервер панели (держит соединения с агентами)
    cat >/etc/systemd/system/gamedock-wss.service <<EOF
[Unit]
Description=GameDock WSS server (agent connections)
After=network.target redis-server.service
Wants=redis-server.service

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_GROUP}
WorkingDirectory=${PANEL_DIR}
ExecStart=/usr/bin/php ${PANEL_DIR}/artisan gamedock:ws-server --host=0.0.0.0 --port=9222
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=gamedock-wss

# Ограничения
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${PANEL_DIR}/storage /var/log

[Install]
WantedBy=multi-user.target
EOF

    # Очереди
    for i in $(seq 1 $QUEUE_WORKERS); do
        cat >/etc/systemd/system/gamedock-queue@.service <<EOF
[Unit]
Description=GameDock queue worker #%i
After=network.target redis-server.service
PartOf=gamedock.target

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_GROUP}
WorkingDirectory=${PANEL_DIR}
ExecStart=/usr/bin/php ${PANEL_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --name=gamedock-%i
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=gamedock-queue@%i
NoNewPrivileges=true
EOF
    done

    # Планировщик
    cat >/etc/systemd/system/gamedock-scheduler.service <<EOF
[Unit]
Description=GameDock scheduler (cron tasks, billing, alerts)
After=network.target redis-server.service

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_GROUP}
WorkingDirectory=${PANEL_DIR}
ExecStart=/usr/bin/php ${PANEL_DIR}/artisan schedule:work
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=gamedock-scheduler
NoNewPrivileges=true
EOF

    # Агент-шаблон (инстанцируется на каждой ноде)
    cat >/etc/systemd/system/gamedock-agent@.service <<EOF
[Unit]
Description=GameDock node agent (%i)
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_GROUP}
WorkingDirectory=${AGENT_DIR}
EnvironmentFile=-/etc/gamedock/agent-%i.env
ExecStart=/usr/bin/node ${AGENT_DIR}/src/index.js
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=gamedock-agent@%i

LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

    # Целевой юнит для управления вместе
    cat >/etc/systemd/system/gamedock.target <<'EOF'
[Unit]
Description=GameDock panel services
Wants=gamedock-wss.service gamedock-scheduler.service gamedock-queue@1.service
After=network.target
EOF

    systemctl daemon-reload
    systemctl enable --now gamedock-wss.service
    systemctl enable --now gamedock-scheduler.service

    for i in $(seq 1 $QUEUE_WORKERS); do
        systemctl enable --now "gamedock-queue@${i}.service" || warn "Не удалось запустить очередь #$i"
    done

    ok "Сервисы запущены: gamedock-wss, gamedock-scheduler, gamedock-queue@1..${QUEUE_WORKERS}"
}

setup_agent_template() {
    step "Готовлю агента ноды"

    mkdir -p "$AGENT_DIR"
    copy_tree "$REPO_DIR/agent" "$AGENT_DIR" "агент" \
        || warn "Агент не развёрнут из $REPO_DIR/agent"
    copy_tree "$REPO_DIR/game-images" "$GAME_IMAGES_DIR" "образы игр" \
        || warn "Каталог образов игр не развёрнут"

    chown -R "$SERVICE_USER:$SERVICE_USER" "$AGENT_DIR" "$GAME_IMAGES_DIR"

    cd "$AGENT_DIR"
    npm ci --omit=dev --silent 2>&1 | tail -3 || npm install --omit=dev --silent 2>&1 | tail -3

    # Пример конфигурации
    cat >/etc/gamedock/agent.env.example <<'AGENTENVEOF'
# Конфигурация агента ноды GameDock
# Скопируйте в agent-<NODE_ID>.env, подставьте токен из панели и включите инстанс:
#   systemctl enable --now gamedock-agent@<NODE_ID>.service

GD_PANEL=https://${PANEL_DOMAIN}
GD_TOKEN=PASTE_TOKEN_HERE
GD_RUNTIME=${DEFAULT_RUNTIME}
GD_SERVERS_ROOT=/home/gamedock/servers
GD_BACKUPS_ROOT=/home/gamedock/backups
GD_TEMPLATES_ROOT=${GAME_IMAGES_DIR}
GD_SYSTEM_USER=${SERVICE_USER}
GD_SYSTEM_GROUP=${SERVICE_GROUP}
AGENTENVEOF

    cp /etc/gamedock/agent.env.example "$AGENT_DIR/agent.config.json" 2>/dev/null || true

    # Скрипт установки агента на новую ноду
    cp "$REPO_DIR/deploy/agent.sh" /usr/local/bin/gamedock-agent-install 2>/dev/null || true
    chmod +x /usr/local/bin/gamedock-agent-install 2>/dev/null || true

    ok "Агент подготовлен в $AGENT_DIR"
    ok "Шаблоны игр в $GAME_IMAGES_DIR"
}

setup_local_agent() {
    # Раньше INSTALL_AGENT разбирался из аргументов, но нигде не читался:
    # агент ставился на машину безусловно, а --no-agent (это же значение по
    # умолчанию) не делал ничего. Для машины «только панель» это лишний
    # systemd-юнит, node и каталоги игровых серверов.
    if [[ $INSTALL_AGENT != "yes" ]]; then
        log "Агент на эту машину не ставится — панель будет работать с нодами на других серверах"
        log "  Если нода всё же нужна здесь, переустановите с ключом --with-agent"
        return 0
    fi

    step "Устанавливаю агента на эту машину"

    local node_id
    node_id=$(cd "$PANEL_DIR" && su -s /bin/bash "$SERVICE_USER" -c "php artisan tinker --execute='echo \\App\\Models\\Node::count() + 1;'" 2>/dev/null | tr -dc '0-9' | head -c 3)
    node_id="${node_id:-1}"

    cat >/etc/gamedock/agent-1.env <<AGENTENVEOF
GD_PANEL=https://${PANEL_DOMAIN}
GD_TOKEN=ВСТАВЬТЕ_ТОКЕН_ИЗ_ПАНЕЛИ
GD_RUNTIME=${DEFAULT_RUNTIME}
GD_SERVERS_ROOT=/home/gamedock/servers
GD_BACKUPS_ROOT=/home/gamedock/backups
GD_TEMPLATES_ROOT=${GAME_IMAGES_DIR}
GD_SYSTEM_USER=${SERVICE_USER}
GD_SYSTEM_GROUP=${SERVICE_GROUP}
AGENTENVEOF

    warn "Откройте в панели «Админка → Ноды → Добавить ноду», укажите рантайм ${DEFAULT_RUNTIME} и хост ${PUBLIC_IP:-127.0.0.1}"
    warn "Затем выполните: sed -i 's/ВСТАВЬТЕ_ТОКЕН_ИЗ_ПАНЕЛИ/<ТОКЕН>/' /etc/gamedock/agent-1.env && systemctl enable --now gamedock-agent@1"

    # Docker
    if [[ $DEFAULT_RUNTIME == "docker" ]] && ! command -v docker >/dev/null 2>&1; then
        log "Ставлю Docker Engine…"
        install -m 0755 -d /etc/apt/keyrings
        # download.docker.com держит отдельные ветки для debian и ubuntu
        local docker_key
        docker_key="$(mktemp)"
        if fetch_with_retry "https://download.docker.com/linux/${OS_ID}/gpg" "$docker_key"; then
            gpg --dearmor -o /etc/apt/keyrings/docker.gpg <"$docker_key"
        else
            rm -f "$docker_key"
            warn "Не удалось скачать ключ Docker — репозиторий не подключён"
            warn "Поставьте Docker вручную: https://docs.docker.com/engine/install/"
            return
        fi
        rm -f "$docker_key"
        chmod a+r /etc/apt/keyrings/docker.gpg

        local repo_line="deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${OS_ID} ${OS_CODENAME} stable"
        echo "$repo_line" >/etc/apt/sources.list.d/docker.list

        apt_update
        if ! apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin </dev/null 2>&1 | tail -5; then
            fail "Не удалось поставить Docker из download.docker.com/linux/${OS_ID}"
        fi

        usermod -aG docker "$SERVICE_USER"

        # cgroup v2 для лимитов
        if [ ! -f /etc/systemd/system/docker.service.d/limits.conf ]; then
            mkdir -p /etc/systemd/system/docker.service.d
            cat >/etc/systemd/system/docker.service.d/limits.conf <<'DOCKEREOF'
[Service]
CPUAccounting=true
MemoryAccounting=true
DOCKEREOF
        fi

        systemctl enable --now docker
        ok "Docker установлен"
    fi
}

# ═══════════════════════════════════════════════════════════════════
# Финальные штрихи
# ═══════════════════════════════════════════════════════════════════

setup_firewall() {
    step "Настройка файрвола"

    if command -v ufw >/dev/null 2>&1; then
        ufw allow 22/tcp comment 'SSH' >/dev/null 2>&1 || true
        ufw allow 80/tcp comment 'HTTP' >/dev/null 2>&1 || true
        ufw allow 443/tcp comment 'HTTPS' >/dev/null 2>&1 || true

        if [[ $WITH_NGINX == "yes" ]]; then
            ufw allow 9443/tcp comment 'Agent WSS' >/dev/null 2>&1 || true
        fi

        if [[ $DEFAULT_RUNTIME == "native" ]]; then
            warn "Рантайм native: игровые порты 25000-25999 (game), 26000-26999 (query), 27000-27199 (rcon) нужно открыть"
        fi

        # fail2ban
        if [[ -f /etc/fail2ban/filter.d/sshd.conf ]]; then
            systemctl enable --now fail2ban
        fi

        ok "Файрвол настроен"
    else
        warn "ufw не найден — настройте файрвол вручную"
    fi
}

optimize_system() {
    step "Оптимизация системы"

    # Параметры ядра для игровых серверов
    cat >/etc/sysctl.d/99-gamedock.conf <<'SYSCTL'
# GameDock — оптимизация под игровые серверы

# Больше соединений
net.core.somaxconn = 4096
net.ipv4.tcp_max_syn_backlog = 8192
net.ipv4.ip_local_port_range = 10240 65000
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
net.ipv4.tcp_keepalive_time = 300

# Быстрые UDP-пакеты (SAMP, Minecraft Bedrock, RAGE.MP)
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.udp_rmem_min = 8192
net.ipv4.udp_wmem_min = 8192

# Сеть
net.ipv4.tcp_congestion_control = bbr
net.core.default_qdisc = fq
net.ipv4.tcp_fastopen = 3

# Виртуальная память (SteamCMD, Java)
vm.swappiness = 10
vm.vfs_cache_pressure = 50
vm.max_map_count = 262144

# Базовая безопасность
kernel.dmesg_restrict = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
SYSCTL

    if command -v bbr >/dev/null 2>&1 || modprobe tcp_bbr 2>/dev/null; then
        ok "BBR доступен"
    else
        warn "BBR не поддерживается ядром — будет CUBIC"
    fi

    sysctl --system >/dev/null 2>&1 || warn "sysctl не применился"

    # Ограничение для systemd-сервисов GameDock
    mkdir -p /etc/systemd/system/gamedock-wss.service.d
    cat >/etc/systemd/system/gamedock-wss.service.d/limits.conf <<'EOF'
[Service]
LimitNOFILE=65535
EOF

    systemctl daemon-reload

    ok "Система оптимизирована"
}

backup_file() {
    local file="$1"

    if [[ -f "$file" ]]; then
        cp "$file" "${file}.backup-$(date +%Y%m%d-%H%M%S)"
    fi
}

print_summary() {
    local admin_pass
    admin_pass="$1"

    # Блок phpMyAdmin собираем отдельно: либо показываем адрес и пароль,
    # либо одну строку «не установлен». Пустой ${PMA_SUMMARY} в heredoc
    # оставил бы после себя лишнюю пустую строку.
    local PMA_SUMMARY=""
    if [[ -n ${PHPMYADMIN_URL:-} && -f $PHPMYADMIN_CREDS ]]; then
        # shellcheck disable=SC1090
        . "$PHPMYADMIN_CREDS"
        PMA_SUMMARY=$(cat <<PMAOUT
  phpMyAdmin:    ${PHPMYADMIN_URL}
  Логин:         ${PMA_USER}
  Пароль:        ${PMA_PASS}
PMAOUT
        )
        if [[ -n $PHPMYADMIN_ALLOW ]]; then
            PMA_SUMMARY+="
  Доступ с IP:  ${PHPMYADMIN_ALLOW}"
        else
            PMA_SUMMARY+="
  Вход открыт с любого IP, но только по паролю выше."
        fi
        PMA_SUMMARY+=$'\n'
    else
        PMA_SUMMARY=$'\n  phpMyAdmin:    не установлен\n'
    fi

    cat <<SUMMARYEOF

$(echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${NC}")
$(echo -e "${BOLD}${GREEN}║            GameDock установлен успешно                  ║${NC}")
$(echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${NC}")

  Панель:        https://${PANEL_DOMAIN}
  Логин:         ${PANEL_EMAIL}
  Пароль:        ${admin_pass}

  Режим нод:     ${NODE_MODE}
  Рантайм:       ${DEFAULT_RUNTIME}
  Версия панели: ${GAMEDOCK_VERSION}
${PMA_SUMMARY}

  Включено:
    промокоды со скидкой    $( $PROMO_DISCOUNT =~ yes && echo "да" || echo "нет")
    промокоды на срок        $( $PROMO_DURATION =~ yes && echo "да" || echo "нет" )
    промокоды с бонусом      $( $PROMO_BONUS =~ yes && echo "да" || echo "нет" )
    реферальная программа    $( $REFERRAL =~ yes && echo "да" || echo "нет" )
    секретные коды игрокам   $( $SECRET_CODES =~ yes && echo "да" || echo "нет" )
    тестовый период          $( $TRIAL =~ yes && echo "${TRIAL_DAYS} дн" || echo "нет" )

  Приём оплаты:
    ЮKassa           $( $PAY_YOOKASSA =~ yes && echo "да" || echo "нет" )
    Т-Банк           $( $PAY_TINKOFF =~ yes && echo "да" || echo "нет" )
    Криптобот        $( $PAY_CRYPTOBOT =~ yes && echo "да" || echo "нет" )
    ручной приём     $( $PAY_MANUAL =~ yes && echo "да" || echo "нет" )

  Службы:
    gamedock-wss       — соединения с агентами (порт 9222)
    gamedock-queue@1   — очереди задач
    gamedock-scheduler — биллинг, алерты, бэкапы, планировщик

  Полезные команды:
    systemctl status gamedock-wss
    journalctl -u gamedock-wss -f
    cd ${PANEL_DIR} && php artisan gamedock:scheduler billing

$(echo -e "${BOLD}${CYAN}Следующий шаг:${NC}")
  1. Откройте панель и смените пароль администратора
  2. «Админка → Ноды» — добавьте ноду, получите токен
  3. На ноде выполните:  sudo gamedock-agent-install --panel https://${PANEL_DOMAIN} --token <ТОКЕН>
  4. «Админка → Игры» — проверьте каталог, добавьте свою игру
  5. «Админка → Настройки» — SMTP, реквизиты, порты

$(echo -e "${YELLOW}Важно:${NC}")
  • Панель в /opt/gamedock, агент в /opt/gamedock/agent
  • Логи: /var/log/gamedock-install.log, journalctl -u gamedock-wss
  • Секреты БД и Redis: /opt/gamedock/panel/.env и /etc/redis/redis.conf.gamedock
  • Установку можно повторить: ./install.sh --yes (настройки перезапишутся)

SUMMARYEOF
}

# ═══════════════════════════════════════════════════════════════════
# Неинтерактивная установка пакетов
# ═══════════════════════════════════════════════════════════════════

# Готовим apt так, чтобы он ни разу не спросил пользователя ничего.
#
# Установка пакетов идёт через run_logged, а он выполняет команду в
# подстановке `$(...)`. stdin при этом наследуется от терминала, поэтому
# любой вопрос apt блокирует установку, пока пользователь не нажмёт Enter.
#
# Три источника вопросов, и все три закрываются по-разному:
#   1. debconf-диалоги. DEBIAN_FRONTEND=noninteractive их подавляет, но
#      только если задан ДО вызова apt, а не в отдельной функции.
#   2. needrestart — на Debian 11/12/13 и Ubuntu 22.04/24.04 после крупных
#      обновлений спрашивает, какие службы перезапустить. Он НЕ подчиняется
#      DEBIAN_FRONTEND: без NEEDRESTART_MODE=a диалог появляется всегда.
#   3. dpkg при изменении конфигурационных файлов. Лечится force-confold.
setup_noninteractive() {
    export DEBIAN_FRONTEND=noninteractive
    export NEEDRESTART_MODE=a
    export NEEDRESTART_SUSPEND=""

    # needrestart надёжнее переключается файлом конфигурации, чем
    # переменной окружения: значение из окружения теряется там, где apt
    # вызывает хуки через sudo с чистым окружением.
    if [[ -d /etc/needrestart/conf.d ]]; then
        printf '%s\n' \
            '$nrconf{REBOOT} = "a";' \
            '$nrconf{APPRAISE} = "a";' \
            >/etc/needrestart/conf.d/gamedock.conf 2>/dev/null || true
    fi

    # Тихая зона: без неё tzdata в неинтерактивном режиме падает с ошибкой.
    if ! grep -qs ' Etc/UTC ' /etc/timezone 2>/dev/null; then
        export TZ="${TZ:-Etc/UTC}"
    fi

    # Заранее отвечаем на самые частые debconf-вопросы, чтобы пакеты не
    # зависали даже там, где noninteractive почему-то не сработал.
    if command -v debconf-set-selections >/dev/null 2>&1; then
        debconf-set-selections <<'DEBCONFEOF' 2>/dev/null || true
tzdata tzdata/Areas select Etc
tzdata tzdata/Zones/Etc select UTC
mariadb-server mariadb-server/root_password password
mariadb-server mariadb-server/root_password_again password
mariadb-server mariadb-server/re-root-pass password
mariadb-server mariadb-server/default-auth-override select Use Strong Password Encryption (RECOMMENDED)
ssh-server ssh-server/permit-root-login select no
DEBCONFEOF
    fi
}

# Флаги apt, которыми сборка пакетов не должна вставать на вопрос.
apt_quiet_flags() {
    printf '%s\n' \
        -y \
        -o Dpkg::Options::=--force-confdef \
        -o Dpkg::Options::=--force-confold \
        -o Dpkg::Options::=--force-yes
}

# ═══════════════════════════════════════════════════════════════════
# Предварительная проверка
# ═══════════════════════════════════════════════════════════════════

# Проверяем, что установка вообще дойдёт до конца, ДО установки пакетов.
#
# Без этой проверки пользователь узнаёт, что Composer недоступен, спустя
# несколько минут после установки 52 пакетов — и узнаёт это так три раза
# подряд, потому что до Composer дело не доходит. Проверка занимает секунды
# и экономит время.
preflight_dependencies() {
    step "Проверяю доступность зависимостей"

    local -a problems=()

    # ── Composer ───────────────────────────────────────────────────
    # Основной путь — пакет из репозитория дистрибутива, запасной —
    # getcomposer.org. Предупреждаем, если ни один не сработает.
    if command -v composer >/dev/null 2>&1; then
        log "  Composer: уже установлен"
    elif pkg_available composer; then
        log "  Composer: пакет есть в репозиториях, поставлю оттуда"
    elif curl -fsSI --connect-timeout 10 --max-time 25 \
            https://getcomposer.org/installer >/dev/null 2>&1; then
        log "  Composer: пакета в репозиториях нет, скачаю с getcomposer.org"
    else
        problems+=(
            "Composer недоступен: пакета нет в репозиториях, а getcomposer.org не отвечает."
        )
    fi

    # ── Репозиторий PHP ─────────────────────────────────────────────
    # Нужен там, где в дистрибутиве PHP старее 8.2. Проверяем только когда
    # он действительно понадобится, иначе лишняя задержка.
    if [[ $OS_NEEDS_SURY -eq 1 ]] && [[ ! -s /usr/share/keyrings/sury-php.gpg ]]; then
        if curl -fsSI --connect-timeout 10 --max-time 25 \
                https://packages.sury.org/php/apt.gpg >/dev/null 2>&1; then
            log "  packages.sury.org: отвечает"
        else
            problems+=(
                "packages.sury.org недоступен, а в дистрибутиве PHP старее ${PHP_VERSION:-8.3}."
            )
        fi
    fi

    # ── Итог ────────────────────────────────────────────────────────
    if (( ${#problems[@]} > 0 )); then
        echo
        warn "Предварительная проверка не пройдена — останавливаюсь до установки пакетов:"
        echo
        local p
        for p in "${problems[@]}"; do
            printf '   %s- %s%s\n' "$YELLOW" "$p" "$NC"
        done
        echo
        fail "Устраните причину и запустите установщик снова. Так вы не потратите
несколько минут на установку пакетов, которая всё равно прервётся.

Чаще всего помогает:

  • Если getcomposer.org или другое внешнее хранилище недоступно —
    поставьте нужный пакет из репозитория дистрибутива заранее:
        apt-get update && apt-get install -y composer

  • Если внешние хосты режет провайдер или фильтр — проверьте доступ:
        curl -I --max-time 15 https://getcomposer.org/installer

  • Ничего не помогает — запустите установщик вручную, шаг за шагом,
    и пришлите вывод шага, на котором он остановился."
    fi

    ok "Зависимости доступны, продолжаю"
}

# ═══════════════════════════════════════════════════════════════════
# Главный сценарий
# ═══════════════════════════════════════════════════════════════════

main() {
    parse_args "$@"

    PUBLIC_IP="$(curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')"

    echo
    echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${CYAN}║        GameDock — установка игрового хостинга            ║${NC}"
    echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════════════╝${NC}"
    echo

    check_root
    setup_noninteractive
    detect_distro
    ensure_cgroup_v2
    check_system
    ensure_base_tools
    check_deps
    preflight_dependencies

    # ── Диалог ─────────────────────────────────────────────────────
    step "Настройка панели"

    if [[ -z $PANEL_DOMAIN ]]; then
        PANEL_DOMAIN=$(ask "Домен панели (без https://)" "panel.example.com")
    fi
    [[ -n $PANEL_DOMAIN ]] || PANEL_DOMAIN="panel.example.com"

    if [[ -z $PANEL_EMAIL ]]; then
        PANEL_EMAIL=$(ask "Email администратора" "admin@example.com")
    fi
    SUPPORT_EMAIL="$PANEL_EMAIL"

    PANEL_NAME=$(ask "Название панели" "$PANEL_NAME")

    if [[ $INTERACTIVE -eq 1 ]]; then
        echo
        echo -e "${CYAN}Режим работы с нодами:${NC}"
        echo "  single — одна нода, ничего не выбирается (для теста/одного VPS)"
        echo "  manual — ноду выбирает администратор"
        echo "  auto   — панель сама распределяет по свободным ресурсам"
        NODE_MODE=$(ask "Режим нод (single/manual/auto)" "$NODE_MODE")
    fi

    if [[ $INTERACTIVE -eq 1 ]]; then
        echo
        echo -e "${CYAN}Рантайм игровых процессов:${NC}"
        echo "  docker — контейнеры (рекомендуется)"
        echo "  podman — альтернатива Docker"
        echo "  lxc    — контейнеры Proxmox VE"
        echo "  native — процессы через systemd + cgroup v2 (без Docker)"
        DEFAULT_RUNTIME=$(ask "Рантайм (docker/podman/lxc/native)" "$DEFAULT_RUNTIME")
    fi

    case "$NODE_MODE" in
        single|manual|auto) ;;
        *) warn "Неизвестный режим нод «$NODE_MODE» — ставлю auto"; NODE_MODE="auto" ;;
    esac

    case "$DEFAULT_RUNTIME" in
        docker|podman|lxc|native) ;;
        *) warn "Неизвестный рантайм «$DEFAULT_RUNTIME» — ставлю docker"; DEFAULT_RUNTIME="docker" ;;
    esac

    # ── Маркетинг ──────────────────────────────────────────────────
    if [[ $INTERACTIVE -eq 1 ]]; then
        step "Маркетинговые механики"

        ask_yes_no "Промокоды со скидкой?" "yes"    && PROMO_DISCOUNT="yes" || PROMO_DISCOUNT="no"
        ask_yes_no "Промокоды на срок аренды?" "yes" && PROMO_DURATION="yes" || PROMO_DURATION="no"
        ask_yes_no "Промокоды с бонусом?" "yes"     && PROMO_BONUS="yes"    || PROMO_BONUS="no"
        ask_yes_no "Реферальная программа?" "yes"   && REFERRAL="yes"       || REFERRAL="no"
        ask_yes_no "Секретные коды для игроков?" "yes" && SECRET_CODES="yes" || SECRET_CODES="no"

        if ask_yes_no "Тестовый период при регистрации?" "yes"; then
            TRIAL="yes"
            TRIAL_DAYS=$(ask "Сколько дней теста" "3")
        else
            TRIAL="no"
        fi

        # ── Платежи ───────────────────────────────────────────────
        step "Приём оплаты"

        ask_yes_no "ЮKassa (карты, СБП)?" "yes" && PAY_YOOKASSA="yes" || PAY_YOOKASSA="no"
        if [[ $PAY_YOOKASSA == "yes" ]]; then
            YOOKASSA_SHOP_ID=$(ask "Shop ID ЮKassa (оставьте пустым, чтобы настроить позже)" "")
            YOOKASSA_SECRET=$(ask "Секретный ключ ЮKassa" "")
        fi

        if ask_yes_no "Т-Банк Интернет-магазин?" "no"; then
            PAY_TINKOFF="yes"
            TINKOFF_TERMINAL=$(ask "Terminal Key Т-Банка" "")
            TINKOFF_PASSWORD=$(ask "Пароль Т-Банка" "")
        else
            PAY_TINKOFF="no"
        fi

        ask_yes_no "Криптобот (крипта в Telegram)?" "yes" && PAY_CRYPTOBOT="yes" || PAY_CRYPTOBOT="no"
        if [[ $PAY_CRYPTOBOT == "yes" ]]; then
            CRYPTOBOT_TOKEN=$(ask "Токен @CryptoBot" "")
        fi

        ask_yes_no "Ручной приём оплаты?" "yes" && PAY_MANUAL="yes" || PAY_MANUAL="no"

        # ── Telegram ──────────────────────────────────────────────
        step "Telegram-уведомления (необязательно)"

        if ask_yes_no "Настроить Telegram-бота для алертов?" "no"; then
            TELEGRAM_BOT_TOKEN=$(ask "Токен бота от @BotFather" "")
            TELEGRAM_ADMIN_CHAT=$(ask "Ваш chat_id (напишите боту /start и посмотрите в логах)" "")
        fi
    fi

    # ── Установка ─────────────────────────────────────────────────
    install_packages
    # Исходники разворачиваем ДО создания каталогов установки: create_service_user
    # создаёт $GAME_IMAGES_DIR, который лежит внутри $INSTALL_DIR, и git отказывается
    # клонировать в непустой каталог. Обратный порядок давал
    # «fatal: destination path '/opt/gamedock' already exists and is not an empty directory».
    detect_repo
    create_service_user
    setup_database
    setup_redis
    setup_php
    # Порядок важен: сначала исходники и каталоги, потом .env, и только
    # потом зависимости. composer install вызывает artisan package:discover,
    # который поднимает приложение и читает .env — при старом порядке
    # установка падала на .env, дошедшем от прошлого прогона.
    deploy_panel_sources
    setup_env
    install_panel_deps
    run_migrations
    apply_hosting_config
    # До setup_nginx: он делает nginx -t, и сниппет phpMyAdmin должен
    # к этому моменту существовать — иначе include роняет проверку.
    install_phpmyadmin
    setup_nginx
    setup_ssl
    setup_systemd
    setup_agent_template
    setup_local_agent
    setup_firewall
    optimize_system

    # ── Пароль администратора ─────────────────────────────────────
    local admin_pass
    admin_pass="$(gen_password 16)"

    cd "$PANEL_DIR"
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan gamedock:admin-password --email='${PANEL_EMAIL}' --password='${admin_pass}'" 2>/dev/null || warn "Не удалось задать пароль админа — используйте дефолтный admin@example.com / admin12345 и смените его вручную"

    # Финальная проверка
    step "Проверка установки"

    check_service gamedock-wss
    check_service gamedock-scheduler
    check_url "https://${PANEL_DOMAIN}"

    print_summary "$admin_pass"

    # Сохраняем параметры для повторного использования
    cat >"$STATE_DIR/install-params.sh" <<PARAMSEOF
# Параметры установки GameDock (сгенерировано $(date))
GAMEDOCK_VERSION=${GAMEDOCK_VERSION}
PANEL_DOMAIN=${PANEL_DOMAIN}
PANEL_EMAIL=${PANEL_EMAIL}
PANEL_NAME=${PANEL_NAME}
NODE_MODE=${NODE_MODE}
DEFAULT_RUNTIME=${DEFAULT_RUNTIME}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
REDIS_PASS=${REDIS_PASS}
PROMO_DISCOUNT=${PROMO_DISCOUNT}
PROMO_DURATION=${PROMO_DURATION}
PROMO_BONUS=${PROMO_BONUS}
REFERRAL=${REFERRAL}
SECRET_CODES=${SECRET_CODES}
TRIAL=${TRIAL}
TRIAL_DAYS=${TRIAL_DAYS}
PARAMSEOF

    chmod 600 "$STATE_DIR/install-params.sh"

    ok "Готово!"
}

check_service() {
    local name="$1"

    if systemctl is-active --quiet "$name"; then
        ok "$name — работает"
    else
        warn "$name — не запущен. Проверьте: journalctl -u $name -n 50"
    fi
}

check_url() {
    local url="$1"
    local code

    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null || echo "000")

    if [[ "$code" =~ ^(200|301|302)$ ]]; then
        ok "Панель отвечает: HTTP $code"
    else
        warn "Панель не отвечает (HTTP $code). Проверьте: systemctl status nginx php${PHP_VERSION}-fpm"
    fi
}

# ═══════════════════════════════════════════════════════════════════
main "$@"
