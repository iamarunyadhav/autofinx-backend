#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi

"$APP_DIR/stop-workers.sh"
sleep 2
APP_DIR="$APP_DIR" PHP="$PHP" "$APP_DIR/start-workers.sh"

echo "AutoFinX workers restarted."
