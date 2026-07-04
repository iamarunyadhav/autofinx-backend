#!/usr/bin/env bash
set -euo pipefail

# AutoFinX one-shot post-deploy runtime fixer.
# Runs the important production maintenance commands in a safe order.
# Runtime ownership is centralized in Supervisor + autofinx-runtime-master.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"
FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"
WORKSPACE_ID="${WORKSPACE_ID:-}"
TICK_DURATION="${TICK_DURATION:-20}"
INSTALL_CRON=0
NO_SUPERVISOR=0
NO_COMPOSER=0
NO_MIGRATE=0
NO_CACHE=0
SKIP_SMOKE=0
WATCH=0

usage() {
  cat <<HELP
AutoFinX post-deploy all-in-one runtime script

Usage:
  bash autofinx_post_deploy_all.sh [options]

Options:
  --workspace=UUID     Limit workspace-aware smoke commands.
  --install-cron       Install maintenance cron only; runtime is master-owned.
  --no-supervisor      Skip supervisorctl/runtime-master checks.
  --no-composer        Skip composer install/dump-autoload.
  --no-migrate         Skip php artisan migrate --force.
  --no-cache           Skip config/route cache rebuild.
  --skip-smoke         Skip tick/user-stream smoke runs.
  --watch              Tail live-feed log for 30 seconds at the end.
  -h, --help           Show this help.

Environment overrides:
  APP_DIR, PHP, COMPOSER_BIN, FT_SYMBOLS, FT_STREAMS, WORKSPACE_ID, TICK_DURATION
HELP
}

for arg in "$@"; do
  case "$arg" in
    --workspace=*) WORKSPACE_ID="${arg#*=}" ;;
    --install-cron) INSTALL_CRON=1 ;;
    --no-supervisor) NO_SUPERVISOR=1 ;;
    --no-composer) NO_COMPOSER=1 ;;
    --no-migrate) NO_MIGRATE=1 ;;
    --no-cache) NO_CACHE=1 ;;
    --skip-smoke) SKIP_SMOKE=1 ;;
    --watch) WATCH=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; usage; exit 2 ;;
  esac
done

if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/usr/local/bin/ea-php82" ]; then
  PHP="/usr/local/bin/ea-php82"
fi
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; }; then
  PHP="$(command -v php || true)"
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "PHP binary not found. Set PHP=/path/to/php and retry." >&2
  exit 1
fi

cd "$APP_DIR"
mkdir -p storage/logs storage/framework storage/framework/cache storage/framework/sessions

LOG_FILE="$APP_DIR/storage/logs/autofinx-post-deploy-all.log"
LOCK_DIR="$APP_DIR/storage/framework/autofinx-post-deploy-all.lock"

if [ -d "$LOCK_DIR" ]; then
  if find "$LOCK_DIR" -maxdepth 0 -mmin +20 2>/dev/null | grep -q .; then
    rmdir "$LOCK_DIR" >/dev/null 2>&1 || true
  fi
fi
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  echo "[POST-DEPLOY] another run is active: $LOCK_DIR" >&2
  exit 1
fi
trap 'rmdir "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT

exec > >(tee -a "$LOG_FILE") 2>&1

section() {
  printf '\n[%s] %s\n' "$(date -Is)" "$*"
}

run() {
  section "$*"
  "$@"
}

artisan() {
  "$PHP" artisan "$@"
}

try_artisan() {
  section "php artisan $*"
  timeout 120 "$PHP" artisan "$@" || true
}

has_supervisor() {
  [ "$NO_SUPERVISOR" -eq 0 ] && command -v supervisorctl >/dev/null 2>&1
}

restart_supervisor_group() {
  local group="$1"
  if supervisorctl status "$group" >/dev/null 2>&1; then
    supervisorctl restart "$group" || true
  fi
}

count_processes() {
  local pattern="$1"
  pgrep -f "$pattern" 2>/dev/null | wc -l | tr -d ' '
}

section "AutoFinX post-deploy all started"
echo "APP_DIR=$APP_DIR"
echo "PHP=$PHP"
echo "FT_SYMBOLS=$FT_SYMBOLS"
echo "FT_STREAMS=$FT_STREAMS"
echo "WORKSPACE_ID=${WORKSPACE_ID:-all}"

section "1. PHP syntax smoke for runtime files"
find app/Console/Commands app/Domain/Trading app/Domain/FutureTrade app/Domain/Ops app/Jobs routes config \
  -type f -name "*.php" -print0 \
  | xargs -0 -n 1 "$PHP" -l >/dev/null

