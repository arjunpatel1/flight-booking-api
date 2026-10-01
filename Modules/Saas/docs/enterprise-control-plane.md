# Enterprise SaaS Control Plane

This layer completes the developer-free onboarding surface without replacing existing tenant, billing, provisioning, or device systems.

## Verified Existing Systems

- Tenant isolation: existing tenant scopes and SaaS tenant context.
- Activity logging: existing `ActivityLog` module and `HasActivityLog` traits.
- Feature limits: existing `tenant_feature_limits`, subscription plan features, and overrides.
- Devices: existing POS terminal device heartbeat/control plane.
- Provisioning: existing `SaasProvisioningService` plus `ProvisioningOrchestratorService`.
- Delivery: existing `SaasDeliveryJob` for server automation and optional white-label app build.

## Added Control Plane APIs

- `GET /api/v1/saas/control-plane`
- `GET /api/v1/saas/control-plane/tenants/{tenant}/features`
- `POST /api/v1/saas/control-plane/tenants/{tenant}/features`
- `GET /api/v1/saas/control-plane/tenants/{tenant}/usage`
- `GET /api/v1/saas/control-plane/tenants/{tenant}/activity`

All routes require `admin.saas.manage`.

## Feature Flags

Feature state is resolved in this order:

1. Subscription plan features
2. Subscription overrides
3. Tenant feature limit records
4. Tenant runtime flag override in `tenants.settings.feature_flags.tenant`
5. Branch runtime flag override in `tenants.settings.feature_flags.branches`

No deployment is required for tenant/branch overrides.

## Usage Metering

The control plane summarizes current usage from existing tenant/branch scoped tables:

- branches
- users
- orders
- invoices
- payments
- tables
- menu items
- customers
- printer jobs
- POS devices
- feature limit usage

The existing `FeatureLimitService` remains the source of truth for quota counters.

## Audit / Activity

Feature flag changes are written to the existing `activity_log` table with:

- causer
- tenant
- old value
- new value
- IP/user-agent through the existing activity log stack

## App Icon and Runtime JSON

The generic Waiter app auto-refreshes runtime JSON on startup. Server-driven config supports:

- API/Reverb endpoints
- app name
- restaurant name
- logo URLs
- splash logo URL
- app icon URL metadata
- theme colors

Native launcher icons still require the optional white-label build delivery job because Android does not allow changing the installed launcher icon from remote JSON.

## Enterprise Login / SSO

NexDine supports three tenant-safe sign-in layers from one codebase:

- Password login with tenant-domain resolution.
- MFA and passkeys through the existing User module.
- Optional enterprise OAuth SSO for configured providers.

Configure provider credentials in the backend environment:

```env
SSO_GOOGLE_ENABLED=true
SSO_GOOGLE_CLIENT_ID=
SSO_GOOGLE_CLIENT_SECRET=

SSO_MICROSOFT_ENABLED=true
SSO_MICROSOFT_CLIENT_ID=
SSO_MICROSOFT_CLIENT_SECRET=
SSO_MICROSOFT_TENANT=common
```

The login screen calls `GET /api/v1/auth/sso/providers` and only displays SSO buttons for providers with enabled credentials. Callback handling uses a short-lived handoff token, so Sanctum tokens are never placed in the browser URL. SSO users must already exist in the correct tenant by verified email; SSO does not auto-create users.

Register these redirect URLs with the OAuth provider:

- Google: `https://api.example.com/api/v1/auth/sso/google/callback`
- Microsoft: `https://api.example.com/api/v1/auth/sso/microsoft/callback`
