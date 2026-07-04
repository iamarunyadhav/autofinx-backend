#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
ACTION="${1:-daemon}"

CHECK_INTERVAL_SECONDS="${AUTOFINX_MASTER_INTERVAL_SECONDS:-5}"
LIVE_FEED_STALE_SECONDS="${AUTOFINX_MASTER_LIVE_FEED_STALE_SECONDS:-8}"
LIVE_FEED_RESTART_COOLDOWN_SECONDS="${AUTOFINX_MASTER_LIVE_FEED_RESTART_COOLDOWN_SECONDS:-15}"
USER_STREAM_STALE_SECONDS="${AUTOFINX_MASTER_USER_STREAM_STALE_SECONDS:-30}"
USER_STREAM_RESTART_COOLDOWN_SECONDS="${AUTOFINX_MASTER_USER_STREAM_RESTART_COOLDOWN_SECONDS:-20}"
ACCOUNT_SYNC_MAX_AGE_SECONDS="${AUTOFINX_MASTER_ACCOUNT_SYNC_MAX_AGE_SECONDS:-120}"
ACCOUNT_SYNC_COOLDOWN_SECONDS="${AUTOFINX_MASTER_ACCOUNT_SYNC_COOLDOWN_SECONDS:-60}"
SCHEDULE_RUN_MAX_SECONDS="${AUTOFINX_MASTER_SCHEDULE_RUN_MAX_SECONDS:-120}"
LOG_FILE="${AUTOFINX_MASTER_LOG_FILE:-$APP_DIR/storage/logs/autofinx-runtime-master.log}"
LOCK_DIR="$APP_DIR/storage/framework/autofinx-runtime-master.lock"
RESTART_STAMP="$APP_DIR/storage/framework/autofinx-runtime-master-live-feed-restart.at"
USER_STREAM_RESTART_STAMP="$APP_DIR/storage/framework/autofinx-runtime-master-user-stream-restart.at"
ACCOUNT_SYNC_STAMP="$APP_DIR/storage/framework/autofinx-runtime-master-account-sync.at"
HEARTBEAT_FILE="$APP_DIR/storage/framework/autofinx-live-feed-heartbeat.json"
USER_STREAM_HEARTBEAT_FILE="$APP_DIR/storage/framework/autofinx-user-stream-heartbeat.json"
ACCOUNT_SYNC_HEARTBEAT_FILE="$APP_DIR/storage/framework/autofinx-account-sync-heartbeat.json"

cd "$APP_DIR"
mkdir -p storage/logs storage/framework

if [ ! -x "$PHP" ]; then
  PHP="$(command -v php || true)"
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "[RUNTIME-MASTER] PHP binary not found. Set PHP=/path/to/php." >&2
  exit 1
fi

log() {
  echo "[RUNTIME-MASTER] $(date -Is) $*" | tee -a "$LOG_FILE"
}

supervisor_available() {
  command -v supervisorctl >/dev/null 2>&1
}

supervisor_line() {
  local program="$1"
  supervisorctl status 2>/dev/null | awk -v program="$program" '$1 == program { print; exit }'
}

supervisor_exists() {
  [ -n "$(supervisor_line "$1")" ]
}

supervisor_state() {
  local line
  line="$(supervisor_line "$1")"
  [ -n "$line" ] && echo "$line" | awk '{ print $2 }' || echo "MISSING"
}

supervisor_pid() {
  local program="$1"
  supervisor_line "$program" | awk '{
    for (i = 1; i <= NF; i++) {
      if ($i == "pid") {
        pid=$(i + 1);
        gsub(",", "", pid);
        print pid;
        exit;
      }
    }
  }'
}

process_count() {
  local pattern="$1"
  pgrep -f "$pattern" 2>/dev/null | wc -l | tr -d ' '
}

php_artisan_count() {
  local pattern="$1"
  ps -eo comm=,args= \
    | awk -v pattern="$pattern" 'tolower($1) ~ /php/ && $0 ~ pattern { count++ } END { print count + 0 }'
}

