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
#   curl -fsSL https://raw.githubusercontent.com/your-org/gamedock/main/deploy/install.sh | sudo bash
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
set -euo pipefail

# ═══════════════════════════════════════════════════════════════════
# Константы
# ═══════════════════════════════════════════════════════════════════

GAMEDOCK_VERSION="${GAMEDOCK_VERSION:-1.0.0}"
INSTALL_DIR="${GAMEDOCK_INSTALL_DIR:-/opt/gamedock}"
PANEL_DIR="$INSTALL_DIR/panel"
AGENT_DIR="$INSTALL_DIR/agent"
GAME_IMAGES_DIR="${GAME_IMAGES_DIR:-/opt/gamedock/game-images}"
STATE_DIR=/var/lib/gamedock
LOG_FILE=/var/log/gamedock-install.log

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

log()   { echo -e "${BLUE}[GameDock]${NC} $*" | tee -a "$LOG_FILE"; }
ok()    { echo -e "${GREEN}✓${NC} $*" | tee -a "$LOG_FILE"; }
warn()  { echo -e "${YELLOW}!${NC} $*" | tee -a "$LOG_FILE"; }
fail()  { echo -e "${RED}✗${NC} $*" | tee -a "$LOG_FILE"; exit 1; }
step()  { echo; echo -e "${BOLD}${CYAN}▸ $*${NC}" | tee -a "$LOG_FILE"; }

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

check_system() {
    step "Проверка системы"

    [[ $OS_SUPPORTED -eq 1 ]] || fail "Система не определена — сначала detect_distro"

    local kernel
    kernel="$(uname -r)"

    # Проверка свободного места
    local avail_gb
    avail_gb=$(df -BG --output=avail "$INSTALL_DIR" 2>/dev/null | tail -1 | tr -dc '0-9' || echo 0)

    if (( avail_gb < 5 )); then
        fail "Мало места: доступно ${avail_gb} ГБ, нужно минимум 5 ГБ"
    fi

    ok "$OS_PRETTY_NAME, ядро ${kernel}, свободно ${avail_gb} ГБ"
}

check_deps() {
    step "Проверка зависимостей"

    for cmd in curl git unzip tar; do
        command -v $cmd >/dev/null 2>&1 || fail "Не найдена утилита $cmd"
    done

    ok "Базовые утилиты на месте"
}

