#!/usr/bin/env bash
# Train the ML shadow models in prioritised BATCHES, one model at a time, at the
# lowest CPU/IO priority, so a batch can be judged before the next is started.
#
#   bash ml-train-batch.sh list                 show the plan and what is done
#   bash ml-train-batch.sh 1 --takeover         batch 1, taking over from /root/ml-train-all.sh
#   bash ml-train-batch.sh 2                    next batch
#   bash ml-train-batch.sh 3
#   bash ml-train-batch.sh all
#   touch /root/ml-batch.stop                   finish the current model, then stop (never kill a model mid-run)
#
# Batches (BTC is already trained or running in the old script):
#   1  horizons 240m + 1440m for ETH BNB SOL XRP SUI   (long holds: what the spot Ratio Bot would use)
#   2  horizons 120m + 60m
#   3  horizon 15m                                      (display-only in the UI)
#
# Options: --takeover  stop /root/ml-train-all.sh (its running model is allowed to finish)
#          --fg        run in the foreground (default: background, survives closing the terminal)
#          --dry-run   print what would run
# Environment: COINS="ETHUSDT ..."  QUIESCE="supervisor-program ..." (stopped during the batch, restarted after;
#              default none: only list programs you know are safe to pause)
#
# Each model is skipped if it is already in /root/.ml-batch-done (delete a line to retrain it).
# Output: storage/logs/ml-batch-<time>.log and /root/ml-batch.out
set -uo pipefail

APP="${APP:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
APP_USER="${APP_USER:-zcadmin}"
STATE="${STATE:-/root/.ml-batch-done}"
STOP_FILE="${STOP_FILE:-/root/ml-batch.stop}"
OUT="${OUT:-/root/ml-batch.out}"
COINS="${COINS:-ETHUSDT BNBUSDT SOLUSDT XRPUSDT SUIUSDT}"
QUIESCE="${QUIESCE:-}"

WHICH=""; TAKEOVER=0; FG=0; DRY=0
for arg in "$@"; do
  case "$arg" in
    --takeover) TAKEOVER=1 ;;
    --fg) FG=1 ;;
    --dry-run) DRY=1 ;;
    list|1|2|3|all) WHICH="$arg" ;;
    *) echo "Unknown argument: $arg"; sed -n 2,22p "$0"; exit 2 ;;
  esac
done
[ -n "$WHICH" ] || { sed -n 2,22p "$0"; exit 2; }

plan() {  # prints "SYMBOL HORIZON" lines for a batch
  local batch="$1" coin h
  local horizons
  case "$batch" in
    1) horizons="240 1440" ;;
    2) horizons="120 60" ;;
    3) horizons="15" ;;
    all) for b in 1 2 3; do plan "$b"; done; return ;;
  esac
  for coin in $COINS; do for h in $horizons; do echo "$coin $h"; done; done
}
is_done() { [ -f "$STATE" ] && grep -qx "$1:$2" "$STATE"; }

if [ "$WHICH" = list ]; then
  for b in 1 2 3; do
    echo "== batch $b"
    plan "$b" | while read -r s h; do printf '  %-9s %5sm  %s\n' "$s" "$h" "$(is_done "$s" "$h" && echo 'done' || echo 'pending')"; done
  done
  exit 0
fi

if [ "$DRY" = 1 ]; then
  echo "[dry-run] would train, in this order (skipping done):"
  plan "$WHICH" | while read -r s h; do
    if is_done "$s" "$h"; then echo "  skip  $s ${h}m (done)"; else echo "  nice -n 15 ionice -c3 runuser -u $APP_USER -- $PHP -d memory_limit=2048M artisan ml:train $s $h --ignore-eligibility"; fi
  done
  exit 0
fi

[ "$(id -u)" -eq 0 ] || { echo "Run as root (it switches to $APP_USER for artisan)."; exit 1; }

# Detach so closing the terminal or an SSH drop does not kill a multi-hour run.
if [ "$FG" = 0 ] && [ -z "${ML_BATCH_CHILD:-}" ]; then
  ML_BATCH_CHILD=1 setsid nohup bash "$0" "$@" >>"$OUT" 2>&1 < /dev/null &
  echo "Started in the background (pid $!). Watch it:  tail -f $OUT"
  echo "Graceful stop after the current model:  touch $STOP_FILE"
  exit 0
fi

