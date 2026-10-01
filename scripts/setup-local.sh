#!/usr/bin/env bash
#
# One-command local setup for Ubuntu / Debian.
#
#   bash scripts/setup-local.sh
#
# Only Git is needed beforehand. Installs PHP 8.4 (+ extensions), Composer,
# MySQL, Redis and Node.js when missing, configures Reverb, creates .env and the
# database, runs migrations + seeders, prints the login details and starts the
# API, queue worker and Reverb (scripts/start-local.sh).
# Safe to re-run: every step is idempotent.
#
# Overridable environment variables:
#   DB_DATABASE   default: nexdine
#   DB_USERNAME   default: nexdine
#   DB_PASSWORD   default: secret
#   APP_PORT      default: 8000
#   REVERB_PORT   default: 8080
#   SKIP_NPM=1    skip `npm install` (Puppeteer / Chrome download)
#   FRESH=1       drop all tables and re-seed (migrate:fresh)
#   NO_START=1    only set up; do not start the servers at the end
#
set -euo pipefail
cd "$(dirname "$0")/.."

DB_DATABASE=${DB_DATABASE:-nexdine}
DB_USERNAME=${DB_USERNAME:-nexdine}
DB_PASSWORD=${DB_PASSWORD:-secret}
APP_PORT=${APP_PORT:-8000}
REVERB_PORT=${REVERB_PORT:-8080}
PHP_VERSION=8.4

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
has()  { command -v "$1" >/dev/null 2>&1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=''; else SUDO='sudo'; fi
has apt-get || die "This script supports Ubuntu/Debian (apt). Use scripts/setup-local.ps1 on Windows."

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
$SUDO apt-get install -y software-properties-common ca-certificates curl unzip git gnupg lsb-release

if ! apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
  step "Adding ondrej/php PPA for PHP ${PHP_VERSION}"
  $SUDO add-apt-repository -y ppa:ondrej/php
  $SUDO apt-get update -y
fi

step "Installing PHP ${PHP_VERSION} and extensions"
$SUDO apt-get install -y \
  "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" "php${PHP_VERSION}-mysql" \
  "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-redis" \
  "php${PHP_VERSION}-gmp" "php${PHP_VERSION}-opcache"
$SUDO update-alternatives --set php "/usr/bin/php${PHP_VERSION}" 2>/dev/null || true

if ! has composer; then
  step "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  $SUDO php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
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
set_env APP_URL "http://127.0.0.1:${APP_PORT}"
set_env APP_INSTALLED true
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
if ! grep -qE '^QR_SECRET=.+' .env; then
  set_env QR_SECRET "$(php -r 'echo bin2hex(random_bytes(32));')"
fi

step "Installing Composer dependencies"
composer install --no-interaction --prefer-dist

if ! grep -qE '^APP_KEY=.+' .env; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/{cache,sessions,views,testing} storage/logs bootstrap/cache
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

if [ "${SKIP_NPM:-0}" != 1 ]; then
  step "Installing Node dependencies (Puppeteer)"
  npm install || warn "npm install failed; PDF/screenshot features need Puppeteer. Re-run: npm install"
fi

php artisan optimize:clear >/dev/null

admin_email=$(php artisan tinker --execute="echo Modules\\User\\Models\\User::where('username','admin')->value('email');" 2>/dev/null | tail -n1 || true)
admin_email=${admin_email:-admin@myteknoland.com}

step "Setup complete"
cat <<EOF

  ┌──────────────────────────── Login details ────────────────────────────┐
    API URL        http://127.0.0.1:${APP_PORT}
    Admin email    ${admin_email}
    Admin password 12345678        (change it after first login)

    MySQL          127.0.0.1:3306  db=${DB_DATABASE}  user=${DB_USERNAME}  pass=${DB_PASSWORD}
    Redis          127.0.0.1:6379
    Reverb (WS)    ws://127.0.0.1:${REVERB_PORT}
  └────────────────────────────────────────────────────────────────────────┘

  Start everything later with:  bash scripts/start-local.sh
EOF

if [ "${NO_START:-0}" != 1 ]; then
  exec bash scripts/start-local.sh
fi
