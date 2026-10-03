#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════
# Сборка дистрибутива автоустановщика GameDock.
#
# Делает два файла из одного install.sh:
#
#   gamedock-install.sh        — открытый исходник (тот же install.sh)
#   gamedock-install           — бинарник shc для раздачи друзьям
#   gamedock-install.enc.sh    — запасной вариант, если нет компилятора
#
# Запуск (на любой Linux-машине, где стоят bash, openssl и, желательно, gcc):
#
#   bash deploy/build-dist.sh                 # собрать всё, что можно
#   bash deploy/build-dist.sh --shc           # только бинарник shc
#   bash deploy/build-dist.sh --openssl       # только запасной вариант
#   bash deploy/build-dist.sh --check         # только проверить результат
#
# Куда класть результат: каталог из --out, по умолчанию deploy/dist/.
#
# ── Честно про защиту кода ───────────────────────────────────────────
#
# В bash невозможно сделать по-настоящему секретный файл: всё, что
# исполняется на машине, можно прочитать. Разница между вариантами лишь в
# том, сколько усилий придётся приложить.
#
#   shc      компилирует скрипт в ELF. `cat` не покажет исходник, файл
#            выглядит как обычная программа. Но `strings` покажет
#            читаемые строки, а при отладке исходник восстанавливается.
#   openssl  тело зашифровано, ключ лежит рядом в том же файле. Вручную
#            раскрыть сложнее, чем base64, но ключ физически в файле.
#
# Для друга, который не копается намеренно, оба варианта непроницаемы.
# Для того, кто целенаправленно ищет, — нет. Это свойство языка, а не
# недостаток конкретной упаковки.
# ══════════════════════════════════════════════════════════════════════

set -euo pipefail

VERSION="$(grep -oE '^GAMEDOCK_VERSION="\$\{GAMEDOCK_VERSION:-[^}]+\}"' "$(dirname "${BASH_SOURCE[0]}")/install.sh" 2>/dev/null \
    | head -1 | grep -oE '[^}]+\}$' | tr -d '}' || echo "")"
VERSION="${VERSION:-1.0.0}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE="$SCRIPT_DIR/install.sh"
OUT_DIR="$SCRIPT_DIR/dist"

WANT_SHC="yes"
WANT_OPENSSL="yes"
CHECK_ONLY="no"
OPENSSL_KEY=""

# ── Разбор аргументов ────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --shc)      WANT_OPENSSL="no"; shift ;;
        --openssl)  WANT_SHC="no"; shift ;;
        --check)    CHECK_ONLY="yes"; shift ;;
        --out)      OUT_DIR="$2"; shift 2 ;;
        --key)      OPENSSL_KEY="$2"; shift 2 ;;
        --version)  printf '%s\n' "$VERSION"; exit 0 ;;
        -h|--help)  sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)          printf 'Неизвестный параметр: %s\n\n' "$1" >&2
                    sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
                    exit 1 ;;
    esac
