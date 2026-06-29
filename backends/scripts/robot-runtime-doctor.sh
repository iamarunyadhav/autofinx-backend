#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

PHP_BIN="${PHP_BIN:-php}"
LOG_DIR="$ROOT_DIR/storage/logs"
mkdir -p "$LOG_DIR"

REPAIR=1
MAX_ATTEMPTS="${MAX_ATTEMPTS:-3}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"

for arg in "$@"; do
  case "$arg" in
    --check-only) REPAIR=0 ;;
    --repair) REPAIR=1 ;;
    --attempts=*) MAX_ATTEMPTS="${arg#*=}" ;;
    --sleep=*) SLEEP_SECONDS="${arg#*=}" ;;
  esac
done

timestamp() {
  date -Is
}

line() {
  printf '[%s] %s\n' "$(timestamp)" "$*"
}

has_process() {
  local pattern="$1"
  pgrep -af "$pattern" >/dev/null 2>&1
}

start_process() {
  local name="$1"
  local pattern="$2"
  local command="$3"
  local logfile="$4"

  if has_process "$pattern"; then
    line "OK $name running"
    return 0
  fi

  line "MISSING $name"
  if [ "$REPAIR" -ne 1 ]; then
    return 1
  fi

  line "START $name"
  nohup bash -lc "$command" >> "$LOG_DIR/$logfile" 2>&1 &
  sleep 2

  if has_process "$pattern"; then
    line "FIXED $name started"
    return 0
  fi

  line "FAILED $name did not start"
  return 1
}

run_artisan() {
  local label="$1"
  shift
  line "RUN $label: $PHP_BIN artisan $*"
  if "$PHP_BIN" artisan "$@"; then
    line "OK $label"
    return 0
  fi
  line "FAILED $label"
  return 1
}

check_once() {
  local failed=0

  start_process "scheduler" "artisan schedule:work" "$PHP_BIN artisan schedule:work" "schedule-work.log" || failed=1
  start_process "queue worker" "artisan queue:work" "$PHP_BIN artisan queue:work --queue=critical,execution,signals,market,default --sleep=1 --tries=1 --timeout=90" "queue-work.log" || failed=1
  start_process "futures market feed" "artisan market:ws-consume.*market-type=futures" "$PHP_BIN artisan market:ws-consume --market-type=futures --store-ohlc --ohlc-intervals=1m,5m,15m --no-dispatch" "market-feed.log" || failed=1

  if [ "$REPAIR" -eq 1 ]; then
    run_artisan "queue restart signal" queue:restart || true
    run_artisan "futures feed refresh" market:ws-consume --once --market-type=futures --store-ohlc --ohlc-intervals=1m,5m,15m --no-dispatch || failed=1
    run_artisan "robot session evaluate" robot-sessions:evaluate --limit=50 || failed=1
  fi

  run_artisan "runtime status" future-trade:runtime-status --json || failed=1

  return "$failed"
}

line "AutoFinX Robot Runtime Doctor started repair=$REPAIR attempts=$MAX_ATTEMPTS"

attempt=1
while [ "$attempt" -le "$MAX_ATTEMPTS" ]; do
  line "Attempt $attempt/$MAX_ATTEMPTS"
  if check_once; then
    line "Runtime doctor completed successfully"
    exit 0
  fi

  if [ "$attempt" -lt "$MAX_ATTEMPTS" ]; then
    line "Retrying in ${SLEEP_SECONDS}s"
    sleep "$SLEEP_SECONDS"
  fi
  attempt=$((attempt + 1))
done

line "Runtime doctor finished with unresolved issues"
exit 2
