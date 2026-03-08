<?php
/**
 * グループ管理 API
 * 既存のconversationsテーブル構造に対応
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

// 組織管理者チェック
if (!isLoggedIn() || !isOrgAdmin()) {
    echo json_encode(['success' => false, 'error' => '権限がありません']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // グループ一覧CSV出力
            if (isset($_GET['export']) && $_GET['export'] === 'csv' && !isset($_GET['id'])) {
                exportGroupsCsv($pdo);
                exit;
            }
            // グループメンバーCSV出力
            if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['id'])) {
                exportGroupMembersCsv($pdo, (int)$_GET['id']);
                exit;
            }
            // 全ユーザー一覧取得（メンバー追加用）
            if (isset($_GET['all_users'])) {
                getAllUsers($pdo);
                break;
            }
            // グループ詳細取得（多言語対応）
            if (isset($_GET['id']) && isset($_GET['action']) && $_GET['action'] === 'get_detail') {
                getGroupDetail($pdo, (int)$_GET['id']);
                break;
            }
            // グループメンバー取得
            if (isset($_GET['id'])) {
                getGroupMembers($pdo, (int)$_GET['id']);
            } else {
                // 一覧取得
                getGroups($pdo);
            }
            break;
        
        case 'PUT':
            updateGroup($pdo);
            break;
        
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $_GET['action'] ?? ($input['action'] ?? '');
            
            if ($action === 'create_group') {
                createGroup($pdo);
            } elseif ($action === 'add_member') {
                addMember($pdo);
            } elseif ($action === 'change_role') {
                changeRole($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => '不正なアクションです']);
            }
            break;
        
        case 'DELETE':
            // メンバー削除
            if (isset($_GET['action']) && $_GET['action'] === 'remove_member') {
                removeMember($pdo);
            } else {
                // グループ削除
                deleteGroup($pdo);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => '不正なリクエストです']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * カラム存在チェック
 */
function checkColumnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * ユーザー一覧取得（メンバー追加用）
 * 現在の組織が選択されている場合は、その組織に所属するメンバーのみ返す（組織内検索）
 */
function getAllUsers($pdo) {
    $hasMemberType = checkColumnExists($pdo, 'users', 'member_type');
    $hasUserStatus = checkColumnExists($pdo, 'users', 'status');
    $currentOrgId = $_SESSION['current_org_id'] ?? null;
    $currentOrgId = $currentOrgId !== null && $currentOrgId !== '' ? (int)$currentOrgId : null;

    $selectCols = 'u.id, u.display_name, u.full_name';
    if ($hasMemberType) {
        $selectCols .= ', u.member_type';
    }

    if ($currentOrgId !== null) {
        // 組織が選択されている場合: その組織のメンバーのみ
        $hasLeftAt = checkColumnExists($pdo, 'organization_members', 'left_at');
        $leftCond = $hasLeftAt ? ' AND om.left_at IS NULL' : '';
        $statusCond = $hasUserStatus ? ' AND (u.status = \'active\' OR u.status IS NULL)' : '';
        $sql = "
            SELECT {$selectCols}
            FROM users u
            INNER JOIN organization_members om ON om.user_id = u.id AND om.organization_id = ? {$leftCond}
            WHERE 1=1 {$statusCond}
            ORDER BY u.display_name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$currentOrgId]);
    } else {
        // 組織未選択時: 従来どおり全ユーザー（後方互換）
        $statusCond = $hasUserStatus ? " WHERE status = 'active' OR status IS NULL" : '';
        $stmt = $pdo->query("SELECT {$selectCols} FROM users u {$statusCond} ORDER BY u.display_name ASC");
    }

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['id'] = (int)$user['id'];
        if (!$hasMemberType) {
            $user['member_type'] = 'internal';
        }
    }

    echo json_encode(['success' => true, 'users' => $users]);
}

/**
 * グループチャットを新規作成（現在の組織に紐づける）
 */
