#!/usr/bin/env bash
# AutoFinX Master Health Check + Clean Restart — SHARED-HOST FORK-FRIENDLY EDITION
# Run:  bash autofinx_master_check.sh
# Safe to run anytime; auto-recovers duplicates and missing workers.
#
# Why this version: shared cPanel hosts cap `ulimit -u` around 30-50 user
# processes. The previous script burst-spawned ~25 subshells via $(pgrep ...)
# and $(ps aux | wc -l), which collided with running PHP-FPM workers and
# triggered "fork: Resource temporarily unavailable". This version:
#   - caches process scans into variables instead of re-running pgrep
#   - avoids `ps aux | wc -l` entirely (uses /proc count)
#   - serializes work so no concurrent subshells

PHP="/usr/local/bin/ea-php82"
APP="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
WS_ID="26a36b56-4383-4edd-99d1-7622e7c191ef"
FT_SYMBOLS="ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT"
FT_STREAMS="trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h"

cd "$APP" || { echo "ERROR: cd $APP failed"; exit 1; }
mkdir -p storage/logs

# Resolve PHP fallback once
if [ ! -x "$PHP" ]; then
  if [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
    PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
  fi
fi

# Count single helper — uses pgrep -c (single fork) instead of pgrep | wc -l (two forks)
proc_count() {
  pgrep -fc "$1" 2>/dev/null || echo 0
}

# Cached counts — call once, reuse
QUEUE_N=$(proc_count "artisan queue:work")
FEED_N=$(proc_count "future-trade:live-feed")
TICK_N=$(proc_count "future-trade:tick")
STREAM_N=$(proc_count "future-trade:user-stream")
# /proc count avoids `ps aux | wc -l`
TOTAL_N=$(ls /proc 2>/dev/null | grep -c '^[0-9]' || echo "?")

echo "============================================"
echo " AutoFinX Master Check - $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"

echo ""
echo "=== 1. PHP & Process Health ==="
if [ -x "$PHP" ]; then
  echo "OK: $PHP"
  "$PHP" --version 2>/dev/null | head -1
else
  echo "CRITICAL: No PHP binary found"
fi
echo "Total system PIDs: $TOTAL_N"

echo ""
echo "=== 2. Worker Counts ==="
echo "queue:work    = $QUEUE_N (want: 1)"
echo "live-feed     = $FEED_N  (want: 1)"
echo "tick          = $TICK_N  (want: 0 or 1, transient)"
echo "user-stream   = $STREAM_N  (want: 0 or 1, optional)"

NEED_RESTART=0

if [ "$QUEUE_N" -gt 1 ]; then
  echo "DUPLICATE: $QUEUE_N queue:work workers — killing all"
  pkill -f "artisan queue:work" 2>/dev/null
  sleep 2
  NEED_RESTART=1
fi
if [ "$FEED_N" -gt 1 ]; then
  echo "DUPLICATE: $FEED_N live-feed processes — killing all"
  pkill -f "future-trade:live-feed" 2>/dev/null
  sleep 2
  NEED_RESTART=1
fi
[ "$QUEUE_N" -eq 0 ] && NEED_RESTART=1
[ "$FEED_N" -eq 0 ] && NEED_RESTART=1

echo ""
echo "=== 3. Start Missing Workers ==="
if [ "$NEED_RESTART" -eq 1 ]; then
  if ! pgrep -f "artisan queue:work" >/dev/null 2>&1; then
    echo "Starting queue:work..."
    nohup "$PHP" artisan queue:work \
      --queue=critical,execution,reconcile,monitoring,market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading,analytics,notifications,default,monitoring_safe \
      --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
      >> storage/logs/worker-all.log 2>&1 &
    sleep 2
  fi
  if ! pgrep -f "future-trade:live-feed" >/dev/null 2>&1; then
    echo "Starting live-feed..."
    nohup "$PHP" artisan future-trade:live-feed \
      --symbols="$FT_SYMBOLS" --streams="$FT_STREAMS" --reconnect-sleep=2 \
      >> storage/logs/future-trade-live-feed.log 2>> storage/logs/future-trade-live-feed.error.log &
    sleep 3
  fi
else
  echo "All processes OK — no restart"
fi

echo ""
echo "=== 4. Recent Live-Feed Log ==="
tail -n 3 storage/logs/future-trade-live-feed.log 2>/dev/null || echo "(no log yet)"

echo ""
echo "=== 5. Recent Worker Log ==="
tail -n 2 storage/logs/worker-all.log 2>/dev/null || echo "(no log yet)"

echo ""
echo "=== 6. Recent Laravel Errors (top 3) ==="
grep -E "SQLSTATE|\.ERROR:|CRITICAL" storage/logs/laravel.log 2>/dev/null \
  | grep -v "stty 2>" \
  | grep -v "Terminal\.php" \
  | tail -n 3 \
  || echo "No errors"

echo ""
echo "=== 7. Crontab Sanity ==="
crontab -l 2>/dev/null | grep -E "future-trade|autofinx|heartbeat|maintenance|schedule:run" \
  | head -6 \
  || echo "No AutoFinX cron entries"

echo ""
echo "=== 8. Slot Map (all 5 positions) ==="
"$PHP" artisan tinker --no-interaction --execute="
  try {
    \$slots = App\\Models\\FutureTradeBotConfig::where('workspace_id','$WS_ID')
      ->orderBy('slot_index')->get();
    if (\$slots->isEmpty()) { echo 'No slots found for workspace'.PHP_EOL; }
    \$open = 0;
    foreach (\$slots as \$s) {
      \$st = \$s->state;
      \$qty  = \$st ? round((float)(\$st->quantity ?? 0), 6) : 0;
      \$side = \$st ? strtoupper((string)(\$st->position_side ?? '-')) : '-';
      \$sym  = \$st ? strtoupper((string)(\$st->current_symbol ?? '-')) : '-';
      \$state= \$st ? (string)(\$st->state ?? 'IDLE') : 'NO_STATE';
      \$en   = \$s->enabled ? 'ON' : 'OFF';
      \$posOpen = \$qty > 0 || in_array(\$state, ['IN_POSITION','LIVE_IN_POSITION']);
      if (\$posOpen) \$open++;
      \$flag = \$posOpen ? '>>> OPEN' : '    flat';
      echo sprintf('Slot %d | %-3s | %-14s | sym=%-10s side=%-5s qty=%-12s state=%-18s %s'.PHP_EOL,
        (int)\$s->slot_index, \$en, '', \$sym, \$side, \$qty, \$state, \$flag);
    }
    echo PHP_EOL.'Active positions: '.\$open.'/'.count(\$slots).' (max 5)'.PHP_EOL;
  } catch (\\Throwable \$e) { echo 'Slot map error: '.\$e->getMessage().PHP_EOL; }
" 2>/dev/null

echo ""
echo "=== 9. Last 8 Webhook Events (all slots) ==="
# Query across all slots for the workspace — surfaces trade blocks with reason
"$PHP" artisan tinker --no-interaction --execute="
  try {
    \$ids = App\\Models\\FutureTradeBotConfig::where('workspace_id','$WS_ID')->pluck('id');
    \$rows = App\\Models\\FutureTradeBotLog::whereIn('future_trade_bot_config_id', \$ids)
      ->whereIn('event',['PINE_WEBHOOK_SIGNAL','LIVE_SELL_EXECUTED','LIVE_BUY_EXECUTED',
        'BUY_EXECUTED','SELL_EXECUTED','TRADE_BLOCKED','SIGNAL_SKIPPED',
        'PINE_WEBHOOK_REJECTED','WEBHOOK_RAW_RECEIVED','PINE_WEBHOOK_WAIT'])
      ->latest('occurred_at')->limit(8)
      ->get(['event','symbol','signal','reason','occurred_at']);
    foreach (\$rows as \$r) {
      \$tag = in_array(\$r->event, ['TRADE_BLOCKED','SIGNAL_SKIPPED']) ? '!! BLOCKED' : '   OK    ';
      echo \$r->occurred_at.' '.\$tag.' | '.str_pad(\$r->event, 26).' | '.str_pad((string)\$r->symbol,10).' | '.Str::limit((string)\$r->reason, 60).PHP_EOL;
    }
    if (\$rows->isEmpty()) echo 'No webhook events yet'.PHP_EOL;
  } catch (\\Throwable \$e) { echo 'DB error: '.\$e->getMessage().PHP_EOL; }
" 2>/dev/null

echo ""
echo "============================================"
echo " SUMMARY"
echo "============================================"
# Re-query AFTER possible restart
Q=$(proc_count "artisan queue:work")
F=$(proc_count "future-trade:live-feed")
echo "queue:work  = $Q $([ "$Q" -eq 1 ] && echo OK || echo ISSUE)"
echo "live-feed   = $F $([ "$F" -eq 1 ] && echo OK || echo ISSUE)"
echo ""
if [ "$Q" -eq 1 ] && [ "$F" -eq 1 ]; then
  echo "STATUS: ALL GOOD"
else
  echo "STATUS: Re-run after 30s if just restarted"
fi
echo "============================================"
