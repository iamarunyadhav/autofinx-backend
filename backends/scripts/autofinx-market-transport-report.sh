#!/usr/bin/env bash
# Read-only production evidence bundle for the Supervisor-owned market feed.
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
OUT_DIR="${OUT_DIR:-$APP_DIR/storage/logs/diagnostics}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT_DIR="$OUT_DIR/market-feed-$STAMP"

if [ ! -f "$APP_DIR/artisan" ] || [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "APP_DIR/artisan or PHP_BIN is invalid. Set APP_DIR and PHP_BIN." >&2
  exit 2
fi

mkdir -p "$REPORT_DIR"
chmod 700 "$REPORT_DIR"
cd "$APP_DIR"

run_capture() {
  local name="$1"; shift
  timeout 45 "$@" >"$REPORT_DIR/$name.out" 2>"$REPORT_DIR/$name.err" || true
}

run_capture supervisor-status supervisorctl status
run_capture feed-diagnostics-futures "$PHP_BIN" artisan market:feed-diagnostics --market-type=futures --timeframe=1m --json --no-interaction
run_capture feed-diagnostics-spot "$PHP_BIN" artisan market:feed-diagnostics --market-type=spot --timeframe=1m --json --no-interaction
run_capture schedule-list "$PHP_BIN" artisan schedule:list --no-ansi
run_capture runtime-audit bash "$APP_DIR/scripts/autofinx-runtime-audit.sh"
run_capture transport-benchmark "$PHP_BIN" artisan market:transport-benchmark --events=50000 --symbols=4 --flush-ms=100 --no-interaction

for program in autofinx-ratio-feed autofinx-trading:live-feed; do
  safe_name="${program//:/-}"
  run_capture "supervisor-$safe_name-tail" supervisorctl tail -8000 "$program" stdout
done

{
  echo "AUTOFINX MARKET-FEED REPORT"
  echo "created_utc=$(date -u +%FT%TZ)"
  echo "app_dir=$APP_DIR"
  echo
  grep -E 'RUNNING|BACKOFF|FATAL|STOPPED' "$REPORT_DIR/supervisor-status.out" || true
  echo
  echo "Futures diagnostics:"
  cat "$REPORT_DIR/feed-diagnostics-futures.out"
  echo
  echo "Analysis:"
  if ! grep -q 'autofinx-ratio-feed.*RUNNING' "$REPORT_DIR/supervisor-status.out"; then
    echo "FAIL: autofinx-ratio-feed is not RUNNING. Do not enable live trading."
  fi
  if grep -Eqi 'BACKOFF|FATAL|STOPPED' "$REPORT_DIR/supervisor-status.out"; then
    echo "WARN: one or more Supervisor programs are unhealthy; inspect supervisor-status.out."
  fi
  if grep -Eqi '"quote_fresh"[[:space:]]*:[[:space:]]*false' "$REPORT_DIR/feed-diagnostics-futures.out"; then
    echo "FAIL: at least one futures quote is stale; do not enable live trading."
  fi
  if grep -Eqi '"candle_cache_size"[[:space:]]*:[[:space:]]*0' "$REPORT_DIR/feed-diagnostics-futures.out"; then
    echo "WARN: at least one subscribed symbol has no 1m candle cache."
  fi
  if grep -Eqi 'Circuit breaker|No valid market frame accepted|WebSocket connection closed' "$REPORT_DIR/supervisor-autofinx-ratio-feed-tail.out"; then
    echo "WARN: websocket reconnect/error evidence found; inspect ratio-feed tail before enabling live trading."
  fi
  echo "Report files: $REPORT_DIR"
} | tee "$REPORT_DIR/SUMMARY.txt"

ARCHIVE="$OUT_DIR/market-feed-$STAMP.tar.gz"
tar -C "$OUT_DIR" -czf "$ARCHIVE" "$(basename "$REPORT_DIR")"
chmod 600 "$ARCHIVE"
echo "ARCHIVE=$ARCHIVE"
echo "This script is read-only: it never restarts a process, changes Redis, or places orders."
