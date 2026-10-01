# Delivery API and operating rules

This describes the NexDine APIs present in the local codebase. It does not authorize Flash/uEngage traffic. Flash booking, automatic retry, and webhook ingestion remain blocked until the official idempotency and callback authentication contracts are verified. Normal restaurant delivery and the separately authenticated NexDine partner API can operate when their tenant permissions and settings allow them.

## Access boundaries

| Actor | Endpoint | Required access | Result |
| --- | --- | --- | --- |
| Tenant admin | `GET /api/v1/settings/delivery` | `admin.delivery_settings.edit`, delivery entitlement | Tenant delivery settings only |
| Tenant admin | `PUT /api/v1/settings/delivery/update` | Same | Only keys listed in `DeliverySettingAccess::TENANT_KEYS`; provider credentials remain platform controlled |
| Tenant admin | `GET /api/v1/settings/delivery/wallet?page=1&limit=50` | Same | Tenant wallet balance, report, and a paginated ledger; `limit` is 1–100. Platform operator names are omitted |
| Tenant admin | `PUT /api/v1/settings/delivery/wallet` | Same | Low-balance threshold and booking block control; cannot credit funds |
| Tenant staff | `GET /api/v1/orders?filters[type]=delivery&filters[delivery_status]=delivered` | `admin.orders.index` | Delivered delivery orders in the actor's allowed tenant/branches |
| Tenant staff | `GET /api/v1/orders/{reference}/show` | `admin.orders.show` | Authorized order and delivery details |
| Signed-in customer | `POST /api/v1/customer-app/delivery/quote/{cartId}` | Customer token for the resolved tenant, cart, and branch | Server-calculated location, serviceability, fee, and payable preview |
| Signed-in customer | `GET /api/v1/customer-app/orders/tracking/{reference}` | Own order in the resolved tenant | Limited customer tracking view |
| Platform admin | `GET /api/v1/tenants/{tenantId}/delivery-wallet` | Platform actor, `admin.tenants.edit` | Tenant wallet report |
| Platform admin | `POST /api/v1/tenants/{tenantId}/delivery-wallet/adjust` | Same | Audited credit/debit with UUID idempotency key and reason |
| Platform admin | `POST /api/v1/tenants/{tenantId}/delivery-wallet/refund` | Same | Audited refund of a captured provider cost, tied to a delivery and provider reference |
| Partner driver | `GET /api/v1/partner/deliveries/{partnerOrderUuid}` | Signed partner credential and `deliveries:read` scope | Own mapped delivery only |
| Partner driver | `POST /api/v1/partner/deliveries/{partnerOrderUuid}/status` | Signed credential, `deliveries:status`, idempotency key | One validated forward transition on its own task |

The partner route identifier is the partner order mapping UUID, not the restaurant order reference. Partner requests use `X-Api-Key`, `X-Timestamp`, `X-Nonce`, and `X-Signature` as implemented by the NexDine Partner API. These headers are **not** a Flash/uEngage webhook signature contract.

## Delivery sequence

1. Verify the tenant delivery entitlement, availability schedule, branch radius, authorized customer address, cart contents, and server-calculated customer fee before checkout. Quote previews do not book a courier.
2. Checkout recalculates the total and requires an idempotency key. Customer delivery charge and provider cost are separate ledger values. A client total mismatch is rejected for review.
3. Verify payment and restaurant acceptance before a partner driver may be assigned. Paying a partner-managed delivery cannot auto-complete its order. A partner cannot mark pickup before the order is ready; a historically completed order needs manual reconciliation before new driver milestones.
4. Signed partner updates move through the internal delivery state machine. Invalid or backward transitions fail. A duplicate status is read-only, including after delivery, so it cannot rewrite rider or tracking details.
5. Only the partner delivery path may complete a partner-managed order after delivery. Tenant staff cannot advance or cancel provider-managed milestones through ordinary order controls.

For Flash/uEngage, `createTask` must not be retried automatically after a timeout or ambiguous result. Investigation requires manual reconciliation against the provider. Unsigned callbacks cannot update a delivery or order.

## Postman

- Import `postman/NexDine-Delivery.postman_collection.json` for tenant, customer, and platform API calls. Set `base_url` to a **local** API and supply a token appropriate to each folder.
- Import `docs/partner-api/NexDine-Partner-API.postman_collection.json` with its separate environment for signed partner calls.
- The signed partner collection canonicalizes query parameters before signing, matching the server's version 2 signature policy. It must use the partner credential scopes issued for the selected tenant.
- Read-only requests can be run individually. Mutations require `allow_delivery_mutations=true` in the selected local environment. Do not set this variable for a production environment or run mutation folders as a general smoke test.
- No collection contains a Flash/uEngage HTTP request or a fabricated webhook signature.

## Known limits

- Flash v1.3 does not document safe booking idempotency or callback signing; automatic booking and callback-driven completion stay blocked. See `UENGAGE_PROVIDER_QUESTIONS.md`.
- Wallet credits are accounting entries made by an authorized platform actor only after independent payment verification. Online top-up and provider settlement calculations are unsupported until their contracts exist.
- A delivered-order filter uses the internal `order_deliveries.status=delivered` milestone. An order marked completed without a delivered delivery record is intentionally excluded.
- Full database feature tests require a local database driver and migrated test database. Never run those tests against production data.
