# Customer App Phase 1.5 HTTP Isolation Report

## Decision

Phase 1.5 **fails its final security gate and is not certified for Customer App generation**. The
exclusive customer authentication boundary is fail-closed, but the current
Customer App also consumes public menu, cart, order, QR, and reservation routes
that are shared with existing table-QR and POS workflows. Applying mandatory
Customer App context to those routes would change an existing public contract
and regress explicitly protected waiter/POS/QR behavior.

No shared waiter, POS, printing, Reverb, activation, QR, or order-workflow file
was changed in this phase.

## Implemented boundary

- `POST /api/v1/saas/customer-app/bootstrap` resolves the application
  registration and tenant on the server and returns a short-lived signed,
  one-use manifest.
- `POST /api/v1/saas/customer-app/session` exchanges that manifest for a
  random, hashed-at-rest application session bound to the registration,
  tenant, installation, platform, and package.
- Every `/api/v1/customer-auth/*` route now requires the canonical
  `ResolveCustomerAppContext` middleware.
- The middleware derives tenant and customer identity from the application
  session and authenticated token, overwrites spoofable legacy request fields,
  composes with `TenantContext`, and clears both contexts in `finally`.
- Registration state, tenant state, package, platform, entitlement, session
  expiry/revocation, customer role, and customer/tenant membership are
  revalidated on each protected request.
- Context-resolution errors fail closed without leaking tenant existence.
  Downstream validation/domain exceptions retain their normal API semantics.

## Actual route inventory and classification

| Route family | Classification | Context result |
| --- | --- | --- |
| `/saas/customer-app/bootstrap` | Controlled public bootstrap | PASS |
| `/saas/customer-app/session` | Controlled public session exchange | PASS |
| `/customer-auth/register`, `/customer-auth/login` | Customer auth write | PASS |
| Protected `/customer-auth/*` profile, logout, push devices, addresses | Customer read/write | PASS |
| `/online-menus/{slug}/menu` | Shared public menu read | BLOCKED |
| `/public/cart/{cartId}/*` | Shared public cart read/write | BLOCKED |
| `/orders/public-qr/{cartId}` | Shared table-QR order write | BLOCKED |
| `/orders/public-tracking/{reference}` | Shared public order read | BLOCKED |
| `/orders/feedback` | Shared public feedback write | BLOCKED |
| `/qr-order/resolve` | Shared POS/table-QR bootstrap | BLOCKED |
| `/customer-reservations/*` | Existing public reservation read/write | BLOCKED |

`BLOCKED` means the route cannot be certified as a Customer App route until a
dedicated versioned Customer App facade exists. It does not mean the shared
route was modified or disabled.

## Resource ownership audit

| Resource | Result | Evidence / blocker |
| --- | --- | --- |
| Customer profile | PASS | Authenticated customer ID and tenant are server-derived. |
| Customer addresses | PASS | Protected customer-auth route under canonical context. |
| Customer device tokens | PASS | Protected customer-auth route under canonical context. |
| Menus, products, categories, branches | FAIL | Read through shared public online-menu contract. |
| Carts and cart items | FAIL | Mutate through shared public cart contract. |
| Orders, history, tracking, delivery | FAIL | Shared public/table-QR order contracts. |
| Reservations | FAIL | Existing public reservation contract. |
| Notifications | FAIL | Device registration is isolated; complete notification read/history ownership is not exposed through the isolated group. |

## Spoofing and IDOR assessment

- Tenant spoofing on the isolated customer-auth boundary is rejected: client
  `tenant_id`, domain headers, customer IDs, package IDs, and platform values
  cannot replace server-derived context.
- Expired/revoked sessions and registrations are rejected on every request.
- Manifest replay is rejected and session tokens are stored only as hashes.
- Full Customer API IDOR certification fails because the shared resource routes
  above are outside the mandatory Customer App context.

## HTTP isolation verification

The feature suite contains 20 cases covering two tenants/customers, bootstrap,
manifest replay, session exchange, package/platform mismatch, inactive and
revoked registrations, expired sessions, spoofed tenant/customer values,
foreign branches, and resource ownership. In this environment all 20 are
explicitly skipped before schema setup because the PHP CLI has no
`pdo_sqlite` driver. The disposable suite is intentionally not pointed at a
real MySQL database because it creates and drops its security schema.

Syntax and route registration verification remain executable independently.

Because the HTTP suite did not execute, tenant/header spoofing remains
uncertified even though the middleware ignores and overwrites the listed
client fields. Static inspection is not treated as a passing HTTP security
test.

## Public endpoint exposure

- Bootstrap exposes only the signed runtime manifest for a valid registered
  app; tenant database IDs and credentials are excluded. Tenant resolution is
  from the registration UUID/package/platform tuple. Customer auth is not
  required.
- Session exchange exposes an opaque session token only after signature,
  expiry, replay, registration, package, platform, tenant, and entitlement
  checks. Customer auth is not required.
- Online menu is intentionally public for restaurant discovery/table ordering
  and resolves by slug. Its transitive product/category/branch exposure still
  requires a dedicated Customer App ownership test before certification.
- Public cart, QR order, tracking, feedback, QR resolution, and reservation
  endpoints predate this boundary. They remain public/shared for compatibility
  and are therefore not certified as Customer App endpoints.

## Error and performance assessment

The context boundary performs one hashed session lookup with eager-loaded
registration/tenant data, followed by current registration/tenant/entitlement
validation. `last_seen_at` writes are limited to once per five minutes. No
tenant/customer authorization result is cached across identities; replay cache
keys contain a SHA-256 manifest identity. Error responses use stable generic
codes and do not expose tenant existence, database IDs, SQL, or stack traces.

These conclusions are code/route audit results. Query-count and debug-mode HTTP
error-disclosure certification remains blocked with the skipped HTTP suite.

## Migration and file audit

Both additive migrations are registered but pending in this local database:

- `2026_08_11_000001_create_customer_app_registrations_table`
- `2026_08_11_000002_create_customer_app_sessions_table`

Phase 1.5 changed/added the Customer App session model, migration, session
service/controller, immutable context, canonical middleware, customer-auth and
SaaS route wiring, focused feature tests, registration relationship/config,
and this report. Existing unrelated dirty worktree changes were preserved.

Intentionally untouched: `nexdine-waiter-pos`, waiter activation, activation
QR/key/runtime configuration, printing, Reverb, POS workflows, shared public
menu/cart/order/QR/reservation controllers, and deployment/build/signing files.

## Validation evidence

- PHP syntax: PASS for the Phase 1.5 PHP files and migrations.
- Route registration: PASS; 2 bootstrap/session routes and 11 customer-auth
  routes were inspected with verbose middleware output.
- `git diff --check`: PASS.
- HTTP isolation: 20 skipped, because `pdo_sqlite` is unavailable.
- Migration execution: not performed; both migrations are pending locally.
- Waiter regression: no waiter file was changed; runtime waiter regression
  tests were intentionally outside this phase.

## Required compatibility-safe remediation

Stop before Customer App generation. Add dedicated versioned
`/api/v1/customer-app/*` facade routes for catalogue, cart, orders, tracking,
feedback, reservations, and notifications. Put the canonical context on that
entire group, enforce resource ownership in its services, update only the
Customer App client contract, and retain the existing shared QR/POS routes
unchanged. Then rerun the two-tenant HTTP matrix with `pdo_sqlite` available.
