#!/usr/bin/env bash
# The installer validates target settings and backs up an existing plugin.
set -euo pipefail
exec "$(dirname "$0")/install-local.sh" "${1:-kanboard-kanboard-1}"
