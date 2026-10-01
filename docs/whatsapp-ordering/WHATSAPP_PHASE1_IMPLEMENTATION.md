# WhatsApp Ordering Phase 1 Implementation

Date: 2026-09-07  
Scope: trusted context, provider boundary, opaque identifiers, safe resources, isolation protection and regression coverage. Phase 2 customer-flow work is intentionally excluded.

## Implementation summary

- Added `WhatsAppChannelContextResolver` and an immutable `WhatsAppChannelContext`. Tenant, branch and assignment identity now come only from active stored provider-profile, phone-number and assignment relationships.
- Added Meta and MSG91 adapters behind `WhatsAppOrderingProvider`. Signature verification, inbound normalization and outbound transport are provider concerns; NexDine cart/order logic remains in the existing engine.
- Normalized inbound events contain only event ID, provider phone ID, sender, type, bounded text and an allowlisted timestamp. Arbitrary tenant/branch fields and raw provider payloads are not persisted.
- Added UUID public identifiers to provider profiles, phone numbers and tenant assignments. Existing rows are backfilled and uniquely indexed; internal foreign keys remain unchanged.
- Product selection in the WhatsApp command flow now uses product UUIDs. Numeric product IDs are no longer emitted or accepted there.
- Added dedicated resources for connections, conversations, messages and order sessions. They omit tenant/internal IDs, credentials, provider payloads and unnecessary customer data. Conversation lists mask phone numbers.
- Webhook URLs accept UUID profile keys. Numeric profile keys remain temporarily accepted for backward-compatible provider rollout, but are never returned by the new API contracts.
- Duplicate provider event IDs remain idempotent. The same event ID with a different body hash is rejected. Event state moves from queued to processed/failed in the worker.
- The queue worker re-resolves and validates trusted context before business logic. It verifies tenant, assignment and allowed branch again.
- Restaurant admin uses a branch UUID multiselect; no manual database IDs are required. Connection status, provider, ownership, branches, enabled state and webhook state are visible without exposing secrets.
- SaaS control-plane responses and selection controls use UUIDs and sanitized payloads. Legacy numeric request keys are accepted during rollout only.

## Database change

Migration: `2026_09_07_000001_add_public_ids_to_whatsapp_control_plane.php`

It additively adds and backfills a unique UUID on:

- `whatsapp_provider_profiles`
- `whatsapp_phone_numbers`
- `whatsapp_tenant_assignments`

The migration is reversible and does not replace internal keys or foreign keys. Rollback removes only the new unique indexes and UUID columns.

## API changes

- `GET|POST /api/v1/whatsapp/webhook/meta`: stable shared Meta callback. The GET challenge uses the platform verification token; POST resolves the candidate profile from Meta's phone-number ID and verifies that profile's signature before creating tenant context.
- `POST /api/v1/whatsapp/webhook/msg91`: stable shared MSG91 callback with the same fail-closed phone/account lookup and per-profile signature verification.
- `GET|POST /api/v1/whatsapp-ordering/webhook/{profile}`: backward-compatible profile UUID callback retained for zero-downtime migration; legacy numeric lookup remains temporary.
- Shared callbacks return one generic authentication error for unknown and ambiguous account mappings. Exact rejection reasons are retained only in sanitized server logs, preventing provider-account enumeration.
- Ordering overview returns sanitized connection data and `available_branches` as UUID/name pairs.
- Connection and configuration endpoints accept branch UUIDs and return branch UUIDs.
- Conversation lists/details and order-session lists use dedicated sanitized resources.
- Approval responses no longer include numeric order IDs; order reference/status/payment/total are nested in the session resource.
- SaaS WhatsApp profile/assignment APIs return profile, phone, tenant and assignment UUIDs, never provider credentials.

Consumers must use `uuid` instead of `id` for WhatsApp profiles, phone numbers, assignments and branch selections. Existing webhook configuration can be moved from its numeric profile path to the UUID path without downtime.

## Security and observability

