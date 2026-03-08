<?php
/**
 * 組織メンバー管理 API
 * メンバー一覧取得・取得・登録・更新・削除・CSV出力
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/roles.php';

start_session_once();
requireLogin();

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);
$currentOrgId = $_SESSION['current_org_id'] ?? null;

if ($currentOrgId === null || $currentOrgId === '') {
    jsonError('組織を選択してください', 400);
}
$currentOrgId = (int)$currentOrgId;

try {
    if (!hasOrgAdminRole($pdo, $userId, $currentOrgId)) {
        jsonError('管理者権限が必要です', 403);
    }
} catch (Throwable $e) {
    error_log('admin/api/members.php hasOrgAdminRole: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonErrorWithDebug('サーバーエラーが発生しました', 500, $e);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // GET ?id= 単体取得
    if ($method === 'GET' && isset($_GET['id']) && !isset($_GET['action'])) {
        getOne($pdo, $currentOrgId, (int)$_GET['id']);
    }

    // GET ?action=org_groups 組織のグループ一覧（所属選択用）
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'org_groups') {
        getOrgGroups($pdo, $currentOrgId);
    }

    // GET ?action=search_candidates&q= メンバー追加用の候補者検索（組織に未所属のユーザー）
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'search_candidates') {
        searchCandidates($pdo, $currentOrgId);
    }

    // GET ?export=csv CSV出力
    if ($method === 'GET' && isset($_GET['export']) && $_GET['export'] === 'csv') {
        exportCsv($pdo, $currentOrgId);
    }

    // GET 一覧（ページ・検索・種別）
    if ($method === 'GET') {
        listMembers($pdo, $currentOrgId);
    }

    // POST 新規登録 / 既存ユーザーを組織に追加 / 招待メール再送 / 一斉招待
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        if (isset($body['action']) && $body['action'] === 'add_existing') {
            addExistingMember($pdo, $currentOrgId, $body);
        } elseif (isset($body['action']) && $body['action'] === 'resend_invite') {
            resendOrgInvite($pdo, $currentOrgId, $body);
        } elseif (isset($body['action']) && $body['action'] === 'bulk_invite') {
            bulkInvite($pdo, $currentOrgId, $body);
        } else {
            createMember($pdo, $currentOrgId, $body);
        }
    }

    // PUT 更新
    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        updateMember($pdo, $currentOrgId, $body);
    }

    // DELETE 削除（組織から退会）
    if ($method === 'DELETE') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        deleteMember($pdo, $currentOrgId, $body);
    }
} catch (Throwable $e) {
    error_log('admin/api/members.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonErrorWithDebug('サーバーエラーが発生しました', 500, $e);
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
exit;

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 500 等で例外詳細を返す（?debug=1 のときのみ。原因切り分け用）
 */
