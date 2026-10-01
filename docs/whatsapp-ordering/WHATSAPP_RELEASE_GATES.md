# WhatsApp Ordering Release Gates

Status: Phase 1 implementation complete; production blocked.  
Reviewed: 2026-09-08.

- **Internal code certification:** PASS, including isolation, migration, Meta/MSG91 webhook/replay and queue reliability runtime coverage.
- **Provider integration certification:** BLOCKED; no real Meta/MSG91 sandbox run has occurred.
- **Production certification:** BLOCKED; real provider E2E and a real staged canary/operational rollback rehearsal remain.

Skipped tests are never treated as successful. Every gate requires reproducible evidence from an isolated environment.

## Gate summary

| Gate | Objective | Current status | Blocking condition |
|---|---|---|---|
| 0 — Audit | Establish verified current state and gaps | Passed | None |
| 1 — Architecture | Establish trusted context, providers, opaque IDs and safe boundaries | Passed by review | Runtime certification remains downstream |
| 2 — Tenant isolation | Prove tenant, branch and customer separation at runtime | **Passed** | None |
| 3 — Migration | Prove UUID migration lifecycle on disposable data | **Passed** | None |
| 4 — Security | Verify webhook, replay, secrets, resources and authorization | **Passed internally** | None for internal certification |
| 5 — Backend regression | Protect existing backend behavior | Passed for focused scope | Full suite retains unrelated failures |
| 6 — Frontend regression | Protect tenant and SaaS admin contracts | Passed | None |
| 7 — Integration | Exercise signed webhook through queue boundary | **Internal runtime passed; provider blocked** | Real provider sandbox credentials/callback are unavailable |
| 8 — Production readiness | Authorize production rollout | **Blocked** | Provider E2E, staged canary and rollback rehearsal remain |

## Gate 0 — Audit

- **Objective:** Verify routes, models, middleware, migrations, webhook resolution, cart/order/payment reuse, admin UI and tests before change.
- **Required evidence:** Five audit/design documents; repository inspection; baseline test results.
- **Acceptance:** Documentation agrees with source and discrepancies are recorded.
- **Status:** Passed.
- **Evidence:** `WHATSAPP_CURRENT_STATE.md`, gap, architecture, security and implementation plans.
- **Blockers:** None.

## Gate 1 — Architecture

- **Objective:** Ensure WhatsApp is a channel over NexDine services, not a parallel commerce stack.
- **Required tests/review:** Context resolver tests, adapter tests, resource contract assertions, queue revalidation review.
- **Acceptance:** Provider code contains no ordering logic; tenant/branch/account context is immutable and trusted; public contracts use opaque identifiers.
- **Status:** Passed by static and focused automated review.
- **Evidence:** `WhatsAppChannelContext`, `WhatsAppChannelContextResolver`, `WhatsAppOrderingProvider`, Meta/MSG91 adapters and dedicated API resources; 14 focused tests passed with 112 assertions.
- **Blockers:** None; runtime isolation passed Gate 2.

## Gate 2 — Tenant Isolation

- **Objective:** Prove a customer/account/branch cannot read or mutate another tenant’s catalog, cart, session or order.
- **Required test:** `php artisan test tests/Feature/WhatsAppCenter/WhatsAppRuntimeIsolationTest.php --stop-on-failure`.
- **Acceptance:** 12 passed, 0 failed, 0 skipped, including payload manipulation and invalid/disabled context.
- **Status:** **Passed**.
- **Command:** `vendor/bin/phpunit -c /tmp/phpunit-whatsapp-mysql.xml tests/Feature/WhatsAppCenter/WhatsAppRuntimeIsolationTest.php --testdox --stop-on-failure`
- **Result:** 12 passed, 0 failed, 0 skipped; 20 assertions on disposable MySQL.
- **Evidence:** All twelve required tenant/account/branch/payload/resource isolation scenarios executed. The unnecessary SQLite-only test annotation was removed so the database-agnostic suite can run on supported MySQL.
- **Blockers:** None.

## Gate 3 — Migration

- **Objective:** Verify additive UUID introduction for profiles, numbers and assignments without damaging existing relationships.
- **Required test:** Disposable fresh schema → pre-UUID records → migration/backfill → uniqueness/not-empty/lookup checks → application resource checks → rollback → original-row/foreign-key checks.
- **Acceptance:** All old/new records have unique UUIDs after migration; numeric PK/FKs and data remain intact; rollback removes only UUID columns/indexes.
- **Status:** **Passed**.
- **Command:** `vendor/bin/phpunit -c /tmp/phpunit-whatsapp-mysql.xml tests/Feature/WhatsAppCenter/WhatsAppPublicIdMigrationLifecycleTest.php tests/Feature/WhatsAppCenter/WhatsAppRuntimeIsolationTest.php --testdox --stop-on-failure`
- **Result:** 13 passed, 0 failed, 0 skipped; 35 assertions on disposable schema `nexdine_whatsapp_cert_20260907`.
- **Evidence:** Fresh migration, representative pre-UUID rows, backfill validity/uniqueness, FK ownership preservation, UUID resolution, intentional numeric compatibility, rollback, unrelated-row preservation and re-application passed.
- **Blockers:** None.