- HMAC signatures use the original request body and constant-time comparison.
- Request bodies are JSON-only and bounded to 1 MiB.
- Disabled profiles, inactive tenants, inactive/missing branches, suspended assignments and mismatched profile/tenant ownership fail closed.
- Provider payload fields cannot override trusted tenant, branch or account context.
- Logs include correlation ID, internal tenant/branch context, opaque assignment UUID and exception class, but exclude credentials, message body and customer phone.
- No new tenant-sensitive cache was introduced. Existing WhatsApp cache keys remain tenant/branch qualified; catalog invalidation continues through existing product/menu services.
- Existing permission and feature middleware remains on all restaurant and SaaS admin routes. Only signed provider webhooks are public.

## Tests

Added:

- `tests/Unit/WhatsAppCenter/WhatsAppProviderAdapterTest.php`
- `tests/Feature/WhatsAppCenter/WhatsAppRuntimeIsolationTest.php` (12 required isolation/security scenarios)
- opaque-ID and resource regression assertions in `TenantBoundaryRegressionTest`

Local results:

- Provider and tenant boundary unit tests: 14 passed, 112 assertions.
- Runtime isolation suite: 12 discovered, skipped because local PHP does not provide `pdo_sqlite`.
- Vue TypeScript build check: passed.
- Route registration, PHP syntax and `git diff --check`: passed.

The 12 database-backed tests must be executed in CI/test infrastructure with `pdo_sqlite` before production promotion. This is a release gate, not an optional check.

## Backward compatibility and rollback

- Existing internal numeric relationships, carts, order creation, payment verification, `OrderCreated`, KOT and kitchen pipelines are unchanged.
- Existing numeric webhook routes continue resolving during migration; switch providers to UUID webhook paths, verify receipt, then remove numeric compatibility in a later release.
- Rollback application code before rolling back the UUID migration.
- No new provider calls are made synchronously in the webhook receipt transaction.

## Known limitations / Phase 2 dependencies

- Command UX, variants/modifiers, richer interactive messages, full checkout, delivery journey and customer tracking are not part of Phase 1.
- Timestamp-window validation is provider-dependent and was not previously available in the payload contract; signature plus database replay/idempotency protection remains enforced.
- Database-backed isolation tests require `pdo_sqlite` in CI or an isolated disposable MySQL test database.
- Migration columns are nullable during online backfill compatibility, while model hooks guarantee UUIDs for new records. A later online schema-hardening migration may make them non-null after fleet verification.

## Recommended Phase 2 sequence

1. Add a state-machine-driven interactive menu/category/product flow using only opaque IDs.
2. Add variants/modifiers and customer-isolated cart operations over the existing server cart.
3. Add checkout/address/payment UX with authoritative server totals.
4. Add order status/tracking messages and template/media support through the provider interface.
5. Run signed-webhook-to-order/KOT/payment end-to-end and load tests before rollout.

## Phase 1 Production Gate

Gate reviewed: 2026-09-07  
Status: **INTERNAL CODE CERTIFICATION PASSED — provider and production certification blocked**

### Environment

- PHP: 8.4.5 CLI
- PHPUnit: 11.5
- Repository-supported test database: SQLite `:memory:` from `phpunit.xml`
- CI configuration: PHP 8.4 with `pdo_sqlite` and `sqlite3`
- Local drivers: `pdo_mysql` is available; `pdo_sqlite` and `sqlite3` are absent
- Certification database: disposable MySQL schema `nexdine_whatsapp_cert_20260907`; the configured `nex-dine` application database was not migrated or modified.

The missing SQLite extension was not treated as a code defect. The unnecessary SQLite-only test annotation was removed and verification used the supported local `pdo_mysql` driver with a separately named disposable schema.

### Verification results

| Check | Passed | Failed | Skipped | Result |
|---|---:|---:|---:|---|
| WhatsApp/provider/tenant-boundary + payment/order/auth/notification focus | 36 | 0 | 0 | Passed, 156 assertions |
| Mandatory runtime isolation suite | 12 | 0 | 0 | Passed, 20 assertions on disposable MySQL |
| Frontend Vitest | 156 | 0 | 0 | Passed |
| Vue TypeScript | 1 | 0 | 0 | Passed |
| Composer validation | 1 | 0 | 0 | Passed |
| PHP syntax/routes/diff checks | all | 0 | 0 | Passed |
| Migration dry-run | 1 | 0 | 0 | Passed |
| Disposable migrate/backfill/rollback | 1 | 0 | 0 | Passed; combined lifecycle/isolation run: 13 tests, 35 assertions |
| Auth/payment/order/notification regression on disposable MySQL | 46 | 0 | 0 | Passed, 150 assertions |

