#!/bin/bash
# 本番で必要なディレクトリを一括作成（デプロイ後1回実行）
# 使い方: プロジェクトルートで sudo ./scripts/ensure_dirs.sh
# または: bash scripts/ensure_dirs.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

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
  if [ -n "$SUDO_USER" ]; then
    chown "$SUDO_USER:$SUDO_USER" "$d" 2>/dev/null || true
  fi
  echo "OK: $d"
done
echo "Done. tmp/sessions, logs, uploads を作成しました。"
