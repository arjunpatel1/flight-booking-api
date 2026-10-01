# Customer App Phase 1.5 Recovery Report

## 1. Original failure

The Phase 1.5 result reported `0 passed / 0 failed / 20 skipped`. Because no HTTP security test executed, that result could not certify IDOR resistance, tenant spoofing resistance, resource ownership, or mandatory context coverage.

## 2. Exact skip reason

The skip was caused by the test runner environment. The active PHP 8.4 CLI exposed the MySQL PDO driver but did not load `pdo_sqlite` or `sqlite3`. `CustomerAppSecurityContractTest` therefore skipped its disposable in-memory SQLite suite before application assertions ran. This was a **TEST INFRASTRUCTURE BLOCKER**. It was not evidence that the application security checks passed or failed.

## 3. Test environment fix

A signed Ubuntu PHP 8.4 SQLite package was downloaded and extracted under `/tmp/nexdine-php-sqlite`; the host PHP installation and production configuration were not changed. PHPUnit was invoked directly with the extracted `pdo_sqlite.so` and `sqlite3.so` extensions because the Artisan test subprocess did not retain the temporary extension flags. The suite creates and removes an in-memory disposable schema, including the translation table required during HTTP error rendering. No production, live, or developer database was used.

The skip guard was removed after the runner prerequisite was made explicit. The existing security behavior was then exercised without weakening refresh or ownership assertions.

## 4. Route inventory

| Route family | Methods | Public | Customer auth | Customer App context | Tenant/resource protection | Status |
|---|---|---:|---:|---:|---|---|
| `/api/v1/saas/customer-app/bootstrap` | POST | Yes | No | Registration resolution | Server-owned app-to-tenant mapping, signed manifest, throttle | Covered |
| `/api/v1/saas/customer-app/session` | POST | Yes | No | Registration resolution | Signed manifest verification, throttle | Covered |
| `/api/v1/customer-auth/register` | POST | Yes | No | Yes | Resolved tenant; request tenant identifiers ignored | Covered |
| `/api/v1/customer-auth/login` | POST | Yes | No | Yes | Customer must belong to resolved tenant | Covered |
| `/api/v1/customer-auth/me` | GET, PUT | No | Sanctum | Yes | Customer and tenant checked | Covered |
| `/api/v1/customer-auth/addresses` | GET, POST | No | Sanctum | Yes | Customer ownership checked | Covered |
| `/api/v1/customer-auth/addresses/{address}` | PUT, DELETE | No | Sanctum | Yes | Customer ownership checked | Covered |
| `/api/v1/customer-auth/push-devices` | PUT, DELETE | No | Sanctum | Yes | Customer ownership checked | Covered |
| `/api/v1/customer-auth/logout` | POST | No | Sanctum | Yes | Current customer session | Covered |
| `/api/v1/online-menus/{slug}/menu` | GET | Yes | No | No | Tenant-domain/feature middleware only | Gap |
| `/api/v1/public/cart/{cartId}/*` | Multiple | Yes | No | No | Shared `PublicTenantGuard` and branch/cart checks | Gap |
| `/api/v1/orders/public-qr/{cartId}` | POST | Yes | Optional | No | Tenant guard, idempotency, 20/min throttle | Gap |
| `/api/v1/orders/public-tracking/{reference}` | GET | Yes | No | No | Tenant branch scope; reference lookup | Gap |
| `/api/v1/orders/feedback` | POST | Yes | No | No | Tenant guard | Gap |
| `/api/v1/customer-reservations*` | Multiple | Yes | No | No | Tenant domain; reference plus phone for show/cancel; 30/min throttle | Gap |

These shared public endpoints are used by the current customer client but are not dedicated Customer App API contracts. They were audited only; changing them would violate this recovery phase's boundary against public menu, POS, QR, and unrelated workflow changes.

## 5. Middleware coverage

The customer authentication API uses authentication plus `ResolveCustomerAppContext`, and bootstrap/session use their named throttles. The middleware resolves registration, tenant, tenant state, optional authenticated customer, optional branch, subscription, and entitlement before constructing immutable context. It clears both tenant and customer-app context in `finally`.

Mandatory coverage is incomplete because cart, checkout, tracking, feedback, reservation, and online-menu calls used by the customer client do not pass through this middleware. The canonical chain is therefore not yet an invariant across the actual customer journey.

## 6. Context resolution

Registration identity is accepted through `X-NexDine-Customer-App-Token`, hashed server-side, and resolved to a server-owned registration and tenant. Request-provided `tenant_id`, `restaurant_id`, `app_id`, and tenant headers are not authoritative for covered endpoints. Tenant status, customer-to-tenant association, subscription, and entitlement are rechecked.

The immutable context currently records no branch (`branch_id` is `null`). Branch-bound customer operations therefore cannot yet be certified through this context. A dedicated customer facade must resolve and authorize branch identity server-side before controller access.

