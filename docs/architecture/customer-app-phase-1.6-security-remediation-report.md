# Customer App Phase 1.6 Security Remediation Report

## 1. Previous failure evidence

Phase 1.5 recovery executed 20 tests successfully, but the gate remained closed because the real customer journey still used shared public menu, cart, order, QR, and reservation routes. Those routes did not carry the immutable Customer App context, so endpoint-level tenant isolation could not be certified. The previous report correctly recorded mandatory context, IDOR, tenant spoofing, and resource ownership as incomplete.

Phase 1.6 remediates that specific failure. It adds compatibility-safe `/api/v1/customer-app/*` aliases for the customer journey, applies the canonical context middleware before controller execution, and retains the existing shared public endpoints for table QR and restaurant-web compatibility.

## 2. Actual vulnerable routes

The vulnerable Customer App call paths identified in Phase 1.5 were the client-facing uses of:

- public online menu lookup;
- public cart reads and mutations;
- public QR checkout, tracking, and feedback;
- public QR resolution;
- public reservation create/show/cancel.

They were vulnerable as a Customer App contract because application identity was optional and the immutable registration/tenant context was absent. They are no longer Customer App routes. The registered Customer App contract contains 18 dedicated aliases: one menu route, ten cart routes, three order routes, one QR route, and three reservation routes. Every one is protected by `ResolveCustomerAppContext` and rate limiting.

## 3. Actual IDOR findings

The audit found that top-level tenant checks alone were insufficient for nested identifiers. A Tenant A application could otherwise submit a foreign branch, cart, product, order reference, or reservation reference and rely on downstream model lookup behavior.

The remediated path resolves the application registration and tenant first, rejects a foreign branch, and then invokes existing tenant-aware guards. Cart products are constrained through the selected menu's branch and tenant ownership; carts are bound to the authorized branch; order tracking is constrained through branch ownership; and reservation lookups require a branch belonging to the resolved tenant. The executable suite also rejects foreign resource ownership labels and foreign branch/customer identities.

Result: **PASS** for the registered Customer App contract.

## 4. Tenant spoofing findings

The following client-controlled identity aliases were tested: `tenant_id`, `tenantId`, `restaurant_id`, `restaurantId`, `branch_id`, `branchId`, `customer_id`, `customerId`, `app_id`, `appId`, `X-Tenant-ID`, `X-Restaurant-ID`, `X-Branch-ID`, and `X-NexDine-Tenant-Domain`.

None is authoritative. Tenant identity comes from the hashed Customer App session token and its server-owned registration. Branch and authenticated customer must belong to that tenant. Conflicting values fail closed with a generic Customer App error; matching compatibility values do not replace the server-derived context.

Result: **PASS**.

## 5. Resource ownership findings

| Resource | Ownership boundary | Result |
| --- | --- | --- |
| Profile | authenticated customer plus resolved tenant | PASS |
| Addresses | authenticated customer plus resolved tenant | PASS |
| Push devices | authenticated customer plus resolved tenant | PASS |
| Menu/catalogue | context tenant plus tenant-domain/branch guard | PASS |
| Branch | registration tenant | PASS |
| Cart/cart items | context tenant, authorized branch, cart UUID | PASS |
| Products | product menu branch must equal authorized branch | PASS |
| Orders/tracking/feedback | context tenant through branch ownership | PASS |
| Reservations | reference resolves only through context tenant branches | PASS |

No controller accepts a request tenant identifier as the ownership source.

## 6. Middleware before/after

Before Phase 1.6, only bootstrap, session exchange, and customer-auth routes used the canonical Customer App boundary. Customer menu/cart/order/reservation traffic used shared public routes.

After Phase 1.6:

- all 18 `/api/v1/customer-app/*` data routes require `ResolveCustomerAppContext` and a throttle;
- all 11 `/api/v1/customer-auth/*` routes require the same context;
- the nine protected customer identity routes additionally require Sanctum;
- login and registration remain intentionally unauthenticated but require a valid Customer App session and login throttling;
- middleware restores tenant context before downstream access and clears it in `finally`.

The route-inventory test locks the expected counts, so adding an unreviewed route fails certification.

## 7. Public endpoint classification

