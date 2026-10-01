<#
  One-command local setup for Windows 10/11.

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\setup-local.ps1
    (or double-click / run setup-local.cmd in the project root)

  Only Git is needed beforehand. Installs PHP 8.4 (+ extensions), Composer,
  nginx, MySQL, Redis and Node.js via Scoop when missing, configures nginx and
  Reverb, adds the local domains to the hosts file (one UAC prompt), creates
  .env and the database, runs migrations + seeders, provisions a demo tenant,
  prints the login details and starts nginx, PHP, the queue worker and Reverb
  (scripts\start-local.ps1). Safe to re-run: every step is idempotent.

  If a MySQL server is already listening on 3306 (XAMPP, Laragon, MySQL
  Installer...) it is reused; pass its root password with -MysqlRootPassword.
#>
param(
    [string]$DbDatabase = 'nexdine',
    [string]$DbUsername = 'nexdine',
    [string]$DbPassword = 'secret',
    [string]$MysqlRootPassword = '',
    [string]$RootDomain = 'nexdine.test',
    [int]$HttpPort = 80,
    [int]$ReverbPort = 8080,
    [string]$TenantSlug = 'demo',
    [string]$TenantPassword = 'Demo@12345',
    [switch]$SkipNpm,
    [switch]$Fresh,
    [switch]$NoStart
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-Location (Split-Path -Parent $PSScriptRoot)

$ApiDomain = "api.$RootDomain"
$TenantDomain = "$TenantSlug.$RootDomain"
$TenantEmail = "admin@$TenantDomain"
$PortSuffix = if ($HttpPort -eq 80) { '' } else { ":$HttpPort" }
$AppUrl = "http://$ApiDomain$PortSuffix"

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Warn($msg) { Write-Host "WARNING: $msg" -ForegroundColor Yellow }
function Has($cmd) { [bool](Get-Command $cmd -ErrorAction SilentlyContinue) }

# Runs a native command and stops the script when it fails.
function Run {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Cmd)
    & $Cmd[0] @($Cmd | Select-Object -Skip 1)
    if ($LASTEXITCODE -ne 0) { throw "Command failed ($LASTEXITCODE): $($Cmd -join ' ')" }
}

function Test-Port([int]$Port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $client.Dispose() }
}

function Refresh-Path {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' +
                [Environment]::GetEnvironmentVariable('Path', 'User')
}

# Writes KEY=VALUE into .env, replacing an existing (or commented) line.
function Set-Env([string]$Key, [string]$Value) {
    $lines = [IO.File]::ReadAllLines("$PWD\.env")
    $pattern = "^#?\s*$([regex]::Escape($Key))="
    $found = $false
    $lines = $lines | ForEach-Object {
        if (-not $found -and $_ -match $pattern) { $found = $true; "$Key=$Value" } else { $_ }
    }
    if (-not $found) { $lines += "$Key=$Value" }
    [IO.File]::WriteAllLines("$PWD\.env", [string[]]$lines)
}

function Get-EnvValue([string]$Key) {
    $line = Get-Content .env | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
    if ($line) { return $line.Substring($Key.Length + 1).Trim('"') } else { return '' }
}

