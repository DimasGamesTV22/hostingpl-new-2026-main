#!/usr/bin/env bash
#
# GameDock — установщик агента на ноду игровых серверов.
#
# Запускать на КАЖДОЙ ноде от root:
#   sudo ./agent.sh --panel https://panel.example.com --token ТОКЕН --runtime docker
#
# Поддерживаемые системы (та же матрица, что у deploy/install.sh):
#   Debian 11 (bullseye)   12 (bookworm)   13 (trixie)
#   Ubuntu 22.04 (jammy)    24.04 (noble)
#
# Что делает:
#   1. Проверяет систему по матрице SUPPORTED_SYSTEMS
#   2. Ставит системные зависимости: Node.js 20, JRE, build-essential, SteamCMD
#   3. Ставит выбранный рантайм: Docker / Podman / LXC (Proxmox) / native
#   4. Создаёт пользователя gamedock и каталоги
#   5. Разворачивает агента и шаблоны установки игр
#   6. Ставит systemd-юнит и включает автозапуск
#   7. Проверяет связь с панелью
#
set -euo pipefail

# ═══════════════════════════════════════════════════════════════════
# Матрица поддерживаемых систем
# ═══════════════════════════════════════════════════════════════════
#
# Держится в точности как в deploy/install.sh — расхождение ловит
# panel/tools/test-install-logic.sh.
#
# Формат:  ID|VERSION|CODENAME|PHP_В_ДИСТРИБУТИВЕ
SUPPORTED_SYSTEMS=(
    "debian|11|bullseye|7.4"
    "debian|12|bookworm|8.2"
    "debian|13|trixie|8.4"
    "ubuntu|22.04|jammy|8.1"
    "ubuntu|24.04|noble|8.3"
)

OS_ID=""
OS_VERSION_ID=""
OS_CODENAME=""
OS_PRETTY_NAME=""
OS_SUPPORTED=0
NODE_MAJOR="${GD_NODE_MAJOR:-20}"

# ═══════════════════════════════════════════════════════════════════
# Значения (переопределяются аргументами или переменными окружения)
# ═══════════════════════════════════════════════════════════════════

PANEL_URL="${GD_PANEL:-}"
NODE_TOKEN="${GD_TOKEN:-}"
RUNTIME="${GD_RUNTIME:-docker}"
NODE_ID="${GD_NODE_ID:-1}"
INSTALL_DIR="${GD_AGENT_DIR:-/opt/gamedock}"
AGENT_DIR="$INSTALL_DIR/agent"
GAME_IMAGES_DIR="${GD_GAME_IMAGES:-/opt/gamedock/game-images}"
REPO_URL="${GAMEDOCK_REPO:-}"
BRANCH="${GAMEDOCK_BRANCH:-main}"

INSTALL_NGINX="no"
WITH_JAVA="yes"
WITH_STEAMCMD="yes"
WITH_BUILD_DEPS="yes"
SKIP_TESTS="no"

SERVICE_USER="${GD_SYSTEM_USER:-gamedock}"
SERVICE_GROUP="${GD_SYSTEM_GROUP:-gamedock}"
WS_PORT=9222

LOG_FILE=/var/log/gamedock-agent-install.log

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m'; CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'

log()  { echo -e "${BLUE}[GameDock Agent]${NC} $*" | tee -a "$LOG_FILE"; }
ok()   { echo -e "${GREEN}✓${NC} $*" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}!${NC} $*" | tee -a "$LOG_FILE"; }
fail() { echo -e "${RED}✗${NC} $*" | tee -a "$LOG_FILE"; exit 1; }
step() { echo; echo -e "${BOLD}${CYAN}▸ $*${NC}" | tee -a "$LOG_FILE"; }

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
        [[ $default == "y" || $default == "yes" ]]
        return
    fi

    read -rp "$(echo -e "${BOLD}${prompt}${NC} [y/n] (${default}) ")" answer
    [[ "${answer:-$default}" =~ ^[YyДд] ]]
}

INTERACTIVE=1
[[ -t 0 ]] || INTERACTIVE=0

# ═══════════════════════════════════════════════════════════════════
# Аргументы
# ═══════════════════════════════════════════════════════════════════

