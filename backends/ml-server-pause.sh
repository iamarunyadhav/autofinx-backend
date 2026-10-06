#!/usr/bin/env bash
# Pause ALL ML shadow work on the production server (training moved to the owner's PC: see
# docs/ml-engine-v1, D-001/D-002). Trading is not affected: only the ML dashboard/jobs read ML tables.
#
#   bash ml-server-pause.sh                     report only (changes nothing)
#   bash ml-server-pause.sh --apply             pause ML (flag off, ML worker off, batch stopped, temp files removed)
#   bash ml-server-pause.sh --apply --free-disk  ALSO empty the big ML tables (~16 GB): ml_feature_rows,
#                                                ml_labels, ml_shadow_predictions. Irreversible, but every row
#                                                can be rebuilt from Binance candles. Candles/models/runs are kept.
# Undo (later, for V4 shadow): set AUTOFINX_ML_SHADOW_ENABLED=true, config:cache, supervisorctl start autofinx-ml-training.
set -uo pipefail
APP="${APP:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
APPLY=0; FREE=0
for a in "$@"; do case "$a" in --apply) APPLY=1 ;; --free-disk) FREE=1 ;; *) echo "Unknown option $a"; exit 2 ;; esac; done
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 2; }
cd "$APP" || exit 2
artisan() { runuser -u zcadmin -- "$PHP" artisan "$@"; }
do_() { if [ "$APPLY" = 1 ]; then echo "  DO    $*"; eval "$@"; else echo "  WOULD $*"; fi; }
echo "ML server pause @ $(date '+%F %T %Z') ($([ "$APPLY" = 1 ] && echo APPLY || echo 'report only'))"

echo; echo "== 1. Training in progress?"
busy=$(artisan ml:training:busy 2>/dev/null); echo "  $busy"
if echo "$busy" | grep -q '^busy'; then
  echo "  A model is training right now. Re-run this script when it has finished (or stop it from the dashboard)."
  [ "$APPLY" = 1 ] && exit 1
fi

echo; echo "== 2. Batch scripts"
for p in ml-train-batch.sh ml-train-all.sh; do
  if pgrep -f "$p" >/dev/null; then do_ "pkill -f $p"; else echo "  OK    $p not running"; fi
done
[ -f /root/ml-batch.stop ] || do_ "touch /root/ml-batch.stop"

echo; echo "== 3. ML scheduler flag (AUTOFINX_ML_SHADOW_ENABLED)"
grep -n '^AUTOFINX_ML_SHADOW_ENABLED=' .env | sed 's/^/  now: /' || echo "  now: (not set, defaults to false)"
if grep -q '^AUTOFINX_ML_SHADOW_ENABLED=true' .env; then
  do_ "cp -p .env .env.bak-ml-pause-$(date +%Y%m%d%H%M%S)"
  do_ "sed -i 's/^AUTOFINX_ML_SHADOW_ENABLED=.*/AUTOFINX_ML_SHADOW_ENABLED=false/' .env"
  do_ "runuser -u zcadmin -- $PHP artisan config:cache >/dev/null"
  do_ "supervisorctl restart autofinx-scheduler >/dev/null"
else
  echo "  OK    already off"
fi

echo; echo "== 4. ML training queue worker"
supervisorctl status autofinx-ml-training 2>&1 | sed 's/^/  /'
if supervisorctl status autofinx-ml-training 2>&1 | grep -qE 'RUNNING|STARTING|BACKOFF'; then do_ "supervisorctl stop autofinx-ml-training"; fi
conf=$(grep -rl "program:autofinx-ml-training" /etc/supervisord.d /etc/supervisor/conf.d 2>/dev/null | head -1)
if [ -n "$conf" ] && ! grep -q '^autostart=false' "$conf"; then
  do_ "cp -p $conf $conf.bak-ml-pause && sed -i 's/^autostart=.*/autostart=false/' $conf && supervisorctl update >/dev/null"
fi

echo; echo "== 5. Leftover ML files"
for d in storage/app/ml-shadow/exports /home/zcadmin/ml-offload; do
  [ -d "$d" ] && { echo "  $(du -sh "$d" 2>/dev/null | cut -f1)  $d"; do_ "rm -rf $d/*"; }
done

echo; echo "== 6. ML table sizes"
DB=$(mysql -N -e "select table_schema from information_schema.tables where table_name='ml_feature_rows' limit 1")
mysql -N -e "select table_name, round((data_length+index_length)/1048576) from information_schema.tables where table_schema='$DB' and table_name like 'ml\_%' order by 2 desc limit 8" | awk '{printf "  %6s MB  %s\n", $2, $1}'
if [ "$FREE" = 1 ]; then
  for t in ml_feature_rows ml_labels ml_shadow_predictions; do do_ "mysql $DB -e 'TRUNCATE TABLE $t'"; done
else
  echo "  (tables kept; add --free-disk to empty ml_feature_rows, ml_labels, ml_shadow_predictions)"
fi

echo; echo "== Result"; df -h / | tail -1 | awk '{print "  disk / " $5 " used, " $4 " free"}'; uptime | sed 's/^/  /'
[ "$APPLY" = 0 ] && echo "  Nothing changed. Re-run with --apply (and optionally --free-disk)."
