#!/bin/sh
# Helper dùng chung cho các script deploy. POSIX sh (dash/ash/bash đều chạy).
# File này được `.` (source), không chạy trực tiếp.

# Đọc 1 giá trị từ file .env, bỏ dấu nháy bao ngoài và ký tự CR của Windows.
read_env_value() {
  _env_file="$1"
  _env_key="$2"

  [ -f "$_env_file" ] || return 1

  _env_line="$(grep -E "^${_env_key}=" "$_env_file" | tail -n 1 || true)"
  [ -n "$_env_line" ] || return 1

  _env_line="${_env_line#*=}"
  _env_line="$(printf '%s' "$_env_line" | tr -d '\r')"

  case "$_env_line" in
    \"*\") _env_line="${_env_line#\"}"; _env_line="${_env_line%\"}" ;;
    \'*\') _env_line="${_env_line#\'}"; _env_line="${_env_line%\'}" ;;
  esac

  printf '%s' "$_env_line"
}

# Tên project Compose (tránh tạo stack auditclickonvn_* nhầm tên thư mục).
audit_compose_project() {
  _proj_env="${1:-${ENV_FILE:-}}"

  if [ -n "$_proj_env" ] && [ -f "$_proj_env" ]; then
    read_env_value "$_proj_env" COMPOSE_PROJECT_NAME 2>/dev/null || echo "clickon-audit"
  else
    echo "clickon-audit"
  fi
}

# MySQL chạy trên host (mysql.service), không còn container mysql trong stack.
audit_check_host_mysql() {
  _db_env="$1"

  _db_user="$(read_env_value "$_db_env" DB_USERNAME || true)"
  _db_pass="$(read_env_value "$_db_env" DB_PASSWORD || true)"
  _db_name="$(read_env_value "$_db_env" DB_DATABASE || true)"

  if [ -z "$_db_user" ] || [ -z "$_db_pass" ] || [ -z "$_db_name" ]; then
    echo "[ERROR] Thiếu DB_USERNAME / DB_PASSWORD / DB_DATABASE trong $_db_env" >&2
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

  if ! MYSQL_PWD="$_db_pass" mysql -h127.0.0.1 -P3306 -u"$_db_user" -e "SELECT 1" "$_db_name" >/dev/null 2>&1; then
    echo "[ERROR] Không kết nối MySQL host (127.0.0.1) với user=$_db_user db=$_db_name" >&2
    return 1
  fi

  echo "[OK] MySQL host: $_db_name"
  return 0
}
