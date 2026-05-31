#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi

cd "$APP_DIR"

# Trading diagnostics + audit
timeout 60 "$PHP" artisan future-trade:diagnostics --persist   2>/dev/null || true
timeout 60 "$PHP" artisan future-trade:audit-missed-signals --limit=100 2>/dev/null || true
timeout 60 "$PHP" artisan future-trade:queue-cleanup --safe    2>/dev/null || true

# Light storage housekeeping every 5 min: trim logs > 50 MB → 10 MB
# and delete stale session files > 2 days. Keeps disk from filling silently.
find "$APP_DIR/storage/logs" -name "*.log" -size +50M \
  -exec sh -c 'tail -c 10485760 "$1" > /tmp/_afx_trim.tmp && mv /tmp/_afx_trim.tmp "$1"' _ {} \; 2>/dev/null || true
find "$APP_DIR/storage/framework/sessions" -type f -mtime +2 -delete 2>/dev/null || true
find "$APP_DIR/storage/framework/cache/data" -type f -mtime +1 -delete 2>/dev/null || true
