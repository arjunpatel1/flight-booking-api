#!/usr/bin/env bash
#
# One-command local setup for Ubuntu / Debian.
#
#   bash scripts/setup-local.sh
#
# Only Git is needed beforehand. Installs PHP 8.4 (+ FPM and extensions),
# Composer, nginx, MySQL, Redis and Node.js when missing, configures nginx and
# Reverb, creates .env and the database, runs migrations + seeders, provisions a
# demo tenant, prints the login details and starts the queue worker + Reverb
# (scripts/start-local.sh). Safe to re-run: every step is idempotent.
#
# Overridable environment variables:
#   DB_DATABASE      default: nexdine
#   DB_USERNAME      default: nexdine
#   DB_PASSWORD      default: secret
#   ROOT_DOMAIN      default: nexdine.test   (API: api.<root>, tenant: <slug>.<root>)
#   NGINX_PORT       default: 80
#   REVERB_PORT      default: 8080
#   TENANT_SLUG      default: demo
#   TENANT_PASSWORD  default: Demo@12345
#   SKIP_NPM=1       skip `npm install` (Puppeteer / Chrome download)
#   FRESH=1          drop all tables and re-seed (migrate:fresh)
#   NO_START=1       only set up; do not start the queue worker / Reverb
#
set -euo pipefail
cd "$(dirname "$0")/.."
PROJECT_DIR=$(pwd)

DB_DATABASE=${DB_DATABASE:-nexdine}
DB_USERNAME=${DB_USERNAME:-nexdine}
DB_PASSWORD=${DB_PASSWORD:-secret}
ROOT_DOMAIN=${ROOT_DOMAIN:-nexdine.test}
NGINX_PORT=${NGINX_PORT:-80}
REVERB_PORT=${REVERB_PORT:-8080}
TENANT_SLUG=${TENANT_SLUG:-demo}
TENANT_PASSWORD=${TENANT_PASSWORD:-Demo@12345}
PHP_VERSION=8.4

API_DOMAIN="api.${ROOT_DOMAIN}"
TENANT_DOMAIN="${TENANT_SLUG}.${ROOT_DOMAIN}"
TENANT_EMAIL="admin@${TENANT_DOMAIN}"
if [ "$NGINX_PORT" = 80 ]; then PORT_SUFFIX=''; else PORT_SUFFIX=":${NGINX_PORT}"; fi
APP_URL="http://${API_DOMAIN}${PORT_SUFFIX}"

