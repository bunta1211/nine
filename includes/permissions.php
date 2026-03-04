<?php
/**
 * 権限チェック関数
 * 
 * 全ての権限チェックをここに集約することで、
 * 一貫した権限管理とメンテナンス性向上を実現
 */

require_once __DIR__ . '/roles.php';

/**
 * 現在の組織での権限情報を取得（キャッシュ付き）
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return array|null 権限情報、未所属の場合はnull
 */
function getOrgPermissions($pdo, $userId, $orgId) {
    static $cache = [];
    $key = "{$userId}_{$orgId}";
    
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare("
            SELECT 
                om.role,
                om.member_type,
                om.external_contact,
                om.call_restriction,
                om.can_view_messages,
                om.can_delete_messages,
                om.can_create_groups,
                om.can_leave_org,
                om.usage_start_time,
                om.usage_end_time,
                om.daily_limit_minutes,
                u.is_minor
            FROM organization_members om
            INNER JOIN users u ON om.user_id = u.id
            WHERE om.organization_id = ? AND om.user_id = ? AND om.left_at IS NULL
        ");
        $stmt->execute([$orgId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // 数値型キャスト
            $result['external_contact'] = (int)($result['external_contact'] ?? 0);
            $result['can_view_messages'] = (int)($result['can_view_messages'] ?? 1);
            $result['can_delete_messages'] = (int)($result['can_delete_messages'] ?? 1);
            $result['can_create_groups'] = (int)($result['can_create_groups'] ?? 0);
            $result['can_leave_org'] = (int)($result['can_leave_org'] ?? 0);
            $result['is_minor'] = (int)($result['is_minor'] ?? 0);
            $result['daily_limit_minutes'] = (int)($result['daily_limit_minutes'] ?? 0);
        }
        
        $cache[$key] = $result ?: null;
    }
    
    return $cache[$key];
}

/**
 * 組織のメンバー管理権限があるか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return bool
 */
function canManageOrgMembers($pdo, $userId, $orgId) {
    $perms = getOrgPermissions($pdo, $userId, $orgId);
    return $perms && in_array($perms['role'], [ORG_ROLE_OWNER, ORG_ROLE_ADMIN]);
}

/**
 * グループ作成権限があるか
 * @param PDO $pdo
 * @param int $userId
 * @param int|null $orgId 組織に紐付ける場合のみ指定
 * @return bool
 */
function canCreateGroup($pdo, $userId, $orgId = null) {
    // 組織に紐付けない場合は誰でも作成可能
    if (!$orgId) return true;
    
    $perms = getOrgPermissions($pdo, $userId, $orgId);
    if (!$perms) return false;
    
    // 管理者以上は常に可能
    if (in_array($perms['role'], [ORG_ROLE_OWNER, ORG_ROLE_ADMIN])) {
        return true;
    }
    
    // 制限付きメンバーは設定次第
    if ($perms['role'] === ORG_ROLE_RESTRICTED) {
        return (bool)$perms['can_create_groups'];
    }
    
    // 一般メンバーは可能
    return true;
}

/**
 * 会話（グループ/DM）への参加権限があるか
 * @param PDO $pdo
 * @param int $userId
 * @param int $conversationId
 * @return bool
 */
function canAccessConversation($pdo, $userId, $conversationId) {
    $stmt = $pdo->prepare("
        SELECT 1 FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$conversationId, $userId]);
    return (bool)$stmt->fetch();
}

/**
 * 会話の管理者権限があるか
 * @param PDO $pdo
 * @param int $userId
 * @param int $conversationId
 * @return bool
 */
