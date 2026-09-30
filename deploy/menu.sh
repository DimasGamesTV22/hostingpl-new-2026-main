#!/usr/bin/env bash
#
# GameDock — автоустановщик v1.0
#
# Менюшный установщик в духе «MULTYTOOLS»: одно окно, в котором живут все
# операции с сервером — поставить веб-сервер, развернуть панель, поставить
# агента, посмотреть службы и состояние. Пункты вызывают те же скрипты
# install.sh и agent.sh, поэтому линейный (неинтерактивный) сценарий
# продолжает работать сам по себе.
#
# Запуск:
#   curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/menu.sh | sudo bash
#
# Или из репозитория:
#   sudo ./deploy/menu.sh
#
# Без меню (как раньше, одной командой):
#   sudo ./deploy/install.sh --yes
#
# Поддерживаемые системы:
#   Debian  11 (bullseye)  12 (bookworm)  13 (trixie)
#   Ubuntu  22.04 (jammy)   24.04 (noble)
#
# ВАЖНО: пункт «Установить панель» требует веб-сервер. Если его нет,
# установка веб-части недоступна — сначала пункт 1.
#
# Скрипт написан на bash: массивы, [[ ]], process substitution, for (( … )).
# Если его запустили через sh (на Debian это dash), он бы упал с
# «[: not found» и «Bad for loop variable». Поэтому перезапускаем себя под bash.
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi

set -uo pipefail

GAMEDOCK_VERSION="${GAMEDOCK_VERSION:-1.0.0}"
GAMEDOCK_REPO_URL="${GAMEDOCK_REPO:-https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git}"
GAMEDOCK_BRANCH="${GAMEDOCK_BRANCH:-main}"
INSTALL_DIR="${GAMEDOCK_INSTALL_DIR:-/opt/gamedock}"
STATE_DIR=/var/lib/gamedock
LOG_FILE=/var/log/gamedock-menu.log

SERVICE_USER=gamedock
PANEL_DIR="$INSTALL_DIR/panel"
AGENT_DIR="$INSTALL_DIR/agent"

