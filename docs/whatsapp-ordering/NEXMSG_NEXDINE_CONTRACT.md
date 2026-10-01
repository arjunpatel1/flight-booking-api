# NexMsg ↔ NexDine catalog-ordering contract

## Trust boundary

NexMsg accepts Meta callbacks only after validating `X-Hub-Signature-256` against `META_APP_SECRET`. It forwards only recognized catalog greetings and catalog orders to the fixed HTTPS endpoint:

`https://api.nexdine.myteknoland.in/v1/whatsapp/webhook/nexmsg`

The exact compact JSON body is signed with `HMAC-SHA256(NEXDINE_WEBHOOK_SECRET, rawBody)`. NexMsg sends the result as `X-Webhook-Signature: sha256=<hex>` and uses the provider message ID as `X-Request-Id`. The same secret must be stored in the encrypted credentials of the matching NexDine provider profile.

## Greeting event

```json
{"wabaId":"987670750675913","phone_number_id":"provider-phone-id","event_id":"wamid","event_type":"whatsapp.message.received","from":"919999999999","type":"text","text":"Hi","timestamp":1725883200}
```

Only `Hi`, `Hello`, `Hey`, `Start`, and `Menu` are forwarded. NexDine resolves the profile, tenant and branch from the signed WABA mapping; payload tenant or branch fields are never accepted. NexDine then calls authenticated `POST /api/catalog/send` on NexMsg. Recipient numbers contain digits only.

## Order event

```json
{"wabaId":"987670750675913","phone_number_id":"provider-phone-id","event_id":"wamid","event_type":"whatsapp.order.received","from":"919999999999","type":"order","timestamp":1725883200,"order":{"id":"provider-order-id","catalog_id":"catalog-id","items":[{"product_retailer_id":"product-uuid","quantity":2}]}}
```

NexMsg deliberately removes provider price/currency and raw payload data. NexDine resolves every retailer ID through `whatsapp_catalog_products` in the trusted tenant, branch, profile and catalog scope, rechecks product/menu availability, and calculates current prices from NexDine.

Duplicate provider events and provider order IDs are no-ops. Conflicting payloads return `409 WHATSAPP_REPLAY_CONFLICT`.

## Provisioning

The provider profile encrypted credentials must contain `auth_key`, `webhook_secret`, `account_id`, and `catalog_id`. An authorized tenant admin provisions stable mappings using `POST /v1/whatsapp-ordering/catalog/mappings/sync` with `branch_uuid`, the configured `catalog_id`, and optional `product_uuids`. Retailer IDs are stable product UUIDs.

## NexDine → NexMsg catalog synchronization

The mapping endpoint queues one `SyncWhatsAppCatalogProduct` job per product. Product CRUD remains available while provider calls are slow or unavailable. Each job reloads and revalidates the tenant, active assignment, allowed branch, encrypted profile credentials, configured catalog, product availability, current price, and public HTTPS image before sending anything.

NexDine calls authenticated `POST /api/catalog/sync` on NexMsg using the profile's account-bound AuthKey. The provider-neutral body contains the NexMsg account identifier, configured catalog external identifier, desired active/disabled state, stable retailer ID, current NexDine name, description, price, currency, image URL, and availability. It never contains a tenant ID or branch ID because NexMsg authorization is derived from the authenticated owner and stored account/catalog relationship.

NexMsg stores a unique mapping for `(account, catalog, retailer ID)`. An unchanged payload returns the prior result. Before a create retry, NexMsg reconciles by retailer ID to avoid duplicating a Meta product after an ambiguous timeout. Existing provider IDs are updated; disabled products are marked out of stock. Catalog synchronization does not call the customer-message charging service.

NexDine persists `provider_product_id`, `payload_hash`, `sync_attempts`, `sync_status`, `last_synced_at`, and a sanitized `last_sync_error`. Repeated product updates are protected by a unique queued job and an overlap lock. The admin Catalog Sync tab shows aggregate status and can queue a rate-limited branch sync.

## Safe errors

External responses never contain provider credentials, raw Meta responses, stack traces, tenant IDs, or full stored phone numbers. Real Meta certification requires a controlled provider callback and is not implied by internal tests.
