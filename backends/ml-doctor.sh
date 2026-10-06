#!/usr/bin/env bash
# One command that checks the whole ML training setup on the server and repairs
# what is safe to repair. Run as root:
#
#   bash /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/ml-doctor.sh
#   bash ml-doctor.sh --no-fix        report only
#
# Fixed automatically: file ownership under storage/ and bootstrap/cache, a stale
# config cache, orphan `runuser` wrappers, TRAINING rows whose trainer is gone,
# dead runs, orphaned training locks.
# NEVER done automatically: killing a running trainer, restarting services,
# editing .env or supervisor config, rebuilding features or history. Each of those
# is reported with the exact command to run.
#
# Exit code: 0 clean, 1 warnings, 2 failures.
set -uo pipefail

APP="${APP:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
APP_USER="${APP_USER:-zcadmin}"
FIX=1
[ "${1:-}" = "--no-fix" ] && FIX=0

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 2; }
cd "$APP" || { echo "Cannot cd to $APP"; exit 2; }

WARNS=0; FAILS=0; FIXES=0
say() { printf '  %-5s %s\n' "$1" "$2"; }
ok() { say OK "$*"; }
info() { say INFO "$*"; }
warn() { say WARN "$*"; WARNS=$((WARNS + 1)); }
fail() { say FAIL "$*"; FAILS=$((FAILS + 1)); }
fixed() { say FIXED "$*"; FIXES=$((FIXES + 1)); }
section() { printf '\n== %s\n' "$*"; }
artisan() { runuser -u "$APP_USER" -- "$PHP" artisan "$@"; }

echo "ML doctor @ $(date '+%F %T %Z') on $(hostname) ($([ "$FIX" = 1 ] && echo 'repairing safe things' || echo 'read-only'))"

section "Files and config cache"
bad=$(find storage bootstrap/cache -not -user "$APP_USER" 2>/dev/null | wc -l)
if [ "$bad" -gt 0 ]; then
  if [ "$FIX" = 1 ]; then chown -R "$APP_USER:$APP_USER" storage bootstrap/cache && fixed "$bad file(s) under storage/ and bootstrap/cache were not owned by $APP_USER (a root run of artisan/git); ownership repaired."
  else warn "$bad file(s) under storage/ and bootstrap/cache are not owned by $APP_USER; the app may fail to write logs/cache. Run without --no-fix."; fi
else ok "storage/ and bootstrap/cache are owned by $APP_USER."; fi
if [ -f .env ] && [ -f bootstrap/cache/config.php ] && [ .env -nt bootstrap/cache/config.php ]; then
  if [ "$FIX" = 1 ]; then artisan config:cache >/dev/null 2>&1 && fixed ".env was newer than the config cache: ran config:cache."
  else warn ".env is newer than the config cache: the running app still uses the OLD values (artisan config:cache)."; fi
else ok "The config cache is up to date with .env."; fi
pend=$(artisan migrate:status 2>/dev/null | grep -i "pending" || true)
if [ -n "$pend" ]; then warn "Pending migrations (not applied): $(echo "$pend" | awk '{print $1}' | tr '\n' ' ')"; else ok "No pending migrations."; fi

section "Services (supervisor)"
for prog in autofinx-ml-training autofinx-scheduler autofinx-ratio-feed autofinx-queue-ratio autofinx-trading:live-feed; do
  line=$(supervisorctl status "$prog" 2>&1 | head -1)
  if echo "$line" | grep -q RUNNING; then
    up=$(echo "$line" | sed -E 's/.*uptime //')
    if echo "$up" | grep -q day; then secs=86400
    else secs=$(echo "$up" | awk -F: '{ if (NF==3) print $1*3600+$2*60+$3; else print 0 }'); fi
    if [ "${secs:-0}" -lt 60 ]; then warn "$prog is RUNNING but only for ${up}: if it keeps restarting it is crash-looping (supervisorctl tail $prog stderr)."; else ok "$prog RUNNING (uptime $up)."; fi
  else fail "$prog is not running: $line"; fi
done
conf=$(grep -rl "program:autofinx-ml-training" /etc/supervisord.d /etc/supervisord.conf /etc/supervisor/conf.d 2>/dev/null | head -1)
if [ -n "$conf" ]; then
  if sed -n '/program:autofinx-ml-training/,/^\[/p' "$conf" | grep -qE 'stopasgroup *= *true'; then ok "autofinx-ml-training stops its python child with it (stopasgroup)."
  else warn "autofinx-ml-training has no stopasgroup=true / killasgroup=true in $conf: restarting the worker leaves its python trainer running as an orphan. Add both lines, then supervisorctl update (do it when no model is training)."; fi
fi

section "Training processes"
if pgrep -f 'ml-train-all.sh' >/dev/null; then warn "The old /root/ml-train-all.sh is still running; it starts the next model the moment one ends. Stop it: pkill -f ml-train-all.sh (the running model finishes)."; else ok "The old ml-train-all.sh is not running."; fi
if pgrep -f 'ml-train-batch.sh' >/dev/null; then
  ok "ml-train-batch.sh is running. $(wc -l < /root/.ml-batch-done 2>/dev/null || echo 0) model(s) marked done so far."
  [ -e /root/ml-batch.stop ] && info "A stop file exists: the batch ends after the current model."
