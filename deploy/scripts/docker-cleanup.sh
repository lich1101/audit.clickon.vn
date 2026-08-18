#!/bin/sh
# Dọn Docker an toàn: không xóa volume/container đang chạy.
# Mặc định: dangling images + build cache. AGGRESSIVE=1 xóa thêm mọi image không dùng.
#
# Lưu ý: `docker builder prune` sẽ lỗi "buildx component is missing" nếu biến
# DOCKER_BUILDKIT=1 được kế thừa từ script gọi nó mà máy chưa cài buildx.
# Vì vậy luôn gỡ biến này trước khi prune, và không để bước dọn dẹp làm hỏng deploy.

set -eu

AGGRESSIVE="${AGGRESSIVE:-0}"

# Chạy docker với DOCKER_BUILDKIT/BUILDKIT_PROGRESS đã được gỡ khỏi môi trường.
docker_no_buildkit() {
  if command -v env >/dev/null 2>&1; then
    env -u DOCKER_BUILDKIT -u BUILDKIT_PROGRESS docker "$@"
  else
    DOCKER_BUILDKIT= docker "$@"
  fi
}

echo "==> Docker usage before cleanup"
docker system df || true

echo "==> Prune dangling images"
docker image prune -f || echo "[WARN] Bỏ qua: image prune thất bại"

echo "==> Prune build cache"
if docker_no_buildkit builder prune -af; then
  :
else
  echo "[WARN] Bỏ qua: builder prune thất bại (không chặn deploy)"
fi

if [ "$AGGRESSIVE" = "1" ]; then
  echo "==> Prune unused images (không gắn container nào)"
  docker image prune -af || echo "[WARN] Bỏ qua: image prune -af thất bại"
fi

echo "==> Docker usage after cleanup"
docker system df || true

echo "==> Done"