done

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'; NC=$'\033[0m'
if [[ ! -t 1 ]]; then RED=""; GREEN=""; YELLOW=""; CYAN=""; NC=""; fi

say()  { printf '%s==>%s %s\n' "$CYAN" "$NC" "$1"; }
ok()   { printf '%s  готово%s  %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%s  внимание%s  %s\n' "$YELLOW" "$NC" "$1"; }
die()  { printf '%s  ошибка%s  %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

# ── Режим проверки ───────────────────────────────────────────────────
if [[ $CHECK_ONLY == "yes" ]]; then
    say "Проверяю собранные файлы в $OUT_DIR"
    found=0
    for f in gamedock-install gamedock-install.enc.sh gamedock-install.sh; do
        p="$OUT_DIR/$f"
        if [[ -f $p ]]; then
            ok "$f — $(wc -c <"$p") байт"
            found=1
        fi
    done
    (( found )) || die "в $OUT_DIR ничего нет — сначала соберите без --check"
    exit 0
fi

# ── Исходник ─────────────────────────────────────────────────────────
[[ -f $SOURCE ]] || die "не найден $SOURCE"
say "Исходник: $SOURCE ($(wc -l <"$SOURCE") строк, $(wc -c <"$SOURCE") байт)"

# Файл должен быть валидным bash — иначе мы запакуем мусор, который
# другу покажется «сломанным бинарником».
if command -v bash >/dev/null 2>&1; then
    if bash -n "$SOURCE" 2>/dev/null; then
        ok "синтаксис исходника в порядке (bash -n)"
    else
        bash -n "$SOURCE" || true
        die "исходник не проходит bash -n — упаковывать нечего"
    fi
fi

mkdir -p "$OUT_DIR"
ok "каталог для результата: $OUT_DIR"

# ── Открытый файл ────────────────────────────────────────────────────
# Ровно копия исходника. Друзьям можно слать и этот файл — он работает
# одинаково, просто исходник виден.
cp -f "$SOURCE" "$OUT_DIR/gamedock-install.sh"
chmod 0755 "$OUT_DIR/gamedock-install.sh"
ok "открытый файл: $OUT_DIR/gamedock-install.sh"

BINARY="$OUT_DIR/gamedock-install"
ENCRYPTED="$OUT_DIR/gamedock-install.enc.sh"

# ── Вариант 1: shc ───────────────────────────────────────────────────
build_shc() {
    say "Собираю бинарник через shc"

    if ! command -v gcc >/dev/null 2>&1 && ! command -v cc >/dev/null 2>&1; then
        warn "компилятора C нет — бинарник shc собрать нечем"
        warn "  Debian/Ubuntu:  sudo apt-get install -y build-essential"
        warn "  Fedora/RHEL:    sudo dnf install -y gcc glibc-devel"
        warn "  Alpine:         apk add build-base"
        return 1
    fi

    if ! command -v shc >/dev/null 2>&1; then
        warn "утилита shc не найдена, пробуем поставить"
        if command -v apt-get >/dev/null 2>&1; then
            DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1 || true
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq shc >/dev/null 2>&1 || true
        elif command -v dnf >/dev/null 2>&1; then
            dnf install -y shc >/dev/null 2>&1 || true
        elif command -v apk >/dev/null 2>&1; then
            apk add --no-cache shc >/dev/null 2>&1 || true
        fi
    fi

    if ! command -v shc >/dev/null 2>&1; then
        warn "shc поставить не удалось — нужен доступ к репозиторию с пакетом shc"
        return 1
    fi

    shc -f "$SOURCE" -o "$BINARY" -e "$(date -u '+%Y-%m-%d %H:%M:%S') UTC" \
        || { warn "shc завершился с ошибкой"; return 1; }

    [[ -f $BINARY ]] || { warn "shc отработал, но бинарника нет"; return 1; }

    chmod 0755 "$BINARY"
    ok "бинарник: $BINARY ($(wc -c <"$BINARY") байт)"

    # Собранный бинарник должен хотя бы запускаться. Провяем --help:
    # установщик на нём выходит сразу и ничего не меняет в системе.
    if ! "$BINARY" --help >/dev/null 2>&1; then
        warn "бинарник не отвечает на --help"
        warn "  Если это «Must be run as root» — так и должно быть, запустите от root"
        warn "  Если Segfault — сборка несовместима с этой системой"
    else
        ok "бинарник отвечает на --help"
    fi

    return 0
}

# ── Вариант 2: openssl ───────────────────────────────────────────────
# Тело шифруется, ключ кладётся в тот же файл. Скрипт при запуске
# расшифровывает во временный файл и выполняет его.
build_openssl() {
    say "Собираю запасной вариант через openssl"

    command -v openssl >/dev/null 2>&1 || {
        warn "openssl не найден — запасной вариант не собрать"
        return 1
    }

    local key
    if [[ -n $OPENSSL_KEY ]]; then
        key="$OPENSSL_KEY"
        warn "вы задали ключ открытым текстом — не делайте так с настоящим ключом"
    else
        key="$(openssl rand -hex 32)"
    fi

    # Шифруем тело. -a даёт base64, -md sha256 — стойкую функцию.
    # Пароль передаём через -pass env, а не аргументом: аргументы видны
    # в списке процессов, пока openssl работает.
    if ! GAMEDOCK_BUILD_KEY="$key" openssl enc -aes-256-cbc -md sha256 -a \
            -in "$SOURCE" -out /tmp/gd-body.b64 -pass env:GAMEDOCK_BUILD_KEY 2>/dev/null; then
        rm -f /tmp/gd-body.b64
        warn "openssl не смог зашифровать"
        return 1
    fi

    {
        cat <<'HEADER'
#!/usr/bin/env bash
# GameDock — установщик (защищённая сборка).
#
# Этот файл создан автоматически, вручную его править не нужно.
# Исходник лежит рядом: gamedock-install.sh
#
# Как это работает: тело установщика зашифровано, ключ лежит в этом же
# файле. При запуске файл расшифровывается во временный и выполняется.
# Это скрывает исходный код от подглядывания, но не делает его
# секретом: ключ физически внутри. Абсолютной защиты в bash не бывает.
set -euo pipefail

GD_SELF="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || printf '%s' "${BASH_SOURCE[0]}")"
GD_TMP="$(mktemp /tmp/gamedock-install.XXXXXX.sh)"
trap 'rm -f "$GD_TMP"' EXIT

HEADER

        printf "GD_KEY='%s'\n" "$key"
        echo
        echo '# Тело начинается после маркера __GD_BODY__. Ключ лежит в самом файле —'
        echo '# это скрывает исходник от подглядывания, но секретом его не делает.'
        echo "if ! awk '/^__GD_BODY__\$/{f=1;next} f' \"\$GD_SELF\" \\"
        echo '        | openssl enc -d -aes-256-cbc -md sha256 -a -pass "pass:$GD_KEY" > "$GD_TMP" 2>/dev/null; then'
        echo '    echo "Не удалось расшифровать установщик." >&2'
        echo '    echo "Файл повреждён или урезан при передаче — попросите прислать заново." >&2'
        echo '    exit 1'
        echo 'fi'
        echo
        echo '[[ -s $GD_TMP ]] || { echo "Расшифрованный файл пуст — файл повреждён." >&2; exit 1; }'
        echo
        echo 'chmod +x "$GD_TMP"'
        echo 'GAMEDOCK_ENCRYPTED=1 exec bash "$GD_TMP" "$@"'
        echo
        echo '__GD_BODY__'
        cat /tmp/gd-body.b64
        echo
    } >"$ENCRYPTED"

    rm -f /tmp/gd-body.b64
    chmod 0755 "$ENCRYPTED"
    ok "запасной вариант: $ENCRYPTED ($(wc -c <"$ENCRYPTED") байт)"

    return 0
}

# ── Сборка ───────────────────────────────────────────────────────────
shc_ok="no"
enc_ok="no"

if [[ $WANT_SHC == "yes" ]]; then
    if build_shc; then shc_ok="yes"; fi
fi

if [[ $WANT_OPENSSL == "yes" ]]; then
    if build_openssl; then enc_ok="yes"; fi
fi

# ── Итог ─────────────────────────────────────────────────────────────
echo
say "Итог"
printf '  открытый исходник : %s\n' "$OUT_DIR/gamedock-install.sh"

if [[ $shc_ok == "yes" ]]; then
    printf '  бинарник shc       : %s\n' "$BINARY"
else
    printf '  бинарник shc       : %sне собран%s\n' "$YELLOW" "$NC"
fi

if [[ $enc_ok == "yes" ]]; then
    printf '  запасной (openssl)  : %s\n' "$ENCRYPTED"
else
    printf '  запасной (openssl)  : %sне собран%s\n' "$YELLOW" "$NC"
fi

echo
cat <<'HINT'
Что отправить другу
-------------------
Отправляйте ОДИН файл — тот, что получился. Не нужно класть рядом
gamedock-install.sh: тогда исходник достанут без труда.

Файл          Когда использовать
-------------  ------------------------------------------------------------
gamedock-install         shc собрался. Выглядит как программа, запускается
                         ./gamedock-install или sudo ./gamedock-install
gamedock-install.enc.sh  компилятора не было. Запускается через bash:
                         bash gamedock-install.enc.sh

Проверить, что файл целый, до отправки:

    ./gamedock-install --help        # должен показать справку
    bash gamedock-install.enc.sh --help

Что помнить
------------
Запуск — только от root: sudo. Без прав администратора установщик
сообщит об этом и выйдет.

shc скрывает исходник от подглядывания, но не шифрует его по-настоящему.
Файл собран на машине сборки — на другой архитектуре он может не
запуститься, тогда подойдёт вариант openssl.

HINT

# Если не собралось ничего — это провал, а не «ну и ладно».
if [[ $shc_ok == "no" && $enc_ok == "no" ]]; then
    die "не удалось собрать ни бинарник, ни запасной вариант"
fi