# ── Цвета и символы ───────────────────────────────────────────────────
RED=$'\033[0;31m';    GREEN=$'\033[0;32m';  YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m';   CYAN=$'\033[0;36m';   MAGENTA=$'\033[0;35m'
BOLD=$'\033[1m';      NC=$'\033[0m'
LINE_EQ='='; LINE_DASH='-'

# Ширина рамки меню
WIDTH=78

if [[ ! -t 1 ]]; then
    # Вывод в файл/пайп: экранировать нечего, но цвета оставляем —
    # они делают лог установки читаемым.
    :
fi

# ── Вывод ────────────────────────────────────────────────────────────

# Повторяет символ n раз (для рамок меню)
repeat_char() {
    local ch="$1" n="$2" out=""
    local i
    for (( i = 0; i < n; i++ )); do
        out+="$ch"
    done
    printf '%s' "$out"
}

# Рамка из знаков «равно», как в меню MULTYTOOLS:
# длинная черта, короткая, заголовок в «- - -», снова длинная.
banner() {
    local title="${1:-}"
    local bar short

    bar="$(repeat_char "$LINE_EQ" "$WIDTH")"
    short="$(repeat_char "$LINE_EQ" 10)"

    printf '\n%s%s%s\n' "$GREEN" "$bar" "$NC"

    if [[ -n $title ]]; then
        printf '%s%s%s\n' "$GREEN" "$short" "$NC"
        printf '%s%s %s %s%s\n' "$GREEN" "$LINE_DASH $LINE_DASH" "$title" "$LINE_DASH $LINE_DASH" "$NC"
    fi

    printf '%s%s%s\n' "$GREEN" "$bar" "$NC"
}

# Пункт меню: номер, название, пометка
menu_item() {
    local num="$1" color="$2" name="$3" note="${4:-}"
    if [[ -n $note ]]; then
        printf ' - %s -   %s%s%s%s\n' "$num" "$color" "$name" "$NC" "$YELLOW$note$NC"
    else
        printf ' - %s -   %s%s%s\n' "$num" "$color" "$name" "$NC"
    fi
}

# Подвал меню: подпись по центру в одну строку, как «===BY HACKCHIK===».
# Метка центрируется по оставшимся местам.
menu_footer() {
    local label="$1"
    local text=" $label "
    local rest=$((WIDTH - ${#text}))
    (( rest < 2 )) && rest=2

    local left=$((rest / 2))
    local right=$((rest - left))

    printf '%s%s%s%s%s%s\n' \
        "$GREEN" \
        "$(repeat_char "$LINE_EQ" "$left")" \
        "$text" \
        "$(repeat_char "$LINE_EQ" "$right")" \
        "$NC" ''
}

msg()   { printf '%s[GameDock]%s %s\n' "$BLUE" "$NC" "$*"; }
ok()    { printf '%s  ✓%s %s\n' "$GREEN" "$NC" "$*"; }
info()  { printf '%s  →%s %s\n' "$CYAN" "$NC" "$*"; }
warn()  { printf '%s  !%s %s\n' "$YELLOW" "$NC" "$*"; }
err()   { printf '%s  ✗%s %s\n' "$RED" "$NC" "$*"; }
step()  { printf '\n%s▸ %s%s\n' "$BOLD$CYAN" "$*" "$NC"; }

# Пишет в лог установщика, не мешая выводу на экран
mlog() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

# ── Ввод ─────────────────────────────────────────────────────────────

# Читает номер пункта. Пустой ввод (Enter) означает «в меню».
read_choice() {
    local prompt="${1:-Пожалуйста, введите пункт меню:}"
    local answer

    if [[ ! -t 0 ]]; then
        # Без TTY читать нечего — не зависаем.
        return 1
    fi

    read -rp "$(printf '%s %s%s %s' "$BOLD$CYAN" "$prompt" "$NC" "$BOLD")" answer
    answer="${answer// /}"
    printf '%s' "$answer"
}

# Подтверждение y/n
confirm() {
    local prompt="$1" default="${2:-y}" answer

    if [[ ! -t 0 ]]; then
        [[ $default == "y" ]]
        return
    fi

    local hint="[y/n]"
    [[ $default == "y" ]] && hint="[Y/n]" || hint="[y/N]"

    read -rp "$(printf '%s %s %s %s%s' "$BOLD$CYAN" "$prompt" "$hint" "$NC" "$BOLD")" answer
    answer="${answer,,}"

    if [[ -z $answer ]]; then
        [[ $default == "y" ]]
        return
    fi

    [[ $answer == "y" || $answer == "д" || $answer == "yes" ]]
}

# Вопрос со значением по умолчанию
ask() {
    local prompt="$1" default="${2:-}" answer

    if [[ ! -t 0 ]]; then
        printf '%s' "$default"
        return
    fi

    if [[ -n $default ]]; then
        read -rp "$(printf '%s %s [%s]%s ' "$BOLD$CYAN" "$prompt" "$default" "$NC")" answer
        printf '%s' "${answer:-$default}"
    else
        read -rp "$(printf '%s %s%s ' "$BOLD$CYAN" "$prompt" "$NC")" answer
        printf '%s' "$answer"
    fi
}

pause() {
    [[ -t 0 ]] || return 0
    printf '\n'
    read -rp "$(printf '%sНажмите Enter, чтобы вернуться в меню…%s' "$BOLD" "$NC")" _
}

# ── Проверки состояния системы ───────────────────────────────────────

have_cmd() { command -v "$1" >/dev/null 2>&1; }

# Версия команды: «nginx/1.22.1», «v20.11.0» и т. п.
#
# Защиты здесь обязательны: часть версий пишет в stderr (nginx -v), часть
# команд — шим Windows или зависает, а `command not found` не должен попадать
# в таблицу состояния. Поэтому: проверяем наличие, ограничиваем время и берём
# только первую строку.
cmd_version() {
    local cmd="$1"
    shift

    if ! have_cmd "$cmd"; then
        printf '—'
        return 0
    fi

    local out
    out="$(timeout 5 "$cmd" "$@" 2>&1 | head -1 | tr -d '\r')" || out=""

    if [[ -z $out || $out == *"not found"* || $out == *"not recognized"* ]]; then
        printf '—'
        return 0
    fi

    printf '%s' "$out"
}

# Веб-сервер поставлен? Без него панель поставить нечем.
webserver_installed() {
    have_cmd nginx || have_cmd apache2 || have_cmd caddy
}

# PHP установлен?
php_installed() {
    have_cmd php
}

# Панель уже развёрнута?
panel_installed() {
    [[ -f "$PANEL_DIR/artisan" ]]
}

# Агент уже поставлен?
agent_installed() {
    [[ -f "$AGENT_DIR/bin/gamedock-agent.js" ]] || have_cmd gamedock-agent
}

# База данных?
database_installed() {
    have_cmd mariadb || have_cmd mysql
}

redis_installed() {
    have_cmd redis-cli
}

# ── Где лежат скрипты установщика ─────────────────────────────────────
#
# Порядок тот же, что в install.sh: уже скачанный репозиторий → исходники
# рядом с этим скриптом → официальный репозиторий. При запуске через
# `curl … | sudo bash` соседей не видно, поэтому нужен третий шаг.

SCRIPT_DIR=""
REPO_DIR=""

ensure_repo() {
    step "Готовлю рабочие скрипты"

    if [[ -d "$INSTALL_DIR/.git" ]]; then
        REPO_DIR="$INSTALL_DIR"
        ok "Использую установленный репозиторий: $REPO_DIR"
        return 0
    fi

    if [[ -n ${BASH_SOURCE[0]:-} && -f "${BASH_SOURCE[0]}" ]]; then
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        local candidate
        candidate="$(dirname "$SCRIPT_DIR")"
        if [[ -f "$candidate/deploy/install.sh" ]]; then
            REPO_DIR="$candidate"
            ok "Исходники найдены рядом: $REPO_DIR"
            return 0
        fi
    fi

    if [[ -d "$INSTALL_DIR/deploy" ]]; then
        REPO_DIR="$INSTALL_DIR"
        ok "Скрипты найдены в $REPO_DIR"
        return 0
    fi

    mlog "Клонирую $GAMEDOCK_REPO_URL"
    info "Скачиваю компоненты панели (это нужно один раз)…"

    mkdir -p "$INSTALL_DIR"

    if ! have_cmd git; then
        # Без git ставим минимальный набор, чтобы клонировать было чем.
        info "Ставлю git…"
        DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git >/dev/null 2>&1
    fi

    # Клонируем во временный каталог, а не прямо в $INSTALL_DIR: git
    # отказывается клонировать в непустой каталог, а после неудачной
    # установки там остаются каталоги от прошлого прогона.
    local staging
    staging="$(mktemp -d /tmp/gamedock-menu-clone.XXXXXX)" || {
        err "Не удалось создать временный каталог"
        return 1
    }

    if [[ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]]; then
        warn "Каталог $INSTALL_DIR не пуст — перенесу компоненты поверх"
    fi

    # Ошибку git не глушим: по её тексту видно, приватный репозиторий это
    # или нет сети, и пользователю нужна эта разница.
    if ! git clone --depth 1 --branch "$GAMEDOCK_BRANCH" "$GAMEDOCK_REPO_URL" "$staging"; then
        rm -rf "$staging"
        err "Не удалось скачать компоненты с $GAMEDOCK_REPO_URL"
        warn "Частые причины:"
        warn "  1) репозиторий приватный — скопируйте исходники на сервер и запустите"
        warn "     установщик из корня: bash deploy/install.sh"
        warn "  2) адрес неверный — проверьте $GAMEDOCK_REPO_URL"
        warn "  3) нет доступа в интернет"
        return 1
    fi

    if ! cp -a "$staging/." "$INSTALL_DIR/"; then
        rm -rf "$staging"
        err "Не удалось перенести компоненты в $INSTALL_DIR — проверьте права"
        return 1
    fi

    rm -rf "$staging"

    REPO_DIR="$INSTALL_DIR"
    ok "Компоненты скачаны в $REPO_DIR"
}

# Откуда брать скрипты установки.
#
# Сначала ищем их рядом с самим menu.sh: если человек запустил
# `bash /root/deploy/menu.sh`, то логично, что он возьмёт
# /root/deploy/install.sh, а не какую-нибудь копию из клона.
#
# Раньше было наоборот — всегда $REPO_DIR/deploy/install.sh, и это привело к
# непонятной ситуации: в /root/deploy лежал исправленный установщик, а меню
# запускало устаревший из /opt/gamedock, и правки не применялись. Копии
# расходились по хэшам, а ошибки выглядели как «уже исправленные».
#
# Запасной вариант — каталог установки: он нужен, когда menu.sh запускают
# оттуда, где соседнего install.sh нет.
script_source() {
    local name="$1" here

    if [[ -n ${BASH_SOURCE[0]:-} && -f "${BASH_SOURCE[0]}" ]]; then
        here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        if [[ -f "$here/$name" ]]; then
            printf '%s' "$here/$name"
            return 0
        fi
    fi

    if [[ -f "$INSTALL_DIR/deploy/$name" ]]; then
        printf '%s' "$INSTALL_DIR/deploy/$name"
        return 0
    fi

    return 1
}

# Запускает install.sh с переданными аргументами
run_install() {
    local script
    if ! script="$(script_source install.sh)"; then
        err "Не найден install.sh: ни рядом с menu.sh, ни в $INSTALL_DIR/deploy"
        return 1
    fi

    chmod +x "$script" 2>/dev/null || true
    bash "$script" "$@"
}

# Запускает agent.sh
run_agent() {
    local script
    if ! script="$(script_source agent.sh)"; then
        err "Не найден agent.sh: ни рядом с menu.sh, ни в $INSTALL_DIR/deploy"
        return 1
    fi

    chmod +x "$script" 2>/dev/null || true
    bash "$script" "$@"
}

# ── Пункт 1. Веб-сервер (LAMP) ────────────────────────────────────────

install_webserver() {
    step "Веб-сервер (LAMP) для панели"

    if webserver_installed; then
        ok "Веб-сервер уже стоит: $(webserver_name)"
    else
        info "Будут поставлены: nginx, PHP-FPM, MariaDB, Redis, Composer"
    fi

    if ! confirm "Установить/дописать веб-сервер?" "y"; then
        info "Пропускаю"
        pause
        return 0
    fi

    # Установщик сам развернёт LAMP и спросит домен/почту, но панель на этом
    # шаге не ставим — для этого есть отдельный пункт меню.
    if ! confirm "Поставить только веб-часть (без панели)? Если нет — уйдём в п.2" "y"; then
        info "Хорошо, тогда ставьте панель через пункт 2"
        pause
        return 0
    fi

    run_install --yes "$@"
}

webserver_name() {
    have_cmd nginx && { printf 'nginx'; return; }
    have_cmd apache2 && { printf 'Apache'; return; }
    have_cmd caddy && { printf 'Caddy'; return; }
    printf 'неизвестно'
}

# ── Пункт 2. Панель ──────────────────────────────────────────────────

install_panel() {
    step "Панель управления хостингом GameDock"

    # Ровно то правило, о котором предупреждает оригинал: без веб-сервера
    # веб-часть панели поставить нельзя.
    if ! webserver_installed && ! confirm "Веб-сервер не найден. Установить его сейчас?" "y"; then
        err "Без веб-сервера панель не встанет"
        warn "Вернитесь в меню и выполните пункт 1 (Веб-сервер LAMP)"
        pause
        return 1
    fi

    if panel_installed; then
        ok "Панель уже развёрнута: $PANEL_DIR"
        if ! confirm "Переустановить поверх (настройки будут перезаписаны)?" "n"; then
            pause
            return 0
        fi
    fi

    info "Потребуется домен панели и почта администратора"
    run_install "$@"
}

# ── Пункт 3. Игровое окружение ────────────────────────────────────────

install_game_stack() {
    step "Окружение для игровых серверов"

    cat <<'STACKEOF'
Будут поставлены компоненты, нужные серверам:
  • Java 17 (+ 21, если доступна)     — Minecraft, CS2, Rust, Unturned
  • SteamCMD                          — CS2, Rust, Unturned, ARK
  • build-essential и заголовки       — нативная сборка CRMP/RAGEMP/ALTV
  • screen, tmux                      — фоновые процессы
  • cgroups v2                        — только для рантайма native

Запустить установку? [Y/n]
STACKEOF

    if ! confirm "" "y"; then
        info "Пропускаю"
        pause
        return 0
    fi

    if have_cmd java; then
        ok "Java уже стоит: $(java -version 2>&1 | head -1)"
    fi

    if have_cmd steamcmd || [[ -x /usr/games/steamcmd/steamcmd ]]; then
        ok "SteamCMD уже стоит"
    fi

    info "Компоненты ставятся вместе с панелью (пункт 2)."
    warn "Отдельная установка без панели пока не поддерживается."
    pause
}

# ── Пункт 4. Агент ноды ──────────────────────────────────────────────

install_node_agent() {
    step "Агент ноды"

    if agent_installed; then
        ok "Агент уже установлен"
    else
        info "Агент ставится на КАЖДУЮ ноду, где будут игровые серверы"
    fi

    echo
    info "Нужен токен ноды из панели: Админка → Ноды → Показать токен"
    echo

    run_agent "$@"
}

# ── Пункт 5. Меню CRON/BACKUP ────────────────────────────────────────

menu_cron_backup() {
    local choice

    while true; do
        banner "Меню CRON / BACKUP"
        menu_item 1 "$CYAN" "Статус планировщика (биллинг, автосъём, бэкапы)"
        menu_item 2 "$CYAN" "Запустить планировщик сейчас (разово)"
        menu_item 3 "$CYAN" "Показать последние бэкапы"
        menu_item 4 "$CYAN" "Проверить ротацию логов"
        menu_item 0 "$YELLOW" "Вернуться в меню"
        menu_footer "CRON/BACKUP"

        choice="$(read_choice)" || return 0
        echo

        case "$choice" in
            1) cron_status ;;
            2) cron_run_now ;;
            3) backups_list ;;
            4) logs_rotation ;;
            0) return 0 ;;
            "") return 0 ;;
            *) warn "Нет пункта «$choice»" ;;
        esac

        pause
    done
}

