#!/usr/bin/env bash
#
# Тесты меню-установщика deploy/menu.sh.
#
# Меню читает выбор как choice="$(read_choice)", то есть в под-шелле. Поэтому
# очередь ответов держим В ФАЙЛЕ: правка массива наружу не уходит, а счётчик
# в файле переживает под-шелл. Так тест проверяет настоящую диспетчеризацию,
# включая переходы в подменю и возврат по 0.
#
# Ничего не устанавливается: ensure_repo подменён, confirm всегда «нет».
#
# Запуск: bash panel/tools/test-menu.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MENU="$ROOT/deploy/menu.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [[ ! -f $MENU ]]; then
    echo "FAIL  нет файла $MENU"
    exit 1
fi

pass=0
fail=0

t_ok()  { pass=$((pass + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
t_bad() { fail=$((fail + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [[ $# -gt 1 ]] && printf '       %s\n' "$2"; return 0; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# Копия меню без главного вызова
sed 's|^main "\$@"$|true  # main отключён в тесте|' "$MENU" > "$TMP/menu.sh"

ANS="$TMP/answers"

# Подменяет интерактив и сетевые шаги, запускает переданный сценарий.
# $1 — очередь ответов (строки через \n), $2 — код на bash.
run_menu() {
    local answers="$1" code="$2"
    printf '%b' "$answers" > "$ANS"

    (
        # shellcheck disable=SC1090
        . "$TMP/menu.sh"

        read_choice() {
            [[ -s $ANS ]] || return 1        # пусто → «нет TTY» → выход
            local line
            line="$(head -1 "$ANS")"
            tail -n +2 "$ANS" > "$ANS.next" 2>/dev/null || : > "$ANS.next"
            mv -f "$ANS.next" "$ANS"
            printf '%s' "$line"
        }

        pause()     { :; }
        ask()       { printf '%s' "${2:-}"; }
        confirm()   { return 1; }             # на всё отвечаем «нет»
        ensure_repo() { REPO_DIR="$ROOT"; return 0; }

        eval "$code"
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
}

# Проверка «фрагмент есть в выводе»
has() {
    local out="$1" needle="$2" label="$3"
    if grep -qF -- "$needle" <<<"$out"; then
        t_ok "$label"
    else
        t_bad "$label" "не найдено: $needle"
    fi
}

hasnt() {
    local out="$1" needle="$2" label="$3"
    if grep -qF -- "$needle" <<<"$out"; then
        t_bad "$label" "лишний текст: $needle"
    else
        t_ok "$label"
    fi
}

# ── 1. Главный экран ──────────────────────────────────────────────────
head_ "Главный экран"

out="$(run_menu '0\n' 'main_menu')"

has   "$out" "Добро пожаловать в меню автоустановщика GameDock" "заголовок"
has   "$out" "Настроить VDS/VPS под WEB Server (LAMP)" "пункт 1 — веб-сервер"
has   "$out" "Установка панели хостинга GameDock" "пункт 2 — панель"
has   "$out" "Установить/обновить агента ноды" "пункт 4 — агент"
has   "$out" "Открыть меню CRON/BACKUP" "пункт 5 — CRON/BACKUP"
has   "$out" "Открыть меню СЛУЖБЫ" "пункт 6 — службы"
has   "$out" "Открыть меню СОСТОЯНИЕ" "пункт 7 — состояние"
has   "$out" "Установить ВСЁ в один клик" "пункт 8 — всё сразу"
has   "$out" "GAMEDOCK" "подпись в подвале"
has   "$out" "Пока!" "выход по 0"

# Все девять пунктов на месте (0..8)
if [[ $(grep -cE '^ - [0-8] - ' <<<"$out") -eq 9 ]]; then
    t_ok "в меню 9 пунктов (0–8)"
else
    t_bad "в меню 9 пунктов (0–8)" "найдено $(grep -cE '^ - [0-8] - ' <<<"$out")"
fi

# ── 2. Подменю открываются и возвращают по 0 ──────────────────────────
head_ "Подменю"

out="$(run_menu '5\n0\n0\n' 'main_menu')"
has   "$out" "Меню CRON / BACKUP" "CRON/BACKUP открывается"
has   "$out" "Статус планировщика" "в CRON/BACKUP есть пункты"
has   "$out" "Вернуться в меню" "в подменю есть выход"

out="$(run_menu '6\n0\n0\n' 'main_menu')"
has   "$out" "Меню СЛУЖБЫ" "СЛУЖБЫ открывается"
has   "$out" "gamedock-wss" "СЛУЖБЫ показывает состав"
has   "$out" "Запустить все службы" "в СЛУЖБЫ есть действия"

out="$(run_menu '7\n0\n0\n' 'main_menu')"
has   "$out" "Меню СОСТОЯНИЕ" "СОСТОЯНИЕ открывается"
has   "$out" "Обзор системы и версии" "в СОСТОЯНИЕ есть пункты"

# ── 3. Пункт «что установлено» ───────────────────────────────────────
head_ "Состояние установки"

out="$(run_menu '7\n3\n0\n0\n' 'main_menu')"
has   "$out" "Что установлено" "открылась проверка установки"
has   "$out" "Веб-сервер" "проверяется веб-сервер"
has   "$out" "Панель" "проверяется панель"
has   "$out" "Агент" "проверяется агент"

# ── 4. Панель без веб-сервера ─────────────────────────────────────────
#
# Правило как в оригинале: без веб-сервера веб-часть панели не ставится.
head_ "Гейт на веб-сервер"

# Подменяем проверки так, чтобы веб-сервера точно не было
out="$(
    printf '2\nn\n' > "$ANS"
    (
        . "$TMP/menu.sh"
        read_choice() {
            [[ -s $ANS ]] || return 1
            local line; line="$(head -1 "$ANS")"
            tail -n +2 "$ANS" > "$ANS.next" 2>/dev/null || : > "$ANS.next"
            mv -f "$ANS.next" "$ANS"
            printf '%s' "$line"
        }
        pause()      { :; }
        ask()        { printf '%s' "${2:-}"; }
        confirm()    { return 1; }   # «нет» — не ставить веб-сервер
        ensure_repo() { REPO_DIR="$ROOT"; return 0; }
        webserver_installed() { return 1; }   # веб-сервера нет
        run_install()  { echo "ВЫЗОВ run_install"; }
        install_panel
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
)"

has   "$out" "Без веб-сервера панель не встанет" "объяснено, почему нельзя"
has   "$out" "пункт 1" "дана ссылка на пункт 1"
hasnt "$out" "ВЫЗОВ run_install" "install.sh не запускался"

# С веб-сервером установка идёт дальше
out="$(
    printf '2\n' > "$ANS"
    (
        . "$TMP/menu.sh"
        read_choice() { return 1; }
        pause()      { :; }
        ask()        { printf '%s' "${2:-}"; }
        confirm()    { return 0; }
        ensure_repo() { REPO_DIR="$ROOT"; return 0; }
        webserver_installed() { return 0; }   # веб-сервер есть
        panel_installed()     { return 1; }
        run_install()  { echo "ВЫЗОВ run_install"; }
        install_panel
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
)"

has "$out" "ВЫЗОВ run_install" "с веб-сервером установщик запускается"

# ── 5. Обработка неверного ввода ──────────────────────────────────────
head_ "Неверный ввод"

out="$(run_menu '42\n0\n' 'main_menu')"
has "$out" "Нет пункта «42»" "неизвестный пункт сообщает об ошибке"

out="$(run_menu '0\n' 'main_menu')"
hasnt "$out" "Нет пункта" "корректный выход не даёт ошибки"

# ── 6. Вспомогательные функции отрисовки ──────────────────────────────
head_ "Отрисовка"

out="$(run_menu '' 'repeat_char "=" 10; echo; repeat_char "=" 4')"
if grep -qx '==========' <<<"$out" && grep -qx '====' <<<"$out"; then
    t_ok "repeat_char строит рамку нужной длины"
else
    t_bad "repeat_char строит рамку нужной длины" "$(head -3 <<<"$out")"
fi

out="$(run_menu '' 'banner "Тест"; menu_footer "МЕТКА"')"
if grep -q 'Тест' <<<"$out" && grep -q 'МЕТКА' <<<"$out"; then
    t_ok "banner и menu_footer печатают свои аргументы"
else
    t_bad "banner и menu_footer печатают свои аргументы"
fi

# Подвал центрирует подпись и не выходит за общую ширину
if grep -qE '^=+ МЕТКА =+$' <<<"$out"; then
    t_ok "подвал центрирует подпись знаками равенства"
else
    t_bad "подвал центрирует подпись знаками равенства" "$(grep 'МЕТКА' <<<"$out")"
fi

# Меню обязано упоминать webserver_installed: без неё теряется гейт
if grep -q 'webserver_installed' "$MENU"; then
    t_ok "меню проверяет наличие веб-сервера"
else
    t_bad "меню проверяет наличие веб-сервера"
fi

# ── Итог ───────────────────────────────────────────────────────────────
printf '\nПройдено: %d, провалено: %d\n' "$pass" "$fail"
[[ $fail -eq 0 ]] || exit 1

echo 'OK   меню отрисовывается, подменю работают, гейт на веб-сервер соблюдён'
