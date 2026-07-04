#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
LOG_FILE="${DEDUPE_LOG_FILE:-$APP_DIR/storage/logs/autofinx-dedupe-runtime.log}"

cd "$APP_DIR"
mkdir -p storage/logs storage/framework

log() {
  echo "[DEDUPE] $(date -Is) $*" | tee -a "$LOG_FILE"
}

supervisor_pid() {
  local program="$1"
  if ! command -v supervisorctl >/dev/null 2>&1; then
    echo ""
    return 0
  fi

  supervisorctl status 2>/dev/null \
    | awk -v program="$program" '$1 == program {
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

oldest_pid_for_pattern() {
  local pattern="$1"
  ps -eo pid=,lstart=,cmd= \
    | grep -E "$pattern" \
    | grep -v grep \
    | awk '{ print $1 }' \
    | head -n 1
}

terminate_pid() {
  local pid="$1"
  kill "$pid" >/dev/null 2>&1 || true
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

  local keep="$preferred_pid"
  if [ -z "$keep" ]; then
    keep="$(oldest_pid_for_pattern "$pattern")"
  fi

  if [ -z "$keep" ]; then
    keep="${pids[0]}"
  fi

  log "$label duplicate count=$count keep=$keep pids=${pids[*]}"
  for pid in "${pids[@]}"; do
    if [ "$pid" != "$keep" ]; then
      terminate_pid "$pid"
    fi
  done
}

dedupe_supervised() {
  local label="$1"
  local pattern="$2"
  local supervisor_program="$3"
  local keep

  keep="$(supervisor_pid "$supervisor_program")"
  dedupe_pattern "$label" "$pattern" "$keep"
}

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

log "done"
