param(
    [int]$Port = 8001
)

$ErrorActionPreference = 'Stop'

$machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
$normalizedPath = @($machinePath, $userPath) -join ';'
$normalizedPath = ($normalizedPath -replace ';{2,}', ';').Trim(';')
[Environment]::SetEnvironmentVariable('Path', $normalizedPath, 'Process')
[Environment]::SetEnvironmentVariable('PATH', $null, 'Process')

function Invoke-DetachedCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Command
    )

    $shell = New-Object -ComObject WScript.Shell
    [void]$shell.Run($Command, 0, $false)
}

try {
    $ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
    $AliasRoot = Join-Path $env:TEMP 'fbads-root'
    $Php = Join-Path $env:USERPROFILE 'scoop\apps\php\current\php.exe'
    $PgCtl = Join-Path $env:USERPROFILE 'scoop\apps\postgresql\current\bin\pg_ctl.exe'
    $PgIsReady = Join-Path $env:USERPROFILE 'scoop\apps\postgresql\current\bin\pg_isready.exe'
    $PhpIni = Join-Path $ProjectRoot 'launcher-php.ini'
    $Router = Join-Path $ProjectRoot 'launcher-router.php'
    $PhpOutLog = Join-Path $env:TEMP 'fbads-php-server.out.log'
    $PhpErrLog = Join-Path $env:TEMP 'fbads-php-server.err.log'

    Write-Host "Starting FB Ads Dashboard launcher..." -ForegroundColor Cyan
    Write-Host "Project: $ProjectRoot"
    Write-Host "Port: $Port"

    if (!(Test-Path -LiteralPath $Php)) {
        throw "PHP not found: $Php"
    }

    if (Test-Path -LiteralPath $AliasRoot) {
        Remove-Item -LiteralPath $AliasRoot -Force -Recurse
    }
    New-Item -ItemType Junction -Path $AliasRoot -Target $ProjectRoot | Out-Null

    if (Test-Path -LiteralPath $PgIsReady) {
        & $PgIsReady -h 127.0.0.1 -p 5432 | Out-Null
        if ($LASTEXITCODE -ne 0 -and (Test-Path -LiteralPath $PgCtl)) {
            Write-Host "Starting PostgreSQL..."
            Invoke-DetachedCommand ('"{0}" -D "{1}" -l "{2}" start' -f $PgCtl, (Join-Path $env:USERPROFILE 'scoop\apps\postgresql\current\data'), (Join-Path $env:TEMP 'fbads-postgres.log'))
            Start-Sleep -Seconds 2
        }
    }

    $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    if (-not $listener) {
        if (!(Test-Path -LiteralPath $PhpIni) -or !(Test-Path -LiteralPath $Router)) {
            throw "Missing launcher support files in the project folder."
        }

        Write-Host "Starting PHP server on http://127.0.0.1:$Port/"
        Invoke-DetachedCommand ('"{0}" -c "{1}" -S 127.0.0.1:{2} -t "{3}" "{4}"' -f $Php, $PhpIni, $Port, $AliasRoot, $Router)
        Start-Sleep -Seconds 2
    } else {
        Write-Host "Server already running on port $Port"
    }

    Write-Host "Browser URL: http://127.0.0.1:$Port/"
    Write-Host "Done. Keep this window open if you want to see launcher messages." -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "Launcher failed:" -ForegroundColor Red
    Write-Host $_ -ForegroundColor Red
    exit 1
}
