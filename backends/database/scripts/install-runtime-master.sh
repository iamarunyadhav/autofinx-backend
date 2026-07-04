#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"
RUN_USER="${RUN_USER:-zcadmin}"
LOG_DIR="${LOG_DIR:-/var/log/autofinx}"
MASTER_INTERVAL_SECONDS="${AUTOFINX_MASTER_INTERVAL_SECONDS:-5}"
LIVE_FEED_STALE_SECONDS="${AUTOFINX_MASTER_LIVE_FEED_STALE_SECONDS:-8}"

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
  SUP_CONF="/etc/supervisord.d/autofinx-runtime-master.ini"
elif [ -d /etc/supervisor/conf.d ]; then
  SUP_CONF="/etc/supervisor/conf.d/autofinx-runtime-master.conf"
else
  echo "Supervisor config directory not found."
  exit 1
fi

mkdir -p "$LOG_DIR"
chmod +x "$APP_DIR/autofinx-runtime-master.sh"
chmod +x "$APP_DIR/autofinx-dedupe-runtime.sh" 2>/dev/null || true
chown "$RUN_USER:$RUN_USER" "$APP_DIR/autofinx-runtime-master.sh" "$APP_DIR/autofinx-dedupe-runtime.sh" 2>/dev/null || true
chown -R "$RUN_USER:$RUN_USER" "$LOG_DIR" || true

if supervisorctl status autofinx-live-feed-watchdog >/dev/null 2>&1; then
  supervisorctl stop autofinx-live-feed-watchdog || true
  supervisorctl remove autofinx-live-feed-watchdog || true
fi
rm -f /etc/supervisord.d/autofinx-live-feed-watchdog.ini /etc/supervisor/conf.d/autofinx-live-feed-watchdog.conf

cat >"$SUP_CONF" <<EOF
[program:autofinx-runtime-master]
process_name=%(program_name)s
command=$APP_DIR/autofinx-runtime-master.sh daemon
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
stdout_logfile=$LOG_DIR/runtime-master.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=$LOG_DIR/runtime-master.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=APP_DIR="$APP_DIR",PHP="$PHP_BIN",AUTOFINX_MASTER_INTERVAL_SECONDS="$MASTER_INTERVAL_SECONDS",AUTOFINX_MASTER_LIVE_FEED_STALE_SECONDS="$LIVE_FEED_STALE_SECONDS"
EOF

supervisorctl reread
supervisorctl update
supervisorctl restart autofinx-runtime-master || supervisorctl start autofinx-runtime-master

APP_DIR="$APP_DIR" PHP="$PHP_BIN" "$APP_DIR/autofinx-runtime-master.sh" cleanup-cron
APP_DIR="$APP_DIR" PHP="$PHP_BIN" "$APP_DIR/autofinx-runtime-master.sh" once
