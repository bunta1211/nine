<?php
/**
 * 熟慮モード進行状況ポーリングAPI
 * GET ?session_id=xxx&after=0
 */

define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api-helpers.php';
require_once __DIR__ . '/../includes/deliberation_helper.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未ログイン']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$sessionId = $_GET['session_id'] ?? '';
$afterLine = max(0, (int)($_GET['after'] ?? 0));

if (empty($sessionId) || !preg_match('/^[a-zA-Z0-9_-]{10,60}$/', $sessionId)) {
    echo json_encode(['success' => false, 'message' => '無効なセッションID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logData = deliberationReadLog($sessionId, $afterLine);
$result  = deliberationReadResult($sessionId);

echo json_encode([
    'success'  => true,
    'lines'    => $logData['lines'],
    'total'    => (int)$logData['total'],
    'done'     => ($result !== null),
    'result'   => $result
], JSON_UNESCAPED_UNICODE);
