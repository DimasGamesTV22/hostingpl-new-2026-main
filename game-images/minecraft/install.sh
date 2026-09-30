#!/usr/bin/env bash
#
# Установка Minecraft (Java) — Paper / Spigot / Folia / Purpur / Vanilla
#
# Агент передаёт переменные окружения:
#   GD_SERVER_DIR  — каталог установки
#   GD_VERSION     — версия Minecraft
#   GD_BUILD       — идентификатор сборки (paper, spigot, vanilla, folia, purpur)
#   GD_SLOTS       — количество слотов
#   GD_GAME_PORT   — игровой порт
#   GD_QUERY_PORT  — query-порт
#   GD_RCON_PORT   — RCON-порт
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
VERSION="${GD_VERSION:-1.21}"
BUILD="${GD_BUILD:-paper}"
SLOTS="${GD_SLOTS:-20}"

log()  { echo -e "\033[36m[minecraft]\033[0m $*"; }
ok()   { echo -e "\033[32m[minecraft]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[minecraft]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

log "Сборка: $BUILD, версия: $VERSION, слоты: $SLOTS"

# ── Java ─────────────────────────────────────────────────────────────
log "Проверяю Java…"

if ! command -v java >/dev/null 2>&1; then
    log "Java не найдена — устанавливаю OpenJDK 21"
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openjdk-21-jre-headless
fi

JAVA_MAJOR=$(java -version 2>&1 | head -1 | sed -E 's/.*version "([0-9]+).*/\1/')
log "Java $JAVA_MAJOR"

# Minecraft 1.20.5+ требует Java 21, раньше хватало 17
if [[ $VERSION == "1.20.5" || $VERSION == "1.21" || $VERSION == "1.21.1" || $VERSION == "1.21.2" || $VERSION == "1.21.3" || $VERSION == "1.21.4" ]]; then
    if (( JAVA_MAJOR < 21 )); then
        log "Для Minecraft $VERSION нужна Java 21 — устанавливаю"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openjdk-21-jre-headless
    fi
fi

# ── Загрузка сборки ──────────────────────────────────────────────────

download_paper() {
    local project="paper" version="$VERSION" build_filter=""

    case "$BUILD" in
        folia)   project="folia" ;;
        purpur)  project="purpur" ;;
    esac

    log "Получаю ссылку на $project $version…"

    local response
    if [[ $project == "purpur" ]]; then
        # Purpur использует отдельный API
        response=$(curl -fsSL "https://api.purpurmc.org/v2/${project}/$(curl -fsSL "https://api.purpurmc.org/v2/${project}" | jq -r --arg v "$VERSION" '.versions[] | select(.endswith($v))' | tail -1)" 2>/dev/null || true)
    else
        response=$(curl -fsSL "https://api.papermc.io/v2/projects/${project}/versions/${version}/builds" 2>/dev/null || true)
    fi

    if [[ -z $response ]]; then
        fail "Не удалось получить список сборок для $project $version. Проверьте версию."
    fi

    # Последняя успешная сборка
    local download_url
    download_url=$(echo "$response" | jq -r '[.builds[] | select(.channel == "default")] | last | .downloads["application:jar"].url' 2>/dev/null || true)

    if [[ -z $download_url || $download_url == "null" ]]; then
        download_url=$(echo "$response" | jq -r '.builds | last | .downloads["application:jar"].url' 2>/dev/null || true)
    fi

    [[ -n $download_url && $download_url != "null" ]] || fail "Сборка не найдена в ответе PaperMC API"

    log "Скачиваю: $download_url"
    curl -fSL --retry 3 -o server.jar "$download_url"
}