## Gate 4 — Security

- **Objective:** Preserve signature, replay, ownership, authorization, secret and logging controls.
- **Required tests:** Valid/invalid HMAC, unknown profile/phone, disabled integration/tenant/branch, duplicate-identical event, duplicate-conflicting event, safe resource snapshots, permission tests and secret/log scans.
- **Acceptance:** Invalid external input cannot choose tenant/branch; failures are safe; no secrets or unnecessary IDs are emitted or logged.
- **Status:** Passed for internal code certification.
- **Evidence:** Constant-time raw-body HMAC; 1 MiB JSON limit; stored number assignment mapping; payload allowlist; profile-scoped event uniqueness/hash conflict; encrypted/hidden credentials; sanitized logging/resources.
- **Command:** `vendor/bin/phpunit -c /tmp/phpunit-whatsapp-mysql.xml tests/Feature/WhatsAppCenter/WhatsAppWebhookReliabilityTest.php --testdox --stop-on-failure`
- **Result:** 16 passed, 58 assertions on disposable MySQL for Meta and MSG91.
- **Evidence:** Valid dispatch; invalid/missing signature; malformed/missing-ID/unsupported/expired event; unknown profile/number; disabled profile/tenant/branch; wrong tenant/branch mapping; identical duplicate; conflicting replay.
- **Blockers:** None for internal security certification.

Reliability review additionally found and corrected two defects: unsupported inbound event types now fail closed, and already-processed duplicate jobs return before creating another outbound operation. Retry attempts reuse the existing reply record, record an attempt count in its safe internal payload, persist provider message IDs, and mark controlled provider failures as failed before retry.

## Gate 5 — Backend Regression

- **Objective:** Preserve order, payment, authentication and notification behavior.
- **Required tests:** WhatsApp/security plus relevant payment, order, authentication and notification suites; full unit suite classification.
- **Acceptance:** No new Phase 1 failure.
- **Status:** Passed for the focused scope.
- **Evidence:** 36 focused tests passed, 156 assertions. Composer validation passed.
- **Disposable MySQL regression:** Authentication, payment, order and notification suites completed with 46 passed, 0 failed, 0 skipped and 150 assertions. The first combined run executed 44 cases and skipped two notification cases because of an unnecessary SQLite-only annotation; after removing that test-only restriction, both notification cases passed on MySQL with 13 assertions.
- **Known unrelated failures:** Two printer tests require missing SQLite. One POS source assertion expects `$this->uuid`, while the existing resource uses `$attributes['uuid'] ?? null`; Phase 1 did not touch that resource.

## Gate 6 — Frontend Regression

- **Objective:** Ensure UUID/sanitized contracts work in restaurant and SaaS WhatsApp admin screens.
- **Required tests:** TypeScript build and Vitest.
- **Acceptance:** Zero errors/failures.
- **Status:** Passed.
- **Evidence:** TypeScript passed; 27 Vitest files and 156 tests passed.
- **Blockers:** None.

## Gate 7 — Integration

- **Objective:** Verify signed provider event → normalized event → trusted context → persisted message → queued revalidation, without provider calls escaping test fixtures.
- **Required tests:** Meta and MSG91 fixtures; good/bad signatures; unknown account/phone/event; identical retry; conflicting replay; disabled mapping; queue processing; sanitized outbound/provider failure.
- **Acceptance:** All fixtures pass, retries are idempotent, and no arbitrary tenant/branch selection is possible.
- **Status:** Internal runtime passed; external provider certification blocked.
- **Commands:** Webhook matrix command above; `vendor/bin/phpunit -c /tmp/phpunit-whatsapp-mysql.xml tests/Feature/WhatsAppCenter/WhatsAppQueueReliabilityTest.php --testdox --stop-on-failure`.
- **Result:** Queue reliability 4 passed, 35 assertions; provider adapter unit tests 3 passed, 8 assertions.
- **Evidence:** Actual job success, retry reuse, exhausted-failure callback, duplicate-job no-op, current tenant/branch/profile/assignment revalidation, safe job serialization, provider message-ID persistence and safe admin retry visibility passed.
- **Worker rehearsal:** A deliberately invalid job was dispatched through the real database queue on disposable schema `nexdine_whatsapp_worker_cert_20260907`. Three configured attempts failed; `jobs=0`, `failed_jobs=1`, queue=`whatsapp`, job class recorded, and secret-marker scan of the serialized payload returned `0`.
- **Blockers:** Real Meta/MSG91 sandbox inbound/outbound verification, plus staged canary and operational rollback rehearsal.

