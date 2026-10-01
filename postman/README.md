# NexDine POS — Session Features Postman Collection

Delivery API examples and the safe test sequence are in `NexDine-Delivery.postman_collection.json` and `../docs/DELIVERY_API_OPERATIONS.md`. The signed partner driver API has its own collection under `../docs/partner-api/`. Neither collection sends a Flash/uEngage provider request. Keep mutation folders gated to a local environment.

Covers the endpoints added in this work session:

| Feature | Method | Endpoint | Permission |
|---|---|---|---|
| Split bill (by items) | POST | `/orders/{orderId}/split` | `admin.orders.split` |
| Complimentary (NC) bill | POST | `/payments/complimentary` | `admin.orders.complimentary` |
| Aggregator item availability ("86") | POST | `/aggregator-integrations/{id}/item-availability` | `admin.aggregator_integrations.sync` |
| KDS recall (un-bump) | POST | `/pos/kitchen-stations/{stationId}/items/{itemId}/recall` | `admin.pos.kitchen_stations` |
| Loyalty points adjustment | POST | `/loyalty-customers/{id}/adjust-points` | `admin.loyalty_customers.adjust` |

## Files
- `NexDine-Session-Features.postman_collection.json` — the requests (happy path + negative "BAD" cases with test assertions).
- `NexDine.postman_environment.json` — variables (base URL, credentials, resource ids).

## Run in Postman
1. Import both files.
2. Select the **NexDine — Local** environment.
3. Edit the variables (`identifier`, `password`, and the resource ids: `order_id`, `order_product_id`, `station_id`, `kitchen_item_id`, `integration_id`, `loyalty_customer_id`, `product_id`) to match real rows in your database.
4. Run **Auth › Login** first — it stores the bearer token in `{{token}}`; every other request inherits it.

## Run headless with Newman
```bash
npm i -g newman
# Start the API first (from restaurant-pos-api/): php artisan serve
newman run postman/NexDine-Session-Features.postman_collection.json \
  -e postman/NexDine.postman_environment.json
```

## Notes
- Mutating requests (`split`, `complimentary`) send a fresh `Idempotency-Key` (`{{$guid}}`) per call, as the API requires.
- The negative ("BAD") requests assert the expected `422`/`404` so a full collection run doubles as a smoke test of validation and error handling.
- Supply credentials from your own local test database. Do not commit tokens, passwords, API keys, or provider secrets to a collection or environment export.
