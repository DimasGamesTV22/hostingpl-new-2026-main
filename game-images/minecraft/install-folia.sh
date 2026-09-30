#!/usr/bin/env bash
#
# Установка Minecraft на Folia (обёртка)
#
set -euo pipefail

export GD_BUILD="folia"
exec "$(dirname "$0")/install.sh"