while [[ $# -gt 0 ]]; do
    case "$1" in
        --panel)        PANEL_URL="$2"; shift 2 ;;
        --token)        NODE_TOKEN="$2"; shift 2 ;;
        --runtime)      RUNTIME="$2"; shift 2 ;;
        --node-id)      NODE_ID="$2"; shift 2 ;;
        --repo)         REPO_URL="$2"; shift 2 ;;
        --branch)       BRANCH="$2"; shift 2 ;;
        --dir)          INSTALL_DIR="$2"; shift 2 ;;
        --no-java)      WITH_JAVA="no"; shift ;;
        --no-steamcmd)  WITH_STEAMCMD="no"; shift ;;
        --no-build)     WITH_BUILD_DEPS="no"; shift ;;
        --skip-tests)   SKIP_TESTS="yes"; shift ;;
        -y|--yes)       INTERACTIVE=0; shift ;;
        -h|--help)      show_help; exit 0 ;;
        *) fail "Неизвестный параметр: $1 (см. --help)" ;;
    esac
done

show_help() {
    cat <<'HELP'
GameDock Agent — установщик агента игровой ноды

  sudo ./agent.sh --panel https://panel.example.com --token ТОКЕН [опции]

Поддерживаемые системы:
  Debian  11 (bullseye)   12 (bookworm)   13 (trixie)
  Ubuntu  22.04 (jammy)    24.04 (noble)

Обязательные:
  --panel <url>       Адрес панели GameDock
  --token <токен>     Токен ноды из панели (Админка → Ноды)

Опции:
  --runtime <rt>      docker | podman | lxc | native   (по умолчанию docker)
  --node-id <N>       ID ноды в панели (для systemd-инстанса, по умолчанию 1)
  --repo <url>        URL репозитория (если исходников рядом нет)
  --branch <имя>      Ветка репозитория (по умолчанию main)
  --dir <путь>        Каталог установки (по умолчанию /opt/gamedock)
  --no-java           Не ставить Java (если игры не на JVM)
  --no-steamcmd       Не ставить SteamCMD
  --no-build          Не ставить build-essential и dev-пакеты
  --skip-tests        Пропустить проверки
  -y, --yes           Без диалога

Пример:
  sudo ./agent.sh --panel https://panel.example.com --token abc123xyz --runtime docker
HELP
}

# ═══════════════════════════════════════════════════════════════════
# Проверки
# ═══════════════════════════════════════════════════════════════════

check_root() {
    [[ $EUID -eq 0 ]] || fail "Запускать от root: sudo ./agent.sh"
}

check_params() {
    step "Проверяю параметры"

    if [[ -z $PANEL_URL ]]; then
        PANEL_URL=$(ask "Адрес панели GameDock" "")
    fi

    if [[ -z $NODE_TOKEN ]]; then
        NODE_TOKEN=$(ask "Токен ноды (Админка → Ноды)" "")
    fi

    [[ -n $PANEL_URL ]] || fail "Не указан адрес панели (--panel)"
    [[ -n $NODE_TOKEN ]] || fail "Не указан токен ноды (--token)"

    # Нормализуем адрес
    PANEL_URL="${PANEL_URL%/}"
    if [[ ! $PANEL_URL =~ ^https?:// ]]; then
        PANEL_URL="https://${PANEL_URL}"
    fi

    if [[ $INTERACTIVE -eq 1 && $RUNTIME == "docker" ]]; then
        echo
        echo -e "${CYAN}Рантайм игровых процессов:${NC}"
        echo "  docker — контейнеры Docker (рекомендуется, изоляция из коробки)"
        echo "  podman — контейнеры Podman (без демона, можно rootless)"
        echo "  lxc    — контейнеры Proxmox VE (нужен доступ по SSH к PVE)"
        echo "  native — обычные процессы через systemd + cgroup v2 (без Docker)"
        RUNTIME=$(ask "Рантайм" "$RUNTIME")
    fi

    case "$RUNTIME" in
        docker|podman|lxc|native) ok "Рантайм: $RUNTIME" ;;
        *) fail "Неизвестный рантайм «$RUNTIME». Доступны: docker, podman, lxc, native" ;;
    esac

    ok "Панель:   $PANEL_URL"
    ok "Токен:    ${NODE_TOKEN:0:8}…"
}

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