else info "No batch is running. Start one: bash ml-train-batch.sh 1   (list: bash ml-train-batch.sh list)"; fi
[ -f /root/ml-batch.out ] && { info "Last batch log lines:"; tail -n 4 /root/ml-batch.out | sed 's/^/          /'; }
orphans=0
while read -r pid ppid; do
  [ -z "$pid" ] && continue
  if [ "$ppid" = 1 ] && [ -z "$(pgrep -P "$pid")" ]; then
    orphans=$((orphans + 1))
    if [ "$FIX" = 1 ]; then kill "$pid" 2>/dev/null && fixed "Stopped idle runuser wrapper $pid (its trainer already ended)."; else warn "Idle runuser wrapper $pid has no child."; fi
  fi
done < <(ps -eo pid=,ppid=,args= | awk '/runuser -u .* -- bash -c cd .*\$0/ && !/awk/ {print $1, $2}')
[ "$orphans" = 0 ] && ok "No idle runuser wrappers."

section "Host resources"
cores=$(nproc); read -r l1 l5 l15 _ < /proc/loadavg
mem_avail=$(awk '/MemAvailable/{printf "%d", $2/1024}' /proc/meminfo); mem_total=$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)
swap_total=$(awk '/SwapTotal/{printf "%d", $2/1024}' /proc/meminfo); swap_free=$(awk '/SwapFree/{printf "%d", $2/1024}' /proc/meminfo)
swap_pct=0; [ "$swap_total" -gt 0 ] && swap_pct=$(( (swap_total - swap_free) * 100 / swap_total ))
disk_pct=$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')
info "load $l1 $l5 $l15 on $cores cores; RAM available ${mem_avail}/${mem_total} MB; swap used ${swap_pct}% of ${swap_total} MB; disk / ${disk_pct}% used."
load_pc=$(awk -v l="$l5" -v c="$cores" 'BEGIN{printf "%.1f", l/c}')
if awk -v v="$load_pc" 'BEGIN{exit !(v>=3)}'; then fail "Load is ${load_pc} per core (5 min): the host is overloaded, everything including training crawls."
elif awk -v v="$load_pc" 'BEGIN{exit !(v>=1.5)}'; then warn "Load is ${load_pc} per core (5 min)."; else ok "Load is ${load_pc} per core."; fi
if [ "$swap_pct" -ge 85 ]; then fail "Swap is ${swap_pct}% used: the system is memory-starved, a model that takes minutes on a healthy host takes hours here."
elif [ "$swap_pct" -ge 60 ]; then warn "Swap is ${swap_pct}% used."; else ok "Swap is ${swap_pct}% used."; fi
[ "$mem_avail" -lt 800 ] && warn "Only ${mem_avail} MB RAM is available."
[ "$disk_pct" -ge 92 ] && fail "Disk is ${disk_pct}% full: logs, MySQL and model artifacts will start failing." || { [ "$disk_pct" -ge 85 ] && warn "Disk is ${disk_pct}% full."; }
info "Biggest memory users:"; ps -eo pid,pmem,rss,etime,comm,args --sort=-rss | awk 'NR>1 && NR<=7 {printf "          pid %-8s %5s%%  %6d MB  up %-12s %s\n", $1, $2, $3/1024, $4, substr($0, index($0,$6), 70)}'
oom=$(dmesg -T 2>/dev/null | grep -iE "out of memory|killed process" | tail -n 3)
if [ -n "$oom" ]; then warn "The kernel killed processes for lack of memory recently:"; echo "$oom" | cut -c1-170 | sed 's/^/          /'; else ok "No out-of-memory kills in the kernel log."; fi

section "Errors in laravel.log (queue worker and ML)"
LOG="storage/logs/laravel.log"
if [ -f "$LOG" ]; then
  worker=$(tail -n 6000 "$LOG" | awk '/^\[20[0-9-]+ [0-9:]+\] [a-z.]+\.(ERROR|CRITICAL)/ {hdr=$0; printed=0} /ml_training|DatabaseQueue|Queue.Worker/ { if (hdr != "" && !printed) { print substr(hdr, 1, 280); printed=1 } }' | tail -n 3)
  if [ -n "$worker" ]; then warn "The ml_training queue worker hit errors (a worker that cannot poll never starts dashboard runs):"; echo "$worker" | sed 's/^/          /'
  else ok "No ml_training queue worker errors in the last 6000 log lines."; fi
  mlerr=$(tail -n 6000 "$LOG" | grep -cE "production\.(ERROR|CRITICAL).*(MlShadow|ml\.shadow|TrainingRun|ml_training)" || true)
  [ "${mlerr:-0}" -gt 0 ] && warn "$mlerr ML error line(s) in the recent log: grep -E 'MlShadow|TrainingRun' $LOG | tail" || ok "No ML training errors in the recent log."
else info "No laravel.log yet."; fi

section "ML pipeline (artisan ml:doctor)"
if [ "$FIX" = 1 ]; then artisan ml:doctor --fix; else artisan ml:doctor; fi
RC=$?
[ "$RC" -ge 2 ] && FAILS=$((FAILS + 1)); [ "$RC" -eq 1 ] && WARNS=$((WARNS + 1))

section "Verdict"
echo "  $FIXES repaired, $WARNS warning group(s), $FAILS failure group(s)."
if [ "$FAILS" -gt 0 ]; then echo "  Fix the FAIL lines first (host overload and a disabled/unreadable queue make training unreliable)."; EXIT=2
elif [ "$WARNS" -gt 0 ]; then echo "  Nothing is broken; the WARN lines above are what to look at, each with its next step."; EXIT=1
else echo "  Everything checks out."; EXIT=0; fi
echo "  Dashboard: ML Training > Training Runs. Re-run this script any time; it is safe and repeatable."
exit "$EXIT"