download_spigot() {
    log "Скачиваю Spigot через BuildTools…"

    # Spigot не раздаётся напрямую — нужен BuildTools
    command -v java >/dev/null || fail "Нужна Java для BuildTools"

    if [[ ! -d /tmp/BuildTools ]]; then
        log "Клонирую BuildTools (одноразово)…"
        git clone --depth 1 https://hub.spigotmc.org/stash/projects/SPIGOT/repos/buildtools.git /tmp/BuildTools
    fi

    cd /tmp/BuildTools
    ./BuildTools --rev "$VERSION" --compile spigot --output-dir /tmp/spigot-out

    local jar
    jar=$(find /tmp/spigot-out -name "spigot-*.jar" | head -1)
    [[ -f $jar ]] || fail "BuildTools не собрал Spigot"

    cp "$jar" "$DIR/server.jar"
    cd "$DIR"
}

download_vanilla() {
    log "Скачиваю ванильный сервер Mojang…"

    # Официальные манифесты Mojang
    local version_json
    version_json=$(curl -fsSL "https://piston-meta.mojang.com/mc/game/version_manifest_v2.json")

    local url
    url=$(echo "$version_json" | jq -r --arg v "$VERSION" '.versions[] | select(.id == $v) | .url')

    [[ -n $url && $url != "null" ]] || fail "Версия $VERSION не найдена в манифесте Mojang"

    local server_url
    server_url=$(curl -fsSL "$url" | jq -r '.downloads.server.url')

    [[ -n $server_url ]] || fail "Не найден серверный пакет для $VERSION"

    curl -fSL --retry 3 -o server.jar "$server_url"
}

case "$BUILD" in
    paper|folia|purpur) download_paper ;;
    spigot)            download_spigot ;;
    vanilla)           download_vanilla ;;
    *) fail "Неизвестная сборка: $BUILD" ;;
esac

[[ -f server.jar ]] || fail "server.jar не создан"

ok "Серверная сборка готова: $(du -h server.jar | cut -f1)"

# ── EULA ─────────────────────────────────────────────────────────────
log "Принимаю EULA"
echo "eula=true" > eula.txt

# ── Конфигурация ─────────────────────────────────────────────────────
log "Создаю server.properties…"

# Стандартные лимиты игнорируем: панель передаёт их через аргументы JVM
cat >server.properties <<PROPS
# Создано GameDock
server-port=${GD_GAME_PORT:-25565}
query.port=${GD_QUERY_PORT:-25565}
rcon.port=${GD_RCON_PORT:-25575}
rcon.password=${GD_RCON_PASSWORD:-}
enable-rcon=true
broadcast-rcon-to-ops=false

level-name=world
level-type=minecraft\:flat
generator-settings={"layers":[{"block":"bedrock","height":1},{"block":"dirt","height":2},{"block":"grass_block","height":1}],"biome":"plains"}
gamemode=survival
difficulty=normal
motd=${GD_SERVER_NAME:-Сервер GameDock}
max-players=${SLOTS}
online-mode=false
view-distance=8
simulation-distance=8
spawn-protection=16
white-list=false
pvp=true
allow-flight=false
enable-command-block=true
op-permission-level=4
sync-chunk-writes=true
network-compression-threshold=256
hide-online-players=false
max-tick-time=60000
allow-nether=true
enable-status=true
enforce-secure-profile=false
PROPS

# ── bukkit/spigot/paper.yml ──────────────────────────────────────────
log "Создаю конфиги платформы…"

cat >bukkit.yml <<'BUKKIT'
settings:
  allow-end: true
  warn-on-overload: true
  permissions-file: permissions.yml
  update-folder: update
  plugin-profiling: false
  connection-throttle: 4000
  query-plugins: true
  deprecated-verbose: default
  shutdown-message: Сервер выключается
spawn-limits:
  monsters: 70
  animals: 10
  water-animals: 5
  ambient: 15
chunk-gc:
  period-in-ticks: 600
ticks-per:
  animal-spawns: 400
  monster-spawns: 1
  autosave: 6000
BUKKIT