function createGroup($pdo) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'グループ名を入力してください']);
        return;
    }

    $currentOrgId = $_SESSION['current_org_id'] ?? null;
    if ($currentOrgId === null || $currentOrgId === '') {
        echo json_encode(['success' => false, 'error' => '組織を選択してください']);
        return;
    }
    $currentOrgId = (int)$currentOrgId;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ログインし直してください']);
        return;
    }

    $description = trim((string)($input['description'] ?? ''));
    $nameEn = trim((string)($input['name_en'] ?? ''));
    $nameZh = trim((string)($input['name_zh'] ?? ''));

    // プライベートグループ設定（マスター計画 2.5。カラム存在時のみ）
    $hasPrivateGroupCol = checkColumnExists($pdo, 'conversations', 'is_private_group');
    $isPrivateGroup = $hasPrivateGroupCol ? (int)($input['is_private_group'] ?? 0) : 0;
    $allowMemberPost = $hasPrivateGroupCol ? (int)($input['allow_member_post'] ?? 1) : 1;
    $allowDataSend = $hasPrivateGroupCol ? (int)($input['allow_data_send'] ?? 1) : 1;
    $memberListVisible = $hasPrivateGroupCol ? (int)($input['member_list_visible'] ?? 1) : 1;
    $allowAddContactFromGroup = $hasPrivateGroupCol ? (int)($input['allow_add_contact_from_group'] ?? 1) : 1;

    $hasOrgId = checkColumnExists($pdo, 'conversations', 'organization_id');
    $hasCreatedBy = checkColumnExists($pdo, 'conversations', 'created_by');
    $hasNameEn = checkColumnExists($pdo, 'conversations', 'name_en');
    $hasNameZh = checkColumnExists($pdo, 'conversations', 'name_zh');

    $pdo->beginTransaction();
    try {
        if ($hasPrivateGroupCol) {
            // プライベートグループ設定カラムあり: INSERT に含める
            $privateCols = 'is_private_group, allow_member_post, allow_data_send, member_list_visible, allow_add_contact_from_group';
            $privatePlace = '?, ?, ?, ?, ?';
            $privateVals = [$isPrivateGroup, $allowMemberPost, $allowDataSend, $memberListVisible, $allowAddContactFromGroup];
        } else {
            $privateCols = '';
            $privatePlace = '';
            $privateVals = [];
        }
        $sep = ($privateCols !== '') ? ', ' : '';

        if ($hasOrgId && $hasCreatedBy && $hasNameEn && $hasNameZh) {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, name_en, name_zh, description, organization_id, created_by" . ($privateCols ? ", {$privateCols}" : "") . ")
                VALUES ('group', ?, ?, ?, ?, ?, ?" . ($privatePlace ? ", {$privatePlace}" : "") . ")
            ");
            $stmt->execute(array_merge([$name, $nameEn ?: null, $nameZh ?: null, $description ?: null, $currentOrgId, $userId], $privateVals));
        } elseif ($hasOrgId && $hasCreatedBy) {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, description, organization_id, created_by" . ($privateCols ? ", {$privateCols}" : "") . ")
                VALUES ('group', ?, ?, ?, ?, ?" . ($privatePlace ? ", {$privatePlace}" : "") . ")
            ");
            $stmt->execute(array_merge([$name, $description ?: null, $currentOrgId, $userId], $privateVals));
        } elseif ($hasOrgId) {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, description, organization_id" . ($privateCols ? ", {$privateCols}" : "") . ")
                VALUES ('group', ?, ?, ?" . ($privatePlace ? ", {$privatePlace}" : "") . ")
            ");
            $stmt->execute(array_merge([$name, $description ?: null, $currentOrgId], $privateVals));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, description, created_by" . ($privateCols ? ", {$privateCols}" : "") . ")
                VALUES ('group', ?, ?, ?" . ($privatePlace ? ", {$privatePlace}" : "") . ")
            ");
            $stmt->execute(array_merge([$name, $description ?: null, $userId], $privateVals));
        }
        $conversationId = (int)$pdo->lastInsertId();

        $hasJoinedAt = checkColumnExists($pdo, 'conversation_members', 'joined_at');
        if ($hasJoinedAt) {
            $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'admin', NOW())");
        } else {
            $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'admin')");
        }
        $stmt->execute([$conversationId, $userId]);

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'グループを作成しました',
            'group' => ['id' => $conversationId, 'name' => $name]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * グループ名更新（マスター計画 2.7: is_private_group と4設定の変更可能）
 */
