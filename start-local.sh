#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo 'Docker was not found. Install and start Docker, then run this script again.' >&2
    exit 1
fi

docker compose version >/dev/null

if [ ! -f .env ]; then
    cp .env.docker.example .env
    echo 'Created .env with local-only defaults.'
fi

echo 'Building the application images...'
docker compose build

echo 'Starting MySQL...'
docker compose up -d mysql

echo 'Preparing the database and demo data...'
docker compose run --rm app php artisan migrate --seed --force

echo 'Starting Restaurant POS...'
docker compose up -d

printf '\n%s\n' \
    'Restaurant POS is ready at http://localhost:8097' \
    'Login credentials are configured by ADMIN_USERNAME and ADMIN_PASSWORD in .env.' \
    'Stop it with: docker compose down'