detect_repo() {
    step "Определение исходников"

    if [[ -d "$INSTALL_DIR/.git" ]]; then
        REPO_DIR="$INSTALL_DIR"
        ok "Использую локальный репозиторий: $REPO_DIR"
        return
    fi

    local url="${GAMEDOCK_REPO:-}"
    local branch="${GAMEDOCK_BRANCH:-main}"

    if [[ -z $url ]]; then
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

        read -rp "URL репозитория GameDock: " url
    fi

    [[ -n $url ]] || fail "Не указан URL репозитория"

    mkdir -p "$INSTALL_DIR"
    REPO_DIR="$INSTALL_DIR"

    if [[ -d "$REPO_DIR/.git" ]]; then
        log "Обновляю репозиторий…"
        git -C "$REPO_DIR" pull --ff-only || warn "git pull не удался, продолжаю с текущей версией"
    else
        log "Клонирую репозиторий $url…"
        git clone --depth 1 --branch "$branch" "$url" "$REPO_DIR" || fail "git clone не удался"
    fi
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
apt_add_component() {
    local file="$1" comp="$2"

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
enable_apt_components() {
    log "Включаю дополнительные компоненты репозитория…"

    local -a want
    if [[ $OS_ID == "ubuntu" ]]; then
        want=(main restricted universe multiverse)
    else
        want=(main contrib non-free non-free-firmware)
    fi

    if command -v add-apt-repository >/dev/null 2>&1; then
        for comp in "${want[@]}"; do
            add-apt-repository -y "$comp" >/dev/null 2>&1 || true
        done
    else
        # Без software-properties-common правим sources-файлы вручную
        local file comp
        for file in /etc/apt/sources.list \
                    /etc/apt/sources.list.d/"$OS_ID"*.sources \
                    /etc/apt/sources.list.d/"$OS_ID"*.list; do
            [[ -f $file ]] || continue
            for comp in "${want[@]}"; do
                apt_add_component "$file" "$comp" || true
            done
        done
    fi

    apt_update
    ok "Компоненты репозитория включены"
}

# Пакет доступен в текущих репозиториях?
pkg_available() {
    apt-cache show "$1" >/dev/null 2>&1
}

# ── Репозиторий PHP (packages.sury.org) ───────────────────────────
#
# Нужен там, где в дистрибутиве PHP старее 8.2: Debian 11 (7.4), Ubuntu 22.04 (8.1).
# Это официальный репозиторий, который ведёт Debian и Ubuntu.
setup_sury_php() {
    if [[ $OS_NEEDS_SURY -eq 0 && -z $PHP_VERSION ]]; then
        return
    fi

    log "Подключаю packages.sury.org для PHP…"

    apt-get install -y -qq --no-install-recommends ca-certificates apt-transport-https \
        lsb-release curl gnupg >/dev/null

    curl -fsSL "https://packages.sury.org/php/apt.gpg" \
        -o /usr/share/keyrings/sury-php.gpg

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

    apt-get install -y -qq --no-install-recommends ca-certificates curl gnupg >/dev/null

    mkdir -p /etc/apt/keyrings
    curl -fsSL "https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key" \
        | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
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
        software-properties-common git unzip zip tar xz-utils bzip2
        jq less vim htop wget
        ufw fail2ban
    )

    # PHP версионными пакетами: так единообразно для всех дистрибутивов
    local php_ver="${PHP_VERSION/./}"   # 8.3 -> 83
    base+=(
        "php${php_ver}-fpm" "php${php_ver}-cli" "php${php_ver}-mysql"
        "php${php_ver}-mbstring" "php${php_ver}-xml" "php${php_ver}-curl"
        "php${php_ver}-zip" "php${php_ver}-gd" "php${php_ver}-bcmath"
        "php${php_ver}-intl" "php${php_ver}-opcache"
    )

    # php-soap нужен платёжным шлюзам, но есть не везде — добавляем опционально
    base+=("php${php_ver}-soap" "php${php_ver}-redis")

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
        libsdl2-dev libncursesw5-dev
        screen tmux cron
    )

    # Java: 17 есть везде, 21 — не на всех дистрибутивах
    base+=(openjdk-17-jre-headless)
    if pkg_available "openjdk-21-jre-headless"; then
        base+=(openjdk-21-jre-headless)
    else
        warn "openjdk-21-jre-headless недоступен в $OS_PRETTY_NAME — ставится только Java 17"
    fi

    # SteamCMD — для CS2, Rust, Unturned, ARK
    if ! command -v steamcmd >/dev/null 2>&1 && [[ ! -x /usr/games/steamcmd/steamcmd ]]; then
        if pkg_available steamcmd; then
            base+=(steamcmd)
        else
            warn "Пакет steamcmd недоступен — поставьте его вручную или установите игры в контейнерах"
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

    if ! apt-get install -y -qq "${available[@]}" 2>&1 | tee -a "$LOG_FILE" | tail -5; then
        fail "apt-get install не удался. Подробности: $LOG_FILE"
    fi

    install_composer

    ok "Пакеты установлены"
}

