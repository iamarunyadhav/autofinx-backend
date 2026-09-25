#!/usr/bin/env bash
# shellcheck disable=SC2016  # single-quoted PHP/awk/sed programs are intentional (no shell expansion wanted)
# shellcheck disable=SC2015  # "A && pass || fail" is used only where pass/info always return 0
# =============================================================================
# AutoFinX Nexus -- one-shot, idempotent production setup (Phase 0).
#
#   sudo bash scripts/nexus-production-setup.sh                 # DRY RUN (default)
#   sudo bash scripts/nexus-production-setup.sh --apply         # perform
#   sudo bash scripts/nexus-production-setup.sh --verify        # read-only re-check
#   sudo bash scripts/nexus-production-setup.sh --rollback [--rollback-migration]
#
# Phase 0 = Nexus enabled, AUTOFINX_NEXUS_AUTONOMY_LEVEL=0 (local observations
# and reports only: no external source fetches, no LLM path), LLM disabled, the
# dedicated `nexus` Redis queue connection, two isolated Supervisor workers,
# and exactly one owner of the 15-minute `nexus:run-cycle` trigger.
#
# What it deliberately NEVER does: set/print an LLM key, raise autonomy above
# 0, re-enable the Laravel `schedule:run` cron, broadcast `queue:restart`,
# restart/modify any existing trading Supervisor program, run any migration
# other than the pinned Nexus one (and, only with --with-wait-shadows, the
# pinned quick_trade_wait_shadows one), or git add/commit/pull.
#
# Every step verifies its own result. Dry run changes nothing except writing
# its own log file under storage/logs/. See docs/NEXUS_PRODUCTION_SETUP.md.
# =============================================================================
set -Euo pipefail
# bash < 4.4 (EL7) treats "${empty_array[@]}" as unbound under set -u
if [ "${BASH_VERSINFO[0]}" -lt 4 ] || { [ "${BASH_VERSINFO[0]}" -eq 4 ] && [ "${BASH_VERSINFO[1]}" -lt 4 ]; }; then set +u; fi
umask 022

SCRIPT_VERSION="2026-09-24.1"

# ---------------------------------------------------------------------------
# Constants / overridable environment
# ---------------------------------------------------------------------------
APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
APP_USER="${APP_USER:-zcadmin}"
SUP_LOG_DIR="${SUPERVISOR_LOG_DIR:-/var/log/autofinx}"
STATE_DIR="${NEXUS_SETUP_STATE_DIR:-/var/lib/autofinx-nexus-setup}"
AS_APP_PATH="${AS_APP_PATH:-/usr/local/bin:/usr/bin:/bin}"
AS_APP_PASSENV="${AS_APP_PASSENV:-}"          # extra env var NAMES to pass to as_app (test hosts only)
TEST_MODE="${NEXUS_SETUP_TEST_MODE:-0}"      # 1 = allow non-root dry-run/verify on a dev box

NEXUS_MIG_FILE="database/migrations/2026_09_28_000000_create_nexus_tables.php"
NEXUS_MIG_NAME="2026_09_28_000000_create_nexus_tables"
# sha256 of the exact file audited on 2026-09-24 (LF endings, as committed).
NEXUS_MIG_SHA256="5b4e1ce59fa5de7061fe5b60c410bdd61ef09d7a50fb367e0ea2e98e5d41372e"
NEXUS_TABLES=(nexus_runs nexus_entries nexus_reviews nexus_model_calls)   # creation order
WAIT_MIG_FILE="database/migrations/2026_09_30_000000_create_quick_trade_wait_shadows.php"
WAIT_MIG_NAME="2026_09_30_000000_create_quick_trade_wait_shadows"
WAIT_MIG_SHA256="2fc6a552afc426ca3f9da72d1fe6effd9e2bdb084464becb1dbb2f0e8a3ad8aa"
WAIT_TABLE="quick_trade_wait_shadows"

PROGRAMS=(autofinx-nexus-research autofinx-nexus-training)
declare -A PROGRAM_QUEUE=([autofinx-nexus-research]=agent_research [autofinx-nexus-training]=agent_training)
declare -A PROGRAM_ARGS=(
  [autofinx-nexus-research]="queue:work nexus --queue=agent_research --sleep=3 --tries=1 --timeout=110 --memory=192 --max-time=3600"
  [autofinx-nexus-training]="queue:work nexus --queue=agent_training --sleep=5 --tries=1 --timeout=110 --memory=256 --max-time=3600"
)
JOB_TIMEOUT=110          # RunNexusCycleJob::$timeout / worker --timeout
STOPWAITSECS=150         # > job timeout, < nexus retry_after (180)

# Exactly the keys config/nexus.php reads that Phase 0 must pin. LLM URL/KEY/
# models are intentionally NOT written (left absent/blank).
ENV_KEYS=(AUTOFINX_NEXUS_ENABLED AUTOFINX_NEXUS_AUTONOMY_LEVEL AUTOFINX_NEXUS_QUEUE_CONNECTION AUTOFINX_NEXUS_LLM_ENABLED)
declare -A ENV_WANT=(
  [AUTOFINX_NEXUS_ENABLED]=true
  [AUTOFINX_NEXUS_AUTONOMY_LEVEL]=0
  [AUTOFINX_NEXUS_QUEUE_CONNECTION]=nexus
  [AUTOFINX_NEXUS_LLM_ENABLED]=false
)
ENV_MARKER="# Nexus Phase-0 rollout (added by scripts/nexus-production-setup.sh)"

CRON_BEGIN="# >>> autofinx-nexus-cycle (managed by scripts/nexus-production-setup.sh; do not edit inside) >>>"
CRON_END="# <<< autofinx-nexus-cycle <<<"
# Minute offsets 7/22/37/52 sit inside the same 900-second slot as the
# scheduler's :00/:15/:30/:45 tick, so even if both ever fire, NexusCycleService
# returns the existing slot row (unique workspace_id+slot) -- never a 2nd run.
CRON_SCHEDULE="7,22,37,52 * * * *"

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
MODE="dry-run"
WITH_WAIT=0
ROLLBACK_MIGRATION=0
WORKSPACE=""
SCHEDULE_CHOICE="auto"
ACCEPT_DRIFT=0
SETTLE_SECONDS=75
CYCLE_TIMEOUT=300
ASSUME_YES=0
SKIP_DB_BACKUP=0

usage() {
  cat <<'EOF'
Usage: sudo bash scripts/nexus-production-setup.sh [MODE] [OPTIONS]

Modes (default: dry run -- prints preflight + every planned action, changes nothing):
  --apply                 Perform the setup (asks for confirmation unless --yes).
  --verify                Read-only health check (run again ~20 min after --apply to
                          confirm the 15-minute cadence actually fired).
  --rollback              Remove Nexus cron block, restore the Nexus .env keys from the
                          backup, stop + remove the Nexus Supervisor programs.
  --rollback-migration    (with --rollback only) also roll back the Nexus migration
                          (dumps the nexus_* tables first). Only if this script ran it.

Options:
  --with-wait-shadows     Also run the pinned 2026_09_30 quick_trade_wait_shadows
                          migration if it is pending and has the reference_at fix.
  --workspace UUID        Workspace for the verification cycle (default: oldest).
  --schedule auto|scheduler|cron
                          Who triggers nexus:run-cycle every 15 min (default auto:
                          Supervisor autofinx-scheduler if it runs schedule:work and
                          lists nexus:run-cycle, otherwise a dedicated flock'd cron).
  --accept-config-drift   Proceed even though the live config cache differs from
                          .env in non-Nexus keys (re-caching would activate them).
  --settle-seconds N      Worker stability window (default 75; min 20).
  --cycle-timeout N       Seconds to wait for the verification cycle (default 300).
  --skip-db-backup        Skip the mysqldump backup (only if the DB password cannot be
                          expressed in a MySQL option file; .env/config/cron backups
                          are still taken).
  --yes                   Do not prompt before --apply / --rollback.
  -h, --help

Environment overrides: APP_DIR, APP_USER, PHP_BIN, SUPERVISOR_LOG_DIR,
SUPERVISOR_MAIN_CONF, SUPERVISOR_INCLUDE_DIR (default: auto-detect
/etc/supervisord.d then /etc/supervisor/conf.d, like the existing installers).
EOF
}

set_mode() {
  if [ "$MODE" != "dry-run" ] && [ "$MODE" != "$1" ]; then
    echo "Only one of --apply / --verify / --rollback may be given." >&2
    exit 2
  fi
  MODE="$1"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --apply) set_mode apply ;;
    --verify) set_mode verify ;;
    --rollback) set_mode rollback ;;
    --rollback-migration) ROLLBACK_MIGRATION=1 ;;
    --with-wait-shadows) WITH_WAIT=1 ;;
    --workspace) WORKSPACE="${2:-}"; shift ;;
    --workspace=*) WORKSPACE="${1#*=}" ;;
    --schedule) SCHEDULE_CHOICE="${2:-}"; shift ;;
    --schedule=*) SCHEDULE_CHOICE="${1#*=}" ;;
    --accept-config-drift) ACCEPT_DRIFT=1 ;;
    --settle-seconds) SETTLE_SECONDS="${2:-}"; shift ;;
    --settle-seconds=*) SETTLE_SECONDS="${1#*=}" ;;
    --cycle-timeout) CYCLE_TIMEOUT="${2:-}"; shift ;;
    --cycle-timeout=*) CYCLE_TIMEOUT="${1#*=}" ;;
    --skip-db-backup) SKIP_DB_BACKUP=1 ;;
    --yes) ASSUME_YES=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [ "$ROLLBACK_MIGRATION" -eq 1 ] && [ "$MODE" != "rollback" ]; then
  echo "--rollback-migration is only valid together with --rollback." >&2; exit 2
fi
if [ "$WITH_WAIT" -eq 1 ] && { [ "$MODE" = "rollback" ] || [ "$MODE" = "verify" ]; }; then
  echo "--with-wait-shadows is only valid for dry run / --apply." >&2; exit 2
fi
case "$SCHEDULE_CHOICE" in auto|scheduler|cron) ;; *) echo "--schedule must be auto|scheduler|cron" >&2; exit 2 ;; esac
if [ -n "$WORKSPACE" ] && ! [[ "$WORKSPACE" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
  echo "--workspace must be a UUID." >&2; exit 2
fi
[[ "$SETTLE_SECONDS" =~ ^[0-9]+$ ]] && [ "$SETTLE_SECONDS" -ge 20 ] || { echo "--settle-seconds must be an integer >= 20" >&2; exit 2; }
[[ "$CYCLE_TIMEOUT" =~ ^[0-9]+$ ]] && [ "$CYCLE_TIMEOUT" -ge 30 ] || { echo "--cycle-timeout must be an integer >= 30" >&2; exit 2; }
if [ "$TEST_MODE" = "1" ] && { [ "$MODE" = "apply" ] || [ "$MODE" = "rollback" ]; }; then
  echo "NEXUS_SETUP_TEST_MODE=1 is only allowed for dry run / --verify." >&2; exit 2
fi

# ---------------------------------------------------------------------------
# Logging (fd 3 = log file, opened once so later renames/symlinks can't redirect it)
# ---------------------------------------------------------------------------
if [ ! -f "$APP_DIR/artisan" ] || [ ! -d "$APP_DIR/storage/logs" ]; then
  echo "APP_DIR does not look like the Laravel backend: $APP_DIR (set APP_DIR=...)" >&2
  exit 2
fi
cd "$APP_DIR" || exit 2
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOG_FILE="$APP_DIR/storage/logs/nexus-production-setup-$MODE-$TS.log"
if ! ( set -C; : > "$LOG_FILE" ) 2>/dev/null; then
  echo "Cannot create log file $LOG_FILE" >&2; exit 2
fi
exec 3>>"$LOG_FILE"
chmod 600 "$LOG_FILE" 2>/dev/null || true

mask() {
  sed -E \
    -e 's/((PASSWORD|PASSWD|_PASS|SECRET|TOKEN|API_KEY|APP_KEY|_KEY|PRIVATE|CREDENTIAL|AUTH)[A-Za-z0-9_]*["'\'']?[[:space:]]*[=:][[:space:]]*)[^[:space:],;]+/\1***MASKED***/Ig' \
    -e 's/base64:[A-Za-z0-9+\/=]{16,}/base64:***MASKED***/g' \
    -e 's#([a-z]+://)[^:@/[:space:]]+:[^@/[:space:]]+@#\1***:***@#g'
}
_out() { printf '%s\n' "$1"; printf '%s\n' "$1" >&3; }
log() { _out "[$(date -u +%H:%M:%S)] $(printf '%s' "$*" | mask)"; }
section() { _out ""; _out "=== $* ==="; }
emit() { # indent + mask stdin into console and log
  local t
  t="$(mask | sed 's/^/    /')"
  [ -n "$t" ] && _out "$t"
  return 0
}

declare -a R_STATUS=() R_STEP=() R_DETAIL=()
FAILS=0
WARNS=0
record() {
  R_STATUS+=("$1"); R_STEP+=("$2"); R_DETAIL+=("$(printf '%s' "$3" | mask)")
  _out "  [$1] $2 -- $(printf '%s' "$3" | mask)"
}
pass() { record PASS "$1" "$2"; }
warn() { WARNS=$((WARNS + 1)); record WARN "$1" "$2"; }
fail() { FAILS=$((FAILS + 1)); record FAIL "$1" "$2"; }
info() { record INFO "$1" "$2"; }
plan() { record PLAN "$1" "$2"; }
skip() { record SKIP "$1" "$2"; }

print_summary() {
  _out ""
  _out "================================ SUMMARY ($MODE) ================================"
  local i
  _out "$(printf '%-5s %-46s %s' STATUS STEP DETAIL)"
  _out "$(printf '%-5s %-46s %s' ----- ---------------------------------------------- ------)"
  for i in "${!R_STATUS[@]}"; do
    _out "$(printf '%-5s %-46s %s' "${R_STATUS[$i]}" "${R_STEP[$i]:0:46}" "${R_DETAIL[$i]}")"
  done
  _out "---------------------------------------------------------------------------------"
  if [ "$FAILS" -gt 0 ]; then
    _out "RESULT: FAIL ($FAILS failure(s), $WARNS warning(s))."
  else
    _out "RESULT: PASS ($WARNS warning(s))."
  fi
  _out "Log: $LOG_FILE"
}

TMP_WORK=""
ENV_TMP_PENDING=""
# shellcheck disable=SC2329  # invoked via trap
cleanup() {
  [ -n "$ENV_TMP_PENDING" ] && rm -f "$ENV_TMP_PENDING" 2>/dev/null
  [ -n "$TMP_WORK" ] && rm -rf "$TMP_WORK" 2>/dev/null
  return 0
}
# shellcheck disable=SC2329  # cleanup is invoked by the EXIT trap
trap cleanup EXIT
trap 'log "Interrupted. If --apply was running, re-run in dry-run to see state, then --rollback if needed."; exit 130' INT TERM

finish() { # $1 = exit code override (optional)
  print_summary
  if [ "$FAILS" -gt 0 ]; then exit 1; fi
  exit "${1:-0}"
}
abort() { # stop immediately (after a FAIL was recorded)
  log "ABORTING: $*"
  if [ "$MODE" = "apply" ] && [ "${APPLY_STARTED:-0}" = "1" ]; then
    log "Some apply steps may have completed. Review the summary; run --rollback to revert (see docs/NEXUS_PRODUCTION_SETUP.md)."
  fi
  print_summary
  exit 1
}

TMP_WORK="$(mktemp -d "${TMPDIR:-/tmp}/nexus-setup.XXXXXX")" || { echo "mktemp failed" >&2; exit 2; }
chmod 700 "$TMP_WORK"

# ---------------------------------------------------------------------------
# Generic helpers
# ---------------------------------------------------------------------------
IS_ROOT=0
[ "$(id -u)" = "0" ] && IS_ROOT=1

