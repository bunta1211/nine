<?php
/**
 * アプリケーション設定
 * 仕様書: 18_技術構成.md
 * 
 * ローカル設定:
 * - app.local.php が存在する場合、そちらの設定を優先
 * - app.local.example.php を参考にローカル設定を作成可能
 */

// ローカル設定ファイルがあれば読み込む（環境固有の設定）
$localConfig = __DIR__ . '/app.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}
// 金庫用マスターキー: vault.local.php を PHP 実行せずテキストで読み取りキーだけ抽出（パースエラーで白画面にならない）
if (!defined('VAULT_MASTER_KEY')) {
    $vaultLocal = __DIR__ . '/vault.local.php';
    if (file_exists($vaultLocal) && is_readable($vaultLocal)) {
        $vaultContent = @file_get_contents($vaultLocal);
        if ($vaultContent !== false) {
            if (preg_match('/define\s*\(\s*[\'"](?:VAULT_MASTER_KEY)[\'"]\s*,\s*[\'"]([a-fA-F0-9]{64})[\'"]\s*\)/', $vaultContent, $m)) {
                define('VAULT_MASTER_KEY', $m[1]);
            } elseif (preg_match('/define\s*\(\s*[\'"](?:VAULT_MASTER_KEY)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $vaultContent, $m) && preg_match('/^[a-fA-F0-9]{32,64}$/', trim($m[1]))) {
                define('VAULT_MASTER_KEY', trim($m[1]));
            }
        }
    }
}

// 環境設定（development / production）
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', APP_ENV === 'development');
}

// アプリケーション情報
define('APP_NAME', 'Social9');
define('APP_VERSION', '1.0.0');
if (!defined('APP_URL')) {
    define('APP_URL', getenv('APP_URL') ?: 'http://localhost/nine');
}

// 個人アドレス帳・検索で表示するシステム管理者アカウント（1件に統一。Bunta）
if (!defined('SYSTEM_ADMIN_EMAIL')) {
    define('SYSTEM_ADMIN_EMAIL', 'saitanibunta@social9.jp');
}

// 認証レベル
if (!defined('AUTH_LEVEL_EMAIL')) {
    define('AUTH_LEVEL_EMAIL', 1);
    define('AUTH_LEVEL_PHONE', 2);
    define('AUTH_LEVEL_IDENTITY', 3);
}

// ファイルアップロード制限
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE_GENERAL', 10 * 1024 * 1024);     // 10MB
define('MAX_FILE_SIZE_ORGANIZATION', 500 * 1024 * 1024); // 500MB
define('MAX_STORAGE_GENERAL', 1 * 1024 * 1024 * 1024);  // 1GB
define('MAX_STORAGE_ORGANIZATION', 10 * 1024 * 1024 * 1024); // 10GB

// Jitsi Meet設定
define('JITSI_DOMAIN', 'meet.jit.si');
define('JITSI_APP_ID', 'social9');
if (!defined('JITSI_BASE_URL')) {
    define('JITSI_BASE_URL', 'https://' . JITSI_DOMAIN . '/');
}

// セッション設定（全デバイスで常時ログオン・ログアウトするまで維持）
define('SESSION_LIFETIME', 86400 * 30); // 30日
define('SESSION_NAME', 'social9_session');

// パスワード設定
define('PASSWORD_MIN_LENGTH', 8);

// グループ設定
define('MAX_GROUP_MEMBERS', 50);
define('MAX_MESSAGE_LENGTH', 5000);

// レート制限
define('RATE_LIMIT_MESSAGES', 60); // 1分間のメッセージ数上限
define('RATE_LIMIT_API', 100);     // 1分間のAPI呼び出し上限

// タイムゾーン（表示・PHPの日時は日本時間）
date_default_timezone_set('Asia/Tokyo');
// DBに保存されている日時のタイムゾーン（チャットの送信時刻表示・「○時間前」に使用）
// AWS RDS等は UTC、レンタルサーバーで日本時間の場合は app.local.php で 'Asia/Tokyo' を指定
if (!defined('DB_STORAGE_TIMEZONE')) {
    define('DB_STORAGE_TIMEZONE', 'UTC');
}

