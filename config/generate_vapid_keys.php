<?php
/**
 * VAPIDキー生成スクリプト
 * 実行: php config/generate_vapid_keys.php
 * 出力されたキーを config/push.local.php に設定してください
 */

require_once __DIR__ . '/../vendor/autoload.php';

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo "\n=== VAPID キーが生成されました ===\n\n";
echo "config/push.local.php を作成し、以下を記述してください:\n\n";
echo "<?php\n";
echo "/** VAPIDキー（本番用・外部に公開しないこと） */\n";
echo "define('VAPID_PUBLIC_KEY', '" . $keys['publicKey'] . "');\n";
echo "define('VAPID_PRIVATE_KEY', '" . $keys['privateKey'] . "');\n";
echo "define('VAPID_SUBJECT', 'mailto:あなたのメール@example.com');\n";
echo "\n";
echo "--- 公開鍵（クライアント用） ---\n";
echo $keys['publicKey'] . "\n";
echo "\n--- 秘密鍵（サーバー用・絶対に公開しないこと） ---\n";
echo $keys['privateKey'] . "\n";
echo "\n※ キーを変更すると既存のプッシュ購読は無効になります。\n";
echo "  DBの push_subscriptions をクリアするか、ユーザーに再度「有効にする」を押してもらってください。\n\n";
