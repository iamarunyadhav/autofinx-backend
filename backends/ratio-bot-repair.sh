#!/usr/bin/env bash
# One-shot production repair for the 2026-10-05 Ratio Bot / timestamp fixes.
# Run as root from anywhere:   bash ratio-bot-repair.sh
# Safe to re-run: every step is idempotent. Log: storage/logs/ratio-bot-repair-<time>.log
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
APP_USER="zcadmin"
FEEDS=(autofinx-ratio-feed autofinx-trading:live-feed)

[ "$(id -u)" -eq 0 ] || { echo "Run as root (it switches to ${APP_USER} for artisan)."; exit 1; }
cd "$APP_DIR"
LOG="storage/logs/ratio-bot-repair-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG") 2>&1

as_app() { runuser -u "$APP_USER" -- "$@"; }
artisan() { as_app "$PHP" artisan "$@"; }
tinker() { artisan tinker --execute="$1"; }
step() { echo; echo "=== $* ==="; }

restart_feeds() { supervisorctl start "${FEEDS[@]}" >/dev/null 2>&1 || true; }
trap restart_feeds EXIT   # never leave the market feeds stopped, even on failure

step "1/8 Code + permissions"
# The whole checkout: a root "git pull" leaves root-owned files that block
# the app user's next pull, cache writes and log writes.
chown -R "$APP_USER:$APP_USER" "$(dirname "$APP_DIR")"
as_app git pull --ff-only
as_app git log -1 --oneline

step "2/8 Before: columns rewritten on UPDATE, recoverable decision times, corrupted candles"
tinker "echo 'auto-update timestamp columns: '.count(App\Support\Database\TimestampAutoUpdateRepair::columns()).PHP_EOL;
echo 'ratio_bot_decisions.decided_at to restore: '.DB::table('ratio_bot_decisions')->whereNotNull('created_at')->whereRaw('decided_at > created_at + INTERVAL 2 HOUR')->count().PHP_EOL;"

step "3/8 Pending migrations (only the two repair migrations are run by this script)"
artisan migrate:status | grep -i pending || echo "none pending"

step "4/8 Migrations (market feeds paused while market_ohlc may be altered)"
supervisorctl stop "${FEEDS[@]}" || true
artisan migrate --force --path=database/migrations/2026_10_10_000000_stop_market_ohlc_ts_auto_update.php
artisan migrate --force --path=database/migrations/2026_10_10_000100_stop_timestamp_auto_update_everywhere.php
restart_feeds
tinker "echo 'auto-update timestamp columns left: '.count(App\Support\Database\TimestampAutoUpdateRepair::columns()).PHP_EOL;
echo 'decided_at still to restore: '.DB::table('ratio_bot_decisions')->whereNotNull('created_at')->whereRaw('decided_at > created_at + INTERVAL 2 HOUR')->count().PHP_EOL;"

step "5/8 Remove corrupted candles (open time not on :00, or in the future) and refill from Binance"
tinker "\$q = fn () => DB::table('market_ohlc')->where(fn (\$w) => \$w->whereRaw('SECOND(ts) <> 0')->orWhere('ts', '>', now('UTC')->addMinutes(2)));
echo 'corrupted candles found: '.\$q()->count().PHP_EOL; echo 'deleted: '.\$q()->delete().PHP_EOL;"
artisan market:ohlc-backfill --all-ratio-bots --force --limit=1000

step "6/8 Config, stuck scheduler locks, workers"
artisan config:cache
artisan schedule:clear-cache
artisan queue:restart
supervisorctl restart all

step "7/8 Settings that need YOUR decision (not changed by this script)"
grep -E "^LIVE_TRADING_ENABLED" .env || echo "LIVE_TRADING_ENABLED not set (= false)"
echo "  false = every live SPOT order (Ratio Bot included) is refused. Set true only if you want live spot trading."
echo "  BTC bot: holds no BTC on Binance; use Stop -> Start in the UI (do not Resume)."

step "8/8 Health check (waiting 90s for the scheduler and feeds to cycle)"
sleep 90
artisan ratio-bots:verify --exchange || true

echo
echo "Done. Full log: $APP_DIR/$LOG"
