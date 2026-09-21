#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"
RUN_USER="${RUN_USER:-zcadmin}"
WORKSPACE_ID="${WORKSPACE_ID:-26a36b56-4383-4edd-99d1-7622e7c191ef}"
USER_STREAM_DURATION="${USER_STREAM_DURATION:-55}"
USER_STREAM_RECONNECT_SLEEP="${USER_STREAM_RECONNECT_SLEEP:-3}"
USER_STREAM_KEEPALIVE="${USER_STREAM_KEEPALIVE:-45}"
VERIFY_WAIT_SECONDS="${VERIFY_WAIT_SECONDS:-70}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run this script as root."
  exit 1
fi

if [ ! -d "$APP_DIR" ]; then
  echo "APP_DIR not found: $APP_DIR"
  exit 1
fi

if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found. Set PHP_BIN=/usr/local/bin/ea-php82 and retry."
  exit 1
fi

cd "$APP_DIR"
mkdir -p storage/framework storage/logs

log() {
  echo "[FT-RUNTIME-FIX] $(date -Is) $*"
}

active_trading_conf() {
  for file in \
    /etc/supervisord.d/autofinx-trading.ini \
    /etc/supervisor/conf.d/autofinx-trading.conf; do
    if [ -f "$file" ]; then
      echo "$file"
      return 0
    fi
  done
  return 1
}

age_from_json_epoch() {
  local path="$1"
  local key="$2"
  if [ ! -s "$path" ]; then
    echo 999999
    return 0
  fi

  local epoch
  epoch="$("$PHP_BIN" -r '$p=$argv[1]; $k=$argv[2]; $j=json_decode((string) @file_get_contents($p), true); echo is_array($j) ? (int) ($j[$k] ?? 0) : 0;' "$path" "$key" 2>/dev/null || echo 0)"
  if [ "$epoch" -le 0 ]; then
    echo 999999
    return 0
  fi

  echo "$(($(date +%s) - epoch))"
}

patch_trading_supervisor() {
  local conf
  conf="$(active_trading_conf)" || {
    echo "Active autofinx trading supervisor config not found."
    echo "Expected /etc/supervisord.d/autofinx-trading.ini or /etc/supervisor/conf.d/autofinx-trading.conf"
    exit 1
  }

  log "active trading supervisor config=$conf"
  cp -a "$conf" "$conf.bak.runtime-fix.$(date +%Y%m%d%H%M%S)"

  python3 - "$conf" "$APP_DIR" "$PHP_BIN" "$WORKSPACE_ID" "$USER_STREAM_DURATION" "$USER_STREAM_RECONNECT_SLEEP" "$USER_STREAM_KEEPALIVE" <<'PY'
from pathlib import Path
import sys

conf = Path(sys.argv[1])
app_dir = sys.argv[2]
php = sys.argv[3]
workspace = sys.argv[4]
duration = sys.argv[5]
reconnect_sleep = sys.argv[6]
keepalive = sys.argv[7]

lines = conf.read_text().splitlines()
out = []
inside = False
replaced = False

new_cmd = (
    "command=/bin/bash -lc 'cd {app_dir} && while true; do {php} artisan "
    "future-trade:user-stream --workspace={workspace} --duration={duration} "
    "--reconnect-sleep={reconnect_sleep} --max-keepalive={keepalive}; sleep 1; done'"
).format(
    app_dir=app_dir,
    php=php,
    workspace=workspace,
    duration=duration,
    reconnect_sleep=reconnect_sleep,
    keepalive=keepalive,
)

for line in lines:
    stripped = line.strip()
    if stripped == "[program:user-stream-loop]":
        inside = True
        out.append(line)
        continue

    if inside and line.startswith("[") and stripped != "[program:user-stream-loop]":
        inside = False

    if inside and line.startswith("command="):
        out.append(new_cmd)
        replaced = True
    else:
        out.append(line)

if not replaced:
    raise SystemExit("Could not find command= inside [program:user-stream-loop]")

conf.write_text("\n".join(out) + "\n")
print("updated", conf)
PY

  grep -n "user-stream-loop\|future-trade:user-stream" "$conf" || true
}

