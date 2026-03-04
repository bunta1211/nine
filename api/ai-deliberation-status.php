<?php
/**
 * 熟慮モード 進行状況取得API
 * ポーリングでフロントエンドに進行ログを返す
 */
header('Content-Type: application/json; charset=utf-8');

define('IS_API', true);

try {
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/api-helpers.php';
    require_once __DIR__ . '/../includes/deliberation_helper.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'サーバーエラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
$afterLine = max(0, (int)($_GET['after_line'] ?? 0));

if (empty($sessionId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionId)) {
    echo json_encode(['success' => false, 'message' => '無効なセッションID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logData = deliberationReadLog($sessionId, $afterLine);
$result  = deliberationReadResult($sessionId);

$response = [
    'success'  => true,
    'lines'    => $logData['lines'],
    'total'    => (int)$logData['total'],
    'finished' => ($result !== null),
];

if ($result !== null) {
    $response['result'] = $result;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