function jsonErrorWithDebug($message, $code = 500, Throwable $e = null) {
    http_response_code($code);
    $out = ['success' => false, 'message' => $message, 'error' => $message];
    if ($e !== null && isset($_GET['debug']) && $_GET['debug'] === '1') {
        $out['debug_message'] = $e->getMessage();
        $out['debug_file'] = basename($e->getFile());
        $out['debug_line'] = $e->getLine();
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonSuccess($data = [], $message = null) {
    $out = ['success' => true];
    if ($message !== null) {
        $out['message'] = $message;
    }
    echo json_encode(array_merge($out, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * テーブルにカラムが存在するか
 */
function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return false;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE " . $pdo->quote($safeColumn));
        return $stmt !== false && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('admin/api/members.php tableHasColumn: ' . $e->getMessage());
        return false;
    }
}

/**
 * メンバー一覧（ページネーション・検索・種別フィルター）
 */
function listMembers(PDO $pdo, $orgId) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $search = trim((string)($_GET['search'] ?? ''));
    $memberType = (string)($_GET['member_type'] ?? '');

    $orgId = (int)$orgId;
    $offset = ($page - 1) * $perPage;

    $hasMemberType = tableHasColumn($pdo, 'organization_members', 'member_type');
    $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
    $hasIsMinor = tableHasColumn($pdo, 'users', 'is_minor');
    $hasUserCreatedAt = tableHasColumn($pdo, 'users', 'created_at');
    $hasLeftAt = tableHasColumn($pdo, 'organization_members', 'left_at');
    $hasAcceptedAt = tableHasColumn($pdo, 'organization_members', 'accepted_at');

    $hasPhone = tableHasColumn($pdo, 'users', 'phone');
    $createdAtCol = $hasUserCreatedAt ? 'u.created_at' : 'om.joined_at AS created_at';
    $userCols = 'u.id, u.display_name, u.email, ' . ($hasPhone ? 'u.phone, ' : '') . $createdAtCol . ', om.role, om.joined_at';
    if ($hasFullName) {
        $userCols = 'u.id, u.full_name, u.display_name, u.email, ' . ($hasPhone ? 'u.phone, ' : '') . $createdAtCol . ', om.role, om.joined_at';
    }
    if ($hasIsMinor) {
        $userCols = str_replace(', om.role,', ', u.is_minor, om.role,', $userCols);
    }
    if ($hasMemberType) {
        $userCols .= ', om.member_type';
    }
    if ($hasAcceptedAt) {
        $userCols .= ', om.accepted_at';
    }

    $leftAtCondition = $hasLeftAt ? ' AND om.left_at IS NULL' : '';
    $select = "SELECT " . $userCols;
    $from = "
        FROM organization_members om
        INNER JOIN users u ON om.user_id = u.id
        WHERE om.organization_id = :org_id" . $leftAtCondition . "
    ";
    $params = [':org_id' => $orgId];

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        if ($hasFullName) {
            $from .= " AND (u.full_name LIKE :search1 OR u.display_name LIKE :search2 OR u.email LIKE :search3)";
            $params[':search1'] = $params[':search2'] = $params[':search3'] = $searchLike;
        } else {
            $from .= " AND (u.display_name LIKE :search1 OR u.email LIKE :search2)";
            $params[':search1'] = $params[':search2'] = $searchLike;
        }
    }

    if ($hasMemberType && $memberType !== '') {
        $from .= " AND om.member_type = :member_type";
        $params[':member_type'] = $memberType;
    }

    $order = " ORDER BY om.role = 'owner' DESC, om.role = 'admin' DESC, u.display_name ASC";
    $limit = " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $total = 0;
    $members = [];
    $rows = [];

    try {
        $countSql = "SELECT COUNT(*) " . $from;
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $listSql = $select . $from . $order . $limit;
        $stmt = $pdo->prepare($listSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $isDbError = $e instanceof PDOException || strpos($e->getMessage(), 'Unknown column') !== false || strpos($e->getMessage(), 'SQLSTATE') !== false;
        error_log('admin/api/members.php listMembers main query failed: ' . $e->getMessage() . ' [' . get_class($e) . '] in ' . $e->getFile() . ':' . $e->getLine());
        if (!$isDbError) {
            throw $e;
        }
        // フォールバック: 最小カラムのみで再取得（スキーマ差で落ちないようにする）
        try {
            $minFrom = "
                FROM organization_members om
                INNER JOIN users u ON om.user_id = u.id
                WHERE om.organization_id = :org_id" . $leftAtCondition . "
            ";
            $minParams = [':org_id' => $orgId];
            if ($search !== '') {
                $minFrom .= " AND (u.display_name LIKE :search1 OR u.email LIKE :search2)";
                $minParams[':search1'] = $minParams[':search2'] = '%' . $search . '%';
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) " . $minFrom);
            $stmt->execute($minParams);
            $total = (int)$stmt->fetchColumn();

            $minSelect = "SELECT u.id, u.display_name, u.email, om.role, om.joined_at " . $minFrom;
            $stmt = $pdo->prepare($minSelect . " ORDER BY om.role = 'owner' DESC, om.role = 'admin' DESC, u.display_name ASC " . $limit);
            $stmt->execute($minParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            error_log('admin/api/members.php listMembers fallback also failed: ' . $e2->getMessage());
            throw $e2;
        }
    }

    foreach ($rows as $r) {
        $role = $r['role'] ?? 'member';
        $acceptedAt = $hasAcceptedAt ? ($r['accepted_at'] ?? null) : null;
        $members[] = [
            'id' => (int)$r['id'],
            'member_type' => $hasMemberType ? ($r['member_type'] ?? 'internal') : 'internal',
            'full_name' => $hasFullName ? ($r['full_name'] ?? '') : ($r['display_name'] ?? ''),
            'display_name' => $r['display_name'] ?? '',
            'email' => $r['email'] ?? '',
            'phone' => $hasPhone ? ($r['phone'] ?? '') : '',
            'is_org_admin' => in_array($role, ['owner', 'admin']) ? 1 : 0,
            'created_at' => $r['created_at'] ?? $r['joined_at'] ?? null,
            'is_minor' => $hasIsMinor ? (int)($r['is_minor'] ?? 0) : 0,
            'is_restricted' => ($role === 'restricted') ? 1 : 0,
            'accepted_at' => $acceptedAt,
            'invitation_pending' => $hasAcceptedAt && $acceptedAt === null ? 1 : 0,
        ];
    }

    // 統計（同じ条件で集計、member_type フィルターなし）
    $statsFrom = "
        FROM organization_members om
        INNER JOIN users u ON om.user_id = u.id
        WHERE om.organization_id = :org_id" . $leftAtCondition . "
    ";
    $statsParams = [':org_id' => $orgId];
    if ($search !== '') {
        if ($hasFullName) {
            $statsFrom .= " AND (u.full_name LIKE :search1 OR u.display_name LIKE :search2 OR u.email LIKE :search3)";
            $statsParams[':search1'] = $statsParams[':search2'] = $statsParams[':search3'] = '%' . $search . '%';
        } else {
            $statsFrom .= " AND (u.display_name LIKE :search1 OR u.email LIKE :search2)";
            $statsParams[':search1'] = $statsParams[':search2'] = '%' . $search . '%';
        }
    }

    $statsTotal = $total;
    $statsInternal = $total;
    $statsExternal = 0;
    $statsAdmins = 0;
    $searchLike = $search !== '' ? '%' . $search . '%' : null;
    $searchCondition = '';
    if ($search !== '') {
        $searchCondition = $hasFullName
            ? " AND (u.full_name LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)"
            : " AND (u.display_name LIKE ? OR u.email LIKE ?)";
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) " . $statsFrom);
        $stmt->execute($statsParams);
        $statsTotal = (int)$stmt->fetchColumn();
        $statsInternal = $statsTotal;

        if ($hasMemberType) {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN om.member_type = 'external' THEN 1 ELSE 0 END) as external,
                    SUM(CASE WHEN om.role IN ('owner','admin') THEN 1 ELSE 0 END) as admins
                FROM organization_members om
                INNER JOIN users u ON om.user_id = u.id
                WHERE om.organization_id = ?" . $leftAtCondition . "
                " . $searchCondition
            );
            if ($search !== '') {
                $stmt->execute($hasFullName
                    ? array_merge([$orgId], [$searchLike, $searchLike, $searchLike])
                    : array_merge([$orgId], [$searchLike, $searchLike]));
            } else {
                $stmt->execute([$orgId]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $statsExternal = (int)($row['external'] ?? 0);
            $statsAdmins = (int)($row['admins'] ?? 0);
            $statsInternal = $statsTotal - $statsExternal;
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM organization_members om
                INNER JOIN users u ON om.user_id = u.id
                WHERE om.organization_id = ?" . $leftAtCondition . " AND om.role IN ('owner','admin')
                " . $searchCondition
            );
            if ($search !== '') {
                $stmt->execute($hasFullName
                    ? array_merge([$orgId], [$searchLike, $searchLike, $searchLike])
                    : array_merge([$orgId], [$searchLike, $searchLike]));
            } else {
                $stmt->execute([$orgId]);
            }
            $statsAdmins = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('admin/api/members.php listMembers stats: ' . $e->getMessage());
        // 一覧は返すため、統計はデフォルトのまま（total は既に取得済み）
    }

    $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

    jsonSuccess([
        'members' => $members,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
        'stats' => [
            'total' => $statsTotal,
            'internal' => $statsInternal,
            'external' => $statsExternal,
            'admins' => $statsAdmins,
        ],
    ]);
}

/**
 * 1件取得
 */
function getOne(PDO $pdo, $orgId, $memberId) {
    $orgId = (int)$orgId;
    $memberId = (int)$memberId;

    $hasMemberType = tableHasColumn($pdo, 'organization_members', 'member_type');
    $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
    $hasIsMinor = tableHasColumn($pdo, 'users', 'is_minor');

    $hasPhoneGetOne = tableHasColumn($pdo, 'users', 'phone');
    $select = 'u.id, u.display_name, u.email, ' . ($hasPhoneGetOne ? 'u.phone, ' : '') . 'u.created_at, om.role, om.joined_at';
    if ($hasFullName) {
        $select = 'u.id, u.full_name, u.display_name, u.email, ' . ($hasPhoneGetOne ? 'u.phone, ' : '') . 'u.created_at, om.role, om.joined_at';
    }
    if ($hasIsMinor) {
        $select = str_replace('u.created_at,', 'u.created_at, u.is_minor,', $select);
    }
    if ($hasMemberType) {
        $select .= ', om.member_type';
    }
    $hasLeftAtGetOne = tableHasColumn($pdo, 'organization_members', 'left_at');
    $leftCondGetOne = $hasLeftAtGetOne ? ' AND om.left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT $select
        FROM organization_members om
        INNER JOIN users u ON om.user_id = u.id
        WHERE om.organization_id = ? AND om.user_id = ?" . $leftCondGetOne . "
    ");
    $stmt->execute([$orgId, $memberId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        jsonError('メンバーが見つかりません', 404);
    }

    $role = $r['role'] ?? 'member';
    $member = [
        'id' => (int)$r['id'],
        'member_type' => $hasMemberType ? ($r['member_type'] ?? 'internal') : 'internal',
        'full_name' => $hasFullName ? ($r['full_name'] ?? '') : ($r['display_name'] ?? ''),
        'display_name' => $r['display_name'] ?? '',
        'email' => $r['email'] ?? '',
        'phone' => $hasPhoneGetOne ? ($r['phone'] ?? '') : '',
        'is_org_admin' => in_array($role, ['owner', 'admin']) ? 1 : 0,
        'created_at' => $r['created_at'] ?? $r['joined_at'] ?? null,
        'is_minor' => $hasIsMinor ? (int)($r['is_minor'] ?? 0) : 0,
        'is_restricted' => ($role === 'restricted') ? 1 : 0,
    ];

    $member['group_ids'] = getMemberGroupIds($pdo, $orgId, $memberId);
    jsonSuccess(['member' => $member]);
}

/**
 * 組織のグループ一覧（所属選択用・2個以上一括登録用）
 */
function getOrgGroups(PDO $pdo, $orgId) {
    $orgId = (int)$orgId;
    if (!tableHasColumn($pdo, 'conversations', 'organization_id')) {
        jsonSuccess(['groups' => []]);
        return;
    }
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM conversations
        WHERE type = 'group' AND organization_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['name'] = $r['name'] ?? '無題のグループ';
    }
    jsonSuccess(['groups' => $rows]);
}

/**
 * メンバー追加用：組織に未所属のユーザーを検索（氏名・表示名・メールで検索）
 */
function searchCandidates(PDO $pdo, $orgId) {
    $orgId = (int)$orgId;
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1) {
        jsonSuccess(['candidates' => []]);
        return;
    }
    $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
    $hasLeftAt = tableHasColumn($pdo, 'organization_members', 'left_at');
    $leftCond = $hasLeftAt ? ' AND om.left_at IS NULL' : '';
    $like = '%' . $q . '%';
    $fullNameCol = $hasFullName ? ', u.full_name' : '';
    $fullNameCond = $hasFullName ? ' OR u.full_name LIKE ?' : '';
    $params = [$like, $like];
    if ($hasFullName) {
        $params[] = $like;
    }
    $params[] = $orgId;
    $sql = "
        SELECT u.id, u.display_name, u.email" . $fullNameCol . "
        FROM users u
        WHERE (u.display_name LIKE ? OR u.email LIKE ?" . $fullNameCond . ")
        AND u.id NOT IN (
            SELECT om.user_id FROM organization_members om
            WHERE om.organization_id = ?" . $leftCond . "
        )
        ORDER BY u.display_name ASC
        LIMIT 30
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
    }
    jsonSuccess(['candidates' => $rows]);
}

