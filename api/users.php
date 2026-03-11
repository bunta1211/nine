<?php
/**
 * ユーザー API
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */
require_once __DIR__ . '/../includes/api-bootstrap.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
    case 'list_group_members':
        // グループ作成用（include_dm_restricted=1）: 自分が所属する組織のメンバーを返す。組織未所属なら従来どおり同じグループのメンバー
        // 通常時: DM許可グループのメンバーのみ
        $includeDmRestricted = isset($_GET['include_dm_restricted']) && $_GET['include_dm_restricted'] === '1';
        
        if ($includeDmRestricted) {
            // 同一組織メンバーを優先。organization_members が存在し自分が所属していれば組織メンバーを返す
            $users = [];
            try {
                $chkOm = $pdo->query("SHOW TABLES LIKE 'organization_members'");
                if ($chkOm && $chkOm->rowCount() > 0) {
                    $stmtOrg = $pdo->prepare("
                        SELECT DISTINCT u.id, u.display_name, u.avatar_path
                        FROM users u
                        INNER JOIN organization_members om ON u.id = om.user_id AND om.left_at IS NULL
                        WHERE om.organization_id IN (
                            SELECT organization_id FROM organization_members WHERE user_id = ? AND left_at IS NULL
                        )
                        AND u.id != ?
                        AND u.status = 'active'
                        ORDER BY u.display_name
                    ");
                    $stmtOrg->execute([$user_id, $user_id]);
                    $users = $stmtOrg->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {}
            if (empty($users)) {
                // 組織未所属 or テーブルなし: 従来どおり同じグループのメンバー
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    INNER JOIN conversation_members cm ON u.id = cm.user_id
                    WHERE cm.conversation_id IN (
                        SELECT cm2.conversation_id 
                        FROM conversation_members cm2
                        INNER JOIN conversations c ON cm2.conversation_id = c.id
                        WHERE cm2.user_id = ? 
                        AND cm2.left_at IS NULL
                        AND c.type = 'group'
                    )
                    AND cm.left_at IS NULL
                    AND u.id != ?
                    ORDER BY u.display_name
                ");
                $stmt->execute([$user_id, $user_id]);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // 通常検索・DM用：DM許可グループのメンバーのみ
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.display_name, u.avatar_path
                FROM users u
                INNER JOIN conversation_members cm ON u.id = cm.user_id
                INNER JOIN conversations c ON cm.conversation_id = c.id
                WHERE cm.conversation_id IN (
                    SELECT cm2.conversation_id 
                    FROM conversation_members cm2
                    INNER JOIN conversations c2 ON cm2.conversation_id = c2.id
                    WHERE cm2.user_id = ? 
                    AND cm2.left_at IS NULL
                    AND c2.type = 'group'
                    AND c2.allow_member_dm = 1
                )
                AND cm.left_at IS NULL
                AND c.allow_member_dm = 1
                AND u.id != ?
                ORDER BY u.display_name
            ");
            $stmt->execute([$user_id, $user_id]);
        }
        $users = isset($users) ? $users : $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 数値型にキャスト
        foreach ($users as &$user) {
            $user['id'] = (int)$user['id'];
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        break;
        
    case 'search':
        $query = trim($_GET['q'] ?? '');
        $forGroupAdd = isset($_GET['for_group_add']) && $_GET['for_group_add'] === '1';
        $scopeOrg = isset($_GET['scope']) && $_GET['scope'] === 'org';
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => false, 'message' => '検索クエリが短すぎます']);
            exit;
        }
        
        $searchPattern = '%' . $query . '%';
        // 携帯電話検索用：数字のみの場合は完全一致・部分一致の両方で検索
        $phoneDigits = preg_replace('/\D/', '', $query);
        $phonePattern = $phoneDigits === '' ? null : '%' . $phoneDigits . '%';
        
        // 保護者による検索制限をチェック
        $searchRestricted = false;
        try {
            $stmtPr = $pdo->prepare("SELECT search_restricted FROM parental_restrictions WHERE child_user_id = ? AND is_active = 1");
            $stmtPr->execute([$user_id]);
            $restriction = $stmtPr->fetch();
            if ($restriction && (int)($restriction['search_restricted'] ?? 0) === 1) {
                $searchRestricted = true;
            }
        } catch (PDOException $e) {}
        
        // 組織内検索（scope=org & conversation_id 指定時）：追加先グループの組織メンバーのみ
        if ($scopeOrg && $conversationId > 0 && $forGroupAdd && !$searchRestricted) {
            $stmtConv = $pdo->prepare("SELECT organization_id FROM conversations WHERE id = ? AND type IN ('group', 'organization')");
            $stmtConv->execute([$conversationId]);
            $conv = $stmtConv->fetch(PDO::FETCH_ASSOC);
            $orgId = $conv && !empty($conv['organization_id']) ? (int)$conv['organization_id'] : null;
            if ($orgId) {
                try {
                    $chkOm = $pdo->query("SHOW TABLES LIKE 'organization_members'");
                    if ($chkOm && $chkOm->rowCount() > 0) {
                        $phoneCond = $phonePattern !== null ? " OR u.phone LIKE ?" : "";
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT u.id, u.display_name, u.avatar_path
                            FROM users u
                            INNER JOIN organization_members om ON u.id = om.user_id AND om.left_at IS NULL
                            WHERE om.organization_id = ?
                            AND u.id != ?
                            AND u.status = 'active'
                            AND (u.display_name LIKE ? OR u.email LIKE ? " . $phoneCond . ")
                            ORDER BY u.display_name
                            LIMIT 30
                        ");
                        $params = [$orgId, $user_id, $searchPattern, $searchPattern];
                        if ($phonePattern !== null) $params[] = $phonePattern;
                        $stmt->execute($params);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($users as &$u) { $u['id'] = (int)$u['id']; }
                        echo json_encode(['success' => true, 'users' => $users]);
                        exit;
                    }
                } catch (Exception $e) {}
            }
            // 組織未所属 or organization_members なし → 下記の forGroupAdd にフォールスルー
        }
        
        // 現在のユーザーがシステム管理者かチェック（セッション未設定時はDBから取得）
        $current_role = $_SESSION['role'] ?? null;
        if ($current_role === null || $current_role === '') {
            $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmtRole->execute([$user_id]);
            $row = $stmtRole->fetch(PDO::FETCH_ASSOC);
            $current_role = $row['role'] ?? 'user';
            $_SESSION['role'] = $current_role;
        }
        $is_system_admin = in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin']);
        
        // 保護者による検索制限をチェック
        $searchRestricted = false;
        try {
            $stmt = $pdo->prepare("SELECT search_restricted FROM parental_restrictions WHERE child_user_id = ? AND is_active = 1");
            $stmt->execute([$user_id]);
            $restriction = $stmt->fetch();
            if ($restriction && $restriction['search_restricted'] == 1) {
                $searchRestricted = true;
            }
        } catch (PDOException $e) {
            // テーブルが存在しない場合は無視
        }
        
        if ($is_system_admin && !$forGroupAdd) {
            // システム管理者は全ユーザーを検索可能（会話開始用）
            $phoneCond = $phonePattern !== null ? " OR u.phone LIKE ?" : "";
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.display_name, u.avatar_path
                FROM users u
                WHERE u.id != ?
                AND u.status = 'active'
                AND (u.display_name LIKE ? OR u.email LIKE ?" . $phoneCond . ")
                ORDER BY u.display_name
                LIMIT 20
            ");
            $params = [$user_id, $searchPattern, $searchPattern];
            if ($phonePattern !== null) $params[] = $phonePattern;
            $stmt->execute($params);
        } elseif ($forGroupAdd && !$searchRestricted) {
            // グループ追加用: 自分が所属する組織（organization_members）のメンバーのみ検索。組織未所属なら従来の同じグループのメンバー
            $phoneCond = $phonePattern !== null ? " OR u.phone LIKE ?" : "";
            $users = [];
            try {
                $chkOm = $pdo->query("SHOW TABLES LIKE 'organization_members'");
                if ($chkOm && $chkOm->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT u.id, u.display_name, u.avatar_path
                        FROM users u
                        INNER JOIN organization_members om ON u.id = om.user_id AND om.left_at IS NULL
                        WHERE om.organization_id IN (
                            SELECT organization_id FROM organization_members WHERE user_id = ? AND left_at IS NULL
                        )
                        AND u.id != ?
                        AND u.status = 'active'
                        AND (u.display_name LIKE ? OR u.email LIKE ?" . $phoneCond . ")
                        ORDER BY u.display_name
                        LIMIT 30
                    ");
                    $params = [$user_id, $user_id, $searchPattern, $searchPattern];
                    if ($phonePattern !== null) $params[] = $phonePattern;
                    $stmt->execute($params);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {}
            if (empty($users)) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    INNER JOIN conversation_members cm ON u.id = cm.user_id AND cm.left_at IS NULL
                    WHERE u.id != ?
                    AND u.status = 'active'
                    AND (u.display_name LIKE ? OR u.email LIKE ?" . $phoneCond . ")
                    AND cm.conversation_id IN (
                        SELECT cm2.conversation_id
                        FROM conversation_members cm2
                        INNER JOIN conversations c ON cm2.conversation_id = c.id AND c.type = 'group'
                        WHERE cm2.user_id = ? AND cm2.left_at IS NULL
                    )
                    ORDER BY u.display_name
                    LIMIT 30
                ");
                $params = [$user_id, $searchPattern, $searchPattern];
                if ($phonePattern !== null) $params[] = $phonePattern;
                $params[] = $user_id;
                $stmt->execute($params);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($searchRestricted) {
            // 保護者による検索制限：グループメンバー + システム管理者を検索可能
            $phoneCond = $phonePattern !== null ? " OR u.phone LIKE ?" : "";
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.display_name, u.avatar_path
                FROM users u
                LEFT JOIN conversation_members cm ON u.id = cm.user_id AND cm.left_at IS NULL
                LEFT JOIN conversations c ON cm.conversation_id = c.id AND c.type = 'group'
                WHERE u.id != ?
                AND u.status = 'active'
                AND (u.display_name LIKE ? OR u.email LIKE ?" . $phoneCond . ")
                AND (
                    u.role = 'system_admin'
                    OR (
                        cm.user_id IS NOT NULL
                        AND cm.conversation_id IN (
                            SELECT cm2.conversation_id 
                            FROM conversation_members cm2
                            WHERE cm2.user_id = ? AND cm2.left_at IS NULL
                        )
                    )
                )
                ORDER BY u.display_name
                LIMIT 20
            ");
            $params = [$user_id, $searchPattern, $searchPattern];
            if ($phonePattern !== null) $params[] = $phonePattern;
            $params[] = $user_id;
            $stmt->execute($params);
        } elseif (!$forGroupAdd && !$searchRestricted && strlen($phoneDigits) >= 10) {
            // 携帯番号検索：検索拒否でない・表示名が登録されているユーザーを表示（友達申請用）
            // 相手は承諾・保留・拒否できるため検索結果に表示する
            $phonePatternForSearch = '%' . $phoneDigits . '%';
            $hasPrivacy = false;
            try {
                $chk = $pdo->query("SHOW TABLES LIKE 'user_privacy_settings'");
                $hasPrivacy = $chk && $chk->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasPrivacy) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    LEFT JOIN user_privacy_settings ups ON u.id = ups.user_id
                    WHERE u.id != ?
                    AND u.status = 'active'
                    AND u.phone LIKE ?
                    AND TRIM(COALESCE(u.display_name,'')) != ''
                    AND (ups.id IS NULL OR ups.exclude_from_search = 0)
                    AND u.id NOT IN (SELECT blocked_user_id FROM blocked_users WHERE user_id = ?)
                    AND u.id NOT IN (SELECT friend_id FROM friendships WHERE user_id = ? AND status = 'blocked')
                    ORDER BY u.display_name
                    LIMIT 20
                ");
                $stmt->execute([$user_id, $phonePatternForSearch, $user_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    WHERE u.id != ?
                    AND u.status = 'active'
                    AND u.phone LIKE ?
                    AND TRIM(COALESCE(u.display_name,'')) != ''
                    AND u.id NOT IN (SELECT blocked_user_id FROM blocked_users WHERE user_id = ?)
                    AND u.id NOT IN (SELECT friend_id FROM friendships WHERE user_id = ? AND status = 'blocked')
                    ORDER BY u.display_name
                    LIMIT 20
                ");
                $stmt->execute([$user_id, $phonePatternForSearch, $user_id, $user_id]);
            }
        } else {
            // 通常の検索: 表示名でのヒットは同一組織に限定。email/phone は従来どおり同じグループ or システム管理者
            $phoneCond = $phonePattern !== null ? " OR u.phone LIKE :phone_query" : "";
            $orgSubquery = "";
            try {
                $chkOm = $pdo->query("SHOW TABLES LIKE 'organization_members'");
                if ($chkOm && $chkOm->rowCount() > 0) {
                    $orgSubquery = "
                    (u.display_name LIKE :query AND u.id IN (
                        SELECT om2.user_id FROM organization_members om2
                        WHERE om2.organization_id IN (SELECT organization_id FROM organization_members WHERE user_id = :current_user_org AND left_at IS NULL)
                        AND om2.left_at IS NULL
                    ))
                    OR
                    ";
                }
            } catch (Exception $e) {}
            if ($orgSubquery === "") {
                // 組織テーブルなし: 従来どおり (表示名・email・phone) AND (同じグループ or システム管理者)
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    WHERE u.id != :current_user_id
                      AND u.status = 'active'
                      AND (u.display_name LIKE :query OR u.email LIKE :query" . $phoneCond . ")
                      AND (
                        u.id IN (
                          SELECT cm2.user_id 
                          FROM conversation_members cm1
                          JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
                          JOIN conversations c ON cm1.conversation_id = c.id
                          WHERE cm1.user_id = :current_user_id2
                            AND cm1.left_at IS NULL
                            AND cm2.left_at IS NULL
                            AND c.type = 'group'
                            AND c.allow_member_dm = 1
                        )
                        OR u.role = 'system_admin'
                      )
                      AND u.id NOT IN (SELECT blocked_user_id FROM blocked_users WHERE user_id = :current_user_id3)
                      AND u.id NOT IN (SELECT friend_id FROM friendships WHERE user_id = :current_user_id4 AND status = 'blocked')
                    ORDER BY u.display_name
                    LIMIT 20
                ");
                $executeParams = [
                    ':current_user_id' => $user_id,
                    ':current_user_id2' => $user_id,
                    ':current_user_id3' => $user_id,
                    ':current_user_id4' => $user_id,
                    ':query' => $searchPattern
                ];
                if ($phonePattern !== null) $executeParams[':phone_query'] = $phonePattern;
                $stmt->execute($executeParams);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.display_name, u.avatar_path
                    FROM users u
                    WHERE u.id != :current_user_id
                      AND u.status = 'active'
                      AND (
                      " . $orgSubquery . "
                      (
                        (u.email LIKE :query" . ($phonePattern !== null ? " OR u.phone LIKE :phone_query" : "") . ")
                        AND (
                          u.id IN (
                            SELECT cm2.user_id 
                            FROM conversation_members cm1
                            JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
                            JOIN conversations c ON cm1.conversation_id = c.id
                            WHERE cm1.user_id = :current_user_id2
                              AND cm1.left_at IS NULL 
                              AND cm2.left_at IS NULL
                              AND c.type = 'group'
                              AND c.allow_member_dm = 1
                          )
                          OR u.role = 'system_admin'
                        )
                      )
                      )
                      AND u.id NOT IN (SELECT blocked_user_id FROM blocked_users WHERE user_id = :current_user_id3)
                      AND u.id NOT IN (SELECT friend_id FROM friendships WHERE user_id = :current_user_id4 AND status = 'blocked')
                    ORDER BY u.display_name
                    LIMIT 20
                ");
                $executeParams = [
                    ':current_user_id' => $user_id,
                    ':current_user_id2' => $user_id,
                    ':current_user_id3' => $user_id,
                    ':current_user_id4' => $user_id,
                    ':current_user_org' => $user_id,
                    ':query' => $searchPattern
                ];
                if ($phonePattern !== null) $executeParams[':phone_query'] = $phonePattern;
                $stmt->execute($executeParams);
            }
        }
        if (!isset($users)) {
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 新規ユーザーが最初に検索で見つけられるよう、システム管理者（SYSTEM_ADMIN_EMAIL の1件）を先頭に追加
        try {
            $sysEmail = defined('SYSTEM_ADMIN_EMAIL') ? SYSTEM_ADMIN_EMAIL : 'saitanibunta@social9.jp';
            $stmtSys = $pdo->prepare("
                SELECT u.id, u.display_name, u.avatar_path
                FROM users u
                WHERE u.role = 'system_admin' AND u.status = 'active' AND u.email = ? AND u.id != ?
                LIMIT 1
            ");
            $stmtSys->execute([$sysEmail, $user_id]);
            $sysAdmins = $stmtSys->fetchAll(PDO::FETCH_ASSOC);
            $existingIds = array_column($users, 'id');
            foreach ($sysAdmins as $sa) {
                $sa['id'] = (int) $sa['id'];
                if (!in_array($sa['id'], $existingIds, true)) {
                    array_unshift($users, $sa);
                    $existingIds[] = $sa['id'];
                }
            }
        } catch (PDOException $e) {}
        
        // 数値型にキャスト
        foreach ($users as &$user) {
            $user['id'] = (int)$user['id'];
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        break;
        
    case 'get':
        $target_id = (int)($_GET['id'] ?? 0);
        
        if (!$target_id) {
            echo json_encode(['success' => false, 'message' => 'ユーザーIDが必要です']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT id, display_name, email, avatar_path, online_status, last_active_at
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$target_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'ユーザーが見つかりません']);
            exit;
        }
        
        $user['id'] = (int)$user['id'];
        echo json_encode(['success' => true, 'user' => $user]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '不明なアクションです']);
}


