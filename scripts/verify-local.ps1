# Local pre-deploy check — run from E-learning-parrot-backend
# Requires: MySQL running, php artisan serve --port=8000 (for browser test)

$ErrorActionPreference = "Stop"
$backend = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $backend

Write-Host "`n=== Parrot platform local verification ===`n" -ForegroundColor Cyan

php artisan platform:verify-local --seed --repair
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "`n=== HTTP login (port 8000) ===`n" -ForegroundColor Cyan
$body = '{"username":"infos@parrotglobalstudyacademy.ca","password":"admin123"}'
try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:8000/api/admin/auth/login" -Method POST -Body $body -ContentType "application/json" -UseBasicParsing -TimeoutSec 10
    Write-Host "[OK] Direct API: $($r.Content)" -ForegroundColor Green
} catch {
    Write-Host "[WARN] Backend not on :8000 — start: php artisan serve --port=8000" -ForegroundColor Yellow
}

Write-Host "`nDefault login (local + cPanel):" -ForegroundColor Cyan
Write-Host "  Email:    infos@parrotglobalstudyacademy.ca"
Write-Host "  Password: admin123"
Write-Host "  (Legacy info@xanderglobalscholars.com also works)`n"
