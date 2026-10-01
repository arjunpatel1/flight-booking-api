# WhatsApp Ordering: Target Architecture Plan

## Architectural decision

Keep `Modules/WhatsAppCenter` as the channel adapter and control plane. Do not create a parallel Menu, Cart, Customer, Order, Payment, KOT or Kitchen implementation.

```text
Meta / MSG91 webhook
  -> provider adapter: authenticate + normalize
  -> trusted WhatsAppChannelContext
  -> idempotent receipt + queue
  -> conversation state machine
  -> channel-neutral Catalog / Cart / Checkout services
  -> NexDine Order + Payment application services
  -> existing OrderCreated / status / payment events
  -> WhatsApp notification listener + outbox
  -> provider transport
```

## Trusted channel context

Introduce an immutable application object populated only by the authenticated webhook resolver:

```text
WhatsAppChannelContext
- tenantId
- assignmentUuid
- providerProfileId (internal only)
- phoneNumberId (internal only)
- allowedBranchIds
- capabilities
- ownershipMode
- correlationId
```

Controllers and jobs pass this context or its opaque reference. Customer input must never overwrite tenant, assignment or authorized branches.

## Provider boundary

Extract the provider-specific code behind contracts:

- `WhatsAppWebhookAuthenticator`
- `WhatsAppWebhookNormalizer`
- `WhatsAppTransport`
- `WhatsAppTemplateTransport`

Normalized inbound events must contain provider event ID, provider phone ID, sender identifier, message type, safe text/interaction selection, timestamp and status-event metadata. The domain/application layer must not parse Meta array paths.

## Conversation state machine

Use explicit enums and transition handlers, not nested conditions:

```text
START -> WELCOME -> BRANCH -> MENU -> CATEGORY -> PRODUCT
PRODUCT -> CUSTOMIZATION -> CART
CART -> CUSTOMER_DETAILS -> ORDER_TYPE
ORDER_TYPE -> ADDRESS | TABLE | CHECKOUT_REVIEW
CHECKOUT_REVIEW -> PAYMENT_PENDING | APPROVAL_PENDING
PAYMENT_PENDING -> PAYMENT_VERIFIED -> CONFIRMED
CONFIRMED -> PREPARING -> READY -> OUT_FOR_DELIVERY -> DELIVERED
* -> HUMAN_HANDOFF | CANCELLED | EXPIRED
```

Global intents (`BACK`, `CANCEL`, `HELP`, `RESTART`, `HUMAN`) are transition commands. Every transition validates current state, session version, expiry and ownership inside a transaction.

Persist state as an enum-backed column plus a small structured context. Do not put authoritative tenant, price or catalog ownership inside arbitrary JSON.

## Catalog and selection tokens

Use existing branch menus/products/options/taxes/inventory. Add a channel visibility abstraction only if no equivalent exists, preferably a channel pivot/policy rather than `whatsapp_enabled` columns scattered across entities.

Customer selections use short opaque tokens stored or signed with:

- session UUID
- tenant and branch scope
- resource UUID/type
- option/variant context
- expiry/version

Never accept a numeric product ID directly from customer text. Resolve the token, then re-query through tenant + branch + active menu + channel visibility.

## Cart and pricing

Create a channel-neutral cart facade around the existing `Cart` behavior:

- add/update/remove item
- required option validation
- inventory/availability validation
- coupon/discount policy
- tax and fee calculation
- quote version/fingerprint
- expiry

Every read and write revalidates the session channel context. Checkout recalculates price from authoritative models and rejects stale/unavailable selections with a customer-readable recovery response.

## Checkout and order creation

Refactor reusable behavior from the existing QR/partner/WhatsApp paths into one application service, for example `CheckoutOrderCreator`, consuming a normalized server-owned DTO. It should:

1. lock session/cart;
2. validate tenant/branch/channel;
3. validate branch schedule and order type;
4. resolve/create customer under the same tenant;
5. validate all catalog choices and stock;
6. calculate price/tax/discount/fees;
7. validate payment policy;
8. create order/items/taxes/source mapping transactionally;
9. emit the normal NexDine events after commit.

Add a first-class order source such as `whatsapp`, presented consistently in POS/Kitchen/admin. Internal numeric IDs stay internal; API/resources use order reference/UUID.

## Payment lifecycle

Reuse `DirectUpiService` and configured gateway drivers. Add a WhatsApp payment coordinator that owns session transitions and notification side effects.

```text
checkout quote locked
 -> payment session created idempotently
 -> link sent through outbox
 -> signed provider webhook
 -> payment event uniqueness + amount/currency/reference verification
 -> payment finalized once
 -> order/session transition
 -> after-commit order/payment events
 -> confirmation/tracking notification
```

COD/pay-at-counter must be an explicit tenant/branch/order-type policy; it must never masquerade as paid.

## Message outbox

Do not send provider messages as the durable action itself. Create a tenant-scoped outbox record transactionally, then deliver asynchronously.

Suggested fields:

- UUID and tenant/assignment/conversation ownership
- semantic event and template/version
- destination hash plus encrypted/minimized destination
- provider message ID
- idempotency key
- status, attempts, next attempt, last error code
- correlation ID and timestamps

Provider response bodies should be minimized/redacted rather than retained indiscriminately.

## Events and integrations

Listeners translate existing core events into channel notifications:

- `OrderCreated`
- `OrderPaid`
- `OrderUpdateStatus`
- cancellation/refund events

Listeners check the order’s WhatsApp source mapping and tenant configuration, create outbox messages, and return quickly. Core Order code must not call provider clients directly.

## Admin information architecture

Use one WhatsApp Ordering workspace with eight owner-friendly sections:

1. Overview and setup readiness
2. Connection and branches
3. Ordering experience (types, approval, hours, customer fields)
4. Menu availability
5. Delivery and payments
6. Templates and automations
7. Conversations and orders
8. Health, logs and failed messages

Advanced provider/webhook details remain behind an Advanced drawer and stronger permission. SaaS control-plane configuration stays separate from restaurant operations.

## Observability

Every receipt, command, transition, checkout, payment and outbound message receives a correlation ID. Metrics and logs use tenant-safe dimensions and redacted identifiers. Health is calculated from concrete checks: credentials, webhook age, queue lag, outbox failures, catalog readiness, gateway readiness and order-pipeline success.
