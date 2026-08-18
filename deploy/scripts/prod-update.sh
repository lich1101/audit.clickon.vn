#!/bin/sh
#
# Cập nhật production Clickon Audit (Docker app + MySQL trên host).
# POSIX sh — chạy được bằng: sh deploy/scripts/prod-update.sh
#
# Cách chạy:
#   sh deploy/scripts/prod-update.sh              # branch hiện tại, tự phát hiện cần build gì
#   sh deploy/scripts/prod-update.sh main         # branch cụ thể
#   FORCE_BUILD=1 sh deploy/scripts/prod-update.sh # build lại cả api + web
#   SKIP_BUILD=1  sh deploy/scripts/prod-update.sh # không build, chỉ migrate + restart
#   SKIP_PULL=1   sh deploy/scripts/prod-update.sh # không git pull
#   BUILD_SERVICES="api" sh deploy/scripts/prod-update.sh # chỉ build api
#
# Chế độ nhanh (mặc định): so commit của lần deploy thành công gần nhất với HEAD.
#   - app/ không đổi  -> bỏ qua build api
#   - web/ không đổi  -> bỏ qua build web
#   - app/database/migrations không đổi -> bỏ qua migrate
#   - không build gì và env không đổi   -> bỏ qua optimize + restart
# Trạng thái lưu ở deploy/.state/ (không commit vào git).
#
# Biến tùy chọn (docker.prod.env hoặc môi trường):
#   COMPOSE_PROJECT_NAME, COMPOSE_PARALLEL_LIMIT, NODE_BUILD_HEAP_MB,
#   COMPOSER_MEMORY_LIMIT, DEPLOY_NICE_LEVEL, DOCKER_PRUNE_AFTER_DEPLOY
#
# Ghi đè bằng 1 (luôn chạy) hoặc 0 (luôn bỏ qua); để trống = tự động:
#   OPTIMIZE_LARAVEL, RESTART_QUEUE, RESTART_SCHEDULER, FORCE_MIGRATE

set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
STATE_DIR="${STATE_DIR:-$ROOT_DIR/deploy/.state}"
STATE_COMMIT="$STATE_DIR/last-deploy-commit"
STATE_ENV_SHA="$STATE_DIR/last-deploy-env.sha"

BRANCH="${1:-$(git -C "$ROOT_DIR" branch --show-current 2>/dev/null || echo main)}"
SKIP_PULL="${SKIP_PULL:-0}"
SKIP_BUILD="${SKIP_BUILD:-0}"
FORCE_BUILD="${FORCE_BUILD:-0}"
SKIP_MIGRATE="${SKIP_MIGRATE:-0}"
FORCE_MIGRATE="${FORCE_MIGRATE:-}"
SKIP_CLEANUP="${SKIP_CLEANUP:-0}"
OPTIMIZE_LARAVEL="${OPTIMIZE_LARAVEL:-}"
BUILD_SERVICES="${BUILD_SERVICES:-}"
RESTART_QUEUE="${RESTART_QUEUE:-}"
RESTART_SCHEDULER="${RESTART_SCHEDULER:-}"
MIN_FREE_MB="${MIN_FREE_MB:-400}"

DEPLOY_OK=0

on_exit() {
  _exit_code=$?
  if [ "$DEPLOY_OK" != "1" ] && [ "$_exit_code" != "0" ]; then
    echo >&2
    echo "[ERROR] Deploy DỪNG do lỗi (exit=$_exit_code). Container đang chạy giữ nguyên, site không bị gỡ." >&2
  fi
}
trap on_exit EXIT

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
COMPOSE_PARALLEL_LIMIT="$(read_env_value "$ENV_FILE" COMPOSE_PARALLEL_LIMIT || echo 1)"
NODE_BUILD_HEAP_MB="$(read_env_value "$ENV_FILE" NODE_BUILD_HEAP_MB || echo 768)"
COMPOSER_MEMORY_LIMIT="$(read_env_value "$ENV_FILE" COMPOSER_MEMORY_LIMIT || echo 512M)"
DEPLOY_NICE_LEVEL="$(read_env_value "$ENV_FILE" DEPLOY_NICE_LEVEL || echo 10)"
DOCKER_PRUNE_AFTER_DEPLOY="${DOCKER_PRUNE_AFTER_DEPLOY:-$(read_env_value "$ENV_FILE" DOCKER_PRUNE_AFTER_DEPLOY || echo 1)}"

