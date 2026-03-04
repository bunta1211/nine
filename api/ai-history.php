<?php
/**
 * AI会話履歴取得API
 * ai.php が 500 になる対策として独立エンドポイント化
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/api-helpers.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

try {
    $limit = min(max((int)($_GET['limit'] ?? 20), 1), 50);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $stmt = $pdo->prepare("
        SELECT id, user_id, question, answer, answered_by, created_at
        FROM ai_conversations
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ");
    $stmt->execute([$user_id]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    successResponse(['conversations' => $conversations]);
} catch (Throwable $e) {
    error_log("AI history error: " . $e->getMessage());
    successResponse(['conversations' => []]);
}