function updateGroup($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'グループIDが指定されていません']);
        return;
    }
    
    if (empty($data['name'])) {
        echo json_encode(['success' => false, 'error' => 'グループ名を入力してください']);
        return;
    }
    
    $groupId = (int)$data['id'];
    $hasPrivateCols = checkColumnExists($pdo, 'conversations', 'is_private_group');
    
    // 多言語対応のカラムが存在するか確認
    $columns = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'name_en'")->fetchAll();
    $hasI18n = count($columns) > 0;
    
    // 名前＋プライベート設定を1回のUPDATEでまとめて保存（プライベート設定が送られていれば必ず反映）
    $sets = ['name = ?'];
    $params = [trim($data['name'])];
    
    if ($hasI18n) {
        $sets[] = 'name_en = ?';
        $sets[] = 'name_zh = ?';
        $params[] = isset($data['name_en']) ? trim($data['name_en']) : null;
        $params[] = isset($data['name_zh']) ? trim($data['name_zh']) : null;
    }
    
    if ($hasPrivateCols) {
        $isPrivate = array_key_exists('is_private_group', $data) ? (int)(bool)$data['is_private_group'] : null;
        $allowPost = array_key_exists('allow_member_post', $data) ? (int)(bool)$data['allow_member_post'] : null;
        $allowData = array_key_exists('allow_data_send', $data) ? (int)(bool)$data['allow_data_send'] : null;
        $listVisible = array_key_exists('member_list_visible', $data) ? (int)(bool)$data['member_list_visible'] : null;
        $allowContact = array_key_exists('allow_add_contact_from_group', $data) ? (int)(bool)$data['allow_add_contact_from_group'] : null;
        if ($isPrivate !== null) { $sets[] = 'is_private_group = ?'; $params[] = $isPrivate; }
        if ($allowPost !== null) { $sets[] = 'allow_member_post = ?'; $params[] = $allowPost; }
        if ($allowData !== null) { $sets[] = 'allow_data_send = ?'; $params[] = $allowData; }
        if ($listVisible !== null) { $sets[] = 'member_list_visible = ?'; $params[] = $listVisible; }
        if ($allowContact !== null) { $sets[] = 'allow_add_contact_from_group = ?'; $params[] = $allowContact; }
    }
    
    $params[] = $groupId;
    $stmt = $pdo->prepare("UPDATE conversations SET " . implode(', ', $sets) . " WHERE id = ? AND type = 'group'");
    $stmt->execute($params);
    
    echo json_encode(['success' => true]);
}

/**
 * グループ削除
 */
function deleteGroup($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'グループIDが指定されていません']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // メンバーを先に削除
        $stmt = $pdo->prepare("DELETE FROM conversation_members WHERE conversation_id = ?");
        $stmt->execute([$data['id']]);
        
        // グループを削除
        $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ? AND type = 'group'");
        $stmt->execute([$data['id']]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * メンバー追加
 */
function addMember($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['group_id']) || empty($data['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'グループIDまたはユーザーIDが指定されていません']);
        return;
    }
    
    // 既にメンバーかチェック
    $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
    $stmt->execute([$data['group_id'], $data['user_id']]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'このユーザーは既にメンバーです']);
        return;
    }
    
    // 過去に退出したレコードがあれば更新、なければ新規作成
    $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$data['group_id'], $data['user_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $stmt = $pdo->prepare("UPDATE conversation_members SET left_at = NULL, role = 'member', joined_at = NOW() WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'member')");
        $stmt->execute([$data['group_id'], $data['user_id']]);
    }
    
    echo json_encode(['success' => true]);
}

/**
 * メンバー削除
 */