API_IMAGE="${API_IMAGE:-clickon-audit-api:latest}"
WEB_IMAGE="${WEB_IMAGE:-${COMPOSE_PROJECT}-web}"

export COMPOSE_PARALLEL_LIMIT
export DOCKER_BUILDKIT=1
export BUILDKIT_PROGRESS="${BUILDKIT_PROGRESS:-plain}"
export NODE_BUILD_HEAP_MB
export COMPOSER_MEMORY_LIMIT

dc() {
  docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

run_low_priority() {
  _nice_level="$1"
  shift

  if command -v ionice >/dev/null 2>&1; then
    ionice -c2 -n7 nice -n "$_nice_level" "$@"
    return
  fi

  if command -v nice >/dev/null 2>&1; then
    nice -n "$_nice_level" "$@"
    return
  fi

  "$@"
}

docker_build() {
  run_low_priority "$DEPLOY_NICE_LEVEL" \
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build "$1"
}

warn_low_memory() {
  _avail_mb="$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
  if [ "$_avail_mb" -gt 0 ] && [ "$_avail_mb" -lt "$MIN_FREE_MB" ]; then
    echo "[WARN] RAM khả dụng ~${_avail_mb}MB (< ${MIN_FREE_MB}MB). Cân nhắc SKIP_BUILD=1 hoặc tắt stack nặng (firecrawl) trước deploy." >&2
  fi
}

prepare_storage() {
  _storage_dir="$ROOT_DIR/app/storage/app/private/audit-ai-responses"
  mkdir -p "$_storage_dir"
  chown -R 33:33 "$_storage_dir" 2>/dev/null || chown -R www-data:www-data "$_storage_dir" 2>/dev/null || true
  chmod -R ug+rwX "$_storage_dir" 2>/dev/null || chmod -R 775 "$_storage_dir" 2>/dev/null || true
}

optimize_laravel() {
  echo "==> Laravel optimize (config/route cache)"
  dc exec -T api php artisan config:clear
  dc exec -T api php artisan route:clear
  dc exec -T api php artisan view:clear
  dc exec -T api php artisan config:cache
  dc exec -T api php artisan route:cache
}

env_file_sha() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$ENV_FILE" | cut -d' ' -f1
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$ENV_FILE" | cut -d' ' -f1
  else
    echo "no-hash-tool"
  fi
}

# 0 = commit hợp lệ và dùng so sánh được
last_deploy_commit() {
  [ -f "$STATE_COMMIT" ] || return 1
  _last="$(cat "$STATE_COMMIT" 2>/dev/null || true)"
  [ -n "$_last" ] || return 1
  git -C "$ROOT_DIR" cat-file -e "${_last}^{commit}" 2>/dev/null || return 1
  printf '%s' "$_last"
}

# path_changed <commit> <path> -> 0 nếu có thay đổi
path_changed() {
  ! git -C "$ROOT_DIR" diff --quiet "$1" HEAD -- "$2" 2>/dev/null
}

echo "==> Clickon Audit — prod update"
echo "    project:      $COMPOSE_PROJECT"
echo "    env:          $ENV_FILE"
echo "    branch:       $BRANCH"
echo "    SKIP_PULL:    $SKIP_PULL"
echo "    SKIP_BUILD:   $SKIP_BUILD"

warn_low_memory