matrix_list() {
    local row
    for row in "${SUPPORTED_SYSTEMS[@]}"; do
        IFS='|' read -r r_id r_ver r_code _r_php <<<"$row"
        printf '%s %s (%s)\n' "$r_id" "$r_ver" "$r_code"
    done
}

detect_distro() {
    step "Определяю систему"

    if [[ ! -f /etc/os-release ]]; then
        fail "Не найден /etc/os-release — это не Debian/Ubuntu"
    fi

    # shellcheck disable=SC1090
    . /etc/os-release

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

Установку панели на любой ОС смотрите docker-compose.yml."
    fi

    OS_SUPPORTED=1
    [[ -n $OS_CODENAME ]] || OS_CODENAME="$codename"

    ok "$OS_PRETTY_NAME — поддерживается"
}

check_system() {
    step "Проверяю систему"

    if [[ $OS_SUPPORTED -ne 1 ]]; then
        fail "Система не определена — сначала detect_distro"
    fi

    if [[ $RUNTIME == "native" ]]; then
        if [[ -f /sys/fs/cgroup/cgroup.controllers ]]; then
            ok "cgroup v2 доступен"
        else
            fail "Для рантайма native нужен cgroup v2.
Включите его в /etc/default/grub: systemd.unified_cgroup_hierarchy=1
Затем update-grub и перезагрузка. На Debian 11 cgroup v1 — норма по умолчанию.
Рантайм docker/podman от этого не зависит — попробуйте --runtime docker."
        fi

        command -v systemctl >/dev/null 2>&1 || fail "Не найден systemd"
    fi

    # Порты игр
    log "Проверяю свободные порты…"
    for port in 25000 25565 27015; do
        if ss -tuln 2>/dev/null | grep -q ":${port} "; then
            warn "Порт ${port} уже занят — возможны конфликты с существующими сервисами"
        fi
    done

    ok "Система готова"
}

# ═══════════════════════════════════════════════════════════════════
# Пакеты
# ═══════════════════════════════════════════════════════════════════

# Добавляет компонент репозитория (main/contrib/universe/…) в sources-файл.
# Возвращает 0, если файл изменён, 1 — если компонент уже был.
apt_add_component() {
    local file="$1" comp="$2"

    if grep -qE "^[[:space:]]*deb(-src)?[[:space:]].*\b${comp}\b" "$file"; then
        return 1
    fi

    if grep -qE "^[[:space:]]*#[[:space:]]*deb(-src)?[[:space:]].*\b${comp}\b" "$file"; then
        sed -i -E "s|^[[:space:]]*#[[:space:]]*(deb(-src)?[[:space:]].*)$|\1|" "$file"
        return 0
    fi

    if grep -qE "^[[:space:]]*deb(-src)?[[:space:]]" "$file"; then
        sed -i -E "0,/^[[:space:]]*(deb(-src)?[[:space:]]+.*)$/s//\1 ${comp}/" "$file"
        return 0
    fi

    return 1
}

# Включает дополнительные компоненты: без них не находятся steamcmd
# и часть заголовков для сборки игр.
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

    apt-get update -qq
}

pkg_available() {
    apt-cache show "$1" >/dev/null 2>&1
}

setup_nodejs_repo() {
    log "Подключаю NodeSource для Node.js ${NODE_MAJOR}.x…"

    apt-get install -y -qq --no-install-recommends ca-certificates curl gnupg >/dev/null
    mkdir -p /etc/apt/keyrings
    curl -fsSL "https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key" \
        | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    chmod a+r /etc/apt/keyrings/nodesource.gpg

    cat >/etc/apt/sources.list.d/nodesource.list <<NODESOURCEEOF
deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main
NODESOURCEEOF

    apt-get update -qq
}