cron_status() {
    step "Планировщик"

    if ! systemctl list-unit-files 2>/dev/null | grep -q gamedock-scheduler; then
        warn "Служба gamedock-scheduler не найдена — сначала поставьте панель"
        return 0
    fi

    local active
    active="$(systemctl is-active gamedock-scheduler 2>/dev/null || echo unknown)"

    if [[ $active == "active" ]]; then
        ok "gamedock-scheduler — работает"
    elif [[ $active == "inactive" ]]; then
        warn "gamedock-scheduler — остановлена. Включить автозапуск? [Y/n]"
        if confirm "" "y"; then
            systemctl enable --now gamedock-scheduler && ok "Запущена"
        fi
    else
        err "gamedock-scheduler — состояние: $active"
        info "journalctl -u gamedock-scheduler -n 50"
    fi

    echo
    info "Что делает планировщик:"
    echo "   • ежедневные начисления за серверы и автосъём при нулевом балансе"
    echo "   • предупреждения об истекающей аренде"
    echo "   • резервные копии по расписанию"
    echo "   • ротация логов игровых серверов"
}

cron_run_now() {
    step "Разовый запуск планировщика"

    if [[ ! -f "$PANEL_DIR/artisan" ]]; then
        warn "Панель не найдена в $PANEL_DIR"
        return 0
    fi

    local task
    task="$(ask "Какое задание выполнить? (billing/alerts/backups/maintenance/all)" "all")"

    info "Запускаю gamedock:scheduler $task…"
    if su -s /bin/bash "$SERVICE_USER" -c \
        "cd '$PANEL_DIR' && php artisan gamedock:scheduler $task" 2>&1 | tail -20; then
        ok "Задание «$task» выполнено"
    else
        err "Задание «$task» завершилось с ошибкой"
    fi
}

