#!/bin/bash
# Mac / Linux 用デプロイスクリプト
# 指定したファイルを EC2 本番サーバーにアップロードする
#
# 使い方:
#   ./deploy.sh                           # $files に定義されたファイルを一括送信
#   ./deploy.sh api/messages.php          # 指定ファイルだけ送信（複数可）
#   ./deploy.sh includes/chat/scripts.php assets/css/chat-main.css

set -euo pipefail

KEY="$HOME/.ssh/social9-key.pem"
EC2="ec2-user@54.95.86.79"
ROOT="$(cd "$(dirname "$0")" && pwd)"
REMOTE_ROOT="/var/www/html"

if [ ! -f "$KEY" ]; then
    echo "ERROR: PEM key not found at $KEY"
    exit 1
fi

upload_file() {
    local local_path="$1"
    local remote_dir
    remote_dir="$REMOTE_ROOT/$(dirname "$local_path")/"

    if [ ! -f "$ROOT/$local_path" ]; then
        echo "SKIP (not found): $local_path"
        return 1
    fi

    echo "Uploading: $local_path -> $EC2:$remote_dir"
    scp -i "$KEY" "$ROOT/$local_path" "$EC2:$remote_dir"
}

if [ $# -gt 0 ]; then
    count=0
    errors=0
    for file in "$@"; do
        if upload_file "$file"; then
            count=$((count + 1))
        else
            errors=$((errors + 1))
        fi
    done
    echo "Done. $count file(s) uploaded, $errors skipped."
    exit 0
fi

# 引数なしの場合: 定義済みファイル一覧を送信
files=(
    "chat.php"
    "api/ai.php"
    "api/messages.php"
    "api/notifications.php"
    "config/app.php"
    "config/ai_config.php"
    "includes/chat/scripts.php"
    "includes/ai_file_reader.php"
    "includes/ai_billing_rates.php"
    "includes/today_topics_helper.php"
    "includes/design_config.php"
    "includes/design_loader.php"
    "assets/js/ai-reply-suggest.js"
    "assets/js/error-collector.js"
    "assets/js/push-notifications.js"
    "assets/css/chat-main.css"
    "admin/ai_usage.php"
    "admin/storage_billing.php"
    "cron/ai_today_topics_evening.php"
    "cron/run_today_topics_morning_per_user.php"
    "cron/send_today_topics_to_user.php"
    "cron/run_today_topics_test_once.php"
)

count=0
errors=0
for file in "${files[@]}"; do
    if upload_file "$file"; then
        count=$((count + 1))
    else
        errors=$((errors + 1))
    fi
done

echo "Done. $count file(s) uploaded, $errors skipped."
