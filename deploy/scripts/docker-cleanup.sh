#!/bin/sh
# Dọn Docker an toàn: không xóa volume/container đang chạy.
# POSIX sh — chạy bằng: sh deploy/scripts/docker-cleanup.sh
#
# Mặc định chỉ xóa dangling images. Build cache được GIỮ LẠI để lần build sau
# nhanh (xóa cache = mỗi lần build phải compile lại PHP ext, npm ci...), chỉ
# prune khi ổ đĩa sắp đầy.
#
# Biến:
#   PRUNE_BUILD_CACHE   auto (mặc định) | 1 (luôn xóa) | 0 (không bao giờ xóa)
#   DISK_MIN_FREE_GB    ngưỡng cho chế độ auto (mặc định 5)
#   AGGRESSIVE          1 = xóa thêm mọi image không gắn container
#
# Lưu ý: `docker builder prune` sẽ lỗi "buildx component is missing" nếu biến
# DOCKER_BUILDKIT=1 được kế thừa từ script gọi nó mà máy chưa cài buildx.
# Vì vậy luôn gỡ biến này trước khi prune, và không để bước dọn dẹp làm hỏng deploy.

set -eu

AGGRESSIVE="${AGGRESSIVE:-0}"
PRUNE_BUILD_CACHE="${PRUNE_BUILD_CACHE:-auto}"
DISK_MIN_FREE_GB="${DISK_MIN_FREE_GB:-5}"
DOCKER_ROOT="${DOCKER_ROOT:-/var/lib/docker}"

# Chạy docker với DOCKER_BUILDKIT/BUILDKIT_PROGRESS đã được gỡ khỏi môi trường.
docker_no_buildkit() {
  if command -v env >/dev/null 2>&1; then
    env -u DOCKER_BUILDKIT -u BUILDKIT_PROGRESS docker "$@"
  else
    DOCKER_BUILDKIT= docker "$@"
  fi
}

free_gb() {
  _target="$DOCKER_ROOT"
  [ -d "$_target" ] || _target=/
  df -Pk "$_target" 2>/dev/null | awk 'NR==2 {print int($4/1048576)}' || echo 0
}

echo "==> Docker usage before cleanup"
docker system df || true

echo "==> Prune dangling images"
docker image prune -f || echo "[WARN] Bỏ qua: image prune thất bại"

FREE_GB="$(free_gb)"
[ -n "$FREE_GB" ] || FREE_GB=0

DO_PRUNE_CACHE=0
case "$PRUNE_BUILD_CACHE" in
  1) DO_PRUNE_CACHE=1 ;;
  0) DO_PRUNE_CACHE=0 ;;
  *)
    if [ "$FREE_GB" -lt "$DISK_MIN_FREE_GB" ]; then
      DO_PRUNE_CACHE=1
    fi
    ;;
esac

if [ "$DO_PRUNE_CACHE" = "1" ]; then
  echo "==> Prune build cache (còn trống ${FREE_GB}GB < ${DISK_MIN_FREE_GB}GB hoặc được yêu cầu)"
  docker_no_buildkit builder prune -af || echo "[WARN] Bỏ qua: builder prune thất bại (không chặn deploy)"
else
  echo "==> Giữ build cache (còn trống ${FREE_GB}GB >= ${DISK_MIN_FREE_GB}GB) — build lần sau sẽ nhanh"
  echo "    Xóa thủ công khi cần: PRUNE_BUILD_CACHE=1 sh deploy/scripts/docker-cleanup.sh"
fi

if [ "$AGGRESSIVE" = "1" ]; then
  echo "==> Prune unused images (không gắn container nào)"
  docker image prune -af || echo "[WARN] Bỏ qua: image prune -af thất bại"
fi

echo "==> Docker usage after cleanup"
docker system df || true

echo "==> Done"
