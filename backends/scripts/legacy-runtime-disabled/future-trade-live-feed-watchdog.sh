#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-$(command -v php || true)}"
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/usr/local/bin/ea-php82" ]; then
  PHP="/usr/local/bin/ea-php82"
fi
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "[LIVE-FEED-WATCHDOG] PHP binary not found. Set PHP=/path/to/php and retry." >&2
  exit 1
fi

FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"
CHECK_INTERVAL_SECONDS="${LIVE_FEED_WATCHDOG_INTERVAL_SECONDS:-3}"
STALE_SECONDS="${LIVE_FEED_WATCHDOG_STALE_SECONDS:-8}"
RESTART_COOLDOWN_SECONDS="${LIVE_FEED_WATCHDOG_RESTART_COOLDOWN_SECONDS:-12}"
WARMUP_SECONDS="${LIVE_FEED_WATCHDOG_WARMUP_SECONDS:-15}"

cd "$APP_DIR"
mkdir -p storage/logs storage/framework

HEARTBEAT_FILE="$APP_DIR/storage/framework/autofinx-live-feed-heartbeat.json"
RESTART_STAMP="$APP_DIR/storage/framework/future-trade-live-feed-watchdog-restart.at"
OUT_LOG="$APP_DIR/storage/logs/future-trade-live-feed.log"
ERR_LOG="$APP_DIR/storage/logs/future-trade-live-feed.error.log"
WATCHDOG_LOG="$APP_DIR/storage/logs/future-trade-live-feed-watchdog.log"

log() {
  echo "[LIVE-FEED-WATCHDOG] $(date -Is) $*" | tee -a "$WATCHDOG_LOG"
}

process_count() {
  pgrep -f "artisan future-trade:live-feed" 2>/dev/null | wc -l | tr -d ' '
}

supervisor_program_exists() {
  command -v supervisorctl >/dev/null 2>&1 \
    && supervisorctl status 2>/dev/null | awk '$1 == "autofinx-trading:live-feed" { found=1 } END { exit found ? 0 : 1 }'
}

heartbeat_epoch() {
  if [ ! -s "$HEARTBEAT_FILE" ]; then
    echo 0
    return 0
  fi

  "$PHP" -r '$p=$argv[1]; $j=json_decode((string) @file_get_contents($p), true); echo is_array($j) ? (int) ($j["last_message_epoch"] ?? 0) : 0;' "$HEARTBEAT_FILE" 2>/dev/null || echo 0
}

start_live_feed() {
  local reason="$1"
  if supervisor_program_exists; then
    log "starting live-feed via supervisor reason=$reason"
    supervisorctl start "autofinx-trading:live-feed" >> "$WATCHDOG_LOG" 2>&1 || supervisorctl restart "autofinx-trading:live-feed" >> "$WATCHDOG_LOG" 2>&1 || true
    date +%s > "$RESTART_STAMP"
    return 0
  fi

  log "starting live-feed reason=$reason symbols=$FT_SYMBOLS streams=$FT_STREAMS"
  nohup "$PHP" artisan future-trade:live-feed \
    --symbols="$FT_SYMBOLS" \
    --streams="$FT_STREAMS" \
    --reconnect-sleep=1 \
    >> "$OUT_LOG" \
    2>> "$ERR_LOG" &
  date +%s > "$RESTART_STAMP"
}

restart_live_feed() {
  local reason="$1"
  local now_epoch
  local last_restart

  now_epoch="$(date +%s)"
  last_restart="$(cat "$RESTART_STAMP" 2>/dev/null || echo 0)"
  if [ $((now_epoch - last_restart)) -lt "$RESTART_COOLDOWN_SECONDS" ]; then
    log "restart skipped reason=$reason cooldown_active=$((now_epoch - last_restart))s"
    return 0
  fi

  log "restarting live-feed reason=$reason"
  if supervisor_program_exists; then
    if [ -x "$APP_DIR/autofinx-dedupe-runtime.sh" ]; then
      APP_DIR="$APP_DIR" "$APP_DIR/autofinx-dedupe-runtime.sh" >> "$WATCHDOG_LOG" 2>&1 || true
    fi
    supervisorctl restart "autofinx-trading:live-feed" >> "$WATCHDOG_LOG" 2>&1 || true
    date +%s > "$RESTART_STAMP"
    return 0
  fi

  pkill -f "artisan future-trade:live-feed" >/dev/null 2>&1 || true
  sleep 1
  start_live_feed "$reason"
}

log "started interval=${CHECK_INTERVAL_SECONDS}s stale=${STALE_SECONDS}s cooldown=${RESTART_COOLDOWN_SECONDS}s warmup=${WARMUP_SECONDS}s"

if [ -x "$APP_DIR/autofinx-dedupe-runtime.sh" ]; then
  APP_DIR="$APP_DIR" "$APP_DIR/autofinx-dedupe-runtime.sh" >> "$WATCHDOG_LOG" 2>&1 || true
fi

while true; do
  now_epoch="$(date +%s)"
  feed_count="$(process_count)"
  hb_epoch="$(heartbeat_epoch)"

  if [ "$feed_count" -lt 1 ]; then
    start_live_feed "missing_process"
    sleep "$CHECK_INTERVAL_SECONDS"
    continue
  fi

  if [ "$feed_count" -gt 1 ]; then
    restart_live_feed "duplicate_processes_${feed_count}"
    sleep "$CHECK_INTERVAL_SECONDS"
    continue
  fi

  last_restart="$(cat "$RESTART_STAMP" 2>/dev/null || echo 0)"
  if [ "$hb_epoch" -le 0 ]; then
    if [ $((now_epoch - last_restart)) -gt "$WARMUP_SECONDS" ]; then
      restart_live_feed "heartbeat_missing"
    fi
    sleep "$CHECK_INTERVAL_SECONDS"
    continue
  fi

  age=$((now_epoch - hb_epoch))
  if [ "$age" -gt "$STALE_SECONDS" ]; then
    restart_live_feed "heartbeat_stale_${age}s"
  fi

  sleep "$CHECK_INTERVAL_SECONDS"
done
