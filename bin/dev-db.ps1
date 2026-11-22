<#
.SYNOPSIS
    Start, stop and inspect the portable MySQL 8.4 development instance.

.DESCRIPTION
    This workstation has two MySQL servers, and only one of them is the development database:

      * MySQL 8.4.9 - portable, in the workspace under .tools/, listens on 127.0.0.1:3307.
                      This is what booking-app/.env points at. Not a Windows service.
      * MySQL 9.6.0 - the machine-wide service "MySQL96", on port 3306, in Program Files.
                      Nothing in this project uses it, and starting it does not help.

    Starting the wrong one is easy and looks like success, and the application then fails with
    "actively refused" on 3307 - which reads like a code fault rather than the wrong engine. That
    is why this script exists, why it refuses to run against anything that is not 8.4.x, and why
    `status` names the service it deliberately does not touch.

    The file is deliberately ASCII-only: Windows PowerShell 5.1 reads a BOM-less script as ANSI,
    and a stray em dash breaks the parse instead of printing.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File bin/dev-db.ps1 status

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File bin/dev-db.ps1 start

.NOTES
    Loopback-bound, synthetic data only. The tuning block in mysql84-my.ini trades crash
    durability for speed and must never be copied to staging or production.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('start', 'stop', 'status', 'reset')]
    [string] $Action = 'status',

    # Workspace .tools directory. Defaults to the one beside this repository.
    [string] $Tools,

    [string] $RootPassword = 'local-root-only-4mZ8',

    # `reset` destroys the data directory. It does nothing without this.
    [switch] $Yes
)

$ErrorActionPreference = 'Stop'

# MYSQL_PWD instead of -p: mysql prints "Using a password on the command line can be insecure"
# to stderr, and Windows PowerShell turns a native command's stderr into a terminating error under
# $ErrorActionPreference = 'Stop'. The script would abort on the health check that proves the
# server is fine. This also keeps the password out of the process table.
$env:MYSQL_PWD = $RootPassword

$repoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Tools) {
    $Tools = Join-Path (Split-Path -Parent $repoRoot) '.tools'
}

$basedir = Join-Path $Tools 'mysql84\PFiles64\MySQL\MySQL Server 8.4'
$mysqld = Join-Path $basedir 'bin\mysqld.exe'
$mysql = Join-Path $basedir 'bin\mysql.exe'
$mysqladmin = Join-Path $basedir 'bin\mysqladmin.exe'
$datadir = Join-Path $Tools 'mysql84-data'
$ini = Join-Path $Tools 'mysql84-my.ini'
$port = 3307

if (-not (Test-Path $mysqld)) {
    throw "No portable MySQL at $mysqld. The development instance is missing; do not substitute the MySQL96 service."
}

function Get-ServerVersion {
    $raw = & $mysqld --version 2>&1
    if ($raw -match 'Ver\s+(\d+\.\d+\.\d+)') { return $Matches[1] }

    return 'unknown'
}

function Test-Port([int] $Port, [int] $TimeoutMs = 500) {
    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $task = $client.ConnectAsync('127.0.0.1', $Port)
        return $task.Wait($TimeoutMs) -and $client.Connected
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Get-ServerIdentity {
    # A TCP probe proves something is listening; only the server can say which server.
    # 2>$null, not 2>&1: Windows PowerShell turns a native command's stderr into a terminating
    # error under $ErrorActionPreference='Stop', so mysql's password warning would abort the script
    # on the very check that is supposed to report a healthy server.
    $out = & $mysql --protocol=TCP -h 127.0.0.1 -P $port -u root -N -B -e 'SELECT VERSION(), @@port;' 2>$null
    if ($LASTEXITCODE -ne 0) { return $null }

    $line = ($out | Where-Object { $_ -match '\d' } | Select-Object -First 1)
    if (-not $line) { return $null }

    $parts = $line -split "`t"

    return [pscustomobject]@{ Version = $parts[0]; Port = $parts[1] }
}

function Assert-Version {
    $version = Get-ServerVersion
    if ($version -notlike '8.4.*') {
        throw "The portable instance reports $version. This project's schema, config and dumps are MySQL 8.4; running them on another version produces failures that look like code faults. Refusing to start it."
    }

    return $version
}

switch ($Action) {
    'status' {
        $version = Get-ServerVersion
        $listening = Test-Port $port
        $identity = if ($listening) { Get-ServerIdentity } else { $null }

        "binary   : $mysqld"
        "version  : $version"
        "config   : $ini"
        "datadir  : $datadir"
        "port     : $port (listening: $listening)"

        if ($identity) {
            "server   : MySQL $($identity.Version) on port $($identity.Port)"
        } elseif ($listening) {
            "server   : something is listening but did not answer as the development root user"
        } else {
            "server   : not running. Start it with: powershell -File bin/dev-db.ps1 start"
        }

        if (Test-Port 3306) {
            "warning  : port 3306 is also listening. That is MySQL96 (9.6), not this project's database."
        }

        if (-not $listening) { exit 1 }
    }

    'start' {
        if (Test-Port $port) {
            $identity = Get-ServerIdentity
            if ($identity) {
                "already running: MySQL $($identity.Version) on port $($identity.Port). Nothing to do."
                exit 0
            }

            throw "Something is listening on port $port but it is not the development instance. Investigate before starting another server."
        }

        [void] (Assert-Version)

        Start-Process $mysqld -ArgumentList "--defaults-file=$ini", '--console' -WindowStyle Hidden

        $ready = $false
        for ($i = 0; $i -lt 45; $i++) {
            Start-Sleep -Seconds 2
            if (Test-Port $port) { $ready = $true; break }
        }

        if (-not $ready) {
            throw "mysqld did not answer on port $port within 90 s. Read $Tools\mysql84.err."
        }

        $identity = Get-ServerIdentity
        if (-not $identity) {
            throw "The server is listening but the development root login failed. Check the credentials in this script against .tools/MYSQL-DEV-README.md."
        }

        "started: MySQL $($identity.Version) on port $($identity.Port), ready in $($i * 2) s"
    }

    'stop' {
        if (-not (Test-Port $port)) {
            "not running. Nothing to do."
            exit 0
        }

        & $mysqladmin --protocol=TCP -h 127.0.0.1 -P $port -u root shutdown 2>$null | Out-Null

        for ($i = 0; $i -lt 20; $i++) {
            Start-Sleep -Milliseconds 500
            if (-not (Test-Port $port)) { "stopped."; exit 0 }
        }

        throw "The server did not stop within 10 s. Read $Tools\mysql84.err before killing the process."
    }

    'reset' {
        if (-not $Yes) {
            throw 'reset destroys the data directory. Re-run with -Yes once you are certain nothing in it is wanted.'
        }

        if (Test-Port $port) {
            & $mysqladmin --protocol=TCP -h 127.0.0.1 -P $port -u root shutdown 2>$null | Out-Null
            Start-Sleep -Seconds 3
        }

        if (Test-Path $datadir) { Remove-Item -Recurse -Force $datadir }

        [void] (Assert-Version)
        & $mysqld --initialize-insecure --basedir="$basedir" --datadir="$datadir" --console 2>&1 | Out-Null

        "re-initialised $datadir (no root password set yet)."
        "next: start it, then run the bootstrap SQL in .tools/bootstrap.sql and 'php bin/console migrate'."
    }
}