backups_list() {
    step "Резервные копии"

    local dir="/home/gamedock/backups"

    if [[ ! -d $dir ]]; then
        warn "Каталог $dir не найден"
        info "Бэкапы серверов делает панель. Настроить расписание:"
        echo "   Панель → Настройки → Бэкапы, или тариф с бэкапами"
        return 0
    fi

    local count
    count="$(find "$dir" -name '*.tar.gz' 2>/dev/null | wc -l)"

    if [[ $count -eq 0 ]]; then
        info "Бэкапов пока нет"
    else
        ok "Всего архивов: $count"
        echo
        ls -lhtr "$dir"/*.tar.gz 2>/dev/null | tail -10 | awk '{printf "   %-12s %8.1f МБ  %s %s %s\n", $9, $5/1048576, $6, $7, $8}'
    fi

    echo
    info "Диск, занятый бэкапами: $(du -sh "$dir" 2>/dev/null | cut -f1)"
}

logs_rotation() {
    step "Ротация логов игровых серверов"

    local logs="/home/gamedock/logs"

    if [[ ! -d $logs ]]; then
        warn "Каталог $logs не найден"
        return 0
    fi

    if [[ ! -f /etc/logrotate.d/gamedock ]]; then
        info "Правило logrotate не создано — настрою"
        if confirm "Создать /etc/logrotate.d/gamedock?" "y"; then
            cat > /etc/logrotate.d/gamedock <<'LOGEOF'
/home/gamedock/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su gamedock gamedock
}
LOGEOF
            ok "Создано: ежедневно, хранить 14 архивов, сжатие"
        fi
    else
        ok "Правило есть: /etc/logrotate.d/gamedock"
        sed 's/^/   /' /etc/logrotate.d/gamedock
    fi

    echo
    info "Живой лог (последние 5 строк):"
    ls -t "$logs"/*.log 2>/dev/null | head -1 | while read -r f; do
        printf '   %s\n' "$f"
        tail -5 "$f" 2>/dev/null | sed 's/^/   /'
    done
}

# ── Пункт 6. Меню СЛУЖБЫ ─────────────────────────────────────────────

SERVICES=(
    "gamedock-wss"
    "gamedock-queue@1"
    "gamedock-scheduler"
    "gamedock-agent@1"
)

menu_services() {
    local choice

    while true; do
        banner "Меню СЛУЖБЫ"
        services_overview
        menu_item 1 "$CYAN" "Запустить все службы панели"
        menu_item 2 "$CYAN" "Остановить все службы панели"
        menu_item 3 "$CYAN" "Перезапустить всё"
        menu_item 4 "$CYAN" "Живые логи (следить)"
        menu_item 0 "$YELLOW" "Вернуться в меню"
        menu_footer "СЛУЖБЫ"

        choice="$(read_choice)" || return 0
        echo

        case "$choice" in
            1) services_action start ;;
            2) services_action stop ;;
            3) services_action restart ;;
            4) services_follow ;;
            0) return 0 ;;
            "") return 0 ;;
            *) warn "Нет пункта «$choice»" ;;
        esac

        pause
    done
}

# Печатает по строке на службу: имя + состояние цветом
services_overview() {
    local svc state color

    for svc in "${SERVICES[@]}"; do
        if ! systemctl list-unit-files 2>/dev/null | grep -q "$svc"; then
            printf '   %-24s %sне установлена%s\n' "$svc" "$YELLOW" "$NC"
            continue
        fi

        state="$(systemctl is-active "$svc" 2>/dev/null || echo unknown)"

        case "$state" in
            active)    color="$GREEN" ;;
            failed)    color="$RED" ;;
            inactive)  color="$YELLOW" ;;
            *)         color="$YELLOW" ;;
        esac

        printf '   %-24s %s%s%s\n' "$svc" "$color" "$state" "$NC"
    done

    echo
}

services_action() {
    local action="$1" svc ok_count=0

    step "Службы: $action"

    for svc in "${SERVICES[@]}"; do
        # Агент ставится не на каждой машине — молча пропускаем.
        if ! systemctl list-unit-files 2>/dev/null | grep -q "$svc"; then
            continue
        fi

        if systemctl "$action" "$svc" 2>/dev/null; then
            ok "$svc"
            ok_count=$((ok_count + 1))
        else
            err "$svc — не удалось ($action)"
        fi
    done

    if (( ok_count == 0 )); then
        warn "Ни одной службы панели не найдено"
    fi
}

services_follow() {
    step "Живые логи"

    local svc
    svc="$(ask "Какую службу смотреть? (wss/queue/scheduler/agent)" "wss")"

    local unit
    case "$svc" in
        wss)       unit="gamedock-wss" ;;
        queue)     unit="gamedock-queue@1" ;;
        scheduler) unit="gamedock-scheduler" ;;
        agent)     unit="gamedock-agent@1" ;;
        *)         unit="gamedock-$svc" ;;
    esac

    info "Ctrl+C — выйти из просмотра"
    journalctl -u "$unit" -f -n 30
}

# ── Пункт 7. Меню СОСТОЯНИЕ ──────────────────────────────────────────

menu_status() {
    local choice

    while true; do
        banner "Меню СОСТОЯНИЕ"
        menu_item 1 "$CYAN" "Обзор системы и версии"
        menu_item 2 "$CYAN" "Диск и память"
        menu_item 3 "$CYAN" "Состояние панели и агента"
        menu_item 0 "$YELLOW" "Вернуться в меню"
        menu_footer "СОСТОЯНИЕ"

        choice="$(read_choice)" || return 0
        echo

        case "$choice" in
            1) status_overview ;;
            2) status_resources ;;
            3) status_installation ;;
            0) return 0 ;;
            "") return 0 ;;
            *) warn "Нет пункта «$choice»" ;;
        esac

        pause
    done
}

# Печатает «имя — значение» с выравниванием
kv() { printf '   %-22s %s\n' "$1" "$2"; }

status_overview() {
    step "Обзор системы"

    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        kv "ОС" "${PRETTY_NAME:-?}"
    fi

    kv "Ядро" "$(uname -r)"
    kv "Архитектура" "$(uname -m)"
    kv "Аптайм" "$(uptime -p 2>/dev/null | sed 's/^up //')"

    echo
    kv "PHP" "$(cmd_version php -v | sed 's/^PHP //; s/ .*//')"
    kv "Node.js" "$(cmd_version node -v)"
    kv "nginx" "$(webserver_name_version)"
    kv "MariaDB/MySQL" "$(database_installed && cmd_version mariadb --version || cmd_version mysql --version)"
    kv "Redis" "$(redis_installed && cmd_version redis-cli --version | awk '{print $3}' || printf '—')"
    kv "Docker" "$(cmd_version docker --version | sed 's/^Docker version //; s/,.*//')"
    kv "cgroups" "$(stat -fc %T /sys/fs/cgroup/ 2>/dev/null || echo '—')"
}

