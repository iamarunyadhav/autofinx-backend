#!/usr/bin/env bash
# ============================================================
#  AutoFinX — Patch Deploy: Robot Trade Improvements +
#             KlineBrainService v11.0 + Warning Fixes
#  Date: June 2026
#
#  Run this ON THE SERVER after uploading all changed files.
#  Usage:  bash deploy-patch-jun2026.sh
# ============================================================
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
  echo "ERROR: PHP binary not found. Set PHP=/path/to/php" >&2
  exit 1
fi

cd "$APP_DIR"

echo "============================================"
echo " AutoFinX patch deploy — $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"

# ─── 1. PHP syntax check — all changed files ──────────────────────────────
echo ""
echo "[1/6] PHP syntax check..."
CHANGED=(
  "app/Domain/Trading/KlineBrainService.php"
  "app/Console/Commands/BinanceKlineStreamCommand.php"
  "app/Domain/Ops/OperationalHealthService.php"
  "app/Domain/Trading/RobotSessionService.php"
  "app/Models/RobotSession.php"
  "database/migrations/2026_06_19_000000_add_signal_streak_to_robot_sessions.php"
)
FAIL=0
for f in "${CHANGED[@]}"; do
  if [ -f "$f" ]; then
    "$PHP" -l "$f" > /dev/null && echo "   OK  $f" || { echo "   ERR $f"; FAIL=1; }
  else
    echo "   MISSING $f"
    FAIL=1
  fi
done
if [ "$FAIL" -eq 1 ]; then
  echo "Syntax errors found — aborting." >&2
  exit 1
fi
echo "   All OK."

# ─── 2. Stop workers ──────────────────────────────────────────────────────
echo ""
echo "[2/6] Stopping workers (freeing process slots)..."
pkill -f "artisan queue:work"              2>/dev/null || true
pkill -f "artisan schedule:work"           2>/dev/null || true
pkill -f "future-trade:live-feed"          2>/dev/null || true
pkill -f "future-trade:tick"               2>/dev/null || true
pkill -f "future-trade:user-stream"        2>/dev/null || true
pkill -f "trading:binance-kline-stream"    2>/dev/null || true
sleep 3
echo "   Stopped."

# ─── 3. composer dump-autoload (new classes need autoload map) ─────────────
echo ""
echo "[3/6] composer dump-autoload (new classes: KlineBrainService, BinanceKlineStreamCommand)..."
COMPOSER=""
for c in /usr/local/bin/composer /usr/bin/composer /usr/local/cpanel/3rdparty/lib/cpan/scripts/composer; do
  [ -f "$c" ] && { COMPOSER="$c"; break; }
done
if [ -n "$COMPOSER" ]; then
  "$PHP" "$COMPOSER" dump-autoload --no-interaction --no-scripts --no-plugins --quiet 2>/dev/null \
    && echo "   Autoload OK." \
    || echo "   Autoload WARN (non-fatal — existing vendor/ used)."
else
  echo "   Composer not found — using existing vendor/ autoload."
fi

# ─── 4. Migrations ────────────────────────────────────────────────────────
echo ""
echo "[4/6] Running migrations..."
"$PHP" artisan migrate --force
echo "   Done."

# ─── 5. Cache clear ───────────────────────────────────────────────────────
echo ""
echo "[5/6] Clearing caches..."
"$PHP" artisan config:clear 2>/dev/null || true
"$PHP" artisan route:clear  2>/dev/null || true
"$PHP" artisan view:clear   2>/dev/null || true
"$PHP" artisan cache:clear  2>/dev/null || true
"$PHP" artisan config:cache 2>/dev/null || true
"$PHP" artisan route:cache  2>/dev/null || true
echo "   Done."

# ─── 6. Restart workers ───────────────────────────────────────────────────
echo ""
echo "[6/6] Starting workers..."
nohup "$PHP" artisan queue:work \
  --queue=critical,execution,reconcile,monitoring \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-critical.log 2>&1 &

nohup "$PHP" artisan queue:work \
  --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-market.log 2>&1 &

nohup "$PHP" artisan queue:work \
  --queue=analytics,notifications,default,monitoring_safe \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-safe.log 2>&1 &

nohup "$PHP" artisan future-trade:live-feed --reconnect-sleep=2 \
  >> storage/logs/future-trade-live-feed.log \
  2>> storage/logs/future-trade-live-feed.error.log &

# Start kline streams for multi-symbol brain (15m + 1h)
nohup "$PHP" artisan trading:binance-kline-stream \
  --timeframe=15m \
  --symbols=BTCUSDT,ETHUSDT,SOLUSDT,BNBUSDT,XRPUSDT,DOGEUSDT,ADAUSDT,1000PEPEUSDT \
  --reconnect-sleep=5 \
  >> storage/logs/kline-stream-15m.log 2>&1 &

nohup "$PHP" artisan trading:binance-kline-stream \
  --timeframe=1h \
  --symbols=BTCUSDT,ETHUSDT,SOLUSDT,BNBUSDT,XRPUSDT,DOGEUSDT,ADAUSDT,1000PEPEUSDT \
  --reconnect-sleep=5 \
  >> storage/logs/kline-stream-1h.log 2>&1 &

sleep 3
echo "   Workers started."

# ─── Done ─────────────────────────────────────────────────────────────────
echo ""
echo "============================================"
echo " DONE — $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo " Process status:"
printf "   queue workers  = %s\n" "$(pgrep -fc 'artisan queue:work'       2>/dev/null || echo 0)"
printf "   live-feed      = %s\n" "$(pgrep -fc 'future-trade:live-feed'   2>/dev/null || echo 0)"
printf "   kline-15m      = %s\n" "$(pgrep -fc 'kline-stream.*15m'        2>/dev/null || echo 0)"
printf "   kline-1h       = %s\n" "$(pgrep -fc 'kline-stream.*1h'         2>/dev/null || echo 0)"
echo "============================================"
