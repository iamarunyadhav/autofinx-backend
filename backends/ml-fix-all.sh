#!/usr/bin/env bash
# ONE command: gets the server and the ML training into a healthy, running state, step by step.
#
#   bash ml-fix-all.sh
#
# Steps: 1 pull code | 2 free disk (safe items only) | 3 permissions + config cache + migrations check
#        4 ratio worker | 5 ML worker + supervisor stopasgroup | 6 host health | 7 doctor | 8 start 5-year batch
# Safe by design: never kills a running trainer, never edits .env or the database, never restarts MariaDB.
# A step that cannot be done safely right now is SKIPPED and the exact reason + next command is printed.
# Re-run it any time (it is repeatable). Run as root.
set -uo pipefail

APP="${APP:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
APP_USER="${APP_USER:-zcadmin}"
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 2; }
cd "$APP" || { echo "Cannot cd to $APP"; exit 2; }
artisan() { runuser -u "$APP_USER" -- "$PHP" artisan "$@"; }
TODO=()
step() { printf '\n\033[1m=== STEP %s ===\033[0m\n' "$*"; }
ok() { echo "  OK    $*"; }
info() { echo "  INFO  $*"; }
warn() { echo "  WARN  $*"; }
todo() { TODO+=("$*"); echo "  TODO  $*"; }
trainer_running() { pgrep -f 'artisan ml:train|train_side_classifier' >/dev/null || artisan ml:training:busy >/dev/null 2>&1; }

step "1/8  Pull the latest code"
if runuser -u "$APP_USER" -- git -C "$APP" pull --ff-only 2>&1 | tail -3; then ok "git pull finished (see above)."; else warn "git pull failed; continuing with the code already on the server."; fi

step "2/8  Free disk space (safe items only)"
bash "$APP/disk-doctor.sh" --clean 2>&1 | sed -n '/== Files over/,$p'
disk_pct=$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')
if [ "$disk_pct" -ge 92 ]; then todo "Disk is still ${disk_pct}% full. Look at the 'Files over 300 MB' / 'Top folders' lines above; delete old backups or MariaDB binlogs you do not need, then re-run this script."; else ok "Disk ${disk_pct}% used."; fi

step "3/8  Permissions, config cache, migrations"
chown -R "$APP_USER:$APP_USER" storage bootstrap/cache 2>/dev/null && ok "storage/ and bootstrap/cache owned by $APP_USER."
artisan config:cache >/dev/null 2>&1 && ok "Config cache rebuilt."
pend=$(artisan migrate:status 2>/dev/null | grep -ci pending || true)
if [ "${pend:-0}" -gt 0 ]; then todo "$pend migration(s) pending. Review, then run: runuser -u $APP_USER -- $PHP artisan migrate --force"; else ok "No pending migrations."; fi

step "4/8  Ratio bot worker"
if supervisorctl status autofinx-queue-ratio 2>&1 | grep -q RUNNING; then ok "autofinx-queue-ratio is running."; else supervisorctl start autofinx-queue-ratio; fi
todo "Open the app > Ratio Bot and press START on the bots (reset-history leaves them stopped). Then: runuser -u $APP_USER -- $PHP artisan ratio-bots:verify --exchange"

step "5/8  ML queue worker + supervisor stopasgroup"
conf=$(grep -rl "program:autofinx-ml-training" /etc/supervisord.d /etc/supervisord.conf /etc/supervisor/conf.d 2>/dev/null | head -1)
if trainer_running; then
  info "A model is training right now: the ML worker and its supervisor config are left untouched."
  if [ -n "$conf" ] && ! sed -n '/program:autofinx-ml-training/,/^\[/p' "$conf" | grep -qE 'stopasgroup *= *true'; then
    todo "When the running model has finished, re-run this script: it will add stopasgroup/killasgroup to $conf and restart the worker."
  fi
else
  if [ -n "$conf" ] && ! sed -n '/program:autofinx-ml-training/,/^\[/p' "$conf" | grep -qE 'stopasgroup *= *true'; then
    cp "$conf" "${conf}.bak.$(date +%s)"
    sed -i '/^\[program:autofinx-ml-training\]/a stopasgroup=true\nkillasgroup=true' "$conf" && ok "Added stopasgroup/killasgroup to $conf (backup kept)." && supervisorctl update >/dev/null 2>&1
  else ok "Supervisor config already stops the python child with the worker."; fi
  if supervisorctl status autofinx-ml-training 2>&1 | grep -q RUNNING; then ok "autofinx-ml-training is running."; else supervisorctl restart autofinx-ml-training; sleep 3; supervisorctl status autofinx-ml-training; fi
fi

step "6/8  Host health (decides whether training may start)"
cores=$(nproc); read -r _ l5 _ < /proc/loadavg
swap_total=$(awk '/SwapTotal/{print $2}' /proc/meminfo); swap_free=$(awk '/SwapFree/{print $2}' /proc/meminfo)
swap_pct=0; [ "$swap_total" -gt 0 ] && swap_pct=$(( (swap_total - swap_free) * 100 / swap_total ))
load_pc=$(awk -v l="$l5" -v c="$cores" 'BEGIN{printf "%.1f", l/c}')
info "load/core ${load_pc}, swap ${swap_pct}%, disk ${disk_pct}%"
HEALTHY=1
awk -v v="$load_pc" 'BEGIN{exit !(v>=3)}' && { HEALTHY=0; warn "Load per core is ${load_pc} (limit 3)."; }
[ "$swap_pct" -ge 85 ] && { HEALTHY=0; warn "Swap ${swap_pct}% used (limit 85)."; }
[ "$disk_pct" -ge 92 ] && { HEALTHY=0; warn "Disk ${disk_pct}% full (limit 92)."; }
if [ "$HEALTHY" = 0 ]; then
  mdb=$(ps -C mariadbd -o rss= 2>/dev/null | awk '{printf "%d", $1/1024}')
  todo "Host is overloaded. Biggest cause: MariaDB uses ${mdb:-?} MB. Ask the hosting admin (or schedule a quiet window) to lower innodb_buffer_pool_size (e.g. 1G) and restart MariaDB; this script never restarts the database. Also pause n8n / extra sites while training."
fi

step "7/8  ML doctor"
bash "$APP/ml-doctor.sh" 2>&1 | sed -n '/== Verdict/,$p'

step "8/8  Start the 5-year batch (one model at a time, background)"
if trainer_running || pgrep -f 'ml-train-batch.sh' >/dev/null; then
  ok "Training/batch is already running; nothing to start. It continues by itself through the whole batch."
  info "Watch it: tail -f /root/ml-batch.out   |   app > ML Training > Training Runs"
elif [ "$HEALTHY" = 0 ]; then
  warn "Not starting training: the host is unhealthy (see step 6). Starting now would only make it crawl."
  todo "After fixing step 6, re-run this script; it starts the batch automatically."
else
  bash "$APP/ml-train-batch.sh" 1 --takeover && ok "Batch 1 started in the background (240m + 1440m for ETH BNB SOL XRP SUI). Full history, one model at a time."
  info "Watch it: tail -f /root/ml-batch.out   |   app > ML Training > Training Runs"
  todo "When batch 1 finishes and you are happy with the verdicts: bash ml-train-batch.sh 2   (then 3)"
fi

printf '\n\033[1m=== SUMMARY ===\033[0m\n'
if [ "${#TODO[@]}" -eq 0 ]; then echo "  Everything is in order."; else echo "  Remaining actions for you:"; i=1; for t in "${TODO[@]}"; do echo "   $i. $t"; i=$((i + 1)); done; fi
echo "  Re-run any time: bash ml-fix-all.sh"
