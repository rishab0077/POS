$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker was not found. Install and start Docker Desktop, then run this script again.'
}

docker compose version | Out-Null

if (-not (Test-Path -LiteralPath '.env')) {
    Copy-Item -LiteralPath '.env.docker.example' -Destination '.env'
    Write-Host 'Created .env with local-only defaults.'
}

Write-Host 'Building the application images...'
docker compose build
if ($LASTEXITCODE -ne 0) { throw 'Docker image build failed.' }

Write-Host 'Starting MySQL...'
docker compose up -d mysql
if ($LASTEXITCODE -ne 0) { throw 'MySQL failed to start.' }

Write-Host 'Preparing the database and demo data...'
docker compose run --rm app php artisan migrate --seed --force
if ($LASTEXITCODE -ne 0) { throw 'Database initialization failed.' }

Write-Host 'Starting Restaurant POS...'
docker compose up -d
if ($LASTEXITCODE -ne 0) { throw 'The application failed to start.' }

Write-Host ''
Write-Host 'Restaurant POS is ready at http://localhost:8097'
Write-Host 'Login credentials are configured by ADMIN_USERNAME and ADMIN_PASSWORD in .env.'
Write-Host 'Stop it with: docker compose down'