function removeMember($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['group_id']) || empty($data['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'グループIDまたはユーザーIDが指定されていません']);
        return;
    }
    
    // left_atを設定（論理削除）
    $stmt = $pdo->prepare("UPDATE conversation_members SET left_at = NOW() WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$data['group_id'], $data['user_id']]);
    
    echo json_encode(['success' => true]);
}

/**
 * グループ一覧取得（conversationsテーブルを使用）
 */
function getGroups($pdo) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // 現在の組織IDを取得
    $currentOrgId = $_SESSION['current_org_id'] ?? null;
    
    // 検索条件（type='group'のものだけ＋組織でフィルタリング）
    $where = "WHERE c.type = 'group'";
    $params = [];
    
    if ($currentOrgId) {
        $where .= ' AND c.organization_id = ?';
        $params[] = $currentOrgId;
    }
    
    if ($search) {
        $where .= ' AND c.name LIKE ?';
        $params[] = "%{$search}%";
    }

    $hasPrivateGroupCol = checkColumnExists($pdo, 'conversations', 'is_private_group');
    $privateSelect = $hasPrivateGroupCol
        ? ", c.is_private_group, c.allow_member_post, c.allow_data_send, c.member_list_visible, c.allow_add_contact_from_group"
        : "";

    // 総件数取得
    $countSql = "SELECT COUNT(*) FROM conversations c {$where}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    $totalPages = ceil($totalCount / $perPage);
    
    // データ取得（メンバー数を含む）
    $sql = "SELECT c.id, c.name, c.created_at{$privateSelect},
                   (SELECT COUNT(*) FROM conversation_members cm WHERE cm.conversation_id = c.id AND cm.left_at IS NULL) as member_count
            FROM conversations c 
            {$where} 
            ORDER BY c.id 
            LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 数値型にキャスト
    foreach ($groups as &$group) {
        $group['id'] = (int)$group['id'];
        $group['member_count'] = (int)$group['member_count'];
        $group['name'] = $group['name'] ?? '無題のグループ';
        if ($hasPrivateGroupCol) {
            $group['is_private_group'] = (int)($group['is_private_group'] ?? 0);
            $group['allow_member_post'] = (int)($group['allow_member_post'] ?? 1);
            $group['allow_data_send'] = (int)($group['allow_data_send'] ?? 1);
            $group['member_list_visible'] = (int)($group['member_list_visible'] ?? 1);
            $group['allow_add_contact_from_group'] = (int)($group['allow_add_contact_from_group'] ?? 1);
        }
    }
    
    echo json_encode([
        'success' => true,
        'groups' => $groups,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'per_page' => $perPage
        ]
    ]);
}

/**
 * グループ詳細取得（多言語対応）
 */
function getGroupDetail($pdo, $groupId) {
    // 多言語カラムの存在チェック（name_en と description_en は別マイグレーションの可能性あり）
    $hasNameI18n = checkColumnExists($pdo, 'conversations', 'name_en');
    $hasDescI18n = checkColumnExists($pdo, 'conversations', 'description_en');
    $hasPrivateGroupCol = checkColumnExists($pdo, 'conversations', 'is_private_group');
    $privateSelect = $hasPrivateGroupCol
        ? ", is_private_group, allow_member_post, allow_data_send, member_list_visible, allow_add_contact_from_group"
        : "";

    $nameCols = $hasNameI18n ? "name, name_en, name_zh" : "name, '' as name_en, '' as name_zh";
    $descCols = $hasDescI18n ? "description, description_en, description_zh" : "description, '' as description_en, '' as description_zh";

    $stmt = $pdo->prepare("
        SELECT id, {$nameCols}, {$descCols}, type, created_at {$privateSelect}
        FROM conversations
        WHERE id = ? AND type = 'group'
    ");
    
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        echo json_encode(['success' => false, 'error' => 'グループが見つかりません']);
        return;
    }
    
    $group['id'] = (int)$group['id'];
    if ($hasPrivateGroupCol) {
        $group['is_private_group'] = (int)($group['is_private_group'] ?? 0);
        $group['allow_member_post'] = (int)($group['allow_member_post'] ?? 1);
        $group['allow_data_send'] = (int)($group['allow_data_send'] ?? 1);
        $group['member_list_visible'] = (int)($group['member_list_visible'] ?? 1);
        $group['allow_add_contact_from_group'] = (int)($group['allow_add_contact_from_group'] ?? 1);
    }
    
    echo json_encode(['success' => true, 'group' => $group]);
}

/**
 * グループメンバー取得
 */
