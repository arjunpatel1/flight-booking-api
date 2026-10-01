# WhatsApp Ordering Phase 2 Architecture

Status: design only. No Phase 2 production logic, schema or tests are implemented.

## Scope and boundaries

Phase 2 is limited to guided menu navigation, a persisted conversation state machine and product-customization foundations. It excludes final checkout, payment, delivery completion and full order tracking.

The architecture remains:

`NexDine catalog → channel visibility policy → WhatsApp catalog query/resources → conversation state machine → existing server cart`

and:

`conversation engine → provider-neutral message service → WhatsAppOrderingProvider → Meta | MSG91`

No provider adapter may query carts/orders, and no conversation service may format Meta/MSG91 payloads.

## Existing components to reuse

- `Menu`, `OnlineMenu`, `Category`, `Product`, `Option`, `OptionValue`, media and translation relationships.
- `BranchSchedule`, active menu/branch checks and existing price resolution/special-price logic.
- `Cart`/`CartDBStorage`, `ChosenProductOptions`, `ValidatesCartItemOptions`, cart tax/discount/total calculation.
- Existing product/category cache tags and tenant-aware `makeCacheKey` convention.
- Phase 1 trusted context, provider adapters, conversations, sessions, idempotent webhook events and UUID resources.

Do not return or reuse POS resources for customers: they contain internal IDs and operational metadata. Add narrow WhatsApp DTO/resources backed by shared queries/calculators.

## Catalog query boundary

Introduce a read-only `WhatsAppCatalogService` contract after the production gate passes:

- input: trusted context, locale, pagination cursor and optional category/product public reference;
- queries: only the resolved active tenant branch and active assigned menu;
- filters: menu/category/product active state, product availability, soft deletion, branch acceptance/schedule, and future WhatsApp visibility policy;
- eager loading: categories, optimized media, resolved prices, options/values and taxes only when required;
- output: immutable channel DTOs, never Eloquent models.

Suggested resources:

- `WhatsAppMenuResource`: menu reference, branch reference/name, currency, availability and catalog version.
- `WhatsAppCategoryResource`: public reference, name, image, order and child availability.
- `WhatsAppProductResource`: public reference, category references, name, description, image, authoritative display price, availability and `requires_customization`.
- `WhatsAppCustomizationGroupResource`: option reference, label, presentation kind, required, min/max and values.
- `WhatsAppCustomizationValueResource`: value reference, label, authoritative price delta/display.
- `WhatsAppCartResource`: opaque session/cart reference, normalized items and server-calculated totals.

## Existing-model findings and required design decisions

- Products, options and option values already have UUIDs. Conversations and sessions have UUIDs.
- Categories currently have no UUID. Phase 2 needs an additive, backfilled, unique category UUID migration before exposing categories. It must follow the Phase 1 online backfill pattern and receive its own lifecycle test.
- There is no separate Variant model. Current select/radio product options can represent a variant choice for UX, but domain semantics must be declared by configuration rather than inferred from labels.
- Existing options provide `is_required` and types, but no explicit `min_selection`, `max_selection`, channel visibility or availability columns. Phase 2 must first decide whether bounded metadata belongs on the product-option pivot or option itself. Product-specific pivot metadata is preferable when the same global option has different rules per product.
- Existing `OptionValue` has price/price type but no active/channel-visible flag. Until a reviewed additive model exists, deleted/detached values are unavailable; do not invent availability client-side.
- Existing discounts/taxes/pricing are authoritative services. WhatsApp resources display their result but never recalculate them.

## Opaque identifier plan

| Entity | Public identifier | Ownership validation |
|---|---|---|
| Menu | existing UUID | active menu belongs to resolved branch |
| Category | proposed additive UUID | active category belongs to resolved menu/branch |
| Product | existing UUID | active/available product belongs to menu, category and branch |
| Variant/customization group | existing option UUID | option attached to selected product and same branch |
| Modifier/add-on value | existing option-value UUID | value belongs to selected attached option and branch |
| Conversation | existing UUID | tenant + assignment + customer ownership |
| Session/cart | existing session/cart UUID | tenant + branch + conversation/customer ownership |

Numeric compatibility must not be added to new customer-facing Phase 2 endpoints. Knowing a UUID is never sufficient: every lookup includes trusted tenant/branch/menu/session ownership.

## Product customization flow

`Product → ordered customization groups → required? → min/max validation → values available? → quantity → bounded instruction → authoritative cart add`

Rules:

- Re-read product and option graph at commit time under trusted context.
- Required single-select: exactly one. Optional single-select: zero or one.
- Required multi-select: configured minimum through maximum; optional: zero through maximum.
- Until explicit min/max metadata exists, preserve current NexDine semantics: required means at least one; single-select max one; multi-select maximum equals available values. Do not claim stronger behavior in UI.
- Quantity is a bounded positive integer using existing input limits; product/business rules can lower the maximum.
- Special instruction is optional, normalized and length-bounded; it cannot alter pricing or fulfillment controls.
- If a selected value becomes unavailable/detached, reject the mutation atomically and return refreshed choices.
- Every option/value must belong to the selected product graph. Duplicate references are normalized once.
- Cart storage receives internal IDs only after UUID ownership validation. All base/option pricing, tax, discount and totals are calculated by existing server services.

## Provider-neutral menu UX

