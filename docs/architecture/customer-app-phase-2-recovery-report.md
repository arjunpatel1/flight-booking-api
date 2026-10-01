# Customer App Phase 2 Recovery Report

## 1. Why the database gate originally failed

The original Phase 2 run was configured for SQLite `:memory:` through `phpunit.xml`, but the CLI PHP binary does not provide `pdo_sqlite` or `SQLite3`. PHPUnit therefore failed during connection setup before exercising any HTTP assertion. This was an environment failure, not evidence that the control plane was secure.

The recovery used MySQL, as requested, without changing the normal development database. A dedicated disposable schema named `nex_dine_phase2_test` was created and positively selected before executing destructive tests.

## 2. CLI PHP and pdo_sqlite status

`php -m` reports `PDO` and `pdo_mysql`. `php --ri pdo_sqlite` reports that the extension is not present. No system PHP configuration was changed because MySQL was already available and a disposable MySQL schema provides the production-relevant constraint behavior required by this phase.

## 3. Test database used

- Driver: MySQL through `pdo_mysql`
- Host: the existing local MySQL service from the API `.env`
- Schema: `nex_dine_phase2_test`
- Purpose: disposable Phase 2 tests only
- Explicitly not used: the normal `nex-dine` development schema, production, staging, or customer data

The test suite creates its minimal schema in `setUp` and drops it in `tearDown`. The disposable database itself is retained empty for repeatable verification.

## 4. Migration results

PASS. The build-control migration test ran against MySQL and verified that the registration, build, and artifact tables can be created, inspected, rolled back in dependency order, and recreated. It verifies named indexes, foreign keys, tenant-scoped uniqueness, and the composite artifact-to-build tenant ownership constraint.

MySQL also exposed an unsafe test rollback order in the broader security-contract test: the registration parent was being dropped before `customer_app_sessions`. The test was corrected to roll back the session child first, then registrations, and recreate them in forward dependency order. No foreign key was removed or weakened.

## 5. Initial 13-test result

The first unchanged MySQL execution reached two passing cases and then stopped at the third case: `2 passed / 1 failed / 10 not executed`. The endpoint returned HTTP 500 while building a validation response because the isolated schema omitted the shared `translations` table used by the application's validation translator.

After supplying that shared test dependency, the next diagnostic run reached `9 passed / 1 failed / 3 not executed`. Its only failure was a test-double lifecycle problem: replacing an authorization mock between requests did not replace the authorization dependency already held by the resolved controller service.

## 6. Final 13-test result

PASS: `13 passed / 0 failed / 0 skipped`, with 74 assertions, on `nex_dine_phase2_test`.

The final run exercised the real HTTP endpoints for create, view, artifact access, cancellation, and retry. It did not substitute unit tests or skip cases.

## 7. Failures, classification, and fixes

### Missing translations table

- Classification: TEST SETUP
- Endpoint: `POST /api/v1/tenants/current/customer-app/builds`
- Expected: HTTP 422 for prohibited authority fields
- Actual: HTTP 500 caused by querying the absent `translations` table
- Fix: the isolated test schema now creates and drops the minimal shared translations table used by request validation
- Security impact: none; assertions and application authorization were not relaxed

### Sequential authorization denial test

- Classification: TEST SETUP
- Endpoint: `POST /api/v1/tenants/current/customer-app/builds`
- Expected sequence: `TENANT_SUSPENDED`, `PLAN_FEATURE_DISABLED`, `ROLE_FORBIDDEN`
- Actual: the first resolved service retained the first denial mock
- Fix: one authorization double now emits the three expected exceptions in request order
- Security impact: none; the same real HTTP endpoint and exact error assertions remain

### MySQL parent-before-child rollback

- Classification: TEST SETUP / DATABASE VALIDATION
- Actual: MySQL correctly rejected dropping `customer_app_registrations` while `customer_app_sessions` referenced it
- Fix: rollback validation now follows reverse dependency order and reapply follows forward dependency order
- Security impact: foreign keys remain enforced

No application, authorization, tenant-query, entitlement, branding, idempotency, or artifact-contract defect was found by the required 13-case suite.

## 8. Entitlement verification

PASS. The server-side authorization boundary denies suspended tenants, inactive registrations, disabled customer-app build entitlement, and unauthorized roles. Client state is not used as the authority. App-bundle access remains separately entitlement-controlled.

## 9. Tenant ownership verification

PASS. Tenant identity is resolved from the authenticated request context. Client-supplied tenant and registration authority fields are prohibited. Tenant A cannot view, download, cancel, retry, manipulate, or create a build for Tenant B. Build and artifact queries are tenant-scoped, and artifact ownership is protected by a composite database constraint.

## 10. Idempotency verification

PASS. The same tenant, registration, version, build type, platform, branding/config revision returns the active request instead of creating another. A legitimate new version creates a distinct request. The database active fingerprint constraint supplies the race-safe uniqueness boundary.

## 11. Branding validation

PASS. Missing required branding is rejected before a build request is queued. The service validates the public app identity and required branding fields server-side; callers cannot supply their own configuration snapshot or source revision.

## 12. Artifact security verification

PASS. Artifact lookup is tenant-bound and authenticated. Responses do not expose a public URL or private storage reference. A foreign tenant cannot request artifact metadata or download another tenant's artifact. No artifact was marked ready and no APK/AAB was generated.

## 13. UI verification

PASS from the Phase 2 implementation verification. The existing Downloads UI has request loading state, duplicate-click protection, error presentation, entitlement and branding failure handling, status polling, cancellation, retry, and authenticated blob download. This recovery did not redesign or weaken the UI, and backend authorization remains authoritative.

## 14. Waiter App regression verification

NONE. `git status -- nexdine-waiter-pos` and `git diff --name-only -- nexdine-waiter-pos` are empty for this recovery. Waiter activation, QR/key activation, runtime configuration, printing, Reverb, and POS order flow were not invoked or modified.

## 15. Files changed by recovery

- `tests/Feature/Saas/CustomerAppBuildControlPlaneTest.php`
  - added the shared translations schema required by HTTP validation
  - made the three denial responses deterministic without changing assertions
- `tests/Feature/Saas/CustomerAppSecurityContractTest.php`
  - made migration rollback/reapply validation MySQL-safe by respecting FK dependency order
- `docs/architecture/customer-app-phase-2-recovery-report.md`

The build-control application implementation was not changed to manufacture a passing result.

## 16. Files untouched

This recovery did not modify the Waiter application, generate or modify Flutter/Gradle workers, generate APK/AAB artifacts, configure CI/CD build infrastructure, alter production PHP, execute production migrations, deploy code, or start Phase 3 implementation.

## 17. Remaining risks

- CLI SQLite remains unavailable; Phase 2 is certified on disposable MySQL instead.
- The broader customer-app security-contract suite initially produced 56 passes and one MySQL rollback-order failure. The rollback fixture is corrected, and its focused MySQL rerun passes with 10 assertions. The required 13-case Phase 2 suite and its migration case are fully green.
- A real generation worker, signing-key custody, isolated Flutter/Gradle environment, artifact signing, retention, cancellation during build, and production observability belong to Phase 3 and do not exist yet.
- No production or staging migration/deployment was performed in this recovery.

## Gate conclusion

The Phase 2 database, HTTP security, entitlement, tenant ownership, idempotency, branding, artifact, UI, and Waiter-regression gates are green. The control plane is ready for Phase 3 design and implementation, but it is not yet a real application generation pipeline because Phase 3 workers and signed artifacts have intentionally not been created.
