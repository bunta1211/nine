<?php
/**
 * プッシュ通知のローカル設定（VAPIDキー）
 * 
 * 使い方:
 * 1. このファイルを push.local.php にコピー
 * 2. php config/generate_vapid_keys.php でキーを生成
 * 3. 生成されたキーを下記に貼り付け
 * 4. push.local.php は .gitignore に追加して外部に公開しないこと
 */

define('VAPID_PUBLIC_KEY', 'ここに公開鍵を貼り付け');
define('VAPID_PRIVATE_KEY', 'ここに秘密鍵を貼り付け');
define('VAPID_SUBJECT', 'mailto:admin@yourdomain.com');
