#!/bin/bash
# セッション保存用ディレクトリを作成（本番デプロイ後1回実行）
# 使い方: プロジェクトルートで ./scripts/ensure_tmp_sessions.sh
# または: bash scripts/ensure_tmp_sessions.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
SESSIONS_DIR="$ROOT_DIR/tmp/sessions"

mkdir -p "$SESSIONS_DIR"
chmod 0770 "$SESSIONS_DIR"
# Webサーバー実行ユーザーに合わせて変更（例: apache, nginx, www-data）
if [ -n "$SUDO_USER" ]; then
    chown "$SUDO_USER:$SUDO_USER" "$SESSIONS_DIR" 2>/dev/null || true
fi
echo "OK: $SESSIONS_DIR created (mode 0770)"
