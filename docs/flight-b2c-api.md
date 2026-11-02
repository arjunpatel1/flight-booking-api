# Flight Booking B2C API — Integration Reference

This document describes the customer-facing flight API routes currently registered by this application, plus the agency admin routes used to create bookable flights and manage bookings (see [Admin APIs](#admin-apis)). It is intended for web and mobile app integrations.

## Base URL and request conventions

Live API base URL:

```text
https://api.crttravels.com/api/v1/flight
```

The admin dashboard is available at `https://crttravels.com/admin/flight/dashboard`; B2C API requests use the `api.crttravels.com` API subdomain.

- Send JSON requests with `Content-Type: application/json` and `Accept: application/json`.
- Search, reference-data, and booking-creation routes can be used without a customer login.
- Customer account routes require `Authorization: Bearer <customer_token>`.
- Guest booking-specific routes require `X-Booking-Token: <access_token>` returned when the booking is created. A signed-in owner may use their customer bearer token instead.
- A deployment may resolve a tenant from its domain. Only send `X-Tenant-ID` if the API operator has provided the correct tenant setup.
- There is no partner API key or HMAC scheme on these B2C routes.

Most JSON endpoints use this envelope:

```json
{
  "body": {},
  "message": "..."
}
```

Errors generally return `message` and `errors`; validation failures commonly use HTTP `422`. Some ticket endpoints return a PDF or HTML document instead of JSON. Webhook responses also have their own format.

## Customer authentication

| Method | Path | Auth | Purpose / main inputs |
|---|---|---|---|
| POST | `/customer-auth/register` | Public | Create a customer account. Required: `name`, `email`, `phone`, `password`; optional `remember`. |
| POST | `/customer-auth/login` | Public | Sign in with `login` (email or phone) and `password`; optional `remember`. |
| POST | `/customer-auth/otp/request` | Public | Request an OTP using `phone` and UUID `device_id`. The response is intentionally generic. |
| POST | `/customer-auth/otp/verify` | Public | Verify `challenge_id`, six-digit `otp`, and `device_id`; optional `remember`. |
| POST | `/customer-auth/logout` | Customer bearer token | Revoke the current customer token. |
| GET | `/customer-auth/bookings` | Customer bearer token | List bookings owned by the signed-in customer. |
| GET | `/customer-auth/bookings/{identifier}/ticket` | Customer bearer token | Download the owner's e-ticket PDF. |
| GET | `/customer-auth/bookings/{identifier}/ticket/preview` | Customer bearer token | Preview the owner's e-ticket as HTML. |
| GET | `/customer-auth/bookings/{identifier}/cancellation-quote` | Customer bearer token | Get the current online cancellation eligibility and refund quote. |
| POST | `/customer-auth/bookings/{identifier}/cancel` | Customer bearer token | Cancel an owned booking. Required: `expected_refund_amount` from the latest cancellation quote; optional `reason`. |

`identifier` may be a booking reference or numeric booking ID. Authentication success returns `body.token`; send it as a Bearer token on customer routes. Customer tickets are available only after payment and ticketing conditions are met.

## Reference data and public settings

| Method | Path | Purpose |
|---|---|---|
| GET | `/meta` | Flight API metadata. |
| GET | `/cabin-classes` | Active cabin classes. |
| GET | `/airports` | Active airport directory. |
| GET | `/airports/{identifier}` | Airport by identifier. |
| GET | `/airlines` | Active airline directory. |
| GET | `/airlines/{identifier}` | Airline by identifier. |
| GET | `/aircraft` | Active aircraft directory. |
| GET | `/aircraft/{identifier}` | Aircraft by identifier. |
| GET | `/fare-families` | Active fare families. |
| GET | `/fare-families/{identifier}` | Fare family by identifier. |
| GET | `/booking-options/resolve` | Resolve booking-option/fare information. Requires query `flight_number`; accepts optional `origin_code` and `destination_code`. |
| GET | `/configuration/{key}` | Read an allow-listed public flight configuration value. Unknown keys return `404`. |
| GET | `/public/refund-policy` | Public cancellation/refund policy. |

## Flight search

| Method | Path | Purpose |
|---|---|---|
| GET or POST | `/search` | Search one-way or round-trip flights. GET uses query parameters; POST uses a JSON body. |
| POST | `/search/multi-city` | Search a multi-city itinerary. |
| GET | `/search/filters` | Return available search facets, popular airports, airlines, cabin classes, and time windows. |
| GET | `/search/workspace` | Return search workspace/default-filter information. |

`/search` accepts:

| Field | Requirement / allowed values |
|---|---|
| `origin`, `destination` | Required three-character airport codes; must differ. |
| `departure_date` | Required `YYYY-MM-DD`. |
| `return_date` | Optional `YYYY-MM-DD`, not earlier than departure. |
| `trip_type` | `one_way`, `round_trip`, or `multi_city`. |
| `adults`, `children`, `infants` | Optional integers; adults 1–9, children 0–9, infants 0–9. |
| `cabin_class` | Optional active cabin-class code, e.g. `ECONOMY`. |
| `airlines` | Optional array of two-character airline codes. |
| `max_stops`, `min_price`, `max_price` | Optional filters. |
| `departure_time_window` | `morning`, `afternoon`, `evening`, or `night`. |
| `refundable_only`, `baggage_included` | Optional boolean filters. |
| `supplier`, `sort_by`, `currency` | Optional supplier filter, sort (`recommended`, `price_asc`, `price_desc`, `duration_asc`, `departure_asc`, `departure_desc`), and three-character currency. |

For multi-city search, send `legs` as an array of 2–6 objects, each with `origin`, `destination`, and `departure_date`. Passenger counts, `cabin_class`, `currency`, and `sort_by` are optional at the top level.

Example request:

```http
POST /api/v1/flight/search HTTP/1.1
Content-Type: application/json
Accept: application/json

{
  "origin": "DEL",
  "destination": "BOM",
  "departure_date": "2027-01-15",
  "trip_type": "one_way",
  "adults": 1,
  "cabin_class": "ECONOMY",
  "currency": "INR"
}
```

Successful search results are under `body`; itineraries include flight and fare information. Round-trip results may also include `return_itineraries`.

## Price check, hold, and quote

| Method | Path | Purpose / main inputs |
|---|---|---|
| POST | `/price-check` | Revalidate a fare. Send `fare_id` (or `flight_fare_id`); optional `expected_price`, `quantity` (1–9), `departure_date`, and `cabin_class`. |
| POST | `/inventory/check` | Check available inventory. Send `inventory_id` (or `flight_inventory_id`); optional `quantity`, `cabin_class`, `departure_date`. |
| POST | `/holds` | Temporarily reserve seats. Send `inventory_id` (or `flight_inventory_id`); optional `fare_id`/`flight_fare_id`, `cabin_class`, `quantity`, `departure_date`, and `idempotency_key`. |
| GET | `/holds/{identifier}` | Retrieve a hold and its expiry/remaining time. |
| POST | `/holds/{identifier}/release` | Release a hold. Optional JSON `reason`. |
| POST | `/quotes` | Create an immutable fare quote. Send `fare_id` (or `flight_fare_id`); optional `hold_id`, `quantity`, `expected_price`, `departure_date`, and `idempotency_key`. |
| GET | `/quotes/{identifier}` | Retrieve a quote, validity, price, and expiry. |

Example hold and quote requests:

```http
POST /api/v1/flight/holds HTTP/1.1
Content-Type: application/json

{
  "inventory_id": 123,
  "fare_id": 456,
  "cabin_class": "ECONOMY",
  "quantity": 1
}

POST /api/v1/flight/quotes HTTP/1.1
Content-Type: application/json

{
  "fare_id": 456,
  "hold_id": "hold-reference-from-previous-response",
  "quantity": 1
}
```

Use the returned `hold_id` and `quote_id` in later steps. Holds and quotes expire; check their returned `expires_at` and remaining-time fields.

## Offers and discounts

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/offers?quote_id={quote_id}` | List offers available for a quote. `quote_id` is required. |
| POST | `/offers/validate` | Validate a code for a quote. Required: `quote_id`, `code`; optional `discount_id`. |

## Booking, payment, and tickets

| Method | Path | Auth | Purpose / main inputs |
|---|---|---|---|
| POST | `/bookings` | Public; customer bearer optional | Create a booking from a quote. Requires `passengers` (at least one) and `quote_id` or `quote_reference`. |
| GET | `/bookings/{identifier}` | Booking access | Get booking details. |
| GET | `/bookings/{identifier}/status` | Booking access | Get booking/payment status. |
| POST | `/bookings/{identifier}/ancillaries` | Booking access | Add an ancillary. Requires `passenger_id`, `category`, and `code`; optional name, quantity, seat, and ancillary metadata. |
| GET | `/bookings/{identifier}/payment-options` | Booking access | Get available payment options and amount breakdown. |
| POST | `/bookings/{identifier}/payment` | Booking access | Start gateway checkout. Optional JSON fields include `gateway` (`razorpay`; `mock` is local/test only), `payment_method`, and `idempotency_key`. |
| POST | `/payments/{reference}/verify` | Booking access | Verify the gateway checkout result. Send gateway fields such as `razorpay_payment_id`, `razorpay_order_id`, and `razorpay_signature`. |
| GET | `/payments/{reference}` | Booking access | Get payment status/details; useful for polling after checkout. |
| GET | `/bookings/{identifier}/notifications` | Booking access | List notifications for the booking. |
| POST | `/bookings/{identifier}/issue-ticket` | Booking access | Request ticket issuance for an eligible booking. |
| GET | `/bookings/{identifier}/ticket` | Booking access | Get ticket/PNR details as JSON. |
| GET | `/bookings/{identifier}/eticket/download` | Booking access | Download the e-ticket PDF. |
| GET | `/bookings/{identifier}/eticket/preview` | Booking access | Preview the e-ticket as HTML. |

The booking request needs passenger names (`first_name`, `last_name`); passenger type, title, DOB, gender, nationality, passport, contact, and preference fields are optional as applicable. The API calculates the authoritative booking/payment amount; do not use client-provided totals as the source of truth.

`POST /bookings` returns `body.booking_reference` and `body.access_token`. Store the access token securely: it is the guest's credential for the booking-protected routes and is returned at booking creation. Send it in the `X-Booking-Token` header.

Typical guest flow:

1. `POST /search`
2. `POST /price-check`
3. `POST /holds`
4. `POST /quotes`
5. `POST /bookings` with the quote and passenger details
6. Save `booking_reference` and `access_token`
7. `GET /bookings/{booking_reference}/payment-options`
8. `POST /bookings/{booking_reference}/payment`; complete checkout using the returned gateway session
9. `POST /payments/{payment_reference}/verify`, then optionally poll `GET /payments/{payment_reference}`
10. Fetch ticket details or download the e-ticket when ticketing is complete

## Webhooks (server-to-server)

| Method | Path | Purpose |
|---|---|---|
| POST | `/payments/webhook/{gateway}` | Payment-gateway webhook. Razorpay signatures are checked using `X-Razorpay-Signature` (or `Signature`). |
| POST | `/webhooks/{provider}` | Flight supplier/provider webhook under the flight prefix. |
| POST | `/api/v1/webhooks/{provider}` | Alternate root-level provider webhook route registered by the flight module. |

Configure webhook URLs with the API operator. These are not browser checkout endpoints.

## External provider APIs used by the server

- **Flight supply:** the application has a supplier-adapter interface and a bundled `MockSupplierAdapter` for simulated/test search, price, booking, ticketing, cancellation, refund, seat-map, and ancillary operations. No specific live airline GDS/NDC provider endpoint is hard-coded in this repository. A deployment can configure its own supplier adapter/credentials; those supplier credentials and upstream endpoints are not part of this public B2C API.
- **Payments:** the server-side gateway adapter supports Razorpay at `https://api.razorpay.com/v1` and a mock driver for local/testing. Its Razorpay calls include creating orders (`POST /orders`), fetching/capturing payments (`GET /payments/{id}`, `POST /payments/{id}/capture`), creating refunds (`POST /payments/{id}/refund`), and checking refunds (`GET /payments/{id}/refunds/{refund_id}`). These are server-to-server calls; consumers of the B2C API should use the payment session returned by this API and must never receive the Razorpay secret.

## Admin APIs

The agency admin uses these routes to create the flights and fares that customers search and book, and to manage the resulting bookings. Every admin route needs `Authorization: Bearer <admin_token>`. The agency (tenant) is taken from the token, so records are created for that agency only.

### Admin login

These routes are under `https://api.crttravels.com/api/v1/auth`, not `/flight`. Staff sign in on the website at `https://crttravels.com/auth/login`.

| Method | Path | Auth | Purpose / main inputs |
|---|---|---|---|
| POST | `/auth/login` | Public | Sign in. Required: `identifier` (email or username), `password` (max 20); optional `remember`. Returns `body.token`, `body.expires_at`, and `body.user` (including `tenant_id`, `role`, `effective_permissions`). |
| POST | `/auth/mfa/verify` | Public | When login returns `mfa_required: true` and a `challenge_token`, send `challenge_token` and `code` to get the token. |
| POST | `/auth/check` | Admin bearer token | Confirm the token is valid and return the user. |
| POST | `/auth/refresh` | Admin bearer token | Issue a new token; the old one is revoked. |
| POST | `/auth/logout` | Admin bearer token | Revoke the token. |

Login and MFA verify allow 3 attempts per minute per identifier and IP address, then return `429` with `Retry-After`.

When `/search` or `/search/multi-city` is called with an admin token, results are limited to that agency's own flights and fares. Without a token, search is public and covers every agency.

### How a flight becomes bookable

1. **Master data (once):** airlines, airports, aircraft, cabin classes.
2. **Fare families:** fare products per cabin, e.g. `SAVER` and `FLEXI` in `ECONOMY`.
3. **Route:** the flight itself — number, date, times, seats, prices, fare families on sale.
4. **Inventory:** seats per cabin for that flight. Search reads this.
5. **Fares:** one priced fare per inventory cabin and fare family. Customers book a fare.

The admin Routes screen does steps 3–5 in one save: `POST /routes`, then `POST /inventories`, then `POST /fares` (or `PUT /fares/{id}`) for each fare family.

### Master data

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/airlines` | List airlines (`search`, `active_only`, `per_page`). |
| POST | `/airlines` | Add an airline. Required: `iata_code` (2 chars, unique), `name`; optional `icao_code` (3 chars), `country`, `country_code`, `logo_url`, `alliance` (`star_alliance`, `skyteam`, `oneworld`, `none`), `is_active`. |
| GET / PUT / DELETE | `/airlines/{identifier}` | Show, edit, or delete an airline. |
| GET | `/airports` | List airports (`search`, `active_only`, `per_page`). |
| POST | `/airports` | Add an airport. Required: `iata_code` (3 chars, unique), `name`, `city`, `city_code` (3 chars), `country`, `country_code` (2 chars); optional `icao_code`, `latitude`, `longitude`, `timezone`, `terminals`, `is_active`. |
| GET / PUT / DELETE | `/airports/{identifier}` | Show, edit, or delete an airport. |
| GET | `/aircraft` | List aircraft. |
| POST | `/aircraft` | Add an aircraft. Required: `iata_code` (3 chars, unique), `model`, `manufacturer`, `total_seats` (≥ 1); optional `aircraft_type`, `registration`, `seat_configuration`, `is_active`. |
| GET | `/cabin-classes` | List cabin classes. |
| POST | `/cabin-classes` | Add a cabin class. Required: `code` (letters, digits, `_`; unique, e.g. `ECONOMY`), `name`; optional `sort_order`. |
| PUT / DELETE | `/cabin-classes/{id}` | Edit or delete a cabin class. |

### Fare families

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/fare-families` | Active fare families. |
| GET | `/fare-families/manage/list` | All fare families, for the admin screen. |
| POST | `/fare-families` | Add a fare family. Required: `code`, `name`, `cabin_class` (an active cabin class); optional `description`, `features`, `baggage_allowance` (e.g. `{"cabin_kg":7,"checkin_kg":20}`), `changeability` and `refundability` (e.g. `{"allowed":true}`), `is_active`. |
| PUT / DELETE | `/fare-families/{identifier}` | Edit or delete a fare family. |

### Routes (create a flight)

| Method | Path | Purpose |
|---|---|---|
| GET | `/routes` | List flights (`per_page`, `sort_by`, `sort_direction`). |
| POST | `/routes` | Create a flight. |
| PUT / DELETE | `/routes/{id}` | Edit or delete a flight. |

`POST /routes` accepts:

| Field | Requirement / allowed values |
|---|---|
| `origin_airport_id`, `destination_airport_id` | Required airport IDs; must differ. |
| `flight_number` | e.g. `6E-2031`; the part before the dash must match an active airline code. |
| `flight_date`, `end_date` | Departure and arrival dates, `YYYY-MM-DD`. |
| `flight_time`, `end_time` | Departure and arrival times, `HH:mm`. |
| `departure_terminal`, `arrival_terminal`, `stops`, `stops_type` | Optional details. |
| `pnr_number`, `purchase_date` | The agency's block PNR and purchase date, if known. |
| `total_seats`, `total_adult_seats`, `total_child_seats` | Seat counts. |
| `adult_price`, `child_price`, `infant_price`, `gst_percentage` | Prices per passenger type. |
| `fare_families` | Fare family codes on sale, e.g. `["SAVER","FLEXI"]`. |
| `fare_family_prices`, `fare_family_pax_prices` | Price per fare family, e.g. `{"SAVER":{"adult":4500,"child":4000}}`. |
| `distance_km`, `flight_duration_minutes`, `is_active` | Optional. |

```http
POST /api/v1/flight/routes HTTP/1.1
Authorization: Bearer <admin_token>
Content-Type: application/json

{
  "origin_airport_id": 1,
  "destination_airport_id": 2,
  "flight_number": "6E-2031",
  "flight_date": "2026-12-15",
  "flight_time": "09:00",
  "end_date": "2026-12-15",
  "end_time": "11:30",
  "total_seats": 30,
  "adult_price": 4500,
  "gst_percentage": 5,
  "fare_families": ["SAVER"],
  "fare_family_pax_prices": { "SAVER": { "adult": 4500 } },
  "is_active": true
}
```

### Schedules (repeating flights)

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/schedules` | List schedules. |
| POST | `/schedules` | Add a weekly schedule. Required: `route_id`, `airline_id`, `flight_number`, `departure_time`, `arrival_time`, `days_of_week` (1–7, Monday = 1), `valid_from`, `valid_until`; optional `day_offset` (0–2), `is_active`. |
| PUT / DELETE | `/schedules/{id}` | Edit or delete a schedule. |

### Inventory (seats)

| Method | Path | Purpose |
|---|---|---|
| GET | `/inventories` | List inventory (`search`, `airline_id`, `origin_airport_id`, `destination_airport_id`, `cabin_class`, `status`, `available_only`, `per_page`). |
| GET | `/inventories/availability` | Seats left. |
| POST | `/inventories` | Add seats for a flight, one row per cabin. |
| GET / PUT / DELETE | `/inventories/{identifier}` | Show, edit, or delete an inventory row. |

`POST /inventories` accepts:

| Field | Requirement / allowed values |
|---|---|
| `airline_id`, `flight_number` | Required. |
| `origin_airport_id`, `destination_airport_id` | Required; must differ. |
| `departure_time`, `arrival_time` | Required. |
| `cabins` | Required array; each item has `cabin_class`, `total_seats`, `base_price` (required), `available_seats`, `baggage_policy` (defaults to 7 kg cabin, 15 kg check-in). |
| `aircraft_id`, `operating_days` | Optional; operating days default to every day. |
| `is_refundable`, `cancellation_policy`, `reschedule_policy` | Optional. |
| `status` | `active`, `inactive`, `suspended`, or `cancelled`. |

### Fares (prices customers book)

| Method | Path | Purpose |
|---|---|---|
| GET | `/fares` | List fares (`flight_inventory_id`, `fare_family_code`, `cabin_class`, `status`, `per_page`). |
| POST | `/fares` | Add a fare. |
| PUT | `/fares/{id}` | Edit a fare. |

`POST /fares` accepts:

| Field | Requirement / allowed values |
|---|---|
| `flight_inventory_id` | Required inventory row. |
| `fare_family_code`, `cabin_class` | Required. |
| `supplier_fare` | Required base fare. |
| `total_seats`, `available_seats` | Required; available ≤ total. |
| `taxes`, `supplier_fees`, `convenience_fee`, `gst`, `gst_rate`, `discount` | Optional amounts. |
| `platform_markup`, `tenant_markup`, `client_markup` | Optional; each with a matching `_type` of `fixed` or `percentage`. |
| `currency` | e.g. `INR`. |
| `is_refundable`, `is_changeable`, `baggage` | Optional. |
| `valid_from`, `valid_until` | Optional dates. |
| `status` | `active`, `expired`, `sold_out`, or `suspended`. |
| `rules` | Optional: `cancellation_policy`, `refund_policy`, `reschedule_policy`, `baggage_policy`, `advance_purchase_days`, `min_stay_days`, `max_stay_days`. |

### Managing bookings

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/bookings` | List bookings (`status`, `payment_status`, `search`, `per_page`). |
| GET | `/bookings/{identifier}` | One booking with passengers and payments. |
| PUT | `/bookings/{identifier}/passengers/{passengerId}` | Correct passenger details: any of `title`, `first_name`, `last_name`, `date_of_birth`, `gender`, `nationality`, `passport_number`, `passport_expiry`, `passport_country`, `seat_preference`, `meal_preference`, `frequent_flyer_number`, `contact_email`, `contact_phone`. |
| POST | `/bookings/{identifier}/confirm` | Confirm a booking paid offline. Optional `payment_reference`, `payment_method`. |
| PUT | `/bookings/{identifier}/pnr` | Save the airline PNR and mark the booking booked. Required `pnr` (letters, digits, `-`; max 50). |
| POST | `/bookings/{identifier}/issue-ticket` | Issue the e-ticket. |
| POST | `/bookings/{identifier}/sold-out` | Mark the booking sold out when no seat could be bought. |
| POST | `/bookings/{identifier}/cancel` | Cancel a booking. Optional `reason` (max 500). |
| GET | `/routes/{id}/pnr-pending-bookings` | Paid bookings on a flight still waiting for a PNR. |
| POST | `/routes/{id}/allocate-pnr` | Give one PNR to several bookings at once. `pnr`, `booking_references` (up to 500), required boolean `sold_out_remaining` (mark the rest sold out). |

### Cancellations, refunds, and reschedules

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET | `/bookings/{identifier}/servicing` | Cancellation, refund, and reschedule history. |
| POST | `/bookings/{identifier}/cancel-servicing` | Cancel and calculate the refund under the refund policy. Optional `reason`. |
| GET | `/bookings/{identifier}/reschedule/search` | Find replacement flights. |
| POST | `/bookings/{identifier}/reschedule` | Move to another flight. Required `new_inventory_id`; optional `new_fare_id`, `reason`. |
| POST | `/bookings/{identifier}/refunds/{refundId}/mark-processed` | Record a refund paid outside the gateway. Required `reference`. |
| POST | `/bookings/{identifier}/refunds/{refundId}/retry` | Retry a failed gateway refund. |

### Notifications

| Method | Path | Purpose |
|---|---|---|
| GET | `/notifications` | Email and WhatsApp messages sent to customers. |
| GET | `/notifications/{identifier}` | One message. |
| POST | `/notifications/{identifier}/retry` | Resend a failed message. |

### Coupons and discounts

Customers enter a coupon by its code at checkout; discounts are shown in a list for the customer to pick. Both have the same routes: replace `coupons` with `discounts`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/coupons` | List coupons. |
| GET | `/coupons/form/meta` | Options for the create form. |
| GET | `/coupons/{id}` | One coupon. |
| POST | `/coupons` | Add a coupon. |
| PUT | `/coupons/{id}` | Edit a coupon. |
| DELETE | `/coupons/{ids}` | Delete one or more (comma-separated IDs). |

| Field | Requirement / allowed values |
|---|---|
| `name` | Required, by language, e.g. `{"en":"Diwali sale"}`. |
| `code` | Required for coupons, optional for discounts; unique, max 200. |
| `type`, `value` | Required; `type` is `percent` or `fixed`. |
| `is_active` | Required boolean. |
| `minimum_spend`, `maximum_spend`, `max_discount` | Optional limits. |
| `usage_limit`, `per_customer_limit` | Optional counts. |
| `start_date`, `end_date` | Optional `YYYY-MM-DD`. |
| `conditions` | Optional: `airlines`, `flight_numbers`, `routes` (e.g. `HYD-DEL`), `fare_families`. |

### Settings

| Method | Path | Purpose / main inputs |
|---|---|---|
| GET / PUT | `/settings/booking-window` | Required `minimum_advance_booking_days` (0–365); optional `pnr_confirmation_timeout_minutes`, `booking_alert_emails` (up to 10), `booking_alert_whatsapp_numbers` (up to 5). |
| GET / PUT | `/settings/refund-policy` | `enabled`, `slabs` (1–10; each `min_hours_before_departure`, `fee_type` `fixed`/`percent`, `fee_value`, `label`), `no_show_cutoff_hours`, `cap_fee_at_base_fare`, `refund_taxes_on_no_show`, `convenience_fee_refundable`, `service_fee_per_passenger`, `look_in.enabled`, `look_in.hours_after_booking`, `look_in.min_days_before_departure`, `refund_processing_days`, optional `reschedule_fee`, `policy_notes`. |
| GET / PUT | `/settings/ticket-advisory` | Required `ticket_advisory` (max 12,000 characters), printed on the e-ticket. |
| PUT | `/configuration/{key}` | Update a stored flight configuration value. |

## Scope

This reference contains the customer-facing B2C routes and the agency admin routes used to set up flights and manage B2C bookings. B2B agent, distributor, wallet, settlement, and inventory-allocation routes are not included.