APP_HOME=""
AS_APP_METHOD=""
# as_app [VAR=value ...] command args...   -- run as $APP_USER with a CLEAN env
# (only HOME/USER/LOGNAME/PATH/LANG + explicit VAR=value), so the app reads its
# own .env exactly like PHP-FPM / Supervisor workers do, never root's env.
as_app() {
  local -a envs=("HOME=$APP_HOME" "USER=$APP_USER" "LOGNAME=$APP_USER" "PATH=$AS_APP_PATH" "LANG=C" "LC_ALL=C")
  local n
  for n in $AS_APP_PASSENV; do
    [ -n "${!n:-}" ] && envs+=("$n=${!n}")
  done
  while [ $# -gt 0 ] && [[ "$1" =~ ^[A-Z_][A-Z0-9_]*= ]]; do envs+=("$1"); shift; done
  case "$AS_APP_METHOD" in
    runuser) runuser -u "$APP_USER" -- env -i "${envs[@]}" "$@" ;;
    sudo) sudo -n -H -u "$APP_USER" -- env -i "${envs[@]}" "$@" ;;
    *) echo "as_app: no method to switch to $APP_USER" >&2; return 97 ;;
  esac
}

artisan() { as_app timeout 300 "$PHP_BIN" artisan "$@" --no-interaction --no-ansi; }

# tinker_eval CODE [VAR=value ...] -> stdout (caller parses NX| lines)
tinker_eval() {
  local code="$1"; shift
  # tr: strip CR so values compare exactly even if PHP_EOL is CRLF (dev boxes)
  as_app "$@" timeout 180 "$PHP_BIN" artisan tinker --no-interaction --no-ansi --execute="$code" 2>&1 | tr -d '\r'
}

declare -A F=()
parse_facts() { # stdin: NX|key|value lines -> F[]
  local tag k v
  while IFS='|' read -r tag k v; do
    [ "$tag" = "NX" ] && [ -n "$k" ] && F["$k"]="$v"
  done
  return 0
}
fact() { printf '%s' "${F[$1]:-}"; }

sha256_of() { sha256sum "$1" 2>/dev/null | awk '{print $1}'; }
stat_ugm() { stat -c '%U:%G %a' "$1" 2>/dev/null; }
stat_num() { stat -c '%u:%g %a' "$1" 2>/dev/null; }

confirm_or_abort() {
  [ "$ASSUME_YES" -eq 1 ] && return 0
  if [ ! -r /dev/tty ]; then
    fail "confirmation" "No TTY for confirmation; re-run with --yes after reviewing the dry run."
    abort "no confirmation"
  fi
  local answer=""
  printf '\n%s\nType %s to continue: ' "$1" "$2" > /dev/tty
  read -r answer < /dev/tty || true
  if [ "$answer" != "$2" ]; then
    fail "confirmation" "Operator did not confirm; nothing further was changed."
    abort "not confirmed"
  fi
}

# ---------------------------------------------------------------------------
# State file (root-only, never sourced -- parsed as data)
# ---------------------------------------------------------------------------
STATE_FILE="$STATE_DIR/state"
state_get() { [ -f "$STATE_FILE" ] && grep -m1 "^$1=" "$STATE_FILE" 2>/dev/null | cut -d= -f2- || true; }
state_set() {
  local key="$1" value="$2" tmp
  mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR" || return 1
  tmp="$(mktemp "$STATE_DIR/.state.XXXXXX")" || return 1
  { [ -f "$STATE_FILE" ] && grep -v "^$key=" "$STATE_FILE"; printf '%s=%s\n' "$key" "$value"; } > "$tmp"
  chmod 600 "$tmp" && mv -f "$tmp" "$STATE_FILE"
}
state_set_once() { [ -n "$(state_get "$1")" ] || state_set "$1" "$2"; }

# ---------------------------------------------------------------------------
# PHP snippets executed through `php artisan tinker` AS THE APP USER
# ---------------------------------------------------------------------------
read -r -d '' PHP_FACTS <<'PHPCODE' || true
$o = function ($k, $v) { if (is_bool($v)) { $v = $v ? 'true' : 'false'; } elseif ($v === null) { $v = 'null'; } elseif (is_array($v)) { $v = json_encode($v); } echo 'NX|'.$k.'|'.str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) $v).PHP_EOL; };
$DB = 'Illuminate\Support\Facades\DB'; $SC = 'Illuminate\Support\Facades\Schema';
$db = (string) config('database.default');
$o('app.env', config('app.env'));
$o('app.url', config('app.url'));
$o('db.default', $db);
$o('db.driver', config("database.connections.$db.driver"));
$o('db.database', config("database.connections.$db.database"));
$o('db.charset', config("database.connections.$db.charset"));
$o('db.collation', config("database.connections.$db.collation"));
$mysql = in_array(config("database.connections.$db.driver"), ['mysql', 'mariadb'], true);
$dbok = false;
try { $DB::connection()->getPdo(); $dbok = true; $o('db.ping', 'ok'); } catch (\Throwable $e) { $o('db.ping', 'error: '.class_basename($e).': '.substr($e->getMessage(), 0, 180)); }
if ($dbok && $mysql) {
    try { $r = $DB::selectOne('select version() as v, @@GLOBAL.sql_mode as m'); $o('db.version', $r->v); $o('db.sql_mode', $r->m); } catch (\Throwable $e) { $o('db.version', 'error'); }
    try { $o('db.row_format', $DB::selectOne('select @@innodb_default_row_format as f')->f); } catch (\Throwable $e) { $o('db.row_format', 'unknown'); }
    foreach (['workspaces', 'users'] as $t) {
        try {
            $r = $DB::selectOne("select c.COLUMN_TYPE ct, c.CHARACTER_SET_NAME cs, c.COLLATION_NAME co, c.COLUMN_KEY ck, t.ENGINE en from information_schema.COLUMNS c join information_schema.TABLES t on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME where c.TABLE_SCHEMA = database() and c.TABLE_NAME = ? and c.COLUMN_NAME = 'id'", [$t]);
            $o("fk.$t", $r ? implode(',', [strtolower((string) $r->ct), (string) $r->cs, (string) $r->co, (string) $r->ck, (string) $r->en]) : 'missing');
        } catch (\Throwable $e) { $o("fk.$t", 'error'); }
    }
}
if ($dbok) {
    try { $o('mig.table', $SC::hasTable('migrations') ? 'yes' : 'no'); } catch (\Throwable $e) { $o('mig.table', 'error'); }
    foreach (['nexus' => '2026_09_28_000000_create_nexus_tables', 'wait' => '2026_09_30_000000_create_quick_trade_wait_shadows'] as $k => $m) {
        try { $b = $DB::table('migrations')->where('migration', $m)->value('batch'); $o("mig.$k", $b === null ? 'pending' : (string) $b); } catch (\Throwable $e) { $o("mig.$k", 'error'); }
    }
    try { $o('mig.maxbatch', (string) $DB::table('migrations')->max('batch')); } catch (\Throwable $e) { $o('mig.maxbatch', 'error'); }
    foreach (['nexus_runs', 'nexus_entries', 'nexus_reviews', 'nexus_model_calls', 'quick_trade_wait_shadows'] as $t) {
        try { $o("tbl.$t", $SC::hasTable($t) ? (string) $DB::table($t)->count() : 'absent'); } catch (\Throwable $e) { $o("tbl.$t", 'error'); }
    }
    try { $o('ws.count', (string) App\Models\Workspace::query()->count()); $o('ws.oldest', (string) App\Models\Workspace::query()->orderBy('created_at')->orderBy('id')->value('id')); } catch (\Throwable $e) { $o('ws.count', 'error'); }
}
$o('nexus.enabled', config('nexus.enabled'));
$o('nexus.autonomy', config('nexus.autonomy'));
$o('nexus.connection', config('nexus.connection'));
$o('nexus.queue', config('nexus.queue'));
$o('nexus.training_queue', config('nexus.training_queue'));
$o('nexus.max_pending', config('nexus.max_pending'));
$o('nexus.llm.enabled', config('nexus.llm.enabled'));
$o('nexus.llm.url_set', (bool) config('nexus.llm.url'));
$o('nexus.llm.key_set', (bool) config('nexus.llm.key'));
$qc = (string) config('nexus.connection');
$o('queue.nexus.exists', is_array(config('queue.connections.nexus')));
$o('queue.nexus.driver', config('queue.connections.nexus.driver'));
$o('queue.nexus.retry_after', config('queue.connections.nexus.retry_after'));
$o('queue.nexus.redis', config('queue.connections.nexus.connection'));
$cs = (string) config('cache.default');
$o('cache.store', $cs);
$o('cache.driver', config("cache.stores.$cs.driver"));
$o('sched.enabled', config('autofinx.scheduler.enabled', true));
$o('runtime.supervisor', config('autofinx.runtime.supervisor'));
try { $o('redis.ping', Illuminate\Support\Facades\Redis::connection((string) config('queue.connections.nexus.connection', 'default'))->ping() ? 'ok' : 'false'); } catch (\Throwable $e) { $o('redis.ping', 'error: '.class_basename($e).': '.substr($e->getMessage(), 0, 160)); }
try { $o('queue.size.research', (string) Illuminate\Support\Facades\Queue::connection('nexus')->size('agent_research')); $o('queue.size.training', (string) Illuminate\Support\Facades\Queue::connection('nexus')->size('agent_training')); } catch (\Throwable $e) { $o('queue.size.research', 'error: '.class_basename($e)); }
try { if ($dbok && $SC::hasTable('nexus_runs')) { $o('runs.pending', (string) App\Models\NexusRun::whereIn('status', ['queued', 'running'])->count()); } } catch (\Throwable $e) { }
$o('ok', '1');
PHPCODE

# Secrets for mysqldump / redis-cli. Output is captured in memory and NEVER logged.
read -r -d '' PHP_SECRETS <<'PHPCODE' || true
$db = (string) config('database.default'); $c = (array) config("database.connections.$db");
$h = $c['host'] ?? null; if (is_array($h)) { $h = $h[0] ?? null; }
$r = (array) config('database.redis.'.(string) config('queue.connections.nexus.connection', 'default'));
echo 'NXSECRET:'.base64_encode(json_encode(['db' => ['host' => $h, 'port' => $c['port'] ?? null, 'database' => $c['database'] ?? null, 'username' => $c['username'] ?? null, 'password' => $c['password'] ?? null, 'socket' => $c['unix_socket'] ?? null], 'redis' => ['host' => $r['host'] ?? null, 'port' => $r['port'] ?? null, 'password' => $r['password'] ?? null, 'username' => $r['username'] ?? null, 'database' => $r['database'] ?? null, 'scheme' => $r['scheme'] ?? null, 'path' => $r['path'] ?? null]])).PHP_EOL;
PHPCODE

# Flattened sha1 map of every config leaf EXCEPT the nexus.* tree (drift detection).
read -r -d '' PHP_CONFIG_HASH <<'PHPCODE' || true
$f = function ($a, $p) use (&$f) { $o = []; foreach ($a as $k => $v) { $key = $p === '' ? (string) $k : $p.'.'.$k; if (is_array($v) && $v !== [] && ! array_is_list($v)) { $o += $f($v, $key); } else { $o[$key] = sha1((string) json_encode($v)); } } return $o; };
$c = config()->all(); unset($c['nexus']);
echo 'NXCFG:'.base64_encode(json_encode($f($c, ''))).PHP_EOL;
PHPCODE

read -r -d '' PHP_RUN_LOOKUP <<'PHPCODE' || true
$w = getenv('NX_WS');
$now = intdiv(now()->timestamp, 900);
$r = App\Models\NexusRun::where('workspace_id', $w)->whereIn('slot', [(string) $now, (string) ($now - 1)])->orderByDesc('created_at')->first();
echo 'NX|slot.now|'.$now.PHP_EOL;
echo 'NX|run.id|'.($r ? $r->id : '').PHP_EOL;
echo 'NX|run.slot|'.($r ? $r->slot : '').PHP_EOL;
echo 'NX|run.status|'.($r ? $r->status : '').PHP_EOL;
echo 'NX|ok|1'.PHP_EOL;
PHPCODE