install_dependencies() {
    step "Ставлю зависимости"

    export DEBIAN_FRONTEND=noninteractive

    enable_apt_components
    setup_nodejs_repo

    local packages=(
        curl ca-certificates gnupg apt-transport-https
        git unzip zip tar xz-utils bzip2 jq
        chrony
    )

    if [[ $WITH_BUILD_DEPS == "yes" ]]; then
        packages+=(
            build-essential cmake pkg-config
            libssl-dev libcurl4-openssl-dev libicu-dev
            libsdl2-dev libncursesw5-dev
        )
    fi

    if [[ $WITH_JAVA == "yes" ]]; then
        # Java 17 есть везде, Java 21 — не на всех системах
        packages+=(openjdk-17-jre-headless)
        if pkg_available openjdk-21-jre-headless; then
            packages+=(openjdk-21-jre-headless)
        else
            warn "openjdk-21-jre-headless недоступен в $OS_PRETTY_NAME — ставится только Java 17"
        fi
    fi

    # SteamCMD для CS2, Rust, Unturned, ARK
    if [[ $WITH_STEAMCMD == "yes" ]]; then
        if ! command -v steamcmd >/dev/null 2>&1 && [[ ! -x /usr/games/steamcmd/steamcmd ]]; then
            if pkg_available steamcmd; then
                packages+=(steamcmd)
            else
                warn "Пакет steamcmd недоступен — поставьте его вручную или используйте контейнеры"
            fi
        fi
    fi

    # PHP для ALTV и PocketMine (нативный рантайм)
    if [[ $RUNTIME != "docker" ]] || [[ $WITH_JAVA == "yes" ]]; then
        packages+=(php-cli php-mbstring php-curl php-xml php-zip)
        # php-sqlite3 нужен не везде
        pkg_available php-sqlite3 && packages+=(php-sqlite3)
    fi

    # Под apt-get install -e одна отсутствующая позиция обрушила бы установку,
    # поэтому отбрасываем то, чего в репозиториях нет.
    local -a available=()
    local -a missing=()
    local pkg
    for pkg in "${packages[@]}"; do
        if pkg_available "$pkg"; then
            available+=("$pkg")
        else
            missing+=("$pkg")
        fi
    done

    if (( ${#missing[@]} > 0 )); then
        warn "Не найдены в репозиториях и будут пропущены: ${missing[*]}"
    fi

    log "Устанавливаю ${#available[@]} пакетов…"
    if ! apt-get install -y -qq "${available[@]}" 2>&1 | tee -a "$LOG_FILE" | tail -3; then
        fail "apt-get install не удался. Подробности: $LOG_FILE"
    fi

    # Node.js >= 20 нужен агенту
    if ! command -v node >/dev/null 2>&1 || [[ "$(node -v | sed 's/v//; s/\..*//')" -lt 20 ]]; then
        log "Ставлю Node.js ${NODE_MAJOR}…"
        apt-get install -y -qq nodejs 2>&1 | tail -2
    fi

    if command -v node >/dev/null 2>&1; then
        local major
        major="$(node -v | sed 's/v//; s/\..*//')"
        if (( major < 20 )); then
            fail "Node.js $(node -v) слишком старый — агенту нужен >= 20. Проверьте NodeSource."
        fi
        ok "Node.js $(node -v)"
    else
        fail "Node.js не установился"
    fi
}

create_user() {
    step "Создаю пользователя и каталоги"

    if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
        adduser --system --group --home /home/gamedock --shell /usr/sbin/nologin "$SERVICE_USER"
        ok "Пользователь $SERVICE_USER создан"
    else
        ok "Пользователь $SERVICE_USER уже есть"
    fi

    mkdir -p "$INSTALL_DIR" "$GAME_IMAGES_DIR" \
             /home/gamedock/servers /home/gamedock/backups /home/gamedock/plugins

    chmod 750 /home/gamedock/servers /home/gamedock/backups
    chown -R "$SERVICE_USER:$SERVICE_USER" /home/gamedock
    chown "$SERVICE_USER:$SERVICE_USER" "$INSTALL_DIR"

    ok "Каталоги готовы"
}

# ═══════════════════════════════════════════════════════════════════
# Рантаймы
# ═══════════════════════════════════════════════════════════════════

install_runtime() {
    case "$RUNTIME" in
        docker) install_docker ;;
        podman) install_podman ;;
        lxc)    prepare_lxc ;;
        native) prepare_native ;;
    esac
}

