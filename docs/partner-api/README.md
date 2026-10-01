# NexDine Partner API v1

The Partner API exposes tenant- and branch-scoped restaurant catalog data without revealing internal database IDs.

## Authentication

Every request must include:

- `X-Api-Key`: issued API key
- `X-Timestamp`: current Unix timestamp in seconds
- `X-Nonce`: unique URL-safe value, 16–128 characters
- `X-Signature`: lowercase hex HMAC-SHA256 signature

The string to sign is exactly:

```text
UPPERCASE_HTTP_METHOD\n
/exact/request/path\n
unix_timestamp\n
nonce\n
sha256_hex_of_raw_request_body
```

Sign that UTF-8 string using the issued API secret. Requests outside the configured clock window, duplicate nonces, invalid signatures, disallowed IP addresses, revoked credentials, or exhausted limits are rejected. Never send the API secret itself.

## Catalog endpoints

- `GET /api/v1/partner/health`
- `GET /api/v1/partner/branches`
- `GET /api/v1/partner/branches/{branch_uuid}/menus`
- `GET /api/v1/partner/menus/{menu_uuid}/products?page=1&per_page=50`

Catalog routes require the `catalog:read` scope. Branch and product identifiers are stable partner-specific UUIDs. All prices are produced by NexDine; callers cannot supply or override catalog prices.

## Signing example (Node.js)

```js
import crypto from 'node:crypto'

const body = ''
const timestamp = String(Math.floor(Date.now() / 1000))
const nonce = crypto.randomBytes(24).toString('base64url')
const path = '/api/v1/partner/branches'
const bodyHash = crypto.createHash('sha256').update(body).digest('hex')
const canonical = ['GET', path, timestamp, nonce, bodyHash].join('\n')
const signature = crypto.createHmac('sha256', process.env.NEXDINE_API_SECRET)
  .update(canonical).digest('hex')
```

API secrets are displayed once when issued or rotated. During rotation, the previous credential remains usable only for the configured grace window.
