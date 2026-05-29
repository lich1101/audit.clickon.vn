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

COMPOSE_PROJECT="$(audit_compose_project "$ENV_FILE")"

dc() {
  docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

read_or_empty() {
  local key="$1"
  read_env_value "$ENV_FILE" "$key" 2>/dev/null || true
}

check_required_env() {
  local key="$1"
  local value
  value="$(read_or_empty "$key")"

  if [[ -z "$value" ]]; then
    echo "[ERROR] Missing env: $key"
    return 1
  fi

  echo "[OK] $key"
  return 0
}

check_optional_env() {
  local key="$1"
  local value
  value="$(read_or_empty "$key")"

  if [[ -z "$value" ]]; then
    echo "[WARN] Empty env: $key"
    return 0
  fi

  echo "[OK] $key"
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
if [[ -d "$CREDENTIALS_FILE" ]]; then
  echo "[ERROR] Firebase credentials path is a directory: $CREDENTIALS_FILE"
  STATUS=1
elif [[ ! -f "$CREDENTIALS_FILE" ]]; then
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
echo "AUDIT_STEP3_FLOW_MODE=$(read_or_empty AUDIT_STEP3_FLOW_MODE)"
echo "AUDIT_DEEP_RESEARCH_RESEARCH_PROVIDER=$(read_or_empty AUDIT_DEEP_RESEARCH_RESEARCH_PROVIDER)"
echo "AUDIT_DEEP_RESEARCH_RESEARCH_MODEL=$(read_or_empty AUDIT_DEEP_RESEARCH_RESEARCH_MODEL)"
echo "AUDIT_DEEP_RESEARCH_REASONING_PROVIDER=$(read_or_empty AUDIT_DEEP_RESEARCH_REASONING_PROVIDER)"
echo "AUDIT_DEEP_RESEARCH_REASONING_MODEL=$(read_or_empty AUDIT_DEEP_RESEARCH_REASONING_MODEL)"
echo "AUDIT_DEEP_RESEARCH_FORMATTER_PROVIDER=$(read_or_empty AUDIT_DEEP_RESEARCH_FORMATTER_PROVIDER)"
echo "AUDIT_DEEP_RESEARCH_FORMATTER_MODEL=$(read_or_empty AUDIT_DEEP_RESEARCH_FORMATTER_MODEL)"
echo "AUDIT_DEEP_RESEARCH_BATCH_SIZE=$(read_or_empty AUDIT_DEEP_RESEARCH_BATCH_SIZE)"

echo
echo "==> Preflight: MySQL host"
audit_check_host_mysql "$ENV_FILE" || STATUS=1

echo
echo "==> Preflight: Laravel audit configuration check"
dc up -d --no-build api 2>/dev/null || true

if dc run --rm --no-deps api php artisan audit:check-config; then
  echo
  echo "[OK] Laravel audit configuration is ready"
else
  echo
  echo "[ERROR] Laravel audit configuration is not ready"
  STATUS=1
fi

exit "$STATUS"
