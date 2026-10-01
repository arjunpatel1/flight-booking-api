# NexDine SaaS Production Certification

## Verified Gates

- Backend tests: `php artisan test` passed with 255 tests and 1298 assertions.
- Backend skipped tests: 66 skipped because this local environment lacks `pdo_sqlite` or `REAL_DB_SMOKE=1`.
- Laravel cache/optimize: `php artisan optimize` passed.
- Vue type-check: `npm run type-check` passed.
- Vue production build: `npm run build` passed.
- Flutter analyze: `flutter analyze` passed with no issues.
- Flutter tests: `flutter test` passed with 371 tests.

## Infrastructure Control Plane

SaaS admin now exposes one web-server automation path for Apache and Nginx:

- Preview: `POST /api/v1/saas/server/webserver-ssl`
- Health: `GET /api/v1/saas/server/health`
- Legacy compatibility: `POST /api/v1/saas/server/apache-ssl`

Required server-side scripts:

- Apache: `Modules/Saas/deploy/apache-saas-ssl.sh`
- Nginx: `Modules/Saas/deploy/nginx-saas-ssl.sh`

Both scripts default to dry-run. Production execution requires `SAAS_SERVER_AUTOMATION_APPLY=true` and an explicit apply request from the SaaS admin.

## Queue Strategy

Use shared workers, not tenant-specific workers.

Recommended queues:

```text
provisioning,delivery,assets,notifications,emails,printing,monitoring,backups,exports,imports,analytics,webhooks,default
```

Recommended worker controls:

- `--tries=3`
- `--timeout=120`
- `--memory=256`
- process count based on server size and queue pressure
- supervisor restart on failure

## Restore Safety

Restore execution is intentionally disabled by default.

Production-safe flags:

```env
SAAS_RESTORE_EXECUTION_ENABLED=false
SAAS_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
```

Only staging copies should enable execution:

```env
SAAS_RESTORE_EXECUTION_ENABLED=true
APP_ENV=staging
```

Restore requires a `RESTORE` confirmation string and logs every step.

## Billing Readiness

The health API reports Razorpay and Stripe readiness from configured keys.

Required production env:

```env
SAAS_BILLING_RAZORPAY_KEY=
SAAS_BILLING_RAZORPAY_SECRET=
SAAS_BILLING_STRIPE_KEY=
SAAS_BILLING_STRIPE_SECRET=
```

Webhook routes must remain behind signature validation before public launch.

## Delivery Readiness

Waiter app delivery jobs support webhook artifact capture from these payload keys:

- `artifact_url`
- `download_url`
- `artifacts.waiter_app`
- `data.artifact_url`
- `data.download_url`

## Remaining Environment Checks

These checks require a real staging/production host:

- DNS and wildcard SSL issuance.
- Apache/Nginx site enablement.
- Supervisor process status and restart.
- Redis queue pressure and failed jobs.
- Reverb multi-device realtime latency.
- 1000 print job stress test with physical printers.
- Composer audit with outbound Packagist access.
- Real Razorpay/Stripe webhook round trip.

## Deployment Checklist

```bash
git pull
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart <app-workers>:*
sudo supervisorctl restart <app-reverb>
```

## Rollback Checklist

```bash
git checkout <previous-release>
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate:rollback --force
php artisan optimize
php artisan queue:restart
sudo supervisorctl restart <app-workers>:*
sudo supervisorctl restart <app-reverb>
```

Run rollback only after confirming migration compatibility and backup availability.

