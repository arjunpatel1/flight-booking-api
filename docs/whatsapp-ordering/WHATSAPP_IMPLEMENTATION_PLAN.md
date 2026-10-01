# WhatsApp Ordering: Incremental Implementation Plan

Each phase is independently reviewable, reversible and protected by tests. Shared-service changes require a consumer inventory and POS/Waiter/QR/API regression run.

## Phase 0 — Baseline audit (completed by these documents)

- Inventory current backend/frontend/database integrations.
- Record gaps, risks, reuse decisions and production blockers.
- Freeze major refactoring until the baseline is reviewed.

Exit: five audit/architecture/security/implementation documents exist and match repository reality.

## Phase 1 — Trusted context and public identifiers

- Add `WhatsAppChannelContext` and a single resolver repository.
- Replace public numeric webhook/profile/resource IDs with opaque UUID/key routes.
- Add purpose-built API resources that omit internal IDs and provider payloads.
- Validate full assignment/profile/phone/tenant/branch relationship.
- Introduce provider adapter contracts and normalized inbound DTOs.

Tests: fake webhook, wrong profile/phone, tenant/branch crossing, hidden secrets/internal IDs.

## Phase 2 — Durable webhook and outbox infrastructure

- Separate receipt states: received, queued, processing, processed, failed, dead-letter.
- Add payload size/schema checks, correlation IDs and hash-conflict detection.
- Add outbound outbox/idempotency records, exponential backoff and dead-letter recovery.
- Process delivery/read/failure status callbacks.
- Enforce monthly limits atomically.

Tests: duplicate/concurrent delivery, retry without duplicate send, terminal failure, redaction.

## Phase 3 — Conversation state machine

- Add enum-backed states, command DTOs and transition registry/handlers.
- Implement `BACK`, `CANCEL`, `HELP`, `RESTART`, `HUMAN_SUPPORT` globally.
- Enforce expiry, optimistic version and transactional transitions.
- Provide deterministic recovery messages for stale/invalid actions.

Tests: every legal/illegal transition, expiry, concurrent messages, handoff/resume.

## Phase 4 — Catalog and channel visibility

- Build channel-neutral visibility policy/pivot after auditing existing visibility fields.
- Add categories, product pages, images/descriptions, search and pagination.
- Add opaque session-scoped selection tokens.
- Add restaurant admin category/product visibility controls with preview.
- Use tenant/branch/channel-safe cache keys.

Tests: cross-branch token, expired token, hidden/unavailable product, cache isolation, query count.

## Phase 5 — Customization and cart

- Reuse product option/variant/modifier validation through a shared cart facade.
- Support add, edit quantity, remove, clear and required add-ons.
- Add authoritative discounts, taxes, delivery fee and minimum order calculations.
- Add quote version/fingerprint and stale-quote recovery.

Tests: required/invalid option combinations, quantity manipulation, price manipulation, stock changes.

## Phase 6 — Customer details, order types and delivery

- Capture mandatory customer name and configurable optional fields.
- Safely resolve/create tenant customer records.
- Support pickup, delivery and dine-in when branch configuration permits.
- Add guided address capture, serviceability, fee and instructions.
- Validate business hours and scheduled-order policy.

Tests: foreign customer, unsupported type/table, invalid address, closed branch, minimum order.

## Phase 7 — Shared checkout/order application service

- Inventory QR, partner API, POS and WhatsApp order creation consumers.
- Extract one normalized server-authoritative checkout/order creator.
- Add first-class `whatsapp` source and a WhatsApp order mapping.
- Preserve existing order events, stock, KOT, kitchen, invoice and realtime behavior.
- Return only UUID/reference/status/tracking resources.

Tests: duplicate approval, transaction rollback, downstream event count, all channel regressions.

## Phase 8 — Payment orchestration

- Add explicit prepaid versus COD/pay-at-counter policies.
- Initiate the configured gateway idempotently and send link through outbox.
- Synchronize verified payment state into WhatsApp session/order flow.
- Notify success, failure, expiry and manual review without exposing payment secrets.

Tests: signature, duplicate event, amount/currency/reference mismatch, expiry and reconciliation.

## Phase 9 — Status, KOT and tracking

- Translate core order/payment/status/refund events into configured WhatsApp messages.
- Confirm WhatsApp source is visible in POS/Kitchen/order lists.
- Deliver tenant/menu-aware tracking, invoice, feedback and reorder URLs.
- Prevent stale ready/status messages through semantic idempotency keys.

Tests: one notification per transition, out-of-order events, cancelled/refunded flow, URL ownership.

## Phase 10 — Owner-friendly admin workspace

- Implement the eight-section information architecture in the architecture plan.
- Load branches/menus/gateways as select controls; no manual IDs.
- Add setup wizard, sandbox/live mode, preview, test connection/message/order and readiness gate.
- Add conversation workspace, failed-message recovery and safe audit details.
- Apply tenant theme, responsive layouts and accessible states.

Tests: permissions, validation, responsive component tests and tenant-safe resource contracts.

## Phase 11 — Operations, performance and certification

- Health checks, queue/outbox metrics, dashboards and alerts.
- Retention/purge commands and credential rotation.
- Query profiling, indexes, pagination, load tests and provider failure drills.
- Full sandbox E2E: inbound -> customized cart -> checkout -> payment -> order -> KOT -> status -> tracking.
- Produce deployment, rollback, API, database and admin documentation.

## Definition of done for every phase

1. Relevant unit, feature, integration and security tests pass.
2. Static analysis/type checks pass.
3. Tenant/branch isolation tests pass on the production database engine.
4. No secrets, PII or internal IDs leak through changed surfaces.
5. Queue/retry/idempotency behavior is verified.
6. Existing POS, Waiter, QR, partner API, payment, KOT and kitchen regressions pass where shared code changed.
7. Documentation and migration rollback notes are updated.

## Delivery priority

Phases 1–3 and runtime security tests come first. Customer-facing catalog/cart work must not proceed on top of ambiguous context or non-idempotent messaging. Payment and automatic approval remain disabled by safe default until their certification gates pass.