1. `Hi` → welcome with View Menu, Offers, Track Order and Help.
2. View Menu → paginated categories.
3. Category → paginated product cards/list.
4. Product → image, description and authoritative display price.
5. Customize/Add → ordered customization groups or direct cart add.
6. Cart → view items, change/remove, add more, or continue to `CHECKOUT_READY`.

The generic interaction service supplies stable action references and fallback numbered text. Meta/MSG91 adapters advertise supported capabilities and translate list/button/media structures within provider limits. Unsupported rich controls degrade to text without changing state semantics.

## Tenant-safe cache design

Proposed key:

`tenant:{tenant}:branch:{branch}:channel:whatsapp:catalog:{catalogVersion}:locale:{locale}:{resource}:{cursor}`

Use existing tenant-aware key helpers rather than literal keys. Cache only resource DTOs, never context objects, credentials, customer/session data or Eloquent models. `catalogVersion` should be a tenant/branch version token incremented after committed changes.

Invalidate/version-bump on product price, special price, availability, active state, category/tree, product-category mapping, option/value attachment or price, media, menu activation, branch state and WhatsApp visibility changes. Register after-commit listeners to existing domain events/model changes; avoid broad global tag flushes for channel reads. A visibility-setting change invalidates only that tenant/branch/channel version.

## Future server-cart contract

Customer request:

```json
{
  "session_reference": "uuid",
  "product_reference": "uuid",
  "quantity": 2,
  "customization": {
    "option-uuid": ["value-uuid"]
  },
  "instruction": "Less spicy",
  "action_reference": "opaque-short-lived-token"
}
```

The request never contains tenant/branch IDs, unit price, option price, discount, tax, charges or total. The application resolves ownership, validates offered state/version and converts public references to internal IDs. Existing cart services then calculate every amount and return a safe cart DTO. A transition idempotency key prevents duplicate adds.

## Admin UX design

Keep the top-level WhatsApp workspace understandable to restaurant staff:

- **Overview:** readiness, active number, branch coverage, order/chat health and actionable warnings.
- **Account Connection:** provider, number, enabled state; secrets only through controlled replacement.
- **Branch Mapping:** UUID-backed branch multiselect and per-branch readiness.
- **Ordering Settings:** approval and supported order types.
- **Catalog (Phase 2):** WhatsApp enabled switch, category/product visibility, sort order, availability status, product preview and customization preview.
- **Conversations:** operational inbox/handoff.
- **Logs & Health:** sanitized delivery/webhook diagnostics behind advanced permissions.

Later sections (Payment, Delivery, Business Hours, Templates and Notifications) remain visible only when the related feature is implemented/entitled. Normal restaurant users should not see account IDs, signatures, credentials, webhook internals or database identifiers.

Catalog screen proposal:

- branch selector first; active menu shown read-only;
- searchable category tree with inherited/explicit channel visibility;
- product table showing image, price, active/available/channel state and customization count;
- side preview renders provider-neutral card/list and text fallback;
- bulk visibility changes show affected item count and require confirmation;
- diagnostics explain why an item is unavailable without exposing internals.

## Phase 2 test plan

No tests are implemented by this document. Required runtime tests:

1. Menu belongs to trusted tenant/branch.
2. Category belongs to active branch menu.
3. Product belongs to category, menu, tenant and branch.
4. Another tenant’s product UUID is rejected.
5. Disabled/unavailable product cannot be added.
6. WhatsApp-hidden product cannot be listed or selected.
7. Invalid/stale public ID fails safely.
8. Variant option/value belongs to selected product.
9. Modifier value belongs to selected group/product/branch.
10. Required selection cannot be skipped.
11. Maximum selection cannot be exceeded.
12. Submitted prices/totals are ignored or rejected and server values win.
13. Payload tenant cannot alter context.
14. Payload branch cannot alter context.
15. Session/cart belongs to customer conversation.
16. Session belongs to trusted tenant/branch/assignment.
17. Expired/closed session fails safely.
18. Duplicate provider event/action does not duplicate cart mutation.

Also test pagination/back/restart, stale catalog version, cache separation/invalidation, provider capability fallback, concurrent transition locking, resource secret/ID snapshots and query-count bounds.

## Implementation sequence after authorization

1. Pass Phase 1 Gates 2 and 3 first.
2. Add category UUID and any approved option-rule/visibility schema through additive lifecycle-tested migrations.
3. Add DTOs/catalog query boundary and tenant-safe cache versioning.
4. Add persisted transition service and provider-neutral interaction DTOs.
5. Extend provider adapters only for interaction rendering.
6. Connect validated opaque customization to the existing server cart.
7. Add admin catalog visibility/preview UX.
8. Run all isolation, idempotency, cache, query and provider-fixture tests before canary rollout.

## Architectural risks

- Treating option labels as variants would be brittle; semantics need explicit metadata.
- Category has no public UUID today.
- Selection min/max and option-value availability are not modeled explicitly.
- Existing public/POS resources contain numeric IDs and are unsuitable for WhatsApp reuse.
- Existing cache tags are broad; channel catalog needs scoped versioning to avoid leaks and excessive flushes.
- Provider interaction limits differ and may change; capability-driven fallback is mandatory.
- State transition and provider delivery retries must be separated to prevent duplicate cart actions.
