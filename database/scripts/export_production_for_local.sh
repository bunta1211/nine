#!/bin/bash
# 本番 RDS をダンプし、ローカル取り込み用ファイルを作る（EC2 上で実行）
# 使い方:
#   EC2 に SSH したあと:
#   chmod +x /var/www/html/database/scripts/export_production_for_local.sh
#   cd /var/www/html && ./database/scripts/export_production_for_local.sh
# パスワード: /etc/httpd/conf.d/db-env.conf の DB_PASS を環境変数 DB_PASS に設定するか、プロンプトで入力

set -e
RDS_HOST="${RDS_HOST:-database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com}"
DB_NAME="${DB_NAME:-social9}"
DB_USER="${DB_USER:-admin}"
OUTPUT_DIR="${OUTPUT_DIR:-/home/ec2-user}"
STAMP=$(date +%Y%m%d_%H%M%S)
OUTPUT_FILE="${OUTPUT_DIR}/social9_production_${STAMP}.sql"

if [ -z "$DB_PASS" ]; then
  echo "本番DBのパスワードを設定してください: export DB_PASS='あなたのパスワード'"
  echo "または、次の mysqldump のプロンプトで入力してください。"
fi

echo "Exporting ${DB_NAME} from ${RDS_HOST} to ${OUTPUT_FILE} ..."
mysqldump -h "$RDS_HOST" -P 3306 -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction \
  --routines \
  --triggers \
  "$DB_NAME" > "$OUTPUT_FILE"

gzip -f "$OUTPUT_FILE"
echo "Done: ${OUTPUT_FILE}.gz"
echo "ローカルにダウンロードする例（Windows PowerShell）:"
echo "  scp -i \"C:\\Users\\narak\\Desktop\\social9-key.pem\" ec2-user@54.95.86.79:${OUTPUT_FILE}.gz c:\\xampp\\htdocs\\nine\\database\\backup\\"
