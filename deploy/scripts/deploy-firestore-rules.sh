#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RULES_FILE="$ROOT_DIR/firestore.rules"

echo "Firestore rules source: $RULES_FILE"
echo
echo "Service account trên server KHÔNG có quyền deploy rules tự động."
echo "Hãy deploy thủ công qua Firebase Console:"
echo
echo "1. Mở https://console.firebase.google.com/project/auditclickonvn/firestore/rules"
echo "2. Copy toàn bộ nội dung file firestore.rules"
echo "3. Dán vào editor Rules"
echo "4. Bấm Publish"
echo
echo "--- firestore.rules preview (20 dòng đầu) ---"
head -20 "$RULES_FILE"
