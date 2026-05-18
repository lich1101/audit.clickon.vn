#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"

# shellcheck source=/dev/null
source "$ROOT_DIR/deploy/scripts/_env.sh"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE" >&2
  exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
  echo "Missing compose file: $COMPOSE_FILE" >&2
  exit 1
fi

echo "==> Building and starting production containers"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --build mysql api queue web nginx

echo "==> Rebuilding artisan image (profile tools)"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build artisan

echo "==> Running database migrations"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile tools run --rm artisan migrate --force

AUTO_SEED_ADMIN="$(read_env_value "$ENV_FILE" "AUTO_SEED_ADMIN" || true)"
ADMIN_SEED_EMAIL="$(read_env_value "$ENV_FILE" "ADMIN_SEED_EMAIL" || true)"
ADMIN_SEED_PASSWORD="$(read_env_value "$ENV_FILE" "ADMIN_SEED_PASSWORD" || true)"

if [[ "${AUTO_SEED_ADMIN:-0}" == "1" ]] && [[ -n "${ADMIN_SEED_EMAIL:-}" ]] && [[ -n "${ADMIN_SEED_PASSWORD:-}" ]]; then
  echo "==> Auto seeding admin account"
  "$ROOT_DIR/deploy/scripts/prod-seed-admin.sh"
fi

echo "==> Current container status"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps
