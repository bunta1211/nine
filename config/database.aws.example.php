<?php
/**
 * AWS（EC2）環境用 DB 設定
 * 
 * PHP-FPM で SetEnv が渡らない場合に使用
 * 
 * 使用方法:
 * 1. このファイルを database.aws.php にコピー
 * 2. 値を EC2 の RDS 情報に合わせて設定
 * 3. database.aws.php は .gitignore に追加（パスワードを含むため）
 */
// このファイルを database.aws.php にコピーして使用すること
if (!defined('DB_HOST')) {
    define('DB_HOST', 'database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com');
    define('DB_NAME', 'social9');
    define('DB_USER', 'admin');
    define('DB_PASS', 'RDSのパスワードをここに'); // db-env.conf の DB_PASS と同じ値
}
