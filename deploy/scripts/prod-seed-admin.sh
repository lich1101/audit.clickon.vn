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

EMAIL="${1:-$(read_env_value "$ENV_FILE" "ADMIN_SEED_EMAIL" || true)}"
PASSWORD="${2:-$(read_env_value "$ENV_FILE" "ADMIN_SEED_PASSWORD" || true)}"
NAME="${3:-$(read_env_value "$ENV_FILE" "ADMIN_SEED_NAME" || true)}"
UID="$(read_env_value "$ENV_FILE" "ADMIN_SEED_UID" || true)"

if [[ -z "$NAME" ]]; then
  NAME="Clickon Audit Admin"
fi

CREDENTIALS_FILE="$ROOT_DIR/app/storage/app/firebase-service-account.json"

if [[ -d "$CREDENTIALS_FILE" ]]; then
  echo "Firebase credentials path is a directory, expected a JSON file: $CREDENTIALS_FILE" >&2
  echo "Delete that directory, then place your Firebase service account JSON at the same path." >&2
  exit 1
fi

if [[ ! -f "$CREDENTIALS_FILE" ]]; then
  echo "Missing Firebase service account JSON: $CREDENTIALS_FILE" >&2
  exit 1
fi

if [[ -z "$EMAIL" ]]; then
  echo "Missing admin email. Pass it as arg1 or set ADMIN_SEED_EMAIL in $ENV_FILE" >&2
  exit 1
fi

if [[ -z "$PASSWORD" ]]; then
  echo "Missing admin password. Pass it as arg2 or set ADMIN_SEED_PASSWORD in $ENV_FILE" >&2
  exit 1
fi

ARGS=(clickon:create-admin "$EMAIL" "$PASSWORD" "--name=$NAME")

if [[ -n "$UID" ]]; then
  ARGS+=("--uid=$UID")
fi

docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile tools run --rm artisan "${ARGS[@]}"
