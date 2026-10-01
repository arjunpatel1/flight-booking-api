# Phase 1 Canary and Rollback Runbook

Status: prepared; internal disable/failure mechanisms rehearsed on disposable data. Provider canary and operational deployment rollback remain unexecuted. Phase 2 remains frozen.

## Canary stages

1. Enable the existing `whatsapp_ordering` entitlement only for an internal test tenant and one branch/account assignment.
2. Enable one controlled restaurant after webhook, queue, provider-ID and alert checks pass.
3. Expand to a small allowlisted group with daily error/replay/latency review.
4. General availability requires provider certification and an approved release gate.

## Preflight and success criteria

Before Stage 1, require migrated schema, healthy `whatsapp` workers, empty unexpected backlog, working failed-job storage, active tenant/branch/profile/assignment, provider signature secret, reachable HTTPS callback, dashboard/log access, consented test recipient and a named rollback owner.

A stage succeeds only when signed inbound delivery resolves the intended tenant/branch, exactly one conversation/event/job/outbound reply is produced, provider acceptance and message ID are recorded, latency remains inside the agreed operational target, no secret or full phone is logged, and order/payment state remains unchanged except through existing verified NexDine services.

Monitor webhook accepted/rejected counts and error codes, queued/processed/failed events, outbound sent/failed status, attempt count, provider message ID, last successful webhook, queue depth and failed jobs. Stop expansion on tenant mismatch, duplicate business action/message, secret exposure, sustained provider failures, or unbounded queue age.

## Rollback order

1. Disable the tenant `whatsapp_ordering` entitlement and suspend its assignment/profile to stop new work.
2. Disable or redirect the provider webhook and stop only WhatsApp workers if unsafe processing continues.
3. Preserve queued/failed records for diagnosis; drain only verified safe jobs and do not silently discard them.
4. Deploy the known-good application version.
5. Preserve the additive UUID columns and existing data by default. Database rollback is exceptional and only after dependency/backup review.
6. Re-enable one internal tenant, verify inbound/outbound and provider message ID, then resume staged rollout.

## Recovery and post-rollback validation

Confirm the entitlement/profile/assignment remains disabled, provider callbacks no longer enqueue work, the WhatsApp queue is either safely drained or intentionally paused, no duplicate outbound/order/payment occurred, failed jobs and correlation evidence remain available, normal POS/KOT/payment paths are healthy, and the known-good application version is running. Communicate impact, affected tenant/branch, start/end time, safe error codes, owner and next decision to operations and the controlled restaurant contact.

Escalation evidence must include correlation ID, opaque assignment/profile reference, safe error code, event/message status and timestamps. Never attach credentials, authorization headers, raw customer payloads or unmasked phone numbers.

## Rehearsal evidence

- Disabled tenant, branch, profile and assignment were each rejected by webhook/queue runtime tests without producing outbound messages.
- Duplicate jobs returned without another outbound operation.
- A real database worker exhausted the job's three attempts and persisted one `failed_jobs` record while leaving zero live jobs.
- Serialized failed-job payload scanning found no access token, auth key, webhook secret, authorization, bearer or password marker.
- No production database, provider callback or live assignment was used.

## External provider prerequisites

- Meta: test phone-number ID, business account, access token, verify token, webhook secret, reachable HTTPS callback and subscribed message events.
- MSG91: test integrated number, account/auth key, webhook secret, API endpoint, reachable HTTPS callback and enabled inbound events.
- Both: isolated tenant/branch assignment, queue worker for `whatsapp`, failed-job storage, log/metric access and a test recipient with consent.
