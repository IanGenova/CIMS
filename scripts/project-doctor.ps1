$ErrorActionPreference = "Stop"
Write-Host "CIMS project checks" -ForegroundColor Cyan

$phpVersion = & php -r "echo PHP_VERSION;"
$nodeVersion = & node --version
$npmVersion = & npm --version
Write-Host "PHP:  $phpVersion"
Write-Host "Node: $nodeVersion"
Write-Host "npm:  $npmVersion"

if (-not (Test-Path .env)) { Write-Warning ".env is missing. Run: Copy-Item .env.example .env" }
if (-not (Test-Path vendor/autoload.php)) { Write-Warning "vendor is missing. Run: composer install" }
if (-not (Test-Path node_modules)) { Write-Warning "node_modules is missing. Run: npm install" }

& php artisan --version
& php artisan route:list
Write-Host "Doctor finished." -ForegroundColor Green
