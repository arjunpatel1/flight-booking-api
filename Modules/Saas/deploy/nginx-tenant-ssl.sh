#!/usr/bin/env bash
set -euo pipefail

umask 027
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'
unset BASH_ENV ENV CDPATH GLOBIGNORE

readonly SECURITY_CONFIG='/etc/nexdine/tenant-ssl.conf'
readonly NGINX_CONF_DIR='/etc/nginx/conf.d'
readonly LETSENCRYPT_LIVE='/etc/letsencrypt/live'
readonly LOCK_FILE='/run/lock/nexdine-tenant-ssl.lock'

TENANT_DOMAIN=''
FRONTEND_ROOT=''
CERT_EMAIL=''
DRY_RUN=1

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tenant-domain) [[ $# -ge 2 ]] || fail 'Missing tenant domain.'; TENANT_DOMAIN="$2"; shift 2 ;;
    --frontend-root) [[ $# -ge 2 ]] || fail 'Missing frontend root.'; FRONTEND_ROOT="$2"; shift 2 ;;
    --email) [[ $# -ge 2 ]] || fail 'Missing certificate email.'; CERT_EMAIL="$2"; shift 2 ;;
    --apply) DRY_RUN=0; shift ;;
    -h|--help)
      echo "Usage: $0 --tenant-domain restaurant.example.com --frontend-root /var/www/app/dist --email ops@example.com [--apply]"
      exit 0
      ;;
    *) fail "Unknown option: $1" ;;
  esac
done

[[ -n "$TENANT_DOMAIN" && -n "$FRONTEND_ROOT" && -n "$CERT_EMAIL" ]] || \
  fail 'Tenant domain, frontend root, and certificate email are required.'

TENANT_DOMAIN="${TENANT_DOMAIN,,}"
[[ ${#TENANT_DOMAIN} -le 253 ]] || fail 'Tenant domain is too long.'
[[ "$TENANT_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] || \
  fail 'Invalid tenant domain.'
[[ "$CERT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || fail 'Invalid certificate email.'

ALLOWED_DOMAIN_SUFFIX=''
ALLOWED_FRONTEND_ROOT=''
ALLOWED_CERT_EMAIL=''
EXPECTED_SERVER_IP=''

if [[ "$DRY_RUN" -eq 0 ]]; then
  [[ "$EUID" -eq 0 ]] || fail 'Apply mode requires root through the scoped sudo rule.'
  [[ -f "$SECURITY_CONFIG" && ! -L "$SECURITY_CONFIG" ]] || fail "Missing secure configuration: $SECURITY_CONFIG"
  config_owner="$(stat -c '%U:%G' "$SECURITY_CONFIG")"
  config_mode="$(stat -c '%a' "$SECURITY_CONFIG")"
  [[ "$config_owner" == 'root:root' ]] || fail 'Security configuration must be owned by root:root.'
  (( (8#$config_mode & 8#022) == 0 )) || fail 'Security configuration must not be group/world writable.'

  # This file is root-controlled and must contain only simple KEY=value lines.
  while IFS='=' read -r key value; do
    [[ -z "$key" || "$key" == \#* ]] && continue
    case "$key" in
      TENANT_DOMAIN_SUFFIX) ALLOWED_DOMAIN_SUFFIX="$value" ;;
      TENANT_FRONTEND_ROOT) ALLOWED_FRONTEND_ROOT="$value" ;;
      TENANT_SSL_EMAIL) ALLOWED_CERT_EMAIL="$value" ;;
      TENANT_SERVER_IP) EXPECTED_SERVER_IP="$value" ;;
      *) fail "Unsupported key in $SECURITY_CONFIG: $key" ;;
    esac
  done < "$SECURITY_CONFIG"

  [[ "$ALLOWED_DOMAIN_SUFFIX" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail 'Invalid configured tenant suffix.'
  [[ "$TENANT_DOMAIN" == *."$ALLOWED_DOMAIN_SUFFIX" ]] || fail 'Domain is outside the approved tenant suffix.'
  [[ -n "$ALLOWED_FRONTEND_ROOT" && -n "$ALLOWED_CERT_EMAIL" && -n "$EXPECTED_SERVER_IP" ]] || \
    fail 'Security configuration is incomplete.'

  requested_root="$(realpath -e -- "$FRONTEND_ROOT")" || fail 'Frontend root does not exist.'
  approved_root="$(realpath -e -- "$ALLOWED_FRONTEND_ROOT")" || fail 'Approved frontend root does not exist.'
  [[ "$requested_root" == "$approved_root" ]] || fail 'Frontend root is not approved.'
  [[ "$CERT_EMAIL" == "$ALLOWED_CERT_EMAIL" ]] || fail 'Certificate email is not approved.'
  # Keep nginx pointed at the configured path, which may be the stable
  # `current` symlink used by atomic releases. Writing `realpath` here pins a
  # tenant vhost to one release; pruning it then produces a raw nginx 404.
  [[ "$ALLOWED_FRONTEND_ROOT" == /* && "$ALLOWED_FRONTEND_ROOT" != *$'\n'* && "$ALLOWED_FRONTEND_ROOT" != *$'\r'* ]] || \
    fail 'Approved frontend root must be a safe absolute path.'
  FRONTEND_ROOT="${ALLOWED_FRONTEND_ROOT%/}"

  [[ -f "$0" && ! -L "$0" ]] || fail 'Automation script must be a regular file, not a symlink.'
  [[ "$(stat -c '%U:%G' "$0")" == 'root:root' ]] || fail 'Automation script must be owned by root:root.'
  script_mode="$(stat -c '%a' "$0")"
  (( (8#$script_mode & 8#022) == 0 )) || fail 'Automation script must not be group/world writable.'
fi

readonly SITE="${NGINX_CONF_DIR}/${TENANT_DOMAIN}.conf"
readonly CERT_DIR="${LETSENCRYPT_LIVE}/${TENANT_DOMAIN}"
readonly ACME_ROOT="${FRONTEND_ROOT%/}"
challenge_site=''
temporary_site=''
site_backup=''
site_existed=0

cleanup() {
  [[ -n "$challenge_site" ]] && rm -f -- "$challenge_site"
  [[ -n "$temporary_site" ]] && rm -f -- "$temporary_site"
  [[ -n "$site_backup" ]] && rm -f -- "$site_backup"
  return 0
}
trap cleanup EXIT

rollback_site() {
  if [[ -n "$site_backup" && -f "$site_backup" ]]; then
    install -o root -g root -m 0644 -- "$site_backup" "$SITE"
  elif [[ "$site_existed" -eq 0 ]]; then
    rm -f -- "$SITE"
  fi
}

render_site() {
  cat <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${TENANT_DOMAIN};
    server_tokens off;
    return 301 https://${TENANT_DOMAIN}\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${TENANT_DOMAIN};
    server_tokens off;
    root ${FRONTEND_ROOT};
    index index.html;
    client_max_body_size 50M;

    ssl_certificate ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https: wss:; worker-src 'self' blob:; media-src 'self' data: blob:; frame-src 'self' https://uen.io https://*.uen.io https://maps.google.com https://www.google.com https://checkout.razorpay.com https://api.razorpay.com" always;

    location = /index.html {
        add_header Cache-Control "no-store";
        add_header Strict-Transport-Security "max-age=31536000" always;
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
        add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https: wss:; worker-src 'self' blob:; media-src 'self' data: blob:; frame-src 'self' https://uen.io https://*.uen.io https://maps.google.com https://www.google.com https://checkout.razorpay.com https://api.razorpay.com" always;
    }

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(?:js|css|png|jpg|jpeg|gif|ico|svg|webp|woff2?)\$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        add_header Strict-Transport-Security "max-age=31536000" always;
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
        add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https: wss:; worker-src 'self' blob:; media-src 'self' data: blob:; frame-src 'self' https://uen.io https://*.uen.io https://maps.google.com https://www.google.com https://checkout.razorpay.com https://api.razorpay.com" always;
        try_files \$uri =404;
    }
}
NGINX
}

render_http_challenge_site() {
  cat <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${TENANT_DOMAIN};
    server_tokens off;
    root ${FRONTEND_ROOT};

    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_ROOT};
        default_type "text/plain";
        try_files \$uri =404;
    }

    location / {
        return 503;
    }
}
NGINX
}

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[preflight] tenant=${TENANT_DOMAIN}"
  echo "[preflight] frontend=${FRONTEND_ROOT}"
  echo "[dry-run] validate root-owned allowlist ${SECURITY_CONFIG}"
  echo "[dry-run] issue/renew certificate and atomically install ${SITE}"
  render_site
  exit 0
fi

[[ -f "${FRONTEND_ROOT%/}/index.html" ]] || fail 'Frontend index.html was not found. Deploy the portal first.'
[[ -d "$NGINX_CONF_DIR" && ! -L "$NGINX_CONF_DIR" ]] || fail 'Nginx configuration directory is unsafe.'
[[ "$(stat -c '%U:%G' "$NGINX_CONF_DIR")" == 'root:root' ]] || fail 'Nginx configuration directory must be root-owned.'
[[ ! -e "$SITE" || ( -f "$SITE" && ! -L "$SITE" ) ]] || fail 'Tenant site path is not a safe regular file.'

for binary in /usr/sbin/nginx /usr/bin/systemctl /usr/bin/certbot /usr/bin/getent /usr/bin/openssl /usr/bin/flock; do
  [[ -x "$binary" ]] || fail "Required executable is missing: $binary"
done

exec 9>"$LOCK_FILE"
/usr/bin/flock -n 9 || fail 'Another tenant SSL operation is already running.'

mapfile -t resolved_ips < <(/usr/bin/getent ahostsv4 "$TENANT_DOMAIN" | awk '{print $1}' | sort -u)
[[ ${#resolved_ips[@]} -gt 0 ]] || fail 'Tenant DNS does not resolve.'
printf '%s\n' "${resolved_ips[@]}" | grep -Fxq -- "$EXPECTED_SERVER_IP" || \
  fail 'Tenant DNS does not point to the approved NexDine server.'

if [[ -f "$SITE" ]]; then
  site_existed=1
  site_backup="$(mktemp --tmpdir=/run nexdine-nginx-backup.XXXXXX)"
  cp -p -- "$SITE" "$site_backup"
fi

if [[ ! -d "$CERT_DIR" ]]; then
  challenge_site="$(mktemp --tmpdir=/run nexdine-nginx-challenge.XXXXXX)"
  render_http_challenge_site > "$challenge_site"
  install -o root -g root -m 0644 -- "$challenge_site" "$SITE"
  /usr/sbin/nginx -t || { rollback_site; exit 1; }
  /usr/bin/systemctl reload nginx

  install -d -o root -g root -m 0755 -- "${ACME_ROOT}/.well-known" "${ACME_ROOT}/.well-known/acme-challenge"
  /usr/bin/certbot certonly --webroot -w "$ACME_ROOT" --non-interactive --agree-tos \
    --email "$CERT_EMAIL" --cert-name "$TENANT_DOMAIN" -d "$TENANT_DOMAIN" || {
      rollback_site
      /usr/sbin/nginx -t && /usr/bin/systemctl reload nginx
      exit 1
    }
fi

[[ -r "${CERT_DIR}/fullchain.pem" && -r "${CERT_DIR}/privkey.pem" ]] || fail 'Certificate files are unavailable.'
/usr/bin/openssl x509 -in "${CERT_DIR}/fullchain.pem" -noout -checkhost "$TENANT_DOMAIN" >/dev/null || \
  fail 'Certificate hostname verification failed.'

temporary_site="$(mktemp --tmpdir=/run nexdine-nginx-site.XXXXXX)"
render_site > "$temporary_site"
install -o root -g root -m 0644 -- "$temporary_site" "$SITE"
/usr/sbin/nginx -t || { rollback_site; exit 1; }
/usr/bin/systemctl reload nginx

echo "Tenant SSL ready: https://${TENANT_DOMAIN}"
