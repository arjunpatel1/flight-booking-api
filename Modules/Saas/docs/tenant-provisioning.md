# NexDine SaaS Tenant Provisioning

This layer uses the existing shared-database SaaS architecture. It does not replace tenant resolution, authentication, authorization, or tenant isolation.

## Create Tenant

```bash
php artisan saas:create-tenant \
  --name="Happy Kitchen" \
  --slug=happy \
  --email=admin@happy.com \
  --phone=9876543210
```

Optional:

```bash
--domain=happy.nexdine.com
--password="StrongPassword"
--plan=starter
--primary-color="#ff6b00"
--secondary-color="#0f172a"
```

The command is idempotent by slug. It creates or updates:

- tenant
- main branch
- branch admin user
- default roles and permissions
- default subscription plan and active subscription
- scoped restaurant/POS/payment/printer/invoice/theme settings
- main menu and categories
- floor, zones, and starter tables
- basic GST tax records
- tenant storage tree under the Laravel `local` disk, normally `storage/app/private/tenants/{tenant_id}`
- tenant health metadata

## Lifecycle Commands

```bash
php artisan saas:suspend-tenant happy --reason="billing hold"
php artisan saas:activate-tenant happy
php artisan saas:delete-tenant happy --reason="customer cancelled"
```

Delete is soft-delete first and writes a backup before deactivation. Add `--delete-storage` only when storage must be removed immediately.

## Backup And Restore

```bash
php artisan saas:backup --tenant=happy
php artisan saas:backup
php artisan saas:restore storage/app/private/tenants/2/backups/tenant-2-20260717-120000.json
```

Restore currently validates backup metadata and intentionally stops before automatic database mutation. This keeps production recovery reviewable and rollback-safe.

## Health And Inventory

```bash
php artisan saas:health
php artisan saas:health --tenant=happy
php artisan saas:list
```

Health reports database, Redis availability, queue driver, scheduler configuration, Reverb config, storage readiness, failed jobs, and tenant disk usage.

## Deployment

No manual post-deploy wiring is required after the code is deployed:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm install
npm run build
php artisan optimize
```

## Rollback Strategy

- Tenant creation is idempotent by slug.
- Suspension only sets `is_active=false` and lifecycle metadata.
- Activation restores `is_active=true`.
- Delete is soft-delete unless `--delete-storage` is explicitly used.
- Backup metadata is written before delete.
