param(
    [int]$Port = 8001
)

$ErrorActionPreference = 'Stop'

try {
    Write-Host "Stopping FB Ads Dashboard on port $Port..." -ForegroundColor Cyan
    $PhpBinary = Join-Path $env:USERPROFILE 'scoop\apps\php\current\php.exe'

    $listeners = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    $targets = @()

    if ($listeners) {
        $targets += ($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    } else {
        Write-Host "No listener found on port $Port." -ForegroundColor Yellow
    }

    $phpTargets = Get-Process php -ErrorAction SilentlyContinue | Where-Object {
        $_.Path -and $_.Path -eq $PhpBinary
    } | Select-Object -ExpandProperty Id

    if ($phpTargets) {
        $targets += $phpTargets
    }

    $targets = $targets | Where-Object { $_ -and $_ -ne 0 } | Select-Object -Unique
    foreach ($pid in $targets) {
        $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
        if ($proc) {
            Write-Host "Stopping process $($proc.ProcessName) (PID $pid)" -ForegroundColor Yellow
            Stop-Process -Id $pid -Force
        }
    }

    Start-Sleep -Seconds 1

    $stillListening = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    $stillPhp = Get-Process php -ErrorAction SilentlyContinue | Where-Object {
        $_.Path -and $_.Path -eq $PhpBinary
    }

    if (($stillListening | Measure-Object).Count -gt 0 -or ($stillPhp | Measure-Object).Count -gt 0) {
        Write-Host "Some dashboard processes are still present." -ForegroundColor Red
        exit 1
    }

    Write-Host "Dashboard stopped." -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "Stopper failed:" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