/**
 * 既存ユーザーを組織にメンバーとして追加（user_id のみで追加）
 */
function addExistingMember(PDO $pdo, $orgId, array $data) {
    $orgId = (int)$orgId;
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonError('ユーザーIDが必要です', 400);
    }
    $memberType = (isset($data['member_type']) && $data['member_type'] === 'external') ? 'external' : 'internal';
    $isOrgAdmin = !empty($data['is_org_admin']);
    if ($memberType === 'external' && $isOrgAdmin) {
        $isOrgAdmin = false;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        jsonError('ユーザーが見つかりません', 404);
    }

    $hasLeftAt = tableHasColumn($pdo, 'organization_members', 'left_at');
    $leftCond = $hasLeftAt ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ?" . $leftCond);
    $stmt->execute([$orgId, $userId]);
    if ($stmt->fetch()) {
        jsonError('このユーザーは既に組織に所属しています', 400);
    }

    $role = $isOrgAdmin ? 'admin' : 'member';
    $hasMemberType = tableHasColumn($pdo, 'organization_members', 'member_type');

    if ($hasMemberType) {
        $stmt = $pdo->prepare("
            INSERT INTO organization_members (organization_id, user_id, role, member_type, joined_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$orgId, $userId, $role, $memberType]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO organization_members (organization_id, user_id, role, joined_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$orgId, $userId, $role]);
    }

    jsonSuccess([], 'メンバーを追加しました');
}

