#!/usr/bin/env bash

read_env_value() {
  local file="$1"
  local key="$2"
  local line

  if [[ ! -f "$file" ]]; then
    return 1
  fi

  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"

  if [[ -z "$line" ]]; then
    return 1
  fi

  line="${line#*=}"
  line="${line%$'\r'}"

  if [[ "$line" =~ ^\".*\"$ ]]; then
    line="${line:1:${#line}-2}"
  elif [[ "$line" =~ ^\'.*\'$ ]]; then
    line="${line:1:${#line}-2}"
  fi

  printf '%s' "$line"
}

# Tên project Compose (tránh tạo stack auditclickonvn_* nhầm tên thư mục).
audit_compose_project() {
  local env_file="${1:-${ENV_FILE:-}}"
  if [[ -n "$env_file" && -f "$env_file" ]]; then
    read_env_value "$env_file" COMPOSE_PROJECT_NAME 2>/dev/null || echo "clickon-audit"
  else
    echo "clickon-audit"
  fi
}

# MySQL chạy trên host (mysql.service), không còn container mysql trong stack.
audit_check_host_mysql() {
  local env_file="$1"
  local db_user db_pass db_name

  db_user="$(read_env_value "$env_file" DB_USERNAME || true)"
  db_pass="$(read_env_value "$env_file" DB_PASSWORD || true)"
  db_name="$(read_env_value "$env_file" DB_DATABASE || true)"

  if [[ -z "$db_user" || -z "$db_pass" || -z "$db_name" ]]; then
    echo "[ERROR] Thiếu DB_USERNAME / DB_PASSWORD / DB_DATABASE trong $env_file" >&2
    return 1
  fi

  if ! systemctl is-active --quiet mysql 2>/dev/null; then
    echo "[ERROR] mysql.service không chạy trên host" >&2
    return 1
  fi

  if ! command -v mysql >/dev/null 2>&1; then
    echo "[WARN] Không có lệnh mysql CLI — bỏ qua kiểm tra kết nối host" >&2
    return 0
  fi

  MYSQL_PWD="$db_pass" mysql -h127.0.0.1 -P3306 -u"$db_user" -e "SELECT 1" "$db_name" >/dev/null 2>&1 || {
    echo "[ERROR] Không kết nối MySQL host (127.0.0.1) với user=$db_user db=$db_name" >&2
    return 1
  }

  echo "[OK] MySQL host: $db_name"
  return 0
}
