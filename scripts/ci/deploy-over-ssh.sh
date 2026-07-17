#!/usr/bin/env bash
set -euo pipefail

required_variables=(
    VM_HOST
    VM_PORT
    VM_USERNAME
    VPS_SSH_PRIVATE_KEY
    DEPLOY_KNOWN_HOSTS
    DEPLOY_SHA
    DEPLOY_PATH
    APP_IMAGE_REPOSITORY
    WEB_IMAGE_REPOSITORY
    APP_IMAGE_TAG
)

for variable in "${required_variables[@]}"; do
    if [ -z "${!variable:-}" ]; then
        echo "Missing required CI/CD variable or secret: $variable"
        exit 1
    fi
done

if ! [[ "$DEPLOY_SHA" =~ ^[0-9a-fA-F]{40,64}$ ]]; then
    echo "DEPLOY_SHA must be a full Git commit SHA."
    exit 1
fi

if ! [[ "$DEPLOY_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
    echo "DEPLOY_PATH must be a safe absolute path."
    exit 1
fi

ssh_dir="$HOME/.ssh"
key_file="$ssh_dir/id_ed25519"
remote_script="/tmp/restaurant-pos-deploy-${DEPLOY_SHA}.sh"
port="$VM_PORT"

cleanup() {
    rm -f "$key_file"
}
trap cleanup EXIT

install -m 700 -d "$ssh_dir"
printf '%s\n' "$VPS_SSH_PRIVATE_KEY" | tr -d '\r' > "$key_file"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" | tr -d '\r' > "$ssh_dir/known_hosts"
chmod 600 "$key_file" "$ssh_dir/known_hosts"

for command in ssh scp; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Required SSH client command is missing from the runner: $command"
        exit 1
    fi
done

if ! grep -Eq 'BEGIN (OPENSSH |RSA |EC |DSA )?PRIVATE KEY' "$key_file"; then
    echo "VPS_SSH_PRIVATE_KEY must contain the complete private key."
    exit 1
fi

ssh_options=(
    -i "$key_file"
    -p "$port"
    -o BatchMode=yes
    -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes
)

scp_options=(
    -i "$key_file"
    -P "$port"
    -o BatchMode=yes
    -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes
)

ssh "${ssh_options[@]}" "$VM_USERNAME@$VM_HOST" "echo 'SSH connection OK'"
scp "${scp_options[@]}" deploy.sh "$VM_USERNAME@$VM_HOST:$remote_script"

printf -v remote_command \
    'cd %q && env PROJECT_DIR=%q DEPLOY_BRANCH=%q DEPLOY_SHA=%q APP_IMAGE_REPOSITORY=%q WEB_IMAGE_REPOSITORY=%q APP_IMAGE_TAG=%q bash %q' \
    "$DEPLOY_PATH" \
    "$DEPLOY_PATH" \
    main \
    "$DEPLOY_SHA" \
    "$APP_IMAGE_REPOSITORY" \
    "$WEB_IMAGE_REPOSITORY" \
    "$APP_IMAGE_TAG" \
    "$remote_script"

set +e
ssh "${ssh_options[@]}" "$VM_USERNAME@$VM_HOST" "$remote_command"
deploy_status=$?
set -e

ssh "${ssh_options[@]}" "$VM_USERNAME@$VM_HOST" "rm -f '$remote_script'" || true
exit "$deploy_status"
