#!/usr/bin/env bash
# shellcheck disable=SC2016,SC2009,SC2012  # intentional: single-quoted awk/sed; ps|grep on FPM process titles; ls -t for newest log
# =============================================================================
# AutoFinX -- READ-ONLY diagnostic for "every API request sits pending /
# completed requests take 7-8s".
#
#   sudo bash scripts/autofinx-stuck-requests-diagnose.sh [--domain D] [--samples N]
#
# Collects evidence for the usual suspects WITHOUT changing anything:
#   PHP-FPM pool exhaustion (cPanel EA4 pools, workers, listen queue, slow/error logs)
#   CloudLinux LVE limits/faults (EP / NPROC / PMEM / CPU throttling)
#   Apache/LiteSpeed worker state + error logs
#   Redis stalls (latency, blocked clients, bgsave/AOF rewrite, memory, queue lengths)
#   MySQL locks (processlist, long transactions, lock waits, InnoDB status)
#   origin vs network/proxy latency (curl -w against the public URL AND pinned to 127.0.0.1)
#   Laravel log errors, host load/memory/disk, a short live sampling loop.
#
# The only thing it writes is its own report file (mode 600). Secrets (DB/Redis
# passwords, APP_KEY, tokens) are never printed: credentials go to a temporary
# 600 --defaults-extra-file / REDISCLI_AUTH, and every line is masked.
# =============================================================================
set -uo pipefail
# bash < 4.4 (EL7) treats "${empty_array[@]}" as unbound under set -u
if [ "${BASH_VERSINFO[0]}" -lt 4 ] || { [ "${BASH_VERSINFO[0]}" -eq 4 ] && [ "${BASH_VERSINFO[1]}" -lt 4 ]; }; then set +u; fi
umask 077

SCRIPT_VERSION="2026-09-24.1"
APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
APP_USER="${APP_USER:-zcadmin}"
DOMAIN=""
SAMPLES=5
INTERVAL=2
OUT_DIR=""
USE_ROOT_MYCNF=0
CMD_TIMEOUT=20

usage() {
  cat <<'EOF'
Usage: sudo bash scripts/autofinx-stuck-requests-diagnose.sh [options]

  --domain D          vhost to inspect (default: host of APP_URL in .env)
  --samples N         live sampling iterations (default 5; 0 disables)
  --interval S        seconds between samples (default 2)
  --out DIR           report directory (default: $APP_DIR/storage/logs)
  --mysql-root-cnf    query MySQL with /root/.my.cnf (full processlist visibility)
                      instead of the app's own DB user from .env
  -h, --help

Read-only. Env overrides: APP_DIR, APP_USER, PHP_BIN.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift ;;
    --domain=*) DOMAIN="${1#*=}" ;;
    --samples) SAMPLES="${2:-}"; shift ;;
    --samples=*) SAMPLES="${1#*=}" ;;
    --interval) INTERVAL="${2:-}"; shift ;;
    --interval=*) INTERVAL="${1#*=}" ;;
    --out) OUT_DIR="${2:-}"; shift ;;
    --out=*) OUT_DIR="${1#*=}" ;;
    --mysql-root-cnf) USE_ROOT_MYCNF=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done
[[ "$SAMPLES" =~ ^[0-9]+$ ]] || { echo "--samples must be an integer" >&2; exit 2; }
[[ "$INTERVAL" =~ ^[0-9]+$ ]] && [ "$INTERVAL" -ge 1 ] || { echo "--interval must be an integer >= 1" >&2; exit 2; }
if [[ "$DOMAIN" =~ [^A-Za-z0-9.-] ]]; then echo "--domain has invalid characters" >&2; exit 2; fi

[ -d "$APP_DIR" ] || { echo "APP_DIR not found: $APP_DIR" >&2; exit 2; }
OUT_DIR="${OUT_DIR:-$APP_DIR/storage/logs}"
[ -d "$OUT_DIR" ] || { echo "Output dir not found: $OUT_DIR" >&2; exit 2; }
TS="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT="$OUT_DIR/stuck-requests-diagnose-$TS.txt"
( set -C; : > "$REPORT" ) 2>/dev/null || { echo "Cannot create $REPORT" >&2; exit 2; }
exec 3>>"$REPORT"
chmod 600 "$REPORT" 2>/dev/null || true

TMP="$(mktemp -d "${TMPDIR:-/tmp}/afx-diag.XXXXXX")" || exit 2
chmod 700 "$TMP"
trap 'rm -rf "$TMP"' EXIT