status_resources() {
    step "Ресурсы"

    echo "Диск:"
    df -h / /home 2>/dev/null | awk 'NR==1 || !seen[$6]++ {print "   " $0}'

    echo
    echo "Память:"
    free -h 2>/dev/null | sed 's/^/   /'

    echo
    if [[ -d "$INSTALL_DIR" ]]; then
        kv "Панель" "$(du -sh "$PANEL_DIR" 2>/dev/null | cut -f1 || echo '—')"
    fi
    if [[ -d /home/gamedock/servers ]]; then
        kv "Серверы" "$(du -sh /home/gamedock/servers 2>/dev/null | cut -f1 || echo '—')"
    fi
    if [[ -d /home/gamedock/backups ]]; then
        kv "Бэкапы" "$(du -sh /home/gamedock/backups 2>/dev/null | cut -f1 || echo '—')"
    fi
}

status_installation() {
    step "Что установлено"

    if webserver_installed; then
        kv "Веб-сервер" "$(webserver_name) $(webserver_name_version)"
    else
        kv "Веб-сервер" "не установлен"
    fi

    if php_installed; then
        kv "PHP" "$(cmd_version php -r 'echo PHP_VERSION;')"
    else
        kv "PHP" "не установлен"
    fi

    if database_installed; then
        kv "База данных" "установлена"
    else
        kv "База данных" "не установлена"
    fi

    if redis_installed; then
        kv "Redis" "установлен"
    else
        kv "Redis" "не установлен"
    fi

    if panel_installed; then
        kv "Панель" "установлена — $PANEL_DIR"
        if [[ -f "$PANEL_DIR/.env" ]]; then
            kv "APP_URL" "$(grep -m1 '^APP_URL=' "$PANEL_DIR/.env" 2>/dev/null | cut -d= -f2-)"
        fi
    else
        kv "Панель" "не установлена"
    fi

    if agent_installed; then
        kv "Агент" "установлен"
    else
        kv "Агент" "не установлен"
    fi
}

