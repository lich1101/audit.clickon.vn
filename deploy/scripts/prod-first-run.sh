#!/bin/sh
# Lần chạy đầu tiên trên máy production: build + migrate + start toàn bộ stack.
# POSIX sh — chạy bằng: sh deploy/scripts/prod-first-run.sh

set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"
STATE_DIR="${STATE_DIR:-$ROOT_DIR/deploy/.state}"

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

echo "==> First run — project: $COMPOSE_PROJECT"
echo "==> Kiểm tra MySQL host"
audit_check_host_mysql "$ENV_FILE"

echo "==> Building production images"
dc build api web

echo "==> Running database migrations"
dc run --rm --no-deps api php artisan migrate --force

AUDIT_RESPONSES_DIR="$ROOT_DIR/app/storage/app/private/audit-ai-responses"
mkdir -p "$AUDIT_RESPONSES_DIR"
chown -R 33:33 "$AUDIT_RESPONSES_DIR" 2>/dev/null || chown -R www-data:www-data "$AUDIT_RESPONSES_DIR" 2>/dev/null || true
chmod -R ug+rwX "$AUDIT_RESPONSES_DIR" 2>/dev/null || chmod -R 775 "$AUDIT_RESPONSES_DIR" 2>/dev/null || true

echo "==> Starting production containers"
dc up -d --remove-orphans api queue scheduler web nginx

AUTO_SEED_ADMIN="$(read_env_value "$ENV_FILE" AUTO_SEED_ADMIN || true)"
ADMIN_SEED_EMAIL="$(read_env_value "$ENV_FILE" ADMIN_SEED_EMAIL || true)"
ADMIN_SEED_PASSWORD="$(read_env_value "$ENV_FILE" ADMIN_SEED_PASSWORD || true)"

if [ "${AUTO_SEED_ADMIN:-0}" = "1" ] && [ -n "${ADMIN_SEED_EMAIL:-}" ] && [ -n "${ADMIN_SEED_PASSWORD:-}" ]; then
  echo "==> Auto seeding admin account"
  sh "$ROOT_DIR/deploy/scripts/prod-seed-admin.sh"
fi

# Ghi state để lần deploy sau dùng được chế độ nhanh.
mkdir -p "$STATE_DIR"
git -C "$ROOT_DIR" rev-parse HEAD > "$STATE_DIR/last-deploy-commit" 2>/dev/null || true
if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ENV_FILE" | cut -d' ' -f1 > "$STATE_DIR/last-deploy-env.sha"
fi

echo "==> Current container status"
dc ps
echo
echo "==> Suggested post-deploy check"
echo "sh \"$ROOT_DIR/deploy/scripts/prod-audit-preflight.sh\""
