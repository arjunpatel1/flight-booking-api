# NexDine Enterprise SaaS Verification

This checklist verifies the developer-free SaaS onboarding flow without replacing the existing tenant architecture.

## Completed

| Module | Status | Evidence |
| --- | --- | --- |
| Tenant provisioning | Complete | `POST /api/v1/saas/onboarding/provision` creates tenant, branch, admin, subscription, settings, and dispatches background steps. |
| Public signup API | Complete | `POST /api/v1/saas/signup` with captcha/terms/honeypot support behind `SAAS_SELF_SERVICE_ENABLED`. |
| Public onboarding page | Complete | `/onboarding` includes signup, progress, activation QR, activation code, downloads, checklist, and completion state. |
| Provisioning progress | Complete | `GET /api/v1/saas/onboarding/provision/{uuid}` returns progress, steps, failures, and estimated remaining time. |
| Retry/resume/cancel | Complete | Existing retry, resume, cancel routes operate on failed/unfinished provisioning runs. |
| Runtime waiter config | Complete | `GET /api/v1/saas/client-config/{slug}` returns tenant, branch, API, Reverb, theme, branding, features, and downloads. |
| Activation QR | Complete | `GET /api/v1/saas/client-config/{slug}/activation` returns QR payload with config URL, issued time, expiry, and signature metadata. |
| Download center config | Complete | Download URLs are controlled by `SAAS_DOWNLOAD_*` env values and returned in signup/client-config payloads. |
| SaaS admin control | Complete | Admin SaaS dashboard includes provisioning, control plane, billing, backups, delivery jobs, alerts, and Apache/SSL automation hooks. |
| Device fleet baseline | Complete | POS terminal device heartbeat/control exists in the POS module and waiter app heartbeat reports device health. |

## Partial / Requires Live Server Validation

| Module | Pending Verification | Reason |
| --- | --- | --- |
| Apache/SSL automation | Run from SaaS admin on staging server | Requires root/sudo-capable server environment. |
| Supervisor/Reverb workers | Verify queue names and process counts after deployment | Local code cannot prove live supervisor process health. |
| White-label app build | Verify CI/webhook returns APK artifact URL | Depends on external build pipeline and webhook secret. |
| Real payment billing | Verify Razorpay/Stripe keys and webhook callbacks | Requires gateway sandbox/live credentials. |
| Email/WhatsApp delivery | Verify notification providers | Depends on SMTP/WhatsApp credentials. |
| Full backup restore execution | Verify on staging copy first | Restore can overwrite tenant data; never validate first on production. |

## Manual Verification Flow

1. Set `SAAS_SELF_SERVICE_ENABLED=true`.
2. Configure `SAAS_DOWNLOAD_WAITER_APP_URL` and `SAAS_DOWNLOAD_WINDOWS_AGENT_URL`.
3. Open `/onboarding`.
4. Submit restaurant details.
5. Confirm the page shows provisioning progress.
6. Confirm activation QR appears.
7. Open the client config URL and verify API/Reverb/theme/logo values.
8. Scan activation QR in the generic waiter app.
9. Login with the generated admin/waiter credentials.
10. Confirm tenant data is isolated and branch data loads.

## Quality Gates

Run before release:

```bash
php artisan route:list --path=api/v1/saas
php artisan test tests/Unit/Saas/ProvisioningWorkflowTest.php tests/Unit/Saas/SaasEnterpriseConfigTest.php
npm run type-check
flutter analyze
```

## Remaining Production Risks

- Native launcher icon cannot change at runtime after APK installation. Use the optional white-label build pipeline for client-specific icons.
- Activation payload includes signed/expiry metadata, but current generic waiter app remains backward-compatible and does not yet enforce the signature locally.
- Public onboarding should remain disabled until captcha, payment/trial policy, and rate limits are configured for the production domain.