function Php-Version {
    if (-not (Has php)) { return [version]'0.0' }
    return [version](& php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
}

# --- Scoop + tools -----------------------------------------------------------
Refresh-Path
if (-not (Has scoop)) {
    Step 'Installing Scoop package manager'
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
    $installer = Invoke-RestMethod -Uri 'https://get.scoop.sh'
    if ($isAdmin) { & ([scriptblock]::Create($installer)) -RunAsAdmin } else { & ([scriptblock]::Create($installer)) }
    Refresh-Path
    $env:Path = "$env:USERPROFILE\scoop\shims;$env:Path"
}

if (-not (Has git)) { Step 'Installing Git'; Run scoop install git }

if ((Php-Version) -lt [version]'8.4') {
    Step 'Installing PHP 8.4'
    scoop bucket add versions | Out-Null
    scoop install php84
    if ($LASTEXITCODE -ne 0) { Run scoop install php }
}

if (-not (Has composer)) { Step 'Installing Composer'; Run scoop install composer }

if (-not (Has redis-server)) { Step 'Installing Redis'; Run scoop install redis }

if (-not (Has nginx)) { Step 'Installing nginx'; Run scoop install nginx }

if (-not $SkipNpm -and -not (Has node)) { Step 'Installing Node.js LTS'; Run scoop install nodejs-lts }

# --- PHP extensions ----------------------------------------------------------
Step 'Enabling PHP extensions'
$phpDir = Split-Path -Parent (& php -r 'echo PHP_BINARY;')
$phpIni = (& php -r 'echo php_ini_loaded_file();')
if (-not $phpIni) {
    $phpIni = Join-Path $phpDir 'php.ini'
    Copy-Item (Join-Path $phpDir 'php.ini-development') $phpIni
}
$ini = [IO.File]::ReadAllText($phpIni)
if ($ini -notmatch '(?m)^\s*extension_dir\s*=') {
    $ini += "`r`nextension_dir = `"$(Join-Path $phpDir 'ext')`"`r`n"
}
$extensions = 'bcmath', 'curl', 'exif', 'fileinfo', 'gd', 'gmp', 'intl', 'mbstring', 'openssl',
              'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'sockets', 'zip'
foreach ($ext in $extensions) {
    if ($ini -match "(?m)^\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") { continue }
    if ($ini -match "(?m)^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") {
        $ini = [regex]::Replace($ini, "(?m)^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$", "extension=$ext")
    } elseif (Test-Path (Join-Path $phpDir "ext\php_$ext.dll")) {
        $ini += "`r`nextension=$ext"
    }
}
[IO.File]::WriteAllText($phpIni, $ini)
& php -m | Out-Null

# --- MySQL -------------------------------------------------------------------
if (-not (Test-Port 3306)) {
    if (-not (Has mysqld)) { Step 'Installing MySQL'; Run scoop install mysql }
    Step 'Starting MySQL'
    $mysqld = (Get-Command mysqld).Source
    Start-Process -FilePath $mysqld -WindowStyle Hidden
    $up = $false
    for ($i = 0; $i -lt 30 -and -not $up; $i++) { Start-Sleep 1; $up = Test-Port 3306 }
    if (-not $up) {
        # First run on a blank data directory: initialise it (root, no password).
        & $mysqld --initialize-insecure
        Start-Process -FilePath $mysqld -WindowStyle Hidden
        for ($i = 0; $i -lt 30 -and -not $up; $i++) { Start-Sleep 1; $up = Test-Port 3306 }
    }
    if (-not $up) { throw 'MySQL did not start on port 3306. Start it manually and re-run this script.' }
}

# --- Redis -------------------------------------------------------------------
if (-not (Test-Port 6379)) {
    Step 'Starting Redis'
    Start-Process -FilePath (Get-Command redis-server).Source -WindowStyle Hidden
    $up = $false
    for ($i = 0; $i -lt 15 -and -not $up; $i++) { Start-Sleep 1; $up = Test-Port 6379 }
    if (-not $up) { Warn 'Redis did not start on port 6379. Run redis-server manually.' }
}

Step "Creating MySQL database '$DbDatabase' and user '$DbUsername'"
$sql = @"
CREATE DATABASE IF NOT EXISTS ``$DbDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DbUsername'@'localhost' IDENTIFIED BY '$DbPassword';
CREATE USER IF NOT EXISTS '$DbUsername'@'127.0.0.1' IDENTIFIED BY '$DbPassword';
ALTER USER '$DbUsername'@'localhost' IDENTIFIED BY '$DbPassword';
ALTER USER '$DbUsername'@'127.0.0.1' IDENTIFIED BY '$DbPassword';
GRANT ALL PRIVILEGES ON ``$DbDatabase``.* TO '$DbUsername'@'localhost';
GRANT ALL PRIVILEGES ON ``$DbDatabase``.* TO '$DbUsername'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
$env:MYSQL_PWD = $MysqlRootPassword
$sql | & mysql -u root -h 127.0.0.1 -P 3306
$mysqlExit = $LASTEXITCODE
Remove-Item Env:\MYSQL_PWD
if ($mysqlExit -ne 0) { throw 'Could not create the database. Pass the MySQL root password with -MysqlRootPassword.' }

# --- Application -------------------------------------------------------------
Step 'Configuring .env'
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
Set-Env APP_ENV local
Set-Env APP_DEBUG true
Set-Env APP_URL $AppUrl
Set-Env APP_INSTALLED true
Set-Env PUBLIC_DOMAIN $RootDomain
Set-Env API_DOMAIN $ApiDomain
Set-Env SAAS_ROOT_DOMAIN $RootDomain
Set-Env SAAS_CENTRAL_DOMAINS "localhost,127.0.0.1,$RootDomain,$ApiDomain"
Set-Env DB_CONNECTION mysql
Set-Env DB_HOST 127.0.0.1
Set-Env DB_PORT 3306
Set-Env DB_DATABASE $DbDatabase
Set-Env DB_USERNAME $DbUsername
Set-Env DB_PASSWORD $DbPassword
# The phpredis DLL is not bundled with PHP on Windows; predis is a Composer dependency.
Set-Env REDIS_CLIENT predis
Set-Env REDIS_HOST 127.0.0.1
Set-Env REDIS_PORT 6379
Set-Env BROADCAST_CONNECTION reverb
Set-Env REVERB_HOST 127.0.0.1
Set-Env REVERB_PORT $ReverbPort
Set-Env REVERB_SCHEME http
Set-Env REVERB_SERVER_HOST 0.0.0.0
Set-Env REVERB_SERVER_PORT $ReverbPort
Set-Env REVERB_ALLOWED_ORIGINS "localhost,127.0.0.1,$RootDomain,$ApiDomain,$TenantDomain"
if (-not (Get-EnvValue QR_SECRET)) { Set-Env QR_SECRET (& php -r 'echo bin2hex(random_bytes(32));') }

Step 'Installing Composer dependencies'
# pcntl/posix do not exist on Windows; they are only needed by Horizon workers.
Run composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix

if (-not (Get-EnvValue APP_KEY)) { Run php artisan key:generate --force }

'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views',
'storage\framework\testing', 'storage\logs', 'bootstrap\cache' |
    ForEach-Object { New-Item -ItemType Directory -Force -Path $_ | Out-Null }

Run php artisan config:clear

Step 'Running migrations and seeders'
if ($Fresh) { Run php artisan migrate:fresh --force } else { Run php artisan migrate --force }
Run php artisan db:seed --force
Run php artisan permission:sync-permissions
Run php artisan permission:sync-default-roles --force

if (-not (Test-Path public\storage)) { & php artisan storage:link }

Step "Provisioning tenant '$TenantSlug' ($TenantDomain)"
Run php scripts\local-tenant.php $TenantSlug $TenantDomain $TenantEmail $TenantPassword

if (-not $SkipNpm) {
    Step 'Installing Node dependencies (Puppeteer)'
    & npm install
    if ($LASTEXITCODE -ne 0) { Warn 'npm install failed; PDF/screenshot features need Puppeteer. Re-run: npm install' }
}

& php artisan optimize:clear | Out-Null

# --- nginx -------------------------------------------------------------------
# A project-local nginx prefix (storage\nginx) so nothing global is touched.
# start-local.ps1 runs it together with php-cgi FastCGI workers on 9001-9004.
Step "Configuring nginx for $ApiDomain, $RootDomain and *.$RootDomain"
$nginxPrefix = Join-Path $PWD 'storage\nginx'
foreach ($dir in 'conf', 'logs', 'temp\client_body_temp', 'temp\proxy_temp', 'temp\fastcgi_temp', 'temp\uwsgi_temp', 'temp\scgi_temp') {
    New-Item -ItemType Directory -Force -Path (Join-Path $nginxPrefix $dir) | Out-Null
}
$nginxHome = (& scoop prefix nginx).Trim()
Copy-Item (Join-Path $nginxHome 'conf\mime.types') (Join-Path $nginxPrefix 'conf') -Force
Copy-Item (Join-Path $nginxHome 'conf\fastcgi_params') (Join-Path $nginxPrefix 'conf') -Force
$publicDir = (Join-Path $PWD 'public') -replace '\\', '/'
$nginxConf = @"
worker_processes 1;
error_log logs/error.log;
pid logs/nginx.pid;

events { worker_connections 1024; }

http {
    include mime.types;
    default_type application/octet-stream;
    sendfile off;
    access_log logs/access.log;
    client_max_body_size 64m;

    upstream php_cgi {
        server 127.0.0.1:9001;
        server 127.0.0.1:9002;
        server 127.0.0.1:9003;
        server 127.0.0.1:9004;
    }

    server {
        listen $HttpPort;
        server_name $RootDomain $ApiDomain *.$RootDomain localhost;
        root "$publicDir";
        index index.php;
        charset utf-8;

        location / {
            try_files `$uri `$uri/ /index.php?`$query_string;
        }

        location ~ \.php$ {
            fastcgi_pass php_cgi;
            fastcgi_param SCRIPT_FILENAME `$document_root`$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_hide_header X-Powered-By;
            fastcgi_read_timeout 300;
        }

        location ~ /\.(?!well-known).* {
            deny all;
        }
    }
}
"@
[IO.File]::WriteAllText((Join-Path $nginxPrefix 'conf\nginx.conf'), $nginxConf)

