#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"

cd "$APP_DIR"

queue_count="$(pgrep -fc "artisan queue:work" || true)"
schedule_count="$(pgrep -fc "artisan schedule:work" || true)"
feed_count="$(pgrep -fc "future-trade:live-feed" || true)"

echo "[HEARTBEAT] queue=$queue_count schedule=$schedule_count feed=$feed_count at $(date -Is)"

if [ "$queue_count" -lt 3 ] || [ "$schedule_count" -lt 1 ] || [ "$feed_count" -lt 1 ]; then
  echo "[HEARTBEAT] missing worker; restarting"
  "$APP_DIR/restart-workers.sh"
  exit 0
fi

"$PHP" artisan future-trade:queue-health >/dev/null || true
