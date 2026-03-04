<?php
/**
 * Web Push通知設定
 * 
 * VAPIDキー:
 * - push.local.php が存在する場合、そちらのキーを優先
 * - キー生成: php config/generate_vapid_keys.php
 * - 既存キーで「invalid JWT provided」が出る場合はキー再生成が必要
 */

// ローカル設定（本番用VAPIDキーなど）
$pushLocal = __DIR__ . '/push.local.php';
if (file_exists($pushLocal)) {
    require_once $pushLocal;
}

// VAPID設定（Web Push認証用）
// 重要: 本番環境では必ず独自のキーを生成してください
if (!defined('VAPID_PUBLIC_KEY')) {
    // 公開鍵（クライアント側で使用）- 2026/02 再生成分
    define('VAPID_PUBLIC_KEY', 'BJ94T5E7J70zYC570YI0vLcbtezgf62k1imQ1g4oxvZYuqHeUegrOkBTv7ErLa2SlXj4-Q_XOYIQA7mjjkLLvPc');
}

if (!defined('VAPID_PRIVATE_KEY')) {
    // 秘密鍵（サーバー側で使用）- 2026/02 再生成分
    define('VAPID_PRIVATE_KEY', 'DpSqR8FtDHaUtx4tG-5xS1gUyQpiMb9NX53-guv20n8');
}

if (!defined('VAPID_SUBJECT')) {
    // 連絡先（mailto: または https:// のURL）
    define('VAPID_SUBJECT', 'mailto:admin@social9.example.com');
}

// プッシュ通知のデフォルト設定
if (!defined('PUSH_DEFAULT_TTL')) {
    define('PUSH_DEFAULT_TTL', 86400); // 24時間
}

if (!defined('PUSH_DEFAULT_URGENCY')) {
    define('PUSH_DEFAULT_URGENCY', 'normal'); // low, normal, high, very-low
}

/**
 * VAPIDキーをURLセーフBase64にエンコード
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * URLセーフBase64をデコード
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}