read -r -d '' PHP_RUN_STATUS <<'PHPCODE' || true
$r = App\Models\NexusRun::find(getenv('NX_RUN'));
echo 'NX|run.status|'.($r ? $r->status : 'missing').PHP_EOL;
echo 'NX|run.error|'.($r ? (string) $r->error_code : '').PHP_EOL;
echo 'NX|run.summary|'.($r ? str_replace(["\n", '|'], [' ', '/'], (string) json_encode($r->summary)) : '').PHP_EOL;
echo 'NX|run.started|'.($r && $r->started_at ? $r->started_at->toIso8601String() : '').PHP_EOL;
echo 'NX|run.finished|'.($r && $r->finished_at ? $r->finished_at->toIso8601String() : '').PHP_EOL;
if ($r) { echo 'NX|run.entries|'.json_encode(App\Models\NexusEntry::where('workspace_id', $r->workspace_id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')).PHP_EOL; }
echo 'NX|ok|1'.PHP_EOL;
PHPCODE

read -r -d '' PHP_VERIFY_RUNS <<'PHPCODE' || true
$since = now()->subDay();
echo 'NX|v.by_status|'.json_encode(App\Models\NexusRun::where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')).PHP_EOL;
$last = App\Models\NexusRun::orderByDesc('created_at')->first();
echo 'NX|v.last_slot|'.($last ? $last->slot : '').PHP_EOL;
echo 'NX|v.last_status|'.($last ? $last->status : '').PHP_EOL;
echo 'NX|v.last_age_s|'.($last ? (string) now()->diffInSeconds($last->created_at, true) : '').PHP_EOL;
$after = (int) getenv('NX_AFTER_SLOT');
echo 'NX|v.later_slots|'.($after > 0 ? (string) App\Models\NexusRun::whereRaw('CAST(slot AS UNSIGNED) > ?', [$after])->distinct()->count('slot') : '').PHP_EOL;
try { echo 'NX|v.failed_jobs|'.(string) Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>=', $since)->where('payload', 'like', '%RunNexusCycleJob%')->count().PHP_EOL; } catch (\Throwable $e) { echo 'NX|v.failed_jobs|unknown'.PHP_EOL; }
echo 'NX|ok|1'.PHP_EOL;
PHPCODE

# ---------------------------------------------------------------------------
# Environment detection
# ---------------------------------------------------------------------------
PHP_BIN_REQ="${PHP_BIN:-}"
PHP_BIN=""
detect_php() {
  local c vid
  for c in "$PHP_BIN_REQ" /usr/local/bin/ea-php82 /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/ea-php83 /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/ea-php84 /opt/cpanel/ea-php84/root/usr/bin/php; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    vid="$("$c" -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"
    if [[ "$vid" =~ ^[0-9]+$ ]] && [ "$vid" -ge 80200 ]; then PHP_BIN="$c"; return 0; fi
  done
  return 1
}

ENV_FILE=""
resolve_env_file() {
  ENV_FILE="$APP_DIR/.env"
  if [ -L "$ENV_FILE" ]; then ENV_FILE="$(readlink -f "$ENV_FILE")"; fi
}

SUP_MAIN=""; SUP_DIR=""; SUP_FILE=""; SUP_INCLUDED=0
declare -a SUP_CONF_FILES=()
detect_supervisor() {
  local c globs g base
  for c in "${SUPERVISOR_MAIN_CONF:-}" /etc/supervisord.conf /etc/supervisor/supervisord.conf; do [ -n "$c" ] && [ -f "$c" ] && { SUP_MAIN="$c"; break; }; done
  # Same detection order as database/scripts/install-trading-supervisor-daemons.sh
  if [ -n "${SUPERVISOR_INCLUDE_DIR:-}" ] && [ -d "$SUPERVISOR_INCLUDE_DIR" ]; then
    SUP_DIR="$SUPERVISOR_INCLUDE_DIR"; SUP_FILE="$SUP_DIR/autofinx-nexus.ini"
    [[ "$SUP_DIR" == */conf.d ]] && SUP_FILE="$SUP_DIR/autofinx-nexus.conf"
  elif [ -d /etc/supervisord.d ]; then SUP_DIR=/etc/supervisord.d; SUP_FILE=/etc/supervisord.d/autofinx-nexus.ini
  elif [ -d /etc/supervisor/conf.d ]; then SUP_DIR=/etc/supervisor/conf.d; SUP_FILE=/etc/supervisor/conf.d/autofinx-nexus.conf
  fi
  SUP_CONF_FILES=()
  [ -n "$SUP_MAIN" ] && SUP_CONF_FILES+=("$SUP_MAIN")
  if [ -n "$SUP_MAIN" ]; then
    globs="$(awk '/^\[include\]/{f=1;next} /^\[/{f=0} f && /^[[:space:]]*files[[:space:]]*=/{sub(/^[^=]*=[[:space:]]*/,""); print}' "$SUP_MAIN")"
    base="$(dirname "$SUP_MAIN")"
    for g in $globs; do
      [[ "$g" == /* ]] || g="$base/$g"
      # shellcheck disable=SC2053 # intentional glob match of our target against the include glob
      [ -n "$SUP_FILE" ] && [[ "$SUP_FILE" == $g ]] && SUP_INCLUDED=1
      for c in $g; do [ -f "$c" ] && SUP_CONF_FILES+=("$c"); done
    done
  fi
}

sup_status_line() { supervisorctl status "$1" 2>/dev/null | awk -v n="$1" '$1==n {print; exit}'; }
sup_state() { local l; l="$(sup_status_line "$1")"; [ -n "$l" ] && awk '{print $2}' <<<"$l" || echo MISSING; }
sup_pid() { sup_status_line "$1" | awk '{for(i=1;i<=NF;i++) if($i=="pid"){p=$(i+1); gsub(",","",p); print p; exit}}'; }
sup_uptime() { # seconds, from "uptime [N day[s],] H:MM:SS"
  sup_status_line "$1" | awk '{
    d=0; t="";
    for(i=1;i<=NF;i++){ if($i=="uptime"){ if($(i+2) ~ /^day/){ d=$(i+1); t=$(i+3) } else { t=$(i+1) } } }
    if(t==""){print 0; exit}
    n=split(t,a,":"); s=(n==3)? a[1]*3600+a[2]*60+a[3] : 0; print d*86400+s }'
}
sup_program_command() { # from config files
  local f
  for f in "${SUP_CONF_FILES[@]}"; do
    awk -v n="[program:$1]" '$0==n{f=1;next} /^\[/{f=0} f && /^command[[:space:]]*=/{sub(/^command[[:space:]]*=[[:space:]]*/,""); print; exit}' "$f"
  done | head -n 1
}
proc_args() { ps -o args= -p "$1" 2>/dev/null | head -n 1; }
proc_user() { ps -o user= -p "$1" 2>/dev/null | awk '{print $1}'; }
proc_tree_args() {
  ps -eo pid=,ppid=,args= 2>/dev/null | awk -v r="$1" '
    { pid=$1; pp=$2; $1=""; $2=""; sub(/^ +/,""); a[pid]=$0; p[pid]=pp; ids[NR]=pid }
    END { keep[r]=1; ch=1; while(ch){ ch=0; for(i=1;i<=NR;i++){ x=ids[i]; if(!(x in keep) && (p[x] in keep)){ keep[x]=1; ch=1 } } }
          for(k in keep) if(k in a) print k" "a[k] }'
}

declare -A SNAP_STATE=() SNAP_PID=()
snapshot_supervisor() { # fill SNAP_* with every non-Nexus program
  SNAP_STATE=(); SNAP_PID=()
  local name state rest pid
  while read -r name state rest; do
    [ -z "$name" ] && continue
    case "$name" in autofinx-nexus-*) continue ;; esac
    pid="$(awk '{for(i=1;i<=NF;i++) if($i=="pid"){p=$(i+1); gsub(",","",p); print p; exit}}' <<<"$rest")"
    SNAP_STATE["$name"]="$state"; SNAP_PID["$name"]="${pid:-}"
  done < <(supervisorctl status 2>/dev/null | grep -E '^[^[:space:]]+[[:space:]]+(RUNNING|STOPPED|STARTING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)')
}

env_occurrences() { # key -> raw lines (non-comment) in file
  grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$2" 2>/dev/null || true
}
env_raw_value() { # last occurrence, everything after '=' verbatim (quotes kept)
  env_occurrences "$1" "$2" | tail -n 1 | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=//"
}
env_value() { # last occurrence value, surrounding quotes stripped
  env_occurrences "$1" "$2" | tail -n 1 | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/^\"(.*)\"$/\1/; s/^'(.*)'$/\1/"
}

# Rewrite env content: NX_SET="K=V\n..." replaces every occurrence of K (or
# appends under the marker); NX_DEL="K1 K2" removes keys (+ our marker line);
# NX_RESTORE="file" restores the named keys to their exact lines from a backup.
env_render() { # $1 = source file, stdout = new content
  NX_SET="${NX_SET:-}" NX_DEL="${NX_DEL:-}" NX_MARKER="$ENV_MARKER" awk '
    BEGIN {
      n = split(ENVIRON["NX_SET"], arr, "\n"); cnt = 0
      for (i = 1; i <= n; i++) { if (arr[i] == "") continue; k = arr[i]; sub(/=.*/, "", k); want[k] = substr(arr[i], length(k) + 2); order[++cnt] = k }
      m = split(ENVIRON["NX_DEL"], d, " "); for (i = 1; i <= m; i++) if (d[i] != "") del[d[i]] = 1
      marker = ENVIRON["NX_MARKER"]
    }
    {
      line = $0
      if (line == marker && m > 0) next
      if (line ~ /^[[:space:]]*(export[[:space:]]+)?[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=/) {
        k = line; sub(/^[[:space:]]*(export[[:space:]]+)?/, "", k); sub(/[[:space:]]*=.*$/, "", k)
        if (k in del) next
        if (k in want) { print k "=" want[k]; seen[k] = 1; next }
      }
      print line
    }
    END {
      added = 0
      for (i = 1; i <= cnt; i++) { k = order[i]; if (!(k in seen)) { if (!added) { print ""; print marker; added = 1 } print k "=" want[k] } }
    }' "$1"
}

# Atomically replace $1 with the content of $2, preserving owner/group/mode of
# $1, then prove the app user can still read it (the 2026-09-24 incident).
write_env_atomically() {
  local target="$1" src="$2" before tmp
  before="$(stat_num "$target")" || return 1
  tmp="$(mktemp "$(dirname "$target")/.env.nexus-setup.XXXXXX")" || return 1
  ENV_TMP_PENDING="$tmp"
  cat "$src" > "$tmp" || return 1
  chown --reference="$target" "$tmp" && chmod --reference="$target" "$tmp" || return 1
  [ "$(stat_num "$tmp")" = "$before" ] || { log "temp file attributes $(stat_num "$tmp") != $before"; return 1; }
  if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled 2>/dev/null; then chcon --reference="$target" "$tmp" 2>/dev/null || true; fi
  mv -f "$tmp" "$target" || return 1
  ENV_TMP_PENDING=""
  [ "$(stat_num "$target")" = "$before" ] || { log ".env attributes changed to $(stat_num "$target") (expected $before)"; return 1; }
  as_app test -r "$target" || { log "$APP_USER cannot read $target after write"; return 1; }
  return 0
}

# ---------------------------------------------------------------------------
# Kill-pattern collision scan (runtime-master, dedupe, cron.d, crontab scripts)
# ---------------------------------------------------------------------------
declare -a KP_PATTERNS=() KP_SOURCES=()
collect_kill_patterns() {
  KP_PATTERNS=(); KP_SOURCES=()
  local -a files=() refs=()
  local f p rm_cmd rm_script
  rm_cmd="$(sup_program_command autofinx-runtime-master)"
  rm_script="$(awk '{for(i=1;i<=NF;i++) if($i ~ /\.sh$/){print $i; exit}}' <<<"$rm_cmd")"
  [ -n "$rm_script" ] && [ -f "$rm_script" ] && files+=("$rm_script")
  files+=("$APP_DIR/autofinx-runtime-master.sh" "$APP_DIR/autofinx-dedupe-runtime.sh")
  if [ -d /etc/cron.d ]; then for f in /etc/cron.d/*; do [ -f "$f" ] && files+=("$f"); done; fi
  # scripts referenced from cron.d files and the root/app-user crontabs
  mapfile -t refs < <( { cat /etc/cron.d/* 2>/dev/null; crontab -l 2>/dev/null; crontab -u "$APP_USER" -l 2>/dev/null; } \
      | grep -vE '^[[:space:]]*#' | grep -oE '(/[^[:space:]"'\'';|&]+\.sh)' | sort -u)
  for f in "${refs[@]}"; do [ -f "$f" ] && files+=("$f"); done
  mapfile -t files < <(printf '%s\n' "${files[@]}" | awk '!seen[$0]++')
  for f in "${files[@]}"; do
    [ -f "$f" ] || continue
    while IFS= read -r p; do
      [ -z "$p" ] && continue
      # "$pattern"-style captures come from helper function bodies; the literal
      # patterns are captured at their dedupe_* call sites instead.
      [[ "$p" == *'$'* ]] && continue
      KP_PATTERNS+=("$p"); KP_SOURCES+=("$f")
    done < <(grep -vE '^[[:space:]]*#' "$f" | {
        sed -nE 's/.*dedupe_(supervised|pattern)[[:space:]]+"[^"]*"[[:space:]]+"([^"]+)".*/\2/p'
      } ; grep -vE '^[[:space:]]*#' "$f" | sed -nE 's/.*(pkill|pgrep)([[:space:]]+-[A-Za-z]+)*[[:space:]]+"([^"$]+)".*/\3/p' ;
          grep -vE '^[[:space:]]*#' "$f" | sed -nE "s/.*(pkill|pgrep)([[:space:]]+-[A-Za-z]+)*[[:space:]]+'([^'\$]+)'.*/\\3/p" )
  done
  KP_FILES_SCANNED="${#files[@]}"
}
# check_kill_collisions "cmdline" ... -> records PASS/FAIL
check_kill_collisions() {
  local label="$1"; shift
  local i c hits="" rc
  for i in "${!KP_PATTERNS[@]}"; do
    for c in "$@"; do
      [ -z "$c" ] && continue
      printf '%s\n' "$c" | grep -Eq -- "${KP_PATTERNS[$i]}" 2>/dev/null; rc=$?
      if [ "$rc" -eq 0 ]; then hits="$hits [${KP_PATTERNS[$i]} @ ${KP_SOURCES[$i]}]"; fi
    done
  done
  if [ -n "$hits" ]; then
    fail "kill-pattern collision ($label)" "Nexus command matches an existing kill/dedupe pattern:$hits -- NOT proceeding; do not edit the watchdog blindly, report this."
  else
    pass "kill-pattern scan ($label)" "${#KP_PATTERNS[@]} pattern(s) from ${KP_FILES_SCANNED:-0} file(s); no match against Nexus command lines"
  fi
}

# ---------------------------------------------------------------------------
# Migration audit helpers
# ---------------------------------------------------------------------------
# Static lint: every timestamp/dateTime column must be nullable()/useCurrent()/
# default() -- MariaDB strict mode rejects NOT NULL TIMESTAMP w/o default (1067).
lint_migration() {
  local file="$1" bad
  bad="$(grep -nE -- '->(timestamp|timestampTz|dateTime|dateTimeTz)\(' "$file" | grep -vE 'nullable\(|useCurrent\(|default\(' || true)"
  [ -z "$bad" ] && return 0
  printf '%s\n' "$bad"
  return 1
}
# SQL lint over `migrate --pretend` output.
lint_pretend_sql() {
  local sql="$1" bad
  bad="$(printf '%s\n' "$sql" | grep -oiE '`[a-z0-9_]+` (timestamp|datetime)(\([0-9]\))? not null( default [^,)]+)?' | grep -viE ' default ' || true)"
  [ -z "$bad" ] && return 0
  printf '%s\n' "$bad"
  return 1
}

# ---------------------------------------------------------------------------
# PREFLIGHT
# ---------------------------------------------------------------------------
PRE_DB_DEFAULT=""
CONFIG_CACHED=0
DRIFT_KEYS=""
NEXUS_MIG_PENDING=0
WAIT_MIG_PENDING=0
WAIT_ELIGIBLE=0
SCHED_KIND="unknown"
SCHED_STATE="MISSING"
SCHED_LISTS_NEXUS="unknown"
SCHEDULE_OWNER=""
CRON_SHELL="/bin/sh"
CRON_LOCK=""
CRON_LOG=""
CRON_CMD=""
CRON_LINE=""
VERIFY_WS=""

preflight_host() {
  section "Preflight: host, user, PHP"
  log "nexus-production-setup $SCRIPT_VERSION mode=$MODE APP_DIR=$APP_DIR APP_USER=$APP_USER"
  if [ "$IS_ROOT" -eq 1 ]; then pass "root" "running as root"
  elif [ "$TEST_MODE" = "1" ]; then warn "root" "NOT root (NEXUS_SETUP_TEST_MODE=1 dev-box dry run; server-only checks are stubbed/limited)"
  else fail "root" "must run as root (sudo bash scripts/nexus-production-setup.sh)"; abort "not root"; fi

  local ent
  ent="$(getent passwd "$APP_USER" 2>/dev/null || true)"
  if [ -z "$ent" ]; then fail "app user" "$APP_USER does not exist"; abort "no app user"; fi
  APP_HOME="$(cut -d: -f6 <<<"$ent")"
  pass "app user" "$APP_USER (home $APP_HOME)"

  if command -v runuser >/dev/null 2>&1 && runuser -u "$APP_USER" -- true >/dev/null 2>&1; then AS_APP_METHOD=runuser
  elif command -v sudo >/dev/null 2>&1 && sudo -n -u "$APP_USER" -- true >/dev/null 2>&1; then AS_APP_METHOD=sudo
  else fail "switch to app user" "neither 'runuser -u' nor 'sudo -n -u' works for $APP_USER"; abort "cannot run as app user"; fi
  pass "switch to app user" "using $AS_APP_METHOD with a clean environment"

  if [ "$IS_ROOT" -eq 1 ]; then chown "$APP_USER:$APP_USER" "$LOG_FILE" 2>/dev/null || true; fi

  local owner
  owner="$(stat -c %U "$APP_DIR" 2>/dev/null || true)"
  if [ "$owner" = "$APP_USER" ]; then pass "APP_DIR owner" "$APP_DIR owned by $owner"
  else warn "APP_DIR owner" "$APP_DIR owned by '$owner' (expected $APP_USER)"; fi

  if detect_php; then
    pass "PHP >= 8.2" "$PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null))"
  else
    fail "PHP >= 8.2" "no PHP >= 8.2 found (tried PHP_BIN, /usr/local/bin/ea-php8x, /opt/cpanel/ea-php8x/root/usr/bin/php)"; abort "no php"
  fi
  if [ "$PHP_BIN" != "/usr/local/bin/ea-php82" ]; then
    warn "PHP path" "selected $PHP_BIN; existing AutoFinX Supervisor programs use /usr/local/bin/ea-php82"
  fi
  if as_app test -x "$PHP_BIN"; then pass "PHP executable by app user" "$PHP_BIN"; else fail "PHP executable by app user" "$APP_USER cannot execute $PHP_BIN"; fi

  local mem_kb
  mem_kb="$(awk '/^MemAvailable:/{print $2}' /proc/meminfo 2>/dev/null || true)"
  if [[ "$mem_kb" =~ ^[0-9]+$ ]]; then
    if [ "$mem_kb" -lt 524288 ]; then warn "memory headroom" "MemAvailable $((mem_kb / 1024)) MB (< 512 MB); two workers add ~100-250 MB"
    else pass "memory headroom" "MemAvailable $((mem_kb / 1024)) MB"; fi
  fi
  [ -r /proc/loadavg ] && info "load average" "$(cut -d' ' -f1-3 /proc/loadavg)"
}

preflight_env_file() {
  section "Preflight: .env ownership/readability (2026-09-24 incident guard)"
  resolve_env_file
  if [ ! -f "$ENV_FILE" ]; then fail ".env present" "$ENV_FILE missing"; abort "no .env"; fi
  [ "$ENV_FILE" != "$APP_DIR/.env" ] && info ".env symlink" "$APP_DIR/.env -> $ENV_FILE (edits go to the target; link kept)"
  local ugm
  ugm="$(stat_ugm "$ENV_FILE")"
  if [ "${ugm%% *}" = "$APP_USER:$APP_USER" ]; then pass ".env owner" "$ugm"
  else fail ".env owner" "$ugm (expected $APP_USER:$APP_USER). Fix first: chown $APP_USER:$APP_USER $ENV_FILE && chmod 600 $ENV_FILE"; fi
  if [ "${ugm##* }" = "600" ]; then pass ".env mode" "600"
  else warn ".env mode" "${ugm##* } (600 recommended; this script preserves whatever it finds)"; fi
  if as_app test -r "$ENV_FILE"; then pass ".env readable by $APP_USER" "ok"
  else fail ".env readable by $APP_USER" "NOT readable -- Laravel would silently fall back to sqlite"; fi
  if grep -q $'\r' "$ENV_FILE" 2>/dev/null; then fail ".env line endings" "CRLF detected; refusing to edit"; fi
  local d notw=""
  for d in storage/logs storage/framework storage/framework/cache storage/app bootstrap/cache; do
    as_app test -w "$APP_DIR/$d" || notw="$notw $d"
  done
  if [ -z "$notw" ]; then pass "app dirs writable" "storage/{logs,framework,framework/cache,app}, bootstrap/cache writable by $APP_USER"
  else fail "app dirs writable" "not writable by $APP_USER:$notw (config:cache / migrations / workers need them)"; fi
  if [ -f "$APP_DIR/bootstrap/cache/config.php" ]; then
    CONFIG_CACHED=1
    info "config cache" "present ($(stat_ugm "$APP_DIR/bootstrap/cache/config.php"))"
  else
    CONFIG_CACHED=0
    info "config cache" "absent -- config will NOT be cached by this script (caching would change raw env() behaviour)"
  fi
}

preflight_app() {
  section "Preflight: Laravel as $APP_USER"
  local out
  out="$(artisan --version 2>&1)"
  if grep -q "Laravel Framework" <<<"$out"; then pass "artisan as $APP_USER" "$out"
  else fail "artisan as $APP_USER" "$(head -c 300 <<<"$out")"; abort "artisan broken"; fi

  out="$(artisan list --raw 2>&1)"
  local c missing=""
  for c in nexus:run-cycle tinker migrate schedule:list; do
    grep -qE "^$c([[:space:]]|$)" <<<"$out" || missing="$missing $c"
  done
  # nexus:doctor / nexus:status are $aliases of NexusCommand (not shown by list --raw)
  for c in nexus:doctor nexus:status; do
    artisan help "$c" >/dev/null 2>&1 || missing="$missing $c"
  done
  if [ -z "$missing" ]; then pass "artisan commands" "nexus:run-cycle nexus:doctor nexus:status tinker schedule:list present"
  else fail "artisan commands" "missing:$missing (deploy the Nexus code first)"; abort "commands missing"; fi

  out="$(tinker_eval "$PHP_FACTS")"
  parse_facts <<<"$out"
  if [ "$(fact ok)" != "1" ]; then
    fail "app facts (tinker)" "tinker did not complete: $(printf '%s' "$out" | grep -v '^NX|' | tail -n 5 | tr '\n' ' ' | head -c 400)"
    abort "tinker failed"
  fi
  pass "app facts (tinker)" "app.env=$(fact app.env) url=$(fact app.url)"
  PRE_DB_DEFAULT="$(fact db.default)"
  if [ "$(fact db.ping)" = "ok" ]; then
    pass "DB connection (as $APP_USER)" "default=$(fact db.default) driver=$(fact db.driver) db=$(fact db.database) version=$(fact db.version)"
  else
    fail "DB connection (as $APP_USER)" "$(fact db.ping)"
  fi
  case "$(fact db.driver)" in
    mysql) pass "DB driver" "mysql (not the sqlite fallback)" ;;
    mariadb) warn "DB driver" "mariadb grammar: uuid() becomes native UUID (needs MariaDB >= 10.7); audited SQL assumed mysql grammar" ;;
    *) fail "DB driver" "'$(fact db.driver)' -- production must be mysql; refusing (sqlite here means .env was not read)" ;;
  esac
  info "DB sql_mode" "$(fact db.sql_mode) row_format=$(fact db.row_format)"

  case "$(fact redis.ping)" in
    ok) pass "Redis via app (nexus queue connection '$(fact queue.nexus.redis)')" "PING ok" ;;
    *) fail "Redis via app" "$(fact redis.ping)" ;;
  esac
  if [[ "$(fact queue.size.research)" =~ ^[0-9]+$ ]]; then
    pass "nexus queue reachable" "agent_research=$(fact queue.size.research) agent_training=$(fact queue.size.training)"
  else
    fail "nexus queue reachable" "$(fact queue.size.research)"
  fi
  if [ "$(fact queue.nexus.exists)" = "true" ] && [ "$(fact queue.nexus.driver)" = "redis" ]; then
    local ra; ra="$(fact queue.nexus.retry_after)"
    if [[ "$ra" =~ ^[0-9]+$ ]] && [ "$ra" -gt "$JOB_TIMEOUT" ] && [ "$ra" -gt "$STOPWAITSECS" ]; then
      pass "queue.connections.nexus" "driver=redis retry_after=$ra (> job timeout $JOB_TIMEOUT and > stopwaitsecs $STOPWAITSECS)"
    else
      fail "queue.connections.nexus" "retry_after='$ra' must exceed $JOB_TIMEOUT and $STOPWAITSECS"
    fi
  else
    fail "queue.connections.nexus" "missing or not redis (exists=$(fact queue.nexus.exists) driver=$(fact queue.nexus.driver))"
  fi
  case "$(fact cache.driver)" in
    redis|database|memcached|file|dynamodb) pass "cache store (locks)" "$(fact cache.store) driver=$(fact cache.driver)" ;;
    *) fail "cache store (locks)" "driver '$(fact cache.driver)' cannot provide cross-process locks (dispatch lock / onOneServer)" ;;
  esac
  if [ "$(fact nexus.llm.key_set)" = "true" ] || [ "$(fact nexus.llm.url_set)" = "true" ]; then
    warn "LLM credentials" "an LLM url/key is already configured; this script leaves it untouched but forces AUTOFINX_NEXUS_LLM_ENABLED=false and autonomy 0 (LLM path unreachable)"
  else
    pass "LLM credentials" "none configured (as intended for Phase 0)"
  fi
  local wc; wc="$(fact ws.count)"
  if [[ "$wc" =~ ^[0-9]+$ ]]; then
    if [ "$wc" -eq 0 ]; then warn "workspaces" "0 workspaces -- cycles have nothing to observe; verification cycle will be skipped"
    elif [ "$wc" -ge "${F[nexus.max_pending]:-100}" ]; then warn "workspaces" "$wc workspaces >= nexus.max_pending ($(fact nexus.max_pending)); one cycle request may hit the backlog limit"
    elif [ "$wc" -gt 50 ]; then warn "workspaces" "$wc workspaces; one serial research worker must finish all within 15 min"
    else pass "workspaces" "$wc"; fi
  fi
  VERIFY_WS="${WORKSPACE:-$(fact ws.oldest)}"
  info "current nexus config" "enabled=$(fact nexus.enabled) autonomy=$(fact nexus.autonomy) connection=$(fact nexus.connection) llm.enabled=$(fact nexus.llm.enabled)"
  info "scheduler flags" "autofinx.scheduler.enabled=$(fact sched.enabled) autofinx.runtime.supervisor=$(fact runtime.supervisor)"
}