/**
 * メンバーが所属している組織内グループID一覧
 */
function getMemberGroupIds(PDO $pdo, $orgId, $userId) {
    $orgId = (int)$orgId;
    $userId = (int)$userId;
    if (!tableHasColumn($pdo, 'conversations', 'organization_id')) {
        return [];
    }
    $hasLeftAt = tableHasColumn($pdo, 'conversation_members', 'left_at');
    $leftCond = $hasLeftAt ? ' AND cm.left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT c.id
        FROM conversation_members cm
        INNER JOIN conversations c ON c.id = cm.conversation_id
        WHERE c.type = 'group' AND c.organization_id = ? AND cm.user_id = ? " . $leftCond . "
    ");
    $stmt->execute([$orgId, $userId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/**
 * メンバーを複数グループに一括追加（既にいればスキップ）
 */
function addMemberToGroups(PDO $pdo, $orgId, $userId, array $groupIds) {
    $orgId = (int)$orgId;
    $userId = (int)$userId;
    if (!tableHasColumn($pdo, 'conversations', 'organization_id')) {
        return;
    }
    $hasLeftAt = tableHasColumn($pdo, 'conversation_members', 'left_at');
    foreach ($groupIds as $gid) {
        $gid = (int)$gid;
        if ($gid <= 0) continue;
        $stmt = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND type = 'group' AND organization_id = ?");
        $stmt->execute([$gid, $orgId]);
        if (!$stmt->fetch()) continue;
        $stmt = $pdo->prepare("SELECT id, left_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$gid, $userId]);
        $ex = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            if ($hasLeftAt && $ex['left_at']) {
                $pdo->prepare("UPDATE conversation_members SET left_at = NULL, role = 'member', joined_at = NOW() WHERE id = ?")->execute([$ex['id']]);
            }
        } else {
            $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $userId]);
        }
    }
}

/**
 * メンバーの所属グループを指定リストに同期（含まないグループからは退出）
 */
function syncMemberGroups(PDO $pdo, $orgId, $userId, array $groupIds) {
    $orgId = (int)$orgId;
    $userId = (int)$userId;
    if (!tableHasColumn($pdo, 'conversations', 'organization_id')) {
        return;
    }
    $hasLeftAt = tableHasColumn($pdo, 'conversation_members', 'left_at');
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE type = 'group' AND organization_id = ?");
    $stmt->execute([$orgId]);
    $allOrgGroupIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $allOrgGroupIds[] = (int)$row['id'];
    }
    $wantSet = array_map('intval', array_filter($groupIds));
    foreach ($allOrgGroupIds as $gid) {
        $stmt = $pdo->prepare("SELECT id, left_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$gid, $userId]);
        $ex = $stmt->fetch(PDO::FETCH_ASSOC);
        $shouldBeIn = in_array($gid, $wantSet, true);
        if ($ex) {
            if ($shouldBeIn && $hasLeftAt && $ex['left_at']) {
                $pdo->prepare("UPDATE conversation_members SET left_at = NULL, role = 'member', joined_at = NOW() WHERE id = ?")->execute([$ex['id']]);
            } elseif (!$shouldBeIn) {
                if ($hasLeftAt) {
                    $pdo->prepare("UPDATE conversation_members SET left_at = NOW() WHERE conversation_id = ? AND user_id = ?")->execute([$gid, $userId]);
                } else {
                    $pdo->prepare("DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?")->execute([$gid, $userId]);
                }
            }
        } elseif ($shouldBeIn) {
            $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $userId]);
        }
    }
}

