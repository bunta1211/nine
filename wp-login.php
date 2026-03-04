<?php
/**
 * ハニーポット - WordPress偽ログイン
 * 攻撃者がWordPressと思い込んでアクセスした場合に記録
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

$pdo = getDB();
$security = getSecurity();

// アクセスを記録
$security->logEvent('suspicious_activity', 'critical', [
    'description' => 'WordPress偽ログインページへのアクセス（攻撃者）',
    'resource' => '/wp-login.php'
]);

// 即座にブロック（48時間）
$security->blockIP(
    $security->getClientIP(),
    'WordPress攻撃試行',
    2880
);

// WordPressエラー画面を模倣
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <title>Page Not Found - Error 404</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f1f1f1; margin: 0; padding: 0; }
        .error-container { max-width: 600px; margin: 100px auto; background: white; padding: 40px; }
        h1 { color: #23282d; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Error 404 - Not Found</h1>
        <p>The page you are looking for does not exist.</p>
    </div>
    <script>
    navigator.sendBeacon('/api/security.php?action=honeypot_collect', JSON.stringify({
        page: 'wp-login',
        screen: screen.width + 'x' + screen.height,
        language: navigator.language,
        platform: navigator.platform,
        referrer: document.referrer
    }));
    </script>
</body>
</html>