webserver_name_version() {
    have_cmd nginx && { cmd_version nginx -v | sed 's|.*nginx/||'; return; }
    have_cmd apache2 && { cmd_version apache2 -v | sed 's|.*Apache/||'; return; }
    have_cmd caddy && { cmd_version caddy version; return; }
    printf '—'
}

# ── Пункт 8. Всё в один клик ────────────────────────────────────────

install_all() {
    step "Установка всего сразу"

    cat <<'ALLEOF'
Будет выполнено:
  1. Веб-сервер (nginx + PHP-FPM)
  2. MariaDB и Redis
  3. Панель GameDock + миграции
  4. nginx-виртуал + сертификат Let's Encrypt
  5. Службы: WSS, очереди, планировщик
  6. Агент ноды на этой же машине

Это тот же путь, что и пункт 1 → пункт 2, только без лишних вопросов.
Меня вы спросите домен и почту.

Продолжить? [y/N]
ALLEOF

    if ! confirm "" "n"; then
        info "Отменено"
        pause
        return 0
    fi

    run_install --with-agent --yes "$@"
}

# ── Главное меню ─────────────────────────────────────────────────────

main_menu() {
    local choice

    while true; do
        # Свежий список того, что уже есть, — пункты подсвечиваются.
        local web_mark="" panel_mark="" agent_mark=""
        webserver_installed  && web_mark="[уже стоит]"
        panel_installed      && panel_mark="[уже стоит]"
        agent_installed      && agent_mark="[уже стоит]"

        banner "Добро пожаловать в меню автоустановщика GameDock ${GAMEDOCK_VERSION}!"
        printf '%s   Панель: %s   Веб: %s   Агент: %s%s\n\n' \
            "$CYAN" \
            "$(panel_installed && echo 'установлена' || echo 'нет')" \
            "$(webserver_installed && echo "$(webserver_name)" || echo 'нет')" \
            "$(agent_installed && echo 'есть' || echo 'нет')" \
            "$NC"

        menu_item 1 "$MAGENTA" "Настроить VDS/VPS под WEB Server (LAMP) для панели!" "$web_mark"
        menu_item 2 "$GREEN"    "Установка панели хостинга GameDock!" "$panel_mark"
        menu_item 3 "$CYAN"     "Настроить VDS/VPS под игровые серверы (Java, SteamCMD)!"
        menu_item 4 "$BLUE"     "Установить/обновить агента ноды!" "$agent_mark"
        menu_item 5 "$YELLOW"   "Открыть меню CRON/BACKUP!"
        menu_item 6 "$YELLOW"   "Открыть меню СЛУЖБЫ!"
        menu_item 7 "$YELLOW"   "Открыть меню СОСТОЯНИЕ!"
        menu_item 8 "$BOLD$GREEN" "Установить ВСЁ в один клик (панель + агент)!"
        menu_item 0 "$RED"      "Выход"
        menu_footer "GAMEDOCK"

        choice="$(read_choice)" || {
            echo
            warn "Интерактивный режим недоступен (нет TTY)."
            info "Для автоматической установки: bash deploy/install.sh --yes"
            return 0
        }
        echo

        case "$choice" in
            1) install_webserver ;;
            2) install_panel ;;
            3) install_game_stack ;;
            4) install_node_agent ;;
            5) menu_cron_backup ;;
            6) menu_services ;;
            7) menu_status ;;
            8) install_all ;;
            0) banner "Пока!"; return 0 ;;
            "") ;;   # просто Enter — остаёмся в меню
            *) warn "Нет пункта «$choice». Введите число от 0 до 8." ;;
        esac
    done
}

