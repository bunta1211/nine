<?php
/**
 * chat.php の動作診断（本番でエラー確認用）
 * 使用後は削除すること
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/plain; charset=utf-8');
echo "=== chat.php 診断 ===\n\n";

try {
    echo "1. config/session.php ... ";
    require_once __DIR__ . '/config/session.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " (Line " . $e->getLine() . ")\n";
    exit;
}

try {
    echo "2. config/database.php ... ";
    require_once __DIR__ . '/config/database.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "3. config/app.php ... ";
    require_once __DIR__ . '/config/app.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "4. includes/asset_helper.php ... ";
    require_once __DIR__ . '/includes/asset_helper.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "5. config/ai_config.php ... ";
    require_once __DIR__ . '/config/ai_config.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "6. config/push.php ... ";
    require_once __DIR__ . '/config/push.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "7. includes/design_loader.php ... ";
    require_once __DIR__ . '/includes/design_loader.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "8. includes/lang.php ... ";
    require_once __DIR__ . '/includes/lang.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "9. includes/chat/data.php ... ";
    require_once __DIR__ . '/includes/chat/data.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    echo "10. DB接続 ... ";
    $pdo = getDB();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

echo "\n=== 全チェック完了 ===\n";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'N/A') . "\n";
echo "APP_ENV: " . (defined('APP_ENV') ? APP_ENV : 'N/A') . "\n";
echo "APP_DEBUG: " . (defined('APP_DEBUG') ? (APP_DEBUG ? 'true' : 'false') : 'N/A') . "\n";