Canary and non-destructive rollback preparation is recorded in `WHATSAPP_PHASE1_CANARY_ROLLBACK.md`. This is design evidence only; it has not been rehearsed against a provider environment.

## Gate 8 — Production Readiness

- **Objective:** Authorize an observable, reversible rollout.
- **Required evidence:** Gates 0–7 passed; migration backup/rollback runbook; stable provider webhook cutover; queue worker health; alerting; staged tenant canary.
- **Acceptance:** No blocked gate; 12/12 isolation tests pass; migration lifecycle passes; canary rollback is rehearsed.
- **Status:** **Blocked**.
- **Blockers:** External provider sandbox E2E, staged canary and operational rollback rehearsal.

## Required next action

Keep Phase 2 frozen. Configure a reachable sandbox callback and provider credentials, perform real Meta/MSG91 inbound/outbound verification, then execute the documented staged canary and rollback rehearsal. Do not use production data.

## Final certification decision

| Gate | Status | Evidence |
|---|---|---|
| Code integrity | PASS | Final working-tree audit; Phase 1 files only, with test portability changes documented |
| PHP syntax | PASS | All modified/untracked Phase 1 PHP files linted |
| Tenant isolation | PASS | 12/12 runtime isolation cases; combined final WhatsApp suite passed |
| Webhook security | PASS | Meta/MSG91 database matrix |
| Webhook replay protection | PASS | Identical duplicate, conflicting duplicate and expired-event coverage |
| Queue reliability | PASS | Success, failure, retry recovery, stale context and safe state coverage |
| Queue exhaustion | PASS | Real database worker exhausted three attempts and recorded one failed job |
| Duplicate job protection | PASS | Duplicate after success produces no second outbound operation |
| Payment boundary | PASS | Existing verified Direct UPI service; server-derived due amount; orders remain unpaid until verified |
| Order boundary | PASS | Existing cart totals, normal Order model/status logging and `OrderCreated` pipeline reused |
| Secret protection | PASS | Encrypted/hidden credentials and targeted source/failed-payload scans |
| API exposure | PASS | Opaque UUID resources, masked customer phone and no raw provider payload/tenant ID |
| Observability | PASS | Status, retry count, provider message ID, event failure and failed-job evidence |
| Regression | PASS | Selected auth/payment/order/notification suite and frontend/type checks passed |
| Provider E2E | BLOCKED | Meta/MSG91 credentials and reachable callbacks unavailable |
| Canary | BLOCKED | Real staged environment unavailable |
| Rollback rehearsal | PASS | Internal disable, stale-context, queue failure and disposable migration rollback rehearsed; production operational rehearsal remains a canary prerequisite |
| Production certification | BLOCKED | Provider E2E and real canary are mandatory external gates |

## Independent final-gate evidence

- Provider-independent gate rerun on 2026-09-08: all 36 WhatsApp feature/unit tests passed on disposable MySQL with 136 assertions and no skipped tests.
- The rerun also confirmed 15 WhatsApp ordering/SaaS routes, 14 tenant/provider boundary tests with 112 assertions, valid Composer metadata, clean PHP syntax and a clean `git diff --check` result.
- A targeted application-log scan found no authorization header, bearer token, access token, auth key or webhook secret material. The only source-code scan match was a configuration-key reference, not a credential value.
- Fresh disposable MySQL final suite: 33 WhatsApp feature tests passed, 128 assertions, 0 failed, 0 skipped.
- Provider/tenant/partner boundary units: 16 passed, 140 assertions.
- Auth/payment/order/notification disposable-MySQL regression: 46 passed, 150 assertions, 0 failed, 0 skipped.
- Frontend: 27 files and 156 tests passed; Vue TypeScript passed.
- All WhatsApp PHP files passed syntax validation; 15 WhatsApp ordering/SaaS routes loaded; `git diff --check` passed.
- Environment contained no configured Meta, MSG91, NexMsg or WhatsApp sandbox variables, so provider results were not fabricated.
- Targeted Laravel-log scan returned no access-token, auth-key, webhook-secret, authorization or bearer match.
- Working-tree audit found Phase 1 WhatsApp/SaaS code, tests and documentation plus database-agnostic test portability changes; no debug statements, real credentials, production configuration changes or implemented Phase 2 state/catalog/cart UX.
