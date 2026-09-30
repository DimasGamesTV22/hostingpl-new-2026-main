#!/usr/bin/env bash
#
# Установка Minecraft Vanilla (обёртка)
#
set -euo pipefail

export GD_BUILD="vanilla"
exec "$(dirname "$0")/install.sh"
