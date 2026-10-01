#!/usr/bin/env bash
set -euo pipefail

APP_NAME="nexdine-saas"
API_DOMAIN=""
ROOT_DOMAIN=""
TENANT_DOMAINS=""
FRONTEND_ROOT=""
API_ROOT=""
CERT_EMAIL=""
DRY_RUN=1
INSTALL_PACKAGES=0

usage() {
  cat <<'USAGE'
Usage:
  nginx-saas-ssl.sh --api-domain api.nexdine.com --root-domain nexdine.com \
    --tenant-domains "chirag.nexdine.com,happy.nexdine.com,pooripool.nexdine.com" \
    --frontend-root /var/www/restaurant-pos-web/dist \
    --api-root /var/www/restaurant-pos-web/restaurant-pos-api/public \
    --email admin@nexdine.com [--apply] [--install-packages]

What it does:
  - Creates Nginx server blocks for one Laravel API domain and many Vue tenant domains.
  - Runs nginx config validation.
  - Runs certbot for every configured domain when --apply is used.
  - Prints required Laravel/Vite SaaS env values.

Safety:
  - Dry-run is default. Add --apply to write Nginx config and request SSL.
  - Existing Nginx site file is backed up before overwrite.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --api-domain) API_DOMAIN="${2:-}"; shift 2 ;;
    --root-domain) ROOT_DOMAIN="${2:-}"; shift 2 ;;
    --tenant-domains) TENANT_DOMAINS="${2:-}"; shift 2 ;;
    --frontend-root) FRONTEND_ROOT="${2:-}"; shift 2 ;;
    --api-root) API_ROOT="${2:-}"; shift 2 ;;
    --email) CERT_EMAIL="${2:-}"; shift 2 ;;
    --site-name) APP_NAME="${2:-}"; shift 2 ;;
    --install-packages) INSTALL_PACKAGES=1; shift ;;
    --apply) DRY_RUN=0; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

require_value() {
  local name="$1"
  local value="$2"
  if [[ -z "$value" ]]; then
    echo "Missing required option: $name" >&2
    usage
    exit 1
  fi
}

require_value "--api-domain" "$API_DOMAIN"
require_value "--root-domain" "$ROOT_DOMAIN"
require_value "--tenant-domains" "$TENANT_DOMAINS"
require_value "--frontend-root" "$FRONTEND_ROOT"
require_value "--api-root" "$API_ROOT"
require_value "--email" "$CERT_EMAIL"

NGINX_SITE="/etc/nginx/sites-available/${APP_NAME}.conf"
NGINX_LINK="/etc/nginx/sites-enabled/${APP_NAME}.conf"
IFS=',' read -r -a TENANTS <<< "$TENANT_DOMAINS"
DOMAIN_ARGS=(-d "$API_DOMAIN" -d "$ROOT_DOMAIN")
TENANT_SERVER_NAMES=""
CENTRAL_DOMAINS="localhost,127.0.0.1,${API_DOMAIN},${ROOT_DOMAIN}"

for domain in "${TENANTS[@]}"; do
  clean_domain="$(echo "$domain" | xargs)"
  [[ -z "$clean_domain" ]] && continue
  DOMAIN_ARGS+=(-d "$clean_domain")
  TENANT_SERVER_NAMES+=" ${clean_domain}"
done

run() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] $*"
  else
    "$@"
  fi
}

write_file() {
  local path="$1"
  local content="$2"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] write ${path}"
    echo "----- ${path} -----"
    echo "$content"
    echo "----- end ${path} -----"
    return
  fi

  if [[ -f "$path" ]]; then
    cp "$path" "${path}.bak.$(date +%Y%m%d%H%M%S)"
  fi
  printf '%s\n' "$content" > "$path"
}

if [[ "$DRY_RUN" -eq 0 && "$EUID" -ne 0 ]]; then
  echo "Run with sudo when using --apply." >&2
  exit 1
fi

if [[ "$INSTALL_PACKAGES" -eq 1 ]]; then
  run apt-get update
  run apt-get install -y nginx certbot python3-certbot-nginx
fi

SITE="$(cat <<NGINX
server {
    listen 80;
    server_name ${API_DOMAIN};
    server_tokens off;
    root ${API_ROOT};
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

server {
    listen 80;
    server_name ${ROOT_DOMAIN}${TENANT_SERVER_NAMES};
    server_tokens off;
    root ${FRONTEND_ROOT};
    index index.html;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https: wss:; worker-src 'self' blob:; media-src 'self' data: blob:; frame-src 'self' https://uen.io https://*.uen.io https://maps.google.com https://www.google.com https://checkout.razorpay.com https://api.razorpay.com" always;

    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
NGINX
)"

write_file "$NGINX_SITE" "$SITE"
run ln -sfn "$NGINX_SITE" "$NGINX_LINK"
run nginx -t
run systemctl reload nginx

if [[ "$DRY_RUN" -eq 0 ]]; then
  certbot --nginx --non-interactive --agree-tos --redirect --email "$CERT_EMAIL" "${DOMAIN_ARGS[@]}"
  systemctl reload nginx
else
  echo "[dry-run] certbot --nginx --non-interactive --agree-tos --redirect --email ${CERT_EMAIL} ${DOMAIN_ARGS[*]}"
fi

cat <<ENVINFO

Laravel .env values:
ENABLE_ROUTE_DOMAIN=true
PUBLIC_DOMAIN=${ROOT_DOMAIN}
API_DOMAIN=${API_DOMAIN}
SAAS_ROOT_DOMAIN=${ROOT_DOMAIN}
SAAS_CENTRAL_DOMAINS=${CENTRAL_DOMAINS}
SESSION_DOMAIN=.${ROOT_DOMAIN}
SANCTUM_STATEFUL_DOMAINS=${ROOT_DOMAIN},${TENANT_DOMAINS},${API_DOMAIN}

Vue .env.production values:
VITE_API_URL=https://${API_DOMAIN}/api

After DNS points to this server:
  sudo -u www-data php artisan optimize:clear
  sudo -u www-data php artisan route:clear
  sudo -u www-data php artisan config:cache

Deploy the locally or CI-built web dist artifact. Do not install Node packages
or run npm/yarn builds on the production server.
ENVINFO