| Surface | Classification | Required controls |
| --- | --- | --- |
| Customer App bootstrap | controlled public handshake | registration lookup, entitlement, signed short-lived manifest, throttle |
| Customer App session | controlled public exchange | signature/expiry/replay checks, hashed opaque token, throttle |
| Customer registration/login | public identity entry | live app session, resolved tenant, throttle |
| Customer App data routes | private application contract | live app session, canonical context, ownership checks, throttle |
| Legacy online menu/cart/order/QR/reservations | compatibility public restaurant/QR contract | authenticated tenant-domain resolution, tenant-aware guards, throttle |

Legacy public routes were not converted into Customer App routes and were not weakened. An executable route audit requires every retained legacy route in these families to include `EnsureAuthenticatedTenant` and rate limiting.

## 8. Public endpoint test results

Bootstrap tests cover valid resolution, unknown registration, inactive registration, inactive tenant, package/platform mismatch, forged tenant, and throttling. Session tests cover signed exchange, one-use manifests, replay, identity mismatch, stale branding revision, expiry, current tenant/subscription revalidation, hashed token persistence, and wrong package.

The legacy public route inventory is executed against Laravel's registered routes and verifies explicit tenant middleware plus throttling. The dedicated Customer App aliases are separately exercised fail-closed and cannot fall back to legacy public behavior when the application session is absent.

Result: **PASS**.

## 9. HTTP certification results

Command: disposable SQLite test database with PHP 8.4 SQLite extensions loaded from `/tmp`; no production database was used.

Result: **57 tests passed, 0 failed, 0 skipped; 335 assertions**. The mandatory fail-closed route matrix contributes **20 passed, 0 failed, 0 skipped** and covers every externally entered Customer App family plus register/login.

## 10. Fail-closed tests

All 18 Customer App data endpoints and both public customer identity entry endpoints reject a request without a live Customer App session. Expired sessions, revoked registrations, inactive tenants, inactive subscriptions, missing entitlement, invalid manifests, replayed manifests, foreign branches, foreign customers, and forged tenant aliases are rejected. Error responses use stable generic machine codes and do not expose database identifiers, SQL, credentials, or tenant existence.

Result: **PASS**.

## 11. Performance impact

The context boundary performs a single indexed session-token hash lookup with bounded eager loading, then validates current registration, tenant, subscription, and entitlement state. Session and registration tables have tenant/status and uniqueness indexes. `last_seen_at` writes are rate-limited, and no authorization decision is cached across identities. The middleware adds a small fixed lookup cost and does not introduce unbounded collections or per-resource catalogue scans.

Formal high-concurrency load testing remains outside this security-only phase.

## 12. Files changed

Phase 1 through 1.6 Customer App security implementation under certification:

- Customer App registration/session models, migrations, controllers, services, exception, immutable context, entitlement support, and context middleware under `Modules/Saas`;
- Customer App bootstrap/session and canonical facade route registration;
- customer-auth hardening and route registration under `Modules/User`;
- compatibility-safe customer aliases in Cart, Menu, Order, POS, and Seating Plan route files;
- `tests/Feature/Saas/CustomerAppSecurityContractTest.php`;
- Phase 1/1.5/1.6 architecture reports.

This Phase 1.6 pass specifically expanded the fail-closed route matrix, added registered-route security invariants, added the retained-public-route audit, and produced this report. Unrelated dirty inventory, finance, import, product, and manager-approval work was preserved and is not claimed by this phase.

## 13. Files untouched

- `nexdine-waiter-pos` source;
- Waiter activation and QR activation keys;
- POS ordering behavior;
- printer-agent and printing behavior;
- Reverb configuration and channels;
- build workers, APK/AAB generation, signing, Generate App UI, and CI build automation;
- production/staging infrastructure and databases.

## 14. Waiter regression verification

No Waiter repository file was changed by this remediation. Existing public QR/POS endpoints remain registered separately and retain their tenant middleware and throttles. No Waiter build or runtime test was performed because the phase explicitly prohibited build generation and Waiter-flow modification.

Source regression introduced by Phase 1.6: **NONE**.

## 15. Remaining risks

- This security phase certifies isolation, ownership, spoofing resistance, and fail-closed behavior; it does not certify production load, UX, app generation, or deployment.
- The legacy public route contract remains an intentionally separate attack surface and must keep its tenant guard, ownership validation, abuse limits, and independent regression suite.
- Any new Customer App route, resource type, identity header, or public endpoint must update the locked route inventory and Tenant A/Tenant B matrix before release.
- Migration rollout must remain additive and be verified in staging before production.

All Phase 1.6 security gates required before Customer App generation are satisfied by the current registered route contract and executed suite.