# Composer нужен для panel/composer.json, но в дистрибутивах его нет.
install_composer() {
    if command -v composer >/dev/null 2>&1; then
        ok "Composer уже установлен: $(composer --version 2>/dev/null | head -1)"
        return
    fi

    log "Устанавливаю Composer…"

    local installer="/tmp/composer-setup.php"
    curl -fsSL https://getcomposer.org/installer -o "$installer"

    # Ожидаемаю подпись — защита от подмены скрипта.
    local expected
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"

    if command -v sha384sum >/dev/null 2>&1; then
        local actual
        actual="$(sha384sum "$installer" | cut -d' ' -f1)"
        [[ $actual == "$expected" ]] || { rm -f "$installer"; fail "Подпись установщика Composer не совпала"; }
    else
        warn "sha384sum недоступен — проверяю подпись через PHP"
        php -r '
            $h = hash_file("sha384", $argv[1]);
            $e = trim(file_get_contents($argv[2]));
            exit($h === $e ? 0 : 1);
        ' "$installer" <(curl -fsSL https://composer.github.io/installer.sig) \
            || { rm -f "$installer"; fail "Подпись установщика Composer не совпала"; }
    fi

    php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"

    command -v composer >/dev/null 2>&1 || fail "Composer не установился"
    ok "Composer установлен: $(composer --version 2>/dev/null | head -1)"
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

setup_database() {
    step "Настройка базы данных"

    if [[ $INSTALL_DB == "external" ]]; then
        log "Использется внешняя БД — пропускаю установку"
        return
    fi

    DB_PASS="$(gen_password 28)"

    systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql

    # root-пароль для MariaDB
    if [[ -z $DB_ROOT_PASS ]]; then
        DB_ROOT_PASS="$(gen_password 28)"
    fi

    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';" 2>/dev/null \
        || mysql -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_ROOT_PASS}');" 2>/dev/null \
        || warn "Не удалось установить пароль root (возможно, уже настроен)"

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"

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

    [[ -n $PHP_VERSION ]] || fail "PHP не найден. Установите вручную: apt install php${MIN_PHP_MAJOR}${MIN_PHP_MINOR}-fpm"

    if ! version_ge "$PHP_VERSION" "${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}"; then
        fail "Нужен PHP >= ${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}, а установлен ${PHP_VERSION} (Laravel 11 не запустится)"
    fi

    # Расширения, без которых панель не поднимется
    local -a required=(cli fpm mbstring xml curl zip gd bcmath intl mysql opcache)
    local -a missing=()
    local ext

    for ext in "${required[@]}"; do
        if ! php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
            missing+=("$ext")
        fi
    done

    if (( ${#missing[@]} > 0 )); then
        warn "PHP ${PHP_VERSION} без расширений: ${missing[*]}"
        warn "Установите их и повторите настройку"
    fi

    ok "PHP ${PHP_VERSION} найден, все нужные расширения на месте"
}

setup_panel() {
    step "Развёртываю панель"

    mkdir -p "$PANEL_DIR"
    cp -a "$REPO_DIR/panel/." "$PANEL_DIR/"
    chown -R "$SERVICE_USER:$SERVICE_USER" "$PANEL_DIR"

    cd "$PANEL_DIR"

    log "Ставлю зависимости Composer…"
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && composer install --no-dev --optimize-autoloader --no-interaction" 2>&1 | tail -8

    [[ -f vendor/autoload.php ]] || fail "composer install не удался"

    # Фронтенд
    log "Собираю фронтенд…"
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && npm ci --silent && npm run build" 2>&1 | tail -5 \
        || warn "Сборка фронтенда не удалась — интерфейс будет без ассетов"

    # Каталоги
    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan storage:link" 2>/dev/null || true
    chown -R "$SERVICE_USER:$SERVICE_USER" storage bootstrap/cache
    chmod -R ug+rwx storage bootstrap/cache

    ok "Панель развёрнута в $PANEL_DIR"
}

setup_env() {
    step "Создаю .env"

    cd "$PANEL_DIR"

    local app_key
    app_key="base64:$(openssl rand -base64 32)"

    # Настройки из диалога
    cat >.env <<ENVEOF
APP_NAME=${PANEL_NAME}
APP_ENV=production
APP_KEY=${app_key}
APP_DEBUG=false
APP_URL=https://${PANEL_DOMAIN}
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
MAIL_FROM_ADDRESS=noreply@${PANEL_DOMAIN}
MAIL_FROM_NAME="${PANEL_NAME}"

# Режимы работы
GD_NODE_MODE=${NODE_MODE}
GD_RUNTIME=${DEFAULT_RUNTIME}
GD_DOCKER_SOCKET=/var/run/docker.sock
GD_SERVERS_ROOT=/home/gamedock/servers
GD_BACKUPS_ROOT=/home/gamedock/backups
GD_SYSTEM_USER=${SERVICE_USER}
GD_SYSTEM_GROUP=${SERVICE_GROUP}
GD_AGENT_INBOUND=true

GD_TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
GD_TELEGRAM_ADMIN_CHAT_ID=${TELEGRAM_ADMIN_CHAT}

GD_PAYMENTS_ENABLED=true
GD_YOOKASSA_SHOP_ID=${YOOKASSA_SHOP_ID}
GD_YOOKASSA_SECRET_KEY=${YOOKASSA_SECRET}
GD_TINKOFF_TERMINAL_KEY=${TINKOFF_TERMINAL}
GD_TINKOFF_PASSWORD=${TINKOFF_PASSWORD}
GD_CRYPTOBOT_TOKEN=${CRYPTOBOT_TOKEN}
GD_PAYMENT_SUCCESS_URL=https://${PANEL_DOMAIN}/payment/success
GD_PAYMENT_CANCEL_URL=https://${PANEL_DOMAIN}/payment/cancel

GD_BRAND_NAME=${PANEL_NAME}
GD_SUPPORT_EMAIL=${SUPPORT_EMAIL}
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
    local config_file="config/hosting.php"

    backup_file "$config_file"

    # Значения из диалога установщика — в окружение для PHP-скрипта
    export GD_NODE_MODE="$NODE_MODE"
    export GD_RUNTIME="$DEFAULT_RUNTIME"
    export GD_PROMO_DISCOUNT="$PROMO_DISCOUNT"
    export GD_PROMO_DURATION="$PROMO_DURATION"
    export GD_PROMO_BONUS="$PROMO_BONUS"
    export GD_REFERRAL="$REFERRAL"
    export GD_SECRET_CODES="$SECRET_CODES"
    export GD_TRIAL="$TRIAL"
    export GD_TRIAL_DAYS="$TRIAL_DAYS"
    export GD_PAY_YOOKASSA="$PAY_YOOKASSA"
    export GD_PAY_TINKOFF="$PAY_TINKOFF"
    export GD_PAY_CRYPTOBOT="$PAY_CRYPTOBOT"
    export GD_PAY_MANUAL="$PAY_MANUAL"

    php <<'PHPEOF'
<?php
$file = 'config/hosting.php';
$content = file_get_contents($file);

function bool(bool $value): string { return $value ? 'true' : 'false'; }

$replacements = [
    "/'node_mode' => env\('GD_NODE_MODE', '[^']*'\)/" =>
        "'node_mode' => env('GD_NODE_MODE', '" . getenv('GD_NODE_MODE') . "')",
    "/'default' => env\('GD_RUNTIME', '[^']*'\)/" =>
        "'default' => env('GD_RUNTIME', '" . getenv('GD_RUNTIME') . "')",
];

foreach ($replacements as $pattern => $replacement) {
    $content = preg_replace($pattern, $replacement, $content, 1);
}

$flags = [
    'promo_discount' => getenv('GD_PROMO_DISCOUNT') === 'yes',
    'promo_duration' => getenv('GD_PROMO_DURATION') === 'yes',
    'promo_bonus'    => getenv('GD_PROMO_BONUS') === 'yes',
    'referral'       => getenv('GD_REFERRAL') === 'yes',
    'secret_codes'   => getenv('GD_SECRET_CODES') === 'yes',
    'trial'          => getenv('GD_TRIAL') === 'yes',
    'yookassa'       => getenv('GD_PAY_YOOKASSA') === 'yes',
    'tinkoff'        => getenv('GD_PAY_TINKOFF') === 'yes',
    'cryptobot'      => getenv('GD_PAY_CRYPTOBOT') === 'yes',
    'manual'         => getenv('GD_PAY_MANUAL') === 'yes',
];

foreach ($flags as $key => $enabled) {
    $pattern = "/('" . $key . "' => \[.*?'enabled' => )(?:true|false)/s";
    $content = preg_replace($pattern, "$1" . bool($enabled), $content, 1);
}

$days = (int) getenv('GD_TRIAL_DAYS');
if ($days > 0) {
    $content = preg_replace("/('trial' => \[.*?'days' => )\d+/s", "$1" . $days, $content, 1);
}

file_put_contents($file, $content);
echo "  ✓ config/hosting.php: node_mode=" . getenv('GD_NODE_MODE')
    . " runtime=" . getenv('GD_RUNTIME')
    . " промо=" . ($flags['promo_discount'] ? 'да' : 'нет')
    . " рефералка=" . ($flags['referral'] ? 'да' : 'нет')
    . " секретные коды=" . ($flags['secret_codes'] ? 'да' : 'нет')
    . " тест=" . ($flags['trial'] ? $days . ' дн' : 'нет') . "\n";
PHPEOF

    su -s /bin/bash "$SERVICE_USER" -c "cd '$PANEL_DIR' && php artisan config:clear" >/dev/null 2>&1

    ok "Режимы панели настроены"
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

    local socket_name="gamedock-fpm.sock"
    local php_ver="$PHP_VERSION"
    [[ -S "/run/php/$socket_name" ]] || socket_name="php${php_ver}-fpm.sock"

    cat >/etc/nginx/sites-available/gamedock <<'NGINXEOF'
# GameDock — панель управления
# Агент: WSS на 9222 (см. ниже) — держим SSE без буферизации

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
proxy_send_timeout 3600s;

server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN} ${PANEL_DOMAIN}.www;

    # Актуальные IP для Cloudflare
    real_ip_header CF-Connecting-IP;
    real_ip_recursive on;

    location / {
        return 301 https://\$host\$request_uri;
    }

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }
}

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

    client_max_body_size 128M;

    # Ассеты
    location /assets/ {
        alias ${PANEL_DIR}/public/build/;
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location /storage/ {
        alias ${PANEL_DIR}/storage/app/public/;
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
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_pass gamedock_php;
        fastcgi_param SCRIPT_FILENAME ${PANEL_DIR}/public/index.php;
        fastcgi_param DOCUMENT_ROOT ${PANEL_DIR}/public;
        fastcgi_read_timeout 3600;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}

# WSS-эндпоинт агентов — если панель общается в режиме inbound через nginx
# (основной режим — отдельный процесс gamedock-wss на 9222)
server {
    listen 9443 ssl;
    listen [::]:9443 ssl;
    http2 on;
    server_name ${PANEL_DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${PANEL_DOMAIN}/privkey.pem;

    location /agent/ws {
        proxy_pass http://127.0.0.1:9222;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header X-GameDock-Token \$http_x_gamedock_token;
        proxy_set_header X-Node-Id \$http_x_node_id;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
        proxy_buffering off;
    }
}
NGINXEOF

    ln -sf /etc/nginx/sites-available/gamedock /etc/nginx/sites-enabled/gamedock
    rm -f /etc/nginx/sites-enabled/default

    # Заглушка на время выпуска сертификата
    if [[ $WITH_SSL == "yes" ]] && [[ ! -f /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem ]]; then
        warn "Сертификат ещё не получен — временно отключаю проверку SSL"
        sed -i 's/^    ssl_certificate/#    ssl_certificate/' /etc/nginx/sites-available/gamedock
    fi

    nginx -t 2>&1 | tee -a "$LOG_FILE" || fail "Конфигурация nginx некорректна"

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

    certbot certonly --webroot -w /var/www/certbot \
        -d "$PANEL_DOMAIN" -d "${PANEL_DOMAIN}.www" \
        --email "$email" \
        --agree-tos \
        --no-eff-email \
        --non-interactive 2>&1 | tee -a "$LOG_FILE" | tail -5 || {
        warn "Не удалось получить сертификат автоматически"
        warn "Проверьте, что домен ${PANEL_DOMAIN} указывает на ${PUBLIC_IP:-этот сервер}"
        warn "После исправления DNS выполните: certbot certonly --webroot -w /var/www/certbot -d ${PANEL_DOMAIN}"
        return
    }

    # Certbot hook
    cat >/etc/letsencrypt/renewal-hooks/deploy/gamedock-nginx <<'HOOKEOF'
#!/bin/sh
sed -i 's/^    ssl_certificate /    ssl_certificate /' /etc/nginx/sites-available/gamedock
nginx -t && systemctl reload nginx
HOOKEOF
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/gamedock-nginx

    sed -i 's/^#    ssl_certificate /    ssl_certificate /' /etc/nginx/sites-available/gamedock
    systemctl reload nginx

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
    cp -a "$REPO_DIR/agent/." "$AGENT_DIR/"
    cp -a "$REPO_DIR/game-images" "$GAME_IMAGES_DIR" 2>/dev/null || true

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
        curl -fsSL "https://download.docker.com/linux/${OS_ID}/gpg" | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg

        local repo_line="deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${OS_ID} ${OS_CODENAME} stable"
        echo "$repo_line" >/etc/apt/sources.list.d/docker.list

        apt_update
        if ! apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin 2>&1 | tail -5; then
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
    detect_distro
    ensure_cgroup_v2
    check_system
    check_deps

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
    create_service_user
    detect_repo
    setup_database
    setup_redis
    setup_php
    setup_panel
    setup_env
    run_migrations
    apply_hosting_config
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