The isolation annotation was made database-agnostic and the suite was executed on a disposable MySQL schema. Result: **12 passed, 0 failed, 0 skipped (20 assertions)**. A combined migration/isolation run passed **13 tests with 35 assertions**. Production certification still cannot be issued because the complete provider webhook/queue matrix and real sandbox E2E have not run.

### Focused security review

- **Tenant/account resolution — trusted/derived:** webhook profile and provider phone identity resolve the active stored assignment; request tenant/branch/account fields are not consumed.
- **Branch resolution — derived/validated:** branches are loaded under the resolved tenant, must be active, and must match the assignment allowlist. Queue processing validates the branch again.
- **Public UUIDs — validated:** public conversation/session lookups always include the authenticated tenant. Branch/product UUIDs are resolved inside tenant/branch menu constraints. Numeric webhook compatibility is limited to stored profile lookup and never selects a tenant directly.
- **Queue identifiers — internal but revalidated:** numeric queue IDs are not public; tenant, conversation, assignment and branch relationships are checked before processing.
- **Webhook — protected:** raw-body HMAC verification uses constant-time comparison; JSON and 1 MiB limits apply; provider event IDs are unique per profile; same-ID/different-body replay is rejected; duplicate identical events are idempotent.
- **Authorization — protected:** restaurant and SaaS management routes retain feature and permission middleware. Only signed provider webhook endpoints are public.
- **Resources — sanitized:** connection/conversation/message/session resources omit credentials, tenant/internal IDs and provider payloads; list phone numbers are masked.
- **Secrets/logging — protected:** credentials remain encrypted/hidden. API resources and structured logs contain no access token, auth key, webhook secret, message body or customer phone. Provider failures expose status only.
- **Cache — safe:** Phase 1 adds no tenant-sensitive cache. Existing WhatsApp service cache keys remain tenant/branch qualified.
- **Errors — fail closed:** missing/disabled/mismatched profile, number, assignment, tenant or branch returns a generic integration error and correlation code without fallback.

Certification found and fixed one genuine Phase 1 defect: legacy numeric webhook profile lookup called nonexistent `orWhereKey()`. It now uses a qualified primary-key predicate. UUID lookup remains primary and tenant derivation remains assignment-controlled.

### Migration review

The UUID migration is additive and reversible. It creates nullable UUID columns, backfills existing rows in bounded chunks, then adds unique indexes. Existing numeric primary and foreign keys are retained. Rollback removes only the new indexes/columns and does not delete WhatsApp rows.

Static review, MySQL dry-run, fresh schema, populated backfill, UUID validity/uniqueness, ownership integrity, resolver lookup, rollback and re-application passed on disposable MySQL. The production database was not used.

### Known unrelated failures

- Two printer signature tests fail because their database setup requires the same missing SQLite driver. Classification: **PRE-EXISTING ENVIRONMENT FAILURE**. Printer code was not modified.
- `PublicCapabilitySecurityTest::test_customer_checkout_uses_opaque_menu_scoped_product_references` expects the exact source string `"reference" => $this->uuid`; the resource currently uses `"reference" => $attributes['uuid'] ?? null`. Both changes belong to the existing `2aef0a4` in-progress commit, and no Phase 1 WhatsApp change touches that POS resource. Classification: **PRE-EXISTING UNRELATED SOURCE-ASSERTION MISMATCH**, not an observed WhatsApp regression.

### Production blockers and release recommendation

Database-backed Meta/MSG91 webhook coverage passes 16 tests/58 assertions, and queue reliability passes 4 tests/35 assertions. A real disposable database worker also exhausted three controlled attempts and created one secret-safe failed-job record. Before deployment, run a real provider sandbox inbound/outbound canary, verify the returned provider message ID is persisted, and rehearse the operational deployment rollback. Phase 2 remains frozen.
