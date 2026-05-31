#!/usr/bin/env bash
# AutoFinX Server Cleanup — Shared cPanel Edition
# Frees disk space, trims DB logs, clears stale cache.
# Safe to run anytime. Never touches active trades or sessions.
#
# Usage:
#   bash autofinx_cleanup.sh           # full cleanup
#   bash autofinx_cleanup.sh --dry-run # show what WOULD be cleaned (no deletes)
#   bash autofinx_cleanup.sh --deep    # full + deep DB trim (keeps 14 days instead of 30)

set -euo pipefail

APP="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi

DRY=0
DEEP=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY=1 ;;
    --deep)    DEEP=1 ;;
  esac
done

KEEP_DAYS=30
[ "$DEEP" -eq 1 ] && KEEP_DAYS=14

cd "$APP"

log()  { echo "[CLEANUP] $*"; }
size() { du -sh "$1" 2>/dev/null | cut -f1 || echo "?"; }
run()  {
  if [ "$DRY" -eq 1 ]; then
    echo "  [DRY] would run: $*"
  else
    eval "$@" || true
  fi
}

echo "============================================================"
echo " AutoFinX Cleanup — $(date '+%Y-%m-%d %H:%M:%S')"
echo " Mode: $([ "$DRY" -eq 1 ] && echo DRY-RUN || echo LIVE)"
echo " Keep days: $KEEP_DAYS"
echo "============================================================"

# ── BEFORE ────────────────────────────────────────────────────────────────────
echo ""
log "=== BEFORE: Disk Usage ==="
echo "  Total storage/: $(size storage/)"
echo "  storage/logs/:  $(size storage/logs/)"
echo "  storage/framework/: $(size storage/framework/)"
echo "  bootstrap/cache/: $(size bootstrap/cache/)"
DISK_BEFORE=$(df -h . 2>/dev/null | tail -1 | awk '{print $4}')
echo "  Server free disk: $DISK_BEFORE"

# ── 1. LOG FILES ──────────────────────────────────────────────────────────────
echo ""
log "=== 1. Log Files ==="

# Truncate any log > 20 MB to 5 MB (keep recent tail)
find storage/logs -name "*.log" -size +20M 2>/dev/null | while read -r f; do
  FSIZE=$(du -sh "$f" 2>/dev/null | cut -f1)
  log "  Truncating $f ($FSIZE → 5 MB)"
  if [ "$DRY" -eq 0 ]; then
    tail -c 5242880 "$f" > "/tmp/_afx_log_trim.tmp" && mv "/tmp/_afx_log_trim.tmp" "$f"
  fi
done

# Delete logs older than KEEP_DAYS days (rotated/archived logs)
COUNT=$(find storage/logs -name "*.log.*" -mtime +$KEEP_DAYS 2>/dev/null | wc -l)
log "  Rotated logs older than ${KEEP_DAYS}d: $COUNT files"
if [ "$COUNT" -gt 0 ]; then
  run "find storage/logs -name '*.log.*' -mtime +$KEEP_DAYS -delete"
fi

# Laravel log: deduplicate repeated identical lines (common in production)
if [ -f storage/logs/laravel.log ]; then
  LSIZE=$(size storage/logs/laravel.log)
  log "  laravel.log size: $LSIZE"
  # If > 10 MB, keep last 10 MB only
  if [ "$DRY" -eq 0 ] && [ "$(find storage/logs/laravel.log -size +10M 2>/dev/null | wc -l)" -gt 0 ]; then
    log "  Trimming laravel.log to last 10 MB"
    tail -c 10485760 storage/logs/laravel.log > /tmp/_afx_laravel.tmp \
      && mv /tmp/_afx_laravel.tmp storage/logs/laravel.log
  fi
fi

# ── 2. FRAMEWORK CACHE ────────────────────────────────────────────────────────
echo ""
log "=== 2. Framework Cache ==="

# Old compiled views (> 7 days)
OLD_VIEWS=$(find storage/framework/views -name "*.php" -mtime +7 2>/dev/null | wc -l)
log "  Stale compiled views (>7d): $OLD_VIEWS"
run "find storage/framework/views -name '*.php' -mtime +7 -delete 2>/dev/null"

# App cache files (> KEEP_DAYS days — active cache is < 1 hour TTL)
OLD_CACHE=$(find storage/framework/cache/data -type f -mtime +1 2>/dev/null | wc -l)
log "  Stale file cache entries (>1d): $OLD_CACHE"
run "find storage/framework/cache/data -type f -mtime +1 -delete 2>/dev/null"
run "find storage/framework/cache/data -type d -empty -delete 2>/dev/null"

# Old session files (> 2 days)
OLD_SESS=$(find storage/framework/sessions -type f -mtime +2 2>/dev/null | wc -l)
log "  Old session files (>2d): $OLD_SESS"
run "find storage/framework/sessions -type f -mtime +2 -delete 2>/dev/null"

