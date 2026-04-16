# AutoFinx Contabo VPS / Linux Production Runbook

## 1. Purpose

இந்த guide specifically:

- **Contabo VPS / Linux production**
- **Ubuntu-style server**
- **Binance spot restricted live deployment**
- **Auto Bot + AI + exit engine + ops readiness**

க்கு எழுதப்பட்டுள்ளது.

This is the safest current production shape for this repo:

- backend: Laravel
- frontend: built React static app
- database: MySQL/MariaDB
- cache/optional queues: Redis
- process manager: Supervisor
- web server: Nginx
- scheduler: cron + Laravel scheduler

---

## 2. Honest deployment target

This repo should be treated like this in production:

- **Spot paper / sandbox**: yes
- **Spot restricted live**: yes, if readiness passes
- **Auto Bot**: yes, supervised and guarded
- **AI restricted auto**: yes, only inside policy boundaries
- **Futures live**: no
- **Unattended full autonomy**: no

So this runbook is for:

- launch the application properly
- connect Binance properly
- keep scheduler / queues / health / readiness running
- activate spot automation carefully

---

## 3. Recommended server sizing

For first real deployment on Contabo:

- CPU: 4 vCPU or better
- RAM: 8 GB minimum
- Disk: SSD/NVMe
- OS: Ubuntu 22.04 LTS or 24.04 LTS

If you run:

- backend
- frontend static hosting
- MySQL
- Redis
- queue workers
- user stream consumer

on the same VPS, 8 GB is a practical minimum.

---

## 4. Suggested domain layout

Recommended simple setup:

- frontend: `https://app.yourdomain.com`
- backend API: `https://api.yourdomain.com`

Alternative simpler setup:

- frontend static files served from same domain
- API under `/api`

Because this frontend mainly uses bearer tokens, same-domain is not mandatory. But for clean CORS and ops clarity, split subdomains are fine.

---

## 5. First server prep

SSH into Contabo VPS as root:

```bash
ssh root@YOUR_SERVER_IP
```

Update system:

```bash
apt update && apt upgrade -y
timedatectl set-timezone Asia/Colombo
```

Create deploy user:

```bash
adduser deploy
usermod -aG sudo deploy
```

Optional but recommended SSH hardening:

- disable password login after keys are working
- disable root SSH login

Firewall:

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

---

## 6. Install required packages

For Ubuntu:

```bash
apt install -y nginx mysql-server redis-server supervisor git unzip curl software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-mysql php8.2-sqlite3 php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-bcmath php8.2-intl php8.2-redis composer
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

Enable services:

```bash
systemctl enable nginx
systemctl enable mysql
systemctl enable redis-server
systemctl enable supervisor
```

---

## 7. MySQL database setup

Login:

```bash
mysql -u root -p
```

Create DB and user:

```sql
CREATE DATABASE autofinx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'autofinx'@'127.0.0.1' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON autofinx.* TO 'autofinx'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

---

## 8. Project folder layout

Recommended path:

```bash
mkdir -p /var/www
chown deploy:deploy /var/www
su - deploy
cd /var/www
git clone <your-repo-url> auto_finx
cd auto_finx
```

Expected important folders:

- `/var/www/auto_finx/backend`
- `/var/www/auto_finx/frontend/sentinel-ai-trader`

---

## 9. Backend install on server

As `deploy`:

```bash
cd /var/www/auto_finx/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```bash
nano .env
```

Recommended starting production values:

```env
APP_NAME=AutoFinx
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

FRONTEND_URL=https://app.yourdomain.com
FRONTEND_URL_ALT=https://yourdomain.com
SANCTUM_STATEFUL_DOMAINS=app.yourdomain.com,yourdomain.com,api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autofinx
DB_USERNAME=autofinx
DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

TRADING_MODE=paper
LIVE_TRADING_ENABLED=false
LIVE_TRADING_REQUIRE_2FA=true
LIVE_TRADING_SYMBOL_ALLOWLIST=BTCUSDT,ETHUSDT
LIVE_TRADING_MAX_EXPOSURE_PCT=20
LIVE_TRADING_MAX_TRADE_PCT=2
LIVE_TRADING_STALE_FEED_THRESHOLD_SEC=60

BINANCE_ENABLED=true
BINANCE_API_URL=https://api.binance.com
BINANCE_WS_URL=wss://stream.binance.com:9443
BINANCE_TESTNET_API_URL=https://testnet.binance.vision
BINANCE_TESTNET_WS_URL=wss://testnet.binance.vision
BINANCE_FUTURES_API_URL=https://fapi.binance.com
BINANCE_FUTURES_TESTNET_API_URL=https://testnet.binancefuture.com
BINANCE_USER_STREAM_KEEPALIVE_SEC=1800

AI_MODE=ADVISORY
AI_TRADE_PROVIDER=heuristic
AI_TRADE_OPENAI_API_KEY=

