#!/usr/bin/env bash
# Controlled deployment of the bounded market websocket transport.
# Default mode is a dry run. --apply changes .env, rebuilds Laravel caches and
# restarts the already-existing Supervisor market-feed owner only.
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
FEED_PROGRAM="${FEED_PROGRAM:-autofinx-ratio-feed}"
APPLY=0
RUN_TESTS=0

usage() {
  cat <<'EOF'
Usage: bash scripts/autofinx-market-transport-deploy.sh [--apply] [--test]

Without --apply, this performs only preflight checks. With --apply it updates
the transport-related .env keys, rebuilds Laravel caches, signals queue workers,
and restarts the existing Supervisor market-feed program. It never migrates.

Environment overrides: APP_DIR, PHP_BIN, FEED_PROGRAM.
EOF
}

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --test) RUN_TESTS=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

if [ ! -f "$APP_DIR/artisan" ] || [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "APP_DIR/artisan or PHP_BIN is invalid. Set APP_DIR and PHP_BIN." >&2
  exit 2
fi
if ! command -v supervisorctl >/dev/null 2>&1; then
  echo "supervisorctl is required; no manual websocket process will be started." >&2
  exit 2
fi

cd "$APP_DIR"
echo "APP_DIR=$APP_DIR"
echo "PHP_BIN=$PHP_BIN"
echo "FEED_PROGRAM=$FEED_PROGRAM"

status="$(supervisorctl status "$FEED_PROGRAM" 2>&1 || true)"
if [ -z "$status" ]; then
  echo "Supervisor program $FEED_PROGRAM was not found. Install/review deploy/autofinx-ratio-feed.supervisor.conf.example first." >&2
  exit 2
fi
echo "$status"

if ! supervisorctl status "$FEED_PROGRAM" 2>/dev/null | grep -q 'RUNNING\|STARTING\|STOPPED\|BACKOFF\|FATAL'; then
  echo "Could not read a valid Supervisor status for $FEED_PROGRAM." >&2
  exit 2
fi

echo "== Preflight =="
"$PHP_BIN" -l app/Console/Commands/MarketWsConsumerCommand.php
"$PHP_BIN" -l app/Domain/Market/MarketTransportCoalescer.php
"$PHP_BIN" artisan market:transport-benchmark --events=50000 --symbols=4 --flush-ms=100 --no-interaction
if [ "$RUN_TESTS" -eq 1 ]; then
  "$PHP_BIN" artisan test --compact tests/Unit/Market/MarketTransportCoalescerTest.php tests/Unit/Market/AsyncTradeForecastTest.php
fi

if [ "$APPLY" -eq 0 ]; then
  echo "DRY RUN complete. Review deploy/autofinx-market-transport.env.example, then rerun with --apply."
  exit 0
fi

FEED_PID="$(supervisorctl pid "$FEED_PROGRAM" 2>/dev/null || true)"
FEED_COMMAND=""
if [[ "$FEED_PID" =~ ^[0-9]+$ ]]; then
  FEED_COMMAND="$(ps -p "$FEED_PID" -o args= 2>/dev/null || true)"
fi
if ! grep -q 'market:ws-consume' <<<"$FEED_COMMAND"; then
  echo "Safety stop: $FEED_PROGRAM is not currently a market:ws-consume process." >&2
  echo "Review its Supervisor command; do not create or restart a duplicate websocket consumer." >&2
  exit 2
fi

ENV_FILE="$APP_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo ".env is missing: $ENV_FILE" >&2
  exit 2
fi
BACKUP_DIR="$APP_DIR/storage/app/deploy-backups"
mkdir -p "$BACKUP_DIR"
cp -p "$ENV_FILE" "$BACKUP_DIR/.env.market-transport.$(date -u +%Y%m%dT%H%M%SZ).bak"
chmod 600 "$BACKUP_DIR"/.env.market-transport.*.bak

set_env() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp "$APP_DIR/.env.market-transport.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    BEGIN { done=0 }
    $0 ~ "^" key "=" { print key "=" value; done=1; next }
    { print }
    END { if (!done) print key "=" value }
  ' "$ENV_FILE" >"$tmp"
  chmod 600 "$tmp"
  mv "$tmp" "$ENV_FILE"
}

set_env AUTOFINX_RUNTIME_OWNER supervisor
set_env AUTOFINX_SUPERVISOR_RUNTIME true
set_env AUTOFINX_SCHEDULER_MARKET_CONSUMERS false
set_env AUTOFINX_MARKET_TRANSPORT_FLUSH_INTERVAL_MS 100
set_env AUTOFINX_LIVE_QUOTE_WRITE_INTERVAL_MS 100
set_env AUTOFINX_LIVE_OPEN_CANDLE_WRITE_INTERVAL_MS 1000
set_env AUTOFINX_LIVE_FEED_SIDE_EFFECT_INTERVAL_MS 1000
set_env AUTOFINX_LIVE_FEED_MAX_EVENT_LAG_MS 10000

echo "== Applying cache/worker changes =="
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan queue:restart || true
supervisorctl restart "$FEED_PROGRAM"

echo "Waiting 15 seconds for the websocket owner to connect..."
sleep 15
APP_DIR="$APP_DIR" PHP_BIN="$PHP_BIN" bash "$APP_DIR/scripts/autofinx-market-transport-report.sh"
echo "Deploy complete. Read SUMMARY.txt in the emitted diagnostics archive before enabling/changing any live bot."
