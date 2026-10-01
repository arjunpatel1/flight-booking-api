# SaaS Billing and License Management

## Scope

This layer extends the existing NexDine SaaS billing system. It does not change tenant architecture, authentication, provisioning, or tenant isolation.

## Supported Lifecycle

- Trial subscriptions can be created by existing provisioning flows.
- Trial reminders run through `saas:billing-lifecycle`.
- Expired trials move to grace status.
- Expired active subscriptions move to grace status.
- Expired grace subscriptions are marked expired and the tenant is suspended.
- Dunning continues to process overdue SaaS billing invoices.

## Coupons

Coupons are stored in `saas_coupons` and redemptions in `saas_coupon_redemptions`.

Supported coupon types:

- `percentage`
- `flat`
- `free_months`
- `trial_extension`
- `plan_upgrade`

Limits:

- global usage limit
- per tenant limit
- per email limit
- per mobile limit
- expiry date
- active/inactive status

Lifetime coupons are recorded in the subscription `overrides.billing_lifetime_coupon` metadata for support visibility.

## Activation Keys

Activation keys are stored in `saas_activation_keys` with audit records in `saas_activation_events`.

Activation keys can:

- activate a tenant
- switch a tenant subscription plan
- enforce expiry
- enforce tenant ownership
- enforce activation limits
- record device/IP context

## Gateway Webhooks

Public webhook endpoints:

- `POST /api/v1/saas/billing/webhooks/razorpay`
- `POST /api/v1/saas/billing/webhooks/stripe`

Required env keys:

```env
SAAS_RAZORPAY_WEBHOOK_SECRET=
SAAS_STRIPE_WEBHOOK_SECRET=
```

Payment callbacks are signature verified and idempotent. Duplicate callbacks do not duplicate `payment_completed` events.

## Scheduler and Queues

The scheduler dispatches:

```bash
php artisan saas:billing-lifecycle
```

Daily at `02:15`. The command queues `RunSaasBillingLifecycleJob` on:

```env
SAAS_MONITORING_QUEUE=monitoring
```

For immediate local execution:

```bash
php artisan saas:billing-lifecycle --sync
```

## Admin APIs

Protected by `admin.saas.manage`:

- `POST /api/v1/saas/billing/coupons`
- `POST /api/v1/saas/billing/coupons/apply`
- `POST /api/v1/saas/billing/activation-keys`
- `POST /api/v1/saas/billing/activation-keys/activate`
- `POST /api/v1/saas/billing/lifecycle/run`

Protected by existing SaaS billing permissions:

- invoice create
- payment intent create
- manual mark paid
- dunning run

## Deployment

```bash
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

Ensure the monitoring queue worker is running if lifecycle work should process asynchronously.

## Rollback

Rollback removes only the new additive license tables:

- `saas_activation_events`
- `saas_activation_keys`
- `saas_coupon_redemptions`
- `saas_coupons`

Existing tenant, subscription, invoice, provisioning, and payment records are not modified by rollback.
