# WhatsApp Ordering: Current State

Status: Phase 1 internal code certification passed; production gate blocked, 2026-09-07. Isolation, migration, Meta/MSG91 database-backed webhook/replay and queue reliability tests pass. Provider sandbox E2E, canary and rollback rehearsal remain outstanding.

Independent final-gate rerun: 33 WhatsApp feature tests/128 assertions, 16 security-boundary unit tests/140 assertions, 46 auth/payment/order/notification tests/150 assertions, and 156 frontend tests all passed without a Phase 1 failure. Provider sandbox variables and reachable callbacks remain unavailable.

## Executive summary

NexDine already contains a meaningful WhatsApp ordering foundation. It correctly treats WhatsApp as a channel over the existing branch, cart, order, payment, KOT, notification, and tenant services. It must be evolved rather than replaced.

The currently implemented customer flow is command based:

`HI -> MENU -> ADD <opaque product UUID> <quantity> -> CART -> PICKUP/DELIVERY -> CHECKOUT -> staff approval -> optional UPI link`

This is suitable as a proof of concept, but it is not yet the guided, stateful, customer-friendly ordering experience described in the product brief.

## Existing module and ownership

Primary implementation: `Modules/WhatsAppCenter`.

- Routes: `Modules/WhatsAppCenter/routes/api/v1.php`
- Tenant/admin and webhook controller: `WhatsAppOrderingController`
- Conversation/order orchestration: `WhatsAppOrderingEngine`
- Async inbound job: `ProcessWhatsAppOrderingMessage`
- Provider transport: `WhatsAppOrderingSender`
- Platform-managed provisioning: `SaasWhatsAppOrderingController`
- Restaurant admin: `src/pages/Admin/WhatsAppOrdering/Index.vue`
- SaaS control plane: `src/pages/Admin/Saas/WhatsAppOrderingControl.vue`
- Client API wrapper: `src/api/whatsapp-ordering.ts`

Supporting modules already reused:

- `Cart`: `Cart` with `CartDBStorage`
- `Branch`: active/order-accepting branch resolution and `BranchSchedule`
- `Product/Menu`: branch menu and availability queries
- `Order`: normal `Order`, order items/taxes, status logs, and `OrderCreated`
- `Payment`: tenant-scoped Direct UPI session and verified provider webhook
- `Printer/Kitchen`: existing listeners downstream of `OrderCreated`
- `Notification`: provider clients, templates, message jobs, logs and retry command
- `Saas`: tenant context, entitlements and tenant-aware model scope

## Existing data model

The ordering control-plane migration creates:

| Table | Purpose | Existing isolation/idempotency |
|---|---|---|
| `whatsapp_provider_profiles` | Encrypted provider credentials and health | `tenant_id`; credentials use `encrypted:array` and are hidden |
| `whatsapp_phone_numbers` | Provider number identities | unique profile + provider phone ID |
| `whatsapp_tenant_assignments` | Trusted number-to-tenant/branch mapping | unique phone assignment; tenant ID and allowed branch list |
| `whatsapp_conversations` | Customer conversation and staff handoff | UUID, tenant ID, branch ID, assignment ID |
| `whatsapp_messages` | Inbound/outbound messages | unique conversation + provider message ID |
| `whatsapp_order_sessions` | Server-side cart/order workflow | UUID, tenant/branch/conversation/cart/order linkage and expiry |
| `whatsapp_webhook_events` | Webhook receipt/idempotency record | unique provider profile + provider event ID and payload hash |

Older `whatsapp_templates` and `whatsapp_schedules` tables support messaging/reporting but are not a complete ordering-template/catalog abstraction.

## Existing inbound flow

1. A public profile-UUID endpoint receives the provider webhook (legacy numeric profile keys remain accepted during rollout).
2. The selected provider adapter verifies the signature over the raw body and normalizes an allowlisted event DTO.
3. `WhatsAppChannelContextResolver` resolves the provider phone identity.
4. The stored active profile/number/assignment resolves the active tenant and permitted active branches.
5. Event, conversation and inbound message records are created transactionally.
6. Duplicate provider event/message identifiers are idempotent; reuse with a different body hash is rejected.
7. `ProcessWhatsAppOrderingMessage` is queued after commit.
8. The engine executes a command and `WhatsAppOrderingSender` returns a text reply.

Positive properties:

- Request-supplied `tenant_id`, `branch_id` and account IDs are discarded by normalization.
- Credentials are encrypted at rest and excluded from serialization.
- Expensive conversation work is queued.
- Webhook and inbound message IDs have database uniqueness protection and queued/processed/failed state.
- The receiving phone number can belong to only one active tenant assignment.

## Existing cart and order behavior

- A conversation creates/reuses a server-side `CartDBStorage` cart.
- Products are queried from an active branch menu.
- Quantity is bounded from 1 to 99.
- Current price and taxes are calculated by the server cart.
- Branch opening state and delivery minimum are rechecked before approval.
- Approval locks the order session and creates one normal NexDine order transactionally.
- Order items and taxes use existing order methods.
- `OrderCreated` enters the normal stock, KOT, kitchen, realtime and notification pipeline.
- The order is created unpaid; WhatsApp text never marks it paid.

## Existing payment behavior

- An approved order can create a tenant-scoped Direct UPI payment session.
- Amount is derived from the current NexDine order due amount.
- Provider URL has HTTPS/private-network SSRF checks.
- Payment session creation is idempotent.
- Provider webhooks verify signature and validate event ID, amount, currency, merchant order reference and session ownership.
- Only a verified provider event creates the payment and can release a pending order.

## Existing admin experience

Restaurant admin currently has four tabs:

- Inbox
- Order sessions
- Ordering rules
- Connection

It exposes setup progress, connection state, approval mode, pickup/delivery, human handoff, payments, address editing, manual approval and payment-link copying.

SaaS admin can create managed provider profiles and assign a number to a tenant with message/order limits.

## Phase 1 tests

Provider normalization/signature tests, source regression tests, and 12 database-backed isolation scenarios now exist. The final combined WhatsApp suite passed 33 tests and 128 assertions on disposable MySQL with no failures or skips. It includes migration lifecycle, tenant isolation, Meta/MSG91 signed-webhook/replay coverage, queue retries, duplicate jobs, stale context, safe failures and provider-message-ID persistence. Real provider sandbox coverage remains a production-certification dependency.

## Current-state conclusion

The Phase 1 security foundation is implemented without introducing a second order engine or duplicating menu/cart/payment storage. Internal code certification passes, but Phase 1 is not production-certified until a real provider sandbox inbound/outbound flow, staged canary and rollback rehearsal pass. Guided ordering, modifiers and full tracking remain frozen outside this phase.