/**
 * MySQLの日時をクライアント用ISO 8601に変換（ブラウザで現在時刻通りに表示するため）
 * @param string|null $mysqlDatetime 例: 2026-02-12 07:06:00
 * @return string 例: 2026-02-12T07:06:00Z (UTC) または 2026-02-12T16:06:00+09:00 (JST)
 */
function formatDatetimeForClient($mysqlDatetime) {
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return $mysqlDatetime;
    }
    $tz = defined('DB_STORAGE_TIMEZONE') ? DB_STORAGE_TIMEZONE : 'UTC';
    try {
        $dt = new DateTime($mysqlDatetime, new DateTimeZone($tz));
        return $dt->format('c');
    } catch (Exception $e) {
        return $mysqlDatetime;
    }
}

// エラーレポート（本番では表示を無効化・セキュリティ対策）
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);  // 本番: 内部エラーを画面に表示しない
}

// ログディレクトリ
define('LOG_DIR', __DIR__ . '/../logs/');
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// 今日の話題：朝の配信対象。DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md
// - TODAY_TOPICS_MORNING_FIXED_USER_IDS: 毎朝7時に必ず配信する user_id の JSON 配列（例: KEN=6, Yusei, Naomi の ID を指定）
// - TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK: true のとき、過去1週間アクティブなユーザー（today_topics_morning_enabled=1 かつ 7時希望）にも配信
// - 朝の配信は 7 時のみ実行（cron は 0 7 * * *）
if (!defined('TODAY_TOPICS_MORNING_FIXED_USER_IDS')) {
    define('TODAY_TOPICS_MORNING_FIXED_USER_IDS', '[6]'); // KEN (6)。Yusei・Naomi を追加する場合は app.local.php で [6, id_yusei, id_naomi]
}
if (!defined('TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK')) {
    define('TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK', true); // 過去1週間アクティブなユーザーにも配信
}
// 後方互換: 未定義なら固定IDのみが対象だった頃の挙動に使う（空で全員）
if (!defined('TODAY_TOPICS_LIMIT_USER_IDS')) {
    define('TODAY_TOPICS_LIMIT_USER_IDS', '');
}

// 金庫暗号化用マスターキー
if (!defined('VAULT_MASTER_KEY')) {
    $vaultKey = getenv('VAULT_MASTER_KEY');
    $vaultKey = ($vaultKey !== false && $vaultKey !== '') ? $vaultKey : null;
    if ($vaultKey === null && APP_ENV !== 'production') {
        $vaultKey = 'dev-vault-master-key-change-in-production';
    }
    if (($vaultKey === null || $vaultKey === '') && function_exists('random_bytes')) {
        $vaultLocal = __DIR__ . '/vault.local.php';
        if (is_writable(__DIR__) && (!file_exists($vaultLocal) || is_writable($vaultLocal))) {
            $newKey = bin2hex(random_bytes(32));
            $content = "<?php\n/** Auto-generated vault key. Do not commit. */\ndefine('VAULT_MASTER_KEY', '" . $newKey . "');\n";
            if (@file_put_contents($vaultLocal, $content, LOCK_EX) !== false) {
                $vaultKey = $newKey;
            }
        }
    }
    if ($vaultKey === null || $vaultKey === '') {
        $vaultKey = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678abcdef0123456789abcdef01';
    }
    define('VAULT_MASTER_KEY', $vaultKey);
}

/**
 * エラーログを記録
 */
function logError($message, $context = []) {
    $log = date('Y-m-d H:i:s') . ' [ERROR] ' . $message;
    if (!empty($context)) {
        $log .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($log . PHP_EOL, 3, LOG_DIR . 'error.log');
}

/**
 * 情報ログを記録
 */
function logInfo($message, $context = []) {
    if (!APP_DEBUG) return;
    
    $log = date('Y-m-d H:i:s') . ' [INFO] ' . $message;
    if (!empty($context)) {
        $log .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($log . PHP_EOL, 3, LOG_DIR . 'app.log');
}

/**
 * リダイレクト用のベースURLを取得（サーバー移転・リバースプロキシ対応）
 */
function getBaseUrl() {
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return '';
}

/**
 * 携帯・スマートフォンからのリクエストかどうか（User-Agent ベース）
 * 携帯版ではグループチャット一覧がトップページの役割となる
 */
function is_mobile_request() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i', $ua);
}