EXCHANGE_SYNC_CRON=*/10 * * * *
```

Why `QUEUE_CONNECTION=database` here:

- easiest first production launch
- less moving parts
- current repo already defaults to it

If later you want Redis queues, switch after system is stable.

Run migrations:

```bash
php artisan migrate --force
php artisan migrate:status
```

Then:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Important:

- if `migrate:status` shows required pending migrations, do **not** go live yet

---

## 10. Frontend build on server

As `deploy`:

```bash
cd /var/www/auto_finx/frontend/sentinel-ai-trader
npm ci
```

Create frontend env file:

```bash
cat > .env.production <<'EOF'
VITE_API_BASE_URL=https://api.yourdomain.com/api/v1
EOF
```

Build:

```bash
npm run build
```

Build output:

- `/var/www/auto_finx/frontend/sentinel-ai-trader/dist`

---

## 11. Nginx setup

## API server block

Create:

```bash
sudo nano /etc/nginx/sites-available/autofinx-api
```

Config:

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;

    root /var/www/auto_finx/backend/public;
    index index.php index.html;

    client_max_body_size 25M;

    access_log /var/log/nginx/autofinx-api-access.log;
    error_log /var/log/nginx/autofinx-api-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## Frontend server block

Create:

```bash
sudo nano /etc/nginx/sites-available/autofinx-app
```

Config:

```nginx
server {
    listen 80;
    server_name app.yourdomain.com;

    root /var/www/auto_finx/frontend/sentinel-ai-trader/dist;
    index index.html;

    access_log /var/log/nginx/autofinx-app-access.log;
    error_log /var/log/nginx/autofinx-app-error.log;

    location / {
        try_files $uri /index.html;
    }
}
```

Enable:

```bash
sudo ln -s /etc/nginx/sites-available/autofinx-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/autofinx-app /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 12. SSL with Let’s Encrypt

Install certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

Request certs:

```bash
sudo certbot --nginx -d api.yourdomain.com -d app.yourdomain.com
```

Auto-renew test:

```bash
sudo certbot renew --dry-run
```

After SSL, verify:

- `APP_URL=https://api.yourdomain.com`
- `FRONTEND_URL=https://app.yourdomain.com`

If you use same domain, adjust accordingly.

---

## 13. File permissions

From `backend`:

```bash
cd /var/www/auto_finx/backend
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

---

## 14. Queue workers with Supervisor

Create worker config:

```bash
sudo nano /etc/supervisor/conf.d/autofinx-queue.conf
```

Config:

```ini
[program:autofinx-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/auto_finx/backend/artisan queue:work --queue=execution,reconcile,strategies,assistant,monitoring,billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120 --max-time=3600
directory=/var/www/auto_finx/backend
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/auto_finx/backend/storage/logs/queue-worker.log
stopwaitsecs=360
```

Why `numprocs=2`:

- enough for first production deployment
- not too heavy

Activate:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start autofinx-queue:*
sudo supervisorctl status
```

---

## 15. Optional dedicated Binance user-stream process

For spot live, this is strongly recommended.

Create:

```bash
sudo nano /etc/supervisor/conf.d/autofinx-userstream.conf
```

Config:

```ini
[program:autofinx-userstream]
command=/usr/bin/php /var/www/auto_finx/backend/artisan trading:binance-user-stream --workspace=YOUR_WORKSPACE_UUID
directory=/var/www/auto_finx/backend
autostart=true
autorestart=true
user=deploy
redirect_stderr=true
stdout_logfile=/var/www/auto_finx/backend/storage/logs/binance-userstream.log
stopwaitsecs=30
```

Activate:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start autofinx-userstream
sudo supervisorctl status
```

Use this only after:

- Binance connection exists
- workspace chosen

If workspace changes, update the config.

---

## 16. Scheduler with cron

Do **not** run `schedule:work` in production unless you intentionally want that style.

Use cron:

```bash
crontab -e
```

Add:

```cron
* * * * * cd /var/www/auto_finx/backend && php artisan schedule:run >> /dev/null 2>&1
```

Reason:

- Laravel scheduler already handles sub-minute tasks defined in the app
- cron every minute is the correct production pattern

---

## 17. What scheduler and queues do in production

### Scheduler handles

- connection sync
- bot evaluation dispatch
- AI decision cycle dispatch
- exit monitoring dispatch
- stale feed detection
- ops readiness checks
- ops maturity cycle
- order status polling
- user-stream keepalive

### Queue workers handle

- signal execution
- reconciliation
- AI jobs
- monitoring jobs
- billing/risk/ops async jobs

If scheduler runs but queue workers are dead:

- decisions may get dispatched
- but actual execution / reconciliation may stall

If queue workers run but scheduler is dead:

- periodic bot / AI / ops / exit cycles will not trigger

So both are mandatory.

---

## 18. Health check commands after launch

From `backend`:

```bash
php artisan migrate:status
php artisan ops:readiness-check --workspace=YOUR_WORKSPACE_UUID
php artisan ops:maturity-cycle --workspace=YOUR_WORKSPACE_UUID
php artisan connections:sync --workspace=YOUR_WORKSPACE_UUID
```

Check supervisor:

```bash
sudo supervisorctl status
```

Check logs:

```bash
tail -f /var/www/auto_finx/backend/storage/logs/laravel.log
tail -f /var/www/auto_finx/backend/storage/logs/queue-worker.log
tail -f /var/www/auto_finx/backend/storage/logs/binance-userstream.log
```

Check nginx:

```bash
sudo tail -f /var/log/nginx/autofinx-api-error.log
sudo tail -f /var/log/nginx/autofinx-app-error.log
```

---

## 19. Binance account setup for live

### Safe recommended setup

1. Use a dedicated Binance account or sub-account.
2. Keep very small balance first.
3. Enable Binance 2FA.
4. Create API key.
5. Restrict API key to your Contabo server public IP.
6. Enable read + trade only.
7. Disable withdrawals.
8. Start with spot only.

Do not start with:

- futures
- leverage
- full main account funds
- many symbols

---

## 20. AutoFinx-side Binance setup

You should configure **both** of these:

### A. Exchange Connection

In the app:

- create connection
- `provider = binance`
- `is_testnet = true` first
- API key
- API secret
- test it

This is the practical current credential path for bot spot execution.

### B. Integration

Also create a Binance integration:

- `type = exchange`
- `provider_key = binance`
- `display_name = Binance`
- add same API credentials
- run health check

Reason:

- readiness and some newer subsystems check active integrations

---

## 21. Workspace production config order

Do this in the app in this order:

1. login as operator
2. enable 2FA
3. create workspace
4. add Exchange Connection
5. add Integration
6. create/update risk profile
7. configure exit policy
8. configure AI trading only if needed
9. keep trading mode `paper` first
10. keep `Auto Trading` OFF initially
11. run readiness checks
12. test paper / sandbox behavior

Only after this:

13. switch to live-capable workspace config
14. turn `Auto Trading` ON
15. start bot

---

## 22. Workspace trading settings for first live run

Recommended first live settings:

- `trading_mode = live`
- `live_enabled = true`
- `venue_environment = live`
- `preferred_provider = binance`
- `auto_trading_enabled = false` initially
- `bot_default_interval_seconds = 3600`

Then create bot:

- `interval_sec = 3600`
- `is_live = true`
- symbols: start with `BTCUSDT` only
- allocation: small fixed amount
- high confidence
- strict stop-loss
- take-profit enabled
- trailing enabled
- time-stop enabled

Then:

- turn `Auto Trading` ON
- start bot

---

## 23. Exact go-live order

This is the clean order I would use on Contabo:

### Stage 1: backend/frontend deploy

1. backend deploy
2. frontend build
3. Nginx active
4. Supervisor active
5. cron active

### Stage 2: app-level config

1. workspace created
2. 2FA confirmed
3. connection added
4. integration added
5. risk profile ready
6. exit policy ready

### Stage 3: paper/sandbox proof

1. `TRADING_MODE=paper`
2. `LIVE_TRADING_ENABLED=false`
3. run bot in paper mode
4. inspect logs / monitor / exits / ops

### Stage 4: tiny live cutover

1. enable live mode in app + env only after proof
2. use tiny capital
3. one symbol
4. one bot
5. one open trade max
6. monitor first full sessions manually

---

## 24. Deployment command sequence for updates

When you push a new version:

```bash
cd /var/www/auto_finx
git pull

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart

cd ../frontend/sentinel-ai-trader
npm ci
npm run build

sudo systemctl reload nginx
sudo supervisorctl restart autofinx-queue:*
sudo supervisorctl restart autofinx-userstream
```

Then verify:

```bash
cd /var/www/auto_finx/backend
php artisan ops:readiness-check --workspace=YOUR_WORKSPACE_UUID
```

---

## 25. What can break in production

Common failure points:

- cron not running
- queue workers dead
- nginx root wrong
- PHP-FPM permissions issue
- pending migrations
- Binance connection exists but integration missing
- workspace still in paper mode
- `Auto Trading` switch OFF
- kill switch active
- readiness blocked by 2FA or missing secrets

So when bot “does nothing”, always check:

1. queue worker alive?
2. cron alive?
3. readiness blocked?
4. connection active?
5. integration active?
6. auto trading ON?
7. bot status RUNNING?
8. workspace live mode correct?

---

## 26. Safe first production recommendation

For your first Contabo production launch:

- launch **spot only**
- start with **paper**
- then **Binance spot testnet**
- then **tiny restricted live**
- keep **futures disabled**
- keep **AI in advisor/manual/restricted-auto only**
- keep **user stream consumer running**

That is the correct order.

---

## 27. Final practical conclusion

Yes, you can launch this on a Contabo VPS/Linux production server.

But the right target is:

- **guarded spot automation**
- not reckless full-autonomy

If you want, next I can prepare two more things for you:

1. a **copy-paste production `.env` template** for your exact domain names
2. ready-to-use **Supervisor + Nginx config files** with your chosen domain structure