supervisor_tree_pids() {
  local root="$1"
  [ -z "$root" ] && return 0
  ps -eo pid=,ppid= \
    | awk -v roots="$root" '
      BEGIN {
        split(roots, rootList, " ");
        for (i in rootList) keep[rootList[i]] = 1;
        changed = 1;
      }
      { pid[NR] = $1; ppid[NR] = $2; }
      END {
        while (changed) {
          changed = 0;
          for (i = 1; i <= NR; i++) {
            if (keep[ppid[i]] && !keep[pid[i]]) {
              keep[pid[i]] = 1;
              changed = 1;
            }
          }
        }
        for (p in keep) print p;
      }
    '
}

oldest_pid_for_pattern() {
  local pattern="$1"
  ps -eo pid=,lstart=,cmd= \
    | grep -E "$pattern" \
    | grep -v grep \
    | awk '{ print $1 }' \
    | head -n 1
}

dedupe_pattern() {
  local label="$1"
  local pattern="$2"
  local preferred_pid="${3:-}"

  mapfile -t pids < <(pgrep -f "$pattern" 2>/dev/null || true)
  local count="${#pids[@]}"
  if [ "$count" -le 1 ]; then
    return 0
  fi

  local keep_pids=()
  if [ -n "$preferred_pid" ]; then
    mapfile -t keep_pids < <(supervisor_tree_pids "$preferred_pid" || true)
  fi
  if [ "${#keep_pids[@]}" -eq 0 ]; then
    local keep
    keep="$(oldest_pid_for_pattern "$pattern")"
    [ -z "$keep" ] && keep="${pids[0]}"
    keep_pids=("$keep")
  fi

  local kill_pids=()
  for pid in "${pids[@]}"; do
    local keep_match=0
    for keep_pid in "${keep_pids[@]}"; do
      [ "$pid" = "$keep_pid" ] && keep_match=1 && break
    done
    [ "$keep_match" -eq 0 ] && kill_pids+=("$pid")
  done

  if [ "${#kill_pids[@]}" -eq 0 ]; then
    return 0
  fi

  log "$label duplicate count=$count keep=${keep_pids[*]} kill=${kill_pids[*]} pids=${pids[*]}"
  for pid in "${kill_pids[@]}"; do
    kill "$pid" >/dev/null 2>&1 || true
  done
}

dedupe_supervised() {
  local label="$1"
  local pattern="$2"
  local program="$3"
  local keep=""
  if supervisor_available && supervisor_exists "$program"; then
    keep="$(supervisor_pid "$program")"
  fi
  dedupe_pattern "$label" "$pattern" "$keep"
}

ensure_supervisor_running() {
  local program="$1"
  if ! supervisor_available || ! supervisor_exists "$program"; then
    return 0
  fi

  local state
  state="$(supervisor_state "$program")"
  if [ "$state" != "RUNNING" ] && [ "$state" != "STARTING" ]; then
    log "starting supervisor program=$program state=$state"
    supervisorctl start "$program" >> "$LOG_FILE" 2>&1 || true
  fi
}

heartbeat_age() {
  if [ ! -s "$HEARTBEAT_FILE" ]; then
    echo 999999
    return 0
  fi

  local epoch
  epoch="$("$PHP" -r '$p=$argv[1]; $j=json_decode((string) @file_get_contents($p), true); echo is_array($j) ? (int) ($j["last_message_epoch"] ?? 0) : 0;' "$HEARTBEAT_FILE" 2>/dev/null || echo 0)"
  if [ "$epoch" -le 0 ]; then
    echo 999999
    return 0
  fi

  echo "$(($(date +%s) - epoch))"
}

json_epoch_age() {
  local path="$1"
  local key="$2"
  if [ ! -s "$path" ]; then
    echo 999999
    return 0
  fi

  local epoch
  epoch="$("$PHP" -r '$p=$argv[1]; $k=$argv[2]; $j=json_decode((string) @file_get_contents($p), true); echo is_array($j) ? (int) ($j[$k] ?? 0) : 0;' "$path" "$key" 2>/dev/null || echo 0)"
  if [ "$epoch" -le 0 ]; then
    echo 999999
    return 0
  fi

  echo "$(($(date +%s) - epoch))"
}