function getGroupMembers($pdo, $groupId) {
    $hasMemberType = checkColumnExists($pdo, 'users', 'member_type');
    
    $selectCols = "u.id, u.display_name, u.full_name, CASE WHEN cm.role = 'admin' THEN 1 ELSE 0 END as is_group_admin";
    if ($hasMemberType) {
        $selectCols .= ", u.member_type";
    }
    
    $stmt = $pdo->prepare("
        SELECT {$selectCols}
        FROM conversation_members cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id = ? AND cm.left_at IS NULL
        ORDER BY cm.role = 'admin' DESC, u.display_name
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 数値型にキャスト、full_nameがnullの場合は空文字
    foreach ($members as &$member) {
        $member['id'] = (int)$member['id'];
        $member['is_group_admin'] = (int)$member['is_group_admin'];
        $member['full_name'] = $member['full_name'] ?? '';
        if (!$hasMemberType) {
            $member['member_type'] = 'internal';
        }
    }
    
    echo json_encode(['success' => true, 'members' => $members]);
}

/**
 * グループ一覧CSV出力
 */
function exportGroupsCsv($pdo) {
    // 現在の組織IDを取得
    $currentOrgId = $_SESSION['current_org_id'] ?? null;
    
    $where = "WHERE c.type = 'group'";
    $params = [];
    
    if ($currentOrgId) {
        $where .= ' AND c.organization_id = ?';
        $params[] = $currentOrgId;
    }
    
    $stmt = $pdo->prepare("
        SELECT c.id, c.name,
               (SELECT COUNT(*) FROM conversation_members cm WHERE cm.conversation_id = c.id AND cm.left_at IS NULL) as member_count,
               DATE_FORMAT(c.created_at, '%Y-%m-%d') as created_date
        FROM conversations c 
        {$where}
        ORDER BY c.id
    ");
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="groups.csv"');
    
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['No', 'グループ名', 'メンバー数', '作成日']);
    
    foreach ($groups as $group) {
        fputcsv($output, [
            $group['id'],
            $group['name'] ?? '無題のグループ',
            $group['member_count'],
            $group['created_date']
        ]);
    }
    
    fclose($output);
}

/**
 * グループメンバーCSV出力
 */
function exportGroupMembersCsv($pdo, $groupId) {
    // グループ名取得
    $stmt = $pdo->prepare("SELECT name FROM conversations WHERE id = ?");
    $stmt->execute([$groupId]);
    $groupName = $stmt->fetchColumn() ?? '無題のグループ';
    
    // メンバー取得
    $stmt = $pdo->prepare("
        SELECT u.display_name,
               CASE WHEN cm.role = 'admin' THEN 'グループ管理者' ELSE '一般メンバー' END as role
        FROM conversation_members cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id = ? AND cm.left_at IS NULL
        ORDER BY cm.role = 'admin' DESC, u.display_name
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="group_members.csv"');
    
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['No', 'グループ名', '表示名', '氏名', '権限']);
    
    $no = 1;
    foreach ($members as $member) {
        fputcsv($output, [
            $no++,
            $groupName,
            $member['display_name'],
            $member['display_name'],
            $member['role']
        ]);
    }
    
    fclose($output);
}

/**
 * グループメンバーの権限を変更
 */
function changeRole($pdo, $input) {
    $groupId = (int)($input['group_id'] ?? 0);
    $userId = (int)($input['user_id'] ?? 0);
    $role = $input['role'] ?? 'member';
    
    if (!$groupId || !$userId) {
        echo json_encode(['success' => false, 'error' => 'グループIDとユーザーIDが必要です']);
        return;
    }
    
    // roleのバリデーション
    if (!in_array($role, ['admin', 'member'])) {
        echo json_encode(['success' => false, 'error' => '無効な権限です']);
        return;
    }
    
    // グループの存在確認
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND type = 'group'");
    $stmt->execute([$groupId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'グループが見つかりません']);
        return;
    }
    
    // メンバーの存在確認
    $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'メンバーが見つかりません']);
        return;
    }
    
    // 権限を更新
    $stmt = $pdo->prepare("UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$role, $groupId, $userId]);
    
    $roleName = $role === 'admin' ? 'グループ管理者' : '一般メンバー';
    echo json_encode(['success' => true, 'message' => "{$roleName}に変更しました"]);
}
