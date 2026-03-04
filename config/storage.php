<?php
/**
 * 共有フォルダ（Shared Folder）設定
 */

// ローカル設定がある場合は優先
$storageLocal = __DIR__ . '/storage.local.php';
if (file_exists($storageLocal)) {
    require_once $storageLocal;
}

// AWS S3
if (!defined('STORAGE_S3_BUCKET'))  define('STORAGE_S3_BUCKET',  getenv('STORAGE_S3_BUCKET')  ?: 'social9-storage');
if (!defined('STORAGE_S3_REGION'))  define('STORAGE_S3_REGION',  getenv('STORAGE_S3_REGION')  ?: 'ap-northeast-1');
if (!defined('STORAGE_S3_KEY'))     define('STORAGE_S3_KEY',     getenv('STORAGE_S3_KEY')     ?: '');
if (!defined('STORAGE_S3_SECRET'))  define('STORAGE_S3_SECRET',  getenv('STORAGE_S3_SECRET')  ?: '');

// 署名付きURL有効期限（秒）
if (!defined('STORAGE_PRESIGN_UPLOAD_EXPIRY'))   define('STORAGE_PRESIGN_UPLOAD_EXPIRY',   900);   // 15分
if (!defined('STORAGE_PRESIGN_DOWNLOAD_EXPIRY')) define('STORAGE_PRESIGN_DOWNLOAD_EXPIRY', 3600);  // 1時間

// 容量
if (!defined('STORAGE_FREE_QUOTA'))      define('STORAGE_FREE_QUOTA',      2 * 1024 * 1024 * 1024);   // 2GB
if (!defined('STORAGE_MAX_FILE_SIZE'))    define('STORAGE_MAX_FILE_SIZE',   500 * 1024 * 1024);        // 500MB

// 容量無制限とする組織ID（例: Clover International = 6）。本番は storage.local.php で上書き可。
if (!defined('STORAGE_UNLIMITED_ORGANIZATION_IDS')) define('STORAGE_UNLIMITED_ORGANIZATION_IDS', [6]);
if (!defined('STORAGE_UNLIMITED_QUOTA')) define('STORAGE_UNLIMITED_QUOTA', PHP_INT_MAX);

// ブロックするファイル拡張子（Dropbox準拠）
if (!defined('STORAGE_BLOCKED_EXTENSIONS')) {
    define('STORAGE_BLOCKED_EXTENSIONS', 'exe,bat,cmd,sh,com,msi,scr,pif');
}

// ゴミ箱保持日数
if (!defined('STORAGE_TRASH_RETENTION_DAYS')) define('STORAGE_TRASH_RETENTION_DAYS', 30);

// ダウングレード猶予日数
if (!defined('STORAGE_DOWNGRADE_GRACE_DAYS')) define('STORAGE_DOWNGRADE_GRACE_DAYS', 30);

// 全銀データ用 委託者情報（自社）
if (!defined('ZENGIN_CONSIGNOR_CODE'))      define('ZENGIN_CONSIGNOR_CODE',      '0000000000');   // 郵便局から付与される委託者コード
if (!defined('ZENGIN_CONSIGNOR_NAME'))      define('ZENGIN_CONSIGNOR_NAME',      'ｼﾔｶﾙﾅｲﾝ');      // 委託者名（カナ）
if (!defined('ZENGIN_BANK_CODE'))           define('ZENGIN_BANK_CODE',           '9900');          // ゆうちょ銀行
if (!defined('ZENGIN_BANK_NAME'))           define('ZENGIN_BANK_NAME',           'ﾕｳﾁﾖ');          // 銀行名（カナ）
if (!defined('ZENGIN_BRANCH_CODE'))         define('ZENGIN_BRANCH_CODE',         '000');
if (!defined('ZENGIN_BRANCH_NAME'))         define('ZENGIN_BRANCH_NAME',         '');
if (!defined('ZENGIN_ACCOUNT_TYPE'))        define('ZENGIN_ACCOUNT_TYPE',        '1');             // 1:普通
if (!defined('ZENGIN_ACCOUNT_NUMBER'))      define('ZENGIN_ACCOUNT_NUMBER',      '0000000');