# The developer account PHP-FPM runs as, so storage/ stays writable without chmod 777.
DEV_USER=${SUDO_USER:-$(id -un)}
DEV_GROUP=$(id -gn "$DEV_USER")

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
has()  { command -v "$1" >/dev/null 2>&1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=''; else SUDO='sudo'; fi
has apt-get || die "This script supports Ubuntu/Debian (apt). Use setup-local.cmd on Windows."

# Writes KEY=VALUE into .env, replacing an existing (or commented) line.
set_env() {
  local key=$1 value=$2
  if grep -qE "^#?\s*${key}=" .env; then
    sed -i -E "s|^#?\s*${key}=.*|${key}=${value}|" .env
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

# --- System packages ---------------------------------------------------------
step "Installing system packages"
$SUDO apt-get update -y
$SUDO apt-get install -y software-properties-common ca-certificates curl unzip git gnupg lsb-release acl

if ! apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
  step "Adding ondrej/php PPA for PHP ${PHP_VERSION}"
  $SUDO add-apt-repository -y ppa:ondrej/php
  $SUDO apt-get update -y
fi

step "Installing PHP ${PHP_VERSION}, PHP-FPM and extensions"
$SUDO apt-get install -y \
  "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-common" \
  "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-bcmath" \
  "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-redis" "php${PHP_VERSION}-gmp" "php${PHP_VERSION}-opcache"
$SUDO update-alternatives --set php "/usr/bin/php${PHP_VERSION}" 2>/dev/null || true

if ! has composer; then
  step "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  $SUDO php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

if ! has nginx; then
  step "Installing nginx"
  $SUDO apt-get install -y nginx
fi

if ! has mysql && ! has mariadb; then
  step "Installing MySQL server"
  $SUDO apt-get install -y mysql-server
fi
$SUDO systemctl enable --now mysql 2>/dev/null \
  || $SUDO systemctl enable --now mariadb 2>/dev/null \
  || $SUDO service mysql start 2>/dev/null || true

if ! has redis-server; then
  step "Installing Redis"
  $SUDO apt-get install -y redis-server
fi
$SUDO systemctl enable --now redis-server 2>/dev/null \
  || $SUDO service redis-server start 2>/dev/null || true

node_major=$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || echo 0)
if [ "${SKIP_NPM:-0}" != 1 ] && [ "${node_major:-0}" -lt 18 ]; then
  step "Installing Node.js 22 (NodeSource)"
  curl -fsSL https://deb.nodesource.com/setup_22.x | $SUDO -E bash -
  $SUDO apt-get install -y nodejs
fi

# --- Database ----------------------------------------------------------------
step "Creating MySQL database '${DB_DATABASE}' and user '${DB_USERNAME}'"
# Ubuntu's MySQL root uses socket auth, so run as root via sudo.
$SUDO mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# --- Application -------------------------------------------------------------
step "Configuring .env"
[ -f .env ] || cp .env.example .env
set_env APP_ENV local
set_env APP_DEBUG true
set_env APP_URL "$APP_URL"
set_env APP_INSTALLED true
set_env PUBLIC_DOMAIN "$ROOT_DOMAIN"
set_env API_DOMAIN "$API_DOMAIN"
set_env SAAS_ROOT_DOMAIN "$ROOT_DOMAIN"
set_env SAAS_CENTRAL_DOMAINS "localhost,127.0.0.1,${ROOT_DOMAIN},${API_DOMAIN}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_DATABASE"
set_env DB_USERNAME "$DB_USERNAME"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env REDIS_CLIENT phpredis
set_env REDIS_HOST 127.0.0.1
set_env REDIS_PORT 6379
set_env BROADCAST_CONNECTION reverb
set_env REVERB_HOST 127.0.0.1
set_env REVERB_PORT "$REVERB_PORT"
set_env REVERB_SCHEME http
set_env REVERB_SERVER_HOST 0.0.0.0
set_env REVERB_SERVER_PORT "$REVERB_PORT"
set_env REVERB_ALLOWED_ORIGINS "localhost,127.0.0.1,${ROOT_DOMAIN},${API_DOMAIN},${TENANT_DOMAIN}"
if ! grep -qE '^QR_SECRET=.+' .env; then
  set_env QR_SECRET "$(php -r 'echo bin2hex(random_bytes(32));')"
fi

step "Installing Composer dependencies"
composer install --no-interaction --prefer-dist

if ! grep -qE '^APP_KEY=.+' .env; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/{cache,sessions,views,testing} storage/logs storage/app/public bootstrap/cache
chmod -R ug+rw storage bootstrap/cache

php artisan config:clear

step "Running migrations and seeders"
if [ "${FRESH:-0}" = 1 ]; then
  php artisan migrate:fresh --force
else
  php artisan migrate --force
fi
php artisan db:seed --force
php artisan permission:sync-permissions
php artisan permission:sync-default-roles --force

php artisan storage:link 2>/dev/null || true

step "Provisioning tenant '${TENANT_SLUG}' (${TENANT_DOMAIN})"
php scripts/local-tenant.php "$TENANT_SLUG" "$TENANT_DOMAIN" "$TENANT_EMAIL" "$TENANT_PASSWORD"

if [ "${SKIP_NPM:-0}" != 1 ]; then
  step "Installing Node dependencies (Puppeteer)"
  npm install || warn "npm install failed; PDF/screenshot features need Puppeteer. Re-run: npm install"
fi

php artisan optimize:clear >/dev/null

# --- nginx + PHP-FPM ---------------------------------------------------------
step "Configuring PHP-FPM pool (runs as ${DEV_USER})"
FPM_SOCK=/run/php/nexdine-fpm.sock
$SUDO tee "/etc/php/${PHP_VERSION}/fpm/pool.d/nexdine.conf" >/dev/null <<EOF
[nexdine]
user = ${DEV_USER}
group = ${DEV_GROUP}
listen = ${FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
EOF
$SUDO systemctl enable "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true
$SUDO systemctl restart "php${PHP_VERSION}-fpm"

step "Configuring nginx for ${API_DOMAIN}, ${ROOT_DOMAIN} and *.${ROOT_DOMAIN}"
$SUDO tee /etc/nginx/sites-available/nexdine.conf >/dev/null <<'EOF'
server {
    listen __PORT__;
    server_name __ROOT__ __API__ *.__ROOT__;
    root __PROJECT__/public;
    index index.php;
    charset utf-8;
    client_max_body_size 64m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:__SOCK__;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
$SUDO sed -i \
  -e "s|__PORT__|${NGINX_PORT}|" -e "s|__ROOT__|${ROOT_DOMAIN}|g" -e "s|__API__|${API_DOMAIN}|" \
  -e "s|__PROJECT__|${PROJECT_DIR}|" -e "s|__SOCK__|${FPM_SOCK}|" \
  /etc/nginx/sites-available/nexdine.conf
$SUDO ln -sf /etc/nginx/sites-available/nexdine.conf /etc/nginx/sites-enabled/nexdine.conf

# nginx (www-data) serves static files directly, so it needs to traverse the
# path to the project (home dirs are 0750 on Ubuntu) and read public/.
dir=$PROJECT_DIR
while [ "$dir" != / ]; do
  $SUDO setfacl -m u:www-data:x "$dir"
  dir=$(dirname "$dir")
done
$SUDO setfacl -R -m u:www-data:rX public storage/app/public
$SUDO setfacl -R -d -m u:www-data:rX public storage/app/public

if systemctl is-active --quiet apache2 && [ "$NGINX_PORT" = 80 ]; then
  warn "Apache is running on port 80; stopping it so nginx can bind."
  $SUDO systemctl stop apache2
  $SUDO systemctl disable apache2 >/dev/null 2>&1 || true
fi
$SUDO nginx -t
$SUDO systemctl enable nginx >/dev/null 2>&1 || true
$SUDO systemctl restart nginx

step "Adding local domains to /etc/hosts"
for host in "$ROOT_DOMAIN" "$API_DOMAIN" "$TENANT_DOMAIN"; do
  grep -qE "^127\.0\.0\.1\s+.*\b${host//./\\.}\b" /etc/hosts \
    || echo "127.0.0.1 ${host}" | $SUDO tee -a /etc/hosts >/dev/null
done

if ! curl -fsS -o /dev/null "${APP_URL}/"; then
  warn "${APP_URL} did not answer yet. Check /var/log/nginx/error.log and storage/logs/laravel.log"
fi

admin_email=$(php artisan tinker --execute="echo Modules\\User\\Models\\User::where('username','admin')->value('email');" 2>/dev/null | tail -n1 || true)
admin_email=${admin_email:-admin@myteknoland.com}

step "Setup complete"
cat <<EOF

  ┌──────────────────────────── Login details ────────────────────────────┐
    Platform (super admin)
      API URL     ${APP_URL}
      Email       ${admin_email}
      Password    12345678

    Tenant "${TENANT_SLUG}"
      URL         http://${TENANT_DOMAIN}${PORT_SUFFIX}
      Email       ${TENANT_EMAIL}
      Password    ${TENANT_PASSWORD}

    MySQL         127.0.0.1:3306  db=${DB_DATABASE}  user=${DB_USERNAME}  pass=${DB_PASSWORD}
    Redis         127.0.0.1:6379
    Reverb (WS)   ws://127.0.0.1:${REVERB_PORT}
  └────────────────────────────────────────────────────────────────────────┘
  Local credentials only. Change them before using this data anywhere else.

  nginx + PHP-FPM run as system services. Start the queue worker and Reverb
  with:  bash scripts/start-local.sh
EOF

if [ "${NO_START:-0}" != 1 ]; then
  exec bash scripts/start-local.sh
fi
