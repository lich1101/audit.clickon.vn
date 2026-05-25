#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
BRANCH="${1:-$(git -C "$ROOT_DIR" branch --show-current)}"
SKIP_PULL="${SKIP_PULL:-0}"

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

COMPOSE_PARALLEL_LIMIT="$(read_env_value "$ENV_FILE" COMPOSE_PARALLEL_LIMIT || echo 1)"
NODE_BUILD_HEAP_MB="$(read_env_value "$ENV_FILE" NODE_BUILD_HEAP_MB || echo 768)"
COMPOSER_MEMORY_LIMIT="$(read_env_value "$ENV_FILE" COMPOSER_MEMORY_LIMIT || echo 512M)"
DEPLOY_NICE_LEVEL="$(read_env_value "$ENV_FILE" DEPLOY_NICE_LEVEL || echo 10)"

export COMPOSE_PARALLEL_LIMIT
export DOCKER_BUILDKIT=1
export BUILDKIT_PROGRESS="${BUILDKIT_PROGRESS:-plain}"
export NODE_BUILD_HEAP_MB
export COMPOSER_MEMORY_LIMIT

dc() {
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

run_low_priority() {
  local nice_level="$1"
  shift

  if command -v ionice >/dev/null 2>&1; then
    ionice -c2 -n7 nice -n "$nice_level" "$@"
    return
  fi

  if command -v nice >/dev/null 2>&1; then
    nice -n "$nice_level" "$@"
    return
  fi

  "$@"
}

docker_build() {
  local service="$1"
  # Phải gọi "docker compose" trực tiếp — không dùng tên "compose" vì trùng /usr/bin/compose (mail).
  run_low_priority "$DEPLOY_NICE_LEVEL" docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build "$service"
}

if [[ "$SKIP_PULL" != "1" ]]; then
  echo "==> Updating source from git branch: $BRANCH"
  git -C "$ROOT_DIR" fetch origin "$BRANCH"
  git -C "$ROOT_DIR" pull --ff-only origin "$BRANCH"
fi

echo "==> Building images sequentially (không build song song, tránh tràn RAM VPS chia sẻ)"
echo "    COMPOSE_PARALLEL_LIMIT=${COMPOSE_PARALLEL_LIMIT}"
echo "    NODE_BUILD_HEAP_MB=${NODE_BUILD_HEAP_MB}"
echo "    COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT}"
echo "    DEPLOY_NICE_LEVEL=${DEPLOY_NICE_LEVEL}"

docker_build api
docker_build web

AUDIT_RESPONSES_DIR="$ROOT_DIR/app/storage/app/private/audit-ai-responses"
mkdir -p "$AUDIT_RESPONSES_DIR"
chown -R 33:33 "$AUDIT_RESPONSES_DIR" 2>/dev/null || chown -R www-data:www-data "$AUDIT_RESPONSES_DIR" 2>/dev/null || true
chmod -R ug+rwX "$AUDIT_RESPONSES_DIR" 2>/dev/null || chmod -R 775 "$AUDIT_RESPONSES_DIR" 2>/dev/null || true

echo "==> Starting MySQL only"
dc up -d mysql

echo "==> Running database migrations"
dc run --rm --no-deps api php artisan migrate --force

echo "==> Starting production containers (không build lại song song)"
dc up -d api queue web nginx

if [[ "${DOCKER_PRUNE_AFTER_DEPLOY:-1}" == "1" ]]; then
  echo "==> Docker cleanup (dangling images + build cache)"
  bash "$ROOT_DIR/deploy/scripts/docker-cleanup.sh"
fi

echo "==> Deployment complete"
dc ps
