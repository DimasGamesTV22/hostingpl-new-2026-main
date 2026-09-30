#!/usr/bin/env bash
#
# Установка любой игры через SteamCMD.
#
# Используется играми, у которых installer.type = "steamcmd":
#   cs2, csgo, rust, unturned, ark
#
# Переменные окружения:
#   GD_SERVER_DIR   — каталог установки
#   GD_APP_ID       — Steam AppID (если не задан — берётся из игрового описания)
#   GD_BRANCH       — ветка/бетка: stable | beta
#   GD_SERVER_NAME  — имя для параметров конфигурации
#   GD_SLOTS, GD_GAME_PORT, GD_QUERY_PORT, GD_RCON_PORT
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
APP_ID="${GD_APP_ID:-}"
BRANCH="${GD_BRANCH:-stable}"
MAX_ATTEMPTS=3

log()  { echo -e "\033[36m[steamcmd]\033[0m $*"; }
ok()   { echo -e "\033[32m[steamcmd]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[steamcmd]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"
mkdir -p "$DIR/steamapps" "$DIR/logs"

# ── Поиск SteamCMD ───────────────────────────────────────────────────

find_steamcmd() {
    local candidates=(
        "${GD_STEAMCMD:-}"
        /usr/games/steamcmd/steamcmd
        /usr/local/bin/steamcmd
        /opt/steamcmd/steamcmd
        /usr/bin/steamcmd
    )

    for candidate in "${candidates[@]}"; do
        [[ -n $candidate && -x $candidate ]] && { echo "$candidate"; return 0; }
    done

    # Ищем в домашнем каталоге steamcmd
    for candidate in "$HOME"/Steam/steamcmd.sh "$HOME"/.steam/steamcmd.sh; do
        [[ -x $candidate ]] && { echo "$candidate"; return 0; }
    done

    return 1
}

if ! STEAMCMD="$(find_steamcmd)"; then
    log "SteamCMD не найден — устанавливаю"
    apt-get update -qq 2>/dev/null || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq steamcmd 2>/dev/null || true

    STEAMCMD="$(find_steamcmd)" || fail "Не удалось установить SteamCMD. Пакет steamcmd в репозиториях Debian."
fi

ok "SteamCMD: $STEAMCMD"

[[ -n $APP_ID ]] || fail "Не передан GD_APP_ID"

# ── 32-битные библиотеки (нужны для старых игр) ──────────────────────

if ! dpkg -s lib32gcc-s1 >/dev/null 2>&1; then
    log "Ставлю 32-битные библиотеки…"
    dpkg --add-architecture i386 2>/dev/null || true
    apt-get update -qq 2>/dev/null || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        lib32gcc-s1 lib32stdc++6 2>/dev/null || warn="lib32 не установились"
fi

# ── Скачивание ───────────────────────────────────────────────────────

update_app() {
    local attempt=1

    while (( attempt <= MAX_ATTEMPTS )); do
        log "Загрузка AppID ${APP_ID} (попытка ${attempt}/${MAX_ATTEMPTS})…"

        local args=(
            +force_install_dir "$DIR"
            +login anonymous
            +app_update "$APP_ID"
        )

        if [[ $BRANCH == "beta" ]]; then
            args+=("validate")
        else
            args+=("validate")
        fi

        args+=("+quit")

        if "$STEAMCMD" "${args[@]}"; then
            return 0
        fi

        log "Не удалось. Жду 10 секунд и повторяю…"
        sleep 10
        attempt=$(( attempt + 1 ))
    done

    return 1
}

if ! update_app; then
    fail "SteamCMD не смог загрузить AppID ${APP_ID} после ${MAX_ATTEMPTS} попыток.
  Проверьте:
  • правильность AppID
  • свободное место (нужно от 20 ГБ)
  • доступ к сети Steam
  • логи: $DIR/logs/steamcmd.log"
fi

# ── Проверка результата ──────────────────────────────────────────────

DOWNLOADED=$(du -sh "$DIR" 2>/dev/null | cut -f1)

if [[ "$DOWNLOADED" == "4.0K" || -z $DOWNLOADED ]]; then
    fail "SteamCMD ничего не скачал. Проверьте логи."
fi

ok "Скачано ${DOWNLOADED} в $DIR"

# Сохраняем информацию о версии
cat >steam-appinfo.txt <<'INFOEOF'
AppID: ${APP_ID}
Installed: $(date -Iseconds)
SteamCMD: ${STEAMCMD}
INFOEOF

ok "Готово"
