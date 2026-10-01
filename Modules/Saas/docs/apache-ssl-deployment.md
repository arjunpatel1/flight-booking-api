# SaaS Apache + SSL Deployment

This runbook deploys one backend and one frontend for many restaurant tenants.

## What The Script Manages

- Apache vhost for the Laravel API domain.
- Apache vhost for the Vue frontend root domain and tenant subdomains.
- SPA fallback to `index.html` for tenant frontend routes.
- Apache modules: `rewrite`, `headers`, `ssl`, `http2`.
- Certbot SSL certificates and HTTP-to-HTTPS redirect.
- Required SaaS `.env` values.

The script is dry-run by default. It only writes Apache files and requests SSL when `--apply` is passed.

## Production DNS

Create DNS `A` records pointing to the server:

```text
api.example.com       -> SERVER_IP
chirag.example.com    -> SERVER_IP
happy.example.com     -> SERVER_IP
pooripool.example.com -> SERVER_IP
example.com           -> SERVER_IP
```

Wildcard DNS can also be used:

```text
*.example.com -> SERVER_IP
example.com   -> SERVER_IP
```

The included script requests normal certificates for the explicit domains. Wildcard SSL requires DNS challenge automation and should be configured with your DNS provider plugin.

## Dry Run

```bash
cd /var/www/restaurant-pos-web/restaurant-pos-api

bash Modules/Saas/deploy/apache-saas-ssl.sh \
  --api-domain api.example.com \
  --root-domain example.com \
  --tenant-domains "chirag.example.com,happy.example.com,pooripool.example.com" \
  --frontend-root /var/www/restaurant-pos-web/dist \
  --api-root /var/www/restaurant-pos-web/restaurant-pos-api/public \
  --email admin@example.com
```

## Apply On Server

```bash
cd /var/www/restaurant-pos-web/restaurant-pos-api

sudo bash Modules/Saas/deploy/apache-saas-ssl.sh \
  --api-domain api.example.com \
  --root-domain example.com \
  --tenant-domains "chirag.example.com,happy.example.com,pooripool.example.com" \
  --frontend-root /var/www/restaurant-pos-web/dist \
  --api-root /var/www/restaurant-pos-web/restaurant-pos-api/public \
  --email admin@example.com \
  --install-packages \
  --apply
```

## SaaS Admin Panel Control

The same process is available from:

```text
Admin → Commercial → SaaS Dashboard → Apache + SSL Automation
```

Preview mode is always safe and does not change Apache or SSL.

Apply mode is disabled unless these values are explicitly configured:

```env
SAAS_SERVER_AUTOMATION_ENABLED=true
SAAS_SERVER_AUTOMATION_ALLOW_APPLY=true
SAAS_FRONTEND_ROOT=/var/www/restaurant-pos-web/dist
SAAS_API_ROOT=/var/www/restaurant-pos-web/restaurant-pos-api/public
```

If PHP-FPM runs as `www-data`, it cannot edit Apache or request certificates by default.
To allow the SaaS Admin button to apply changes, configure a narrow sudoers rule for only this script:

```text
www-data ALL=(root) NOPASSWD: /var/www/restaurant-pos-web/restaurant-pos-api/Modules/Saas/deploy/apache-saas-ssl.sh
```

Then enable:

```env
SAAS_SERVER_AUTOMATION_USE_SUDO=true
```

Do not grant broad sudo access to PHP. Only allow this one reviewed script.

## Laravel Environment

Set these values in `restaurant-pos-api/.env`:

```env
ENABLE_ROUTE_DOMAIN=true
PUBLIC_DOMAIN=example.com
API_DOMAIN=api.example.com
SAAS_ROOT_DOMAIN=example.com
SAAS_CENTRAL_DOMAINS=localhost,127.0.0.1,api.example.com,example.com
SESSION_DOMAIN=.example.com
SANCTUM_STATEFUL_DOMAINS=example.com,chirag.example.com,happy.example.com,pooripool.example.com,api.example.com
```

## Vue Environment

Set this in `.env.production` before building:

```env
VITE_API_URL=https://api.example.com/api
```

Then build:

```bash
npm install
npm run build
```

## Laravel Cache Refresh

```bash
cd /var/www/restaurant-pos-web/restaurant-pos-api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Local Development With `.test`

For local `.test` domains, use `/etc/hosts`:

```text
127.0.0.1 api.nexdine.test
127.0.0.1 nexdine.test
127.0.0.1 chirag.nexdine.test
127.0.0.1 happy.nexdine.test
127.0.0.1 pooripool.nexdine.test
```

Local SSL for `.test` domains should use `mkcert` or an internal development CA. Public Let’s Encrypt certificates are not issued for `.test`.

## Verification

```bash
apache2ctl configtest
curl -I https://api.example.com/api/v1/app/settings
curl -I https://chirag.example.com
curl -I https://happy.example.com
curl -I https://pooripool.example.com
sudo certbot certificates
```

## Rollback

The script backs up an existing site file before overwriting:

```bash
sudo ls /etc/apache2/sites-available/nexdine-saas.conf.bak.*
sudo cp /etc/apache2/sites-available/nexdine-saas.conf.bak.YYYYMMDDHHMMSS /etc/apache2/sites-available/nexdine-saas.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```