exec 9>/tmp/ml-train-batch.lock
flock -n 9 || { echo "Another batch is already running (lock /tmp/ml-train-batch.lock)."; exit 1; }
rm -f "$STOP_FILE"
LOG="$APP/storage/logs/ml-batch-$(date +%Y%m%d-%H%M%S).log"
: > "$LOG"; chown "$APP_USER:$APP_USER" "$LOG" 2>/dev/null || true
log() { echo "$(date '+%F %T') $*" | tee -a "$LOG"; }

trainers_running() { pgrep -f 'artisan ml:train [A-Z]' >/dev/null; }

log "batch $WHICH start | load: $(cut -d' ' -f1-3 /proc/loadavg) | $(free -m | awk '/^Mem:/{printf "RAM avail %sMB", $7} /^Swap:/{printf ", swap used %s/%sMB", $3, $2}')"

if pgrep -f 'ml-train-all.sh' >/dev/null; then
  if [ "$TAKEOVER" = 1 ]; then
    # Kills only the bash wrappers; the model they are running is a separate process and finishes.
    pkill -f 'ml-train-all.sh'
    log "stopped /root/ml-train-all.sh; its current model (if any) is left to finish"
  else
    log "ml-train-all.sh is still running and would start its next model the moment one ends. Re-run with --takeover."
    exit 1
  fi
fi

if [ -n "$QUIESCE" ]; then
  log "pausing for the batch: $QUIESCE"
  # shellcheck disable=SC2086
  supervisorctl stop $QUIESCE | tee -a "$LOG"
  # shellcheck disable=SC2086
  trap 'log "restarting: $QUIESCE"; supervisorctl start $QUIESCE | tee -a "$LOG"' EXIT
fi

PRE=(nice -n 15)
command -v ionice >/dev/null 2>&1 && PRE+=(ionice -c3)

PAIRS=""; total=0; done_n=0; spent=0
while read -r sym h; do PAIRS="$PAIRS $sym:$h"; total=$((total + 1)); done < <(plan "$WHICH")

while read -r sym h; do
  if [ -e "$STOP_FILE" ]; then log "stop file found: stopping before $sym ${h}m"; break; fi
  if is_done "$sym" "$h"; then log "skip $sym ${h}m (already done)"; continue; fi

  while trainers_running; do
    [ -e "$STOP_FILE" ] && break
    log "another ml:train is running; waiting (one model at a time)"
    sleep 120
  done
  [ -e "$STOP_FILE" ] && { log "stop file found: stopping before $sym ${h}m"; break; }

  started=$(date +%s)
  log "training $sym ${h}m ..."
  tmp="$(mktemp)"
  "${PRE[@]}" runuser -u "$APP_USER" -- bash -c 'cd "$0" && exec "$@"' "$APP" \
    "$PHP" -d memory_limit=2048M artisan ml:train "$sym" "$h" --ignore-eligibility >"$tmp" 2>&1 < /dev/null
  rc=$?
  cat "$tmp" >> "$LOG"
  took=$(( $(date +%s) - started ))
  verdict="$(grep -m1 -E "h${h} (side_classifier|regression)|training failed|Not eligible" "$tmp" || true)"
  rm -f "$tmp"
  if [ "$rc" -eq 0 ]; then
    echo "$sym:$h" >> "$STATE"
    done_n=$((done_n + 1)); spent=$((spent + took))
    log "${verdict:-finished} [$((took / 60)) min]"
    left=$((total - done_n))
    [ "$left" -gt 0 ] && log "progress $done_n/$total this run; about $(( (spent / done_n) * left / 60 )) min left at this pace"
  else
    log "FAILED $sym ${h}m (exit $rc) after $((took / 60)) min: ${verdict:-see $LOG}. Not marked done; re-run to retry."
  fi
done < <(plan "$WHICH")

log "== results (latest model per coin and horizon) =="
runuser -u "$APP_USER" -- env PAIRS="$PAIRS" "$PHP" "$APP/artisan" tinker --execute='
foreach (explode(" ", trim(getenv("PAIRS"))) as $p) {
  if ($p === "") continue;
  [$s, $h] = explode(":", $p);
  $m = DB::table("ml_models")->where("symbol", $s)->where("horizon_minutes", (int) $h)->orderByDesc("created_at")->first(["status", "decision_reason", "created_at"]);
  echo str_pad($s, 9).str_pad("h".$h, 7).($m ? str_pad((string) $m->status, 11).(string) $m->decision_reason." (".$m->created_at.")" : "no model yet").PHP_EOL;
}' 2>&1 | tee -a "$LOG"
log "batch $WHICH finished. Review the results before starting the next batch."
