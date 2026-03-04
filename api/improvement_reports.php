<?php
/**
 * 改善提案API
 * create: 手動で新規提案を作成（管理者のみ）
 * get: 1件取得（管理者のみ・Cursor用コピー用）
 * mark_done: 対応済みにして報告者に通知（管理者のみ）
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 管理者のみ許可
if (!function_exists('isOrgAdminUser') || !isOrgAdminUser()) {
    errorResponse('管理者権限が必要です', 403);
}

$pdo = getDB();

// テーブル存在チェック
$hasTable = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'improvement_reports'");
    $hasTable = (bool) $stmt->fetch();
} catch (PDOException $e) {}

if (!$hasTable) {
    echo json_encode([
        'success' => false,
        'message' => 'improvement_reports テーブルが存在しません。database/improvement_reports.sql を実行してください。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'create': {
        $title = trim((string)($_POST['title'] ?? ''));
        $problem_summary = trim((string)($_POST['problem_summary'] ?? ''));
        if ($title === '' || $problem_summary === '') {
            echo json_encode(['success' => false, 'message' => 'タイトルと問題の内容は必須です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ui_location = trim((string)($_POST['ui_location'] ?? ''));
        $suspected_location = trim((string)($_POST['suspected_location'] ?? ''));
        $suggested_fix = trim((string)($_POST['suggested_fix'] ?? ''));
        $related_files = trim((string)($_POST['related_files'] ?? ''));
        $stmt = $pdo->prepare("
            INSERT INTO improvement_reports (user_id, title, problem_summary, ui_location, suspected_location, suggested_fix, related_files, status, source)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, 'pending', 'manual')
        ");
        $stmt->execute([$title, $problem_summary, $ui_location ?: null, $suspected_location ?: null, $suggested_fix ?: null, $related_files ?: null]);
        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'id を指定してください'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, user_id, title, problem_summary, ui_location, suspected_location, suggested_fix, related_files, status, source, created_at FROM improvement_reports WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => '該当する提案がありません'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        echo json_encode(['success' => true, 'report' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    case 'mark_done': {
        $report_id = (int)($_POST['report_id'] ?? 0);
        if ($report_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'report_id を指定してください'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, user_id FROM improvement_reports WHERE id = ? AND status = 'pending'");
        $stmt->execute([$report_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => '該当する未対応の提案がありません'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare("UPDATE improvement_reports SET status = 'done', updated_at = NOW() WHERE id = ?")->execute([$report_id]);
        $user_id = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        if ($user_id) {
            try {
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, content, related_type, related_id) VALUES (?, 'system', ?, ?, 'improvement_report', ?)");
                $notifStmt->execute([
                    $user_id,
                    '改善提案のご報告',
                    'ご提出いただいた改善提案が受理され、改善が完了しました。ご確認ください。',
                    $report_id
                ]);
            } catch (PDOException $e) {
                error_log('improvement_reports mark_done notification insert failed: ' . $e->getMessage());
            }
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    default:
        echo json_encode(['success' => false, 'message' => '不正な action です'], JSON_UNESCAPED_UNICODE);
        exit;
}
