#!/usr/bin/env bash
#
# Cập nhật production Clickon Audit (Docker app + MySQL trên host).
#
# Cách chạy:
#   bash deploy/scripts/prod-update.sh              # branch hiện tại
#   bash deploy/scripts/prod-update.sh main         # branch cụ thể
#   SKIP_PULL=1 bash deploy/scripts/prod-update.sh   # không git pull
#   SKIP_BUILD=1 bash deploy/scripts/prod-update.sh # chỉ migrate + restart (nhanh, ít RAM)
#   BUILD_SERVICES=api bash deploy/scripts/prod-update.sh  # chỉ build api
#   OPTIMIZE_LARAVEL=0 bash deploy/scripts/prod-update.sh   # bỏ config:cache
#
# Biến tùy chọn (docker.prod.env hoặc môi trường):
#   COMPOSE_PROJECT_NAME, COMPOSE_PARALLEL_LIMIT, NODE_BUILD_HEAP_MB,
#   COMPOSER_MEMORY_LIMIT, DEPLOY_NICE_LEVEL, DOCKER_PRUNE_AFTER_DEPLOY
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
BRANCH="${1:-$(git -C "$ROOT_DIR" branch --show-current 2>/dev/null || echo main)}"
SKIP_PULL="${SKIP_PULL:-0}"
SKIP_BUILD="${SKIP_BUILD:-0}"
SKIP_MIGRATE="${SKIP_MIGRATE:-0}"
SKIP_CLEANUP="${SKIP_CLEANUP:-0}"
OPTIMIZE_LARAVEL="${OPTIMIZE_LARAVEL:-1}"
BUILD_SERVICES="${BUILD_SERVICES:-api web}"
RESTART_QUEUE="${RESTART_QUEUE:-1}"
MIN_FREE_MB="${MIN_FREE_MB:-400}"

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
COMPOSE_PARALLEL_LIMIT="$(read_env_value "$ENV_FILE" COMPOSE_PARALLEL_LIMIT || echo 1)"
NODE_BUILD_HEAP_MB="$(read_env_value "$ENV_FILE" NODE_BUILD_HEAP_MB || echo 768)"
COMPOSER_MEMORY_LIMIT="$(read_env_value "$ENV_FILE" COMPOSER_MEMORY_LIMIT || echo 512M)"
DEPLOY_NICE_LEVEL="$(read_env_value "$ENV_FILE" DEPLOY_NICE_LEVEL || echo 10)"
DOCKER_PRUNE_AFTER_DEPLOY="${DOCKER_PRUNE_AFTER_DEPLOY:-$(read_env_value "$ENV_FILE" DOCKER_PRUNE_AFTER_DEPLOY || echo 1)}"

export COMPOSE_PARALLEL_LIMIT
export DOCKER_BUILDKIT=1
export BUILDKIT_PROGRESS="${BUILDKIT_PROGRESS:-plain}"
export NODE_BUILD_HEAP_MB
export COMPOSER_MEMORY_LIMIT

dc() {
  docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
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
  run_low_priority "$DEPLOY_NICE_LEVEL" \
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build "$service"
}

warn_low_memory() {
  local avail_mb
  avail_mb="$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
  if [[ "$avail_mb" -gt 0 && "$avail_mb" -lt "$MIN_FREE_MB" ]]; then
    echo "[WARN] RAM khả dụng ~${avail_mb}MB (< ${MIN_FREE_MB}MB). Cân nhắc SKIP_BUILD=1 hoặc tắt stack nặng (firecrawl) trước deploy." >&2
  fi
}

prepare_storage() {
  local dir="$ROOT_DIR/app/storage/app/private/audit-ai-responses"
  mkdir -p "$dir"
  chown -R 33:33 "$dir" 2>/dev/null || chown -R www-data:www-data "$dir" 2>/dev/null || true
  chmod -R ug+rwX "$dir" 2>/dev/null || chmod -R 775 "$dir" 2>/dev/null || true
}

optimize_laravel() {
  echo "==> Laravel optimize (config/route cache)"
  dc exec -T api php artisan config:clear
  dc exec -T api php artisan route:clear
  dc exec -T api php artisan view:clear
  dc exec -T api php artisan config:cache
  dc exec -T api php artisan route:cache
}

echo "==> Clickon Audit — prod update"
echo "    project:      $COMPOSE_PROJECT"
echo "    env:          $ENV_FILE"
echo "    branch:       $BRANCH"
echo "    SKIP_PULL:    $SKIP_PULL"
echo "    SKIP_BUILD:   $SKIP_BUILD"
echo "    BUILD_SERVICES: $BUILD_SERVICES"
echo "    SKIP_MIGRATE: $SKIP_MIGRATE"

warn_low_memory

if [[ "$SKIP_PULL" != "1" ]]; then
  echo "==> Git pull (ff-only)"
  git -C "$ROOT_DIR" fetch origin "$BRANCH"
  git -C "$ROOT_DIR" pull --ff-only origin "$BRANCH"
fi

echo "==> Kiểm tra MySQL host"
audit_check_host_mysql "$ENV_FILE"

prepare_storage

if [[ "$SKIP_BUILD" != "1" ]]; then
  echo "==> Build images tuần tự (tránh tràn RAM VPS)"
  echo "    NODE_BUILD_HEAP_MB=${NODE_BUILD_HEAP_MB}"
  echo "    COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT}"
  echo "    DEPLOY_NICE_LEVEL=${DEPLOY_NICE_LEVEL}"
  for svc in $BUILD_SERVICES; do
    docker_build "$svc"
  done
else
  echo "==> Bỏ qua build (SKIP_BUILD=1)"
fi

echo "==> Khởi động / cập nhật containers (--no-build, --remove-orphans)"
dc up -d --no-build --remove-orphans api queue scheduler web nginx

if [[ "$SKIP_MIGRATE" != "1" ]]; then
  echo "==> Database migrations"
  dc run --rm --no-deps api php artisan migrate --force
else
  echo "==> Bỏ qua migrate (SKIP_MIGRATE=1)"
fi

if [[ "$OPTIMIZE_LARAVEL" == "1" ]]; then
  optimize_laravel
fi

if [[ "$RESTART_QUEUE" == "1" ]]; then
  echo "==> Restart queue worker (nhận code mới)"
  dc restart queue
fi

if [[ "$SKIP_CLEANUP" != "1" && "$DOCKER_PRUNE_AFTER_DEPLOY" == "1" ]]; then
  echo "==> Docker cleanup"
  bash "$ROOT_DIR/deploy/scripts/docker-cleanup.sh"
fi

echo "==> Deployment complete"
dc ps
echo
echo "==> Kiểm tra sau deploy:"
echo "curl -sf http://127.0.0.1:\$(grep -E '^NGINX_HTTP_PORT=' \"$ENV_FILE\" | tail -1 | cut -d= -f2 || echo 18080)/backend/up"
echo "bash \"$ROOT_DIR/deploy/scripts/prod-audit-preflight.sh\""
