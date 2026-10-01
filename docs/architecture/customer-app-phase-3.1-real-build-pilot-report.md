# Customer App Phase 3.1 Real Build Pilot Report

Date: 2026-08-12

## Decision

Phase 3.1 is not release-ready. The controlled build path is implemented and its static/security checks pass, but no genuine release APK has completed the worker pipeline and no artifact has been installed on a physical device.

No production build was executed and no waiter-app source was changed.

## Implemented controls

- Build requests capture a validated schema-v2 immutable snapshot bound to tenant, application registration, package, version, source commit, branding revision, API origin, toolchain and pinned signing certificate.
- Snapshot values must exactly match server-controlled configuration; caller-provided repository, commit, API origin or signing identity cannot override authority.
- Branding asset URLs require HTTPS and reject loopback, private/reserved IP literals, and local/internal hostnames before a worker can claim a build.
- Invalid queued snapshots are terminally failed without exposing internal validation details and cannot permanently starve later valid requests. Worker claiming is bounded by the observed queue depth rather than an arbitrary scan limit.
- Each tenant has a configurable active-build capacity. Repeated identical active requests are idempotent, while parallel different-version builds are rejected until capacity is available.
- Workers use a short lease, heartbeat, explicit state machine, retry limit, cancellation handshake and worker-identity binding.
- Expired claimed leases are recovered safely: exhausted attempts fail terminally with a stable code, cancellable builds become cancelled, and active worker ownership is cleared so future claims are not blocked.
- Release signing has no debug-key fallback. CI verifies package ID, version, label, commit, signer certificate and SHA-256 checksum before upload.
- Artifact upload uses a unique private object key, compensating deletion on database failure, retention metadata, tenant ownership and a private streamed download that also works with non-local storage disks.
- CI scans the expanded APK for known secret material and private-key markers before publishing it.

## Verification performed

### Customer Flutter application

- `flutter analyze`: PASS (`No issues found`)
- `flutter test`: 8 passed, 0 failed, 0 skipped
- Release APK generation: not run in this pass

### Laravel

- PHP syntax for the build controller and build services: PASS
- Laravel Pint for the touched build boundary: PASS
- `git diff --check`: PASS
- Snapshot validator plus build control-plane suite: 35 tests passed, 178 assertions, 0 skipped. The isolated test run loaded a temporary local PDO SQLite extension from `/tmp`; it did not modify PHP, the database, or production configuration.

### CI release environment

- The workflow references all required secret names: build API, worker token, release keystore, keystore password, key alias, and key password.
- The workflow validates that secrets are present, rejects the Android debug key, pins the signer SHA-256, and scrubs signing material from the runner.
- The actual protected-environment secret values were not inspected: this workstation has no GitHub CLI or authenticated GitHub token. Secret values must never be printed or copied into the repository.
- The `customer-app-build-worker` job now targets the protected `customer-app-build` GitHub Environment. Configure required reviewers and branch protection in GitHub before the pilot.

### Physical device

- ADB was available, but no authorized Android device was attached at certification time. No install or device-side mutation was attempted.

## Required pilot before Phase 4

1. Confirm the six protected GitHub Actions secret names are populated in the isolated build environment, without revealing their values.
2. Confirm the non-debug release keystore alias and SHA-256 certificate match the server-pinned signer.
3. Request exactly one Android release build for one approved pilot tenant and observe every state through `ready`.
4. Download the private artifact as the owning tenant, confirm cross-tenant denial, verify checksum/signature/package/version/label independently, and install it on a physical device.
5. Verify cold start, API tenant identity, branding, authentication, ordering, cancellation, retry, revoked/expired artifact behavior, and confirm no waiter-app regression.

## Gate

```text
PHASE 3.1:
FAIL

REAL APK:
FAIL

RELEASE SIGNING:
FAIL

ARTIFACT VERIFICATION:
FAIL

DEVICE VERIFICATION:
FAIL

CROSS-TENANT ISOLATION:
FAIL

CANCELLATION:
FAIL

RETRY:
FAIL

OBSERVABILITY:
FAIL

LARAVEL TESTS:
35 passed / 0 failed / 0 skipped

FLUTTER TESTS:
8 passed / 0 failed / 0 skipped

WAITER APP REGRESSION:
NONE

PRODUCTION SERVER BUILD EXECUTION:
NONE

CUSTOMER APP GENERATION:
NOT READY

NEXT PHASE:

Only recommend Phase 4 after the REAL APK is installed and the complete
pilot flow is verified.
```

The remaining release blocks are real-pilot gates, not local test failures: protected CI secret readiness, non-debug signer confirmation, one isolated APK build, tenant-bound artifact verification, and physical-device certification.
