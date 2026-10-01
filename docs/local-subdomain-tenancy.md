# Local Subdomain Tenancy

NexDine uses the existing `Modules/Saas` implementation. Tenants are stored in the shared database in the `tenants` table and linked to `users.tenant_id` and `branches.tenant_id`.

## Required Hosts Entries

Add these entries to `/etc/hosts`:

```text
127.0.0.1 chirag.nexdine.test
127.0.0.1 happy.nexdine.test
127.0.0.1 pooripool.nexdine.test
127.0.0.1 api.nexdine.test
```

## Backend `.env`

Use one API host and one root SaaS domain:

```env
APP_URL=http://api.nexdine.test
ENABLE_ROUTE_DOMAIN=true
PUBLIC_DOMAIN=nexdine.test
API_DOMAIN=api.nexdine.test
SAAS_ROOT_DOMAIN=nexdine.test
SAAS_CENTRAL_DOMAINS=localhost,127.0.0.1,nexdine.test,api.nexdine.test
SESSION_DOMAIN=.nexdine.test
SANCTUM_STATEFUL_DOMAINS=chirag.nexdine.test,happy.nexdine.test,pooripool.nexdine.test,api.nexdine.test,localhost,127.0.0.1
CORS_ALLOWED_ORIGINS=http://chirag.nexdine.test,http://happy.nexdine.test,http://pooripool.nexdine.test,http://api.nexdine.test,http://localhost:3101,http://127.0.0.1:3101
REVERB_HOST=api.nexdine.test
REVERB_ALLOWED_ORIGINS=chirag.nexdine.test,happy.nexdine.test,pooripool.nexdine.test,api.nexdine.test,localhost,127.0.0.1
```

For production, use the same settings with `.com` hosts:

```env
APP_URL=https://api.nexdine.com
ENABLE_ROUTE_DOMAIN=true
PUBLIC_DOMAIN=nexdine.com
API_DOMAIN=api.nexdine.com
SAAS_ROOT_DOMAIN=nexdine.com
SAAS_CENTRAL_DOMAINS=nexdine.com,api.nexdine.com
SESSION_DOMAIN=.nexdine.com
SANCTUM_STATEFUL_DOMAINS=chirag.nexdine.com,happy.nexdine.com,pooripool.nexdine.com,api.nexdine.com
CORS_ALLOWED_ORIGINS=https://chirag.nexdine.com,https://happy.nexdine.com,https://pooripool.nexdine.com,https://api.nexdine.com
REVERB_HOST=api.nexdine.com
REVERB_ALLOWED_ORIGINS=chirag.nexdine.com,happy.nexdine.com,pooripool.nexdine.com,api.nexdine.com
```

## Frontend `.env`

Use the local frontend env file:

```bash
npm run dev -- --host 0.0.0.0 --mode nexdine-local
```

The Vue API client sends `X-NexDine-Tenant-Domain` from the browser host so the shared API can resolve the tenant while still running on `api.nexdine.test`.

## Apache Example

```apache
<VirtualHost *:80>
    ServerName api.nexdine.test
    DocumentRoot /home/arjun/Documents/restaurant-pos-web/restaurant-pos-api/public

    <Directory /home/arjun/Documents/restaurant-pos-web/restaurant-pos-api/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:80>
    ServerName chirag.nexdine.test
    ServerAlias happy.nexdine.test pooripool.nexdine.test
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:3101/
    ProxyPassReverse / http://127.0.0.1:3101/
</VirtualHost>
```

## Nginx Example

```nginx
server {
    listen 80;
    server_name api.nexdine.test;
    root /home/arjun/Documents/restaurant-pos-web/restaurant-pos-api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}

server {
    listen 80;
    server_name chirag.nexdine.test happy.nexdine.test pooripool.nexdine.test;

    location / {
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:3101;
    }
}
```

## Tenant Rows

Create or update tenants with these values:

| Restaurant | slug | domain |
| --- | --- | --- |
| Chirag Arabian Mandi | `chirag` | `chirag.nexdine.test` |
| Happy Kitchen | `happy` | `happy.nexdine.test` |
| Poori Pool | `pooripool` | `pooripool.nexdine.test` |

Assign every branch and non-super-admin user for each restaurant to the same `tenant_id`.

## Verification Checklist

- `chirag.nexdine.test` sends `X-NexDine-Tenant-Domain: chirag.nexdine.test`.
- `happy.nexdine.test` sends `X-NexDine-Tenant-Domain: happy.nexdine.test`.
- `pooripool.nexdine.test` sends `X-NexDine-Tenant-Domain: pooripool.nexdine.test`.
- Login accepts only users belonging to the resolved tenant.
- Bearer tokens from one tenant return `403` if used against another tenant host.
- Branches, tables, menus, orders, kitchen, billing and settings are scoped by the authenticated user's tenant/branch permissions.
