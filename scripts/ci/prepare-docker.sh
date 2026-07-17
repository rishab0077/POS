#!/usr/bin/env bash
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
        if [ "$(id -u)" -eq 0 ]; then
            apt-get update
            DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl docker.io
        elif command -v sudo >/dev/null 2>&1; then
            sudo apt-get update
            sudo DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl docker.io
        else
            echo "Docker is missing and this runner cannot install packages."
            exit 1
        fi
    else
        echo "Docker is missing. Configure this Gitea runner with Docker access."
        exit 1
    fi
fi

if ! docker compose version >/dev/null 2>&1; then
    compose_version="${DOCKER_COMPOSE_VERSION:-v2.27.1}"
    architecture="$(uname -m)"

    case "$architecture" in
        x86_64|amd64) architecture="x86_64" ;;
        aarch64|arm64) architecture="aarch64" ;;
        *)
            echo "Unsupported Docker Compose architecture: $architecture"
            exit 1
            ;;
    esac

    plugin_dir="${DOCKER_CONFIG:-$HOME/.docker}/cli-plugins"
    mkdir -p "$plugin_dir"
    curl -fsSL \
        "https://github.com/docker/compose/releases/download/${compose_version}/docker-compose-linux-${architecture}" \
        -o "$plugin_dir/docker-compose"
    chmod +x "$plugin_dir/docker-compose"
fi

docker --version
docker compose version

if ! docker info >/dev/null 2>&1; then
    echo "Docker CLI is installed, but the runner cannot reach a Docker daemon."
    echo "Mount /var/run/docker.sock into act_runner or use a Docker-in-Docker runner."
    exit 1
fi