preflight_redis_cli() {
  section "Preflight: redis-cli"
  if ! command -v redis-cli >/dev/null 2>&1; then warn "redis-cli PING" "redis-cli not installed (app-level PING above is authoritative)"; return 0; fi
  local raw j host port pass db path user out
  raw="$(tinker_eval "$PHP_SECRETS" | grep '^NXSECRET:' | head -n 1)"
  raw="${raw#NXSECRET:}"
  if [ -z "$raw" ]; then warn "redis-cli PING" "could not read redis connection settings"; return 0; fi
  # \x1f separator: non-whitespace IFS, so empty fields do not collapse
  j="$(printf '%s' "$raw" | "$PHP_BIN" -r '$j=json_decode(base64_decode(stream_get_contents(STDIN)),true)["redis"]??[]; foreach(["host","port","database","path","username","password"] as $k){ echo str_replace(["\n","\x1f"],"",(string)($j[$k]??"")),"\x1f"; }')"
  IFS=$'\x1f' read -r host port db path user pass <<<"$j"
  [ "$pass" = "null" ] && pass=""
  local -a args=()
  if [ -n "$path" ]; then args+=(-s "$path"); else args+=(-h "${host:-127.0.0.1}" -p "${port:-6379}"); fi
  [ -n "$db" ] && args+=(-n "$db")
  [ -n "$user" ] && [ "$user" != "null" ] && args+=(--user "$user")
  # REDISCLI_AUTH keeps the password out of the process list (never -a).
  out="$(REDISCLI_AUTH="$pass" timeout 10 redis-cli "${args[@]}" PING 2>&1)"
  unset pass j raw
  if grep -q PONG <<<"$out"; then pass "redis-cli PING" "PONG"; else warn "redis-cli PING" "$(head -c 200 <<<"$out")"; fi
}

preflight_git() {
  section "Preflight: git"
  local githead por audited bad=""
  if ! githead="$(as_app GIT_OPTIONAL_LOCKS=0 git -C "$APP_DIR" log -1 --format='%H %ci %s' 2>&1)" || [ -z "$githead" ]; then
    warn "git HEAD" "not a git checkout or git unavailable: $(head -c 200 <<<"$githead")"; return 0
  fi
  info "git HEAD" "$githead (branch $(as_app GIT_OPTIONAL_LOCKS=0 git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null))"
  por="$(as_app GIT_OPTIONAL_LOCKS=0 git -C "$APP_DIR" status --porcelain 2>/dev/null)"
  for audited in "$NEXUS_MIG_FILE" "$WAIT_MIG_FILE" config/nexus.php config/queue.php routes/console.php deploy/nexus-supervisor.conf app/Console/Commands/NexusCommand.php app/Domain/Nexus/NexusCycleService.php app/Jobs/RunNexusCycleJob.php; do
    grep -qF "$audited" <<<"$por" && bad="$bad $audited"
  done
  if [ -n "$bad" ]; then fail "git: audited files unmodified" "locally modified:$bad"
  else pass "git: audited files unmodified" "Nexus migration/config/queue/console/supervisor template unchanged vs HEAD"; fi
  local n; n="$(grep -c . <<<"$por" || true)"
  if [ "${n:-0}" -gt 0 ]; then warn "git status" "$n other modified/untracked path(s) (not blocking): $(head -n 8 <<<"$por" | tr '\n' ';')"
  else pass "git status" "clean"; fi
}

preflight_migrations() {
  section "Preflight: migration audit (MariaDB strict mode)"
  local sha out sql bad
  # --- Nexus migration ---
  if [ ! -f "$APP_DIR/$NEXUS_MIG_FILE" ]; then fail "Nexus migration file" "missing $NEXUS_MIG_FILE"; return; fi
  sha="$(sha256_of "$APP_DIR/$NEXUS_MIG_FILE")"
  if [ "$sha" = "$NEXUS_MIG_SHA256" ]; then pass "Nexus migration sha256" "matches audited $NEXUS_MIG_SHA256"
  else fail "Nexus migration sha256" "on-disk $sha != audited $NEXUS_MIG_SHA256 -- refusing (re-audit before changing the pin)"; fi
  if bad="$(lint_migration "$APP_DIR/$NEXUS_MIG_FILE")"; then pass "Nexus migration lint" "every timestamp column nullable (no MariaDB 1067 risk)"
  else fail "Nexus migration lint" "NOT NULL timestamp without default: $bad"; fi
  local st; st="$(fact mig.nexus)"
  if [ "$st" = "pending" ]; then
    NEXUS_MIG_PENDING=1
    local t present=""
    for t in "${NEXUS_TABLES[@]}"; do [ "$(fact "tbl.$t")" != "absent" ] && present="$present $t($(fact "tbl.$t"))"; done
    if [ -n "$present" ]; then fail "Nexus tables absent" "migration pending but tables already exist:$present -- a previous partial run? inspect manually"
    else pass "Nexus tables absent" "none of ${NEXUS_TABLES[*]} exist yet"; fi
    # FK compatibility: referenced ids must be char(36), same charset/collation as the new tables, InnoDB, PK.
    local want_cs want_co
    want_cs="$(fact db.charset)"; want_co="$(fact db.collation)"
    for t in workspaces users; do
      local v; v="$(fact "fk.$t")"
      IFS=',' read -r ct cs co ck en <<<"$v"
      if [ "$ct" = "char(36)" ] && [ "$cs" = "$want_cs" ] && [ "$co" = "$want_co" ] && [ "$ck" = "PRI" ] && [ "${en,,}" = "innodb" ]; then
        pass "FK target $t.id" "$v matches new tables ($want_cs/$want_co, InnoDB)"
      else
        fail "FK target $t.id" "'$v' vs required char(36),$want_cs,$want_co,PRI,InnoDB -- FK creation would fail (errno 150)"
      fi
    done
    out="$(artisan migrate --pretend --force --path="$NEXUS_MIG_FILE" 2>&1)"
    sql="$(grep -iE 'create table|alter table' <<<"$out" | sed -E 's/^[[:space:]]*[^a-zA-Z]*//')"
    if [ -z "$sql" ]; then fail "Nexus migrate --pretend" "no SQL produced: $(head -c 300 <<<"$out")"
    else
      info "Nexus migrate --pretend" "$(grep -c . <<<"$sql") statement(s):"
      printf '%s\n' "$sql" | emit
      if bad="$(lint_pretend_sql "$sql")"; then pass "Nexus pretend SQL lint" "no 'timestamp not null' without default"
      else fail "Nexus pretend SQL lint" "$bad"; fi
    fi
  elif [[ "$st" =~ ^[0-9]+$ ]]; then
    NEXUS_MIG_PENDING=0
    local t missing=""
    for t in "${NEXUS_TABLES[@]}"; do [ "$(fact "tbl.$t")" = "absent" ] && missing="$missing $t"; done
    if [ -n "$missing" ]; then fail "Nexus migration" "recorded as ran (batch $st) but tables missing:$missing"
    else pass "Nexus migration" "already ran (batch $st); step will be skipped"; fi
  else
    fail "Nexus migration status" "$st"
  fi
  out="$(artisan migrate:status 2>&1)"
  local pend; pend="$(grep -E 'Pending' <<<"$out" | awk '{print $1}' | tr '\n' ' ')"
  info "all pending migrations (NOT run by this script)" "${pend:-none}"

  # --- quick_trade_wait_shadows (owner's pending migration) ---
  st="$(fact mig.wait)"
  if [ ! -f "$APP_DIR/$WAIT_MIG_FILE" ]; then info "wait-shadows migration" "file not present"; return; fi
  if [ "$st" != "pending" ]; then info "wait-shadows migration" "status=$st (not pending); nothing to offer"; return; fi
  WAIT_MIG_PENDING=1
  sha="$(sha256_of "$APP_DIR/$WAIT_MIG_FILE")"
  local ok=1
  if [ "$sha" != "$WAIT_MIG_SHA256" ]; then ok=0; warn "wait-shadows sha256" "on-disk $sha != audited $WAIT_MIG_SHA256"; fi
  if ! grep -qE "timestamp\('reference_at'\)->nullable\(\)" "$APP_DIR/$WAIT_MIG_FILE"; then ok=0; warn "wait-shadows reference_at fix" "reference_at is not ->nullable(): this is the file that failed with MariaDB error 1067"; fi
  if ! bad="$(lint_migration "$APP_DIR/$WAIT_MIG_FILE")"; then ok=0; warn "wait-shadows lint" "$bad"; fi
  if [ "$(fact "tbl.$WAIT_TABLE")" != "absent" ]; then ok=0; warn "wait-shadows table" "$WAIT_TABLE already exists ($(fact "tbl.$WAIT_TABLE") rows) while migration pending -- inspect manually"; fi
  if [ "$ok" -eq 1 ]; then
    out="$(artisan migrate --pretend --force --path="$WAIT_MIG_FILE" 2>&1)"
    sql="$(grep -iE 'create table|alter table' <<<"$out" | sed -E 's/^[[:space:]]*[^a-zA-Z]*//')"
    if [ -n "$sql" ] && lint_pretend_sql "$sql" >/dev/null; then
      WAIT_ELIGIBLE=1
      if [ "$WITH_WAIT" -eq 1 ]; then pass "wait-shadows migration" "pending, pinned sha, reference_at nullable, pretend SQL clean -- WILL run (--with-wait-shadows)"
      else info "wait-shadows migration" "pending and safe (pinned sha + reference_at nullable). Not run unless --with-wait-shadows."; fi
      printf '%s\n' "$sql" | emit
    else
      warn "wait-shadows pretend" "pretend SQL missing or failed lint: $(head -c 300 <<<"$out")"
    fi
  elif [ "$WITH_WAIT" -eq 1 ]; then
    fail "wait-shadows migration" "--with-wait-shadows requested but file/table did not pass the audit above"
  fi
}

