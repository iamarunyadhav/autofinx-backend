#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
LOCK_DIR="$APP_DIR/storage/framework/future-trade-heartbeat.lock"

cd "$APP_DIR"

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  echo "[HEARTBEAT] skipped; another heartbeat is active"
  exit 0
fi

trap 'rmdir "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT

count_processes() {
  local pattern="$1"
  pgrep -af "$pattern" 2>/dev/null | wc -l | tr -d ' '
}

queue_count="$(count_processes "artisan queue:work")"
feed_count="$(count_processes "future-trade:live-feed")"

echo "[HEARTBEAT] queue=$queue_count feed=$feed_count at $(date -Is)"

if [ "$queue_count" -lt 3 ] || [ "$feed_count" -lt 1 ]; then
  echo "[HEARTBEAT] missing worker; starting missing processes only"
  "$APP_DIR/start-workers.sh" || true
fi

"$PHP" artisan future-trade:queue-health >/dev/null 2>&1 || true
