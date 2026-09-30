#!/usr/bin/env bash
# Cowa v1 (обёртка)
set -euo pipefail
export GD_BUILD="cowa"
exec "$(dirname "$0")/install.sh"
