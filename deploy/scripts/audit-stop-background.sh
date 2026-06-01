#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
STOP_MESSAGE="${STOP_MESSAGE:-Audit run stopped by operator before next AI stage.}"
STOP_QUEUE="${STOP_QUEUE:-1}"
STOP_SCHEDULER="${STOP_SCHEDULER:-1}"

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

COMPOSE_PROJECT="$(audit_compose_project "$ENV_FILE")"

dc() {
  docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

echo "==> Stop active audit runs"
dc exec -T api php artisan audit:stop-active-runs --message="$STOP_MESSAGE" --json || true

if [[ "$STOP_QUEUE" == "1" ]]; then
  echo "==> Stop queue container"
  dc stop -t 1 queue
fi

if [[ "$STOP_SCHEDULER" == "1" ]]; then
  echo "==> Stop scheduler container"
  dc stop -t 1 scheduler
fi

echo "==> Background audit workers stopped"
dc ps
