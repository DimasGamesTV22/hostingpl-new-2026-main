#!/usr/bin/env bash
#
# Тесты меню, которое живёт внутри deploy/install.sh.
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
MENU="$ROOT/deploy/install.sh"
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

# Копия для подгрузки. Нижний код защищён проверкой BASH_SOURCE, поэтому
# `source` не запустит установку и вырезать `main "$@"` не нужно.
#
# LOG_FILE переносим в каталог теста: на машине без /var/log (и тем более
# без прав на него) каждый вызов _emit при source печатал бы «No such file or
# directory» поверх вывода теста. Журнал установки тут не проверяется, но
# лишний шум в stderr ломает сравнения строк ниже.
sed -e 's#^LOG_FILE=.*#LOG_FILE='"$TMP"'/install.log#' "$MENU" > "$TMP/menu.sh"

ANS="$TMP/answers"

# Подменяет интерактив и сетевые шаги, запускает переданный сценарий.
# $1 — очередь ответов (строки через \n), $2 — код на bash.
run_menu() {
    local answers="$1" code="$2"
    printf '%b' "$answers" > "$ANS"

    (
        # shellcheck disable=SC1090
        . "$TMP/menu.sh"
        set +eu   # установщик объявил set -euo pipefail при source

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

has   "$out" "установка игрового хостинга" "заголовок"
has   "$out" "Веб-сервер и база" "пункт 1 — веб-сервер и база"
has   "$out" "Панель GameDock" "пункт 2 — панель"
has   "$out" "Игровое окружение" "пункт 3 — игровое окружение"
has   "$out" "Агент ноды" "пункт 4 — агент"
has   "$out" "Telegram-бот" "пункт 5 — Telegram-бот"
has   "$out" "phpMyAdmin" "пункт 6 — phpMyAdmin"
has   "$out" "Службы и бэкапы" "пункт 7 — службы и бэкапы"
has   "$out" "Диагностика и статус" "пункт 8 — диагностика"
has   "$out" "Обновить панель" "пункт 9 — обновление панели"
has   "$out" "Установить ВСЁ в один клик" "пункт A — всё сразу"
has   "$out" "GAMEDOCK" "подпись в подвале"
has   "$out" "Пока!" "выход по 0"

# Все одиннадцать пунктов на месте: 1..9, A и 0
if [[ $(grep -cE '^ - [0-9A] - ' <<<"$out") -eq 11 ]]; then
    t_ok "в меню 11 пунктов (0–9 и A)"
else
    t_bad "в меню 11 пунктов (0–9 и A)" "найдено $(grep -cE '^ - [0-9A] - ' <<<"$out")"
fi

# Пункты, которые раньше были отдельными (CRON/BACKUP и СЛУЖБЫ), теперь
# объединены в пункт 7. Отдельных пунктов в главном меню больше нет.
hasnt "$out" "Открыть меню CRON/BACKUP" "старый пункт CRON/BACKUP убран из меню"
hasnt "$out" "Открыть меню СЛУЖБЫ" "старый пункт СЛУЖБЫ убран из меню"

# ── 2. Подменю открываются и возвращают по 0 ──────────────────────────
head_ "Подменю"

out="$(run_menu '7\n0\n0\n' 'main_menu')"
has   "$out" "СЛУЖБЫ и БЭКАПЫ" "пункт 7 открывается"
has   "$out" "Показать состояние служб" "в подменю есть состояние служб"
has   "$out" "Статус планировщика" "в подменю есть планировщик"
has   "$out" "Показать последние бэкапы" "в подменю есть бэкапы"
has   "$out" "Проверить ротацию логов" "в подменю есть ротация логов"
has   "$out" "Вернуться в меню" "в подменю есть выход"

out="$(run_menu '8\n0\n0\n' 'main_menu')"
has   "$out" "Меню СОСТОЯНИЕ" "пункт 8 открывается"
has   "$out" "Обзор системы и версии" "в СОСТОЯНИЕ есть пункты"

# ── 3. Пункт «что установлено» ───────────────────────────────────────
head_ "Состояние установки"

out="$(run_menu '8\n3\n0\n0\n' 'main_menu')"
has   "$out" "Что установлено" "открылась проверка установки"
has   "$out" "Веб-сервер" "проверяется веб-сервер"
has   "$out" "Панель" "проверяется панель"
has   "$out" "Агент" "проверяется агент"

# ── 3a. Новые пункты: куда ведёт каждый номер ──────────────────────
#
# Проверяем ровно то, ради чего пункт добавлен: номер вызывает нужную
# функцию, а не соседнюю. Ничего не устанавливается.
#
# Внимание на eval: после «}» обязателен разделитель, поэтому тело
# передаётся отдельным аргументом с переводом строки. «eval "$STUBS
# main_menu» падает с синтаксической ошибкой.

head_ "Диспетчеризация пунктов"

STUBS='install_bot() { echo ВЫЗОВ install_bot; }
install_phpmyadmin_menu() { echo ВЫЗОВ install_phpmyadmin_menu; }
menu_ops() { echo ВЫЗОВ menu_ops; }
menu_status() { echo ВЫЗОВ menu_status; }
update_panel() { echo ВЫЗОВ update_panel; }
install_game_stack() { echo ВЫЗОВ install_game_stack; }'

check_target() {
    local n="$1" fn="$2"
    local out
    out="$(run_menu "$n\n" "$STUBS"$'\n'"main_menu")"
    has "$out" "ВЫЗОВ $fn" "пункт $n вызывает $fn"
}

check_target 5 install_bot
check_target 6 install_phpmyadmin_menu
check_target 7 menu_ops
check_target 8 menu_status
check_target 9 update_panel
check_target 3 install_game_stack

# Пункт 7 открывает объединённое подменю, а не два старых отдельных.
# Пункты 6 и 8 подменяем: реальные функции смотрят на окружение.
out="$(run_menu '7\n0\n0\n' "$STUBS"$'\n'"main_menu")"
has "$out" "ВЫЗОВ menu_ops" "пункт 7 зовёт menu_ops"

# ── Игровое окружение: отдельная линейная установка ─────────────────
#
# Саму install_game_stack подменять нельзя — внутри неё она вызвала бы
# сама себя. Подменяем только зависимости.
head_ "Игровое окружение: отдельная линейная установка"

out="$(run_menu '3\n' '
    confirm()     { return 0; }
    run_install() { echo "ВЫЗОВ run_install $*"; }
    main_menu
')"

has   "$out" "ВЫЗОВ run_install --only game-stack" "пункт 3 зовёт установку с областью game-stack"
hasnt "$out" "пока не поддерживается" "старое предупреждение о неподдержке убрано"
has   "$out" "только игровую часть" "сказано, что панель не трогается"

# Меню после вызова пункта 3 продолжает работать: run_install не уводит
# нас в линейный сценарий, который иначе открыл бы меню заново.
out="$(run_menu '3\n0\n' '
    confirm()     { return 0; }
    run_install() { echo "ВЫЗОВ run_install $*"; }
    main_menu
')"
has "$out" "Пока!" "после игрового окружения меню продолжает работать"

# ── phpMyAdmin отдельным пунктом ─────────────────────────────────────
head_ "phpMyAdmin отдельным пунктом"

# Веб-сервер помечен установленным, иначе функция уйдёт на гейт.
out="$(run_menu '6\n0\n0\n' '
    webserver_installed() { return 0; }
    main_menu
')"
has   "$out" "Что сделать" "пункт 6 предлагает варианты"
has   "$out" "перевыпустить пароль" "есть вариант перевыпустить пароль"
has   "$out" "выключить" "есть вариант выключить"

# Без веб-сервера пункт 6 обязан отказать, а не лезть в apt
out="$(run_menu '6\n' '
    webserver_installed() { return 1; }
    main_menu
')"
has   "$out" "phpMyAdmin публикуется через nginx" "без веб-сервера отказ"
hasnt "$out" "apt-get install" "ничего не ставилось"

# ── Обновление панели: гейт на установленную панель ─────────────────
head_ "Обновление панели: гейт на установленную панель"

out="$(run_menu '9\n' '
    panel_installed() { return 1; }
    pause() { :; }
    update_panel
')"
has   "$out" "Панель не найна" "обновление без панели отказано"
hasnt "$out" "composer install" "composer не запускался"

# ── 4. Панель без веб-сервера ─────────────────────────────────────────
#
# Правило как в оригинале: без веб-сервера веб-часть панели не ставится.
head_ "Гейт на веб-сервер"

# Подменяем проверки так, чтобы веб-сервера точно не было
out="$(
    printf '2\nn\n' > "$ANS"
    (
        . "$TMP/menu.sh"
        set +eu   # установщик объявил set -euo pipefail при source
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
        set +eu   # установщик объявил set -euo pipefail при source
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

# ── 7. Падение дочернего установщика ──────────────────────────────────
#
# Пункт меню запускает install.sh отдельным процессом. Раньше его код
# возврата уходил прямо в `set -e` родителя, и наблюдатель видел одно и
# то же: листающийся текст, возврат в меню, попытка снова. Похоже на «не
# понравилось и перезапустил», хотя на самом деле установка упала на
# конкретной строке. Здесь проверяем правильное поведение: падение
# подтверждено кодом, причина показана, и только потом — возврат в меню.
head_ "Падение дочернего установщика"

# Заготовка вместо настоящего install.sh: она ничего не ставит, а только
# печатает первую строку и возвращает заданный код.
STUB="$TMP/stub-child.sh"
stub_child() {
    printf '%s\n' '#!/usr/bin/env bash' "echo \"$1\"" "exit $2" > "$STUB"
    chmod +x "$STUB"
}

# Готовит окружение для одного вызова run_install/run_agent и выполняет
# переданный код. Показывает в stdout вывод, в stderr — признак падения.
# $1 — что зовём (run_install или run_agent), $2 — аргументы для него.
run_child() {
    local fn="$1"
    shift
    (
        . "$TMP/menu.sh" 2>/dev/null
        set +eu   # установщик объявил set -euo pipefail при source

        # LOG_FILE переопределяем ПОСЛЕ source: сам install.sh задаёт
        # /var/log/gamedock-install.log, и на машине без такого каталога
        # запись в журнал сыпала бы ошибками поверх вывода.
        LOG_FILE="$TMP/child.log"
        script_source() { printf '%s' "$STUB"; }
        pause() { echo "ПАУЗА"; }
        err()   { echo "ОШИБКА $*" >&2; }

        "$fn" "$@"
        echo "КОД_ВЫХОДА=$?"
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
}

stub_child "дочерний процесс: начал ставить" 100
out="$(run_child run_install --yes)"

has   "$out" "дочерний процесс: начал ставить" "вывод дочернего процесса виден"
has   "$out" "ОШИБКА Установка прервалась (код выхода 100)" "падение подтверждено кодом выхода"
has   "$out" "КОД_ВЫХОДА=0" "меню не падает вместе с дочерним процессом"
has   "$out" "ПАУЗА" "перед возвратом в меню ждём Enter"

# Провал не должен выглядеть как успех: слова «прервалась» нет при коде 0.
stub_child "всё поставилось" 0
out="$(run_child run_install --yes)"

hasnt "$out" "прервалась" "при успехе нет сообщения об ошибке"
has   "$out" "КОД_ВЫХОДА=0" "при успехе код выхода нулевой"

# Агент ставится тем же способом и должен вести себя так же.
stub_child "агент: начал ставить" 100
out="$(run_child run_agent)"

has "$out" "ОШИБКА Установка агента прервалась (код выхода 100)" "падение агента подтверждено кодом"
has "$out" "ПАУЗА" "перед возвратом в меню ждём Enter"

# Отсутствующий install.sh — понятная ошибка, а не молчаливое падение
out="$(
    (
        . "$TMP/menu.sh"
        set +eu
        LOG_FILE="$TMP/child.log"
        script_source() { return 1; }
        pause() { :; }
        err()   { echo "ОШИБКА $*"; }
        run_install --yes
        echo "КОД_ВЫХОДА=$?"
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -v 'Нет TTY'
)"

has "$out" "ОШИБКА Не найден install.sh" "нет install.sh — объяснено словами"
has "$out" "КОД_ВЫХОДА=0" "нет install.sh — меню продолжает работать"

# set -e у вызывающего кода: main_menu работает под `set -euo pipefail`.
# Если бы run_install отдавал ненулевой код, оболочка меню завершилась бы
# вместе с установкой — и пользователь увидел бы пустой терминал.
stub_child "падает" 1
out="$(
    (
        set -e
        . "$TMP/menu.sh"
        set -e
        LOG_FILE="$TMP/child.log"
        script_source() { printf '%s' "$STUB"; }
        pause() { :; }
        err()   { :; }
        run_install --yes
        echo "МЕНЮ_ЖИВО"
    ) 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -v 'Нет TTY'
)"

has "$out" "МЕНЮ_ЖИВО" "вызов из main_menu не убивает set -e"

# Хвост журнала: после падения на экране остаётся только «✗», а причину
# надо где-то увидеть.
CHILD_LOG="$TMP/child.log"
printf 'строка 1\nстрока 2\nстрока 3\nстрока 4\nстрока 5\n' > "$CHILD_LOG"

out="$(run_menu '' "LOG_FILE='$CHILD_LOG'; show_install_log_tail 3" | grep -v 'Нет TTY')"
has   "$out" "строка 5" "хвост журнала показывает последние строки"
hasnt "$out" "строка 2" "хвост журнала не показывает лишнего"

EMPTY_LOG="$TMP/empty.log"
: > "$EMPTY_LOG"

out="$(run_menu '' "LOG_FILE='$EMPTY_LOG'; show_install_log_tail 5" | grep -v 'Нет TTY')"
if [[ -z ${out//[[:space:]]/} ]]; then
    t_ok "пустой журнал не роняет вывод"
else
    t_bad "пустой журнал не роняет вывод" "$out"
fi

# ── Итог ───────────────────────────────────────────────────────────────
printf '\nПройдено: %d, провалено: %d\n' "$pass" "$fail"
[[ $fail -eq 0 ]] || exit 1

echo 'OK   меню отрисовывается, подменю работают, гейт на веб-сервер соблюдён'