mask() {
  sed -E \
    -e 's/((PASSWORD|PASSWD|_PASS|SECRET|TOKEN|API_KEY|APP_KEY|_KEY|PRIVATE|CREDENTIAL|AUTH)[A-Za-z0-9_]*["'\'']?[[:space:]]*[=:][[:space:]]*)[^[:space:],;]+/\1***MASKED***/Ig' \
    -e 's/base64:[A-Za-z0-9+\/=]{16,}/base64:***MASKED***/g' \
    -e 's#([a-z]+://)[^:@/[:space:]]+:[^@/[:space:]]+@#\1***:***@#g' \
    -e 's/(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+\/=-]{8,}/\1 ***MASKED***/g' \
    -e 's/[0-9]+\|[A-Za-z0-9]{30,}/***SANCTUM_TOKEN***/g' \
    -e 's/(.{0,400}).*/\1/'
}
out() { printf '%s\n' "$1"; printf '%s\n' "$1" >&3; }
say() { out "$(printf '%s' "$*" | mask)"; }
section() { out ""; out "=================== $* ==================="; }
SIGNALS=()
signal() { SIGNALS+=("$*"); say "  >>> SIGNAL: $*"; }
# run "title" cmd args... -- bounded, masked, never fatal
run() {
  local title="$1"; shift
  out "--- $title"
  local o rc
  o="$(timeout "$CMD_TIMEOUT" "$@" 2>&1)"; rc=$?
  [ -n "$o" ] && printf '%s\n' "$o" | mask | sed 's/^/    /' | { t="$(cat)"; out "$t"; }
  [ "$rc" -ne 0 ] && out "    (exit $rc)"
  return 0
}
have() { command -v "$1" >/dev/null 2>&1; }

IS_ROOT=0; [ "$(id -u)" = "0" ] && IS_ROOT=1

# --- .env (read directly: must work even when the app itself is broken) ---
ENV_FILE="$APP_DIR/.env"
env_get() { # last occurrence wins (phpdotenv semantics); quotes stripped
  [ -r "$ENV_FILE" ] || return 0
  grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENV_FILE" | tail -n 1 \
    | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+#.*$//; s/^\"(.*)\"$/\1/; s/^'(.*)'$/\1/"
}
APP_URL="$(env_get APP_URL)"
[ -z "$DOMAIN" ] && DOMAIN="$(sed -E 's#^[a-z]+://##; s#[:/].*$##' <<<"$APP_URL")"
[ -z "$DOMAIN" ] && DOMAIN="api.autofinx.arunyadhav.dev"
POOL_GUESS="${DOMAIN//./_}"

section "0. Context"
say "autofinx-stuck-requests-diagnose $SCRIPT_VERSION  report=$REPORT"
say "date(utc)=$(date -u -Is) host=$(hostname) user=$(id -un) root=$IS_ROOT kernel=$(uname -r)"
say "APP_DIR=$APP_DIR APP_USER=$APP_USER DOMAIN=$DOMAIN APP_URL=$APP_URL"
[ "$IS_ROOT" -eq 1 ] || say "WARNING: not root -- FPM/Redis/MySQL/log sections will be partial."
if [ -r "$ENV_FILE" ]; then say ".env: $(stat -c '%U:%G %a' "$ENV_FILE" 2>/dev/null) DB_CONNECTION=$(env_get DB_CONNECTION) SESSION_DRIVER=$(env_get SESSION_DRIVER) CACHE_STORE=$(env_get CACHE_STORE) QUEUE_CONNECTION=$(env_get QUEUE_CONNECTION)"
else say ".env not readable at $ENV_FILE"; fi

# ---------------------------------------------------------------------------
section "1. Host load / memory / disk"
NPROC="$(nproc 2>/dev/null || echo 1)"
LOAD1="$(cut -d' ' -f1 /proc/loadavg 2>/dev/null || echo 0)"
run "uptime" uptime
run "nproc" nproc
run "free -m" free -m
run "vmstat 1 3 (r=run queue, b=blocked on IO, wa=IO wait, si/so=swap)" vmstat 1 3
run "df -h" df -h
run "df -i" df -i
run "top (batch, head)" bash -c 'top -b -n 1 | head -n 35'
run "top CPU processes" bash -c 'ps -eo pid,user,pcpu,pmem,rss,etimes,stat,args --sort=-pcpu | head -n 25'
run "top RSS processes" bash -c 'ps -eo pid,user,pcpu,pmem,rss,etimes,stat,args --sort=-rss | head -n 15'
run "process count per user" bash -c 'ps -eo user= | sort | uniq -c | sort -rn | head -n 10'
have iostat && run "iostat -x 1 2" iostat -x 1 2
[ "$IS_ROOT" -eq 1 ] && run "kernel OOM / hung-task messages" bash -c 'dmesg -T 2>/dev/null | grep -iE "out of memory|oom-kill|killed process|hung_task|blocked for more than" | tail -n 20'
awk -v l="$LOAD1" -v n="$NPROC" 'BEGIN{exit !(l > n*2)}' && signal "load1=$LOAD1 > 2x nproc=$NPROC (CPU saturation)"
MEMAVAIL="$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo 2>/dev/null)"
[ -n "$MEMAVAIL" ] && [ "$MEMAVAIL" -lt 300 ] && signal "MemAvailable=${MEMAVAIL}MB (memory pressure / swapping)"
while read -r mnt pct; do signal "filesystem $mnt at $pct (full disks stall MySQL/Redis/logging)"; done < <(df -P 2>/dev/null | awk 'NR>1 && $(NF-1)+0 >= 95 {print $NF" "$(NF-1)}')

# ---------------------------------------------------------------------------
section "2. CloudLinux LVE limits and faults (EP/NPROC/PMEM/CPU throttling queue requests)"
if have lvectl; then
  run "lvectl list-user ($APP_USER)" bash -c "lvectl list-user 2>/dev/null | awk 'NR<=2 || \$1==\"$APP_USER\"'"
  have lveinfo && run "lveinfo last 1h ($APP_USER; *f columns = faults)" lveinfo --period=1h --user="$APP_USER" -d
  have lveps && run "lveps (current usage)" bash -c "lveps -p 2>/dev/null | head -n 30"