if [ "$SKIP_PULL" != "1" ]; then
  echo "==> Git pull (ff-only)"
  git -C "$ROOT_DIR" fetch origin "$BRANCH"
  git -C "$ROOT_DIR" pull --ff-only origin "$BRANCH"
fi

echo "==> Kiểm tra MySQL host"
audit_check_host_mysql "$ENV_FILE"

prepare_storage

# ---------------------------------------------------------------------------
# Quyết định việc cần làm (chế độ nhanh)
# ---------------------------------------------------------------------------
HEAD_COMMIT="$(git -C "$ROOT_DIR" rev-parse HEAD 2>/dev/null || echo "")"
ENV_SHA_NOW="$(env_file_sha)"
ENV_SHA_LAST="$(cat "$STATE_ENV_SHA" 2>/dev/null || echo "")"
ENV_CHANGED=1
[ -n "$ENV_SHA_LAST" ] && [ "$ENV_SHA_NOW" = "$ENV_SHA_LAST" ] && ENV_CHANGED=0

LAST_COMMIT="$(last_deploy_commit || echo "")"
WORKTREE_DIRTY=0
if [ -n "$(git -C "$ROOT_DIR" status --porcelain -- app web 2>/dev/null || echo dirty)" ]; then
  WORKTREE_DIRTY=1
fi

NEED_API=0
NEED_WEB=0
NEED_MIGRATE=1
COMPOSE_CHANGED=1

if [ "$FORCE_BUILD" = "1" ] || [ -z "$HEAD_COMMIT" ] || [ -z "$LAST_COMMIT" ] || [ "$WORKTREE_DIRTY" = "1" ]; then
  NEED_API=1
  NEED_WEB=1
else
  path_changed "$LAST_COMMIT" app && NEED_API=1
  path_changed "$LAST_COMMIT" web && NEED_WEB=1
  [ "$ENV_CHANGED" = "1" ] && NEED_WEB=1
  path_changed "$LAST_COMMIT" app/database/migrations || NEED_MIGRATE=0
  path_changed "$LAST_COMMIT" docker-compose.prod.yml || COMPOSE_CHANGED=0
fi

# Image chưa tồn tại thì bắt buộc build.
docker image inspect "$API_IMAGE" >/dev/null 2>&1 || NEED_API=1
docker image inspect "$WEB_IMAGE" >/dev/null 2>&1 || NEED_WEB=1

if [ -z "$BUILD_SERVICES" ]; then
  BUILD_SERVICES=""
  [ "$NEED_API" = "1" ] && BUILD_SERVICES="api"
  [ "$NEED_WEB" = "1" ] && BUILD_SERVICES="${BUILD_SERVICES:+$BUILD_SERVICES }web"
  BUILD_MODE="auto"
else
  BUILD_MODE="thủ công"
fi

if [ "$SKIP_BUILD" = "1" ]; then
  BUILD_SERVICES=""
fi

echo "    lần deploy trước: ${LAST_COMMIT:-chưa có}"
echo "    HEAD hiện tại:    ${HEAD_COMMIT:-?}"
echo "    env đổi:          $([ "$ENV_CHANGED" = "1" ] && echo yes || echo no)"
echo "    sẽ build ($BUILD_MODE): ${BUILD_SERVICES:-không có}"

BUILT_SOMETHING=0

if [ -n "$BUILD_SERVICES" ]; then
  echo "==> Build images tuần tự (tránh tràn RAM VPS)"
  echo "    NODE_BUILD_HEAP_MB=${NODE_BUILD_HEAP_MB}"
  echo "    COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT}"
  echo "    DEPLOY_NICE_LEVEL=${DEPLOY_NICE_LEVEL}"
  for svc in $BUILD_SERVICES; do
    docker_build "$svc"
  done
  BUILT_SOMETHING=1
else
  echo "==> Bỏ qua build (không có thay đổi cần build lại)"
fi

echo "==> Khởi động / cập nhật containers (--no-build, --remove-orphans)"
dc up -d --no-build --remove-orphans api queue scheduler web nginx

