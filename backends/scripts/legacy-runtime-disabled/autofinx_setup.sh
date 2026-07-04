#!/usr/bin/env bash
# AutoFinX Production Setup Script — Shared cPanel Host Edition
# Run ONCE after first deploy (or after `git pull`) to configure everything.
# Safe to re-run: idempotent where possible, skips already-done steps.
#
# Usage:
#   bash autofinx_setup.sh                  # full setup
#   bash autofinx_setup.sh --skip-composer  # skip composer install (vendor/ exists)
#   bash autofinx_setup.sh --only-crontab   # only install/update crontab
#
# What it does:
#   1. Detect PHP binary
#   2. Verify .env and required keys
#   3. composer install (skippable)
#   4. php artisan migrate --force
#   5. Cache clear + rebuild
#   6. Set maxOpenPositions=5 on workspace
#   7. Sync shadow slots (0..4) for multi-position routing
#   8. Install crontab (deduplicated — removes old AutoFinX entries first)
#   9. Start queue workers + live-feed
#  10. Smoke test: ping the health endpoint

set -euo pipefail

##############################################################################
# Config
##############################################################################
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="${APP_DIR:-$SCRIPT_DIR}"
WS_ID="${WS_ID:-26a36b56-4383-4edd-99d1-7622e7c191ef}"
APP_URL="${APP_URL:-https://api.autofinx.arunyadhav.dev}"

SKIP_COMPOSER=0
ONLY_CRONTAB=0
for arg in "$@"; do
  case "$arg" in
    --skip-composer) SKIP_COMPOSER=1 ;;
    --only-crontab)  ONLY_CRONTAB=1  ;;
  esac
done

##############################################################################
# Helpers
##############################################################################
log()  { echo "[SETUP] $*"; }
ok()   { echo "[SETUP] OK: $*"; }
warn() { echo "[SETUP] WARN: $*"; }
fail() { echo "[SETUP] FAIL: $*"; exit 1; }

##############################################################################
# Step 1: PHP binary detection
##############################################################################
detect_php() {
  if [ -n "${PHP:-}" ] && [ -x "$PHP" ]; then
    "$PHP" -r 'if(PHP_MAJOR_VERSION>=8&&PHP_MINOR_VERSION>=2)exit(0);exit(1);' 2>/dev/null && {
      echo "$PHP"; return 0;
    }
  fi
  for candidate in \
    "$(command -v php || true)" \
    /usr/local/bin/ea-php82 \
    /opt/cpanel/ea-php82/root/usr/bin/php \
    /usr/bin/php82 \
    /usr/bin/php8.2 \
    /usr/bin/php; do
    if [ -x "$candidate" ]; then
      "$candidate" -r 'if(PHP_MAJOR_VERSION>=8&&PHP_MINOR_VERSION>=2)exit(0);exit(1);' 2>/dev/null && {
        echo "$candidate"; return 0;
      }
    fi
  done
  return 1
}

PHP=$(detect_php) || fail "PHP 8.2+ binary not found. Set PHP env var manually."
ok "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

##############################################################################
# Step 2: cd to APP_DIR
##############################################################################
[ -d "$APP" ] || fail "APP_DIR not found: $APP"
cd "$APP"
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views

##############################################################################
# Step 3: .env check
##############################################################################
if [ ! -f ".env" ]; then
  if [ -f ".env.example" ]; then
    cp .env.example .env
    warn ".env created from .env.example — fill in secrets before proceeding!"
    warn "Required: APP_KEY, DB_DATABASE, FUTURE_TRADE_PINE_WEBHOOK_TOKEN"
    warn "Binance keys for live trading: BINANCE_FUTURES_API_KEY, BINANCE_FUTURES_API_SECRET"
  else
    fail ".env missing and no .env.example to copy from."
  fi
fi

APP_KEY=$(grep -E '^APP_KEY=' .env | cut -d= -f2- | tr -d '"' | tr -d "'")
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:your-key-here" ]; then
  log "Generating APP_KEY..."
  "$PHP" artisan key:generate --force
fi

##############################################################################
# Step 4: composer install
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ] && [ "$SKIP_COMPOSER" -eq 0 ]; then
  if [ ! -f "vendor/autoload.php" ]; then
    log "Running composer install (vendor/ missing)..."
    COMPOSER_BIN=$(command -v composer || command -v composer.phar || echo "")
    if [ -n "$COMPOSER_BIN" ]; then
      "$PHP" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5
    else
      warn "Composer not found in PATH — skipping vendor install. Upload vendor/ manually."
    fi
  else
    ok "vendor/autoload.php exists — skipping composer install (use --skip-composer to suppress this message)"
  fi
fi

##############################################################################
# Step 5: migrations
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ]; then
  log "Running migrations..."
  "$PHP" artisan migrate --force 2>&1 | tail -8
  ok "Migrations complete"
fi

##############################################################################
# Step 6: cache clear + rebuild
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ]; then
  log "Clearing and rebuilding caches..."
  "$PHP" artisan config:clear   2>/dev/null || true
  "$PHP" artisan route:clear    2>/dev/null || true
  "$PHP" artisan view:clear     2>/dev/null || true
  "$PHP" artisan cache:clear    2>/dev/null || true
  "$PHP" artisan config:cache   2>/dev/null || true
  "$PHP" artisan route:cache    2>/dev/null || true
  ok "Cache rebuild done"
fi

