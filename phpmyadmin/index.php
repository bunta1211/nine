<?php
/**
 * ハニーポット - phpMyAdmin偽ページ
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = getDB();
$security = getSecurity();

$security->logEvent('suspicious_activity', 'critical', [
    'description' => 'phpMyAdmin偽ページへのアクセス（攻撃者）',
    'resource' => '/phpmyadmin/'
]);

// 即座にブロック
$security->blockIP($security->getClientIP(), 'phpMyAdmin攻撃試行', 4320);

http_response_code(403);
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head><title>403 Forbidden</title></head>
<body>
<h1>Forbidden</h1>
<p>You don't have permission to access this resource.</p>
<script>
navigator.sendBeacon('/api/security.php?action=honeypot_collect', JSON.stringify({
    page: 'phpmyadmin',
    screen: screen.width + 'x' + screen.height,
    language: navigator.language
}));
</script>
</body>
</html>
