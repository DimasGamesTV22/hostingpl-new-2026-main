#!/usr/bin/env bash
#
# Тесты логики определения ОС в deploy/install.sh.
#
# Установщик написан вслепую (PHP/Python на машине разработки нет), поэтому
# чистые функции — матрица систем, сравнение версий, detect_distro — проверяются
# здесь, на настоящем bash.
#
# Запуск: bash panel/tools/test-install-logic.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

# Счётчики теста называются t_ok/t_bad, чтобы не путаться с ok()/fail()
# установщика, которые здесь заглушены.
t_ok()  { pass=$((pass + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
t_bad() { fail=$((fail + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [[ $# -gt 1 ]] && printf '       %s\n' "$2"; return 0; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

eq() {
    if [[ "$2" == "$3" ]]; then t_ok "$1"; else t_bad "$1" "ожидалось '$3', получено '$2'"; fi
}

# ── Готовим исполняемую копию установщика без main() ────────────────────
sed -e 's#^LOG_FILE=.*#LOG_FILE='"$TMP"'/test.log#' \
    -e '/^main "\$@"$/d' \
    "$ROOT/deploy/install.sh" > "$TMP/install.sh"

# shellcheck disable=SC1090
source "$TMP/install.sh" >/dev/null 2>&1

# Установщик объявляет `set -euo pipefail`, и после source он действует на весь
# тест. Здесь это мешает: мы намеренно вызываем функции, которые падают,
# а unbound-переменные тест-фреймворк не должен считать своей ошибкой.
# Отдельно поведение под nounset проверяется в блоке detect_distro ниже.
set +eu

# Заглушки для вывода установщика. Имена с префиксом stub_, чтобы не перебить
# счётчики самого теста (ok/fail здесь — часть тест-фреймворка).
stub_log()  { :; }
stub_ok()   { :; }
stub_step() { :; }
stub_warn() { :; }
stub_fail() { FAILED_MSG="$1"; return 1; }

log()   { stub_log; }
ok()    { stub_ok; }
step()  { stub_step; }
warn()  { stub_warn; }
# "$@" обязателен: иначе stub_fail не увидит текст сообщения
fail()  { stub_fail "$@"; }

# ── Матрица поддерживаемых систем ──────────────────────────────────────

head_ "Матрица систем"

eq "в матрице 5 систем" "${#SUPPORTED_SYSTEMS[@]}" "5"

eq "Debian 11 найден"      "$(matrix_lookup debian 11 3 || true)" "bullseye"
eq "Debian 12 найден"      "$(matrix_lookup debian 12 3 || true)" "bookworm"
eq "Debian 13 найден"      "$(matrix_lookup debian 13 3 || true)" "trixie"
eq "Ubuntu 22.04 найден"   "$(matrix_lookup ubuntu 22.04 3 || true)" "jammy"
eq "Ubuntu 24.04 найден"   "$(matrix_lookup ubuntu 24.04 3 || true)" "noble"

eq "Debian 10 не найден"   "$(matrix_lookup debian 10 3 || true)" ""
eq "Debian 14 не найден"   "$(matrix_lookup debian 14 3 || true)" ""
eq "Ubuntu 20.04 не найден" "$(matrix_lookup ubuntu 20.04 3 || true)" ""

if matrix_has debian 12 && matrix_has ubuntu 24.04; then
    t_ok "matrix_has работает для поддерживаемых"
else
    t_bad "matrix_has работает для поддерживаемых"
fi

if matrix_has debian 10 2>/dev/null; then
    t_bad "matrix_has отвергает неподдерживаемые"
else
    t_ok "matrix_has отвергает неподдерживаемые"
fi

eq "список систем не пуст" "$(matrix_list | wc -l)" "5"

# ── Сравнение версий ───────────────────────────────────────────────────

head_ "Сравнение версий"

if version_ge 8.2 8.1; then ok "8.2 >= 8.1"; else bad "8.2 >= 8.1"; fi
if version_ge 8.4 8.2; then ok "8.4 >= 8.2"; else bad "8.4 >= 8.2"; fi
if version_ge 7.4 8.2; then bad "7.4 >= 8.2 должно быть ложью"; else ok "7.4 < 8.2"; fi
if version_ge 8.3 8.3; then ok "8.3 >= 8.3 (равно)"; else bad "8.3 >= 8.3 (равно)"; fi
if version_ge 8.1 8.2; then bad "8.1 >= 8.2 должно быть ложью"; else ok "8.1 < 8.2"; fi
if version_ge 10.0 9.4; then ok "10.0 >= 9.4 (двузначные)"; else bad "10.0 >= 9.4 (двузначные)"; fi

# ── detect_distro на настоящих /etc/os-release ─────────────────────────

head_ "detect_distro"

make_os_release() {
    local file="$1" id="$2" ver="$3" code="$4" pretty="$5"
    cat > "$file" <<EOF
ID=$id
VERSION_ID="$ver"
VERSION_CODENAME=$code
PRETTY_NAME="$pretty"
EOF
}

run_detect() {
    local file="$1"
    FAILED_MSG=""
    OS_SUPPORTED=0
    OS_NEEDS_SURY=0
    PHP_VERSION=""
    OS_RELEASE_FILE="$file" detect_distro >/dev/null
}

# ── Поддерживаемые системы ──
while IFS='|' read -r id ver code php; do
    f="$TMP/os-release-$id-$ver"
    make_os_release "$f" "$id" "$ver" "$code" "$id $ver"
    run_detect "$f"

    if [[ -n $FAILED_MSG ]]; then
        t_bad "$id $ver определена" "установщик отказал: $FAILED_MSG"
        continue
    fi

    eq "$id $ver поддержана"        "$OS_SUPPORTED" "1"
    eq "$id $ver кодовое имя"       "$OS_CODENAME" "$code"
    eq "$id $ver PHP из дистрибутива" "$OS_DISTRO_PHP" "$php"

    # Нужен ли sury: PHP из дистрибутива ниже 8.2
    if version_ge "$php" 8.2; then
        eq "$id $ver — свой PHP, sury не нужен" "$OS_NEEDS_SURY" "0"
    else
        eq "$id $ver — нужен sury"              "$OS_NEEDS_SURY" "1"
    fi

    # resolve_php_version
    resolve_php_version
    if version_ge "$PHP_VERSION" 8.2; then
        eq "$id $ver выбирает PHP >= 8.2" "yes" "yes"
    else
        t_bad "$id $ver выбирает PHP >= 8.2" "получено $PHP_VERSION"
    fi
done < <(printf '%s\n' "${SUPPORTED_SYSTEMS[@]}")

# ── Неподдерживаемые системы ──
for combo in "debian 10" "debian 14" "ubuntu 20.04" "ubuntu 22.10" "centos 7" "alpine 3.19" "raspbian 11"; do
    read -r id ver <<<"$combo"
    f="$TMP/os-release-bad-$id-$ver"
    make_os_release "$f" "$id" "$ver" "testcode" "$id $ver"
    run_detect "$f"

    if [[ -n ${FAILED_MSG:-} ]]; then
        t_ok "$id $ver отклонена"
    else
        t_bad "$id $ver отклонена" "OS_SUPPORTED=${OS_SUPPORTED:-?}, FAILED_MSG='${FAILED_MSG:-}' (ожидалась непустая)"
    fi
done

# ── Отсутствующий os-release ──
run_detect "$TMP/does-not-exist"
if [[ -n ${FAILED_MSG:-} ]]; then t_ok "нет os-release — понятная ошибка"; else t_bad "нет os-release — понятная ошибка" "OS_SUPPORTED=${OS_SUPPORTED:-?}"; fi

# ── Явно заданная версия PHP не переопределяется ───────────────────────

head_ "resolve_php_version"

PHP_VERSION="8.2"
OS_NEEDS_SURY=0
OS_DISTRO_PHP="8.4"
resolve_php_version
eq "явный --php уважается" "$PHP_VERSION" "8.2"

PHP_VERSION=""
OS_NEEDS_SURY=1
OS_DISTRO_PHP="7.4"
resolve_php_version
eq "при sury выбирается 8.3" "$PHP_VERSION" "8.3"

PHP_VERSION=""
OS_NEEDS_SURY=0
OS_DISTRO_PHP="8.4"
resolve_php_version
eq "без suru берётся PHP дистрибутива" "$PHP_VERSION" "8.4"

# ── Правка sources.list ────────────────────────────────────────────────
# Функция правит системные файлы apt, поэтому проверяем её на настоящих
# форматах: однострочный sources.list и deb822 (.sources).

head_ "apt_add_component"

# Формат 1: классический sources.list (Debian 12 / Ubuntu 22.04)
cat > "$TMP/sources.list" <<'EOF'
deb http://deb.debian.org/debian bookworm main
deb http://deb.debian.org/debian bookworm-updates main
deb http://security.debian.org/debian-security bookworm-security main
EOF

apt_add_component "$TMP/sources.list" contrib
if grep -q '^deb .* bookworm main contrib$' "$TMP/sources.list"; then
    t_ok "компонент дописан в конец строки"
else
    t_bad "компонент дописан в конец строки" "$(head -1 "$TMP/sources.list")"
fi
# Вторая строка не тронута
if grep -q '^deb .* bookworm-updates main$' "$TMP/sources.list"; then
    t_ok "остальные строки не изменены"
else
    t_bad "остальные строки не изменены" "$(sed -n 2p "$TMP/sources.list")"
fi
# Повторный вызов ничего не ломает
before="$(cat "$TMP/sources.list")"
apt_add_component "$TMP/sources.list" contrib || true
if [[ $before == "$(cat "$TMP/sources.list")" ]]; then
    t_ok "повторный вызов не дублирует компонент"
else
    t_bad "повторный выклад" "$(cat "$TMP/sources.list")"
fi

# Формат 2: закомментированные строки (Ubuntu иногда так поставляет)
# Компонент дописывается в активную строку; комментарий не трогаем.
cat > "$TMP/sources-commented.list" <<'EOF'
# deb http://archive.ubuntu.com/ubuntu jammy main restricted
deb http://archive.ubuntu.com/ubuntu jammy-updates main restricted
EOF

apt_add_component "$TMP/sources-commented.list" universe
if grep -q '^deb http://archive.ubuntu.com/ubuntu jammy-updates main restricted universe$' "$TMP/sources-commented.list"; then
    t_ok "компонент дописан в активную строку"
else
    t_bad "компонент дописан в активную строку" "$(sed -n 2p "$TMP/sources-commented.list")"
fi
if grep -q '^# deb http://archive.ubuntu.com/ubuntu jammy main restricted$' "$TMP/sources-commented.list"; then
    t_ok "закомментированная строка не тронута"
else
    t_bad "закомментированная строка не тронута" "$(sed -n 1p "$TMP/sources-commented.list")"
fi

# Формат 3: закомментированная строка УЖЕ содержит компонент — раскомментируем
cat > "$TMP/sources-uncomment.list" <<'EOF'
# deb http://deb.debian.org/debian bookworm contrib non-free
deb http://deb.debian.org/debian bookworm main
EOF

apt_add_component "$TMP/sources-uncomment.list" contrib
if grep -q '^deb http://deb.debian.org/debian bookworm contrib non-free$' "$TMP/sources-uncomment.list"; then
    t_ok "строка с компонентом раскомментирована"
else
    t_bad "строка с компонентом раскомментирована" "$(sed -n 1p "$TMP/sources-uncomment.list")"
fi

# Формат 4: нечего менять — в файле нет строк deb
cat > "$TMP/sources-empty.list" <<'EOF'
# только комментарии
EOF

before="$(cat "$TMP/sources-empty.list")"
if apt_add_component "$TMP/sources-empty.list" contrib; then
    t_bad "файл без строк deb не трогаем" "функция сообщила об изменении"
elif [[ $before == "$(cat "$TMP/sources-empty.list")" ]]; then
    t_ok "файл без строк deb не трогаем"
else
    t_bad "файл без строк deb не трогаем" "файл изменён"
fi

# ── Матрицы install.sh и agent.sh должны совпадать ────────────────────
# Оба установщика статят на ноды и панель. Если один расширит список ОС,
# а второй нет — на половине систем будет странная ошибка.

head_ "Матрицы install.sh и agent.sh совпадают"

extract_matrix() {
    sed -n '/^SUPPORTED_SYSTEMS=(/,/^)/p' "$1" | grep -o '"[^"]*"' | tr '\n' ' '
}

mat_panel="$(extract_matrix "$ROOT/deploy/install.sh")"
mat_agent="$(extract_matrix "$ROOT/deploy/agent.sh")"

if [[ -z $mat_panel ]]; then
    t_bad "матрица в install.sh найдена" "пусто"
elif [[ -z $mat_agent ]]; then
    t_bad "матрица в agent.sh найдена" "пусто"
elif [[ $mat_panel == "$mat_agent" ]]; then
    t_ok "матрицы идентичны"
else
    t_bad "матрицы идентичны" "install.sh и agent.sh расходятся"
    printf '       install.sh: %s\n       agent.sh:   %s\n' "$mat_panel" "$mat_agent"
fi

# Оба установщика обязаны иметь одну и ту же функцию определения ОС
for f in install.sh agent.sh; do
    if grep -q '^detect_distro()' "$ROOT/deploy/$f"; then
        t_ok "$f: detect_distro есть"
    else
        t_bad "$f: detect_distro есть"
    fi
    if grep -q '^pkg_available()' "$ROOT/deploy/$f"; then
        t_ok "$f: pkg_available есть"
    else
        t_bad "$f: pkg_available есть"
    fi
done

# ── Итог ───────────────────────────────────────────────────────────────

printf '\nПройдено: %d, провалено: %d\n' "$pass" "$fail"
[[ $fail -eq 0 ]] || exit 1
