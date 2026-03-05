#!/bin/bash
# 本番で必要なディレクトリを一括作成（デプロイ後1回実行）
# 使い方:
#   本番（EC2）: sudo WEB_USER=apache ./scripts/ensure_dirs.sh  または sudo -E ./scripts/ensure_dirs.sh（後述）
#   ローカル:    sudo ./scripts/ensure_dirs.sh（SUDO_USER に chown）または bash scripts/ensure_dirs.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

# 所有者: WEB_USER 指定時はそのユーザー、未指定で sudo 実行時は SUDO_USER、それ以外は apache（本番デフォルト）
OWNER="${WEB_USER:-$SUDO_USER}"
if [ -z "$OWNER" ]; then
  OWNER="apache"
fi

dirs=(
  "$ROOT_DIR/tmp/sessions"
  "$ROOT_DIR/logs"
  "$ROOT_DIR/uploads"
  "$ROOT_DIR/uploads/backgrounds"
  "$ROOT_DIR/uploads/messages"
)

for d in "${dirs[@]}"; do
  mkdir -p "$d"
  chmod 0770 "$d"
  if command -v chown >/dev/null 2>&1; then
    chown "$OWNER:$OWNER" "$d" 2>/dev/null || true
  fi
  echo "OK: $d"
done
echo "Done. tmp/sessions, logs, uploads を作成しました。（所有者: $OWNER）"