show_help() {
    cat <<HELPEOF
GameDock — меню автоустановщика ${GAMEDOCK_VERSION}

  sudo bash deploy/menu.sh            открыть меню
  sudo bash deploy/menu.sh --help     эта справка

Меню:
  1  Веб-сервер (nginx + PHP-FPM + MariaDB + Redis) для панели
  2  Панель управления хостингом GameDock  (требует веб-сервер, т.е. пункт 1)
  3  Игровое окружение: Java, SteamCMD, build-зависимости
  4  Агент ноды (токен берётся в панели: Админка → Ноды)
  5  Подменю CRON/BACKUP
  6  Подменю СЛУЖБЫ
  7  Подменю СОСТОЯНИЕ
  8  Панель + агент одной командой
  0  Выход

Переменные окружения:
  GD_AGENT_CONFIG, GD_PANEL, GD_TOKEN, GD_NODE_ID, GD_RUNTIME,
  GD_PANEL_API, GD_PANEL_TOKEN, GD_WEBHOOK_TOKEN

Без меню (одна команда, без вопросов):
  sudo bash deploy/install.sh --yes
  sudo bash deploy/install.sh --domain panel.example.com --email admin@example.com

Репозиторий:
  ${GAMEDOCK_REPO_URL}  (ветка ${GAMEDOCK_BRANCH})
HELPEOF
}