else
  say "  lvectl not present (not CloudLinux or not root)"
fi
have cagefsctl && run "CageFS status ($APP_USER)" cagefsctl --user-status "$APP_USER"

# ---------------------------------------------------------------------------
section "3. Web server (Apache / LiteSpeed) and PHP handler"
run "web server processes" bash -c 'ps -eo pid,user,etimes,pcpu,rss,args | grep -E "[h]ttpd|[l]itespeed|[l]shttpd|[n]ginx" | head -n 15; echo "httpd children: $(pgrep -c httpd 2>/dev/null || echo 0)"'
if have httpd; then
  run "httpd -V (MPM)" bash -c 'httpd -V 2>/dev/null | grep -iE "version|mpm|threaded|forked"'
  run "httpd modules (handlers)" bash -c 'httpd -M 2>/dev/null | grep -iE "mpm|proxy_fcgi|fcgid|lsapi|suphp|ruid2|http2|cgi" '
fi
for c in /etc/apache2/conf/httpd.conf /usr/local/apache/conf/httpd.conf; do
  [ -r "$c" ] && { run "worker limits in $c" bash -c "grep -nE '^[[:space:]]*(ServerLimit|MaxRequestWorkers|MaxClients|ThreadsPerChild|KeepAlive|KeepAliveTimeout|MaxKeepAliveRequests|Timeout|StartServers)[[:space:]]' '$c'"; break; }
done
have curl && run "Apache scoreboard (cPanel whm-server-status, localhost only)" bash -c 'curl -s --max-time 5 "http://127.0.0.1/whm-server-status?auto" | grep -E "^(BusyWorkers|IdleWorkers|Total Accesses|ReqPerSec|BusyServers|IdleServers|ConnsTotal|Load)"'
[ -x /usr/local/cpanel/bin/rebuild_phpconf ] && run "PHP handlers (rebuild_phpconf --current)" /usr/local/cpanel/bin/rebuild_phpconf --current
have whmapi1 && run "vhost PHP version / FPM flag for $DOMAIN" bash -c "whmapi1 php_get_vhost_versions 2>/dev/null | grep -B3 -A10 \"vhost: $DOMAIN\$\" | grep -E 'vhost|version|php_fpm|phpversion_source|is_suspended|documentroot' | head -n 20"
for lg in /etc/apache2/logs/error_log /usr/local/apache/logs/error_log; do
  [ -r "$lg" ] && { run "Apache error_log (last 60 relevant)" bash -c "tail -n 4000 '$lg' | grep -iE '$DOMAIN|proxy_fcgi|fcgi|timeout|AH01071|AH01075|AH01067|AH00484|MaxRequestWorkers|server reached|scoreboard' | tail -n 60"; break; }
done
for lg in /usr/local/lsws/logs/error.log /usr/local/lsws/logs/stderr.log; do
  [ -r "$lg" ] && run "LiteSpeed $(basename "$lg") (tail 60)" tail -n 60 "$lg"
done
for rt in /tmp/lshttpd/.rtreport /dev/shm/lsws/status/.rtreport; do
  [ -r "$rt" ] && run "LiteSpeed real-time report $rt (REQ_PROCESSING, EXTAPP pool usage)" bash -c "head -c 6000 '$rt'"
done
for dl in "/etc/apache2/logs/domlogs/$DOMAIN-ssl_log" "/etc/apache2/logs/domlogs/$DOMAIN" "/usr/local/apache/domlogs/$DOMAIN-ssl_log" "/usr/local/apache/domlogs/$DOMAIN"; do
  if [ -r "$dl" ]; then
    run "top request paths, last 5000 hits ($dl; query strings stripped)" bash -c "tail -n 5000 '$dl' | awk '{print \$6\" \"\$7}' | sed -E 's/\\?.*//; s#/[0-9a-f]{8}-[0-9a-f-]{27}#/{uuid}#g; s#/[0-9]+(/|\$)#/{id}\\1#g' | sort | uniq -c | sort -rn | head -n 25"
    run "status codes, last 5000 hits" bash -c "tail -n 5000 '$dl' | awk '{print \$9}' | sort | uniq -c | sort -rn | head"
    run "requests per minute, last 5000 hits" bash -c "tail -n 5000 '$dl' | awk '{print substr(\$4,2,17)}' | uniq -c | tail -n 15"
    break
  fi
done

# ---------------------------------------------------------------------------
section "4. PHP-FPM pools (cPanel EA4)"
UD="/var/cpanel/userdata/$APP_USER/$DOMAIN"
[ -r "$UD" ] && run "cPanel userdata $UD (documentroot/phpversion)" bash -c "grep -E '^(documentroot|phpversion|servername|serveralias|owner|group):' '$UD'"
for y in "/var/cpanel/userdata/$APP_USER/$DOMAIN.php-fpm.yaml" /var/cpanel/ApachePHPFPM/system_pool_defaults.yaml /var/cpanel/ApachePHPFPM/system.yaml; do
  [ -r "$y" ] && run "FPM overrides $y" cat "$y"
