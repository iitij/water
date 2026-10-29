#!/usr/bin/env bash
set -Eeuo pipefail

# Run after uploading source and locally built public/build, without local secrets.
[[ ${EUID} -eq 0 ]] || { echo 'Run this script with sudo.' >&2; exit 1; }
APP_USER=${1:?Usage: deploy.sh DEPLOY_USER HOST}
APP_HOST=${2:?Usage: deploy.sh DEPLOY_USER HOST}
[[ $APP_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || exit 2
[[ $APP_HOST =~ ^[A-Za-z0-9.-]+$ ]] || exit 2
APP_DIR=/var/www/water
id "$APP_USER" >/dev/null
[[ -f $APP_DIR/artisan && -f $APP_DIR/composer.lock && -f $APP_DIR/public/build/manifest.json ]] || {
    echo 'Upload Laravel source, lockfiles, and built public/build first.' >&2; exit 2;
}
exec 9>/run/lock/water-deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 2; }
cd "$APP_DIR"
chown -R "$APP_USER":www-data "$APP_DIR"
install -d -o "$APP_USER" -g www-data -m 2775 storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [[ ! -f .env ]]; then
    if mariadb -Nse "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='water'" | grep -q .; then
        echo 'A water database already exists but .env is absent. Restore its environment before deploying.' >&2
        exit 2
    fi
    umask 077
    DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
    APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
    mariadb <<SQL
CREATE DATABASE water CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'water'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON water.* TO 'water'@'127.0.0.1';
SQL
    cat > .env <<EOF
APP_NAME="مشروع مياه الوشاح"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=http://$APP_HOST
APP_TIMEZONE=Asia/Aden
APP_LOCALE=ar
APP_FALLBACK_LOCALE=ar
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=water
DB_USERNAME=water
DB_PASSWORD=$DB_PASSWORD
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
EOF
    unset DB_PASSWORD APP_KEY
fi
chown "$APP_USER":www-data .env
chmod 640 .env
umask 0022

# Always back up the database before migrations. Local root connects over its Unix socket.
install -d -m 700 /var/backups/water
BACKUP=/var/backups/water/before-deploy-$(date -u +%Y%m%dT%H%M%SZ)-$$.sql.gz
mariadb-dump --single-transaction --routines --triggers water | gzip > "$BACKUP"
chmod 600 "$BACKUP"

runuser -u "$APP_USER" -- composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
runuser -u "$APP_USER" -- env PUPPETEER_SKIP_DOWNLOAD=true npm ci --omit=dev --ignore-scripts --no-audit --no-fund
runuser -u "$APP_USER" -- php artisan optimize:clear
runuser -u "$APP_USER" -- php artisan migrate --force
runuser -u "$APP_USER" -- php artisan storage:link
runuser -u "$APP_USER" -- php artisan config:cache
runuser -u "$APP_USER" -- php artisan view:cache
find storage bootstrap/cache -type d -exec chmod 2775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
chown -R "$APP_USER":www-data storage bootstrap/cache

cat > /etc/systemd/system/water-queue.service <<'EOF'
[Unit]
Description=Water Laravel queue worker
After=network.target mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/water
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --timeout=60 --memory=96 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=75
UMask=0002
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/water-scheduler.service <<'EOF'
[Unit]
Description=Water Laravel scheduled tasks
After=mariadb.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/water
ExecStart=/usr/bin/php artisan schedule:run
UMask=0002
EOF
cat > /etc/systemd/system/water-scheduler.timer <<'EOF'
[Unit]
Description=Run Water scheduler each minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable water-queue.service water-scheduler.timer
systemctl restart php8.4-fpm water-queue.service water-scheduler.timer
nginx -t
systemctl reload nginx
curl --fail --silent --show-error --max-time 20 -H "Host: $APP_HOST" http://127.0.0.1/up
echo
echo 'Deployment completed. No demo users or financial records were created.'
echo 'Configure HTTPS before using real credentials or customer data over the public network.'
