#!/usr/bin/env bash
set -Eeuo pipefail

# Run as root on the target Debian 13 VM. No application data is seeded.
[[ ${EUID} -eq 0 ]] || { echo 'Run this script with sudo.' >&2; exit 1; }
APP_USER=${1:?Usage: bootstrap-debian.sh DEPLOY_USER HOST}
APP_HOST=${2:?Usage: bootstrap-debian.sh DEPLOY_USER HOST}
[[ $APP_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || exit 2
[[ $APP_HOST =~ ^[A-Za-z0-9.-]+$ ]] || exit 2
id "$APP_USER" >/dev/null
[[ -f /etc/debian_version ]] || { echo 'Debian is required.' >&2; exit 2; }

if [[ $(awk '/MemTotal/ {print $2}' /proc/meminfo) -lt 1572864 ]] &&
   [[ $(awk '/SwapTotal/ {print $2}' /proc/meminfo) -eq 0 ]]; then
    if [[ ! -e /swap-water ]]; then
        [[ $(df --output=avail -k / | tail -1) -gt 2097152 ]] || {
            echo 'At least 2 GiB free disk space is required for swap.' >&2; exit 2;
        }
        fallocate -l 1G /swap-water
        chmod 600 /swap-water
        mkswap /swap-water
    fi
    swapon /swap-water
    grep -q '^/swap-water ' /etc/fstab || printf '/swap-water none swap sw 0 0\n' >> /etc/fstab
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends nginx mariadb-server composer unzip git ca-certificates \
    php8.4-cli php8.4-fpm php8.4-mysql php8.4-intl php8.4-gd php8.4-zip \
    php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-curl \
    nodejs npm chromium fonts-noto-core

install -d -o "$APP_USER" -g www-data -m 2755 /var/www/water
cat > /etc/php/8.4/fpm/pool.d/water.conf <<'EOF'
[water]
user = www-data
group = www-data
listen = /run/php/water.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 300
php_admin_value[memory_limit] = 128M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 24M
php_admin_flag[display_errors] = off
EOF

cat > /etc/php/8.4/fpm/conf.d/99-water.ini <<'EOF'
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 64
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
EOF

cat > /etc/mysql/mariadb.conf.d/60-water.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
innodb_buffer_pool_size = 64M
max_connections = 20
table_open_cache = 128
performance_schema = OFF
EOF

cat > /etc/nginx/sites-available/water <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name WATER_HOST;
    root /var/www/water/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    client_max_body_size 24m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/water.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }
}
EOF
sed -i "s/WATER_HOST/$APP_HOST/g" /etc/nginx/sites-available/water
ln -sfn /etc/nginx/sites-available/water /etc/nginx/sites-enabled/water
nginx -t
systemctl enable mariadb php8.4-fpm nginx
systemctl restart mariadb php8.4-fpm nginx
echo 'Native runtime installed. Upload the application, then run deploy.sh.'