install_docker() {
    step "Устанавливаю Docker Engine"

    if command -v docker >/dev/null 2>&1; then
        ok "Docker уже установлен: $(docker --version)"
    else
        install -m 0755 -d /etc/apt/keyrings
        # download.docker.com держит отдельные ветки для debian и ubuntu
        curl -fsSL "https://download.docker.com/linux/${OS_ID}/gpg" | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg

        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${OS_ID} ${OS_CODENAME} stable" \
            >/etc/apt/sources.list.d/docker.list

        apt-get update -qq
        if ! apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin 2>&1 | tail -3; then
            fail "Не удалось поставить Docker из download.docker.com/linux/${OS_ID}"
        fi

        ok "Docker установлен"
    fi

    systemctl enable --now docker

    # Агент должен работать от пользователя gamedock
    usermod -aG docker "$SERVICE_USER"

    # cgroup v2 — обязателен для лимитов CPU/RAM контейнеров
    mkdir -p /etc/systemd/system/docker.service.d
    cat >/etc/systemd/system/docker.service.d/limits.conf <<'EOF'
[Service]
CPUAccounting=true
MemoryAccounting=true
EOF

    systemctl daemon-reload
    systemctl restart docker

    # Проверка
    if sudo -u "$SERVICE_USER" docker info >/dev/null 2>&1; then
        ok "Пользователь $SERVICE_USER имеет доступ к Docker"
    else
        warn "Нет доступа к Docker-сокету — перезайдите: newgrp docker"
    fi

    # Тестовый контейнер
    if [[ $SKIP_TESTS == "no" ]]; then
        if timeout 60 docker run --rm hello-world >/dev/null 2>&1; then
            ok "Docker работает (тестовый контейнер запущен)"
        else
            warn "Тестовый контейнер не запустился — проверьте: docker run hello-world"
        fi
    fi
}

install_podman() {
    step "Устанавливаю Podman"

    if command -v podman >/dev/null 2>&1; then
        ok "Podman уже установлен: $(podman --version)"
    else
        apt-get install -y -qq podman
        ok "Podman установлен"
    fi

    # Podman работает без демона
    podman info >/dev/null 2>&1 && ok "Podman работает" || warn "Проверьте podman info"

    # Rootless-конфигурация, если агент работает не от root
    if [[ $(id -u) -ne 0 ]]; then
        mkdir -p /etc/containers
        cat >/etc/containers/containers.conf <<EOF
[containers]
cgroup_manager = "systemd"
netns = "slirp4netns"
EOF
    fi
}

prepare_lxc() {
    step "Готовлю рантайм Proxmox LXC"

    if [[ ! -d /etc/pve ]]; then
        warn "Эта машина не похожа на Proxmox VE"
        warn "Рантайм lxc управляет контейнерами на PVE-хосте по SSH."
        warn "Укажите в agent.config.json: lxc.pveHost, lxc.pveUser, lxc.template, lxc.storage"
    else
        ok "Обнаружен Proxmox VE"
    fi

    local pve_host
    pve_host=$(ask "Адрес Proxmox-хоста" "127.0.0.1")
    local pve_user
    pve_user=$(ask "SSH-пользователь PVE" "root@pam")

    if [[ $INTERACTIVE -eq 1 ]]; then
        if ask_yes_no "Настроить SSH-ключ для PVE (ssh-copy-id)?" "y"; then
            ssh-copy-id -o StrictHostKeyChecking=accept-new "${pve_user}@${pve_host}" 2>&1 | tail -2 || \
                warn "Не удалось — настройте ключ вручную"
        fi
    fi

    LXC_PVE_HOST="$pve_host"
    LXC_PVE_USER="$pve_user"

    ok "LXC настроен на ${pve_user}@${pve_host}"
}

