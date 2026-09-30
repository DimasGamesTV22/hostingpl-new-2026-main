#!/usr/bin/env bash
#
# Установка Minecraft на Purpur (обёртка)
#
set -euo pipefail

export GD_BUILD="purpur"
exec "$(dirname "$0")/install.sh"
