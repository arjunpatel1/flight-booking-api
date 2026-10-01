# Reverb Print Delivery

NexDine print delivery supports two transports:

- `PRINT_TRANSPORT=polling`: agents use long polling only.
- `PRINT_TRANSPORT=reverb`: backend broadcasts a private wake-up event to the assigned agent; the agent then polls immediately to claim and print the job. Long polling remains the fallback.

## Backend Flow

1. A bill, invoice, waiter, kitchen, or delivery print action creates a `print_jobs` row.
2. The job stores `branch_id`, `printer_config.agent_id`, printer connection details, and rendered bytes.
3. If `PRINT_TRANSPORT=reverb` and the job has an assigned `agent_id`, the backend broadcasts `print.job.created`.
4. The event is sent only to `private-agent.{agent_id}`.
5. The event payload contains only job metadata. The agent must fetch/claim the job through `POST /api/v1/agents/{agent_id}/poll`.

## Agent Authentication

Agents authenticate HTTP requests with:

- `X-Agent-ID`
- `X-Signature`

The signature is:

```text
HMAC_SHA256("{agent_id}:{json_payload}", agent_secret)
```

WebSocket private channel auth uses:

```http
POST /api/v1/agents/{agent_id}/broadcasting/auth
```

Payload:

```json
{
  "socket_id": "123.456",
  "channel_name": "private-agent.AGENT-ID"
}
```

The endpoint only authorizes the authenticated agent for its own channel.

## Tenant Isolation

- Events are broadcast only to `private-agent.{printer_config.agent_id}`.
- Agent auth rejects subscription to any other agent channel.
- Polling requires a valid agent signature.
- Polling rejects branch mismatch.
- A claimed job cannot be completed by another agent.

## Agent Behavior

1. Connect to Reverb.
2. Subscribe to `private-agent.{agent_id}`.
3. On `print.job.created`, call `poll` immediately.
4. Print returned jobs.
5. Report success/failure.
6. If socket fails, reconnect and continue long polling fallback.

## Required Env

```env
BROADCAST_CONNECTION=reverb
PRINT_TRANSPORT=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=...
REVERB_PORT=443
REVERB_SCHEME=https
```

For polling-only deployments:

```env
PRINT_TRANSPORT=polling
```

## Local Startup

Start the Reverb socket server and Octane together from the backend root:

```bash
cd restaurant-pos-api
composer realtime:start
```

The startup script loads local `.env` values if present and uses these defaults:

- `REVERB_HOST` default: `127.0.0.1`
- `REVERB_PORT` default: `6001`
- `OCTANE_SERVER` default: `frankenphp`
- `OCTANE_HOST` default: `127.0.0.1`
- `OCTANE_PORT` default: `8000`

## Production Checks

```bash
php artisan config:clear
php artisan cache:clear
php artisan reverb:restart
php artisan queue:restart
```

Then trigger a print and verify:

```bash
tail -f storage/logs/laravel.log | grep -i "print job"
php artisan tinker --execute="dump(DB::table('print_jobs')->orderByDesc('created_at')->limit(5)->get(['id','branch_id','status','claimed_by','completed_at','printer_config'])->toArray());"
```
