<?php
/**
 * ユーザー詳細（リダイレクト）
 * 所属グループ確認ページへ転送します。旧リンク・ブックマーク対策。
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireSystemAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// 絶対パスでリダイレクト（サブディレクトリでも動くよう /admin/ を使用）
$targetUrl = $id > 0 ? '/admin/user_groups.php?user_id=' . $id : '/admin/users.php';

if (!headers_sent()) {
    header('Location: ' . $targetUrl);
    exit;
}
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($targetUrl) ?>"></head>
<body><p>所属グループのページに移動します。<br><a href="<?= htmlspecialchars($targetUrl) ?>">クリックして移動</a></p></body></html>