done
POOL_FILES=()
for f in /opt/cpanel/ea-php*/root/etc/php-fpm.d/*.conf; do
  [ -r "$f" ] || continue
  if grep -qE "^\[$POOL_GUESS\]" "$f" || grep -qF "$DOMAIN" "$f" || grep -qE "^[[:space:]]*user[[:space:]]*=[[:space:]]*${APP_USER}[[:space:]]*$" "$f"; then POOL_FILES+=("$f"); fi
done
if [ "${#POOL_FILES[@]}" -eq 0 ]; then
  say "  No EA4 FPM pool file matched $DOMAIN / user $APP_USER under /opt/cpanel/ea-php*/root/etc/php-fpm.d/ (domain may use mod_lsapi / suPHP / CGI -- see section 3)."
fi
FPM_MAX_TOTAL=0
FPM_WORKERS_TOTAL=0
for f in "${POOL_FILES[@]}"; do
  run "pool directives: $f" bash -c "grep -nE '^\\[|^[[:space:]]*(user|group|listen|listen\\.backlog|pm|pm\\.[a-z_]+|request_terminate_timeout|request_slowlog_timeout|slowlog|php_admin_value\\[(error_log|max_execution_time|memory_limit)\\]|php_value\\[max_execution_time\\]|catch_workers_output)[[:space:]]*=' '$f'"
  pool="$(grep -m1 -oE '^\[[^]]+\]' "$f" | tr -d '[]')"
  maxc="$(grep -m1 -E '^[[:space:]]*pm\.max_children[[:space:]]*=' "$f" | sed -E 's/.*=[[:space:]]*//')"
  listen="$(grep -m1 -E '^[[:space:]]*listen[[:space:]]*=' "$f" | sed -E 's/.*=[[:space:]]*//')"
  slowlog="$(grep -m1 -E '^[[:space:]]*slowlog[[:space:]]*=' "$f" | sed -E 's/.*=[[:space:]]*//')"
  errlog="$(grep -m1 -E '^[[:space:]]*php_admin_value\[error_log\][[:space:]]*=' "$f" | sed -E 's/.*=[[:space:]]*//')"
  status_path="$(grep -m1 -E '^[[:space:]]*pm\.status_path[[:space:]]*=' "$f" | sed -E 's/.*=[[:space:]]*//')"
  ver="$(sed -E 's#^/opt/cpanel/(ea-php[0-9]+)/.*#\1#' <<<"$f")"
  workers="$(ps -eo args= 2>/dev/null | grep -cE "^php-fpm: pool ${pool}[[:space:]]*\$")"
  FPM_WORKERS_TOTAL=$((FPM_WORKERS_TOTAL + workers))
  [[ "$maxc" =~ ^[0-9]+$ ]] && FPM_MAX_TOTAL=$((FPM_MAX_TOTAL + maxc))
  say "  pool=$pool ($ver) workers_now=$workers pm.max_children=${maxc:-<default>} listen=$listen"
  run "workers of pool $pool (state R=on CPU; wchan shows where sleeping ones wait)" bash -c "ps -eo pid,etimes,pcpu,rss,stat,wchan:22,args | awk 'NR==1 || /php-fpm: pool $pool *\$/' | head -n 60"
  run "wchan histogram for pool $pool (accept-like = idle; anything else = busy in a request)" bash -c "ps -eo stat=,wchan:30=,args= | awk '/php-fpm: pool $pool *\$/ {print \$1\" \"\$2}' | sort | uniq -c | sort -rn"
  if [[ "$maxc" =~ ^[0-9]+$ ]] && [ "$workers" -ge "$maxc" ]; then signal "FPM pool $pool has $workers workers = pm.max_children ($maxc): pool is at its ceiling"; fi
  if [ -n "$listen" ] && [ "${listen:0:1}" = "/" ] && have ss; then
    run "listen socket accept queue for $pool (Recv-Q > 0 = requests waiting for a free worker)" bash -c "ss -xl 2>/dev/null | awk 'NR==1 || index(\$0, \"$listen\")'"
    q="$(ss -xl 2>/dev/null | awk -v s="$listen" 'index($0, s) {print $3; exit}')"
    [[ "$q" =~ ^[0-9]+$ ]] && [ "$q" -gt 0 ] && signal "FPM pool $pool listen queue Recv-Q=$q (requests queued waiting for workers)"
  elif [ -n "$listen" ] && have ss; then
    run "listen TCP socket for $pool" bash -c "ss -ltn 2>/dev/null | awk 'NR==1 || index(\$0, \"${listen##*:}\")'"
  fi
  if [ -n "$status_path" ] && have cgi-fcgi && [ "${listen:0:1}" = "/" ]; then
    run "FPM status page $status_path (listen queue / max children reached / slow requests)" env SCRIPT_NAME="$status_path" SCRIPT_FILENAME="$status_path" REQUEST_METHOD=GET QUERY_STRING= cgi-fcgi -bind -connect "$listen"
  else
    say "  (FPM status page not queried: pm.status_path unset or cgi-fcgi missing)"
  fi
  fpmlog="/opt/cpanel/$ver/root/usr/var/log/php-fpm/error.log"
  if [ -r "$fpmlog" ]; then
    run "FPM master error.log ($fpmlog): max_children / busy / slow / terminated (last 40)" bash -c "grep -iE 'max_children|seems busy|slow|terminat|exited on signal|timed out|$pool' '$fpmlog' | tail -n 40"
    n="$(tail -n 20000 "$fpmlog" | grep -c "\[pool $pool\] server reached")"
    [ "$n" -gt 0 ] && signal "FPM log: pool $pool 'server reached pm.max_children' x$n (last 20k lines) -- last: $(grep "\[pool $pool\] server reached" "$fpmlog" | tail -n 1 | cut -c1-120)"
  fi
  [ -n "$slowlog" ] && [ -r "$slowlog" ] && run "FPM slowlog $slowlog (script paths + stacks of slow requests; last 80)" tail -n 80 "$slowlog"
  [ -n "$errlog" ] && [ -r "$errlog" ] && run "PHP error_log $errlog (last 40)" tail -n 40 "$errlog"
