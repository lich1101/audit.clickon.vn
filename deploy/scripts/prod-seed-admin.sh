#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE" >&2
  exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
  echo "Missing compose file: $COMPOSE_FILE" >&2
  exit 1
fi

set -a
source "$ENV_FILE"
set +a

EMAIL="${1:-${ADMIN_SEED_EMAIL:-}}"
PASSWORD="${2:-${ADMIN_SEED_PASSWORD:-}}"
NAME="${3:-${ADMIN_SEED_NAME:-Clickon Audit Admin}}"
UID="${ADMIN_SEED_UID:-}"

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