cat >spigot.yml <<'SPIGOT'
settings:
  bungeecord: false
  restart-on-crash: true
  settings-version: 12
  timeout-time: 60
  restart-time: 15
  netty-threads: 0
  save-structure-info: false
  player-shuffle: false
  sample-count: 12
  player-save-limit: -1
  commands:
    blacklist: false
    whitelist: false
    entity-activation-range: animals=32,monsters=32,raiders=48,projectiles=32,vehicles=32,misc=16
    merge-radius: 2.5
    item-despawn: 3000
    spawn-limits: monsters=100,animals=15,water-animals=5,ambient=15
    tps-limit: 20.0
  net:
    compression-threshold: 256
    velocity-packet-threshold: 4
    player-idle-timeout: 0
  messages:
    authentication-server: 'You need to authenticate to join this server!'
    kick:
      authentication: 'You need to authenticate to join this server!'
      out-of-time: 'Timed out'
      server-full: 'The server is full!'
      slow-login: 'Logged in too slowly'
      kicked: 'Kicked by an operator'
messages:
  whitelist: 'Вы не в белом списке'
  server-full: 'Сервер заполнен'
  server-restart: 'Сервер перезапускается'
  no-permission: 'У вас нет прав'
SPIGOT

if [[ $BUILD == "paper" || $BUILD == "folia" ]]; then
cat >paper-global.yml <<'PAPER'
_version: 29
chunk-loading-advanced:
  auto-config-send-distance: true
  player-max-concurrent-chunk-generates: 0
  player-max-concurrent-chunk-loads: 0
console:
  enable-brigadier-completions: true
  enable-brigadier-highlighting: true
  send-averages: false
  log-ips: true
  rate-limit: 0
  spam-threshold: 2
  restart-schedule:
    enable: false
    time: "1000"
  only-restart-for-crashes: false
messages:
  kick:
    authentication: 'You need to authenticate to join this server!'
    server-full: 'The server is full!'
    slow-login: 'Logged in too slowly'
    out-of-time: 'Timed out'
    no-permission: 'You don''t have permission to perform this action! '
    flying-player: 'Flying is not enabled on this server'
    flying-vehicle: 'Flying is not enabled on this server'
    max-vehicles: 'Too many vehicles for this server'
PAPER

cat >paper-world-defaults.yml <<'PAPERW'
_version: 42
chunks:
  auto-send-distance: 10
  spawn-radius: 10
  entity-per-chunk-save-limit: 32
environment:
  disable-explosion-knockback: false
  water-flow-disabled: false
  lava-flow-disabled: false
  disable-chest-cat-detection: true
entity:
  animal-spawn-limits:
    monsters: 50
    animals: 15
    water-animals: 5
    ambient: 15
  nerf-spawns: false
PAPERW
fi

# ── ops.json / whitelist.json ─────────────────────────────────────────
if [[ ! -f ops.json ]]; then
    echo '{"ops":[],"meta":{"formatVersion":2}}' > ops.json
fi

if [[ ! -f whitelist.json ]]; then
    echo '[]' > whitelist.json
fi

# ── Стартовый скрипт (нужен для LXC и ручного запуска) ──────────────
cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
# Запуск Minecraft-сервера (используется LXC-рантаймом)
set -e
cd "$(dirname "$0")"

RAM="${GD_RAM_MB:-2048}"
JAVA_BIN="${JAVA_BIN:-java}"

exec "$JAVA_BIN" \
  -Xms${RAM}M -Xmx${RAM}M \
  -XX:+UseG1GC \
  -XX:MaxGCPauseMillis=50 \
  -XX:+UnlockExperimentalVMOptions \
  -XX:+DisableExplicitGC \
  -XX:G1NewSizePercent=30 \
  -XX:MaxTenuringThreshold=1 \
  -Dcom.mojang.eula.agree=true \
  -jar server.jar nogui
RUNEOF

chmod +x run.sh

# ── Права ────────────────────────────────────────────────────────────
chmod 755 server.jar eula.txt
chmod 644 server.properties bukkit.yml spigot.yml 2>/dev/null || true

if id gamedock >/dev/null 2>&1; then
    chown -R gamedock:gamedock "$DIR" 2>/dev/null || true
fi

ok "Minecraft ($BUILD $VERSION) установлен в $DIR"
