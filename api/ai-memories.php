<?php
/**
 * 組織AI記憶ストア管理API
 * 
 * 記憶の検索・確認・修正・追記・削除。
 * 計画書 2.4（5）（6）に基づく。
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
$orgId = (int)($_REQUEST['organization_id'] ?? 0);

if (!$orgId) {
    http_response_code(400);
    echo json_encode(['error' => '組織IDが必要です']);
    exit;
}

if (!isOrgMember($userId, $orgId)) {
    http_response_code(403);
    echo json_encode(['error' => 'この組織のメンバーではありません']);
    exit;
}

switch ($action) {
    case 'search':
        handleSearch($orgId, $userId);
        break;
    case 'get':
        handleGet($orgId, $userId);
        break;
    case 'create':
        handleCreate($orgId, $userId);
        break;
    case 'update':
        handleUpdate($orgId, $userId);
        break;
    case 'delete':
        handleDelete($orgId, $userId);
        break;
    case 'restore':
        handleRestore($orgId, $userId);
        break;
    case 'history':
        handleHistory($orgId, $userId);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '不明なアクション']);
}

function handleSearch($orgId, $userId) {
    checkPermission($orgId, $userId, 'view');

    $pdo = getDB();
    $specialistType = $_GET['specialist_type'] ?? '';
    $keyword = $_GET['keyword'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $status = $_GET['status'] ?? 'active';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $where = ['m.organization_id = ?'];
    $params = [$orgId];

    if ($specialistType && in_array($specialistType, SpecialistType::ALL_TYPES)) {
        $where[] = 'm.specialist_type = ?';
        $params[] = $specialistType;
    }
    if ($status) {
        $where[] = 'm.status = ?';
        $params[] = $status;
    }
    if ($dateFrom) {
        $where[] = 'm.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $where[] = 'm.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereClause = implode(' AND ', $where);

    if ($keyword) {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM org_ai_memories m
            WHERE {$whereClause} AND MATCH(m.title, m.content) AGAINST(? IN NATURAL LANGUAGE MODE)
        ");
        $countParams = array_merge($params, [$keyword]);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT m.id, m.specialist_type, m.title,
                   LEFT(m.content, 200) AS content_preview,
                   m.tags, m.source_type, m.status, m.created_at, m.updated_at,
                   u.display_name AS created_by_name
            FROM org_ai_memories m
            LEFT JOIN users u ON u.id = m.created_by
            WHERE {$whereClause} AND MATCH(m.title, m.content) AGAINST(? IN NATURAL LANGUAGE MODE)
            ORDER BY m.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $searchParams = array_merge($params, [$keyword, $perPage, $offset]);
        $stmt->execute($searchParams);
    } else {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM org_ai_memories m WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT m.id, m.specialist_type, m.title,
                   LEFT(m.content, 200) AS content_preview,
                   m.tags, m.source_type, m.status, m.created_at, m.updated_at,
                   u.display_name AS created_by_name
            FROM org_ai_memories m
            LEFT JOIN users u ON u.id = m.created_by
            WHERE {$whereClause}
            ORDER BY m.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $searchParams = array_merge($params, [$perPage, $offset]);
        $stmt->execute($searchParams);
    }

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['tags'] = json_decode($item['tags'] ?? '[]', true);
    }

    echo json_encode([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => ceil($total / $perPage),
    ], JSON_UNESCAPED_UNICODE);
}

function handleGet($orgId, $userId) {
    checkPermission($orgId, $userId, 'view');

    $memoryId = (int)($_GET['id'] ?? 0);
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT m.*, u.display_name AS created_by_name
        FROM org_ai_memories m
        LEFT JOIN users u ON u.id = m.created_by
        WHERE m.id = ? AND m.organization_id = ?
    ");
    $stmt->execute([$memoryId, $orgId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['error' => '記憶が見つかりません']);
        return;
    }

    $item['id'] = (int)$item['id'];
    $item['tags'] = json_decode($item['tags'] ?? '[]', true);
    $item['extracted_entities'] = json_decode($item['extracted_entities'] ?? '{}', true);

    echo json_encode($item, JSON_UNESCAPED_UNICODE);
}

function handleCreate($orgId, $userId) {
    checkPermission($orgId, $userId, 'edit');

    $input = json_decode(file_get_contents('php://input'), true);
    $specialistType = $input['specialist_type'] ?? '';
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $tags = $input['tags'] ?? [];

    if (!in_array($specialistType, SpecialistType::ALL_TYPES)) {
        http_response_code(400);
        echo json_encode(['error' => '無効な専門AIタイプです']);
        return;
    }
    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => '内容は必須です']);
        return;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO org_ai_memories
            (organization_id, specialist_type, title, content, tags, source_type, created_by, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'manual', ?, 'active', NOW())
    ");
    $stmt->execute([
        $orgId, $specialistType, $title, $content,
        json_encode($tags, JSON_UNESCAPED_UNICODE), $userId,
    ]);
    $memoryId = (int)$pdo->lastInsertId();

    $histStmt = $pdo->prepare("
        INSERT INTO org_ai_memory_history (memory_id, action, new_title, new_content, changed_by)
        VALUES (?, 'create', ?, ?, ?)
    ");
    $histStmt->execute([$memoryId, $title, $content, $userId]);

    echo json_encode(['id' => $memoryId, 'message' => '記憶を追加しました'], JSON_UNESCAPED_UNICODE);
}

function handleUpdate($orgId, $userId) {
    checkPermission($orgId, $userId, 'edit');

    $input = json_decode(file_get_contents('php://input'), true);
    $memoryId = (int)($input['id'] ?? 0);
    $newTitle = trim($input['title'] ?? '');
    $newContent = trim($input['content'] ?? '');
    $newTags = $input['tags'] ?? null;

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM org_ai_memories WHERE id = ? AND organization_id = ?");
    $stmt->execute([$memoryId, $orgId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
        http_response_code(404);
        echo json_encode(['error' => '記憶が見つかりません']);
        return;
    }

    $histStmt = $pdo->prepare("
        INSERT INTO org_ai_memory_history (memory_id, action, old_title, old_content, new_title, new_content, changed_by)
        VALUES (?, 'update', ?, ?, ?, ?, ?)
    ");
    $histStmt->execute([$memoryId, $old['title'], $old['content'], $newTitle, $newContent, $userId]);

    $updates = ['updated_at = NOW()'];
    $params = [];
    if ($newTitle !== '') { $updates[] = 'title = ?'; $params[] = $newTitle; }
    if ($newContent !== '') { $updates[] = 'content = ?'; $params[] = $newContent; }
    if ($newTags !== null) { $updates[] = 'tags = ?'; $params[] = json_encode($newTags, JSON_UNESCAPED_UNICODE); }
    $params[] = $memoryId;

    $pdo->prepare("UPDATE org_ai_memories SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

    echo json_encode(['message' => '記憶を更新しました'], JSON_UNESCAPED_UNICODE);
}

function handleDelete($orgId, $userId) {
    checkPermission($orgId, $userId, 'delete');

    $memoryId = (int)($_REQUEST['id'] ?? 0);
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM org_ai_memories WHERE id = ? AND organization_id = ?");
    $stmt->execute([$memoryId, $orgId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => '記憶が見つかりません']);
        return;
    }

    $pdo->prepare("UPDATE org_ai_memories SET status = 'deleted', deleted_at = NOW() WHERE id = ?")->execute([$memoryId]);

    $pdo->prepare("INSERT INTO org_ai_memory_history (memory_id, action, changed_by) VALUES (?, 'delete', ?)")
        ->execute([$memoryId, $userId]);

    echo json_encode(['message' => '記憶を削除しました'], JSON_UNESCAPED_UNICODE);
}

function handleRestore($orgId, $userId) {
    checkPermission($orgId, $userId, 'delete');

    $memoryId = (int)($_REQUEST['id'] ?? 0);
    $pdo = getDB();

    $pdo->prepare("UPDATE org_ai_memories SET status = 'active', deleted_at = NULL WHERE id = ? AND organization_id = ?")
        ->execute([$memoryId, $orgId]);

    $pdo->prepare("INSERT INTO org_ai_memory_history (memory_id, action, changed_by) VALUES (?, 'restore', ?)")
        ->execute([$memoryId, $userId]);

    echo json_encode(['message' => '記憶を復元しました'], JSON_UNESCAPED_UNICODE);
}

function handleHistory($orgId, $userId) {
    checkPermission($orgId, $userId, 'view');

    $memoryId = (int)($_GET['id'] ?? 0);
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT h.*, u.display_name AS changed_by_name
        FROM org_ai_memory_history h
        LEFT JOIN users u ON u.id = h.changed_by
        WHERE h.memory_id = ?
        ORDER BY h.changed_at DESC
        LIMIT 50
    ");
    $stmt->execute([$memoryId]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

function isOrgMember($userId, $orgId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 1 FROM organization_members
        WHERE user_id = ? AND organization_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$userId, $orgId]);
    return (bool)$stmt->fetch();
}

function checkPermission($orgId, $userId, $level) {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT role FROM organization_members
        WHERE user_id = ? AND organization_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$userId, $orgId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(403);
        echo json_encode(['error' => '権限がありません']);
        exit;
    }

    if (in_array($member['role'], ['owner', 'admin'])) {
        return true;
    }

    if ($level === 'view') {
        return true;
    }

    $permStmt = $pdo->prepare("
        SELECT permission_level FROM org_ai_memory_permissions
        WHERE organization_id = ?
          AND (user_id = ? OR role = ?)
        ORDER BY FIELD(permission_level, 'delete', 'edit', 'view') ASC
        LIMIT 1
    ");
    $permStmt->execute([$orgId, $userId, $member['role']]);
    $perm = $permStmt->fetch(PDO::FETCH_ASSOC);

    $levels = ['view' => 1, 'edit' => 2, 'delete' => 3];
    $requiredLevel = $levels[$level] ?? 1;
    $userLevel = $levels[$perm['permission_level'] ?? 'view'] ?? 1;

    if ($userLevel < $requiredLevel) {
        http_response_code(403);
        echo json_encode(['error' => '権限が不足しています']);
        exit;
    }

    return true;
}