restart_live_feed_if_stale() {
  if ! supervisor_available || ! supervisor_exists "autofinx-trading:live-feed"; then
    return 0
  fi

  local age now_epoch last_restart
  age="$(heartbeat_age)"
  if [ "$age" -le "$LIVE_FEED_STALE_SECONDS" ]; then
    return 0
  fi

  now_epoch="$(date +%s)"
  last_restart="$(cat "$RESTART_STAMP" 2>/dev/null || echo 0)"
  if [ $((now_epoch - last_restart)) -lt "$LIVE_FEED_RESTART_COOLDOWN_SECONDS" ]; then
    log "live-feed stale age=${age}s; cooldown active"
    return 0
  fi

  log "live-feed stale age=${age}s; restarting supervisor-owned feed"
  supervisorctl restart "autofinx-trading:live-feed" >> "$LOG_FILE" 2>&1 || true
  date +%s > "$RESTART_STAMP"
}

user_stream_age() {
  json_epoch_age "$USER_STREAM_HEARTBEAT_FILE" "heartbeat_epoch"
}

restart_user_stream_if_stale() {
  if ! supervisor_available || ! supervisor_exists "autofinx-trading:user-stream-loop"; then
    return 0
  fi

  local age now_epoch last_restart
  age="$(user_stream_age)"
  if [ "$age" -le "$USER_STREAM_STALE_SECONDS" ] && [ "$(php_artisan_count "artisan future-trade:user-stream")" -ge 1 ]; then
    return 0
  fi

  now_epoch="$(date +%s)"
  last_restart="$(cat "$USER_STREAM_RESTART_STAMP" 2>/dev/null || echo 0)"
  if [ $((now_epoch - last_restart)) -lt "$USER_STREAM_RESTART_COOLDOWN_SECONDS" ]; then
    log "user-stream stale age=${age}s; cooldown active"
    return 0
  fi

  log "user-stream stale age=${age}s; clearing lock and restarting supervisor-owned stream"
  "$PHP" artisan cache:forget autofinx_ft:user_stream_lock >> "$LOG_FILE" 2>&1 || true
  pkill -f "artisan future-trade:user-stream" >/dev/null 2>&1 || true
  supervisorctl restart "autofinx-trading:user-stream-loop" >> "$LOG_FILE" 2>&1 || true
  date +%s > "$USER_STREAM_RESTART_STAMP"
}

latest_account_sync_age() {
  json_epoch_age "$ACCOUNT_SYNC_HEARTBEAT_FILE" "sync_epoch"
}

run_account_sync_if_stale() {
  local now_epoch last_sync age
  now_epoch="$(date +%s)"
  last_sync="$(cat "$ACCOUNT_SYNC_STAMP" 2>/dev/null || echo 0)"
  if [ $((now_epoch - last_sync)) -lt "$ACCOUNT_SYNC_COOLDOWN_SECONDS" ]; then
    return 0
  fi

  age="$(latest_account_sync_age)"
  if [ "$age" -le "$ACCOUNT_SYNC_MAX_AGE_SECONDS" ]; then
    return 0
  fi

  log "account-sync stale age=${age}s; refreshing Binance Futures account snapshot"
  timeout 90 "$PHP" artisan future-trade:account-sync >> "$LOG_FILE" 2>&1 || true
  date +%s > "$ACCOUNT_SYNC_STAMP"
}

run_once() {
  dedupe_supervised "live-feed" "artisan future-trade:live-feed" "autofinx-trading:live-feed"
  dedupe_supervised "tick-loop" "artisan future-trade:tick" "autofinx-trading:tick-loop"
  dedupe_supervised "user-stream-loop" "artisan future-trade:user-stream" "autofinx-trading:user-stream-loop"
  dedupe_supervised "queue-critical" "queue:work.*--queue=critical,execution,reconcile,monitoring" "autofinx-trading:queue-critical"
  dedupe_supervised "queue-market" "queue:work.*--queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading" "autofinx-trading:queue-market"
  dedupe_supervised "queue-safe" "queue:work.*--queue=analytics,notifications,default,monitoring_safe" "autofinx-trading:queue-safe"
  dedupe_supervised "quick-settler" "autofinx-quick-settler.sh" "autofinx-quick-settler"
  dedupe_supervised "robot-evaluator" "autofinx-robot-evaluator.sh" "autofinx-robot-evaluator"
  dedupe_supervised "scheduler" "artisan schedule:work" "autofinx-scheduler"
  dedupe_pattern "schedule-run" "artisan schedule:run"
  cleanup_stale_schedule_run

  for program in \
    "autofinx-trading:queue-critical" \
    "autofinx-trading:queue-market" \
    "autofinx-trading:queue-safe" \
    "autofinx-trading:live-feed" \
    "autofinx-trading:tick-loop" \
    "autofinx-trading:user-stream-loop" \
    "autofinx-quick-settler" \
    "autofinx-robot-evaluator" \
    "autofinx-scheduler"; do
    ensure_supervisor_running "$program"
  done

  restart_live_feed_if_stale
  restart_user_stream_if_stale
  run_account_sync_if_stale
}

