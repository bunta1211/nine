<?php
/**
 * 専門AI管理API
 * 
 * 組織の専門AI設定の取得・更新・プロビジョニング。
 * 機能フラグの取得・更新。
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_specialist_router.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'list':
        handleList($userId);
        break;
    case 'update':
        handleUpdate($userId);
        break;
    case 'provision':
        handleProvision($userId);
        break;
    case 'flags':
        handleGetFlags();
        break;
    case 'update_flag':
        handleUpdateFlag($userId);
        break;
    case 'defaults':
        handleGetDefaults();
        break;
    case 'update_default':
        handleUpdateDefault($userId);
        break;
    case 'stats':
        handleStats($userId);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '不明なアクション']);
}

function handleList($userId) {
    $orgId = (int)($_GET['organization_id'] ?? 0);
    if (!$orgId || !isOrgMember($userId, $orgId)) {
        http_response_code(403);
        echo json_encode(['error' => 'アクセス権がありません']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM org_ai_specialists WHERE organization_id = ? ORDER BY specialist_type
    ");
    $stmt->execute([$orgId]);
    $specialists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($specialists as &$s) {
        $s['id'] = (int)$s['id'];
        $s['organization_id'] = (int)$s['organization_id'];
        $s['is_enabled'] = (int)$s['is_enabled'];
    }

    echo json_encode(['specialists' => $specialists], JSON_UNESCAPED_UNICODE);
}

function handleUpdate($userId) {
    $orgId = (int)($_REQUEST['organization_id'] ?? 0);
    if (!$orgId || !isOrgAdmin($userId, $orgId)) {
        http_response_code(403);
        echo json_encode(['error' => '管理者権限が必要です']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM org_ai_specialists WHERE id = ? AND organization_id = ?");
    $stmt->execute([$id, $orgId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => '見つかりません']);
        return;
    }

    $upd = $pdo->prepare("
        UPDATE org_ai_specialists SET
            display_name = ?, system_prompt = ?, custom_rules = ?, is_enabled = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $upd->execute([
        trim($input['display_name'] ?? ''),
        trim($input['system_prompt'] ?? '') ?: null,
        trim($input['custom_rules'] ?? '') ?: null,
        (int)($input['is_enabled'] ?? 1),
        $id,
    ]);

    echo json_encode(['message' => '更新しました'], JSON_UNESCAPED_UNICODE);
}

function handleProvision($userId) {
    $orgId = (int)($_REQUEST['organization_id'] ?? 0);
    if (!$orgId || !isOrgAdmin($userId, $orgId)) {
        http_response_code(403);
        echo json_encode(['error' => '管理者権限が必要です']);
        return;
    }

    provisionSpecialistsForOrg($orgId);
    echo json_encode(['message' => '専門AIを初期設定しました'], JSON_UNESCAPED_UNICODE);
}

function handleGetFlags() {
    $pdo = getDB();

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'ai_feature_flags'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        echo json_encode(['flags' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $pdo->query("SELECT * FROM ai_feature_flags ORDER BY feature_number");
    $flags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($flags as &$f) {
        $f['feature_number'] = (int)$f['feature_number'];
    }

    echo json_encode(['flags' => $flags], JSON_UNESCAPED_UNICODE);
}

function handleUpdateFlag($userId) {
    if (!isSystemAdmin($userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'システム管理者のみ変更できます']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $num = (int)($input['feature_number'] ?? 0);
    $status = $input['status'] ?? '';

    $validStatuses = ['disabled', 'beta', 'enabled'];
    if (!$num || !in_array($status, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['error' => '無効なパラメータです']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE ai_feature_flags SET status = ?, updated_by = ? WHERE feature_number = ?");
    $stmt->execute([$status, $userId, $num]);

    echo json_encode(['message' => '更新しました'], JSON_UNESCAPED_UNICODE);
}

function handleGetDefaults() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM ai_specialist_defaults ORDER BY specialist_type");
    $defaults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['defaults' => $defaults], JSON_UNESCAPED_UNICODE);
}

function handleUpdateDefault($userId) {
    if (!isSystemAdmin($userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'システム管理者のみ変更できます']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['specialist_type'] ?? '';
    $validTypes = ['work','people','finance','compliance','mentalcare','education','customer'];
    if (!in_array($type, $validTypes)) {
        http_response_code(400);
        echo json_encode(['error' => '無効な専門AIタイプです']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO ai_specialist_defaults (specialist_type, default_prompt, intent_keywords, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE default_prompt = VALUES(default_prompt),
            intent_keywords = VALUES(intent_keywords),
            version = version + 1, updated_by = VALUES(updated_by)
    ");
    $stmt->execute([
        $type,
        trim($input['default_prompt'] ?? ''),
        json_encode($input['intent_keywords'] ?? [], JSON_UNESCAPED_UNICODE),
        $userId,
    ]);

    echo json_encode(['message' => 'デフォルト設定を更新しました'], JSON_UNESCAPED_UNICODE);
}

function handleStats($userId) {
    $orgId = (int)($_GET['organization_id'] ?? 0);
    if (!$orgId || !isOrgMember($userId, $orgId)) {
        http_response_code(403);
        echo json_encode(['error' => 'アクセス権がありません']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT specialist_type,
            COUNT(*) AS total_calls,
            AVG(latency_ms) AS avg_latency,
            SUM(tokens_used) AS total_tokens
        FROM org_ai_specialist_logs
        WHERE organization_id = ?
        GROUP BY specialist_type
        ORDER BY total_calls DESC
    ");
    $stmt->execute([$orgId]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stats as &$s) {
        $s['total_calls'] = (int)$s['total_calls'];
        $s['avg_latency'] = (int)$s['avg_latency'];
        $s['total_tokens'] = (int)$s['total_tokens'];
    }

    echo json_encode(['stats' => $stats], JSON_UNESCAPED_UNICODE);
}

function isOrgMember($userId, $orgId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 1 FROM organization_members WHERE user_id = ? AND organization_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$userId, $orgId]);
    return (bool)$stmt->fetch();
}

function isOrgAdmin($userId, $orgId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT role FROM organization_members WHERE user_id = ? AND organization_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$userId, $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && in_array($row['role'], ['owner', 'admin']);
}

function isSystemAdmin($userId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && $user['role'] === 'admin';
}
