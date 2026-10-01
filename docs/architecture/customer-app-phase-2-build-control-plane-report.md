# Customer App Phase 2 Build Control Plane Report

## 1. Existing architecture reused

The control plane reuses the authenticated tenant context, `CustomerAppRegistration`, customer-app entitlement checks, tenant branding/settings, Laravel activity logging, the existing API middleware stack, and the tenant administrator workspace. It does not introduce an alternate tenant authority or accept a client-provided tenant identifier.

## 2. New build-control entities

`CustomerAppBuild` records immutable build requests and lifecycle metadata. `CustomerAppBuildArtifact` records the private output contract separately from the request. Both are tenant-owned. The artifact foreign key includes the tenant identifier so an artifact cannot be associated with another tenant's build.

## 3. Build lifecycle

The supported states are `requested`, `queued`, `building`, `testing`, `ready`, `failed`, `cancelled`, and `revoked`. Tenant requests enter `queued`. This phase contains no build worker, Flutter invocation, Gradle invocation, or fake transition to `ready`.

## 4. Idempotency

A deterministic fingerprint is produced from the tenant, registration, platform, build type, version, and sanitized configuration snapshot. A matching non-terminal request is returned instead of creating a duplicate. A changed version or changed public configuration produces a new request.

## 5. Entitlement enforcement

Requests require an active tenant, an active customer-app registration, and the `customer_app` plus `customer_app_build` entitlements. Android app-bundle requests additionally require the AAB entitlement. Authorization is repeated for create, list, show, cancel, retry, and artifact download operations.

## 6. Tenant ownership

Tenant identity is resolved server-side. Queries are scoped by `tenant_id`, and UUID lookups occur only inside that scope. Create rejects tenant, registration, source-commit, and configuration-snapshot authority supplied by the client. Enterprise administrators must belong to the active tenant; platform administrators continue to use the existing platform authorization boundary.

## 7. Configuration snapshot

Each request stores a deterministic public snapshot containing only build-relevant identity, branding, platform, version, and entitlement information. Secrets, tokens, signing material, credentials, internal storage paths, and user-supplied source revisions are excluded from the API contract and fingerprint input.

## 8. Branding validation

The service validates the required customer-facing name, application identifier, primary color, logo, and platform-specific branding values before accepting a request. Validation failures return a stable missing-fields payload instead of creating an incomplete build record.

## 9. API design

Six tenant-current endpoints provide create, paginated list, show, cancel, retry, and authenticated artifact download operations. Responses use a restricted presenter and do not expose filesystem paths. Invalid build/platform combinations are rejected using stable machine-readable errors.

## 10. Admin UI

The tenant Downloads workspace includes a minimal build-request form, build status history, cancellation/retry actions, and authenticated blob download for ready artifacts. It does not imply that a worker is connected or that an artifact exists before the `ready` state.

## 11. Queue boundary

No normal Laravel job is dispatched and no worker implementation is included. The persisted `queued` request is the explicit Phase 3 handoff boundary. A later worker must claim requests with tenant context, enforce immutable inputs, and own all state transitions after `queued`.

## 12. Artifact contract

Artifacts are modeled separately, tenant-owned, revocable, and served only after authorization from private storage. Download requires a `ready` build and a non-revoked artifact. Public URLs and internal storage paths are not returned.

## 13. Security tests

The isolated feature suite defines 13 cases covering foreign-tenant list/show/download/cancel/retry denial, forged tenant inputs, inactive tenant/registration, missing entitlements, AAB entitlement, branding validation, duplicate idempotency, invalid combinations, and private artifact access. In this environment the suite executed 0 assertions because CLI PHP lacks `pdo_sqlite`; all 13 cases failed during isolated database setup. This is an environment failure, not a passing security result, and must be cleared before Phase 3.

## 14. Migration validation

The two additive Phase 2 migrations define tenant-owned build and artifact tables, indexes, tenant-scoped uniqueness, and composite build/artifact ownership. PHP syntax validation passes. Runtime migration/testing is not certified because the isolated SQLite PDO driver is unavailable. No production migration was run.

## 15. Waiter regression verification

No file under `nexdine-waiter-pos` was changed by Phase 2. No Waiter build or runtime workflow was invoked.

## 16. Files changed

- `Modules/Saas/app/Models/CustomerAppBuild.php`
- `Modules/Saas/app/Models/CustomerAppBuildArtifact.php`
- `Modules/Saas/app/Services/CustomerApp/CustomerAppBuildService.php`
- `Modules/Saas/app/Http/Controllers/Api/V1/CustomerAppBuildController.php`
- `Modules/Saas/app/Providers/SaasRuntimeServiceProvider.php`
- `Modules/Saas/config/config.php`
- `Modules/Saas/routes/api/v1.php`
- `Modules/Saas/database/migrations/2026_08_11_000003_create_customer_app_builds_table.php`
- `Modules/Saas/database/migrations/2026_08_11_000004_create_customer_app_build_artifacts_table.php`
- `tests/Feature/Saas/CustomerAppBuildControlPlaneTest.php`
- `src/api/saas/customerAppBuild.ts`
- `src/pages/Admin/Workspace/Downloads.vue`
- `docs/architecture/customer-app-phase-2-build-control-plane-report.md`

## 17. Files untouched

Phase 2 did not modify the Waiter application, POS workflows, printing, Reverb, customer-app activation contract, onboarding/provisioning, build signing, Flutter source generation, Gradle configuration, or production deployment configuration.

## 18. Phase 3 prerequisites

Install/enable `pdo_sqlite` for CLI PHP and obtain a clean 13/13 isolated HTTP security result. Then define an isolated worker identity and claim protocol, signing-secret custody, private build environment, artifact checksum/signature verification, tenant-context restoration, cancellation semantics, retention policy, and worker observability. Phase 3 must not start while the Phase 2 security suite is unexecuted.