/**
 * 新規メンバー登録の本体（exit しない。createMember / bulkInvite から呼ぶ）
 * @return array{success: bool, message?: string, error?: string, user_id?: int}
 */
function doCreateMember(PDO $pdo, $orgId, array $data) {
    $orgId = (int)$orgId;
    $fullName = trim($data['full_name'] ?? '');
    $displayName = trim($data['display_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));
    $phone = (strlen($phone) >= 10 && strlen($phone) <= 15) ? $phone : '';
    $memberType = ($data['member_type'] ?? 'internal') === 'external' ? 'external' : 'internal';
    $isOrgAdmin = !empty($data['is_org_admin']);

    if ($displayName === '') {
        return ['success' => false, 'error' => '表示名は必須です'];
    }
    if ($email === '' && $phone === '') {
        return ['success' => false, 'error' => 'メールアドレスまたは携帯電話番号のどちらかを入力してください'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => '有効なメールアドレスを入力してください'];
    }

    $pdo->beginTransaction();
    try {
        $existingUser = null;
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$existingUser && $phone !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingUser) {
            $userId = (int)$existingUser['id'];
            $hasLeftAtCreate = tableHasColumn($pdo, 'organization_members', 'left_at');
            $leftCondCreate = $hasLeftAtCreate ? ' AND left_at IS NULL' : '';
            $stmt = $pdo->prepare("SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ?" . $leftCondCreate);
            $stmt->execute([$orgId, $userId]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'このメールアドレス・電話番号のユーザーは既にこの組織に所属しています'];
            }
        } else {
            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
            $hasPhone = tableHasColumn($pdo, 'users', 'phone');
            if ($hasFullName && $hasPhone) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, phone, password_hash, display_name, full_name, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$email ?: null, $phone ?: null, $passwordHash, $displayName, $fullName ?: $displayName]);
            } elseif ($hasFullName) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password_hash, display_name, full_name, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$email ?: null, $passwordHash, $displayName, $fullName ?: $displayName]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password_hash, display_name, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$email ?: null, $passwordHash, $displayName]);
            }
            $userId = (int)$pdo->lastInsertId();
        }

        $role = $isOrgAdmin ? 'admin' : 'member';
        if ($memberType === 'external' && $isOrgAdmin) {
            $role = 'member';
        }

        $hasMemberType = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'member_type'");
            $hasMemberType = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {
        }
        $hasAcceptedAt = tableHasColumn($pdo, 'organization_members', 'accepted_at');

        if ($hasMemberType && $hasAcceptedAt) {
            $stmt = $pdo->prepare("
                INSERT INTO organization_members (organization_id, user_id, role, member_type, joined_at, accepted_at)
                VALUES (?, ?, ?, ?, NOW(), NULL)
            ");
            $stmt->execute([$orgId, $userId, $role, $memberType]);
        } elseif ($hasAcceptedAt) {
            $stmt = $pdo->prepare("
                INSERT INTO organization_members (organization_id, user_id, role, joined_at, accepted_at)
                VALUES (?, ?, ?, NOW(), NULL)
            ");
            $stmt->execute([$orgId, $userId, $role]);
        } elseif ($hasMemberType) {
            $stmt = $pdo->prepare("
                INSERT INTO organization_members (organization_id, user_id, role, member_type, joined_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$orgId, $userId, $role, $memberType]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO organization_members (organization_id, user_id, role, joined_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$orgId, $userId, $role]);
        }

        $groupIds = isset($data['group_ids']) && is_array($data['group_ids']) ? $data['group_ids'] : [];
        addMemberToGroups($pdo, $orgId, $userId, $groupIds);

        $pdo->commit();

        // 招待メール送信（承諾・パスワード設定リンク）。失敗しても登録は成功のまま
        try {
            $orgName = '組織';
            $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $stmt->execute([$orgId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty(trim($row['name'] ?? ''))) {
                $orgName = trim($row['name']);
            }
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $hasPrtTable = false;
            try {
                $chk = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
                $hasPrtTable = $chk && $chk->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasPrtTable) {
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
                $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$userId, $token, $expiresAt]);
            }
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $acceptUrl = rtrim($baseUrl, '/') . '/accept_org_invite.php?token=' . urlencode($token);
            require_once __DIR__ . '/../../includes/org_invite_mail.php';
            sendOrgInviteMail($email, $orgName, $acceptUrl, !empty($existingUser));
        } catch (Exception $e) {
            error_log('admin/api/members.php doCreateMember invite mail: ' . $e->getMessage());
        }

        $message = $existingUser
            ? '招待メールを送信しました（承諾後に正式所属になります）'
            : '招待メールを送信しました。本人がパスワードを設定して承諾すると組織に所属します。';
        return ['success' => true, 'message' => $message, 'user_id' => $userId];
    } catch (Exception $e) {
        $pdo->rollBack();
        if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'users.email') !== false) {
            return ['success' => false, 'error' => 'このメールアドレスは既に登録されています。'];
        }
        return ['success' => false, 'error' => '登録に失敗しました: ' . $e->getMessage()];
    }
}

