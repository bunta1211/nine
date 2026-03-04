<?php
/**
 * 今日の話題 クリック記録API
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md セクション 3.4
 *
 * 本日のニューストピックス内の「詳細を見る」クリックを today_topic_clicks に記録する。
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST : [];
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'record' && $method === 'POST') {
    $external_url = trim((string)($input['external_url'] ?? $input['url'] ?? ''));
    $source = mb_substr(trim((string)($input['source'] ?? '')), 0, 100);
    $category_or_keywords = mb_substr(trim((string)($input['category_or_keywords'] ?? '')), 0, 500);
    $topic_id = mb_substr(trim((string)($input['topic_id'] ?? '')), 0, 255);

    if ($external_url === '' && $topic_id === '') {
        errorResponse('external_url または topic_id を指定してください', 400);
    }

    if ($external_url !== '' && !preg_match('#^https?://#i', $external_url)) {
        errorResponse('external_url は有効なURLを指定してください', 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO today_topic_clicks (user_id, topic_id, external_url, source, category_or_keywords, clicked_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $topic_id !== '' ? $topic_id : null,
            $external_url !== '' ? $external_url : null,
            $source !== '' ? $source : null,
            $category_or_keywords !== '' ? $category_or_keywords : null,
        ]);
        successResponse(['recorded' => true]);
    } catch (Throwable $e) {
        error_log("today_topic_click record error: " . $e->getMessage());
        errorResponse('記録に失敗しました', 500);
    }
}

errorResponse('不正なリクエストです', 400);