preflight_config_drift() {
  section "Preflight: config cache drift (non-Nexus keys)"
  if [ "$CONFIG_CACHED" -ne 1 ]; then skip "config drift" "no config cache present"; return; fi
  local a b fresh_cache
  fresh_cache="$APP_DIR/storage/framework/nexus-setup-nonexistent-$TS.php"
  a="$(tinker_eval "$PHP_CONFIG_HASH" | grep '^NXCFG:' | head -n 1)"
  b="$(tinker_eval "$PHP_CONFIG_HASH" "APP_CONFIG_CACHE=$fresh_cache" | grep '^NXCFG:' | head -n 1)"
  if [ -z "$a" ] || [ -z "$b" ]; then warn "config drift" "could not compute config hashes"; return; fi
  printf '%s' "${a#NXCFG:}" > "$TMP_WORK/cfg.cached"
  printf '%s' "${b#NXCFG:}" > "$TMP_WORK/cfg.fresh"
  cp "$TMP_WORK/cfg.cached" "$TMP_WORK/cfg.pre"
  DRIFT_KEYS="$(config_diff "$TMP_WORK/cfg.cached" "$TMP_WORK/cfg.fresh")"
  if [ -z "$DRIFT_KEYS" ]; then
    pass "config drift" "cached config == current .env for all non-Nexus keys; re-caching changes only nexus.*"
  elif [ "$ACCEPT_DRIFT" -eq 1 ]; then
    warn "config drift" "$(grep -c . <<<"$DRIFT_KEYS") non-Nexus key(s) differ and WILL become live on re-cache (accepted): $(head -n 30 <<<"$DRIFT_KEYS" | tr '\n' ' ')"
  else
    fail "config drift" "$(grep -c . <<<"$DRIFT_KEYS") non-Nexus key(s) in .env are NOT live in the config cache; re-caching would silently activate them: $(head -n 30 <<<"$DRIFT_KEYS" | tr '\n' ' ') -- review, then re-run with --accept-config-drift"
  fi
}
config_diff() { # two base64(json) hash maps -> differing keys
  A="$1" B="$2" "$PHP_BIN" -r '$a=json_decode(base64_decode(file_get_contents(getenv("A"))),true)?:[]; $b=json_decode(base64_decode(file_get_contents(getenv("B"))),true)?:[]; $d=[]; foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $k){ if(($a[$k]??null)!==($b[$k]??null)) $d[]=$k; } sort($d); echo implode("\n",$d);'
}

preflight_supervisor() {
  section "Preflight: Supervisor"
  if ! command -v supervisorctl >/dev/null 2>&1; then fail "supervisorctl" "not installed"; return; fi
  local st; st="$(supervisorctl status 2>&1)"
  if ! grep -qE '^[^[:space:]]+[[:space:]]+(RUNNING|STOPPED|STARTING|BACKOFF|EXITED|FATAL)' <<<"$st"; then
    fail "supervisord reachable" "$(head -c 300 <<<"$st")"; return
  fi
  pass "supervisord reachable" "$(supervisorctl version 2>/dev/null | head -n 1)"
  detect_supervisor
  if [ -z "$SUP_FILE" ]; then fail "Supervisor include dir" "neither /etc/supervisord.d nor /etc/supervisor/conf.d exists"; return; fi
  pass "Supervisor include dir" "$SUP_DIR (target $SUP_FILE)"
  if [ "$SUP_INCLUDED" -eq 1 ]; then pass "include glob covers target" "$SUP_MAIN [include] matches $SUP_FILE"
  elif [ -z "$SUP_MAIN" ]; then warn "include glob covers target" "main supervisord.conf not found; cannot prove $SUP_FILE is loaded (reread output is checked instead)"
  else fail "include glob covers target" "$SUP_MAIN [include] files= does not match $SUP_FILE"; fi
  snapshot_supervisor
  local n running=0 name
  for name in "${!SNAP_STATE[@]}"; do [ "${SNAP_STATE[$name]}" = "RUNNING" ] && running=$((running + 1)); done
  n="${#SNAP_STATE[@]}"
  info "existing programs" "$running/$n RUNNING (baseline snapshot saved for the post-check)"
  printf '%s\n' "$st" | emit
  # duplicate [program:autofinx-nexus-*] definitions in other files?
  local f dup=""
  for f in "${SUP_CONF_FILES[@]}"; do
    [ "$f" = "$SUP_FILE" ] && continue
    grep -qE '^\[program:autofinx-nexus-(research|training)\]' "$f" 2>/dev/null && dup="$dup $f"
  done
  if [ -n "$dup" ]; then fail "no duplicate Nexus program sections" "already defined in:$dup"
  else pass "no duplicate Nexus program sections" "only $SUP_FILE will define them"; fi
  local p
  for p in "${PROGRAMS[@]}"; do info "current state $p" "$(sup_state "$p")"; done
  if [ -d "$SUP_LOG_DIR" ]; then pass "log dir" "$SUP_LOG_DIR ($(stat_ugm "$SUP_LOG_DIR"))"; else info "log dir" "$SUP_LOG_DIR missing; will be created owned by $APP_USER"; fi
}

nexus_command_lines() { # prints every command string a kill pattern could see
  local p realphp
  realphp="$(readlink -f "$PHP_BIN" 2>/dev/null || echo "$PHP_BIN")"
  for p in "${PROGRAMS[@]}"; do
    printf '%s\n' "$PHP_BIN artisan ${PROGRAM_ARGS[$p]}"
    printf '%s\n' "/opt/cpanel/ea-php82/root/usr/bin/php artisan ${PROGRAM_ARGS[$p]}"
    [ "$realphp" != "$PHP_BIN" ] && printf '%s\n' "$realphp artisan ${PROGRAM_ARGS[$p]}"
  done
  [ -n "$CRON_CMD" ] && printf '%s\n' "flock -n $CRON_LOCK -c $CRON_CMD" "$CRON_CMD" "$PHP_BIN artisan nexus:run-cycle --no-interaction"
}

