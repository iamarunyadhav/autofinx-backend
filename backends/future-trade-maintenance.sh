#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"

cd "$APP_DIR"
"$PHP" artisan future-trade:diagnostics --persist || true
"$PHP" artisan future-trade:audit-missed-signals --limit=100 || true
"$PHP" artisan future-trade:queue-cleanup --safe || true
