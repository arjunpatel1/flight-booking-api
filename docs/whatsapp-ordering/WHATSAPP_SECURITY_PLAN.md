# WhatsApp Ordering: Security Plan

## Security invariants

1. Tenant and authorized branches come only from the authenticated receiving-number assignment.
2. Every conversation, session, cart, customer, order, payment and message operation verifies the same tenant/channel context.
3. Customer-facing APIs and messages never expose or accept internal numeric database identifiers.
4. Prices, taxes, discounts, fees, stock and payment state are always server authoritative.
5. Provider/payment webhook effects are authenticated, replay protected, idempotent and transactionally applied once.
6. Secrets and unnecessary PII never enter API responses, logs, exception messages or analytics dimensions.

## Controls by boundary

### Webhook ingress

- Provider-specific signature verification over exact raw bytes.
- Reject missing/unknown receiving number before creating business records.
- Enforce content type, maximum body size, message-type schema and bounded text length.
- Use opaque webhook endpoint keys; do not use profile numeric IDs publicly.
- Constant-time comparisons for tokens/signatures.
- Enforce timestamp tolerance where provider supports it.
- Persist unique provider delivery/event ID and payload hash.
- A duplicate ID with a different hash is a security event, not a successful replay.
- Rate limit by endpoint key/provider plus infrastructure-level IP/volume protections.
- Acknowledge only after durable receipt/queue handoff.

### Tenant and branch isolation

- Resolve `WhatsAppChannelContext` from profile + phone + active assignment.
- Intersect every branch operation with assignment branches and tenant branches.
- Use explicit tenant predicates even when global scopes exist in queue/public contexts.
- Avoid `withoutGlobalTenant()` except inside reviewed context-resolution/repository methods.
- Validate relation consistency: profile, number, assignment, conversation, session and order must form one ownership chain.
- Add composite indexes/constraints that support and enforce these checks where practical.

### Customer/session ownership

- Normalize phone identifiers consistently without logging raw values.
- Bind conversation/session/cart to assignment + customer identifier.
- Use UUIDs/opaque tokens and expiry.
- Lock state transitions and require an expected state/version to prevent race/replay.
- Expired/cancelled sessions cannot be resumed without an explicit new session.

### Catalog and pricing

- Resolve opaque selection tokens to authorized server records.
- Reject foreign branch/menu/product/option relationships.
- Enforce positive bounded integer quantities and server-side minimum/maximum rules.
- Recalculate all totals during review and order creation.
- Reject client-supplied price, tax, total, discount, fee and payment flags.
- Recheck availability and inventory at checkout under lock/appropriate stock policy.

### Payment

- Use HTTPS allowlisted provider hosts; defend against DNS/private-address SSRF and redirects.
- Verify provider signatures and timestamps over raw body.
- Uniquely constrain provider event ID, payment ID and merchant idempotency key.
- Match tenant config, session, order, amount, currency and merchant reference.
- Only verified provider events create completed payments.
- Mismatches enter `manual_review`; they do not auto-correct or auto-pay.
- Store encrypted credentials; rotate with versioning; reveal only masked metadata.

### Admin authorization

Split permissions at minimum into:

- view overview
- manage connection/secrets
- manage ordering configuration
- manage catalog visibility
- view conversations/PII
- perform handoff/respond
- approve/reject orders
- manage payments/refunds
- manage templates
- view diagnostics/logs
- retry failed messages

All mutations require policies in addition to feature entitlements. High-risk actions require audit entries and, where appropriate, re-authentication/confirmation.

### Data protection

- Encrypt provider credentials and sensitive delivery/customer fields where operationally feasible.
- Store normalized/minimized provider payloads; raw payload storage must have a short retention window.
- Redact tokens, authorization headers, phone numbers, addresses, QR payloads and payment identifiers in logs.
- Return purpose-built API resources, never direct provider/profile/message Eloquent serialization.
- Define retention and purge schedules for webhook payloads, transcripts, failed messages and delivery addresses.
- Audit transcript/PII access and export.

## Mandatory automated security suite

Create runtime tests for:

1. Tenant A admin cannot read/mutate Tenant B assignment, conversation, session or order.
2. Branch A assignment cannot browse/order Branch B catalog.
3. Customer/session A cannot act on cart/order B.
4. Fake/malformed/missing webhook signature is rejected without durable side effects.
5. Valid webhook replay returns an idempotent acknowledgement and creates no duplicate job/message.
6. Reused event ID with changed payload is rejected and audited.
7. Duplicate payment webhook creates one payment and one transition.
8. Duplicate checkout/approval creates one order.
9. Client-manipulated price/tax/discount/fee is ignored/rejected.
10. Invalid, zero, negative and excessive quantities are rejected.
11. Foreign/unavailable product and invalid option combinations are rejected.
12. Expired session/selection/payment cannot be reused.
13. Unauthorized admin permissions return 403 without existence disclosure.
14. Secrets and internal IDs are absent from resources, logs and validation errors.
15. Provider URL SSRF attempts and redirects are rejected.

## Security release gates

- No critical/high unresolved finding.
- All isolation/idempotency tests pass against the production database engine.
- Secret/PII log scan passes.
- Migration constraints verified on a production-like copy.
- Queue retry and dead-letter recovery tested.
- Payment mismatch and replay drills pass.
- A tenant data export contains no records from another tenant.
