#!/bin/sh
# Deploy nhanh Clickon Audit — 1 lệnh duy nhất.
#
#   sh deploy.sh              # deploy branch hiện tại, tự phát hiện cần build gì
#   sh deploy.sh main         # deploy branch cụ thể
#   FORCE_BUILD=1 sh deploy.sh # build lại toàn bộ api + web
#   SKIP_PULL=1 sh deploy.sh   # deploy code đang có sẵn, không git pull
#
# Kiểm tra sau deploy: sh deploy/scripts/prod-audit-preflight.sh

set -eu

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec sh "$ROOT_DIR/deploy/scripts/prod-update.sh" "$@"
