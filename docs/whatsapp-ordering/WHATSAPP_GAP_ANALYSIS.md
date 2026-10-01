# WhatsApp Ordering: Gap Analysis

## Readiness classification

| Area | State | Key gap |
|---|---|---|
| Trusted tenant resolution | Partial/strong foundation | Needs runtime cross-tenant feature tests and provider-specific adapters |
| Webhook authentication | Partial | Meta-shaped signature path exists; provider contracts, replay timing and payload-size controls need formalization |
| Idempotency | Partial | Receipt uniqueness exists; outbound, order command and payment initiation need consistent idempotency records |
| Conversation engine | Incomplete | Stateless command switch, not an explicit persisted state machine |
| Menu/catalog | Incomplete | Flat first 15 products, numeric database IDs, no categories/channel visibility |
| Variants/modifiers/add-ons | Not implemented | Products with options are rejected |
| Cart | Partial | Existing server cart reused, but remove/update/customization/expiry/customer ownership flows are incomplete |
| Checkout | Partial | Pickup/delivery only; customer details, dine-in, serviceability and guided address capture incomplete |
| Payments | Partial | Verified Direct UPI core exists; customer delivery/status loop and other configured gateways are absent |
| Order creation | Partial | Direct model creation duplicates part of `CreateOrderService` behavior and returns/exposes numeric IDs in admin payloads |
| KOT/kitchen | Partial | `OrderCreated` is reused, but explicit source/channel metadata and full regression proof are missing |
| Status notifications | Incomplete | No complete event-driven WhatsApp lifecycle mapping/tracking flow |
| Admin UX | Incomplete | Four dense tabs; no catalog, business hours, delivery, templates, logs, failed messages or diagnostics workspace |
| Observability | Incomplete | Raw records exist but lifecycle statuses, correlation IDs, redaction, retries and actionable health are weak |
| Automated tests | Incomplete | Source assertions exist; mandatory runtime security/integration scenarios are largely absent |

## Critical functional gaps

1. `WhatsAppOrderingEngine::handle()` is a command `match`, not a transition-validated state machine. Any recognized command can run without a state-specific transition policy.
2. The menu exposes internal numeric product IDs. Customer-facing interactions should use signed/opaque selection tokens scoped to assignment, branch, session and expiry.
3. Catalog browsing is a flat 15-product text response. Categories, pagination, interactive lists, images, descriptions and search are absent.
4. Products with options are explicitly rejected. Variants, modifier groups, add-ons and required-choice validation are absent.
5. There is no channel-visibility policy. The current query only checks general active/available/menu status.
6. Customer identity/name and required details are not guided or connected cleanly to the NexDine customer model.
7. Delivery address is principally entered by staff through admin, not captured safely in the customer conversation. Service area/distance/fee validation is absent.
8. Dine-in/table selection is not supported.
9. Cart item removal, quantity update, discount/coupon and explicit checkout quote/version are missing.
10. Session expiry is stored but not consistently enforced before every command or cleaned by a scheduled job.
11. Automatic checkout creates a confirmed unpaid order before payment. The desired policy must distinguish COD/pay-at-counter from prepaid flows.
12. Payment links are created by an authenticated admin action rather than automatically delivered and tracked through the conversation.
13. Payment/session state is not synchronized back to `WhatsAppOrderSession` after verified payment.
14. No complete event-listener mapping sends accepted/preparing/ready/delivered/cancelled/refunded/tracking updates.
15. Order source is encoded in notes (`WHATSAPP ORDER`) rather than a first-class source/channel field.

## Critical reliability gaps

1. The webhook event is marked `processed` before the queued message is actually processed. Receipt, queued, processing, processed and failed must be distinct.
2. The inbound job catches most exceptions and converts them to a customer reply, which hides operational failure classification.
3. Outbound messages have no dedicated idempotency key; a job retry can create/send another response.
4. Sender calls lack retry/backoff, circuit breaking and a provider-neutral normalized error contract.
5. A send failure leaves the outbound record at `sending`; there is no `failed_at`, next retry, terminal failure, or dead-letter workflow.
6. Delivery/read status webhooks are not handled for ordering messages.
7. Monthly message/order limits are stored but not enforced.
8. One open conversation is implemented through a nullable `closed_at` lookup without a database constraint preventing concurrent duplicates.
9. One browsing session per conversation is not protected by a unique database invariant.

## Security and privacy gaps

1. Runtime tenant/branch/ownership tests are insufficient; source-text tests cannot prove query isolation.
2. Several admin responses serialize Eloquent models directly and expose internal numeric IDs and raw operational structure.
3. Conversation/message endpoints require only the broad index permission; sensitive transcript viewing and staff handoff should have separate permissions.
4. Branch filters in list endpoints are not always validated as actor-authorized tenant branches before use.
5. Raw webhook/message payloads can contain customer PII and are stored without a documented minimization, encryption or retention policy.
6. Customer phone numbers and delivery addresses are returned directly to admin tables without field-level visibility/audit controls.
7. Provider URL selection for MSG91 can originate inside encrypted credentials. It needs the same outbound-host allowlist/SSRF policy as payment URLs.
8. Webhook profile route keys are numeric. Signature verification prevents authorization bypass, but opaque public webhook keys reduce enumeration and operational coupling.
9. No explicit maximum request body, message length or structured interactive payload validation was found.
10. Error responses and stored `last_error` require systematic secret/PII redaction.

## Architecture duplication risk

`WhatsAppOrderingEngine::approve()` directly creates `Order` and applies products/taxes. Although it uses existing order methods and events, this risks divergence from QR, POS and partner ordering rules. The approved refactor direction is a channel-neutral checkout/order application service used by all applicable channels, with WhatsApp supplying a trusted channel context and normalized DTO.

## UX gaps

- Technical credentials and order operations are mixed into one screen.
- Branches are represented as IDs and are not loaded as guided multi-select choices.
- There is no preview/test-mode journey.
- Health only reflects a coarse connection status.
- No template preview, variable contract, language selection or approval-sync view.
- No failed-message recovery UI.
- No customer-facing interactive buttons/lists or graceful invalid-choice recovery.

## Production blockers

The following block production enablement for customer ordering:

1. Runtime tenant-isolation/security test suite.
2. Persisted state machine with replay-safe transitions.
3. Opaque session-scoped catalog selection identifiers.
4. Required variant/modifier support.
5. Idempotent outbound delivery and failure recovery.
6. A single reusable server-side checkout/order creation boundary.
7. Verified-payment-to-session/order/customer notification synchronization.
8. PII retention/redaction policy and enforcement.
9. Complete admin configuration/health controls.
10. End-to-end sandbox certification from webhook through POS/KOT/tracking.