cleanup_stale_schedule_run() {
  local now_epoch
  now_epoch="$(date +%s)"
  while read -r pid start_epoch; do
    [ -z "${pid:-}" ] && continue
    if [ $((now_epoch - start_epoch)) -gt "$SCHEDULE_RUN_MAX_SECONDS" ]; then
      log "schedule:run stale age=$((now_epoch - start_epoch))s pid=$pid; terminating"
      kill "$pid" >/dev/null 2>&1 || true
    fi
  done < <(ps -eo pid=,etimes=,args= | awk '/artisan schedule:run/ && !/awk/ { print $1, $2 }')
}

status_report() {
  echo "== supervisor =="
  supervisorctl status 2>/dev/null || true
  echo
  echo "== process counts =="
  printf "live_feed=%s\n" "$(process_count "artisan future-trade:live-feed")"
  printf "tick_loop=%s\n" "$(php_artisan_count "artisan future-trade:tick")"
  printf "user_stream=%s\n" "$(php_artisan_count "artisan future-trade:user-stream")"
  printf "queue_workers=%s\n" "$(php_artisan_count "artisan queue:work")"
  printf "schedule_work=%s\n" "$(php_artisan_count "artisan schedule:work")"
  printf "schedule_run=%s\n" "$(process_count "artisan schedule:run")"
  printf "quick_settler=%s\n" "$(process_count "autofinx-quick-settler.sh")"
  printf "robot_evaluator=%s\n" "$(process_count "autofinx-robot-evaluator.sh")"
  printf "live_feed_age_s=%s\n" "$(heartbeat_age)"
  printf "user_stream_age_s=%s\n" "$(user_stream_age)"
  printf "account_sync_age_s=%s\n" "$(latest_account_sync_age)"
}

cleanup_cron() {
  local tmp backup
  tmp="$(mktemp)"
  backup="$APP_DIR/storage/logs/crontab.before-runtime-master.$(date +%Y%m%d%H%M%S).txt"
  crontab -l > "$backup" 2>/dev/null || true
  crontab -l 2>/dev/null \
    | grep -vE "future-trade-heartbeat\.sh|start-workers\.sh|restart-workers\.sh|stop-workers\.sh|future-trade:live-feed|future-trade:tick|future-trade:user-stream|artisan queue:work|artisan schedule:work" \
    > "$tmp" || true
  crontab "$tmp"
  rm -f "$tmp"
  log "cron cleanup done backup=$backup"
}

acquire_lock() {
  if [ -d "$LOCK_DIR" ]; then
    local old_pid
    old_pid="$(cat "$LOCK_DIR/pid" 2>/dev/null || echo "")"
    if [ -n "$old_pid" ] && kill -0 "$old_pid" >/dev/null 2>&1; then
      echo "[RUNTIME-MASTER] already running pid=$old_pid"
      exit 0
    fi
    rm -rf "$LOCK_DIR"
  fi
  mkdir "$LOCK_DIR"
  echo "$$" > "$LOCK_DIR/pid"
  trap 'rm -rf "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT
}

case "$ACTION" in
  once)
    run_once
    status_report
    ;;
  daemon)
    acquire_lock
    log "daemon started interval=${CHECK_INTERVAL_SECONDS}s live_feed_stale=${LIVE_FEED_STALE_SECONDS}s user_stream_stale=${USER_STREAM_STALE_SECONDS}s account_sync_max_age=${ACCOUNT_SYNC_MAX_AGE_SECONDS}s"
    while true; do
      run_once || true
      sleep "$CHECK_INTERVAL_SECONDS"
    done
    ;;
  status)
    status_report
    ;;
  cleanup-cron)
    cleanup_cron
    ;;
  *)
    echo "Usage: $0 [daemon|once|status|cleanup-cron]" >&2
    exit 2
    ;;
esac
