<#
.SYNOPSIS
    Build, upload and hand off a release for the HakimeRAD booking host.

.DESCRIPTION
    Runs on a workstation. Produces a clean artifact (no vendor, no .env, no tests, no
    var), uploads it over FTPS, and prints the single command to run in the control
    panel's Terminal. The host has no reachable SSH, so the server-side half of the
    deployment is deploy.sh, which lives in this directory and is uploaded alongside.

    Credentials come from the environment, never from this file:
        $env:DEPLOY_FTP_USER
        $env:DEPLOY_FTP_PASS

.EXAMPLE
    $env:DEPLOY_FTP_USER='hakimevx'; $env:DEPLOY_FTP_PASS='…'
    ./deploy.ps1 -Environment staging
#>
[CmdletBinding()]
param(
    [ValidateSet('staging', 'production')]
    [string]$Environment = 'staging',

    [string]$Id = (Get-Date -Format 'yyyyMMdd-HHmmss'),

    [string]$HostName = 'uk-shared01.dapanel.net'
)

$ErrorActionPreference = 'Stop'

# Each environment has its own hostname directory and its own public URL.
$targets = @{
    staging    = @{ Path = '/domains/staging-booking.hakimerad.com'; Url = 'https://staging-booking.hakimerad.com' }
    production = @{ Path = '/domains/booking.hakimerad.com';         Url = 'https://booking.hakimerad.com' }
}
$target = $targets[$Environment]

if (-not $env:DEPLOY_FTP_USER -or -not $env:DEPLOY_FTP_PASS) {
    throw 'Set DEPLOY_FTP_USER and DEPLOY_FTP_PASS in the environment first.'
}

$repo = Split-Path -Parent $PSScriptRoot
$stage = Join-Path ([IO.Path]::GetTempPath()) "booking-release-$Id"
$zip = Join-Path ([IO.Path]::GetTempPath()) "booking-$Environment-$Id.zip"

Write-Host "== 1/4 stage $Id"
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage | Out-Null

foreach ($dir in @('bin', 'src', 'templates', 'routes', 'database', 'public')) {
    Copy-Item (Join-Path $repo $dir) -Destination $stage -Recurse -Force
}
foreach ($file in @('composer.json', 'composer.lock')) {
    Copy-Item (Join-Path $repo $file) -Destination $stage -Force
}

Write-Host "== 2/4 archive"
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path (Get-ChildItem $stage).FullName -DestinationPath $zip -CompressionLevel Optimal

$sha = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
$size = [math]::Round((Get-Item $zip).Length / 1MB, 2)
Write-Host "  $zip ($size MB)"
Write-Host "  sha256 $sha"

Write-Host "== 3/4 upload over FTPS"
$cred = "$($env:DEPLOY_FTP_USER):$($env:DEPLOY_FTP_PASS)"
$remote = "ftp://$HostName$($target.Path)/"

foreach ($pair in @(@($zip, "release-$Id.zip"), @((Join-Path $PSScriptRoot 'deploy.sh'), 'deploy.sh'))) {
    & curl.exe -sS --ssl-reqd --max-time 300 -u $cred -T $pair[0] "$remote$($pair[1])"
    if ($LASTEXITCODE -ne 0) { throw "upload failed for $($pair[1])" }
    Write-Host "  uploaded $($pair[1])"
}

Write-Host "== 4/4 next"
Write-Host ''
Write-Host 'Run this in the control panel Terminal (Terminal, then paste):'
Write-Host ''
Write-Host "  cd $($target.Path) && bash deploy.sh $Id release-$Id.zip"
Write-Host ''
Write-Host "Smoke target: $($target.Url)"
Write-Host "Record: id=$Id sha256=$sha"
Write-Host 'Delete release-*.zip and deploy.sh from the host when the release is settled.'

Remove-Item $stage -Recurse -Force