# --- migrate ---
DO_MIGRATE=0
if [ "$SKIP_MIGRATE" = "1" ]; then
  DO_MIGRATE=0
elif [ "$FORCE_MIGRATE" = "1" ]; then
  DO_MIGRATE=1
elif [ "$NEED_MIGRATE" = "1" ]; then
  DO_MIGRATE=1
fi

if [ "$DO_MIGRATE" = "1" ]; then
  echo "==> Database migrations"
  dc run --rm --no-deps api php artisan migrate --force
else
  echo "==> Bỏ qua migrate (không có migration mới)"
fi

# --- optimize + restart ---
CHANGED_ANY=0
[ "$BUILT_SOMETHING" = "1" ] && CHANGED_ANY=1
[ "$ENV_CHANGED" = "1" ] && CHANGED_ANY=1
[ "$DO_MIGRATE" = "1" ] && CHANGED_ANY=1
[ "$COMPOSE_CHANGED" = "1" ] && CHANGED_ANY=1

should_run() {
  # $1 = giá trị ghi đè ("" = auto, 1 = luôn, 0 = không)
  case "$1" in
    1) return 0 ;;
    0) return 1 ;;
    *) [ "$CHANGED_ANY" = "1" ] ;;
  esac
}

if should_run "$OPTIMIZE_LARAVEL"; then
  optimize_laravel
else
  echo "==> Bỏ qua Laravel optimize (config cache còn hợp lệ)"
fi

if should_run "$RESTART_QUEUE"; then
  echo "==> Restart queue worker (nhận code mới)"
  dc restart queue
else
  echo "==> Bỏ qua restart queue"
fi

if should_run "$RESTART_SCHEDULER"; then
  echo "==> Restart scheduler (index:publish-pending + audit recover mỗi phút)"
  dc restart scheduler
else
  echo "==> Bỏ qua restart scheduler"
fi

if [ "$BUILT_SOMETHING" = "1" ] || [ "$COMPOSE_CHANGED" = "1" ]; then
  echo "==> Restart nginx (refresh upstream DNS sau khi recreate web/api)"
  dc restart nginx
else
  echo "==> Bỏ qua restart nginx"
fi

# Ghi state sau khi mọi bước quan trọng đã thành công.
mkdir -p "$STATE_DIR"
[ -n "$HEAD_COMMIT" ] && printf '%s\n' "$HEAD_COMMIT" > "$STATE_COMMIT"
printf '%s\n' "$ENV_SHA_NOW" > "$STATE_ENV_SHA"

DEPLOY_OK=1

if [ "$SKIP_CLEANUP" != "1" ] && [ "$DOCKER_PRUNE_AFTER_DEPLOY" = "1" ]; then
  echo "==> Docker cleanup"
  sh "$ROOT_DIR/deploy/scripts/docker-cleanup.sh" || echo "[WARN] Cleanup lỗi — bỏ qua, deploy vẫn thành công"
fi

echo "==> Deployment complete"
dc ps

NGINX_PORT="$(read_env_value "$ENV_FILE" NGINX_HTTP_PORT || echo 18080)"
echo
echo "==> Health check"
if curl -sf -o /dev/null "http://127.0.0.1:${NGINX_PORT}/backend/up"; then
  echo "[OK] backend/up 200"
else
  echo "[WARN] backend/up không trả 200 — xem: docker logs ${COMPOSE_PROJECT}-api-1"
fi
if curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${NGINX_PORT}/login" | grep -qE '^(200|3[0-9]{2})$'; then
  echo "[OK] frontend /login phản hồi"
else
  echo "[WARN] frontend /login không phản hồi — xem: docker logs ${COMPOSE_PROJECT}-web-1"
fi
echo
echo "Preflight đầy đủ: sh \"$ROOT_DIR/deploy/scripts/prod-audit-preflight.sh\""
