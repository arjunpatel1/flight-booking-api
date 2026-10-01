# Ubuntu Standalone Silent Print Agent

Use this setup on the customer POS machine that has access to the printer. Do not install printer detection or CUPS on the cloud server.

## Recommended Flow

Use socket mode for fastest printing:

```text
Print job created -> Reverb event -> Ubuntu agent wakes -> agent fetches job -> local printer prints -> job marked success
```

In socket mode there is no continuous poll API loop. The agent keeps one WebSocket connection open and calls the poll API only when a print event arrives or after reconnect recovery.

If `SOCKET_URL` is not configured, the agent uses long-poll fallback. That still works, but it will make periodic API requests.

## Files Needed

Copy only this folder to the Ubuntu POS machine:

```text
tools/print-agent/ubuntu
```

Files:

- `nexdine-print-agent.py`
- `install-nexdine-print-agent.sh`

## Requirements

```bash
sudo apt-get update
sudo apt-get install -y cups cups-client python3
```

For socket mode, install the Python WebSocket client:

```bash
sudo apt-get install -y python3-websocket
```

If that package is unavailable on your OS, install `websocket-client` using your approved Python package method.

## Printer Queue Setup

Check local printers:

```bash
lpstat -v
lpstat -p
lpinfo -v
```

Example CUPS raw queue:

```bash
sudo lpadmin -p POS-80 -E -v usb://Printer/POS-80?serial=012345678AB -m raw
sudo lpoptions -p POS-80 -o raw
sudo cupsenable POS-80
sudo cupsaccept POS-80
```

Verify:

```bash
lpstat -p POS-80 -l
```

Expected:

```text
printer POS-80 is idle
```

## Application Printer Rules

In admin printer settings:

- Connection type: `spooler`
- Spooler name: exact CUPS queue name
- Raw: `true`
- Agent ID: this terminal's agent ID
- Paper size: `80mm` or `58mm`

For multiple printers on the same Ubuntu terminal:

- Create one CUPS queue per printer.
- Create one app printer record per queue.
- Use the same agent ID if one local agent controls all those queues.
- Leave `PRINTER_QUEUE` blank during install so each job uses its assigned queue.

## Socket URL

Build the socket URL from Reverb settings:

```text
wss://REVERB_HOST/app/REVERB_APP_KEY?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false
```

Use `ws://` for non-TLS local testing and `wss://` for production HTTPS.

## Install

```bash
cd /path/to/tools/print-agent/ubuntu

sudo SERVER_URL=https://your-api-domain.com/api/v1 \
  AGENT_ID=C1234 \
  AGENT_SECRET='<agent-secret-from-admin>' \
  BRANCH_ID=1 \
  SOCKET_URL='wss://your-reverb-domain.com/app/your-reverb-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false' \
  bash install-nexdine-print-agent.sh
```

Do not set `PRINTER_QUEUE` for multiple-printer setups.

Set `PRINTER_QUEUE=POS-80` only for a simple single-printer terminal where every job must go to the same queue.

The installer creates:

```text
Service: nexdine-print-agent.service
Config: /etc/nexdine-print-agent/agent.env
Log: /var/log/nexdine-print-agent/print-agent.log
```

## Manual Test

Socket mode:

```bash
NEXDINE_PRINT_SERVER_URL=https://your-api-domain.com/api/v1 \
NEXDINE_PRINT_AGENT_ID=C1234 \
NEXDINE_PRINT_AGENT_SECRET='<agent-secret-from-admin>' \
NEXDINE_PRINT_BRANCH_ID=1 \
NEXDINE_PRINT_SOCKET_URL='wss://your-reverb-domain.com/app/your-reverb-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false' \
python3 nexdine-print-agent.py --log-path ./print-agent-test.log
```

Fallback one-time poll:

```bash
NEXDINE_PRINT_SERVER_URL=https://your-api-domain.com/api/v1 \
NEXDINE_PRINT_AGENT_ID=C1234 \
NEXDINE_PRINT_AGENT_SECRET='<agent-secret-from-admin>' \
NEXDINE_PRINT_BRANCH_ID=1 \
python3 nexdine-print-agent.py --once --log-path ./print-agent-test.log
```

Expected socket logs:

- `agent started`
- `connecting socket`
- `socket subscribed`
- `socket event received`
- `job found`
- `queue name used`
- `lp command result`
- `job marked completed`

## Operations

Status:

```bash
systemctl status nexdine-print-agent --no-pager -l
```

Logs:

```bash
tail -f /var/log/nexdine-print-agent/print-agent.log
```

Restart:

```bash
sudo systemctl restart nexdine-print-agent
```

Uninstall:

```bash
sudo systemctl disable --now nexdine-print-agent
sudo rm -f /etc/systemd/system/nexdine-print-agent.service
sudo rm -rf /opt/nexdine-print-agent /etc/nexdine-print-agent /var/log/nexdine-print-agent
sudo systemctl daemon-reload
```

## Troubleshooting

- Continuous API calls are still showing: `SOCKET_URL` is blank or socket connection is failing, so fallback is active.
- Socket mode says `websocket-client is required`: install `python3-websocket` or approved `websocket-client` package.
- Job remains pending: service stopped, wrong API URL, wrong secret, wrong branch, or job targets another agent ID.
- Job fails after claim: CUPS queue is wrong/offline or printer cannot accept raw ESC/POS.
- Wrong printer prints: leave `PRINTER_QUEUE` blank and fix each printer record's `spooler_name`.
- Duplicate print: use unique idempotency keys and make sure browser print is not also triggered.
