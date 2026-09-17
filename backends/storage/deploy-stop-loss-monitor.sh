#!/bin/bash
set -e

# 1. Find the real Laravel app path
ARTISAN_PATH=$(find / -maxdepth 5 -name "artisan" -path "*backends*" 2>/dev/null | head -1)
if [ -z "$ARTISAN_PATH" ]; then
  echo "artisan not found — check the path manually"
  exit 1
fi
APP_DIR=$(dirname "$ARTISAN_PATH")
echo "Found Laravel app at: $APP_DIR"

# 2. Run pending migrations
cd "$APP_DIR"
php artisan migrate --force

# 3. Create the supervisor config with the correct artisan path
cat > /etc/supervisor/conf.d/quick-trade-stop-loss-monitor.conf << EOF
[program:autofinx-quick-trade-stop-loss-monitor]
process_name=%(program_name)s_%(process_num)02d
command=php $ARTISAN_PATH quick-trade:monitor-stop-loss --interval=2
autostart=true
autorestart=true
numprocs=1
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/autofinx-quick-trade-stop-loss-monitor.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stopwaitsecs=30
EOF

# 4. Reload supervisor
supervisorctl reread
supervisorctl update

# 5. Verify
sleep 2
supervisorctl status autofinx-quick-trade-stop-loss-monitor:*
