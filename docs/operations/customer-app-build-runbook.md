# Customer App Build Runbook

This runbook explains how to make NexDine customer app builds work reliably without exposing signing secrets.

## What failed

Two different build problems can appear together:

1. GitHub Actions artifact quota is full.
   - This blocks workflows that use `actions/upload-artifact`.
   - Customer APK builds upload the signed APK back to NexDine, but other workflows, such as the Windows agent build, can still consume GitHub artifact storage.
   - GitHub recalculates artifact storage every 6–12 hours after cleanup.

2. Customer app signing variables are empty.
   - The build worker validates signing secrets before compiling.
   - If any required secret is blank, `test -n "$KEYSTORE_B64"` or a similar validation step fails immediately.

## GitHub environment secrets

Create or update the protected GitHub environment named:

```text
customer-app-build
```

Add these environment secrets:

```text
CUSTOMER_APP_BUILD_API
CUSTOMER_APP_BUILD_WORKER_TOKEN
CUSTOMER_APP_KEYSTORE_BASE64
CUSTOMER_APP_KEYSTORE_PASSWORD
CUSTOMER_APP_KEY_ALIAS
CUSTOMER_APP_KEY_PASSWORD
```

`CUSTOMER_APP_BUILD_API` must be the API origin only:

```text
https://api.nexdine.myteknoland.net
```

Do not include `/api/v1`; the workflow appends that path.

`CUSTOMER_APP_BUILD_WORKER_TOKEN` must match the backend `.env` value:

```text
SAAS_CUSTOMER_APP_BUILD_WORKER_TOKEN
```

## Creating the release keystore

If a release keystore does not already exist, create one outside the repository:

```bash
keytool -genkeypair \
  -v \
  -storetype JKS \
  -keystore customer-release.jks \
  -alias nexdine_customer_release \
  -keyalg RSA \
  -keysize 4096 \
  -validity 10000
```

Convert it to a single-line Base64 value.

Linux:

```bash
base64 -w 0 customer-release.jks > customer-release.jks.b64
```

macOS:

```bash
base64 -i customer-release.jks | tr -d '\n' > customer-release.jks.b64
```

Paste the file contents into `CUSTOMER_APP_KEYSTORE_BASE64`.

Never commit the keystore, passwords, or Base64 output.

## Backend production `.env`

Set these values on the API server:

```text
SAAS_CUSTOMER_APP_BUILD_WORKER_CONNECTED=true
SAAS_CUSTOMER_APP_BUILD_WORKER_TOKEN=<same value as CUSTOMER_APP_BUILD_WORKER_TOKEN>
SAAS_CUSTOMER_APP_SOURCE_REPOSITORY=https://github.com/arjunpatel1/restaurant-pos-web.git
SAAS_CUSTOMER_APP_SOURCE_COMMIT=<deployed commit sha>
SAAS_CUSTOMER_APP_FLUTTER_VERSION=<approved flutter version>
SAAS_CUSTOMER_APP_SIGNER_SHA256=<release signer sha256>
SAAS_CUSTOMER_APP_ARTIFACT_DISK=local
SAAS_CUSTOMER_APP_BUILD_RETENTION_DAYS=30
```

Restart queue workers after changing build-worker settings.

## Clearing GitHub artifact quota

1. Open the repository in GitHub.
2. Go to Actions.
3. Delete old workflow artifacts from historical runs, especially Windows agent builds.
4. Keep upload-artifact retention low for large binaries.
5. Wait for GitHub storage recalculation. It can take 6–12 hours.

The Windows agent workflow is configured with short artifact retention so future runs do not permanently consume quota.

## Verifying the build path

1. Trigger `Customer App Build Worker` manually from GitHub Actions.
2. Confirm the required secret validation step passes.
3. Request a customer app build from NexDine Admin.
4. Confirm the build status moves through queued, running, completed.
5. Confirm the artifact appears in NexDine customer app build history.
6. Download the artifact from NexDine Admin, not from GitHub artifacts.

## Private artifact pruning

NexDine private customer-app artifacts are pruned by the backend command:

```bash
php artisan saas:prune-customer-app-build-artifacts --dry-run
php artisan saas:prune-customer-app-build-artifacts
```

Run the dry-run first and review the output before deleting old artifacts.

## Security rules

- Do not store keystore files in the repository.
- Do not paste secrets into tickets, logs, or chat.
- Do not expose build-worker tokens to tenant admins.
- Rotate the worker token if it is ever printed or copied outside GitHub/backend secret storage.
- Keep the GitHub environment protected so only approved maintainers can run release builds.