/**
 * 一斉招待（候補リストの各件で doCreateMember を実行。マスター計画 4.4）
 * POST action=bulk_invite, body: { candidates: [{ display_name, email?, phone? }, ...] }
 */
function bulkInvite(PDO $pdo, $orgId, array $body) {
    $orgId = (int)$orgId;
    $candidates = $body['candidates'] ?? [];
    if (!is_array($candidates)) {
        jsonError('candidates は配列で指定してください', 400);
    }
    if (count($candidates) === 0) {
        jsonError('1件以上の候補を指定してください', 400);
    }
    $results = [];
    foreach ($candidates as $i => $c) {
        $data = [
            'display_name' => trim($c['display_name'] ?? ''),
            'full_name' => trim($c['full_name'] ?? ''),
            'email' => trim($c['email'] ?? ''),
            'phone' => trim($c['phone'] ?? ''),
            'member_type' => ($c['member_type'] ?? 'internal') === 'external' ? 'external' : 'internal',
            'is_org_admin' => !empty($c['is_org_admin']),
            'group_ids' => isset($c['group_ids']) && is_array($c['group_ids']) ? $c['group_ids'] : [],
        ];
        $r = doCreateMember($pdo, $orgId, $data);
        $results[] = [
            'index' => $i,
            'email' => $data['email'],
            'display_name' => $data['display_name'],
            'success' => $r['success'],
            'message' => $r['message'] ?? null,
            'error' => $r['error'] ?? null,
        ];
    }
    $succeeded = count(array_filter($results, function ($x) { return $x['success']; }));
    $failed = count($results) - $succeeded;
    $message = $failed === 0
        ? '全件の招待を送信しました。'
        : ($succeeded . ' 件送信、' . $failed . ' 件失敗しました。');
    jsonSuccess(['results' => $results, 'succeeded' => $succeeded, 'failed' => $failed], $message);
}

/**
 * 新規メンバー登録（表示名・メール・本名のみ。パスワードは本人がメールのリンクから設定し、承諾した時点で正式所属）
 */
function createMember(PDO $pdo, $orgId, array $data) {
    $result = doCreateMember($pdo, $orgId, $data);
    if (!$result['success']) {
        jsonError($result['error'], 400);
    }
    jsonSuccess([], $result['message']);
}

/**
 * password_reset_tokens テーブルがなければ作成する（招待再送・パスワードリセット用）
 * 自動作成に失敗した場合は例外を投げる（呼び出し元でマイグレーション案内を返す）
 */
