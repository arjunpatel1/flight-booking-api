# WhatsApp Conversation State Machine Design

Status: Phase 2 architecture only; not implemented.

## Ownership and persistence

One active conversation is bound to one trusted `WhatsAppChannelContext`, customer phone, assignment and permitted branch. Business state belongs in a persisted order session, not in provider payloads or process memory. Proposed persisted fields are a state key, state version, selected category/product public references, customization draft, last interaction time and optimistic transition version. Schema changes require a separate reviewed migration after Phase 1 certification.

Every inbound event follows:

`verify provider → normalize → resolve trusted context → deduplicate event → lock conversation/session → validate transition → perform one idempotent action → persist next state → enqueue provider-neutral reply`

Customer payloads may select only opaque references already offered in the same trusted context. They cannot supply tenant, branch, account, price, tax or total.

## States and transitions

| State | Accepted input | Action and validation | Normal next state | Timeout/failure behavior |
|---|---|---|---|---|
| `START` | text/start event | Establish active trusted session; do not infer tenant from content | `WELCOME` | Invalid context fails closed; no session created |
| `WELCOME` | provider-neutral buttons or text aliases | Present supported entry actions | `MAIN_MENU` or support/track intent | Retry same event returns prior result |
| `MAIN_MENU` | menu/offers/help/track/support selection | Validate action token was issued for this session | `CATEGORY_LIST` or auxiliary intent | Unknown input returns bounded help, state unchanged |
| `CATEGORY_LIST` | category opaque ID, next/previous/back | Resolve active category in trusted menu/branch/channel | `PRODUCT_LIST` | Stale/hidden category refreshes list |
| `PRODUCT_LIST` | product opaque ID, pagination/back | Resolve active, available, channel-visible product belonging to selected category/menu | `PRODUCT_DETAILS` | Unavailable product returns notice and refreshed list |
| `PRODUCT_DETAILS` | customize/add/back | Re-read authoritative price/availability/options | first customization state or `CART` | Product change invalidates draft and returns refreshed detail |
| `VARIANT_SELECTION` | option-value opaque ID/back | Validate value belongs to product option and branch | next required customization or `CART` | Invalid/stale value keeps state and presents current choices |
| `MODIFIER_SELECTION` | one/many opaque value IDs/done/back | Validate ownership, required flag and selection bounds | next group, add-ons, or `CART` | Reject incomplete/excess selection without mutation |
| `ADDON_SELECTION` | opaque value IDs/skip/done/back | Validate optional values and bounds | `CART` | Unavailable add-on removed with explicit confirmation |
| `CART` | view/change/remove/add-more/continue/back | Read customer-isolated server cart; recompute prices | `CATEGORY_LIST` or `CHECKOUT_READY` | Empty cart returns `CATEGORY_LIST` |
| `CHECKOUT_READY` | review/back/restart | Validate cart, branch schedule, order type availability and current quote | remains `CHECKOUT_READY` | No final order/payment action in Phase 2 |

`VARIANT_SELECTION`, `MODIFIER_SELECTION` and `ADDON_SELECTION` are presentation classifications over existing NexDine product options until the domain proves a distinct variant entity is needed. The engine must not invent a separate price source.

## Global commands

| Command | Behavior |
|---|---|
| `BACK` | Pop one valid navigation checkpoint; never redirect to another tenant/session |
| `CANCEL` | Confirm destructive intent, then discard only the current draft/session cart |
| `HELP` | Show context-sensitive actions; preserve state |
| `RESTART` | Confirm, expire current navigation draft, return to `WELCOME`; keep submitted orders untouched |
| `HUMAN_SUPPORT` | Move conversation to `waiting_for_staff`; automated mutations pause until staff releases it |

## Transition rules

- Serialize transitions with a database lock or optimistic `state_version`; one provider event ID can commit at most once.
- Persist the response/action result with the transition so provider retries do not repeat cart mutations.
- Interactive action tokens carry a short-lived opaque action reference bound server-side to conversation, state/version, tenant, branch and offered entity.
- Text aliases are convenience only and must resolve through the same server-side validators.
- Reject events for expired sessions, closed conversations, inactive integrations, changed assignments or disallowed branches.
- Default inactivity timeout: browsing/customization 30 minutes; checkout-ready 15 minutes. Exact values should be tenant settings with bounded platform defaults.
- On timeout, expire draft state and offer restart. Never silently attach a customer to another open conversation or cart.
- Provider send failure retries transport only; it must not rerun the committed business transition.

## Provider-neutral interaction contract

The engine emits generic messages: `TextMessage`, `ChoiceList`, `ActionButtons`, `MediaCard` and `FallbackLink`. Adapters decide whether Meta/MSG91 can render list/button/media messages and fall back to numbered text plus opaque reply tokens. Provider limits (button/list counts, text length and media rules) are adapter metadata, never business-state conditions.

## Error policy

- Customer errors: short actionable message and correlation reference; no internal IDs or stack trace.
- Stale selection: reload authoritative catalog slice and remain at the closest valid state.
- Validation error: no partial cart mutation.
- Infrastructure error: preserve prior committed state, retry safely, then offer human support.
- Security/context error: fail closed, audit sanitized context, send no tenant-derived content.
