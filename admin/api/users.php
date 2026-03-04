<?php
/**
 * システム管理 - ユーザー API
 * 取得・更新・削除（重複アカウントの修正・削除用）
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

start_session_once();
requireLogin();

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'システム管理者のみ利用できます'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
    $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
    if ($safeTable === '' || $safeColumn === '') return false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE " . $pdo->quote($safeColumn));
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function jsonErr($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonOk($data = [], $message = null) {
    $out = ['success' => true];
    if ($message !== null) $out['message'] = $message;
    echo json_encode(array_merge($out, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // GET ?id= 1件取得
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id <= 0) jsonErr('無効なID', 400);
        $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
        $cols = 'id, email, display_name, status, role, created_at, updated_at, online_status, organization_id';
        if ($hasFullName) $cols .= ', full_name';
        $stmt = $pdo->prepare("SELECT {$cols} FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonErr('ユーザーが見つかりません', 404);
        $user['id'] = (int)$user['id'];
        $user['organization_id'] = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
        $user['has_full_name'] = $hasFullName;
        jsonOk(['user' => $user]);
    }

    // PUT 更新
    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) jsonErr('id を指定してください', 400);
        $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) jsonErr('ユーザーが見つかりません', 404);

        $updates = [];
        $params = [];
        if (isset($body['display_name'])) {
            $v = trim((string)$body['display_name']);
            if ($v === '') jsonErr('表示名は空にできません', 400);
            $updates[] = 'display_name = ?';
            $params[] = $v;
        }
        if (isset($body['email'])) {
            $v = trim((string)$body['email']);
            if ($v === '') jsonErr('メールアドレスは空にできません', 400);
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) jsonErr('メールアドレスの形式が不正です', 400);
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$v, $id]);
            if ($chk->fetch()) jsonErr('このメールアドレスは既に別のユーザーで使用されています', 400);
            $updates[] = 'email = ?';
            $params[] = $v;
        }
        if ($hasFullName && array_key_exists('full_name', $body)) {
            $updates[] = 'full_name = ?';
            $params[] = trim((string)($body['full_name'] ?? ''));
        }
        if (isset($body['status']) && in_array($body['status'], ['active', 'suspended', 'deleted'], true)) {
            $updates[] = 'status = ?';
            $params[] = $body['status'];
        }
        if (isset($body['role'])) {
            $r = $body['role'];
            $allowed = ['user', 'org_admin', 'system_admin', 'developer', 'admin', 'super_admin'];
            if (tableHasColumn($pdo, 'users', 'role')) {
                $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && preg_match_all("/'([^']+)'/", $row['Type'], $m)) {
                    $allowed = $m[1];
                }
                if (in_array($r, $allowed, true)) {
                    $updates[] = 'role = ?';
                    $params[] = $r;
                }
            }
        }

        if (empty($updates)) jsonErr('変更する項目がありません', 400);
        $params[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
        jsonOk(['id' => $id], '保存しました');
    }

    // DELETE 削除（ソフト削除: status = 'deleted'）
    if ($method === 'DELETE') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) jsonErr('id を指定してください', 400);
        $selfId = (int)($_SESSION['user_id'] ?? 0);
        if ($id === $selfId) jsonErr('自分自身のアカウントは削除できません', 400);

        $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonErr('ユーザーが見つかりません', 404);

        if (!tableHasColumn($pdo, 'users', 'status')) {
            jsonErr('status カラムが存在しません', 500);
        }
        $pdo->prepare("UPDATE users SET status = 'deleted', updated_at = NOW() WHERE id = ?")->execute([$id]);
        jsonOk(['id' => $id], 'アカウントを無効化しました（削除済み扱い）');
    }
} catch (Throwable $e) {
    error_log('admin/api/users.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