preflight_kill_patterns() {
  section "Preflight: watchdog kill/dedupe pattern collision scan"
  collect_kill_patterns
  local p
  for p in "${!KP_PATTERNS[@]}"; do _out "    pattern: ${KP_PATTERNS[$p]}   (${KP_SOURCES[$p]})"; done
  local -a cmds=()
  mapfile -t cmds < <(nexus_command_lines)
  check_kill_collisions "configured commands" "${cmds[@]}"
  if [ -d /etc/cron.d ] && grep -lE 'pkill|kill ' /etc/cron.d/* >/dev/null 2>&1; then
    info "/etc/cron.d kill jobs" "$(grep -lE 'pkill|kill ' /etc/cron.d/* 2>/dev/null | tr '\n' ' ') (patterns included above; worker stability is also verified live over ${SETTLE_SECONDS}s)"
  fi
}

preflight_scheduler() {
  section "Preflight: who would trigger nexus:run-cycle every 15 min"
  SCHED_STATE="$(sup_state autofinx-scheduler)"
  local conf_cmd pid tree=""
  conf_cmd="$(sup_program_command autofinx-scheduler)"
  info "autofinx-scheduler Supervisor state" "$SCHED_STATE"
  info "autofinx-scheduler config command" "${conf_cmd:-<no [program:autofinx-scheduler] section found in supervisor config files>}"
  pid="$(sup_pid autofinx-scheduler)"
  if [[ "$pid" =~ ^[0-9]+$ ]] && [ "$pid" -gt 0 ]; then
    tree="$(proc_tree_args "$pid")"
    info "autofinx-scheduler process tree" "$(tr '\n' ';' <<<"$tree" | head -c 600)"
  fi
  if grep -qE 'artisan[^;|&]*schedule:work' <<<"$tree $conf_cmd"; then SCHED_KIND="schedule:work"
  elif grep -qE 'artisan[^;|&]*schedule:run' <<<"$tree $conf_cmd"; then SCHED_KIND="schedule:run-loop"
  else
    # command may be a wrapper script: inspect it (text files only)
    local w
    for w in $conf_cmd; do
      if [[ "$w" == /* ]] && [ -f "$w" ] && grep -Iq . "$w" 2>/dev/null; then
        if grep -qE 'schedule:work' "$w"; then SCHED_KIND="schedule:work (via $w)"; break
        elif grep -qE 'schedule:run' "$w"; then SCHED_KIND="schedule:run-loop (via $w)"; break; fi
      fi
    done
  fi
  info "autofinx-scheduler kind" "$SCHED_KIND"

  # Would the Laravel schedule register nexus:run-cycle once enabled? (fresh
  # config with AUTOFINX_NEXUS_ENABLED=true injected; the real cache is untouched)
  local out
  out="$(as_app AUTOFINX_NEXUS_ENABLED=true "APP_CONFIG_CACHE=$APP_DIR/storage/framework/nexus-setup-nonexistent-$TS.php" timeout 120 "$PHP_BIN" artisan schedule:list --no-interaction --no-ansi 2>&1)"
  if grep -q 'nexus:run-cycle' <<<"$out"; then
    SCHED_LISTS_NEXUS="yes"
    info "schedule:list (predicted, nexus enabled)" "$(grep 'nexus:run-cycle' <<<"$out" | head -n 1 | sed -E 's/[[:space:]]+/ /g')"
  else
    SCHED_LISTS_NEXUS="no"
    info "schedule:list (predicted, nexus enabled)" "nexus:run-cycle NOT registered (autofinx.scheduler.enabled=$(fact sched.enabled))"
  fi

  local scheduler_ok=0
  if [ "$SCHED_STATE" = "RUNNING" ] && [[ "$SCHED_KIND" == schedule:work* || "$SCHED_KIND" == schedule:run-loop* ]] && [ "$SCHED_LISTS_NEXUS" = "yes" ]; then scheduler_ok=1; fi
  case "$SCHEDULE_CHOICE" in
    auto) if [ "$scheduler_ok" -eq 1 ]; then SCHEDULE_OWNER=scheduler; else SCHEDULE_OWNER=cron; fi ;;
    scheduler)
      SCHEDULE_OWNER=scheduler
      [ "$scheduler_ok" -eq 1 ] || fail "schedule owner" "--schedule=scheduler but autofinx-scheduler is $SCHED_STATE/$SCHED_KIND and schedule lists nexus=$SCHED_LISTS_NEXUS -- nothing would fire" ;;
    cron) SCHEDULE_OWNER=cron
      [ "$scheduler_ok" -eq 1 ] && warn "schedule owner" "--schedule=cron while autofinx-scheduler ALSO runs the Laravel schedule: both will fire; NexusCycleService slot dedup keeps it to one run per 15-min slot" ;;
  esac
  if [ "$SCHEDULE_OWNER" = "scheduler" ]; then
    pass "schedule owner" "Supervisor autofinx-scheduler ($SCHED_KIND) -- routes/console.php already registers nexus:run-cycle everyFifteenMinutes()->onOneServer()->withoutOverlapping(5); NO cron will be added"
  else
    pass "schedule owner" "dedicated flock'd $APP_USER cron ($CRON_SCHEDULE); Laravel schedule:run stays disabled"
  fi
}

preflight_cron() {
  section "Preflight: cron"
  if pgrep -x crond >/dev/null 2>&1 || pgrep -x cron >/dev/null 2>&1; then pass "cron daemon" "running"
  elif [ "$SCHEDULE_OWNER" = "cron" ]; then fail "cron daemon" "crond not running but cron is the chosen schedule owner"
  else warn "cron daemon" "crond not detected"; fi
  local ct
  ct="$(crontab -u "$APP_USER" -l 2>/dev/null || true)"
  CRON_SHELL="$(grep -m1 -E '^[[:space:]]*SHELL=' <<<"$ct" | sed -E 's/^[[:space:]]*SHELL=//; s/^"(.*)"$/\1/; s/^'\''(.*)'\''$/\1/')"
  [ -z "$CRON_SHELL" ] && CRON_SHELL="/bin/sh"
  info "$APP_USER crontab SHELL" "$CRON_SHELL"
  local ft; ft="$(grep -E 'future-trade-maintenance' <<<"$ct" | grep -vE '^[[:space:]]*#' | head -n 1)"
  info "existing maintenance cron" "${ft:-<none>}"
  local sr; sr="$(grep -E 'artisan[[:space:]]+schedule:run' <<<"$ct" | grep -vE '^[[:space:]]*#' || true)"
  if [ -n "$sr" ]; then warn "schedule:run cron" "an ACTIVE schedule:run line exists in $APP_USER crontab (owner believes it is disabled): $sr"
  else pass "schedule:run cron" "disabled/absent in $APP_USER crontab (left untouched)"; fi
  local nb; nb="$(grep -cF "$CRON_BEGIN" <<<"$ct" || true)"
  info "managed Nexus cron block" "present=${nb:-0}"
  if command -v cagefsctl >/dev/null 2>&1; then info "CageFS" "$(cagefsctl --user-status "$APP_USER" 2>/dev/null | head -n 1) (cron runs inside the cage; command is re-verified via $CRON_SHELL)"; fi
  for p in "$APP_DIR" "$PHP_BIN"; do
    if [[ "$p" =~ [[:space:]%\'\"] ]]; then fail "cron-safe paths" "'$p' contains space/%/quote; refusing to build a cron line"; fi
  done
  CRON_LOCK="$APP_DIR/storage/framework/nexus-run-cycle.cron.lock"
  CRON_LOG="$APP_DIR/storage/logs/nexus-cron.log"
  CRON_CMD="cd $APP_DIR && { date -u -Iseconds; timeout 300 $PHP_BIN artisan nexus:run-cycle --no-interaction; } >> $CRON_LOG 2>&1"
  CRON_LINE="$CRON_SCHEDULE flock -n $CRON_LOCK -c '$CRON_CMD'"
}

preflight_docroot() {
  local host ud dr
  host="$(sed -E 's#^[a-z]+://##; s#[:/].*$##' <<<"$(fact app.url)")"
  ud="/var/cpanel/userdata/$APP_USER/$host"
  [ -n "$host" ] && [ -f "$ud" ] || return 0
  dr="$(awk -F': ' '/^documentroot:/{print $2}' "$ud" 2>/dev/null)"
  if [ -n "$dr" ]; then
    if [ "$(readlink -f "$dr")" = "$(readlink -f "$APP_DIR/public")" ]; then pass "vhost documentroot" "$dr (backups under storage/ are not web-served)"
    else warn "vhost documentroot" "$host documentroot is $dr, not $APP_DIR/public -- verify storage/ is not web-reachable"; fi
  fi
}

run_preflight() {
  preflight_host
  preflight_env_file
  preflight_app
  preflight_redis_cli
  preflight_git
  preflight_migrations
  preflight_config_drift
  preflight_supervisor
  preflight_scheduler
  preflight_cron
  preflight_kill_patterns
  preflight_docroot
}

# ---------------------------------------------------------------------------
# PLAN (dry run)
# ---------------------------------------------------------------------------
supervisor_content() {
  local p q
  cat <<EOF
; Managed by scripts/nexus-production-setup.sh $SCRIPT_VERSION (generated $TS) from
; deploy/nexus-supervisor.conf. Dedicated Nexus research workers on the isolated
; 'nexus' Redis queue connection: never append these queues to trading workers,
; never add trading queues here. stopwaitsecs ($STOPWAITSECS) > job timeout ($JOB_TIMEOUT)
; and < queue.connections.nexus.retry_after (180), so a stop never double-runs a job.
EOF
  for p in "${PROGRAMS[@]}"; do
    q="${p#autofinx-}"
    cat <<EOF

[program:$p]
process_name=%(program_name)s
command=$PHP_BIN artisan ${PROGRAM_ARGS[$p]}
directory=$APP_DIR
user=$APP_USER
numprocs=1
autostart=true
autorestart=true
startsecs=5
startretries=10
stopasgroup=true
killasgroup=true
stopwaitsecs=$STOPWAITSECS
redirect_stderr=false
stdout_logfile=$SUP_LOG_DIR/$q.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=$SUP_LOG_DIR/$q.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
EOF
  done
}

env_set_payload() { local k; for k in "${ENV_KEYS[@]}"; do printf '%s=%s\n' "$k" "${ENV_WANT[$k]}"; done; }

print_plan() {
  section "PLAN (dry run -- nothing below has been executed)"
  local k cur occ
  plan "1. backups" "copy .env (+ config cache, crontab, supervisor file) to storage/app/deploy-backups/nexus-setup-$TS/ (700/600, $APP_USER-owned); mysqldump of migrations$( [ "$NEXUS_MIG_PENDING" -eq 1 ] || printf ' + nexus_*') via temporary --defaults-extra-file"
  if [ "$NEXUS_MIG_PENDING" -eq 1 ]; then plan "2. migration" "php artisan migrate --force --path=$NEXUS_MIG_FILE (as $APP_USER), BEFORE enabling Nexus"
  else plan "2. migration" "skip (already ran)"; fi
  if [ "$WITH_WAIT" -eq 1 ] && [ "$WAIT_ELIGIBLE" -eq 1 ]; then plan "2b. wait-shadows migration" "php artisan migrate --force --path=$WAIT_MIG_FILE"
  elif [ "$WAIT_MIG_PENDING" -eq 1 ]; then plan "2b. wait-shadows migration" "pending but NOT run ($( [ "$WAIT_ELIGIBLE" -eq 1 ] && echo 'eligible: add --with-wait-shadows' || echo 'not eligible, see preflight'))"; fi
  for k in "${ENV_KEYS[@]}"; do
    occ="$(env_occurrences "$k" "$ENV_FILE" | grep -c . || true)"
    cur="$(env_value "$k" "$ENV_FILE")"
    if [ "${occ:-0}" -eq 0 ]; then plan "3. .env $k" "add $k=${ENV_WANT[$k]}"
    elif [ "$cur" = "${ENV_WANT[$k]}" ] && [ "$occ" -eq 1 ]; then plan "3. .env $k" "unchanged (${ENV_WANT[$k]})"
    else plan "3. .env $k" "change '$cur' -> '${ENV_WANT[$k]}' ($occ occurrence(s))"; fi
  done
  plan "3. .env write" "atomic rename preserving $(stat_ugm "$ENV_FILE"); verify $APP_USER can read it + app still on '$PRE_DB_DEFAULT'"
  if [ "$CONFIG_CACHED" -eq 1 ]; then plan "3. config" "artisan config:clear && config:cache (as $APP_USER); verify only nexus.* changed"
  else plan "3. config" "artisan config:clear only (no cache existed before; not introducing one)"; fi
  plan "4. supervisor" "write $SUP_FILE, supervisorctl reread, supervisorctl update ${PROGRAMS[*]} (targeted; never a global update), verify RUNNING and stable for ${SETTLE_SECONDS}s"
  _out "    ---- $SUP_FILE ----"
  supervisor_content | sed 's/^/    | /' | while IFS= read -r l; do _out "$l"; done
  if [ "$SCHEDULE_OWNER" = "cron" ]; then
    plan "5. schedule" "add to $APP_USER crontab between markers:"
    _out "    | $CRON_BEGIN"; _out "    | $CRON_LINE"; _out "    | $CRON_END"
  else
    plan "5. schedule" "no cron: Supervisor autofinx-scheduler ($SCHED_KIND) fires nexus:run-cycle every 15 min once enabled; remove any old managed cron block"
  fi
  plan "6. verify" "nexus:doctor, nexus:status, one cycle for workspace ${VERIFY_WS:-<none>} (poll nexus_runs up to ${CYCLE_TIMEOUT}s), existing programs unchanged"
}

# ---------------------------------------------------------------------------
# APPLY steps
# ---------------------------------------------------------------------------
BACKUP_DIR=""
APPLY_STARTED=0
ENV_CHANGED=0

step_backups() {
  section "Step 1: backups"
  BACKUP_DIR="$APP_DIR/storage/app/deploy-backups/nexus-setup-$TS"
  mkdir -p "$APP_DIR/storage/app/deploy-backups" || { fail "backup dir" "cannot create"; abort "backup"; }
  mkdir -m 700 "$BACKUP_DIR" || { fail "backup dir" "cannot create $BACKUP_DIR"; abort "backup"; }
  cp -p "$ENV_FILE" "$BACKUP_DIR/env.before" && chmod 600 "$BACKUP_DIR/env.before" || { fail "backup .env" "copy failed"; abort "backup"; }
  cmp -s "$ENV_FILE" "$BACKUP_DIR/env.before" || { fail "backup .env" "verification mismatch"; abort "backup"; }
  pass "backup .env" "$BACKUP_DIR/env.before (sha256 $(sha256_of "$BACKUP_DIR/env.before" | head -c 12)...)"
  if [ "$CONFIG_CACHED" -eq 1 ]; then
    cp -p "$APP_DIR/bootstrap/cache/config.php" "$BACKUP_DIR/config.php.before" && chmod 600 "$BACKUP_DIR/config.php.before" \
      && pass "backup config cache" "$BACKUP_DIR/config.php.before" || { fail "backup config cache" "copy failed"; abort "backup"; }
  fi
  crontab -u "$APP_USER" -l > "$BACKUP_DIR/crontab.before" 2>/dev/null || : > "$BACKUP_DIR/crontab.before"
  chmod 600 "$BACKUP_DIR/crontab.before"; pass "backup crontab" "$BACKUP_DIR/crontab.before"
  if [ -f "$SUP_FILE" ]; then cp -p "$SUP_FILE" "$BACKUP_DIR/supervisor.before" && pass "backup supervisor file" "$BACKUP_DIR/supervisor.before"; fi

  if [ "$SKIP_DB_BACKUP" -eq 1 ]; then
    warn "mysqldump" "skipped by --skip-db-backup"
  else
    local -a tables=(migrations)
    local t
    for t in "${NEXUS_TABLES[@]}"; do [ "$(fact "tbl.$t")" != "absent" ] && tables+=("$t"); done
    [ "$WITH_WAIT" -eq 1 ] && [ "$(fact "tbl.$WAIT_TABLE")" != "absent" ] && tables+=("$WAIT_TABLE")
    if db_dump "$BACKUP_DIR/db-before.sql" "${tables[@]}"; then
      pass "mysqldump" "${tables[*]} -> $BACKUP_DIR/db-before.sql ($(wc -c < "$BACKUP_DIR/db-before.sql") bytes, 'Dump completed' trailer present)"
    else
      fail "mysqldump" "backup failed (see log); nothing has been changed yet"; abort "db backup failed"
    fi
  fi
  ( cd "$BACKUP_DIR" && sha256sum -- * > SHA256SUMS ) || true
  chown -R "$APP_USER:$APP_USER" "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR" && find "$BACKUP_DIR" -type f -exec chmod 600 {} +
  state_set_once BACKUP_DIR_ORIGINAL "$BACKUP_DIR"
  state_set_once ENV_BACKUP "$BACKUP_DIR/env.before"
  state_set_once ENV_BACKUP_SHA "$(sha256_of "$BACKUP_DIR/env.before")"
  state_set_once CONFIG_CACHE_EXISTED "$CONFIG_CACHED"
  state_set_once SUP_FILE_PREEXISTED "$([ -f "$SUP_FILE" ] && echo 1 || echo 0)"
  [ -f "$SUP_FILE" ] && state_set_once SUP_FILE_BACKUP "$BACKUP_DIR/supervisor.before"
  state_set APP_DIR "$APP_DIR"; state_set ENV_FILE "$ENV_FILE"; state_set SUP_FILE "$SUP_FILE"
  state_set LAST_APPLY_AT "$TS"; state_set LAST_BACKUP_DIR "$BACKUP_DIR"
}

# db_dump OUTFILE TABLE... -- credentials only via a 600 --defaults-extra-file in a 700 temp dir
db_dump() {
  local out="$1"; shift
  local dumpbin raw cnf rc
  dumpbin="$(command -v mysqldump || command -v mariadb-dump || true)"
  [ -z "$dumpbin" ] && { log "mysqldump/mariadb-dump not found"; return 1; }
  raw="$(tinker_eval "$PHP_SECRETS" | grep '^NXSECRET:' | head -n 1)"
  raw="${raw#NXSECRET:}"
  [ -z "$raw" ] && { log "could not read DB connection settings"; return 1; }
  cnf="$TMP_WORK/db.cnf"
  ( umask 077
    printf '%s' "$raw" | NX_OUT="$cnf" "$PHP_BIN" -r '
      $j = json_decode(base64_decode(stream_get_contents(STDIN)), true)["db"] ?? [];
      $q = function ($v) { $v = (string) $v; if (strpbrk($v, "\r\n") !== false) exit(3);
        if (strpos($v, "\"") === false) return "\"".str_replace("\\", "\\\\", $v)."\"";
        if (strpos($v, "'"'"'") === false && strpos($v, "\\") === false) return "'"'"'".$v."'"'"'";
        exit(4); };
      $s = "[client]\n"; $s .= "user=".$q($j["username"] ?? "")."\n"; $s .= "password=".$q($j["password"] ?? "")."\n";
      if (!empty($j["socket"])) { $s .= "socket=".$q($j["socket"])."\n"; } else { $s .= "host=".$q($j["host"] ?? "127.0.0.1")."\n"; if (!empty($j["port"])) $s .= "port=".(int) $j["port"]."\n"; }
      if (file_put_contents(getenv("NX_OUT"), $s) === false) exit(5); echo (string) ($j["database"] ?? "");
    ' > "$TMP_WORK/db.name" )
  rc=$?
  unset raw
  if [ "$rc" -ne 0 ]; then
    rm -f "$cnf"
    log "DB password cannot be expressed safely in a MySQL option file (rc=$rc); use --skip-db-backup and take a manual backup"
    return 1
  fi
  chmod 600 "$cnf"
  local dbname; dbname="$(cat "$TMP_WORK/db.name")"
  # HOME points at the private temp dir so root's ~/.my.cnf (cPanel keeps MySQL
  # root creds there, read AFTER --defaults-extra-file) cannot override the
  # app credentials; MYSQL_PWD is cleared for the same reason.
  ( umask 077; export HOME="$TMP_WORK"; unset MYSQL_PWD
    timeout 600 "$dumpbin" --defaults-extra-file="$cnf" --single-transaction --quick --skip-lock-tables \
      --no-tablespaces --hex-blob --default-character-set=utf8mb4 "$dbname" "$@" > "$out" 2> "$TMP_WORK/dump.err" )
  rc=$?
  rm -f "$cnf"
  if [ "$rc" -ne 0 ] || ! tail -n 2 "$out" | grep -q 'Dump completed'; then
    mask < "$TMP_WORK/dump.err" | head -n 20 | emit
    return 1
  fi
  return 0
}

migrate_path() { # $1 file, $2 name, $3 label -> records; returns 0 on success
  local file="$1" name="$2" label="$3" out batch
  local sha; sha="$(sha256_of "$APP_DIR/$file")"
  out="$(artisan migrate --force --path="$file" 2>&1)"; local rc=$?
  printf '%s\n' "$out" | emit
  batch="$(tinker_eval "echo 'NX|b|'.(string) Illuminate\\Support\\Facades\\DB::table('migrations')->where('migration', '$name')->value('batch').PHP_EOL;" | awk -F'|' '$1=="NX" && $2=="b"{print $3}')"
  if [ "$rc" -eq 0 ] && [[ "$batch" =~ ^[0-9]+$ ]]; then
    pass "$label" "ran (batch $batch, file sha $sha)"
    MIG_BATCH="$batch"
    return 0
  fi
  fail "$label" "migrate exited $rc; recorded batch='${batch:-none}'"
  MIG_BATCH=""
  return 1
}

step_migrations() {
  section "Step 2: migrations (before enabling Nexus)"
  local sha
  if [ "$NEXUS_MIG_PENDING" -eq 1 ]; then
    sha="$(sha256_of "$APP_DIR/$NEXUS_MIG_FILE")"
    [ "$sha" = "$NEXUS_MIG_SHA256" ] || { fail "Nexus migration sha256 (re-check)" "$sha"; abort "migration file changed"; }
    if migrate_path "$NEXUS_MIG_FILE" "$NEXUS_MIG_NAME" "Nexus migration"; then
      state_set_once NEXUS_MIGRATION_BATCH "$MIG_BATCH"
      local chk
      chk="$(tinker_eval "foreach (['nexus_runs','nexus_entries','nexus_reviews','nexus_model_calls'] as \$t) { echo 'NX|t.'.\$t.'|'.(Illuminate\\Support\\Facades\\Schema::hasTable(\$t) ? 'yes' : 'no').PHP_EOL; } echo 'NX|cols|'.(Illuminate\\Support\\Facades\\Schema::hasColumns('nexus_runs', ['id','workspace_id','slot','kind','status','started_at','finished_at','summary','error_code']) ? 'yes' : 'no').PHP_EOL;")"
      if grep -q 'NX|t\..*|no' <<<"$chk" || ! grep -q 'NX|cols|yes' <<<"$chk"; then fail "Nexus tables verified" "$(grep '^NX|' <<<"$chk" | tr '\n' ' ')"; abort "tables missing after migrate"
      else pass "Nexus tables verified" "4 tables + nexus_runs columns present"; fi
    else
      # MySQL DDL is not transactional: drop ONLY Nexus tables that did not exist
      # before this run, are empty, and whose migration is NOT recorded.
      local drop
      drop="$(tinker_eval "if (Illuminate\\Support\\Facades\\DB::table('migrations')->where('migration', '$NEXUS_MIG_NAME')->exists()) { echo 'NX|drop|recorded'.PHP_EOL; return; } foreach (['nexus_model_calls','nexus_reviews','nexus_entries','nexus_runs'] as \$t) { if (Illuminate\\Support\\Facades\\Schema::hasTable(\$t)) { if (Illuminate\\Support\\Facades\\DB::table(\$t)->count() === 0) { Illuminate\\Support\\Facades\\Schema::drop(\$t); echo 'NX|drop|'.\$t.PHP_EOL; } else { echo 'NX|keep|'.\$t.PHP_EOL; } } }")"
      warn "partial migration cleanup" "$(grep '^NX|' <<<"$drop" | tr '\n' ' ') (tables pre-existing before this run: none, verified in preflight)"
      abort "Nexus migration failed; Nexus was NOT enabled"
    fi
  else
    skip "Nexus migration" "already ran (batch $(fact mig.nexus))"
  fi
  if [ "$WITH_WAIT" -eq 1 ]; then
    if [ "$WAIT_ELIGIBLE" -eq 1 ]; then
      sha="$(sha256_of "$APP_DIR/$WAIT_MIG_FILE")"
      [ "$sha" = "$WAIT_MIG_SHA256" ] || { fail "wait-shadows sha256 (re-check)" "$sha"; abort "migration file changed"; }
      if migrate_path "$WAIT_MIG_FILE" "$WAIT_MIG_NAME" "wait-shadows migration"; then
        state_set_once WAIT_MIGRATION_BATCH "$MIG_BATCH"
      else
        abort "wait-shadows migration failed (Nexus migration, if run, is intact; Nexus not yet enabled)"
      fi
    else
      skip "wait-shadows migration" "not eligible (see preflight)"
    fi
  fi
}

verify_cached_app() { # prints facts after a config change; returns 1 if the app is broken
  local out
  out="$(tinker_eval "$PHP_FACTS")"
  F=(); parse_facts <<<"$out"
  [ "$(fact ok)" = "1" ] || return 1
  [ "$(fact db.default)" = "$PRE_DB_DEFAULT" ] || return 1
  [ "$(fact db.ping)" = "ok" ] || return 1
  return 0
}

rebuild_config() { # $1 = 1 to cache
  local out rc
  out="$(artisan config:clear 2>&1)"; rc=$?
  printf '%s\n' "$out" | emit
  [ "$rc" -eq 0 ] || return 1
  if [ "$1" = "1" ]; then
    out="$(artisan config:cache 2>&1)"; rc=$?
    printf '%s\n' "$out" | emit
    [ "$rc" -eq 0 ] || return 1
    [ "$(stat -c %U "$APP_DIR/bootstrap/cache/config.php" 2>/dev/null)" = "$APP_USER" ] || { log "config.php not owned by $APP_USER"; return 1; }
  fi
  return 0
}

restore_env_from() { # $1 = backup file; used on failure
  local tmpc="$TMP_WORK/env.restore"
  cp "$1" "$tmpc" && write_env_atomically "$ENV_FILE" "$tmpc" && rebuild_config "$CONFIG_CACHED"
}

step_env() {
  section "Step 3: .env + config"
  local newc="$TMP_WORK/env.new"
  NX_SET="$(env_set_payload)" NX_DEL="" env_render "$ENV_FILE" > "$newc" || { fail ".env render" "awk failed"; abort "env render"; }
  if cmp -s "$ENV_FILE" "$newc"; then
    skip ".env" "all Nexus Phase-0 keys already set; file not rewritten"
    ENV_CHANGED=0
  else
    local keyre; keyre="^[[:space:]]*(export[[:space:]]+)?($(IFS='|'; echo "${ENV_KEYS[*]}"))[[:space:]]*="
    diff <(grep -E "$keyre" "$ENV_FILE") <(grep -E "$keyre" "$newc") | emit
    if ! write_env_atomically "$ENV_FILE" "$newc"; then
      fail ".env write" "atomic write/verification failed; restoring backup"
      restore_env_from "$BACKUP_DIR/env.before" || fail ".env restore" "RESTORE FAILED -- restore manually: cp -p $BACKUP_DIR/env.before $ENV_FILE && chown $APP_USER:$APP_USER $ENV_FILE && chmod 600 $ENV_FILE"
      abort ".env write failed"
    fi
    ENV_CHANGED=1
    pass ".env write" "$(stat_ugm "$ENV_FILE") preserved; readable by $APP_USER"
  fi
  local k bad=""
  for k in "${ENV_KEYS[@]}"; do [ "$(env_value "$k" "$ENV_FILE")" = "${ENV_WANT[$k]}" ] || bad="$bad $k"; done
  [ -z "$bad" ] && pass ".env values" "$(env_set_payload | tr '\n' ' ')" || { fail ".env values" "mismatch:$bad"; restore_env_from "$BACKUP_DIR/env.before"; abort ".env values"; }
  state_set ENV_POST_SHA "$(sha256_of "$ENV_FILE")"

  if ! rebuild_config "$CONFIG_CACHED"; then
    fail "config rebuild" "config:clear/config:cache failed as $APP_USER; restoring .env + config"
    restore_env_from "$BACKUP_DIR/env.before"
    abort "config rebuild failed"
  fi
  pass "config rebuild" "$( [ "$CONFIG_CACHED" -eq 1 ] && echo "config:clear + config:cache as $APP_USER ($(stat_ugm "$APP_DIR/bootstrap/cache/config.php"))" || echo "config:clear only (no cache before)")"

  if ! verify_cached_app; then
    fail "app after config change" "app broken or DB default changed (now '$(fact db.default)', was '$PRE_DB_DEFAULT'); restoring"
    restore_env_from "$BACKUP_DIR/env.before"
    abort "app broken after config change"
  fi
  if [ "$(fact nexus.enabled)" = "true" ] && [ "$(fact nexus.autonomy)" = "0" ] && [ "$(fact nexus.connection)" = "nexus" ] && [ "$(fact nexus.llm.enabled)" = "false" ]; then
    pass "effective nexus config" "enabled=true autonomy=0 connection=nexus llm.enabled=false; DB default still $(fact db.default), DB+Redis ping ok"
  else
    fail "effective nexus config" "enabled=$(fact nexus.enabled) autonomy=$(fact nexus.autonomy) connection=$(fact nexus.connection) llm=$(fact nexus.llm.enabled); restoring"
    restore_env_from "$BACKUP_DIR/env.before"; abort "effective config wrong"
  fi
  if [ "$CONFIG_CACHED" -eq 1 ] && [ -f "$TMP_WORK/cfg.pre" ]; then
    local post diff unexpected
    post="$(tinker_eval "$PHP_CONFIG_HASH" | grep '^NXCFG:' | head -n 1)"
    printf '%s' "${post#NXCFG:}" > "$TMP_WORK/cfg.post"
    diff="$(config_diff "$TMP_WORK/cfg.pre" "$TMP_WORK/cfg.post")"
    unexpected="$(comm -23 <(printf '%s\n' "$diff" | sed '/^$/d' | sort) <(printf '%s\n' "$DRIFT_KEYS" | sed '/^$/d' | sort))"
    if [ -z "$unexpected" ]; then pass "non-Nexus config unchanged" "only nexus.*$( [ -n "$DRIFT_KEYS" ] && echo " + accepted drift keys") changed in the cache"
    else fail "non-Nexus config unchanged" "unexpected changes: $(head -n 20 <<<"$unexpected" | tr '\n' ' '); restoring"; restore_env_from "$BACKUP_DIR/env.before"; abort "unexpected config change"; fi
  fi
}

step_supervisor() {
  section "Step 4: Supervisor workers"
  local newf="$TMP_WORK/supervisor.new" changed=1 p out
  supervisor_content > "$newf"
  if [ -f "$SUP_FILE" ] && cmp -s <(grep -vE '^; Managed by' "$SUP_FILE") <(grep -vE '^; Managed by' "$newf"); then changed=0; fi
  if [ ! -d "$SUP_LOG_DIR" ]; then
    mkdir -p "$SUP_LOG_DIR" && chown "$APP_USER:$APP_USER" "$SUP_LOG_DIR" && pass "log dir" "created $SUP_LOG_DIR ($APP_USER)" || { fail "log dir" "cannot create $SUP_LOG_DIR"; abort "log dir"; }
  fi
  if [ "$changed" -eq 1 ]; then
    local tmp; tmp="$(mktemp "$SUP_DIR/.autofinx-nexus.XXXXXX")" || { fail "supervisor file" "mktemp failed"; abort "sup"; }
    cat "$newf" > "$tmp" && chmod 644 "$tmp" && mv -f "$tmp" "$SUP_FILE" || { rm -f "$tmp"; fail "supervisor file" "write failed"; abort "sup"; }
    pass "supervisor file" "wrote $SUP_FILE"
  else
    skip "supervisor file" "$SUP_FILE already up to date"
  fi
  state_set SUP_FILE_WRITTEN 1
  out="$(supervisorctl reread 2>&1)"
  printf '%s\n' "$out" | emit
  if grep -qiE 'error' <<<"$out"; then fail "supervisorctl reread" "$(head -c 300 <<<"$out")"; abort "reread error"; fi
  local others; others="$(grep -E ':[[:space:]]*(available|changed|disappeared)' <<<"$out" | grep -vE '^autofinx-nexus-(research|training):' || true)"
  if [ -n "$others" ]; then warn "other pending supervisor changes" "NOT applied by this script (targeted update only): $(tr '\n' ' ' <<<"$others")"; fi
  out="$(supervisorctl update "${PROGRAMS[@]}" 2>&1)"
  printf '%s\n' "$out" | emit
  if grep -qiE 'error' <<<"$out"; then fail "supervisorctl update (targeted)" "$(head -c 300 <<<"$out")"; abort "update error"; fi
  for p in "${PROGRAMS[@]}"; do
    # fallback for supervisor builds where targeted `update` does not add new groups
    if [ "$(sup_state "$p")" = "MISSING" ]; then supervisorctl add "$p" 2>&1 | emit; fi
  done
  pass "supervisorctl update (targeted)" "${PROGRAMS[*]}"
  # Programs whose config did not change but env did: restart ONLY Nexus workers (never queue:restart).
  for p in "${PROGRAMS[@]}"; do
    if [ "$changed" -eq 0 ] && [ "$ENV_CHANGED" -eq 1 ] && [ "$(sup_state "$p")" = "RUNNING" ]; then
      supervisorctl restart "$p" 2>&1 | emit; info "restart $p" "env changed; restarted this Nexus worker only"
    elif [ "$(sup_state "$p")" != "RUNNING" ] && [ "$(sup_state "$p")" != "STARTING" ]; then
      supervisorctl start "$p" 2>&1 | emit
    fi
  done
  verify_workers_stable
}

declare -A WORKER_PID=()
verify_workers_stable() {
  local p i st pid args user
  for p in "${PROGRAMS[@]}"; do
    st=""
    for i in $(seq 1 40); do st="$(sup_state "$p")"; [ "$st" = "RUNNING" ] && break; sleep 1; done
    if [ "$st" != "RUNNING" ]; then
      fail "$p RUNNING" "state=$st after 40s; stderr tail:"; tail -n 20 "$SUP_LOG_DIR/${p#autofinx-}.err.log" 2>/dev/null | emit
      continue
    fi
    pid="$(sup_pid "$p")"; WORKER_PID["$p"]="$pid"
    args="$(proc_args "$pid")"; user="$(proc_user "$pid")"
    if grep -qF -- "--queue=${PROGRAM_QUEUE[$p]}" <<<"$args" && grep -q 'queue:work nexus' <<<"$args" && [ "$user" = "$APP_USER" ]; then
      pass "$p RUNNING" "pid $pid user $user: $args"
    else
      fail "$p RUNNING" "pid $pid user '$user' args '$args' do not match expected"
    fi
  done
  # live kill-pattern re-check against the real process command lines
  local -a live=()
  for p in "${PROGRAMS[@]}"; do [ -n "${WORKER_PID[$p]:-}" ] && live+=("$(proc_args "${WORKER_PID[$p]}")"); done
  [ "${#live[@]}" -gt 0 ] && check_kill_collisions "live worker args" "${live[@]}"
  log "Stability window: watching for ${SETTLE_SECONDS}s (runtime-master runs every 5s; per-minute cron guards get at least one pass)..."
  local waited=0 checkpoint=20
  while [ "$waited" -lt "$SETTLE_SECONDS" ]; do
    sleep 5; waited=$((waited + 5))
    if [ "$waited" -ge "$checkpoint" ] && [ "$checkpoint" -gt 0 ]; then
      for p in "${PROGRAMS[@]}"; do log "  t+${waited}s $p: $(sup_status_line "$p" | sed -E 's/[[:space:]]+/ /g')"; done
      checkpoint=$((checkpoint + 20))
    fi
  done
  for p in "${PROGRAMS[@]}"; do
    [ -z "${WORKER_PID[$p]:-}" ] && continue
    st="$(sup_state "$p")"; pid="$(sup_pid "$p")"
    local up; up="$(sup_uptime "$p")"
    if [ "$st" = "RUNNING" ] && [ "$pid" = "${WORKER_PID[$p]}" ] && [ "${up:-0}" -ge 15 ]; then
      pass "$p stable" "same pid $pid, uptime ${up}s after ${SETTLE_SECONDS}s window (not flapping, not killed)"
    elif [ "$st" = "RUNNING" ]; then
      warn "$p stable" "restarted during window (pid ${WORKER_PID[$p]} -> $pid, uptime ${up}s): a queue:restart broadcast or a killer; check $SUP_LOG_DIR/${p#autofinx-}.err.log and re-run --verify"
    else
      fail "$p stable" "state=$st after window"; tail -n 20 "$SUP_LOG_DIR/${p#autofinx-}.err.log" 2>/dev/null | emit
    fi
  done
}

cron_current() { crontab -u "$APP_USER" -l 2>/dev/null || true; }
cron_without_block() { awk -v b="$CRON_BEGIN" -v e="$CRON_END" '$0==b{skip=1;next} $0==e{skip=0;next} !skip{print}'; }
cron_install() { # $1 = file content
  crontab -u "$APP_USER" "$1"
}

step_schedule() {
  section "Step 5: 15-minute trigger ($SCHEDULE_OWNER)"
  local cur new out
  cur="$(cron_current)"
  if [ "$SCHEDULE_OWNER" = "scheduler" ]; then
    out="$(artisan schedule:list 2>&1)"
    if grep -q 'nexus:run-cycle' <<<"$out"; then pass "schedule:list" "$(grep 'nexus:run-cycle' <<<"$out" | head -n 1 | sed -E 's/[[:space:]]+/ /g')"
    else fail "schedule:list" "nexus:run-cycle not registered after enabling"; fi
    local sst; sst="$(sup_state autofinx-scheduler)"
    [ "$sst" = "RUNNING" ] && pass "autofinx-scheduler" "RUNNING ($SCHED_KIND) -- sole owner" || fail "autofinx-scheduler" "state $sst"
    if grep -qF "$CRON_BEGIN" <<<"$cur"; then
      new="$TMP_WORK/crontab.new"; cron_without_block <<<"$cur" > "$new"
      cron_install "$new" && pass "managed cron block" "removed (scheduler is the single owner)" || fail "managed cron block" "removal failed"
    else
      skip "managed cron block" "none present; no cron added"
    fi
    state_set SCHEDULE_OWNER scheduler
    return
  fi
  new="$TMP_WORK/crontab.new"
  { cron_without_block <<<"$cur"; printf '%s\n%s\n%s\n' "$CRON_BEGIN" "$CRON_LINE" "$CRON_END"; } > "$new"
  if [ "$(cron_current)" = "$(cat "$new")" ]; then
    skip "cron block" "already installed and identical"
  else
    cron_install "$new" || { fail "cron install" "crontab -u $APP_USER failed"; abort "cron"; }
  fi
  cur="$(cron_current)"
  local nb ne
  nb="$(grep -cxF "$CRON_BEGIN" <<<"$cur" || true)"; ne="$(grep -cxF "$CRON_END" <<<"$cur" || true)"
  if [ "$nb" = "1" ] && [ "$ne" = "1" ] && grep -qxF "$CRON_LINE" <<<"$cur" && [ "$(grep -cF 'nexus:run-cycle' <<<"$cur")" = "1" ]; then
    pass "cron block" "exactly one managed nexus:run-cycle line in $APP_USER crontab"
  else
    fail "cron block" "read-back mismatch (begin=$nb end=$ne)"
  fi
  local other; other="$(cron_without_block <<<"$cur")"
  if [ "$other" = "$(cron_without_block < "$BACKUP_DIR/crontab.before")" ]; then pass "other cron lines" "unchanged vs backup"
  else fail "other cron lines" "changed vs $BACKUP_DIR/crontab.before"; fi
  state_set CRON_INSTALLED 1
  state_set SCHEDULE_OWNER cron
  # Prove the exact cron command works for the app user under the crontab SHELL.
  local sh="$CRON_SHELL" rc
  [ -x "$sh" ] || sh=/bin/sh
  out="$(as_app timeout 330 "$sh" -c "flock -n $CRON_LOCK -c '$CRON_CMD'" 2>&1)"; rc=$?
  if [ "$rc" -ne 0 ] && [ "$sh" != "/bin/bash" ]; then
    warn "cron command via $sh" "exit $rc ($(head -c 200 <<<"$out")); retrying with /bin/bash"
    out="$(as_app timeout 330 /bin/bash -c "flock -n $CRON_LOCK -c '$CRON_CMD'" 2>&1)"; rc=$?
  fi
  if [ "$rc" -eq 0 ] && tail -n 3 "$CRON_LOG" 2>/dev/null | grep -q 'Nexus cycles requested'; then
    pass "cron command dry-fire" "ran as $APP_USER; $CRON_LOG: $(tail -n 1 "$CRON_LOG")"
  else
    fail "cron command dry-fire" "exit $rc; log tail: $(tail -n 3 "$CRON_LOG" 2>/dev/null | tr '\n' ' ')"
  fi
}

step_verify() { # shared by --apply (with dispatch) and --verify (read-only)
  local dispatch="$1"
  section "Post-verify"
  local out rc
  for c in nexus:doctor nexus:status; do
    out="$(artisan "$c" 2>&1)"; rc=$?
    printf '%s\n' "$out" | emit
    if [ "$rc" -eq 0 ] && grep -qE '"enabled": ?true' <<<"$out" && grep -qE '"database_ready": ?true' <<<"$out" \
       && grep -qE '"retry_after_safe": ?true' <<<"$out" && grep -qE '"connection": ?"nexus"' <<<"$out" && grep -qE '"autonomy": ?0' <<<"$out"; then
      pass "$c" "enabled, database_ready, retry_after_safe, connection=nexus, autonomy=0"
    else
      fail "$c" "exit $rc or unexpected JSON"
    fi
    grep -qE '"provider_configured": ?false' <<<"$out" || warn "$c provider" "provider_configured is not false"
    if grep -qE '"allow_live_trading": ?false' <<<"$out" && grep -qE '"allow_risk_override": ?false' <<<"$out" && grep -qE '"allow_deploy": ?false' <<<"$out"; then
      pass "$c safety" "allow_live_trading/allow_risk_override/allow_deploy all false"
    else
      fail "$c safety" "safety block does not report execution permissions as false"
    fi
  done
  local p st
  for p in "${PROGRAMS[@]}"; do
    st="$(sup_state "$p")"
    [ "$st" = "RUNNING" ] && pass "$p" "RUNNING (uptime $(sup_uptime "$p")s)" || fail "$p" "state $st"
  done

  if [ "$dispatch" = "1" ]; then
    if [ -z "$VERIFY_WS" ]; then
      warn "verification cycle" "no workspace; skipped"
    else
      if [ "$SCHEDULE_OWNER" = "cron" ]; then
        info "verification cycle" "already requested by the cron command dry-fire (all workspaces); tracking workspace $VERIFY_WS"
      else
        out="$(artisan nexus:run-cycle "$VERIFY_WS" 2>&1)"; rc=$?
        printf '%s\n' "$out" | emit
        [ "$rc" -eq 0 ] || fail "nexus:run-cycle $VERIFY_WS" "exit $rc"
      fi
      out="$(tinker_eval "$PHP_RUN_LOOKUP" "NX_WS=$VERIFY_WS")"
      F=(); parse_facts <<<"$out"
      local run; run="$(fact run.id)"
      state_set_once SETUP_SLOT "$(fact slot.now)"
      if [ -z "$run" ]; then
        fail "nexus_runs row" "no run for workspace $VERIFY_WS in slot $(fact slot.now)"
      else
        info "nexus_runs row" "run $run slot $(fact run.slot) status $(fact run.status) (a same-slot run may be reused)"
        local waited=0 s=""
        while [ "$waited" -le "$CYCLE_TIMEOUT" ]; do
          out="$(tinker_eval "$PHP_RUN_STATUS" "NX_RUN=$run")"; F=(); parse_facts <<<"$out"
          s="$(fact run.status)"
          case "$s" in completed|partial|failed|skipped|cancelled|missing) break ;; esac
          sleep 5; waited=$((waited + 5))
        done
        case "$s" in
          completed|partial) pass "cycle $run" "$s in ~${waited}s; summary=$(fact run.summary); entries=$(fact run.entries)" ;;
          *) fail "cycle $run" "status '$s' error '$(fact run.error)' after ${waited}s; worker log: $SUP_LOG_DIR/nexus-research.err.log" ;;
        esac
      fi
    fi
  else
    out="$(tinker_eval "$PHP_VERIFY_RUNS" "NX_AFTER_SLOT=$(state_get SETUP_SLOT)")"; F=(); parse_facts <<<"$out"
    if [ "$(fact ok)" != "1" ]; then
      fail "nexus_runs query" "$(grep -v '^NX|' <<<"$out" | grep -v '^[[:space:]]*$' | head -n 2 | tr '\n' ' ' | head -c 300)"
      return
    fi
    info "runs last 24h" "$(fact v.by_status); last slot $(fact v.last_slot) status $(fact v.last_status) age $(fact v.last_age_s)s"
    local fj; fj="$(fact v.failed_jobs)"
    [ "$fj" = "0" ] && pass "failed RunNexusCycleJob (24h)" "0" || warn "failed RunNexusCycleJob (24h)" "$fj"
    local ls; ls="$(fact v.later_slots)"
    local applied; applied="$(state_get LAST_APPLY_AT)"
    if [[ "$ls" =~ ^[0-9]+$ ]] && [ "$ls" -gt 0 ]; then pass "15-min cadence" "$ls slot(s) ran after the setup slot $(state_get SETUP_SLOT) -- the trigger fires"
    elif [[ "$(state_get SETUP_SLOT)" =~ ^[0-9]+$ ]]; then
      local age now_s; now_s="$(date +%s)"; age=$(( now_s - $(state_get SETUP_SLOT) * 900 ))
      if [ "$age" -gt 1500 ]; then fail "15-min cadence" "no run in any slot after setup slot $(state_get SETUP_SLOT) (${age}s ago) -- the trigger is NOT firing (owner=$(state_get SCHEDULE_OWNER))"
      else info "15-min cadence" "too early to judge (${age}s since setup slot start); re-run --verify after 25 minutes"; fi
    else info "15-min cadence" "no setup slot recorded${applied:+ (applied $applied)}"; fi
    if [ "$(state_get SCHEDULE_OWNER)" = "cron" ]; then
      if grep -qF "$CRON_BEGIN" <<<"$(cron_current)"; then pass "cron block" "present"; else fail "cron block" "missing"; fi
      [ -f "$CRON_LOG" ] && info "nexus-cron.log" "last: $(tail -n 2 "$CRON_LOG" | tr '\n' ' ')"
    fi
  fi
}

compare_other_programs() {
  section "Existing Supervisor programs (must be undisturbed)"
  local name st pid changed=0 down=""
  for name in "${!SNAP_STATE[@]}"; do
    st="$(sup_state "$name")"; pid="$(sup_pid "$name")"
    if [ "${SNAP_STATE[$name]}" = "RUNNING" ] && [ "$st" != "RUNNING" ]; then down="$down $name($st)"
    elif [ "${SNAP_STATE[$name]}" = "RUNNING" ] && [ "$pid" != "${SNAP_PID[$name]}" ]; then
      changed=$((changed + 1)); log "  $name pid ${SNAP_PID[$name]} -> $pid (restart not caused by this script: it never touches other programs; e.g. --max-time recycle or runtime-master)"
    fi
  done
  if [ -n "$down" ]; then fail "existing programs still RUNNING" "no longer running:$down"
  else pass "existing programs still RUNNING" "all ${#SNAP_STATE[@]} baseline programs in their prior state; $changed pid change(s) logged"; fi
}

# ---------------------------------------------------------------------------
# ROLLBACK
# ---------------------------------------------------------------------------
safe_backup_file() { # must be a regular, non-symlink file inside the backups tree
  local f="$1"
  [ -n "$f" ] && [ -f "$f" ] && [ ! -L "$f" ] && [[ "$(readlink -f "$f")" == "$APP_DIR/storage/app/deploy-backups/"* ]]
}

do_rollback() {
  section "Rollback"
  [ -f "$STATE_FILE" ] || { fail "state file" "$STATE_FILE not found; nothing recorded to roll back"; finish; }
  [ "$(state_get APP_DIR)" = "$APP_DIR" ] || { fail "state file" "recorded APP_DIR '$(state_get APP_DIR)' != $APP_DIR"; finish; }
  emit < "$STATE_FILE"
  local planned
  planned="remove managed cron block; restore Nexus .env keys from $(state_get ENV_BACKUP); stop+remove ${PROGRAMS[*]}"
  [ "$ROLLBACK_MIGRATION" -eq 1 ] && planned="$planned; ROLL BACK the Nexus migration (batch $(state_get NEXUS_MIGRATION_BATCH)) after dumping nexus_* tables"
  confirm_or_abort "Rollback will: $planned" "ROLLBACK"
  APPLY_STARTED=1

  # 1. cron (stop new triggers first)
  local cur new
  cur="$(cron_current)"
  if grep -qF "$CRON_BEGIN" <<<"$cur"; then
    new="$TMP_WORK/crontab.rb"; cron_without_block <<<"$cur" > "$new"
    cron_install "$new" && ! grep -qF "$CRON_BEGIN" <<<"$(cron_current)" && pass "cron block" "removed" || fail "cron block" "removal failed"
  else skip "cron block" "not present"; fi

  # 2. .env + config
  local eb; eb="$(state_get ENV_BACKUP)"
  if safe_backup_file "$eb" && [ "$(sha256_of "$eb")" = "$(state_get ENV_BACKUP_SHA)" ]; then
    local tgt="$TMP_WORK/env.rb" k line
    if [ "$(sha256_of "$ENV_FILE")" = "$(state_get ENV_POST_SHA)" ]; then
      cp "$eb" "$tgt"; info ".env rollback mode" ".env unchanged since apply -> restoring the full backup byte-for-byte"
    else
      info ".env rollback mode" ".env changed since apply -> restoring ONLY the Nexus keys from the backup (other edits kept)"
      local del="" set=""
      for k in "${ENV_KEYS[@]}"; do
        line="$(env_occurrences "$k" "$eb" | tail -n 1)"
        if [ -z "$line" ]; then del="$del $k"; else set="$set$k=$(env_raw_value "$k" "$eb")"$'\n'; fi
      done
      NX_SET="$set" NX_DEL="$del" env_render "$ENV_FILE" > "$tgt"
      # NX_DEL also strips our marker line when keys are deleted
    fi
    if cmp -s "$ENV_FILE" "$tgt"; then skip ".env restore" "already equal to pre-apply state"
    elif write_env_atomically "$ENV_FILE" "$tgt"; then pass ".env restore" "$(stat_ugm "$ENV_FILE") preserved; readable by $APP_USER"
    else fail ".env restore" "write failed; manual: cp -p $eb $ENV_FILE && chown $APP_USER:$APP_USER $ENV_FILE && chmod 600 $ENV_FILE"; fi
    local cached; cached="$(state_get CONFIG_CACHE_EXISTED)"
    rebuild_config "${cached:-1}" && pass "config rebuild" "cache=${cached:-1}" || fail "config rebuild" "failed"
    if verify_cached_app; then pass "app after rollback" "DB default $(fact db.default), nexus.enabled=$(fact nexus.enabled)"
    else fail "app after rollback" "app/DB check failed -- investigate immediately"; fi
  else
    fail ".env restore" "backup '$eb' missing, not a safe regular file, or checksum mismatch; .env NOT touched"
  fi

  # 3. supervisor
  local p sf; sf="$(state_get SUP_FILE)"; [ -z "$sf" ] && sf="$SUP_FILE"
  for p in "${PROGRAMS[@]}"; do
    [ "$(sup_state "$p")" != "MISSING" ] && supervisorctl stop "$p" 2>&1 | emit
  done
  if [ "$(state_get SUP_FILE_PREEXISTED)" = "1" ] && safe_backup_file "$(state_get SUP_FILE_BACKUP)"; then
    cp "$(state_get SUP_FILE_BACKUP)" "$sf" && chmod 644 "$sf" && info "supervisor file" "restored pre-existing $sf"
  elif [ -n "$sf" ] && [ -f "$sf" ]; then
    rm -f "$sf" && info "supervisor file" "removed $sf"
  fi
  supervisorctl reread 2>&1 | emit
  supervisorctl update "${PROGRAMS[@]}" 2>&1 | emit
  if [ "$(state_get SUP_FILE_PREEXISTED)" != "1" ]; then
    for p in "${PROGRAMS[@]}"; do
      [ "$(sup_state "$p")" != "MISSING" ] && supervisorctl remove "$p" 2>&1 | emit
    done
  fi
  local left=""
  for p in "${PROGRAMS[@]}"; do [ "$(sup_state "$p")" = "RUNNING" ] && left="$left $p"; done
  if [ -z "$left" ] || [ "$(state_get SUP_FILE_PREEXISTED)" = "1" ]; then pass "Nexus workers" "stopped/removed"
  else fail "Nexus workers" "still running:$left"; fi

  # 4. migration (explicit only)
  if [ "$ROLLBACK_MIGRATION" -eq 1 ]; then
    local batch; batch="$(state_get NEXUS_MIGRATION_BATCH)"
    if ! [[ "$batch" =~ ^[0-9]+$ ]]; then
      fail "migration rollback" "this script did not record running the Nexus migration; refusing"
    elif [ -n "$left" ]; then
      fail "migration rollback" "Nexus workers still running; refusing"
    else
      local dump="$APP_DIR/storage/app/deploy-backups/nexus-setup-$TS-rollback"
      mkdir -p "$APP_DIR/storage/app/deploy-backups" && mkdir -m 700 "$dump"
      if db_dump "$dump/nexus-tables-before-drop.sql" "${NEXUS_TABLES[@]}"; then
        chown -R "$APP_USER:$APP_USER" "$dump"; chmod 600 "$dump"/*
        pass "dump nexus tables" "$dump/nexus-tables-before-drop.sql"
        local out rc
        out="$(artisan migrate:rollback --force --path="$NEXUS_MIG_FILE" --batch="$batch" 2>&1)"; rc=$?
        printf '%s\n' "$out" | emit
        local chk; chk="$(tinker_eval "echo 'NX|rec|'.(Illuminate\\Support\\Facades\\DB::table('migrations')->where('migration', '$NEXUS_MIG_NAME')->exists() ? 'yes' : 'no').PHP_EOL; echo 'NX|tbl|'.(Illuminate\\Support\\Facades\\Schema::hasTable('nexus_runs') ? 'yes' : 'no').PHP_EOL;")"
        if [ "$rc" -eq 0 ] && grep -q 'NX|rec|no' <<<"$chk" && grep -q 'NX|tbl|no' <<<"$chk"; then
          pass "migration rollback" "Nexus tables dropped, migration row removed (batch $batch)"; state_set NEXUS_MIGRATION_BATCH ""
        else fail "migration rollback" "exit $rc; $(grep '^NX|' <<<"$chk" | tr '\n' ' ')"; fi
      else
        fail "dump nexus tables" "dump failed; migration NOT rolled back"
      fi
    fi
  else
    info "migration" "left in place (use --rollback-migration to drop the Nexus tables); wait-shadows migration is never rolled back by this script"
  fi
  compare_other_programs
  if [ "$FAILS" -eq 0 ]; then mv -f "$STATE_FILE" "$STATE_FILE.rolledback-$TS"; info "state" "archived to $STATE_FILE.rolledback-$TS"; fi
  finish
}

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------
# Test harness hook: load the functions without running any mode.
# shellcheck disable=SC2317  # exit is the fallback when not sourced
if [ "${NEXUS_SETUP_SOURCE_ONLY:-0}" = "1" ]; then return 0 2>/dev/null || exit 0; fi

log "Log file: $LOG_FILE"
if [ "$MODE" = "rollback" ]; then
  preflight_host
  resolve_env_file
  as_app test -r "$ENV_FILE" || warn ".env readable" "$APP_USER cannot read $ENV_FILE before rollback"
  detect_supervisor
  command -v supervisorctl >/dev/null 2>&1 && snapshot_supervisor
  PRE_DB_DEFAULT="$(tinker_eval "$PHP_FACTS" | awk -F'|' '$1=="NX" && $2=="db.default"{print $3}')"
  CRON_LOCK="$APP_DIR/storage/framework/nexus-run-cycle.cron.lock"; CRON_LOG="$APP_DIR/storage/logs/nexus-cron.log"
  do_rollback
fi

run_preflight

if [ "$MODE" = "verify" ]; then
  SCHEDULE_OWNER="$(state_get SCHEDULE_OWNER)"
  step_verify 0
  compare_other_programs
  finish
fi

if [ "$MODE" = "dry-run" ]; then
  print_plan
  if [ "$FAILS" -gt 0 ]; then log "Dry run found blocking problems; --apply would refuse until they are fixed."; fi
  finish
fi

# --- apply ---
if [ "$FAILS" -gt 0 ]; then abort "preflight failed; nothing was changed"; fi
print_plan
confirm_or_abort "About to apply the plan above to PRODUCTION ($APP_DIR)." "APPLY"
APPLY_STARTED=1
state_set_once FIRST_APPLY_AT "$TS"
step_backups
step_migrations
step_env
step_supervisor
[ "$FAILS" -gt 0 ] && abort "worker verification failed"
step_schedule
step_verify 1
compare_other_programs
log "Next: run 'sudo bash scripts/nexus-production-setup.sh --verify' ~25 minutes from now to confirm the 15-minute cadence."
finish