prepare_native() {
    step "Готовлю нативный рантайм"

    if [[ ! -f /sys/fs/cgroup/cgroup.controllers ]]; then
        fail "cgroup v2 обязателен. Включите: GRUB_CMDLINE_LINUX=\"systemd.unified_cgroup_hierarchy=1\""
    fi

    # Создаём cgroup и systemd-слайс
    mkdir -p /sys/fs/cgroup/gamedock 2>/dev/null || true

    if ! systemctl list-unit-files 2>/dev/null | grep -q gamedock.slice; then
        cat >/etc/systemd/system/gamedock.slice <<'EOF'
[Slice]
MemoryAccounting=yes
CPUAccounting=yes
EOF
        systemctl daemon-reload
    fi

    # user.slice не даст менять лимиты — ограничиваем владельца
    ok "cgroup v2 на месте, systemd-слайс создан"
}

# ═══════════════════════════════════════════════════════════════════
# Агент
# ═══════════════════════════════════════════════════════════════════

deploy_agent() {
    step "Разворачиваю агента"

    local source_dir=""

    # 1. Исходники рядом с этим скриптом
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local candidate
    candidate="$(dirname "$script_dir")"

    if [[ -d "$candidate/agent/src/index.js" ]]; then
        source_dir="$candidate"
    fi

    # 2. Клонируем репозиторий
    if [[ -z $source_dir ]]; then
        if [[ -z $REPO_URL ]]; then
            read -rp "URL репозитория GameDock: " REPO_URL
        fi

        [[ -n $REPO_URL ]] || fail "Не удалось найти исходники и не указан --repo"

        mkdir -p "$INSTALL_DIR"
        source_dir="$INSTALL_DIR"

        if [[ -d "$source_dir/.git" ]]; then
            git -C "$source_dir" pull --ff-only || warn "git pull не удался"
        else
            git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$source_dir" || fail "git clone не удался"
        fi
    fi

    ok "Исходники: $source_dir"

    # Копируем агента и шаблоны игр
    mkdir -p "$AGENT_DIR"
    cp -a "$source_dir/agent/." "$AGENT_DIR/"

    if [[ -d "$source_dir/game-images" ]]; then
        rm -rf "$GAME_IMAGES_DIR"
        cp -a "$source_dir/game-images" "$GAME_IMAGES_DIR"
        ok "Шаблоны установки игр в $GAME_IMAGES_DIR"
    else
        warn "Каталог game-images не найден в репозитории — скрипты установки игр работать не будут"
    fi

    chown -R "$SERVICE_USER:$SERVICE_USER" "$AGENT_DIR" "$GAME_IMAGES_DIR"

    # Зависимости Node
    cd "$AGENT_DIR"
    log "Ставлю зависимости npm…"
    npm ci --omit=dev --silent 2>&1 | tail -3 || npm install --omit=dev --silent 2>&1 | tail -3

    ok "Агент развёрнут в $AGENT_DIR"
}

