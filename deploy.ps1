param(
    [string]$HostName = "YOUR_SERVER_IP",
    [string]$User = "root",
    [int]$Port = 22,
    [string]$KeyPath = "$env:USERPROFILE\.ssh\id_ed25519",
    [string]$RemotePath = "/var/www/html"
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$ArchiveName = "fbads-deploy-{0}.tar.gz" -f (Get-Date -Format "yyyyMMdd-HHmmss")
$ArchivePath = Join-Path $env:TEMP $ArchiveName
$RemoteTmp = "/tmp/$ArchiveName"

if ($HostName -eq "YOUR_SERVER_IP") {
    throw "Set -HostName or edit deploy.ps1 and replace YOUR_SERVER_IP."
}

if (!(Test-Path -LiteralPath $KeyPath)) {
    throw "SSH key not found: $KeyPath"
}

Write-Host "Building archive..." -ForegroundColor Cyan
Push-Location $ProjectRoot
try {
    if (Test-Path -LiteralPath $ArchivePath) {
        Remove-Item -LiteralPath $ArchivePath -Force
    }

    tar `
        --exclude=".git" `
        --exclude=".github" `
        --exclude="_removed" `
        --exclude=".env" `
        --exclude="config/config.php" `
        --exclude="*.tar.gz" `
        --exclude="snapshot_*" `
        --exclude="index.php.old.diz" `
        -czf $ArchivePath .
}
finally {
    Pop-Location
}

Write-Host "Uploading archive to $User@$HostName..." -ForegroundColor Cyan
scp -P $Port -i $KeyPath $ArchivePath "$User@$HostName`:$RemoteTmp"

Write-Host "Deploying to $RemotePath..." -ForegroundColor Cyan
$remoteCommand = @"
set -e
mkdir -p "$RemotePath"
tar -xzf "$RemoteTmp" -C "$RemotePath"
rm -f "$RemoteTmp"
cd "$RemotePath"
php -l index.php
"@

ssh -p $Port -i $KeyPath "$User@$HostName" $remoteCommand

Remove-Item -LiteralPath $ArchivePath -Force
Write-Host "Deploy finished." -ForegroundColor Green
