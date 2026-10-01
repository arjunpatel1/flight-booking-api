# Enterprise Observability Rollout

This POS API is production-facing. Observability must be enabled in small,
measured steps so restaurant order, billing, printer, and waiter flows remain
stable.

## Current State

| Tool | Package | Runtime State | Safety Notes |
| --- | --- | --- | --- |
| Pulse | `laravel/pulse` | Disabled by default | Dashboard access is gated by `viewPulse`; only super admins are allowed. |
| Debugbar | `barryvdh/laravel-debugbar` | Disabled by default | Installed as `require-dev`; API paths are excluded. |
| ResponseCache | `spatie/laravel-responsecache` | Disabled by default | Uses `PosResponseCacheProfile`; no paths are cacheable until explicitly configured. |
| DTO serialization | Internal support contract | Available for new payloads | No existing endpoint contract has been changed. |

## Production Guardrails

Do not enable these tools globally.

- Keep `APP_DEBUG=false` in production.
- Keep `DEBUGBAR_ENABLED=false` outside local development.
- Keep `PULSE_ENABLED=false` until Pulse migrations are reviewed and deployed.
- Keep `RESPONSE_CACHE_ENABLED=false` until one read-only route has been tested.
- Never cache authenticated POS, order, payment, printer, cart, or waiter live-state endpoints.

## Pulse Rollout

Pulse is useful for slow requests, slow queries, queues, jobs, exceptions, and
server health. It writes to:

- `pulse_values`
- `pulse_entries`
- `pulse_aggregates`

Recommended rollout:

1. Review migration SQL in a staging database.
2. Publish Pulse migrations:

   ```bash
   php artisan vendor:publish --provider="Laravel\\Pulse\\PulseServiceProvider" --tag=pulse-migrations
   ```

3. Run migrations in staging.
4. Enable with low retention:

   ```env
   PULSE_ENABLED=true
   PULSE_STORAGE_KEEP="24 hours"
   PULSE_SLOW_REQUESTS_THRESHOLD=500
   PULSE_SLOW_QUERIES_THRESHOLD=100
   PULSE_SLOW_JOBS_THRESHOLD=1000
   ```

5. Verify:

   ```bash
   php artisan config:show pulse
   php artisan pulse:check
   php artisan route:list --path=pulse
   ```

6. Confirm only super admins can access `/pulse`.

For 24/7 production, run Pulse processing under Supervisor:

```bash
php artisan pulse:work
```

## Debugbar Rollout

Debugbar is for local development only.

Use:

```env
APP_ENV=local
APP_DEBUG=true
DEBUGBAR_ENABLED=true
```

Never enable Debugbar on the production API. It can expose query details,
headers, routes, exceptions, and request context.

Verify disabled state:

```bash
php artisan config:show debugbar
php artisan route:list --path=_debugbar
```

Expected production result:

- `enabled=false`
- no `_debugbar` routes

## ResponseCache Rollout

Response caching is intentionally fail-closed.

Current profile:

- only GET/HEAD
- JSON responses only
- successful responses only
- rejects bearer-authenticated requests
- only configured path patterns
- varies cache suffix by tenant and branch headers/query

Start with a public, read-only, non-live endpoint only.

Example staging-only configuration:

```env
RESPONSE_CACHE_ENABLED=true
POS_RESPONSE_CACHE_LIFETIME=60
POS_RESPONSE_CACHE_PATHS="api/v1/online-menus/*/menu"
```

Do not cache:

- POS viewer menu endpoints used by authenticated waiters until live availability rules are measured.
- active orders
- waiter dashboard
- cart
- order creation/update/payment
- billing
- printer or agent endpoints

Verify:

```bash
php artisan config:show responsecache
php artisan responsecache:clear
```

## DTO Serialization Rollout

Use `Modules\Support\Contracts\DataTransferObject` and
`Modules\Support\Traits\SerializesDataTransferObject` for new measured payloads
where array generation is duplicated or expensive.

Rules:

- Do not replace Laravel API Resources globally.
- Do not change response keys.
- Add DTOs only for measured hot payloads.
- Snapshot-test payload arrays before using DTOs in endpoints.

Good candidates:

- POS menu payload slices
- print dispatch payloads
- Reverb event payloads
- billing summary payloads

## Verification Checklist

Run after every observability change:

```bash
php -l app/Providers/AppServiceProvider.php
php artisan config:show pulse
php artisan config:show debugbar
php artisan config:show responsecache
php artisan route:list --path=pulse
php artisan route:list --path=_debugbar
php artisan test tests/Unit/Core/ObservabilityAccessTest.php tests/Unit/Core/PosResponseCacheProfileTest.php tests/Unit/Support/DataTransferObjectSerializationTest.php
```

Known environment issue:

- Full `composer test` currently needs the missing `pdo_sqlite` PHP extension to run SQLite-backed tests.
