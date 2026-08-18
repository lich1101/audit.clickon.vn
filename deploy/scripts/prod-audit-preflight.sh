#!/bin/sh
# Kiểm tra cấu hình production sau khi deploy.
# POSIX sh — chạy bằng: sh deploy/scripts/prod-audit-preflight.sh

set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/deploy/env/docker.prod.env}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/docker-compose.prod.yml}"

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

read_or_empty() {
  read_env_value "$ENV_FILE" "$1" 2>/dev/null || true
}

check_required_env() {
  _val="$(read_or_empty "$1")"

  if [ -z "$_val" ]; then
    echo "[ERROR] Missing env: $1"
    return 1
  fi

  echo "[OK] $1"
  return 0
}

check_optional_env() {
  _val="$(read_or_empty "$1")"

  if [ -z "$_val" ]; then
    echo "[WARN] Empty env: $1"
    return 0
  fi

  echo "[OK] $1"
  return 0
}

STATUS=0

echo "==> Preflight: env file"
for key in \
  APP_KEY \
  DB_DATABASE \
  DB_USERNAME \
  DB_PASSWORD \
  LARAVEL_INTERNAL_API_KEY \
  FRONTEND_URL \
  NEXT_PUBLIC_LARAVEL_API_URL
do
  check_required_env "$key" || STATUS=1
done

echo
echo "==> Preflight: Firebase/Auth"
for key in \
  FIREBASE_PROJECT_ID \
  FIREBASE_CLIENT_EMAIL \
  FIREBASE_PRIVATE_KEY \
  NEXT_PUBLIC_FIREBASE_API_KEY \
  NEXT_PUBLIC_FIREBASE_PROJECT_ID
do
  check_required_env "$key" || STATUS=1
done

CREDENTIALS_FILE="$ROOT_DIR/app/storage/app/firebase-service-account.json"
if [ -d "$CREDENTIALS_FILE" ]; then
  echo "[ERROR] Firebase credentials path is a directory: $CREDENTIALS_FILE"
  STATUS=1
elif [ ! -f "$CREDENTIALS_FILE" ]; then
  echo "[WARN] Missing Firebase service account JSON: $CREDENTIALS_FILE"
else
  echo "[OK] Firebase service account JSON exists"
fi

echo
echo "==> Preflight: AI providers"
for key in \
  OPENAI_API_KEY \
  GEMINI_API_KEY \
  PERPLEXITY_API_KEY
do
  check_optional_env "$key"
done

echo
echo "==> Preflight: current deep research env defaults"
for key in \
  AUDIT_STEP3_FLOW_MODE \
  AUDIT_DEEP_RESEARCH_RESEARCH_PROVIDER \
  AUDIT_DEEP_RESEARCH_RESEARCH_MODEL \
  AUDIT_DEEP_RESEARCH_REASONING_PROVIDER \
  AUDIT_DEEP_RESEARCH_REASONING_MODEL \
  AUDIT_DEEP_RESEARCH_FORMATTER_PROVIDER \
  AUDIT_DEEP_RESEARCH_FORMATTER_MODEL \
  AUDIT_DEEP_RESEARCH_BATCH_SIZE
do
  echo "$key=$(read_or_empty "$key")"
done

echo
echo "==> Preflight: MySQL host"
audit_check_host_mysql "$ENV_FILE" || STATUS=1

echo
echo "==> Preflight: scheduler + queue"
if dc ps --status running --services 2>/dev/null | grep -qx scheduler; then
  echo "[OK] scheduler đang chạy (schedule:work — index:publish-pending mỗi phút)"
else
  echo "[WARN] scheduler chưa chạy. Bật bằng: sh deploy/scripts/prod-update.sh"
  STATUS=1
fi

if dc ps --status running --services 2>/dev/null | grep -qx queue; then
  echo "[OK] queue đang chạy (queue:work)"
else
  echo "[WARN] queue chưa chạy. Bật bằng: sh deploy/scripts/prod-update.sh"
  STATUS=1
fi

echo
echo "==> Preflight: Laravel audit config"
dc up -d --no-build api >/dev/null 2>&1 || true

if dc run --rm --no-deps api php artisan audit:check-config; then
  echo
  echo "[OK] Laravel audit configuration is ready"
else
  echo
  echo "[ERROR] Laravel audit configuration is not ready"
  STATUS=1
fi

echo
echo "==> Preflight: HTTP"
NGINX_PORT="$(read_or_empty NGINX_HTTP_PORT)"
[ -n "$NGINX_PORT" ] || NGINX_PORT=18080
if curl -sf -o /dev/null "http://127.0.0.1:${NGINX_PORT}/backend/up"; then
  echo "[OK] backend/up 200 (port $NGINX_PORT)"
else
  echo "[ERROR] backend/up không trả 200 (port $NGINX_PORT)"
  STATUS=1
fi

exit "$STATUS"
