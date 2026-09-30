#!/usr/bin/env bash
#
# Установка Minecraft на Spigot (обёртка)
#
set -euo pipefail

export GD_BUILD="spigot"
exec "$(dirname "$0")/install.sh"