clear_runtime_state() {
  log "clearing runtime heartbeat/cache locks"
  rm -f \
    storage/framework/autofinx-market-ws-heartbeat-futures.json \
    storage/framework/autofinx-user-stream-heartbeat.json \
    storage/framework/autofinx-account-sync-heartbeat.json \
    storage/framework/autofinx-runtime-master-live-feed-restart.at \
    storage/framework/autofinx-runtime-master-user-stream-restart.at \
    storage/framework/autofinx-runtime-master-account-sync.at

  "$PHP_BIN" artisan cache:forget autofinx_ft:user_stream_lock >/dev/null 2>&1 || true
  "$PHP_BIN" artisan cache:forget "autofinx_ft:user_stream_lock:$WORKSPACE_ID" >/dev/null 2>&1 || true
}

restart_runtime() {
  log "clearing Laravel caches"
  "$PHP_BIN" artisan optimize:clear || true

  log "installing/restarting runtime master"
  chmod +x autofinx-runtime-master.sh autofinx-dedupe-runtime.sh database/scripts/install-runtime-master.sh 2>/dev/null || true
  APP_DIR="$APP_DIR" PHP_BIN="$PHP_BIN" RUN_USER="$RUN_USER" bash database/scripts/install-runtime-master.sh

  log "reloading supervisor"
  supervisorctl reread || true
  supervisorctl update || true

  log "restarting supervisor-owned realtime processes"
  supervisorctl stop autofinx-trading:user-stream-loop >/dev/null 2>&1 || true
  supervisorctl stop autofinx-trading:live-feed >/dev/null 2>&1 || true
  pkill -f "artisan future-trade:user-stream" >/dev/null 2>&1 || true
  pkill -f "artisan future-trade:live-feed" >/dev/null 2>&1 || true

  supervisorctl start autofinx-trading:live-feed || true
  supervisorctl start autofinx-trading:user-stream-loop || true
}

verify_runtime() {
  log "refreshing account sync"
  "$PHP_BIN" artisan future-trade:account-sync --workspace="$WORKSPACE_ID" --json || true

  log "waiting ${VERIFY_WAIT_SECONDS}s for heartbeats"
  sleep "$VERIFY_WAIT_SECONDS"

  APP_DIR="$APP_DIR" PHP="$PHP_BIN" ./autofinx-runtime-master.sh once || true

  local live_age user_age account_age
  live_age="$(age_from_json_epoch storage/framework/autofinx-market-ws-heartbeat-futures.json last_message_epoch)"
  user_age="$(age_from_json_epoch storage/framework/autofinx-user-stream-heartbeat.json heartbeat_epoch)"
  account_age="$(age_from_json_epoch storage/framework/autofinx-account-sync-heartbeat.json sync_epoch)"

  echo
  echo "== process check =="
  pgrep -af "artisan future-trade:live-feed|artisan future-trade:user-stream|artisan future-trade:tick|artisan queue:work|artisan schedule:work|artisan schedule:run" || true

  echo
  echo "== heartbeat ages =="
  echo "live_feed_age_s=$live_age"
  echo "user_stream_age_s=$user_age"
  echo "account_sync_age_s=$account_age"

  echo
  echo "== runtime status =="
  "$PHP_BIN" artisan future-trade:runtime-status --workspace="$WORKSPACE_ID" || true

  if [ "$live_age" -gt 8 ]; then
    echo "FAIL: live feed heartbeat stale age=${live_age}s"
    exit 2
  fi

  if [ "$user_age" -gt 65 ]; then
    echo "FAIL: user stream heartbeat stale age=${user_age}s"
    exit 3
  fi

  if [ "$account_age" -gt 180 ]; then
    echo "FAIL: account sync stale age=${account_age}s"
    exit 4
  fi

  log "OK: Future Trade runtime is fresh and supervisor-owned."
}

patch_trading_supervisor
clear_runtime_state
restart_runtime
verify_runtime
