# EC2 に show_mysql_connection.php を置く手順

## 方法1: ローカルPCから SCP でアップロード（推奨）

ローカルでプロジェクトがある場合（例: `c:\xampp\htdocs\nine`）、PowerShell またはコマンドプロンプトで:

```bash
scp -i "あなたのキーペア.pem" c:\xampp\htdocs\nine\config\show_mysql_connection.php ec2-user@ip-172-31-6-133.amazonaws.com:/var/www/html/config/
```

- `あなたのキーペア.pem` を実際のキーファイル名に変更
- ホストは EC2 のパブリック IP または DNS に合わせて変更

---

## 方法2: EC2 上でファイルを新規作成（コピー＆ペースト）

EC2 に SSH 接続した状態で、次のコマンドを**まとめてコピーして**貼り付け、Enter を 1 回押す。

```bash
cat << 'ENDOFFILE' > /var/www/html/config/show_mysql_connection.php
<?php
/**
 * DB設定から MySQL 接続用の -u / データベース名 を表示（コピー用）
 * パスワードは表示しない。実行時に -p で入力する。
 *
 * 使い方（プロジェクトルートで）:
 *   php config/show_mysql_connection.php
 *
 * 本番サーバーでは、config/database.aws.php が存在すればその値が使われます。
 * Web から実行されないよう、CLI 専用にしています。
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI 専用です。ターミナルで: php config/show_mysql_connection.php\n");
    exit(1);
}

$configDir = __DIR__;
require $configDir . '/database.php';

$host = defined('DB_HOST') ? DB_HOST : '';
$name = defined('DB_NAME') ? DB_NAME : '';
$user = defined('DB_USER') ? DB_USER : '';

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDERR, "DB_HOST / DB_NAME / DB_USER が設定されていません。config/database.aws.php または環境変数を確認してください。\n");
    exit(1);
}

echo "\n";
echo "========== コピー用: MySQL 接続オプション ==========\n";
echo "\n";
echo "  -h " . $host . " -u " . $user . " -p " . $name . "\n";
echo "\n";
echo "========== 実行例（パスワードは実行後に入力） ==========\n";
echo "\n";
echo "  mysql -h " . $host . " -u " . $user . " -p " . $name . "\n";
echo "\n";
echo "接続後、以下を実行:\n";
echo "\n";
echo "  ALTER TABLE conversation_members\n";
echo "    ADD COLUMN last_read_message_id INT UNSIGNED NULL DEFAULT NULL AFTER last_read_at,\n";
echo "    ADD INDEX idx_last_read_message_id (last_read_message_id);\n";
echo "\n";
echo "========== 1行で実行する場合（-e で SQL を渡す） ==========\n";
echo "\n";
$alterOneLine = "ALTER TABLE conversation_members ADD COLUMN last_read_message_id INT UNSIGNED NULL DEFAULT NULL AFTER last_read_at, ADD INDEX idx_last_read_message_id (last_read_message_id);";
echo "  mysql -h " . $host . " -u " . $user . " -p " . $name . " -e \"" . $alterOneLine . "\"\n";
echo "\n";

echo "========== icon_style マイグレーション（コピーして実行、-p でパスワード入力） ==========\n";
echo "\n";
$iconMigration = "ALTER TABLE conversations ADD COLUMN icon_style VARCHAR(50) DEFAULT 'default' AFTER icon_path; ALTER TABLE conversations ADD COLUMN icon_pos_x FLOAT DEFAULT 0 AFTER icon_style; ALTER TABLE conversations ADD COLUMN icon_pos_y FLOAT DEFAULT 0 AFTER icon_pos_x; ALTER TABLE conversations ADD COLUMN icon_size INT DEFAULT 100 AFTER icon_pos_y;";
echo "  mysql -h " . $host . " -u " . $user . " -p " . $name . " -e \"" . $iconMigration . "\"\n";
echo "\n";
ENDOFFILE
```

貼り付けたら **Enter を 1 回だけ**押す（途中で Enter しないこと）。  
作成できたら次で確認:

```bash
php /var/www/html/config/show_mysql_connection.php
```

MySQL 接続用の行と icon_style マイグレーション用の行が表示されれば成功です。