function ensurePasswordResetTokensTable(PDO $pdo) {
    $chk = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
    if ($chk && $chk->rowCount() > 0) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_token (token),
                INDEX idx_token (token),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('ensurePasswordResetTokensTable: ' . $e->getMessage());
        jsonError('トークンテーブルを自動作成できませんでした。database/migration_password_reset_tokens.sql を実行してください。', 500);
    }
}

/**
 * 招待メール再送（未承諾のメンバーにのみ）
 */
function resendOrgInvite(PDO $pdo, $orgId, array $data) {
    $orgId = (int)$orgId;
    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId) {
        jsonError('user_id が必要です', 400);
    }
    $hasLeftAt = tableHasColumn($pdo, 'organization_members', 'left_at');
    $hasAcceptedAt = tableHasColumn($pdo, 'organization_members', 'accepted_at');
    if (!$hasAcceptedAt) {
        jsonError('招待再送は利用できません（accepted_at カラムがありません）', 400);
    }
    $leftCond = $hasLeftAt ? ' AND om.left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT om.user_id, u.email
        FROM organization_members om
        INNER JOIN users u ON om.user_id = u.id
        WHERE om.organization_id = ? AND om.user_id = ? AND om.accepted_at IS NULL" . $leftCond
    );
    $stmt->execute([$orgId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty(trim($row['email'] ?? ''))) {
        jsonError('承諾前のメンバーが見つかりません', 404);
    }
    $email = trim($row['email']);
    // 既存ユーザー（パスワード設定済み）なら統合案内メールを送る（マスター計画 4.3）
    $is_existing_user = false;
    $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $u = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($u && !empty(trim((string)($u['password_hash'] ?? '')))) {
        $is_existing_user = true;
    }
    $orgName = '組織';
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($o && !empty(trim($o['name'] ?? ''))) {
        $orgName = trim($o['name']);
    }
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    try {
        ensurePasswordResetTokensTable($pdo);
        $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
        $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $token, $expiresAt]);
    } catch (Throwable $e) {
        error_log('resendOrgInvite token: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $msg = $e->getMessage();
        if (strpos($msg, 'password_reset_tokens') !== false || strpos($msg, "doesn't exist") !== false || strpos($msg, 'exist') !== false || strpos($msg, 'Unknown table') !== false) {
            jsonError('トークン用テーブルがありません。database/migration_password_reset_tokens.sql を実行してください。', 500);
        }
        if (strpos($msg, 'ensurePasswordResetTokensTable') !== false || strpos($msg, 'undefined function') !== false) {
            jsonError('トークン用テーブルがありません。database/migration_password_reset_tokens.sql を実行し、admin/api/members.php を最新版に更新してください。', 500);
        }
        jsonError('トークンの発行に失敗しました: ' . (strlen($msg) < 80 ? $msg : 'データベースエラー'), 500);
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $acceptUrl = rtrim($baseUrl, '/') . '/accept_org_invite.php?token=' . urlencode($token);
    try {
        // プロジェクトルート = admin/api の2つ上
        $projectRoot = dirname(__DIR__, 2);
        $mailPath = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_invite_mail.php';
        if (!is_file($mailPath)) {
            jsonError('招待メール送信機能のファイルが見つかりません。includes/org_invite_mail.php と includes/Mailer.php をサーバーにアップロードしてください。', 500);
        }
        require_once $mailPath;
        if (!function_exists('sendOrgInviteMail')) {
            jsonError('招待メール送信関数が定義されていません。', 500);
        }
        $sent = sendOrgInviteMail($email, $orgName, $acceptUrl, $is_existing_user);
    } catch (Throwable $e) {
        error_log('resendOrgInvite mail: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        jsonError('メール送信でエラーが発生しました: ' . (strlen($e->getMessage()) < 60 ? $e->getMessage() : '送信処理に失敗しました'), 500);
    }
    jsonSuccess([], $sent ? '招待メールを再送しました' : 'メールの送信に失敗しましたが、リンクは発行済みです');
}

/**
 * メンバー更新
 */
function updateMember(PDO $pdo, $orgId, array $data) {
    $orgId = (int)$orgId;
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        jsonError('メンバーIDが必要です', 400);
    }

    $fullName = trim($data['full_name'] ?? '');
    $displayName = trim($data['display_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));
    $phone = (strlen($phone) >= 10 && strlen($phone) <= 15) ? $phone : '';
    $password = $data['password'] ?? '';
    $memberType = ($data['member_type'] ?? 'internal') === 'external' ? 'external' : 'internal';
    $isOrgAdmin = !empty($data['is_org_admin']);

    if ($displayName === '') {
        jsonError('表示名は必須です', 400);
    }
    if ($email === '' && $phone === '') {
        jsonError('メールアドレスまたは携帯電話番号のどちらかを入力してください', 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('有効なメールアドレスを入力してください', 400);
    }

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            jsonError('このメールアドレスは既に別のアカウントで使用されています。', 400);
        }
    }
    if ($phone !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
        $stmt->execute([$phone, $id]);
        if ($stmt->fetch()) {
            jsonError('この携帯電話番号は既に別のアカウントで使用されています。', 400);
        }
    }

    $hasLeftAtUpdate = tableHasColumn($pdo, 'organization_members', 'left_at');
    $leftCondUpdate = $hasLeftAtUpdate ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("SELECT user_id FROM organization_members WHERE organization_id = ? AND user_id = ?" . $leftCondUpdate);
    $stmt->execute([$orgId, $id]);
    if (!$stmt->fetch()) {
        jsonError('メンバーが見つかりません', 404);
    }

    $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
    $hasPhone = tableHasColumn($pdo, 'users', 'phone');

    $pdo->beginTransaction();
    try {
        if ($hasFullName && $hasPhone) {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$displayName, $fullName ?: $displayName, $email ?: null, $phone ?: null, $id]);
        } elseif ($hasFullName) {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, full_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$displayName, $fullName ?: $displayName, $email ?: null, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$displayName, $email ?: null, $id]);
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $id]);
        }

        $role = $isOrgAdmin ? 'admin' : 'member';
        if ($memberType === 'external') {
            $role = 'member';
        }

        $hasMemberType = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'member_type'");
            $hasMemberType = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {
        }

        if ($hasMemberType) {
            $pdo->prepare("UPDATE organization_members SET role = ?, member_type = ? WHERE organization_id = ? AND user_id = ?")
                ->execute([$role, $memberType, $orgId, $id]);
        } else {
            $pdo->prepare("UPDATE organization_members SET role = ? WHERE organization_id = ? AND user_id = ?")
                ->execute([$role, $orgId, $id]);
        }

        $groupIds = isset($data['group_ids']) && is_array($data['group_ids']) ? $data['group_ids'] : [];
        syncMemberGroups($pdo, $orgId, $id, $groupIds);

        $pdo->commit();
        jsonSuccess(['password_changed' => $password !== ''], '保存しました');
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('更新に失敗しました: ' . $e->getMessage(), 500);
    }
}