## 7. IDOR

Profile, address, and device-token ownership behavior is covered by the authenticated context. A reusable `CustomerAppResourceAuthorizationService` rejects foreign tenant ownership for its supported resource labels.

Overall IDOR certification fails because products, categories, menus, branches, carts, cart items, orders, reservations, delivery, and notifications are not all exercised through real dedicated Customer App HTTP endpoints with Tenant A/Tenant B identifiers. Service-level label assertions cannot replace endpoint-level list/show/create/update/delete tests.

## 8. Spoofing

The executed login HTTP test submits forged `tenant_id`, `branch_id`, `customer_id`, `restaurant_id`, foreign `app_id`, `X-Tenant-ID`, and `X-Tenant-Id`; the server resolves the covered request from the app registration instead. Cross-tenant customer and branch resolution is also rejected by authorization tests.

Overall spoofing certification fails because the shared public customer journey routes bypass mandatory app identity and immutable context. Those routes require dedicated context-bound facades and equivalent HTTP spoof cases before certification.

## 9. Resource ownership

| Resource | Current evidence | Gate |
|---|---|---|
| Profile | Authenticated context test | Pass |
| Addresses | Authenticated ownership middleware and route audit | Pass |
| Device tokens | Authenticated ownership middleware and route audit | Pass |
| Products/categories/menus | Shared public route, no app context HTTP matrix | Fail |
| Branches | Context branch is unset | Fail |
| Carts/cart items | Shared public routes, no app context HTTP matrix | Fail |
| Orders | Shared QR/tracking routes, no app context HTTP matrix | Fail |
| Reservations | Shared public routes, no app context HTTP matrix | Fail |
| Delivery | No dedicated context-bound contract verified | Fail |
| Notifications | No dedicated context-bound contract verified | Fail |

## 10. Public endpoints

Bootstrap and session endpoints are intentionally public, return generic machine-safe errors, and use named rate limits. Register and login are intentionally unauthenticated but require app context.

Online menu, cart, checkout, tracking, feedback, and reservation endpoints are public for existing restaurant/QR use cases. They cannot simply receive customer-app middleware without changing those existing products. Tracking and feedback need explicit enumeration/rate-limit review, while cart routes need an explicit public abuse budget. The production-safe resolution is a dedicated `/api/v1/customer-app/*` facade that invokes existing services after mandatory context and ownership checks.

## 11. HTTP results

Command environment: PHP 8.4 with disposable SQLite extensions loaded from `/tmp`, direct PHPUnit invocation.

Result: **20 passed / 0 failed / 0 skipped**, 112 assertions, approximately 2.0 seconds, 56.5 MB peak memory.

This result closes the test-infrastructure blocker and validates the implemented Phase 1 contract tests. It does not convert uncovered shared customer journey routes into certified routes.

## 12. Query/performance

The context resolver uses bounded registration/session relationships and avoids trusting client tenant identifiers. Cleanup is guaranteed in a `finally` block. The recovery suite completes quickly, but it contains no formal per-route query-count ceilings or high-concurrency measurements. Query/performance certification remains a risk until dedicated endpoints have query-budget assertions and concurrency tests.

## 13. Waiter regression

No Waiter App, POS workflow, activation, QR, printing, or Reverb source file was changed during this recovery. No Waiter runtime/build was executed because the phase explicitly prohibited touching or generating the Waiter application. Source regression introduced by this recovery: none.

## 14. Changed files

Recovery-specific changes:

- `tests/Feature/Saas/CustomerAppSecurityContractTest.php`: removed environmental skipping, added disposable translation schema cleanup, and added forged body/header identity coverage to the real login request.
- `docs/architecture/customer-app-phase-1.5-recovery-report.md`: this evidence report.

The pre-existing Phase 1/1.5 implementation under review includes the customer app registration/session models and migrations, bootstrap/session controllers, context middleware/support objects, authorization/manifest/resource services, SaaS configuration, and customer-auth route registration.

## 15. Untouched areas

- Waiter App and any generated Customer App build
- APK/AAB, signing, build workers, and Generate App UI
- Existing public menu behavior
- Existing public cart/POS/QR checkout workflow
- Printing and Reverb
- SaaS Admin and restaurant Admin workflows unrelated to the context contract
- Production database and production PHP configuration

## 16. Remaining risks

Phase 1.5 cannot pass until actual customer client operations use dedicated context-bound routes for menu/catalog, branch selection, cart and cart items, checkout/order, tracking, reservations, delivery, and notifications. Each route needs real Tenant A/Tenant B HTTP tests for list/show/create/update/delete where applicable, foreign identifiers, customer-token/app mismatch, request/header spoofing, generic errors, explicit public rate limits, query ceilings, and context cleanup. Branch must be resolved into immutable context. No Customer App generation should begin before those gates pass with zero skipped tests.
