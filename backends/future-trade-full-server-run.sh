#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
APP_ROOT="${APP_ROOT:-$(dirname "$APP_DIR")}"
FRONTEND_DIR="${FRONTEND_DIR:-}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"

INSTALL_CRON=0
NO_PULL=0
SKIP_TESTS=0
NO_FRONTEND_BUILD=0
RESTART_RUNTIME=0
WORKSPACE_ID=""
TICK_DURATION=20
FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"

for arg in "$@"; do
  case "$arg" in
    --install-cron) INSTALL_CRON=1 ;;
    --no-pull) NO_PULL=1 ;;
    --skip-tests) SKIP_TESTS=1 ;;
    --no-frontend-build) NO_FRONTEND_BUILD=1 ;;
    --restart) RESTART_RUNTIME=1 ;;
    --workspace=*) WORKSPACE_ID="${arg#*=}" ;;
    --tick-duration=*) TICK_DURATION="${arg#*=}" ;;
    -h|--help)
      cat <<HELP
Future Trade full server runner

Usage:
  bash future-trade-full-server-run.sh [options]

Options:
  --restart             Restart queue workers and Future Trade live feed.
  --install-cron        Install cPanel cron entries for heartbeat and maintenance.
  --workspace=UUID      Run the scanner tick for one workspace only.
  --tick-duration=N     Scanner smoke duration in seconds. Default: 20.
  --skip-tests          Skip FutureTrade test suite.
  --no-frontend-build   Skip frontend npm build.
  --no-pull             Do not run git pull.

Environment overrides:
  APP_DIR, APP_ROOT, FRONTEND_DIR, PHP, COMPOSER, NPM, FT_SYMBOLS, FT_STREAMS
HELP
      exit 0
      ;;
  esac
done

if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi

log() {
  printf '\n[FT FULL] %s\n' "$*"
}

run_artisan() {
  "$PHP" artisan "$@"
}

cd "$APP_DIR"
mkdir -p storage/logs storage/framework storage/app/runtime

if [ -z "$FRONTEND_DIR" ]; then
  for candidate in \
    "$APP_ROOT/../autofinx-front/frontends" \
    "$APP_ROOT/../../autofinx-front/frontends" \
    "$(dirname "$APP_ROOT")/autofinx-front/frontends" \
    "$APP_DIR/../../autofinx-front/frontends"; do
    if [ -f "$candidate/package.json" ]; then
      FRONTEND_DIR="$candidate"
      break
    fi
  done
fi

log "Using APP_DIR=$APP_DIR"
log "Using PHP=$PHP"

if [ "$NO_PULL" -eq 0 ] && command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  log "Pulling latest backend code"
  git pull --ff-only
fi

log "Checking Future Trade PHP syntax"
find app/Domain/FutureTrade app/Domain/Market app/Http/Controllers/Api app/Console/Commands app/Models config routes \
  -type f \( -name "*FutureTrade*.php" -o -name "future_trade.php" -o -name "api.php" -o -name "console.php" \) \
  -print0 | xargs -0 -n 1 "$PHP" -l >/dev/null

log "Installing optimized backend dependencies"
"$COMPOSER" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$COMPOSER" dump-autoload --no-interaction --optimize

log "Running migrations and clearing caches"
run_artisan migrate --force
run_artisan optimize:clear
run_artisan config:cache
run_artisan route:cache

if [ "$NO_FRONTEND_BUILD" -eq 0 ] && [ -d "$FRONTEND_DIR" ]; then
  log "Building frontend at $FRONTEND_DIR"
  cd "$FRONTEND_DIR"
  "$NPM" install
  "$NPM" run build
  cd "$APP_DIR"
else
  log "Skipping frontend build"
fi

if [ "$SKIP_TESTS" -eq 0 ]; then
  log "Running Future Trade tests"
  run_artisan test --filter=FutureTrade
else
  log "Skipping tests"
fi

log "Preparing executable scripts"
chmod +x \
  start-workers.sh \
  stop-workers.sh \
  restart-workers.sh \
  future-trade-heartbeat.sh \
  future-trade-maintenance.sh \
  future-trade-refresh.sh \
  future-trade-setup.sh \
  future-trade-full-server-run.sh

if [ "$RESTART_RUNTIME" -eq 1 ]; then
  log "Restarting runtime workers and live feed"
  APP_DIR="$APP_DIR" PHP="$PHP" FT_SYMBOLS="$FT_SYMBOLS" FT_STREAMS="$FT_STREAMS" ./restart-workers.sh
else
  log "Starting missing runtime workers and live feed"
  APP_DIR="$APP_DIR" PHP="$PHP" FT_SYMBOLS="$FT_SYMBOLS" FT_STREAMS="$FT_STREAMS" ./start-workers.sh
fi

log "Running Future Trade scanner smoke"
if [ -n "$WORKSPACE_ID" ]; then
  run_artisan future-trade:tick --duration="$TICK_DURATION" --sleep-ms=1000 --workspace="$WORKSPACE_ID"
else
  run_artisan future-trade:tick --duration="$TICK_DURATION" --sleep-ms=1000
fi

log "Running operational checks"
run_artisan future-trade:diagnostics --persist || true
run_artisan future-trade:queue-health || true
run_artisan future-trade:runtime-status || true
run_artisan future-trade:reconcile || true
run_artisan future-trade:audit-missed-signals --limit=100 || true

CRON_LINES=$(cat <<CRON
* * * * * APP_DIR="$APP_DIR" PHP="$PHP" FT_SYMBOLS="$FT_SYMBOLS" FT_STREAMS="$FT_STREAMS" bash "$APP_DIR/future-trade-heartbeat.sh" >> "$APP_DIR/storage/logs/future-trade-heartbeat.log" 2>&1
*/10 * * * * flock -n /tmp/autofinx-future-trade-maintenance.lock -c 'APP_DIR="$APP_DIR" PHP="$PHP" bash "$APP_DIR/future-trade-maintenance.sh" >> "$APP_DIR/storage/logs/future-trade-cron.log" 2>&1'
CRON
)

if [ "$INSTALL_CRON" -eq 1 ]; then
  log "Installing cron entries"
  (crontab -l 2>/dev/null \
    | grep -v "future-trade-heartbeat.sh" \
    | grep -v "future-trade-maintenance.sh" \
    | grep -v "artisan future-trade:tick" \
    | grep -v "artisan future-trade:user-stream" \
    | grep -v "artisan future-trade:live-feed"; \
    echo "$CRON_LINES") | crontab -
  log "Cron installed"
else
  log "Add these cPanel cron entries when ready"
  echo "$CRON_LINES"
fi

log "Complete. Check these logs if needed:"
echo "  $APP_DIR/storage/logs/future-trade-heartbeat.log"
echo "  $APP_DIR/storage/logs/future-trade-tick.log"
echo "  $APP_DIR/storage/logs/future-trade-live-feed.log"
echo "  $APP_DIR/storage/logs/laravel.log"
