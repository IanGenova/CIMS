param(
    [switch]$FreshDatabase
)

$ErrorActionPreference = "Stop"

function Require-Command($Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "$Name was not found in PATH. Install it before continuing."
    }
}

Require-Command php
Require-Command composer
Require-Command node
Require-Command npm

$phpVersion = (& php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;").Trim()
$nodeVersion = (& node -p "process.versions.node").Trim()

Write-Host "PHP $phpVersion | Node $nodeVersion" -ForegroundColor Cyan

if ($phpVersion -notin @("8.0", "8.1")) {
    Write-Warning "This locked Laravel 8 project is tested for PHP 8.0/8.1. PHP $phpVersion may fail."
}

if (-not $nodeVersion.StartsWith("16.")) {
    Write-Warning "Use Node 16.20.2 for this Laravel Mix 5 build."
}

if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host "Created .env. Edit DB and CIMS_ADMIN_* values, then rerun this script." -ForegroundColor Yellow
    exit 0
}

composer install
npm install
php artisan key:generate --force
php artisan optimize:clear

if ($FreshDatabase) {
    Write-Warning "This will erase all tables in the database configured in .env."
    $confirm = Read-Host "Type FRESH to continue"
    if ($confirm -ne "FRESH") {
        throw "Fresh database setup cancelled."
    }
    php artisan migrate:fresh --seed
} else {
    php artisan migrate
}

php artisan passport:install --force
php artisan storage:link

Write-Host "Setup complete. Run 'php artisan serve' and 'npm run watch' in separate terminals." -ForegroundColor Green
