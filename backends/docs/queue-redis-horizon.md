# Queue, Redis, and Horizon Setup

## Recommended Production Queue Mode

Use Redis for production queue workers:

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
HORIZON_ENABLED=true
OPS_QUEUE_BACKLOG_WARNING=100
OPS_QUEUE_BACKLOG_BLOCK=500
QUEUE_DASHBOARD_ALLOW_CLEAR=true
```

## Priority Queue Order

Workers should consume queues in this order:

```bash
critical,execution,market,signals,ops,intelligence,risk,analytics,notifications,default
```

Live exits and execution must stay ahead of analytics, reports, and notifications.

## Laravel Horizon

Install Horizon only after Redis is available on the server:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan migrate --force
php artisan optimize:clear
```

Then run Horizon under Supervisor:

```ini
[program:autofinx-horizon]
process_name=%(program_name)s
command=php /var/www/autofinx-backend/backends/artisan horizon
directory=/var/www/autofinx-backend/backends
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/autofinx-backend/backends/storage/logs/horizon.log
stopwaitsecs=3600
```

Apply Supervisor changes:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start autofinx-horizon
```

## Without Horizon

Use Supervisor workers directly:

```ini
[program:autofinx-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/autofinx-backend/backends/artisan queue:work redis --queue=critical,execution,market,signals,ops,intelligence,risk,analytics,notifications,default --sleep=1 --tries=3 --timeout=120 --max-time=3600
directory=/var/www/autofinx-backend/backends
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/autofinx-backend/backends/storage/logs/queue-worker.log
stopwaitsecs=3600
```

## Jobs Dashboard Safety

The Jobs dashboard can:

- Show queue backlog, failed jobs, priority queues, scheduler history, and Redis/Horizon readiness.
- Send `queue:restart` so workers restart safely after the current job.
- Retry failed jobs after reviewing the exception.
- Forget failed jobs only when the error is not retryable.
- Clear pending jobs per queue only when the backlog is stale or non-critical.

Do not clear `critical` or `execution` queues while live positions are open unless the pending jobs are confirmed stale.