done
if [ -r /opt/cpanel/ea-php82/root/etc/php-fpm.conf ]; then
  run "FPM global conf (process.max / emergency restart)" bash -c "grep -nE '^[[:space:]]*(process\\.max|emergency_restart|process_control_timeout|include)' /opt/cpanel/ea-php82/root/etc/php-fpm.conf"
fi
run "all php-fpm workers per pool (host-wide)" bash -c "ps -eo args= | awk '/^php-fpm: pool /{print \$3}' | sort | uniq -c | sort -rn | head -n 20"

# ---------------------------------------------------------------------------
section "5. Redis"
REDIS_HOST="$(env_get REDIS_HOST)"; REDIS_PORT="$(env_get REDIS_PORT)"; REDIS_DB="$(env_get REDIS_DB)"
REDIS_PW="$(env_get REDIS_PASSWORD)"; [ "$REDIS_PW" = "null" ] && REDIS_PW=""
RARGS=(-h "${REDIS_HOST:-127.0.0.1}" -p "${REDIS_PORT:-6379}")
redis_q() { timeout 10 redis-cli "${RARGS[@]}" "$@" 2>&1; }
# Password reaches redis-cli only through the inherited REDISCLI_AUTH environment
# variable (never argv, so it is not visible in ps).
export REDISCLI_AUTH="$REDIS_PW"
if have redis-cli; then
  # PING round-trips, measured here (ms), password via REDISCLI_AUTH only
  pings=""
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    t0="$(date +%s%N)"; r="$(redis_q PING)"; t1="$(date +%s%N)"
    pings="$pings $(( (t1 - t0) / 1000000 ))"
  done
  say "--- redis PING x10 (ms, includes redis-cli process start): $pings  last reply: $r"
  worst="$(tr ' ' '\n' <<<"$pings" | sort -n | tail -n 1)"
  [ "${worst:-0}" -gt 100 ] && signal "Redis PING worst ${worst}ms (>100ms incl. process start) -- Redis or host stall"
  run "redis-cli --latency (3s sample)" bash -c "timeout 3 redis-cli ${RARGS[*]} --latency --raw 2>&1 | tr '\r' '\n' | tail -n 1"
  run "INFO server (version/uptime)" bash -c "redis-cli ${RARGS[*]} INFO server 2>&1 | grep -E '^(redis_version|uptime_in_seconds|hz|process_id|tcp_port|config_file):'"
  run "INFO clients" bash -c "redis-cli ${RARGS[*]} INFO clients 2>&1"
  run "INFO memory (key fields)" bash -c "redis-cli ${RARGS[*]} INFO memory 2>&1 | grep -E '^(used_memory_human|used_memory_peak_human|maxmemory_human|maxmemory_policy|mem_fragmentation_ratio|used_memory_rss_human):'"
  run "INFO persistence (bgsave / AOF rewrite in progress = fork stall risk)" bash -c "redis-cli ${RARGS[*]} INFO persistence 2>&1 | grep -E '^(loading|rdb_bgsave_in_progress|rdb_last_bgsave_status|rdb_last_bgsave_time_sec|rdb_changes_since_last_save|aof_enabled|aof_rewrite_in_progress|aof_last_bgrewrite_status|aof_last_write_status|latest_fork_usec):'"
  run "INFO stats (rejected connections, evictions)" bash -c "redis-cli ${RARGS[*]} INFO stats 2>&1 | grep -E '^(total_connections_received|rejected_connections|evicted_keys|expired_keys|instantaneous_ops_per_sec|blocked_keys|keyspace_hits|keyspace_misses):'"
  run "INFO keyspace" bash -c "redis-cli ${RARGS[*]} INFO keyspace 2>&1"
  run "CONFIG GET limits (may be disabled)" bash -c "for k in maxclients maxmemory maxmemory-policy save appendonly appendfsync timeout; do redis-cli ${RARGS[*]} CONFIG GET \$k 2>&1 | paste -sd' '; done"
  run "LATENCY LATEST" bash -c "redis-cli ${RARGS[*]} LATENCY LATEST 2>&1"
  run "SLOWLOG (length + command names/durations only; args omitted)" bash -c "redis-cli ${RARGS[*]} SLOWLOG LEN 2>&1; redis-cli ${RARGS[*]} SLOWLOG GET 15 2>&1 | grep -E '^[0-9]+\$|^[A-Z]+\$' | paste -sd' ' | cut -c1-1500"
  run "CLIENT LIST summary (count by last command; blpop/brpop = idle queue workers)" bash -c "redis-cli ${RARGS[*]} CLIENT LIST 2>&1 | grep -oE 'cmd=[^ ]+' | sort | uniq -c | sort -rn | head -n 20"
  run "queue lengths (keys matching *queues:*; SCAN, read-only)" bash -c "for k in \$(redis-cli ${RARGS[*]} ${REDIS_DB:+-n $REDIS_DB} --scan --pattern '*queues:*' --count 1000 2>/dev/null | head -n 60); do t=\$(redis-cli ${RARGS[*]} ${REDIS_DB:+-n $REDIS_DB} TYPE \"\$k\"); case \$t in list) n=\$(redis-cli ${RARGS[*]} ${REDIS_DB:+-n $REDIS_DB} LLEN \"\$k\");; zset) n=\$(redis-cli ${RARGS[*]} ${REDIS_DB:+-n $REDIS_DB} ZCARD \"\$k\");; *) n=\"(\$t)\";; esac; echo \"\$n \$k\"; done | sort -rn | head -n 40"
  bg="$(redis_q INFO persistence | grep -E '^(rdb_bgsave_in_progress|aof_rewrite_in_progress):1' | tr -d '\r')"
  [ -n "$bg" ] && signal "Redis fork in progress now: $bg"
  rej="$(redis_q INFO stats | awk -F: '/^rejected_connections:/{print $2+0}')"
  [ "${rej:-0}" -gt 0 ] && signal "Redis rejected_connections=$rej since start (maxclients reached at some point)"
