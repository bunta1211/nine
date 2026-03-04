<?php
/**
 * AI安全通報管理API
 * 
 * 運営責任者（KEN）向け。通報の一覧・詳細・ステータス変更・追加質問。
 * 計画書 6.1 に基づき、運営責任者のみアクセス可能。
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_safety_reporter.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です']);
    exit;
}

if (!isSystemAdmin($userId)) {
    http_response_code(403);
    echo json_encode(['error' => '運営責任者のみアクセスできます']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'detail':
        handleDetail();
        break;
    case 'update_status':
        handleUpdateStatus($userId);
        break;
    case 'ask_question':
        handleAskQuestion($userId);
        break;
    case 'get_questions':
        handleGetQuestions();
        break;
    case 'stats':
        handleStats();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '不明なアクション']);
}

function handleList() {
    $status = $_GET['status'] ?? null;
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $reports = getSafetyReports($status, $limit, $offset);
    foreach ($reports as &$r) {
        $r['id'] = (int)$r['id'];
        $r['user_id'] = (int)$r['user_id'];
        $r['organization_id'] = $r['organization_id'] ? (int)$r['organization_id'] : null;
    }

    echo json_encode(['reports' => $reports], JSON_UNESCAPED_UNICODE);
}

function handleDetail() {
    $reportId = (int)($_GET['id'] ?? 0);
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT r.*, u.display_name AS user_display_name, u.username
        FROM ai_safety_reports r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
    ");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode(['error' => '通報が見つかりません']);
        return;
    }

    $report['id'] = (int)$report['id'];
    $report['user_id'] = (int)$report['user_id'];
    $report['organization_id'] = $report['organization_id'] ? (int)$report['organization_id'] : null;
    $report['user_personality_snapshot'] = json_decode($report['user_personality_snapshot'] ?? '{}', true);
    $report['user_social_context'] = json_decode($report['user_social_context'] ?? '{}', true);

    $qStmt = $pdo->prepare("
        SELECT q.*, u.display_name AS asked_by_name
        FROM ai_safety_report_questions q
        LEFT JOIN users u ON u.id = q.asked_by
        WHERE q.report_id = ?
        ORDER BY q.created_at ASC
    ");
    $qStmt->execute([$reportId]);
    $report['questions'] = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($report, JSON_UNESCAPED_UNICODE);
}

function handleUpdateStatus($reviewedBy) {
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = (int)($input['id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    $notes = trim($input['notes'] ?? '');

    $validStatuses = ['new', 'reviewing', 'resolved', 'dismissed'];
    if (!in_array($newStatus, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['error' => '無効なステータスです']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE ai_safety_reports SET
            status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $reviewedBy, $notes, $reportId]);

    echo json_encode(['message' => 'ステータスを更新しました'], JSON_UNESCAPED_UNICODE);
}

function handleAskQuestion($askedBy) {
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = (int)($input['report_id'] ?? 0);
    $question = trim($input['question'] ?? '');

    if (empty($question)) {
        http_response_code(400);
        echo json_encode(['error' => '質問内容を入力してください']);
        return;
    }

    $questionId = askSecretaryQuestion($reportId, $askedBy, $question);

    $answer = answerSecretaryQuestion($questionId);

    echo json_encode([
        'question_id' => $questionId,
        'answer'      => $answer,
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetQuestions() {
    $reportId = (int)($_GET['report_id'] ?? 0);
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT q.*, u.display_name AS asked_by_name
        FROM ai_safety_report_questions q
        LEFT JOIN users u ON u.id = q.asked_by
        WHERE q.report_id = ?
        ORDER BY q.created_at ASC
    ");
    $stmt->execute([$reportId]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

function handleStats() {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status = 'reviewing' THEN 1 ELSE 0 END) AS reviewing_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) AS dismissed_count,
            SUM(CASE WHEN severity = 'critical' AND status = 'new' THEN 1 ELSE 0 END) AS critical_new
        FROM ai_safety_reports
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    foreach ($stats as &$v) { $v = (int)$v; }

    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
}

function isSystemAdmin($userId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && $user['role'] === 'admin';
}