Step 'Adding local domains to the hosts file'
$hostsFile = "$env:SystemRoot\System32\drivers\etc\hosts"
$hostsText = Get-Content $hostsFile -Raw
$missing = @($RootDomain, $ApiDomain, $TenantDomain) |
    Where-Object { $hostsText -notmatch "(?m)^\s*127\.0\.0\.1\s+.*\b$([regex]::Escape($_))\b" }
if ($missing) {
    $lines = ($missing | ForEach-Object { "127.0.0.1 $_" }) -join "`r`n"
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
    if ($isAdmin) {
        Add-Content -Path $hostsFile -Value "`r`n$lines"
    } else {
        Write-Host 'Windows will ask for administrator permission to edit the hosts file.'
        $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes(
            "Add-Content -Path '$hostsFile' -Value '`r`n$lines'"))
        Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile', '-EncodedCommand', $encoded
    }
}

Step 'Setup complete'
Write-Host @"

  +--------------------------- Login details ----------------------------+
    Platform (super admin)
      API URL       $AppUrl
      Email         admin@myteknoland.com
      Password      12345678

    Tenant "$TenantSlug"
      URL           http://$TenantDomain$PortSuffix
      Email         $TenantEmail
      Password      $TenantPassword

    MySQL           127.0.0.1:3306  db=$DbDatabase  user=$DbUsername  pass=$DbPassword
    Redis           127.0.0.1:6379
    Reverb (WS)     ws://127.0.0.1:$ReverbPort
  +-----------------------------------------------------------------------+
  Local credentials only. Change them before using this data anywhere else.

  Start everything later with:  start-local.cmd
  Open a NEW terminal so PATH changes (php, composer, mysql) are picked up.
"@ -ForegroundColor Green

if (-not $NoStart) {
    & (Join-Path $PSScriptRoot 'start-local.ps1')
}
