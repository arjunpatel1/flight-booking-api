# NexDine Partner API v1 — Developer Reference

Base path: `/api/v1/partner`

## Available endpoints

| Method | Endpoint | Scope | Purpose |
|---|---|---|---|
| GET | `/health` | authenticated credential | Validate signing and credential configuration |
| GET | `/branches` | `catalog:read` | List active branches assigned to the credential |
| GET | `/branches/{branch_uuid}/menus` | `catalog:read` | List active menus belonging to an allowed branch |
| GET | `/menus/{menu_uuid}/products` | `catalog:read` | List active, server-priced menu products |

Tenant-admin management endpoints use normal NexDine login authentication:

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/v1/partner-integrations` | `admin.partner_integrations.index` |
| POST | `/api/v1/partner-integrations` | `admin.partner_integrations.create` |
| POST | `/api/v1/partner-integrations/credentials/{uuid}/rotate` | `admin.partner_integrations.edit` |
| POST | `/api/v1/partner-integrations/credentials/{uuid}/revoke` | `admin.partner_integrations.destroy` |

## Request signing

Required headers are `X-Api-Key`, `X-Timestamp`, `X-Nonce`, and `X-Signature`. The timestamp is Unix seconds. A nonce must be URL-safe, 16–128 characters, and must never be reused.

Canonical payload:

```text
METHOD
/api/v1/partner/exact/path
TIMESTAMP
NONCE
SHA256_HEX(RAW_BODY)
```

`X-Signature = HMAC_SHA256_HEX(canonical_payload, api_secret)`

The path excludes the scheme, host and query string. Hash the exact raw request body; use the SHA-256 hash of an empty string for GET requests. Do not reformat JSON after signing.

## Response envelope

Success:

```json
{
  "success": true,
  "data": [],
  "meta": {},
  "request_id": "request-correlation-id"
}
```

Failure:

```json
{
  "success": false,
  "error": {
    "code": "AUTHENTICATION_FAILED",
    "message": "Partner authentication failed.",
    "details": {}
  },
  "request_id": "request-correlation-id"
}
```

## Error codes

| HTTP | Code | Action |
|---|---|---|
| 401 | `AUTHENTICATION_FAILED` | Check API key, signature, IP restriction and credential state |
| 401 | `STALE_REQUEST` | Synchronize system time and create a new signature |
| 403 | `INSUFFICIENT_SCOPE` | Ask a tenant administrator for the required scope |
| 404 | `RESOURCE_NOT_FOUND` | UUID is unknown or outside the credential's tenant/branch scope |
| 409 | `REPLAY_DETECTED` | Generate a new nonce and sign a new request |
| 413 | `PAYLOAD_TOO_LARGE` | Reduce payload below the configured maximum |
| 429 | `RATE_LIMITED` | Back off and retry after the next rate window |

## Pagination

Product listing accepts `page` and `per_page`. `per_page` is capped at 100. Pagination details are returned in `meta`.

## Security guidance

- Begin with sandbox credentials.
- Grant only required scopes and branches.
- Configure IP/CIDR restrictions wherever the partner has fixed egress addresses.
- Store secrets only in a secrets manager. Never place them in source code, browser JavaScript or mobile applications.
- Rotate credentials periodically and revoke compromised credentials immediately.
- Log the returned `request_id` for support correlation, but never log the API secret or full signature.

## Postman

Import both files from this directory. Select the sandbox environment, set `base_url`, `api_key`, and `api_secret`, then run requests in order. The collection generates signing headers automatically and stores the first branch/menu UUID for subsequent calls.

Order-write endpoints are intentionally not listed because they are not exposed until they are connected to NexDine's canonical order pipeline and pass idempotency/concurrency testing.
