<?php
if (php_sapi_name() !== 'cli') die('CLI only');
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();
$r = $pdo->query("SHOW COLUMNS FROM users WHERE Field='id'");
$col = $r->fetch(PDO::FETCH_ASSOC);
echo "users.id type: " . $col['Type'] . "\n";

$r2 = $pdo->query("SHOW COLUMNS FROM conversations WHERE Field='id'");
$col2 = $r2->fetch(PDO::FETCH_ASSOC);
echo "conversations.id type: " . $col2['Type'] . "\n";
