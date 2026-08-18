#!/bin/sh
# Dừng khẩn cấp toàn bộ audit đang chạy nền (queue + scheduler).
# POSIX sh — chạy bằng: sh deploy/scripts/audit-stop-background.sh

set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
STOP_MESSAGE="${STOP_MESSAGE:-Audit run stopped by operator before next AI stage.}"
STOP_QUEUE="${STOP_QUEUE:-1}"
STOP_SCHEDULER="${STOP_SCHEDULER:-1}"

# shellcheck source=/dev/null
. "$ROOT_DIR/deploy/scripts/_env.sh"

if [ ! -f "$ENV_FILE" ]; then
  echo "Missing env file: $ENV_FILE" >&2
  exit 1
fi

if [ ! -f "$COMPOSE_FILE" ]; then
  echo "Missing compose file: $COMPOSE_FILE" >&2
  exit 1
fi

COMPOSE_PROJECT="$(audit_compose_project "$ENV_FILE")"

dc() {
  docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

if [ "$STOP_QUEUE" = "1" ]; then
  echo "==> Kill queue container ngay lập tức"
  dc kill queue || true
fi

if [ "$STOP_SCHEDULER" = "1" ]; then
  echo "==> Kill scheduler container ngay lập tức"
  dc kill scheduler || true
fi

echo "==> Stop active audit runs + purge queued audit jobs"
dc exec -T api php artisan audit:stop-active-runs --message="$STOP_MESSAGE" --purge-jobs --json || true

echo "==> Background audit workers stopped"
dc ps
