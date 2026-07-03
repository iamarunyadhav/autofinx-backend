#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP82="${PHP82:-/usr/local/bin/ea-php82}"

cd "$APP_ROOT"

if [[ ! -x "$PHP82" ]]; then
  if command -v ea-php82 >/dev/null 2>&1; then
    PHP82="$(command -v ea-php82)"
  elif command -v php >/dev/null 2>&1; then
    PHP82="$(command -v php)"
  fi
fi

"$PHP82" artisan doctor_cleanup --json "$@"