else
  say "  redis-cli not installed"
fi
unset REDIS_PW REDISCLI_AUTH

# ---------------------------------------------------------------------------
section "6. MySQL / MariaDB (processlist, long transactions, lock waits)"
MYSQL=""
have mysql && MYSQL="$(command -v mysql)"
have mariadb && [ -z "$MYSQL" ] && MYSQL="$(command -v mariadb)"
CNF=""
if [ -n "$MYSQL" ]; then
  if [ "$USE_ROOT_MYCNF" -eq 1 ] && [ -r /root/.my.cnf ]; then
    CNF=/root/.my.cnf; say "  using /root/.my.cnf (full processlist visibility)"
    myq() { timeout "$CMD_TIMEOUT" "$MYSQL" --defaults-file="$CNF" -N -B -e "$1" 2>&1; }
    myqt() { timeout "$CMD_TIMEOUT" "$MYSQL" --defaults-file="$CNF" -t -e "$1" 2>&1; }
  else
    CNF="$TMP/app.cnf"
    DB_PW="$(env_get DB_PASSWORD)"
    q() { local v="$1"; if [[ "$v" != *\"* ]]; then printf '"%s"' "${v//\\/\\\\}"; elif [[ "$v" != *\'* && "$v" != *\\* ]]; then printf "'%s'" "$v"; else return 1; fi; }
    if qpw="$(q "$DB_PW")"; then
      {
        echo "[client]"
        echo "user=$(q "$(env_get DB_USERNAME)")"
        echo "password=$qpw"
        if [ -n "$(env_get DB_SOCKET)" ]; then echo "socket=$(q "$(env_get DB_SOCKET)")"
        else echo "host=$(q "$(env_get DB_HOST)")"; [ -n "$(env_get DB_PORT)" ] && echo "port=$(env_get DB_PORT)"; fi
        echo "database=$(q "$(env_get DB_DATABASE)")"
      } > "$CNF"
      chmod 600 "$CNF"
    else
      say "  DB password contains both quote types; cannot build an option file safely -- use --mysql-root-cnf"; CNF=""
    fi
    unset DB_PW qpw
    # HOME=$TMP so root's ~/.my.cnf (read after --defaults-extra-file) cannot override the app user
    myq() { HOME="$TMP" MYSQL_PWD="" timeout "$CMD_TIMEOUT" "$MYSQL" --defaults-extra-file="$CNF" -N -B -e "$1" 2>&1; }
    myqt() { HOME="$TMP" MYSQL_PWD="" timeout "$CMD_TIMEOUT" "$MYSQL" --defaults-extra-file="$CNF" -t -e "$1" 2>&1; }
  fi
fi
if [ -n "$CNF" ]; then
  mrun() { out "--- $1"; myqt "$2" | mask | sed 's/^/    /' | { t="$(cat)"; out "$t"; }; }
  mrun "server + limits" "SELECT VERSION() version, @@max_connections max_conn, @@wait_timeout wait_timeout, @@innodb_lock_wait_timeout lock_wait_timeout, ROUND(@@innodb_buffer_pool_size/1024/1024) bp_mb, @@GLOBAL.sql_mode sql_mode"
  mrun "global status" "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Threads_running','Max_used_connections','Aborted_connects','Aborted_clients','Slow_queries','Questions','Innodb_row_lock_current_waits','Innodb_row_lock_waits','Innodb_row_lock_time_avg','Table_locks_waited','Created_tmp_disk_tables')"
  mrun "processlist by command/user" "SELECT COMMAND, USER, COUNT(*) n, MAX(TIME) max_s FROM information_schema.PROCESSLIST GROUP BY COMMAND, USER ORDER BY n DESC"
  mrun "active statements (non-Sleep, longest first; SQL truncated)" "SELECT ID, USER, DB, COMMAND, TIME, STATE, LEFT(REPLACE(INFO, '\n', ' '), 160) info FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' AND ID <> CONNECTION_ID() ORDER BY TIME DESC LIMIT 40"
  mrun "open transactions (oldest first)" "SELECT trx_id, trx_state, trx_started, TIMESTAMPDIFF(SECOND, trx_started, NOW()) age_s, trx_mysql_thread_id thread, trx_rows_locked, trx_tables_locked, LEFT(REPLACE(IFNULL(trx_query,''), '\n', ' '), 160) query FROM information_schema.INNODB_TRX ORDER BY trx_started LIMIT 20"
  lw="$(myqt "SELECT r.trx_mysql_thread_id waiting_thread, TIMESTAMPDIFF(SECOND, r.trx_wait_started, NOW()) wait_s, LEFT(r.trx_query,120) waiting_query, b.trx_mysql_thread_id blocking_thread, LEFT(IFNULL(b.trx_query,'<idle in trx>'),120) blocking_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id")"
  if grep -qiE "unknown table|doesn't exist|ERROR" <<<"$lw"; then
    lw="$(myqt "SELECT * FROM sys.innodb_lock_waits LIMIT 20")"
  fi
  out "--- InnoDB lock waits"; printf '%s\n' "$lw" | mask | sed 's/^/    /' | { t="$(cat)"; out "$t"; }
  mrun "metadata locks (MariaDB METADATA_LOCK_INFO plugin, if loaded)" "SELECT THREAD_ID, LOCK_MODE, LOCK_TYPE, TABLE_SCHEMA, TABLE_NAME FROM information_schema.METADATA_LOCK_INFO LIMIT 30"
  out "--- SHOW ENGINE INNODB STATUS (TRANSACTIONS + LATEST DETECTED DEADLOCK excerpts)"
  myq "SHOW ENGINE INNODB STATUS" | sed 's/\\n/\n/g' | awk '/LATEST DETECTED DEADLOCK/{d=1;c=0} /^TRANSACTIONS$/{t=1;c=0} (d||t){print; c++} c>70{d=0;t=0}' | mask | sed 's/^/    /' | { t="$(cat)"; out "$t"; }
  tr_run="$(myq "SHOW GLOBAL STATUS LIKE 'Threads_running'" | awk '{print $2}')"
  [ "${tr_run:-0}" -gt 20 ] 2>/dev/null && signal "MySQL Threads_running=$tr_run (>20: queries piling up)"
  waits="$(myq "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS" 2>/dev/null | head -n 1)"
  [[ "$waits" =~ ^[0-9]+$ ]] && [ "$waits" -gt 0 ] && signal "MySQL has $waits InnoDB lock wait(s) right now"
  old="$(myq "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_started < NOW() - INTERVAL 30 SECOND" | head -n 1)"
  [[ "$old" =~ ^[0-9]+$ ]] && [ "$old" -gt 0 ] && signal "MySQL has $old transaction(s) open > 30s (lock holders)"
  maxc="$(myq "SELECT @@max_connections" | head -n 1)"; conn="$(myq "SHOW GLOBAL STATUS LIKE 'Threads_connected'" | awk '{print $2}')"
  [[ "$maxc" =~ ^[0-9]+$ ]] && [[ "$conn" =~ ^[0-9]+$ ]] && [ "$conn" -ge $((maxc * 9 / 10)) ] && signal "MySQL connections $conn / max_connections $maxc (>= 90%)"
else
  say "  mysql client unavailable or no usable credentials"
fi

# ---------------------------------------------------------------------------
section "7. HTTP timing: public path vs origin (127.0.0.1) -- separates network/proxy from PHP"
BASE="${APP_URL%/}"; [ -z "$BASE" ] && BASE="https://$DOMAIN"
SCHEME="${BASE%%://*}"; PORT=443; [ "$SCHEME" = "http" ] && PORT=80
FMT='code=%{http_code} http=%{http_version} dns=%{time_namelookup} connect=%{time_connect} tls=%{time_appconnect} ttfb=%{time_starttransfer} total=%{time_total} bytes=%{size_download}\n'
ENDPOINTS=(/up /api/v1/auth /api/v1/assets /api/v1/market/symbols)
if have curl; then
  run "response headers of /up (server / proxy / CDN markers)" bash -c "curl -sS -o /dev/null -D - --max-time 15 '$BASE/up' | grep -iE '^(HTTP/|server:|cf-ray:|cf-cache-status:|x-powered-by:|alt-svc:|via:|x-litespeed|x-cache)'"
  ORIGIN_UP_TTFB=""
  for ep in "${ENDPOINTS[@]}"; do
    out "--- $ep"
    for i in 1 2 3; do
      r="$(curl -s -o /dev/null --max-time 20 -w "$FMT" "$BASE$ep" 2>&1)"; say "    public  #$i $r"
    done
    for i in 1 2 3; do
      r="$(curl -s -o /dev/null --max-time 20 -k --resolve "$DOMAIN:$PORT:127.0.0.1" -w "$FMT" "$BASE$ep" 2>&1)"; say "    origin  #$i $r"
      [ "$ep" = "/up" ] && ORIGIN_UP_TTFB="$(sed -nE 's/.*ttfb=([0-9.]+).*/\1/p' <<<"$r")"
    done
  done
  if [ -n "$ORIGIN_UP_TTFB" ] && awk -v t="$ORIGIN_UP_TTFB" 'BEGIN{exit !(t > 1.0)}'; then
    signal "origin /up TTFB ${ORIGIN_UP_TTFB}s from the server itself (>1s): the delay is on this host (web server / FPM / app bootstrap), not the network"
  fi
  hv="$(curl -s -o /dev/null --max-time 15 -w '%{http_version}' "$BASE/up" 2>/dev/null)"
  [ "$SCHEME" = "https" ] && [ "$hv" = "1.1" ] && signal "public endpoint answers HTTP/1.1 only: browsers allow ~6 parallel requests per host, so a few 7-8s requests make every later request show 'pending'"
else
  say "  curl not installed"
fi

# ---------------------------------------------------------------------------
section "8. Laravel / runtime logs"
LL="$(ls -1t "$APP_DIR"/storage/logs/laravel*.log 2>/dev/null | head -n 1)"
if [ -n "$LL" ]; then
  say "  newest Laravel log: $LL ($(stat -c '%s bytes, modified %y' "$LL" 2>/dev/null))"
  run "ERROR/CRITICAL count in last 5000 lines" bash -c "tail -n 5000 '$LL' | grep -cE '\\.(ERROR|CRITICAL|ALERT|EMERGENCY):'"
  run "top repeated errors (last 5000 lines, normalised)" bash -c "tail -n 5000 '$LL' | grep -E '\\.(ERROR|CRITICAL):' | sed -E 's/^\\[[^]]+\\] //; s/[0-9a-f]{8}-[0-9a-f-]{27}/{uuid}/g; s/[0-9]+/N/g' | cut -c1-200 | sort | uniq -c | sort -rn | head -n 15"
  run "timeouts / lock / deadlock / redis errors (last 5000 lines)" bash -c "tail -n 5000 '$LL' | grep -iE 'Maximum execution time|timed out|Lock wait timeout|Deadlock|Too many connections|RedisException|Connection refused|read error on connection|LockTimeoutException|could not be acquired' | cut -c1-300 | tail -n 25"
  run "tail (last 60 lines)" bash -c "tail -n 60 '$LL' | cut -c1-400"
else
  say "  no storage/logs/laravel*.log found"
fi
for lg in autofinx-runtime-master.log future-trade-cron.log nexus-cron.log; do
  [ -r "$APP_DIR/storage/logs/$lg" ] && run "storage/logs/$lg (tail 20)" tail -n 20 "$APP_DIR/storage/logs/$lg"
done
have supervisorctl && run "supervisorctl status" supervisorctl status

# ---------------------------------------------------------------------------
if [ "$SAMPLES" -gt 0 ]; then
  section "9. Live sampling (${SAMPLES}x every ${INTERVAL}s): FPM workers | FPM Recv-Q | MySQL Threads_running | Redis PING ms | origin /up total"
  first_pool=""; first_listen=""
  if [ "${#POOL_FILES[@]}" -gt 0 ]; then
    first_pool="$(grep -m1 -oE '^\[[^]]+\]' "${POOL_FILES[0]}" 2>/dev/null | tr -d '[]')"
    first_listen="$(grep -m1 -E '^[[:space:]]*listen[[:space:]]*=' "${POOL_FILES[0]}" 2>/dev/null | sed -E 's/.*=[[:space:]]*//')"
  fi
  REDISCLI_AUTH="$(env_get REDIS_PASSWORD)"; [ "$REDISCLI_AUTH" = "null" ] && REDISCLI_AUTH=""; export REDISCLI_AUTH
  for i in $(seq 1 "$SAMPLES"); do
    w="-"; [ -n "$first_pool" ] && w="$(ps -eo args= | grep -cE "^php-fpm: pool ${first_pool}[[:space:]]*\$")"
    rq="-"; [ -n "$first_listen" ] && have ss && rq="$(ss -xl 2>/dev/null | awk -v s="$first_listen" 'index($0, s) {print $3; exit}')"
    tr_="-"; [ -n "$CNF" ] && tr_="$(myq "SHOW GLOBAL STATUS LIKE 'Threads_running'" | awk '{print $2}')"
    rp="-"; if have redis-cli; then t0="$(date +%s%N)"; timeout 5 redis-cli "${RARGS[@]}" PING >/dev/null 2>&1; t1="$(date +%s%N)"; rp="$(( (t1 - t0) / 1000000 ))"; fi
    up="-"; have curl && up="$(curl -s -o /dev/null --max-time 20 -k --resolve "$DOMAIN:$PORT:127.0.0.1" -w '%{time_total}' "$BASE/up" 2>/dev/null)"
    say "  $(date -u +%H:%M:%S) fpm_workers=$w recv_q=${rq:-?} mysql_running=${tr_:-?} redis_ping_ms=$rp origin_up_s=$up"
    [ "$i" -lt "$SAMPLES" ] && sleep "$INTERVAL"
  done
  unset REDISCLI_AUTH
fi

# ---------------------------------------------------------------------------
section "10. Signals (automatic heuristics -- evidence to read, not a diagnosis)"
if [ "$FPM_MAX_TOTAL" -gt 0 ]; then say "  FPM: $FPM_WORKERS_TOTAL worker(s) now vs pm.max_children total $FPM_MAX_TOTAL across matched pools"; fi
if [ "${#SIGNALS[@]}" -eq 0 ]; then
  say "  No automatic signal fired. Compare sections 4 (FPM), 2 (LVE faults), 6 (MySQL) and 7 (origin vs public timing) manually;"
  say "  if origin timings are fast while public ones are slow, look at the proxy/CDN/network path, not PHP."
else
  for s in "${SIGNALS[@]}"; do say "  - $s"; done
fi
say ""
say "Report written to $REPORT (mode 600). Nothing on the system was changed."