/**
 * メンバー削除（組織から退会）
 */
function deleteMember(PDO $pdo, $orgId, array $data) {
    $orgId = (int)$orgId;
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        jsonError('メンバーIDが必要です', 400);
    }

    $hasLeftAtDel = tableHasColumn($pdo, 'organization_members', 'left_at');
    if ($hasLeftAtDel) {
        $stmt = $pdo->prepare("UPDATE organization_members SET left_at = NOW() WHERE organization_id = ? AND user_id = ? AND left_at IS NULL");
        $stmt->execute([$orgId, $id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM organization_members WHERE organization_id = ? AND user_id = ?");
        $stmt->execute([$orgId, $id]);
    }
    if ($stmt->rowCount() === 0) {
        jsonError('メンバーが見つかりません', 404);
    }
    jsonSuccess([], 'メンバーを削除しました');
}

/**
 * CSV出力
 */
function exportCsv(PDO $pdo, $orgId) {
    $orgId = (int)$orgId;

    $hasMemberType = tableHasColumn($pdo, 'organization_members', 'member_type');
    $hasFullName = tableHasColumn($pdo, 'users', 'full_name');
    $hasLeftAtExport = tableHasColumn($pdo, 'organization_members', 'left_at');
    $leftCondExport = $hasLeftAtExport ? ' AND om.left_at IS NULL' : '';

    $select = 'u.id, u.display_name, u.email, u.created_at, om.role, om.joined_at';
    if ($hasFullName) {
        $select = 'u.id, u.full_name, u.display_name, u.email, u.created_at, om.role, om.joined_at';
    }
    if ($hasMemberType) {
        $select .= ', om.member_type';
    }
    $stmt = $pdo->prepare("
        SELECT $select
        FROM organization_members om
        INNER JOIN users u ON om.user_id = u.id
        WHERE om.organization_id = ?" . $leftCondExport . "
        ORDER BY om.role = 'owner' DESC, om.role = 'admin' DESC, u.display_name ASC
    ");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="members_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', '種別', '氏名（本名）', '表示名', 'メールアドレス', '権限', '登録日']);
    foreach ($rows as $r) {
        $type = $hasMemberType ? (($r['member_type'] ?? '') === 'external' ? '外部' : '社員') : '社員';
        $role = in_array($r['role'] ?? '', ['owner', 'admin']) ? '組織管理者' : '一般';
        $fullName = $hasFullName ? ($r['full_name'] ?? '') : ($r['display_name'] ?? '');
        fputcsv($out, [
            $r['id'],
            $type,
            $fullName,
            $r['display_name'] ?? '',
            $r['email'] ?? '',
            $role,
            $r['joined_at'] ?? $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}
