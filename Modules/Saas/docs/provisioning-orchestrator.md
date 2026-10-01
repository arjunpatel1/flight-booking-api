# SaaS Provisioning Orchestrator

The enterprise provisioning orchestrator coordinates tenant onboarding without replacing the existing SaaS tenant architecture.

## What Runs Synchronously

- Tenant record
- Main branch
- Admin user
- Subscription
- Essential settings
- Provisioning run record

The HTTP response should return immediately after those records are committed.

## What Runs In Queues

| Step | Queue | Purpose |
| --- | --- | --- |
| storage_ready | assets | Tenant storage folders |
| demo_data_ready | provisioning | Starter menu, floor, zones, tables, taxes |
| qr_ready | assets | QR placeholders/assets |
| theme_ready | assets | Theme JSON |
| client_config_ready | assets | Waiter runtime config and activation JSON |
| cache_warmed | monitoring | Cache warmup/readiness probes |
| health_verified | monitoring | Tenant health record |
| completed | provisioning | Final completion state |

Delivery jobs use the `delivery` queue:

- server automation
- optional white-label waiter app build webhook

## Runtime Waiter App Branding

The generic Waiter APK reads tenant runtime config from:

`GET /api/v1/saas/client-config/{slug}`

Activation QR can contain either:

- the JSON returned by `/api/v1/saas/client-config/{slug}/activation`
- or a URL to that activation endpoint

The app refreshes saved runtime JSON automatically on startup.

Runtime configurable:

- API base URL
- Reverb host/key/scheme/port/origin
- app display name
- restaurant/company name
- logo URLs
- splash logo URL
- app icon URL metadata
- theme colors

Native launcher icons cannot be changed after an APK is installed. Use the optional white-label build delivery job for real launcher icon changes.

## Recovery Rules

- Completed steps are never executed again.
- Failed steps can be retried individually or as a group.
- Resume only dispatches unfinished steps.
- Cancel marks the run as cancelled; queued jobs skip cancelled runs.
- Completion refuses to mark failed/cancelled workflows as completed.

## Admin Controls

SaaS Command Center exposes:

- progress
- ETA
- failed jobs
- retry
- resume
- cancel
- per-step logs
- server automation delivery status
- white-label build delivery status

## Required Workers

Production should run these queues:

```bash
php artisan queue:work --queue=provisioning,assets,delivery,monitoring,notifications,default
```

Use Supervisor/systemd to keep workers alive. The SaaS server automation layer should only apply Apache/SSL changes when the allowlisted deployment script and sudo permissions are configured.
