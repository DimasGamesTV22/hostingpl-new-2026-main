#!/usr/bin/env bash
# Проверка сборки дистрибутива: deploy/build-dist.sh
#
# Смысл набора — не «скрипт запустился», а «собранные файлы пригодны к
# раздаче». Собранный, но нерабочий файл хуже отсутствующего: человек
# скачает его, запустит на сервере и получит ошибку вместо установки.
#
# Что проверяется:
#   • открытый файл совпадает с install.sh и проходит bash -n;
#   • запасной (openssl) вариант расшифровывается и отвечает на --help;
#   • в упакованном файле не читается исходный код;
#   • ключ лежит рядом с телом — то есть защита честно ограничена.
#
# Тест требует openssl. shc-сборка не проверяется: компилятора C на
# тестовой машине может не быть, и это не повод падать. Отсутствие shc
# проверяется отдельно — что скрипт об этом честно сообщает.

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
ROOT="$PWD"
TMP="$ROOT/deploy/.dist-test.$$"
OUT="$TMP"

PASS=0
FAIL=0

t_ok()  { printf '  \033[32mok\033[0m   %s\n' "$1"; PASS=$((PASS+1)); }
t_bad() { printf '  \033[31mFAIL\033[0m %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }
has()   { grep -qF -- "$2" <<<"$3" && t_ok "$1" || t_bad "$1" "нет строки «$2»"; }
hasnt() { grep -qF -- "$2" <<<"$3" && t_bad "$1" "найдено «$2»" || t_ok "$1"; }

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

INSTALL_SH="$ROOT/deploy/install.sh"
BUILD_SH="$ROOT/deploy/build-dist.sh"

head_ "Исходники сборки"

if [[ -f $BUILD_SH ]]; then
    t_ok "build-dist.sh на месте"
else
    t_bad "build-dist.sh на месте" "нет файла $BUILD_SH"
    printf '\nПровалено: %d, пройдено: %d\n' "$FAIL" "$PASS"
    exit 1
fi

if bash -n "$BUILD_SH" 2>/dev/null; then
    t_ok "build-dist.sh проходит bash -n"
else
    t_bad "build-dist.sh проходит bash -n" "синтаксическая ошибка"
fi

if bash -n "$INSTALL_SH" 2>/dev/null; then
    t_ok "install.sh проходит bash -n"
else
    t_bad "install.sh проходит bash -n" "синтаксическая ошибка"
fi

# ── Сборка ───────────────────────────────────────────────────────────
head_ "Сборка в отдельном каталоге"

# Без флагов: скрипт сам попробует собрать бинарник shc, а если
# компилятора нет — перейдёт к запасному варианту. Именно этот порядок и
# проверяется ниже, поэтому --openssl здесь ставить нельзя: при нём шаг
# shc не выполняется и его совет про build-essential не печатается.
BUILD_OUT="$(bash "$BUILD_SH" --out "$OUT" 2>&1)"
build_code=$?

# Скрипт завершается с ошибкой только если не собрал вообще ничего.
# В нашем случае собирается openssl-вариант, поэтому код должен быть 0.
if [[ $build_code -eq 0 ]]; then
    t_ok "сборкаopenssl-варианта завершилась успешно"
else
    t_bad "сборка openssl-варианта завершилась успешно" "код $build_code"
    printf '%s\n' "$BUILD_OUT" | tail -5 | sed 's/^/     /'
fi

for f in gamedock-install.sh gamedock-install.enc.sh; do
    if [[ -f "$OUT/$f" ]]; then
        t_ok "собран $f"
    else
        t_bad "собран $f" "файла нет"
    fi
done

# ── Открытый файл ────────────────────────────────────────────────────
head_ "Открытый файл"

if [[ -f "$OUT/gamedock-install.sh" ]]; then
    if cmp -s "$OUT/gamedock-install.sh" "$INSTALL_SH"; then
        t_ok "открытый файл побайтно совпадает с install.sh"
    else
        t_bad "открытый файл совпадает с install.sh" "файлы различаются"
    fi

    if bash -n "$OUT/gamedock-install.sh" 2>/dev/null; then
        t_ok "открытый файл проходит bash -n"
    else
        t_bad "открытый файл проходит bash -n" "синтаксическая ошибка"
    fi

    if grep -q "INSTALL_SCOPE" "$OUT/gamedock-install.sh"; then
        t_ok "в открытом файле есть флаг --only"
    else
        t_bad "в открытом файле есть флаг --only" "флага нет"
    fi
fi

# ── Запасной вариант ─────────────────────────────────────────────────
head_ "Упакованный вариант: должен работать"

ENC="$OUT/gamedock-install.enc.sh"

if [[ -f $ENC ]]; then
    # Главная проверка: файл не просто существует, а запускается.
    # --help выходит сразу и ничего в системе не меняет.
    ENC_HELP="$(bash "$ENC" --help 2>/dev/null)"
    enc_code=$?

    if [[ $enc_code -eq 0 ]]; then
        t_ok "упакованный файл отвечает на --help (код 0)"
    else
        t_bad "упакованный файл отвечает на --help" "код $enc_code"
    fi

    has "упакованный файл показывает название" "GameDock" "$ENC_HELP"
    has "упакованный файл знает про --only" "--only" "$ENC_HELP"
    has "упакованный файл знает про меню" "Telegram-бот" "$ENC_HELP"

    # ── Исходник не должен читаться ───────────────────────────────
    head_ "Упакованный вариант: исходник не читается"

    # Узнаваемые куски install.sh. Если хоть один найдётся в упакованном
    # файле — обещание «не видно что в коде» не выполнено.
    for marker in "setup_sury_php" "phpmyadmin/reconfigure-webserver" "gamedock-queue@.service"; do
        if grep -qF -- "$marker" "$INSTALL_SH" 2>/dev/null; then
            hasnt "исходник скрыт: $marker" "$marker" "$(cat "$ENC")"
        fi
    done

    # Длинные русские слова из исходника — в base64 их быть не может.
    for word in "Поддерживаемые" "автоустановщик"; do
        if grep -qF -- "$word" "$INSTALL_SH" 2>/dev/null; then
            hasnt "исходник скрыт: «$word»" "$word" "$(cat "$ENC")"
        fi
    done

    # ── Честность ограничения ─────────────────────────────────────
    head_ "Упакованный вариант: ограничение защиты"

    if grep -qE "^GD_KEY='[0-9a-f]{64}'$" "$ENC"; then
        t_ok "ключ лежит рядом с телом (так и задумано)"
    else
        t_bad "ключ лежит рядом с телом" "ключ не найден в открытом виде"
    fi

    if grep -q '^__GD_BODY__$' "$ENC"; then
        t_ok "маркер начала тела на месте"
    else
        t_bad "маркер начала тела на месте" "маркера нет"
    fi

    # Подменённый файл должен вежливо отказать, а не упасть с молотком.
    BROKEN="$TMP/broken.sh"
    head -n 40 "$ENC" >"$BROKEN"          # ключ есть, тела нет
    BROKEN_OUT="$(bash "$BROKEN" --help 2>&1)"
    broken_code=$?

    if [[ $broken_code -ne 0 ]]; then
        t_ok "обрезанный файл не запускается (код $broken_code)"
    else
        t_bad "обрезанный файл не запускается" "вышел с кодом 0"
    fi

    has "обрезанный файл объясняет причину" "повреждён" "$BROKEN_OUT"
fi

# ── Честность насчёт shc ─────────────────────────────────────────────
head_ "shc: честное поведение при отсутствии компилятора"

have_cc=0
command -v gcc >/dev/null 2>&1 && have_cc=1
command -v cc  >/dev/null 2>&1 && have_cc=1

if [[ $have_cc -eq 0 ]]; then
    # Компилятора нет — скрипт обязан об этом сказать, а не упасть молча
    # и не выдать «бинарник», которого не существует.
    has "без компилятора сказано, что делать" "build-essential" "$BUILD_OUT"

    if [[ -f "$OUT/gamedock-install" ]]; then
        t_bad "бинарник не создан вслепую" "файл есть, а компилятора не было"
    else
        t_ok "бинарник не создан вслепую"
    fi

    if [[ -f "$OUT/gamedock-install.enc.sh" ]]; then
        t_ok "запасной вариант собран вместо бинарника"
    else
        t_bad "запасной вариант собран вместо бинарника" "файла нет"
    fi
else
    t_ok "компилятор есть — полная сборка проверяется отдельно на целевой машине"
fi

# ── Итог ─────────────────────────────────────────────────────────────
printf '\nПройдено: %d, провалено: %d\n' "$PASS" "$FAIL"

if [[ $FAIL -gt 0 ]]; then
    printf 'FAIL   сборка дистрибутива не проходит проверки\n'
    exit 1
fi

printf 'OK   собранные файлы пригодны к раздаче: открытый и упакованный запускаются\n'