##############################################################################
# Step 7: workspace settings (maxOpenPositions=5, sync shadow slots 0..4)
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ]; then
  log "Configuring workspace $WS_ID..."
  "$PHP" artisan tinker --no-interaction --execute="
    try {
      \$svc = app(App\\Domain\\FutureTrade\\FutureTradeBotService::class);
      \$c = \$svc->getOrCreateConfig('$WS_ID');
      \$s = \$c->settings ?? [];
      \$s['maxOpenPositions'] = 5;
      \$s['signalSource']     = 'webhook';
      \$c->settings  = \$s;
      \$c->enabled   = true;
      \$c->save();
      \$svc->syncSlots(\$c);
      \$count = App\\Models\\FutureTradeBotConfig::where('workspace_id','$WS_ID')->count();
      echo 'slots_total='.\$count.' max_open_positions=5 enabled=true'.PHP_EOL;
    } catch(\\Throwable \$e) {
      echo 'workspace_setup_error: '.\$e->getMessage().PHP_EOL;
    }
  " 2>/dev/null
  ok "Workspace configured"
fi

##############################################################################
# Step 8: Crontab install (idempotent — removes old AutoFinX entries first)
##############################################################################
install_crontab() {
  TMPFILE=$(mktemp)
  # Read current crontab, strip any pre-existing AutoFinX lines
  crontab -l 2>/dev/null \
    | grep -v "future-trade\|autofinx\|heartbeat\.sh\|maintenance\.sh\|master_check\|future_trade\|schedule:run.*artisan" \
    > "$TMPFILE" || true

  # Append the canonical AutoFinX crontab block
  cat >> "$TMPFILE" <<CRON
# === AutoFinX Trading Bot — managed by autofinx_setup.sh ===
# 1. Heartbeat (every minute): queues + live-feed + tick + user-stream + schedule:run
* * * * * flock -n $APP/storage/framework/heartbeat.lock bash $APP/future-trade-heartbeat.sh >> $APP/storage/logs/future-trade-heartbeat.log 2>&1
# 2. Maintenance (every 5 min): diagnostics + missed-signal audit + queue cleanup
*/5 * * * * flock -n $APP/storage/framework/maintenance.lock bash $APP/future-trade-maintenance.sh >> $APP/storage/logs/future-trade-maintenance.log 2>&1
# 3. Reconcile orphan trades (every 5 min): auto-closes phantom OPEN logical_trades
*/5 * * * * flock -n $APP/storage/framework/reconcile.lock $PHP $APP/artisan future-trade:reconcile --workspace=$WS_ID >> $APP/storage/logs/future-trade-reconcile.log 2>&1
# 4. Master health check + auto-recovery (every 15 min)
*/15 * * * * flock -n $APP/storage/framework/master-check.lock bash $APP/autofinx_master_check.sh >> $APP/storage/logs/master-check.log 2>&1
# 5. Log rotation (daily 3 AM): truncate logs > 50 MB to 10 MB to prevent disk fill
0 3 * * * find $APP/storage/logs -name "*.log" -size +50M -exec truncate -s 10485760 {} \;
# @reboot: restart all workers when the server reboots
@reboot bash $APP/start-workers.sh >> $APP/storage/logs/start-workers.log 2>&1
CRON

  crontab "$TMPFILE"
  rm -f "$TMPFILE"
  ok "Crontab installed (6 entries, no duplicates)"
}

log "Installing crontab..."
install_crontab
echo ""
log "Current AutoFinX crontab entries:"
crontab -l 2>/dev/null | grep -E "future-trade|autofinx|heartbeat|maintenance|master_check|schedule:run|reconcile|truncate|start-workers" || true

##############################################################################
# Step 9: Start workers
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ]; then
  log "Starting workers..."
  bash "$APP/start-workers.sh" 2>/dev/null || true
  sleep 3

  QN=$(pgrep -fc "artisan queue:work" 2>/dev/null || echo 0)
  FN=$(pgrep -fc "artisan future-trade:live-feed" 2>/dev/null || echo 0)
  ok "Workers: queue=$QN live-feed=$FN"
fi

##############################################################################
# Step 10: Smoke test
##############################################################################
if [ "$ONLY_CRONTAB" -eq 0 ]; then
  log "Smoke testing health endpoint..."
  HEALTH_URL="$APP_URL/api/v1/future-trade/$WS_ID/runtime-health"
  if command -v curl >/dev/null 2>&1; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$HEALTH_URL" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "401" ]; then
      ok "Health endpoint responsive (HTTP $HTTP_CODE)"
    else
      warn "Health endpoint returned HTTP $HTTP_CODE — check APP_URL and .env"
    fi
  else
    warn "curl not found — skipping smoke test"
  fi
fi

##############################################################################
# Done
##############################################################################
echo ""
echo "=================================================="
echo " AutoFinX Setup Complete"
echo "=================================================="
echo " App:       $APP"
echo " Workspace: $WS_ID"
echo " PHP:       $PHP"
echo ""
echo " Crontab: 6 entries (heartbeat / maintenance / reconcile /"
echo "          master-check / log-rotation / @reboot)"
echo ""
echo " Next steps:"
echo "   1. Verify .env has FUTURE_TRADE_PINE_WEBHOOK_TOKEN set"
echo "   2. Set FUTURE_TRADE_ENABLE_LIVE_TRADING=true when ready for live"
echo "   3. Add your Binance API keys (testnet or live) to .env"
echo "   4. Test a webhook: curl -X POST $APP_URL/api/v1/future-trade/pine-webhook/$WS_ID"
echo "   5. Check slots: bash $APP/autofinx_master_check.sh"
echo "=================================================="