if [ "$NO_COMPOSER" -eq 0 ] && [ -n "$COMPOSER_BIN" ] && [ -e "$COMPOSER_BIN" ]; then
  section "2. Composer optimized autoload"
  COMPOSER= "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
  COMPOSER= "$COMPOSER_BIN" dump-autoload --no-interaction --no-scripts --optimize
else
  section "2. Composer skipped"
fi

section "3. Laravel deploy maintenance"
artisan down --render="errors::503" >/dev/null 2>&1 || true
artisan optimize:clear || true
if [ "$NO_MIGRATE" -eq 0 ]; then
  artisan migrate --force
fi
if [ "$NO_CACHE" -eq 0 ]; then
  artisan package:discover --ansi || true
  artisan config:cache
  artisan route:cache
fi
artisan up >/dev/null 2>&1 || true

section "4. Stale cleanup and queue restart"
try_artisan future-trade:queue-cleanup --safe
try_artisan queue:prune-failed --hours=168
try_artisan queue:restart

section "5. Supervisor and runtime master"
if has_supervisor; then
  supervisorctl reread || true
  supervisorctl update || true
  if [ -x "$APP_DIR/database/scripts/install-runtime-master.sh" ]; then
    APP_DIR="$APP_DIR" PHP_BIN="$PHP" RUN_USER="${RUN_USER:-zcadmin}" bash "$APP_DIR/database/scripts/install-runtime-master.sh" || true
  fi
  supervisorctl restart autofinx-runtime-master || supervisorctl start autofinx-runtime-master || true
  APP_DIR="$APP_DIR" PHP="$PHP" "$APP_DIR/autofinx-runtime-master.sh" once || true
  supervisorctl status || true
else
  echo "supervisorctl unavailable/skipped; runtime workers were not started."
fi

section "6. Quick trade settlement, keepalive, and reconcile"
try_artisan quick-trades:settle
if [ -n "$WORKSPACE_ID" ]; then
  try_artisan future-trade:reconcile --workspace="$WORKSPACE_ID"
else
  try_artisan future-trade:reconcile
fi
try_artisan ops:reconcile-orders

section "7. Runtime smoke is master-owned"
APP_DIR="$APP_DIR" PHP="$PHP" "$APP_DIR/autofinx-runtime-master.sh" status || true

section "8. Diagnostics, feed stale check, queue health, readiness"
try_artisan future-trade:diagnostics --persist
try_artisan ops:stale-feed-check
try_artisan future-trade:queue-health
if [ -n "$WORKSPACE_ID" ]; then
  try_artisan ops:readiness-check --workspace="$WORKSPACE_ID"
  try_artisan future-trade:runtime-status --workspace="$WORKSPACE_ID"
else
  try_artisan ops:readiness-check
  try_artisan future-trade:runtime-status
fi

section "9. Process summary"
echo "queue_workers=$(count_processes 'artisan queue:work')"
echo "live_feed=$(count_processes 'artisan future-trade:live-feed')"
echo "future_tick=$(count_processes 'artisan future-trade:tick')"
echo "future_user_stream=$(count_processes 'artisan future-trade:user-stream')"
echo "schedule_run=$(count_processes 'artisan schedule:run')"

CRON_LINES=$(cat <<CRON
*/10 * * * * flock -n /tmp/autofinx-future-trade-maintenance.lock -c 'APP_DIR="$APP_DIR" PHP="$PHP" bash "$APP_DIR/future-trade-maintenance.sh" >> "$APP_DIR/storage/logs/future-trade-cron.log" 2>&1'
CRON
)

if [ "$INSTALL_CRON" -eq 1 ]; then
  section "10. Installing maintenance cron"
  (crontab -l 2>/dev/null \
    | grep -v "future-trade-heartbeat.sh" \
    | grep -v "future-trade-maintenance.sh"; \
    echo "$CRON_LINES") | crontab -
  crontab -l | grep -E "future-trade-maintenance" || true
else
  section "10. Cron not installed. Add this maintenance-only entry if needed"
  echo "$CRON_LINES"
fi

if [ "$WATCH" -eq 1 ]; then
  section "12. Watching live-feed log for 30 seconds"
  timeout 30 tail -f "$APP_DIR/storage/logs/future-trade-live-feed.log" || true
fi

section "AutoFinX post-deploy all complete"
echo "Log: $LOG_FILE"