# ── Запуск ───────────────────────────────────────────────────────────

main() {
    local tty_warn=0
    [[ -t 0 ]] || tty_warn=1

    if [[ ${1:-} == "--help" || ${1:-} == "-h" || ${1:-} == "help" ]]; then
        show_help
        return 0
    fi

    # Рамка фиксированной ширины. Считаем символы, а не байты: ${#var}
    # в UTF-8 локали даёт число символов, иначе кириллица «разъезжается».
    local box_w=58
    local title="  GameDock ${GAMEDOCK_VERSION} — установка игрового хостинга"
    local pad=$((box_w - ${#title}))
    (( pad < 0 )) && pad=0

    printf '\n%s╔%s╗%s\n' "$BOLD$CYAN" "$(repeat_char '═' "$box_w")" "$NC"
    printf '%s║%s%s%*s║%s\n' "$BOLD$CYAN" "$NC" "$title" "$pad" '' "$NC"
    printf '%s╚%s╝%s\n' "$BOLD$CYAN" "$(repeat_char '═' "$box_w")" "$NC"

    if (( tty_warn == 1 )); then
        warn "Нет TTY — вопросы задавать нечем, беру значения по умолчанию"
    fi

    [[ $EUID -eq 0 ]] || {
        err "Меню нужно запускать от root: sudo bash menu.sh"
        return 1
    }

    mkdir -p "$STATE_DIR" 2>/dev/null || true
    touch "$LOG_FILE" 2>/dev/null || true

    mlog "Запуск меню v$GAMEDOCK_VERSION от $(whoami)"

    ensure_repo || {
        err "Не удалось подготовить скрипты"
        return 1
    }

    main_menu
}

main "$@"
