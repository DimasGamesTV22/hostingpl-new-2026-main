#!/usr/bin/env bash
#
# Установка Minecraft на Paper (обёртка для конкретной сборки)
# Используется игрой minecraft-java со сборкой build=paper
#
set -euo pipefail
exec "$(dirname "$0")/install.sh"