write_config() {
    step "Пишу конфигурацию агента"

    mkdir -p /etc/gamedock

    local ws_host="$PANEL_URL"
    local ws_port="9222"

    # Схема WSS
    local ws_url
    if [[ $ws_host =~ ^https://(.*)$ ]]; then
        ws_url="wss://${BASH_REMATCH[1]}/agent/ws"
    else
        ws_url="ws://${ws_host}/agent/ws"
    fi

    cat >"/etc/gamedock/agent-${NODE_ID}.json" <<AGENTEOF
{
  "panel": "${PANEL_URL}",
  "wsPath": "/agent/ws",
  "token": "${NODE_TOKEN}",
  "nodeId": ${NODE_ID},
  "runtime": "${RUNTIME}",
  "serversRoot": "/home/gamedock/servers",
  "backupsRoot": "/home/gamedock/backups",
  "templatesRoot": "${GAME_IMAGES_DIR}",
  "pluginsRoot": "/home/gamedock/plugins",
  "systemUser": "${SERVICE_USER}",
  "systemGroup": "${SERVICE_GROUP}",
  "docker": {
    "socket": "/var/run/docker.sock",
    "networkPrefix": "gamedock",
    "defaultImage": "debian:12-slim"
  },
  "podman": {
    "socket": "unix:///run/podman/podman.sock",
    "networkPrefix": "gamedock"
  },
  "lxc": {
    "pveHost": "${LXC_PVE_HOST:-127.0.0.1}",
    "pveUser": "${LXC_PVE_USER:-root@pam}",
    "template": "local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst",
    "storage": "local-lvm",
    "bridge": "vmbr0"
  },
  "native": {
    "cgroupRoot": "/sys/fs/cgroup/gamedock",
    "systemdScope": "gamedock.slice"
  },
  "secretCodes": {
    "prefixes": ["//", "!", "/promo"]
  },
  "logging": {
    "level": "info",
    "file": "/var/log/gamedock-agent.log"
  }
}
AGENTEOF

    # Точка входа для systemd
    cat >/etc/gamedock/agent.env <<ENVEOF
GD_AGENT_CONFIG=/etc/gamedock/agent-${NODE_ID}.json
ENVEOF

    chmod 600 "/etc/gamedock/agent-${NODE_ID}.json"
    chmod 644 /etc/gamedock/agent.env

    ok "Конфигурация: /etc/gamedock/agent-${NODE_ID}.json"
    log "WSS-адрес панели для агента: $ws_url"
}

install_systemd() {
    step "Ставлю systemd-юнит"

    cat >/etc/systemd/system/gamedock-agent@.service <<EOF
[Unit]
Description=GameDock node agent (node %i)
Documentation=https://github.com/your-org/gamedock
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_USER}
WorkingDirectory=${AGENT_DIR}
EnvironmentFile=/etc/gamedock/agent.env
Environment=NODE_ENV=production
Environment=GD_AGENT_CONFIG=/etc/gamedock/agent-%i.json
ExecStart=/usr/bin/node ${AGENT_DIR}/src/index.js
Restart=always
RestartSec=10
RestartKillSignal=SIGTERM
TimeoutStopSec=30
KillSignal=SIGTERM

StandardOutput=journal
StandardError=journal
SyslogIdentifier=gamedock-agent@%i

# Агент создаёт процессы и файлы — нужен доступ
LimitNOFILE=65535
LimitNPROC=8192
LimitCORE=0

# Умеренная изоляция
NoNewPrivileges=no
PrivateTmp=yes
ProtectHome=no
ProtectSystem=full
ReadWritePaths=/home/gamedock /var/log /tmp /run

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable --now "gamedock-agent@${NODE_ID}.service"

    sleep 3

    if systemctl is-active --quiet "gamedock-agent@${NODE_ID}.service"; then
        ok "Сервис gamedock-agent@${NODE_ID} запущен"
    else
        warn "Сервис не запустился. Логи: journalctl -u gamedock-agent@${NODE_ID} -n 50"
        return
    fi
}

setup_firewall() {
    step "Открываю порты в файрволе"

    if ! command -v ufw >/dev/null 2>&1; then
        return
    fi

    ufw allow 22/tcp comment 'SSH' >/dev/null 2>&1 || true

    if [[ $RUNTIME == "native" ]]; then
        ufw allow 25000:25999/tcp comment 'GameDock game ports' >/dev/null 2>&1 || true
        ufw allow 25000:25999/udp comment 'GameDock game ports UDP' >/dev/null 2>&1 || true
        ufw allow 26000:26999/udp comment 'GameDock query ports' >/dev/null 2>&1 || true
        ufw allow 27000:27199/tcp comment 'GameDock RCON ports' >/dev/null 2>&1 || true
        ufw allow 25565/tcp comment 'Minecraft' >/dev/null 2>&1 || true
        ufw allow 25565/udp comment 'Minecraft Bedrock' >/dev/null 2>&1 || true
        ufw allow 27015:27030/tcp comment 'Valve games' >/dev/null 2>&1 || true
        ufw allow 27015:27030/udp comment 'Valve games UDP' >/dev/null 2>&1 || true
        ok "Порты игр открыты (для рантайма native)"
    else
        ufw allow 7777/tcp comment 'Pterodactyl' >/dev/null 2>&1 || true
        ufw allow 25.65.255.65/tcp comment 'Minecraft alternative' >/dev/null 2>&1 || true
        log "Для контейнерного рантайма порты публикуются автоматически (docker-proxy)"
    fi

    # Связь с панелью — исходящая, поэтому исходящие не блокируем
    ok "Файрвол настроен"
}

optimize() {
    step "Оптимизирую систему под игровые серверы"

    cat >/etc/sysctl.d/99-gamedock-agent.conf <<'SYSCTL'
# GameDock Agent — тюнинг под игровые нагрузки

# Сокеты
net.core.somaxconn = 8192
net.core.netdev_max_backlog = 8192
net.ipv4.tcp_max_syn_backlog = 8192
net.ipv4.ip_local_port_range = 10240 65000
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_slow_start_after_idle = 0
net.ipv4.tcp_keepalive_time = 300
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 5

# UDP (SAMP, Bedrock, RAGE.MP, Rust)
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.udp_rmem_min = 16384
net.ipv4.udp_wmem_min = 16384

# Пропускная способность
net.ipv4.tcp_congestion_control = bbr
net.core.default_qdisc = fq
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_mtu_probing = 1

# Память
vm.swappiness = 10
vm.vfs_cache_pressure = 50
vm.max_map_count = 262144

# Стабильность
net.ipv4.tcp_sack = 1
net.ipv4.tcp_timestamps = 1
SYSCTL

    sysctl --system >/dev/null 2>&1 || warn "sysctl не применился"
    ok "Параметры ядра настроены"
}

verify() {
    step "Проверяю связь с панелью"

    sleep 5

    local status
    status=$(systemctl is-active "gamedock-agent@${NODE_ID}.service" 2>/dev/null || echo "inactive")

    if [[ $status != "active" ]]; then
        warn "Сервис не активен. Последние строки журнала:"
        journalctl -u "gamedock-agent@${NODE_ID}.service" -n 15 --no-pager | sed 's/^/    /'
        return
    fi

    # Ищем в логах успешное подключение
    local connected
    connected=$(journalctl -u "gamedock-agent@${NODE_ID}.service" --since "2 minutes ago" --no-pager 2>/dev/null \
        | grep -c "Соединение установлено" || echo 0)

    if (( connected > 0 )); then
        ok "Соединение с панелью установлено"
    else
        warn "Не вижу подключения к панели. Проверьте сетевой доступ и токен."
        journalctl -u "gamedock-agent@${NODE_ID}.service" -n 15 --no-pager | sed 's/^/    /'
    fi

    # Проверяем рантайм
    if journalctl -u "gamedock-agent@${NODE_ID}.service" --since "2 minutes ago" --no-pager 2>/dev/null \
        | grep -q "Рантайм ${RUNTIME}: доступен"; then
        ok "Рантайм ${RUNTIME} готов к работе"
    else
        warn "Рантайм ${RUNTIME} может быть недоступен — смотрите журнал"
    fi
}

print_summary() {
    local public_ip
    public_ip=$(curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')

    cat <<SUMMARY

$(echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${NC}")
$(echo -e "${BOLD}${GREEN}║           Агент ноды установлен и подключён               ║${NC}")
$(echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${NC}")

  Нода:            ${NODE_ID}
  Рантайм:         ${RUNTIME}
  Панель:          ${PANEL_URL}
  IP ноды:         ${public_ip:-определите вручную}
  Агент:           ${AGENT_DIR}
  Шаблоны игр:     ${GAME_IMAGES_DIR}
  Серверы:         /home/gamedock/servers
  Бэкапы:          /home/gamedock/backups

  Панель → Админка → Ноды: проверьте, что нода в статусе «Онлайн»,
  а IP ноды совпадает с указанным выше (иначе игроки не смогут подключиться).

  Управление:
    systemctl status gamedock-agent@${NODE_ID}
    journalctl -u gamedock-agent@${NODE_ID} -f        # логи в реальном времени
    systemctl restart gamedock-agent@${NODE_ID}

  В панели эта нода теперь может принимать серверы.
  Проверить: создайте тестовый сервер и посмотрите журнал установки.

SUMMARY
}

# ═══════════════════════════════════════════════════════════════════
main() {
    echo
    echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${CYAN}║       GameDock Agent — установка агента игровой ноды      ║${NC}"
    echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════════════╝${NC}"
    echo

    check_root
    check_params
    detect_distro
    check_system
    install_dependencies
    create_user
    install_runtime
    deploy_agent
    write_config
    install_systemd
    setup_firewall
    optimize

    if [[ $SKIP_TESTS == "no" ]]; then
        verify
    fi

    print_summary
}

main "$@"
