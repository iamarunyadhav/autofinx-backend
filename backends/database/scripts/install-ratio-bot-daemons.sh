#!/usr/bin/env bash
set -euo pipefail

# Installs the two daemons Ratio Bot needs to run safely IN PARALLEL with
# Future Trade Bot / Robot Trade / Quick Trade, without stealing their shared
# queue-market worker or getting killed by autofinx-verify.sh's legacy-kill
# pass (that pattern was removed for market:ws-consume — see autofinx-verify.sh).
#
#   autofinx-ratio-feed        -> php artisan market:ws-consume (public WS,
#                                  no API key needed; --symbols defaults to
#                                  whatever Ratio Bot configs are active)
#   autofinx-trading:queue-ratio -> php artisan queue:work --queue=ratio_bot
#                                  (its OWN worker, not shared with
#                                  signals/risk/billing on queue-market)
#
# Mirrors the pattern in install-trading-supervisor-daemons.sh.

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"

if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found. Set PHP_BIN=/usr/local/bin/ea-php82 and retry."
  exit 1
fi

if [ ! -d "$APP_DIR" ]; then
  echo "APP_DIR not found: $APP_DIR"
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  SUDO="sudo"
else
  SUDO=""
fi

echo "Using APP_DIR=$APP_DIR"
echo "Using PHP_BIN=$PHP_BIN"

$SUDO mkdir -p /var/log/autofinx

if [ -d /etc/supervisord.d ]; then
  SUP_CONF="/etc/supervisord.d/autofinx-ratio-bot.ini"
elif [ -d /etc/supervisor/conf.d ]; then
  SUP_CONF="/etc/supervisor/conf.d/autofinx-ratio-bot.conf"
else
  echo "Supervisor config directory not found"
  exit 1
fi

$SUDO tee "$SUP_CONF" >/dev/null <<EOF
[program:autofinx-ratio-feed]
process_name=%(program_name)s
command=$PHP_BIN artisan market:ws-consume --market-type=spot --store-ohlc --reconnect-sleep=3
directory=$APP_DIR
autostart=true
autorestart=true
startsecs=5
startretries=10
stopwaitsecs=30
killasgroup=true
stopasgroup=true
user=zcadmin
redirect_stderr=false
stdout_logfile=/var/log/autofinx/ratio-feed.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=/var/log/autofinx/ratio-feed.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5

[program:autofinx-trading:queue-ratio]
process_name=%(program_name)s
command=$PHP_BIN artisan queue:work --queue=ratio_bot --sleep=1 --tries=3 --max-time=3600
directory=$APP_DIR
autostart=true
autorestart=true
startsecs=5
startretries=10
stopwaitsecs=30
killasgroup=true
stopasgroup=true
user=zcadmin
redirect_stderr=false
stdout_logfile=/var/log/autofinx/queue-ratio.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=/var/log/autofinx/queue-ratio.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
EOF

$SUDO chown -R zcadmin:zcadmin /var/log/autofinx || true

cd "$APP_DIR"

echo "Reloading supervisor"
$SUDO supervisorctl reread
$SUDO supervisorctl update

echo "Starting programs"
$SUDO supervisorctl restart autofinx-ratio-feed || $SUDO supervisorctl start autofinx-ratio-feed || true
$SUDO supervisorctl restart "autofinx-trading:queue-ratio" || $SUDO supervisorctl start "autofinx-trading:queue-ratio" || true

sleep 5

echo "Supervisor status"
$SUDO supervisorctl status | grep -i "ratio" || $SUDO supervisorctl status

echo "Ratio feed log"
$SUDO tail -n 50 /var/log/autofinx/ratio-feed.out.log || true
$SUDO tail -n 50 /var/log/autofinx/ratio-feed.err.log || true

echo "Queue-ratio log"
$SUDO tail -n 50 /var/log/autofinx/queue-ratio.out.log || true
$SUDO tail -n 50 /var/log/autofinx/queue-ratio.err.log || true