function isConversationAdmin($pdo, $userId, $conversationId) {
    $stmt = $pdo->prepare("
        SELECT role FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$conversationId, $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result && $result['role'] === 'admin';
}

/**
 * ユーザーに連絡できるか（制限チェック）
 * @param PDO $pdo
 * @param int $fromUserId 送信者
 * @param int $toUserId 受信者
 * @param int|null $orgId 組織コンテキスト
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function canContactUser($pdo, $fromUserId, $toUserId, $orgId = null) {
    // 自分自身には常に連絡可能
    if ($fromUserId === $toUserId) {
        return ['allowed' => true, 'reason' => null];
    }
    
    // 組織コンテキストがある場合、両者が同じ組織に所属しているかチェック
    if ($orgId) {
        $fromPerms = getOrgPermissions($pdo, $fromUserId, $orgId);
        $toPerms = getOrgPermissions($pdo, $toUserId, $orgId);
        
        // 送信者が組織に所属していない
        if (!$fromPerms) {
            return ['allowed' => false, 'reason' => 'この組織に所属していません'];
        }
        
        // 受信者が組織に所属していない
        if (!$toPerms) {
            return ['allowed' => false, 'reason' => '相手はこの組織に所属していません'];
        }
        
        // 両者が同じ組織なら基本的にOK（制限付きメンバーのチェックは別途）
        return ['allowed' => true, 'reason' => null];
    }
    
    // 組織外連絡の制限チェック（制限付きメンバーの場合）
    // TODO: 制限付きメンバーの外部連絡制限を実装
    
    return ['allowed' => true, 'reason' => null];
}

/**
 * メッセージ削除権限があるか
 * @param PDO $pdo
 * @param int $userId
 * @param int $messageId
 * @return bool
 */
function canDeleteMessage($pdo, $userId, $messageId) {
    // メッセージ情報を取得
    $stmt = $pdo->prepare("
        SELECT m.sender_id, m.conversation_id, c.organization_id
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.id
        WHERE m.id = ?
    ");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$message) return false;
    
    // 自分のメッセージは削除可能
    if ((int)$message['sender_id'] === $userId) {
        return true;
    }
    
    // 会話の管理者は削除可能
    if (isConversationAdmin($pdo, $userId, $message['conversation_id'])) {
        return true;
    }
    
    // 組織紐付けの場合、組織管理者も削除可能
    if ($message['organization_id']) {
        $perms = getOrgPermissions($pdo, $userId, $message['organization_id']);
        if ($perms && in_array($perms['role'], [ORG_ROLE_OWNER, ORG_ROLE_ADMIN])) {
            return true;
        }
    }
    
    return false;
}

/**
 * 組織から退出できるか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function canLeaveOrganization($pdo, $userId, $orgId) {
    $perms = getOrgPermissions($pdo, $userId, $orgId);
    
    if (!$perms) {
        return ['allowed' => false, 'reason' => 'この組織に所属していません'];
    }
    
    // オーナーは退出不可（オーナー移譲が必要）
    if ($perms['role'] === ORG_ROLE_OWNER) {
        return ['allowed' => false, 'reason' => 'オーナーは組織を退出できません。オーナー権限を移譲してください。'];
    }
    
    // 制限付きメンバーは設定次第
    if ($perms['role'] === ORG_ROLE_RESTRICTED && !$perms['can_leave_org']) {
        return ['allowed' => false, 'reason' => '組織からの退出が制限されています'];
    }
    
    return ['allowed' => true, 'reason' => null];
}

/**
 * 通話を発信できるか
 * @param PDO $pdo
 * @param int $fromUserId 発信者
 * @param int $toUserId 着信者
 * @param int|null $orgId 組織コンテキスト
 * @return array ['allowed' => bool, 'reason' => string|null]
 */
function canMakeCall($pdo, $fromUserId, $toUserId, $orgId = null) {
    // 自分自身への通話は不可
    if ($fromUserId === $toUserId) {
        return ['allowed' => false, 'reason' => '自分自身には通話できません'];
    }
    
    // 組織コンテキストがある場合の制限チェック
    if ($orgId) {
        $fromPerms = getOrgPermissions($pdo, $fromUserId, $orgId);
        
        if ($fromPerms && $fromPerms['role'] === ORG_ROLE_RESTRICTED) {
            $callRestriction = $fromPerms['call_restriction'] ?? 'none';
            
            if ($callRestriction === 'approved_only') {
                // 承認済み連絡先のみ
                $stmt = $pdo->prepare("
                    SELECT 1 FROM approved_contacts 
                    WHERE organization_id = ? AND restricted_user_id = ? AND approved_user_id = ?
                ");
                $stmt->execute([$orgId, $fromUserId, $toUserId]);
                if (!$stmt->fetch()) {
                    return ['allowed' => false, 'reason' => 'この相手への通話は承認が必要です'];
                }
            } elseif ($callRestriction === 'org_only') {
                // 組織内メンバーのみ
                $toPerms = getOrgPermissions($pdo, $toUserId, $orgId);
                if (!$toPerms) {
                    return ['allowed' => false, 'reason' => '組織外のメンバーへの通話は制限されています'];
                }
            }
        }
    }
    
    return ['allowed' => true, 'reason' => null];
}

/**
 * システム管理者権限があるか
 * @return bool
 */
function hasSystemAdminRole() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['system_admin', 'super_admin', 'developer']);
}

/**
 * 権限エラーレスポンスを返して終了
 * @param string $message
 */
function denyAccess($message = '権限がありません') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error_type' => 'permission_denied'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


