# Windows Silent Print Agent Setup

Use this setup on the customer POS computer, not on the cloud server. The cloud server only creates print jobs. The Windows agent receives the job and prints to local Windows printer queues.

## Recommended Flow

Use socket mode for fast printing:

```text
Print job created -> Reverb event -> Windows agent wakes -> agent fetches job -> local printer prints -> job marked success
```

In socket mode there is no continuous poll API loop. The agent keeps one WebSocket connection open and calls the poll API only when a print event arrives or after reconnect recovery.

If `SocketUrl` is not configured, the agent uses long-poll fallback. That still works, but it will make periodic API requests.

## Files Needed

Copy only these files to the Windows POS machine:

- `tools/print-agent/windows/NexDinePrintAgent.ps1`
- `tools/print-agent/windows/install-nexdine-print-agent.ps1`

Do not copy the Laravel project to the POS machine.

## Printer Rules

For multiple printers, keep `PrinterName` blank during install. Then each print job uses the printer queue configured in the admin printer record.

In admin printer settings, set:

- Connection type: `spooler`
- Spooler name: exact Windows printer queue name
- Raw: `true`
- Agent ID: this terminal's agent ID
- Paper size: `80mm` or `58mm`

Example:

```json
{
  "spooler_name": "Periperi",
  "agent_id": "C1234",
  "raw": true,
  "copies": 1,
  "paper_size": "80mm"
}
```

For two printers on one Windows POS:

- Bill printer spooler name: `Periperi`
- Waiter/KOT printer spooler name: `TVS`
- Both printer records can use the same agent ID if both printers are connected to the same POS machine.

## Before Install

Open PowerShell as Administrator and check printer queues:

```powershell
Get-Printer | Format-Table Name, PrinterStatus, PortName
```

The `Name` must match the admin printer `spooler_name`.

The printer must be installed as a machine-wide Windows printer because the scheduled task runs as `SYSTEM`.

## Socket URL

Build the socket URL from Reverb settings:

```text
wss://REVERB_HOST/app/REVERB_APP_KEY?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false
```

Use `ws://` for non-TLS local testing and `wss://` for production HTTPS.

## Install

Run PowerShell as Administrator:

```powershell
cd C:\NexDinePrintAgent

powershell.exe -ExecutionPolicy Bypass -File .\install-nexdine-print-agent.ps1 `
  -ServerUrl "https://your-api-domain.com/api/v1" `
  -AgentId "C1234" `
  -AgentSecret "<agent-secret-from-admin>" `
  -BranchId 1 `
  -SocketUrl "wss://your-reverb-domain.com/app/your-reverb-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false"
```

Do not pass `-PrinterName` for multiple-printer setups.

Use `-PrinterName "POS-80"` only for a simple single-printer terminal where every job must go to the same queue.

The installer creates:

```text
Scheduled Task: NexDine Print Agent
Config: C:\ProgramData\NexDine\PrintAgent\config.json
Log: C:\ProgramData\NexDine\PrintAgent\print-agent.log
```

## Manual Test

Socket mode:

```powershell
powershell.exe -ExecutionPolicy Bypass -File C:\ProgramData\NexDine\PrintAgent\NexDinePrintAgent.ps1 `
  -ServerUrl "https://your-api-domain.com/api/v1" `
  -AgentId "C1234" `
  -AgentSecret "<agent-secret-from-admin>" `
  -BranchId 1 `
  -SocketUrl "wss://your-reverb-domain.com/app/your-reverb-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false"
```

Fallback one-time poll:

```powershell
powershell.exe -ExecutionPolicy Bypass -File C:\ProgramData\NexDine\PrintAgent\NexDinePrintAgent.ps1 `
  -ServerUrl "https://your-api-domain.com/api/v1" `
  -AgentId "C1234" `
  -AgentSecret "<agent-secret-from-admin>" `
  -BranchId 1 `
  -Once
```

Expected socket logs:

- `agent started`
- `connecting socket`
- `socket subscribed`
- `socket event received`
- `job found`
- `job printer`
- `queue name used`
- `windows spooler result: submitted`
- `job marked completed`

## Operations

Check task:

```powershell
Get-ScheduledTask -TaskName "NexDine Print Agent"
Get-ScheduledTaskInfo -TaskName "NexDine Print Agent"
```

Restart:

```powershell
Stop-ScheduledTask -TaskName "NexDine Print Agent"
Start-ScheduledTask -TaskName "NexDine Print Agent"
```

View logs:

```powershell
Get-Content C:\ProgramData\NexDine\PrintAgent\print-agent.log -Tail 100 -Wait
```

Uninstall:

```powershell
Unregister-ScheduledTask -TaskName "NexDine Print Agent" -Confirm:$false
```

## Troubleshooting

- Continuous API calls are still showing: `SocketUrl` is blank or socket connection is failing, so fallback is active.
- Socket does not connect: check Reverb is running, URL uses correct `ws`/`wss`, firewall/proxy allows WebSockets, and app key is correct.
- Job remains pending: task stopped, wrong API URL, wrong secret, wrong branch, or printer job targets another agent ID.
- Job fails after claim: Windows queue name is wrong, printer is offline, or driver blocks raw ESC/POS.
- Wrong printer prints: keep `PrinterName` blank and fix each printer record's `spooler_name`.
- Duplicate print: check browser print is not also triggered and retry actions use idempotency keys.
