#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
BRANCH="${1:-$(git -C "$ROOT_DIR" branch --show-current)}"
SKIP_PULL="${SKIP_PULL:-0}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE" >&2
  exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
  echo "Missing compose file: $COMPOSE_FILE" >&2
  exit 1
fi

if [[ "$SKIP_PULL" != "1" ]]; then
  echo "==> Updating source from git branch: $BRANCH"
  git -C "$ROOT_DIR" fetch origin "$BRANCH"
  git -C "$ROOT_DIR" pull --ff-only origin "$BRANCH"
fi

echo "==> Rebuilding and starting production containers"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --build mysql api queue web nginx

echo "==> Running database migrations"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile tools run --rm artisan migrate --force

echo "==> Deployment complete"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps
