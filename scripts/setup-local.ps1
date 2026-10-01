<#
  One-command local setup for Windows 10/11.

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\setup-local.ps1
    (or double-click / run setup-local.cmd in the project root)

  Only Git is needed beforehand. Installs PHP 8.4 (+ extensions), Composer,
  MySQL, Redis and Node.js via Scoop when missing, configures Reverb, creates
  .env and the database, runs migrations + seeders, prints the login details and
  starts the API, queue worker and Reverb (scripts\start-local.ps1).
  Safe to re-run: every step is idempotent.

  If a MySQL server is already listening on 3306 (XAMPP, Laragon, MySQL
  Installer...) it is reused; pass its root password with -MysqlRootPassword.
#>
param(
    [string]$DbDatabase = 'nexdine',
    [string]$DbUsername = 'nexdine',
    [string]$DbPassword = 'secret',
    [string]$MysqlRootPassword = '',
    [int]$AppPort = 8000,
    [int]$ReverbPort = 8080,
    [switch]$SkipNpm,
    [switch]$Fresh,
    [switch]$NoStart
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-Location (Split-Path -Parent $PSScriptRoot)

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
Set-Env APP_URL "http://127.0.0.1:$AppPort"
Set-Env APP_INSTALLED true
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

if (-not $SkipNpm) {
    Step 'Installing Node dependencies (Puppeteer)'
    & npm install
    if ($LASTEXITCODE -ne 0) { Warn 'npm install failed; PDF/screenshot features need Puppeteer. Re-run: npm install' }
}

& php artisan optimize:clear | Out-Null

Step 'Setup complete'
Write-Host @"

  +--------------------------- Login details ----------------------------+
    API URL         http://127.0.0.1:$AppPort
    Admin email     admin@myteknoland.com
    Admin password  12345678        (change it after first login)

    MySQL           127.0.0.1:3306  db=$DbDatabase  user=$DbUsername  pass=$DbPassword
    Redis           127.0.0.1:6379
    Reverb (WS)     ws://127.0.0.1:$ReverbPort
  +-----------------------------------------------------------------------+

  Start everything later with:  start-local.cmd
  Open a NEW terminal so PATH changes (php, composer, mysql) are picked up.
"@ -ForegroundColor Green

if (-not $NoStart) {
    & (Join-Path $PSScriptRoot 'start-local.ps1') -AppPort $AppPort
}
