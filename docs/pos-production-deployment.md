# POS Production Deployment Gate

This profile is required before enabling busy-outlet POS, KDS sockets or local silent printing.

## Required Environment

```env
APP_ENV=production
APP_DEBUG=false

CACHE_ENABLED=true
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

BROADCAST_CONNECTION=reverb
REVERB_SCALING_ENABLED=true

REDIS_CLIENT=phpredis
REDIS_HOST=<redis-host>
REDIS_PORT=6379
REDIS_PASSWORD=<secret>
REDIS_CACHE_DB=1
```

Do not use `file` cache/session or `database` queues for a multi-terminal production outlet.
The PHP `redis` extension must be installed before switching these drivers.

## Deployment Sequence

```bash
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan about --only=environment,cache,drivers
```

Run supervised processes for:

```bash
php artisan queue:work redis --queue=pos-print-high,default,notifications --sleep=1 --tries=3 --timeout=90
php artisan reverb:start
```

`pos-print-high` must be first in the worker queue list. POS submit/payment requests enqueue bill,
invoice, and KOT rendering there so the cashier gets a fast response while printing continues in
the background.

Run multiple web application workers through PHP-FPM or Octane. Do not certify load capacity using a single `php artisan serve` worker.

## Release Verification

```bash
REAL_DB_SMOKE=1 php artisan test tests/Feature/RealDataApiSmokeTest.php tests/Feature/ApiAuthenticationResponseTest.php
npm run type-check
```

Run `tests/Load/pos-api-read-load.mjs` against the deployed API using registered terminal tokens. Accept release only when:

- HTTP error and timeout count is zero.
- Warm POS read p95 is within the agreed outlet SLA.
- Queue backlog remains stable during the run.
- Reverb receives order/table/notification/print-agent updates without periodic API polling.

## Printing Safety

Print dispatch requires `Idempotency-Key`. A local print agent claims each job once. If output status is unknown because an agent lost its acknowledgement, the job remains `awaiting_agent_report`; a manager must confirm before retrying to avoid duplicate invoice or KOT printing.

### Thermal Printer Workstation Gate

For an 80 mm USB thermal printer such as POS-80:

- Install the manufacturer's ESC/POS-capable CUPS driver or the approved local print-agent driver on each terminal workstation.
- Configure the application printer as `spooler` with the exact operating-system queue name (for example `POS-80`) when the agent prints rendered receipt images through CUPS.
- Do not use an unrelated CUPS driver such as an HP DesignJet/PostScript queue for an ESC/POS thermal printer. It can cause faint output, incorrect paper width, excess feed or no output.
- Do not select `usb_raw` unless the deployed local agent explicitly converts the rendered receipt to ESC/POS raster commands before writing to the USB endpoint.
- Keep a supervised local print-agent process running on every workstation that must perform silent printing. An active printer record without an online agent only creates queued jobs; it does not print paper.
- Upload a high-contrast monochrome logo in appearance settings and verify `appearance_print_terms` before outlet activation.

Acceptance check at each terminal:

```bash
lpstat -p -d -v
lpoptions -p POS-80
```

The queue must identify the correct receipt printer driver and the application Print Jobs diagnostics page must show an online agent before silent print is enabled.

Preferred print-agent mode is socket/Reverb. The agent keeps one WebSocket open and calls the poll API only when a `print.job.created` event arrives or after reconnect recovery.

For customer terminals, use the standalone agent docs:

```bash
docs/windows-silent-print-agent.md
docs/ubuntu-standalone-print-agent.md
```

Fallback manual poll for a workstation with this application checkout and direct access to the configured printer:

```bash
php artisan printer:agent-work \
  --agent_id=<workstation-agent-id> \
  --branch_id=<branch-id> \
  --server_url=https://api.example.com/api/v1 \
  --agent_secret=<workstation-agent-secret> \
  --sleep=0.2 --max_sleep=5 --long_poll=20
```

For POS-80 through a CUPS queue, use the `spooler` connection with `raw` enabled so the ESC/POS receipt payload bypasses incorrect raster/PostScript conversion.

For the complete Ubuntu + Apache + Supervisor install flow, including the one-command installer, use:

```bash
docs/ubuntu-silent-print-apache-supervisor.md
```

If receipt paper feeds too much, verify the deployed code renders only the `.paper` receipt element and does not use full-page screenshot capture for thermal ESC/POS payloads.
