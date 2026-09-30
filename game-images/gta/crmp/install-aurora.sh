#!/usr/bin/env bash
# Aurora (обёртка)
set -euo pipefail
export GD_BUILD="aurora"
exec "$(dirname "$0")/install.sh"
