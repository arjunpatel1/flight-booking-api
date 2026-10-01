# Customer payments

Customer checkout methods are controlled in **Settings → Payment Gateways → Customer checkout**. Razorpay credentials are encrypted, may be restricted to selected branches, and are never returned to the browser. COD and pay-at-counter orders remain `unpaid`; staff must use the existing permission-protected receive-payment action before they become paid.

## Customer API

All customer endpoints require a Sanctum customer token, resolved tenant context, and throttling.

- `GET /api/v1/customer-app/payments/options?branch_id={id}` — enabled methods for the selected branch.
- `POST /api/v1/customer-app/payments/razorpay/sessions` — create/reuse a server-side Razorpay Order. Requires `Idempotency-Key` and `{ "order_reference": "ORD-..." }`.
- `POST /api/v1/customer-app/payments/razorpay/sessions/{uuid}/verify` — verify Checkout response. Requires `Idempotency-Key` and the three Razorpay response fields.
- `POST /api/v1/payment-gateways/razorpay/webhook/{webhook-key}` — public signed webhook. Configure Razorpay to send `payment.captured`; the endpoint validates `X-Razorpay-Signature` against the raw body.

Example verification body:

```json
{
  "razorpay_payment_id": "pay_...",
  "razorpay_order_id": "order_...",
  "razorpay_signature": "64-character-hex-signature"
}
```

The server compares the returned order ID with its stored payment session, validates HMAC-SHA256, fetches the payment from Razorpay, validates amount/currency/order, captures an authorized payment when required, and uses a locked transaction to create one payment and release the order to the kitchen.

## Admin API

- `GET /api/v1/payment-gateway-settings`
- `PUT /api/v1/payment-gateway-settings/razorpay`
- `POST /api/v1/payment-gateway-settings/razorpay/test`
- `PUT /api/v1/payment-gateway-settings/checkout/options`

Admin routes require `admin.settings.edit`. Staff settlement continues to require `admin.orders.receive_payment`.

The paid-bill WhatsApp switch queues the configured `billing_sent` utility template after settlement. Parameters include `greeting`, `customer_name`, `order_id`, `order_total`, `invoice_number`, `bill_total`, and `payment_link`. Configure and approve that template in the selected WhatsApp provider before enabling the switch. Duplicate paid-bill dispatch is suppressed per order.
