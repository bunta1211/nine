<?php
/**
 * 所属組織を新規作成するAPI
 * ログインユーザーがオーナーとして追加される
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

start_session_once();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim((string)($input['name'] ?? ''));
$type = trim((string)($input['type'] ?? 'corporation'));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '組織名を入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedTypes = ['corporation', 'family', 'school', 'group'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'corporation';
}

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ログインし直してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 代表者として設定するユーザーが users に存在するか確認（外部キー制約エラー防止）
try {
    $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    $chk->execute([$userId]);
    if (!$chk->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ユーザー情報が無効です。ログインし直してください。', 'debug_message' => 'user_id=' . $userId . ' is not in users table'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベースエラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

// テーブル存在チェック
$organizationsExists = false;
$organizationMembersExists = false;
try {
    $t = $pdo->query("SHOW TABLES LIKE 'organizations'");
    $organizationsExists = $t && $t->rowCount() > 0;
    $t = $pdo->query("SHOW TABLES LIKE 'organization_members'");
    $organizationMembersExists = $t && $t->rowCount() > 0;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベースエラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$organizationsExists) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '組織テーブルが存在しません'], JSON_UNESCAPED_UNICODE);
    exit;
}

// organizations のカラム存在チェック（owner_id / admin_user_id / created_by のいずれかで代表者を設定）
$hasType = false;
$hasCreatedBy = false;
$hasAdminUserId = false;
$hasOwnerId = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM organizations");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['Field'] === 'type') $hasType = true;
        if ($row['Field'] === 'created_by') $hasCreatedBy = true;
        if ($row['Field'] === 'admin_user_id') $hasAdminUserId = true;
        if ($row['Field'] === 'owner_id') $hasOwnerId = true;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベースエラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // owner_id に FK が張られている環境では owner_id を優先して設定する
    if ($hasType && $hasOwnerId) {
        $pdo->prepare("INSERT INTO organizations (name, type, owner_id) VALUES (?, ?, ?)")
            ->execute([$name, $type, $userId]);
    } elseif ($hasOwnerId) {
        $pdo->prepare("INSERT INTO organizations (name, owner_id) VALUES (?, ?)")
            ->execute([$name, $userId]);
    } elseif ($hasType && $hasCreatedBy) {
        $pdo->prepare("INSERT INTO organizations (name, type, created_by) VALUES (?, ?, ?)")
            ->execute([$name, $type, $userId]);
    } elseif ($hasType && $hasAdminUserId) {
        $pdo->prepare("INSERT INTO organizations (name, type, admin_user_id) VALUES (?, ?, ?)")
            ->execute([$name, $type, $userId]);
    } elseif ($hasAdminUserId) {
        $pdo->prepare("INSERT INTO organizations (name, admin_user_id) VALUES (?, ?)")
            ->execute([$name, $userId]);
    } elseif ($hasCreatedBy) {
        $pdo->prepare("INSERT INTO organizations (name, created_by) VALUES (?, ?)")
            ->execute([$name, $userId]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '組織テーブルに代表者カラム(owner_id/admin_user_id/created_by)がありません'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $orgId = (int)$pdo->lastInsertId();

    // organization_members が存在する場合のみオーナーを追加（テーブルが無い環境では organizations.admin_user_id で管理者となる）
    if ($organizationMembersExists) {
        $hasMemberType = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'member_type'");
            $hasMemberType = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        $hasJoinedAt = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'joined_at'");
            $hasJoinedAt = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        if ($hasMemberType && $hasJoinedAt) {
            $pdo->prepare("INSERT INTO organization_members (organization_id, user_id, role, member_type, joined_at) VALUES (?, ?, 'owner', 'internal', NOW())")
                ->execute([$orgId, $userId]);
        } elseif ($hasJoinedAt) {
            $pdo->prepare("INSERT INTO organization_members (organization_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())")
                ->execute([$orgId, $userId]);
        } else {
            $pdo->prepare("INSERT INTO organization_members (organization_id, user_id, role) VALUES (?, ?, 'owner')")
                ->execute([$orgId, $userId]);
        }
    }

    $pdo->commit();

    // セッションに新組織を設定
    $_SESSION['current_org_id'] = $orgId;
    $_SESSION['current_org_name'] = $name;
    $_SESSION['current_org_type'] = $type;
    $_SESSION['current_org_role'] = 'owner';

    echo json_encode([
        'success' => true,
        'message' => '組織を作成しました',
        'organization' => [
            'id' => $orgId,
            'name' => $name,
            'type' => $type,
            'role' => 'owner'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('create-organization.php: ' . $e->getMessage());
    http_response_code(500);
    $payload = [
        'success' => false,
        'message' => '保存に失敗しました。',
        'debug_message' => $e->getMessage()
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
