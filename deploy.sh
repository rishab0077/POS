#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/opt/restaurant-pos}"
REPO_URL="${REPO_URL:-git@git.aayutech.dev:Rishab/Cafe-app.git}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_SHA="${DEPLOY_SHA:-}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-restaurant-pos}"
LOCK_FILE="${DEPLOY_LOCK_FILE:-/tmp/restaurant-pos-deploy.lock}"
STATE_DIR="$PROJECT_DIR/.deployment-state"

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

if ! command_exists docker; then
    echo "Docker is required but was not found."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is required but was not found."
    exit 1
fi

if ! command_exists git; then
    echo "Git is required but was not found."
    exit 1
fi

if ! command_exists flock; then
    echo "flock is required but was not found."
    exit 1
fi

if ! command_exists gzip; then
    echo "gzip is required but was not found."
    exit 1
fi

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Deployment aborted: another Restaurant POS deployment is running."
    exit 1
fi

if [ ! -d "$PROJECT_DIR/.git" ]; then
    mkdir -p "$PROJECT_DIR"
    git clone "$REPO_URL" "$PROJECT_DIR"
fi

cd "$PROJECT_DIR"

if [ -n "$(git status --porcelain)" ]; then
    echo "Deployment aborted: the server worktree has uncommitted changes."
    git status --short
    exit 1
fi

previous_sha="$(git rev-parse HEAD 2>/dev/null || true)"

git fetch --prune origin \
    "+refs/heads/$DEPLOY_BRANCH:refs/remotes/origin/$DEPLOY_BRANCH"

if [ -n "$DEPLOY_SHA" ]; then
    if ! [[ "$DEPLOY_SHA" =~ ^[0-9a-fA-F]{40,64}$ ]]; then
        echo "Deployment aborted: DEPLOY_SHA must be a full Git commit SHA."
        exit 1
    fi

    if ! git merge-base --is-ancestor "$DEPLOY_SHA" "origin/$DEPLOY_BRANCH"; then
        echo "Deployment aborted: $DEPLOY_SHA is not part of origin/$DEPLOY_BRANCH."
        exit 1
    fi

    git checkout --detach "$DEPLOY_SHA"
else
    git checkout "$DEPLOY_BRANCH"
    git pull --ff-only origin "$DEPLOY_BRANCH"
fi

deployed_sha="$(git rev-parse HEAD)"

if [ -n "$DEPLOY_SHA" ] && [ "$deployed_sha" != "$DEPLOY_SHA" ]; then
    echo "Deployment aborted: checked out $deployed_sha instead of $DEPLOY_SHA."
    exit 1
fi

if [ ! -f .env ]; then
    cp .env.production.example .env
    echo "Created .env from .env.production.example."
fi

if grep -Eq 'CHANGE_ME_|^APP_KEY=$' .env; then
    echo "Deployment stopped. Edit .env and replace all production secrets:"
    echo "- APP_KEY"
    echo "- DB_PASSWORD"
    echo "- MYSQL_ROOT_PASSWORD"
    echo "- PUSHER_APP_SECRET"
    echo "- ADMIN_PASSWORD"
    exit 1
fi

export COMPOSE_PROJECT_NAME

image_deployment=false
if [ -n "${APP_IMAGE_REPOSITORY:-}" ] &&
    [ -n "${WEB_IMAGE_REPOSITORY:-}" ] &&
    [ -n "${APP_IMAGE_TAG:-}" ]; then
    image_deployment=true
    export APP_IMAGE_REPOSITORY WEB_IMAGE_REPOSITORY APP_IMAGE_TAG
    echo "Pulling tested images tagged $APP_IMAGE_TAG..."
    docker compose pull app nginx
else
    APP_IMAGE_REPOSITORY="${APP_IMAGE_REPOSITORY:-restaurant-pos-app}"
    WEB_IMAGE_REPOSITORY="${WEB_IMAGE_REPOSITORY:-restaurant-pos-web}"
    APP_IMAGE_TAG="${APP_IMAGE_TAG:-sha-${deployed_sha}}"
    export APP_IMAGE_REPOSITORY WEB_IMAGE_REPOSITORY APP_IMAGE_TAG
    echo "No registry image coordinates supplied; building images on this server..."
    docker compose build app nginx
fi

docker compose up -d mysql

mkdir -p storage/backups "$STATE_DIR"
backup_file="storage/backups/pre-deploy-$(date +%Y%m%d-%H%M%S)-${deployed_sha:0:12}.sql.gz"

docker compose exec -T mysql sh -c \
    'exec mysqldump --single-transaction --quick --lock-tables=false -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip -c > "$backup_file"

gzip -t "$backup_file"
test -s "$backup_file"

printf '%s\n' "$previous_sha" > "$STATE_DIR/previous_sha"
printf '%s\n' "$deployed_sha" > "$STATE_DIR/pending_sha"
printf '%s\n' "$backup_file" > "$STATE_DIR/latest_backup"

maintenance_enabled=false
restore_application() {
    exit_code=$?

    if [ "$maintenance_enabled" = true ]; then
        docker compose run --rm app php artisan up >/dev/null 2>&1 || true
    fi

    if [ "$exit_code" -ne 0 ]; then
        echo "Deployment failed for commit $deployed_sha."
        echo "Previous commit: ${previous_sha:-unknown}"
        echo "Database backup: $backup_file"
        docker compose logs --tail=200 app nginx queue scheduler || true
    fi

    exit "$exit_code"
}
trap restore_application EXIT

docker compose run --rm app php artisan down --retry=60
maintenance_enabled=true

docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --class=BasicSeeder --force

docker compose up -d --remove-orphans
docker compose exec -T app php artisan queue:restart
docker compose exec -T app php artisan up
maintenance_enabled=false

for attempt in $(seq 1 18); do
    if docker compose exec -T nginx wget -q \
        --header="Host: ${APP_HEALTH_HOST:-localhost}" \
        -O /dev/null http://127.0.0.1/health; then
        echo "Health check passed."
        break
    fi

    if [ "$attempt" -eq 18 ]; then
        echo "Deployment failed: /health did not become ready."
        exit 1
    fi

    sleep 5
done

set_env_value APP_IMAGE_REPOSITORY "$APP_IMAGE_REPOSITORY"
set_env_value WEB_IMAGE_REPOSITORY "$WEB_IMAGE_REPOSITORY"
set_env_value APP_IMAGE_TAG "$APP_IMAGE_TAG"

printf '%s\n' "$deployed_sha" > "$STATE_DIR/current_sha"
printf '%s\n' "$APP_IMAGE_TAG" > "$STATE_DIR/current_image_tag"
rm -f "$STATE_DIR/pending_sha"

trap - EXIT

echo "Deployment completed successfully."
echo "Commit: $deployed_sha"
echo "Image tag: $APP_IMAGE_TAG"
echo "Image source: $([ "$image_deployment" = true ] && echo registry || echo local-build)"
echo "Local app: http://127.0.0.1:8097"
echo "Application URL: ${APP_URL:-not configured}"
echo "Database backup: $backup_file"