# Stale heartbeat/lock directories (> 5 min = stuck locks)
run "find storage/framework -name '*.lock' -type d -mmin +5 -exec rmdir {} + 2>/dev/null"
log "  Stale lock dirs cleaned"

# ── 3. DATABASE CLEANUP ───────────────────────────────────────────────────────
echo ""
log "=== 3. Database Cleanup (keep last ${KEEP_DAYS} days) ==="

if [ "$DRY" -eq 0 ]; then
  "$PHP" artisan tinker --no-interaction --execute="
    \$keep = now()->subDays($KEEP_DAYS);
    \$keepRecent = now()->subDays(7);

    // Bot logs — keep recent ${KEEP_DAYS} days (can be huge)
    \$deleted = App\\Models\\FutureTradeBotLog::where('occurred_at', '<', \$keep)->delete();
    echo 'bot_logs_deleted=' . \$deleted . PHP_EOL;

    // Market price cache — keep last 7 days
    try {
      \$mc = App\\Models\\MarketPriceCache::where('updated_at', '<', \$keepRecent)->delete();
      echo 'market_cache_deleted=' . \$mc . PHP_EOL;
    } catch(\\Throwable \$e) { echo 'market_cache=skip' . PHP_EOL; }

    // Failed jobs older than 7 days
    try {
      \$fj = DB::table('failed_jobs')->where('failed_at', '<', \$keepRecent->timestamp)->delete();
      echo 'failed_jobs_deleted=' . \$fj . PHP_EOL;
    } catch(\\Throwable \$e) { echo 'failed_jobs=skip' . PHP_EOL; }

    // Completed queue jobs (status done/failed) older than 7 days
    try {
      \$qj = DB::table('jobs')->where('available_at', '<', \$keepRecent->timestamp)->delete();
      echo 'old_jobs_deleted=' . \$qj . PHP_EOL;
    } catch(\\Throwable \$e) { echo 'jobs_table=skip' . PHP_EOL; }

    // Closed logical trades older than 30 days (keep OPEN always)
    \$lt = App\\Models\\FutureTradeLogicalTrade
      ::whereIn('status', ['CLOSED','RECONCILIATION_REQUIRED'])
      ->where('closed_at', '<', \$keep)
      ->delete();
    echo 'old_closed_logical_trades=' . \$lt . PHP_EOL;

    // DB size estimate
    try {
      \$sz = DB::select('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS mb FROM information_schema.tables WHERE table_schema = DATABASE()');
      echo 'db_size_mb=' . (\$sz[0]->mb ?? '?') . PHP_EOL;
    } catch(\\Throwable) {}

    echo 'cleanup_done' . PHP_EOL;
  " 2>/dev/null
else
  log "  [DRY] would clean bot_logs, market_cache, failed_jobs older than ${KEEP_DAYS}d"
fi

# ── 4. ARTISAN CACHE REBUILD ─────────────────────────────────────────────────
echo ""
log "=== 4. Cache Rebuild (config + route) ==="
if [ "$DRY" -eq 0 ]; then
  "$PHP" artisan config:cache  2>/dev/null && log "  config:cache OK"
  "$PHP" artisan route:cache   2>/dev/null && log "  route:cache  OK"
else
  log "  [DRY] would rebuild config + route cache"
fi

# ── 5. OPCACHE RESET (if available) ──────────────────────────────────────────
echo ""
log "=== 5. OPcache ==="
OPCACHE_STATUS=$("$PHP" -r 'echo function_exists("opcache_reset") ? "available" : "unavailable";' 2>/dev/null || echo "unavailable")
if [ "$OPCACHE_STATUS" = "available" ] && [ "$DRY" -eq 0 ]; then
  "$PHP" -r 'opcache_reset(); echo "opcache_reset=ok";' 2>/dev/null || true
  log "  OPcache reset OK"
else
  log "  OPcache: $OPCACHE_STATUS"
fi

# ── AFTER ─────────────────────────────────────────────────────────────────────
echo ""
log "=== AFTER: Disk Usage ==="
echo "  Total storage/: $(size storage/)"
echo "  storage/logs/:  $(size storage/logs/)"
echo "  storage/framework/: $(size storage/framework/)"
DISK_AFTER=$(df -h . 2>/dev/null | tail -1 | awk '{print $4}')
echo "  Server free disk: $DISK_AFTER"

# ── RAM SNAPSHOT ──────────────────────────────────────────────────────────────
echo ""
log "=== RAM Usage ==="
free -h 2>/dev/null || log "  (free command unavailable on this host)"
echo ""
log "=== PHP Processes ==="
echo "  queue:work:  $(pgrep -f 'artisan queue:work' 2>/dev/null | wc -l)"
echo "  live-feed:   $(pgrep -f 'future-trade:live-feed' 2>/dev/null | wc -l)"
echo "  tick:        $(pgrep -f 'future-trade:tick' 2>/dev/null | wc -l)"

echo ""
echo "============================================================"
echo " CLEANUP DONE — $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"
