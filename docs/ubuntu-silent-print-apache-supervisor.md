# Ubuntu Silent Print Setup

This guide is for Apache-hosted NexDine/POS deployments where thermal receipts are printed silently through a local CUPS queue and the Laravel print agent.

## Production Notes

- Prefer Ubuntu 24.04 LTS for production. The provided server is Ubuntu 25.04, which is already end-of-life in the login banner, so plan an OS upgrade before a busy outlet launch.
- Apache/PHP handles web requests. Supervisor must run long-lived workers: queue workers, Reverb for socket printing, and the local print agent.
- For POS-80 and similar ESC/POS thermal printers, configure the application printer as `spooler` with `raw=true`. This sends ESC/POS bytes directly to CUPS and avoids bad raster/PostScript conversion.
- If a printer queue is mapped to an unrelated driver, for example HP Designjet/PostScript, manual OS printing can be faint or oversized. Raw silent printing bypasses that driver, but the queue still must point to the correct USB/network device.

## One Command Installer

Run this on the machine that has physical/network access to the printer.

```bash
cd /var/www/restaurant-pos-api
sudo AGENT_ID=AGENT-6a143a8d421a2 \
  BRANCH_ID=1 \
  SERVER_URL=https://api.example.com/api/v1 \
  AGENT_SECRET=agent-secret-from-admin \
  APP_DIR=/var/www/restaurant-pos-api \
  APP_USER=www-data \
  PRINTER_QUEUE=POS-80 \
  bash scripts/install-silent-print-agent-ubuntu.sh
```

Set `AGENT_ID` and `AGENT_SECRET` to the active Print Agent configured in the admin panel. Set `SERVER_URL` to the production API base URL, including `/api/v1`. Set `BRANCH_ID` and `PRINTER_QUEUE` for the outlet terminal.

The installer:

- installs CUPS, Supervisor, Chromium, PHP extensions, and fonts needed by receipt rendering;
- enables CUPS and Supervisor;
- gives the Apache user access to printer groups;
- creates `/etc/supervisor/conf.d/nexdine-print-agent.conf`;
- starts the local print agent under Supervisor/systemd. Use socket mode for fast printing and no continuous poll API loop.

## Apache Server Checklist

Install/enable normal Laravel runtime services separately:

```bash
sudo apt-get install -y apache2 php-fpm php-cli php-mysql php-redis php-gd php-curl php-mbstring php-xml php-zip unzip
sudo a2enmod rewrite proxy_fcgi setenvif
sudo systemctl enable --now apache2
```

Recommended Laravel deployment commands:

```bash
cd /var/www/restaurant-pos-api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

Supervisor should also run a dedicated fast worker for receipt/KOT rendering:

```ini
[program:nexdine-print-queue]
directory=/var/www/restaurant-pos-api
command=/usr/bin/php artisan queue:work redis --queue=pos-print-high --sleep=0 --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexdine/print-queue.log
```

Keep the normal application queue separate:

```ini
[program:nexdine-queue]
directory=/var/www/restaurant-pos-api
command=/usr/bin/php artisan queue:work redis --queue=default,notifications --sleep=1 --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexdine/queue.log
```

If you cannot run a separate worker, `pos-print-high` must still be first:

```ini
command=/usr/bin/php artisan queue:work redis --queue=pos-print-high,default,notifications --sleep=0 --tries=3 --timeout=90
```

For fast socket printing, add a separate supervised `php artisan reverb:start` process.

Example:

```ini
[program:nexdine-reverb]
directory=/var/www/restaurant-pos-api
command=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexdine/reverb.log
```

## Printer Queue Setup

Check available devices:

```bash
lpinfo -v
lpstat -p -d -v
```

For many POS-80 USB printers, CUPS raw queue setup is:

```bash
sudo lpadmin -p POS-80 -E -v usb://Printer/POS-80?serial=012345678AB -m raw
sudo lpoptions -p POS-80 -o raw
sudo cupsenable POS-80
sudo cupsaccept POS-80
```

Verify:

```bash
lpstat -p POS-80 -l
lpoptions -p POS-80
```

## Application Printer Settings

In the POS admin printer setup:

- Connection type: `spooler`
- Queue/name: `POS-80`
- Paper size: `80mm`
- Raw: `true`
- Color mode: `mono`
- Copies: `1`
- Timeout: `30000 ms`
- Assign the printer to the correct register for bill/invoice/waiter/delivery and to kitchen stations for KOT.

If paper feeds too much, verify the current code uses `.paper` element capture and not full-page screenshot capture. Also check printer settings for:

- `copies=1`
- no duplicate bill/invoice printer mapping on the same register unless intentionally needed
- one active print agent per physical terminal/queue
- no browser preview print being triggered in addition to silent print

## Commands For Operations

Restart print agent:

```bash
sudo supervisorctl restart nexdine-print-agent
```

View logs:

```bash
sudo tail -f /var/log/nexdine/print-agent.log
```

Run one fallback manual poll:

```bash
cd /var/www/restaurant-pos-api
php artisan printer:agent-work \
  --agent_id=AGENT-6a143a8d421a2 \
  --branch_id=1 \
  --server_url=https://api.example.com/api/v1 \
  --agent_secret=agent-secret-from-admin \
  --sleep=0.2 --max_sleep=5 --long_poll=20 \
  --once
```

`--server_url` and `--agent_secret` can also be provided through environment variables:

```bash
PRINT_AGENT_SERVER_URL=https://api.example.com/api/v1 \
PRINT_AGENT_SECRET=agent-secret-from-admin \
php artisan printer:agent-work \
  --agent_id=AGENT-6a143a8d421a2 \
  --branch_id=1 \
  --sleep=0.2 --max_sleep=5 --long_poll=20 \
  --once
```

The log must show:

- `Agent started`
- `Polling server URL`
- `Job found`
- `Queue name used`
- `lp command result`
- `Job marked completed`

Check stuck jobs:

```bash
lpstat -o POS-80
php artisan tinker --execute='Modules\Printer\Models\PrintJob::latest()->limit(5)->get(["id","status","claimed_by","error_message","completed_at"])->each(fn($j)=>print(json_encode($j->toArray()).PHP_EOL));'
```

## Acceptance Test

1. Print a bill from POS using Direct Print.
2. Confirm one physical receipt only.
3. Check `print_jobs.status=success`.
4. Check `lpstat -o POS-80` is empty.
5. Use Submit & Print from payment and confirm it does not open browser print.
