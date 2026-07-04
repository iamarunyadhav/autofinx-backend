#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"
RUN_USER="${RUN_USER:-zcadmin}"
LOG_DIR="${LOG_DIR:-/var/log/autofinx}"
WATCHDOG_INTERVAL_SECONDS="${LIVE_FEED_WATCHDOG_INTERVAL_SECONDS:-3}"
WATCHDOG_STALE_SECONDS="${LIVE_FEED_WATCHDOG_STALE_SECONDS:-8}"
WATCHDOG_RESTART_COOLDOWN_SECONDS="${LIVE_FEED_WATCHDOG_RESTART_COOLDOWN_SECONDS:-12}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run this script as root."
  exit 1
fi

if [ ! -d "$APP_DIR" ]; then
  echo "APP_DIR not found: $APP_DIR"
  exit 1
fi

if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found. Set PHP_BIN=/usr/local/bin/ea-php82 and retry."
  exit 1
fi

if [ -d /etc/supervisord.d ]; then
  SUP_CONF="/etc/supervisord.d/autofinx-live-feed-watchdog.ini"
elif [ -d /etc/supervisor/conf.d ]; then
  SUP_CONF="/etc/supervisor/conf.d/autofinx-live-feed-watchdog.conf"
else
  echo "Supervisor config directory not found."
  exit 1
fi

mkdir -p "$LOG_DIR"
chmod +x "$APP_DIR/future-trade-live-feed-watchdog.sh"
chmod +x "$APP_DIR/autofinx-dedupe-runtime.sh" 2>/dev/null || true
chown "$RUN_USER:$RUN_USER" "$APP_DIR/future-trade-live-feed-watchdog.sh" || true
chown "$RUN_USER:$RUN_USER" "$APP_DIR/autofinx-dedupe-runtime.sh" 2>/dev/null || true
chown -R "$RUN_USER:$RUN_USER" "$LOG_DIR" || true

cat >"$SUP_CONF" <<EOF
[program:autofinx-live-feed-watchdog]
process_name=%(program_name)s
command=$APP_DIR/future-trade-live-feed-watchdog.sh
directory=$APP_DIR
autostart=true
autorestart=true
startsecs=3
startretries=10
stopwaitsecs=10
killasgroup=true
stopasgroup=true
user=$RUN_USER
redirect_stderr=false
stdout_logfile=$LOG_DIR/live-feed-watchdog.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=$LOG_DIR/live-feed-watchdog.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=APP_DIR="$APP_DIR",PHP="$PHP_BIN",LIVE_FEED_WATCHDOG_INTERVAL_SECONDS="$WATCHDOG_INTERVAL_SECONDS",LIVE_FEED_WATCHDOG_STALE_SECONDS="$WATCHDOG_STALE_SECONDS",LIVE_FEED_WATCHDOG_RESTART_COOLDOWN_SECONDS="$WATCHDOG_RESTART_COOLDOWN_SECONDS"
EOF

chown "$RUN_USER:$RUN_USER" "$SUP_CONF" || true

supervisorctl reread
supervisorctl update
supervisorctl restart autofinx-live-feed-watchdog || supervisorctl start autofinx-live-feed-watchdog

sleep 5
supervisorctl status autofinx-live-feed-watchdog
tail -n 60 "$LOG_DIR/live-feed-watchdog.out.log" || true
