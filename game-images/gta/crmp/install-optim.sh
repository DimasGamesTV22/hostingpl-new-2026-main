#!/usr/bin/env bash
# Optim (обёртка)
set -euo pipefail
export GD_BUILD="optim"
exec "$(dirname "$0")/install.sh"
