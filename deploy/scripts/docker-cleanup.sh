#!/usr/bin/env bash

set -euo pipefail

# Dọn Docker an toàn: không xóa volume/container đang chạy.
# Mặc định: dangling images + build cache. Aggressive thêm: mọi image không dùng.

AGGRESSIVE="${AGGRESSIVE:-0}"

echo "==> Docker usage before cleanup"
docker system df || true

echo "==> Prune dangling images"
docker image prune -f

echo "==> Prune build cache"
docker builder prune -af

if [[ "$AGGRESSIVE" == "1" ]]; then
  echo "==> Prune unused images (không gắn container nào)"
  docker image prune -af
fi

echo "==> Docker usage after cleanup"
docker system df || true

echo "==> Done"
