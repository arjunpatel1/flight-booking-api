<#
  Starts the local dev stack on Windows: MySQL + Redis (if not running),
  API server, queue worker and Reverb. Ctrl+C stops the PHP processes.
  Logs: storage\logs\{serve,queue,reverb}.log

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-local.ps1
    (or run start-local.cmd in the project root)
#>
param([int]$AppPort = 8000)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' +
            [Environment]::GetEnvironmentVariable('Path', 'User') + ";$env:USERPROFILE\scoop\shims"

function Test-Port([int]$Port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $client.Dispose() }
}

function Start-IfDown([int]$Port, [string]$Exe) {
    if (Test-Port $Port) { return }
    $cmd = Get-Command $Exe -ErrorAction SilentlyContinue
    if (-not $cmd) { Write-Host "WARNING: nothing on port $Port and $Exe not found." -ForegroundColor Yellow; return }
    Start-Process -FilePath $cmd.Source -WindowStyle Hidden
    for ($i = 0; $i -lt 20 -and -not (Test-Port $Port); $i++) { Start-Sleep 1 }
}

Start-IfDown 3306 mysqld
Start-IfDown 6379 redis-server

$reverbLine = Get-Content .env | Where-Object { $_ -match '^REVERB_SERVER_PORT=' } | Select-Object -First 1
$reverbPort = if ($reverbLine) { $reverbLine.Split('=')[1].Trim() } else { '8080' }

$logs = 'storage\logs'
New-Item -ItemType Directory -Force -Path $logs | Out-Null
$php = (Get-Command php).Source

function Start-Php([string]$Name, [string[]]$ArgList) {
    Start-Process -FilePath $php -ArgumentList $ArgList -NoNewWindow -PassThru `
        -RedirectStandardOutput "$logs\$Name.log" -RedirectStandardError "$logs\$Name.err.log"
}

$procs = @(
    (Start-Php 'serve'  @('artisan', 'serve', '--host=127.0.0.1', "--port=$AppPort")),
    (Start-Php 'queue'  @('artisan', 'queue:listen', '--tries=1')),
    (Start-Php 'reverb' @('artisan', 'reverb:start', '--host=0.0.0.0', "--port=$reverbPort"))
)

try {
    Start-Sleep 2
    Write-Host @"

  Running:
    API      http://127.0.0.1:$AppPort
    Reverb   ws://127.0.0.1:$reverbPort
    Queue    php artisan queue:listen
  Logs: $logs\serve.log, queue.log, reverb.log, laravel.log

  Press Ctrl+C to stop.
"@ -ForegroundColor Green

    while ($true) {
        $dead = $procs | Where-Object { $_.HasExited } | Select-Object -First 1
        if ($dead) { Write-Host "A process exited. Check $logs\ for details." -ForegroundColor Red; break }
        Start-Sleep 2
    }
} finally {
    Write-Host 'Stopping...'
    # /T also stops the child server that `artisan serve` spawns.
    foreach ($p in $procs) { if (-not $p.HasExited) { & taskkill /T /F /PID $p.Id | Out-Null } }
}
