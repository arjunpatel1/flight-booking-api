<#
  Starts the local dev stack on Windows: MySQL + Redis (if not running),
  php-cgi FastCGI workers, nginx, the queue worker and Reverb.
  Ctrl+C stops everything this script started (MySQL/Redis keep running).
  Logs: storage\logs\{queue,reverb}.log, storage\nginx\logs\error.log

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-local.ps1
    (or run start-local.cmd in the project root)
#>
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

function Get-EnvValue([string]$Key, [string]$Default) {
    $line = Get-Content .env | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
    if ($line) { return $line.Substring($Key.Length + 1).Trim('"') } else { return $Default }
}

$nginxPrefix = Join-Path $PWD 'storage\nginx'
if (-not (Test-Path (Join-Path $nginxPrefix 'conf\nginx.conf'))) {
    throw 'nginx is not configured yet. Run setup-local.cmd first.'
}

Start-IfDown 3306 mysqld
Start-IfDown 6379 redis-server

$appUrl = Get-EnvValue APP_URL 'http://api.nexdine.test'
$reverbPort = Get-EnvValue REVERB_SERVER_PORT '8080'
# Every queue the app dispatches to (mirrors config/horizon.php).
$queues = 'default,notifications,whatsapp,emails,mail,printing,provisioning,assets,delivery,monitoring,analytics,reports,backup'
$logs = 'storage\logs'
New-Item -ItemType Directory -Force -Path $logs | Out-Null

$php = (Get-Command php).Source
$phpCgi = Join-Path (Split-Path -Parent (& php -r 'echo PHP_BINARY;')) 'php-cgi.exe'
$phpIni = (& php -r 'echo php_ini_loaded_file();')
$nginx = (Get-Command nginx).Source
# Forward slashes: a trailing "\" before a quote would escape it on the command line.
$nginxPrefixArg = ($nginxPrefix -replace '\\', '/') + '/'
$nginxArgs = @('-p', $nginxPrefixArg, '-c', 'conf/nginx.conf')
$nginxStartArgs = @('-p', "`"$nginxPrefixArg`"", '-c', 'conf/nginx.conf')

# php-cgi exits after 500 requests by default; 0 keeps the workers alive.
$env:PHP_FCGI_MAX_REQUESTS = '0'

$procs = @()
foreach ($port in 9001..9004) {
    $cgiArgs = @('-b', "127.0.0.1:$port")
    if ($phpIni) { $cgiArgs = @('-c', "`"$phpIni`"") + $cgiArgs }
    $procs += Start-Process -FilePath $phpCgi -ArgumentList $cgiArgs -WindowStyle Hidden -PassThru
}

& $nginx @nginxArgs -t
if ($LASTEXITCODE -ne 0) { throw 'nginx config test failed. See output above.' }
$procs += Start-Process -FilePath $nginx -ArgumentList $nginxStartArgs -WorkingDirectory $nginxPrefix -WindowStyle Hidden -PassThru

$procs += Start-Process -FilePath $php -ArgumentList 'artisan', 'queue:listen', "--queue=$queues", '--tries=1' `
    -NoNewWindow -PassThru -RedirectStandardOutput "$logs\queue.log" -RedirectStandardError "$logs\queue.err.log"
$procs += Start-Process -FilePath $php -ArgumentList 'artisan', 'reverb:start', '--host=0.0.0.0', "--port=$reverbPort" `
    -NoNewWindow -PassThru -RedirectStandardOutput "$logs\reverb.log" -RedirectStandardError "$logs\reverb.err.log"

try {
    Start-Sleep 2
    Write-Host @"

  Running:
    API      $appUrl   (nginx + php-cgi)
    Reverb   ws://127.0.0.1:$reverbPort
    Queue    $queues
  Logs: $logs\queue.log, reverb.log, laravel.log, storage\nginx\logs\error.log

  Press Ctrl+C to stop.
"@ -ForegroundColor Green

    while ($true) {
        $dead = $procs | Where-Object { $_.HasExited } | Select-Object -First 1
        if ($dead) { Write-Host "A process exited. Check $logs\ and storage\nginx\logs\ for details." -ForegroundColor Red; break }
        Start-Sleep 2
    }
} finally {
    Write-Host 'Stopping...'
    try { & $nginx @nginxArgs -s quit } catch { }
    # /T also stops child processes (nginx workers, queue job processes).
    foreach ($p in $procs) { if (-not $p.HasExited) { & taskkill /T /F /PID $p.Id | Out-Null } }
}
