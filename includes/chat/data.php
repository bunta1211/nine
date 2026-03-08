<?php
/**
 * チャット画面用データ取得
 * chat.php から分離したデータ取得ロジック
 * 
 * 依存: config/session.php, config/database.php, config/app.php
 *       includes/design_loader.php, includes/lang.php
 */

/**
 * 画像のみのメッセージか（翻訳対象外）
 * @param string $text メッセージ内容
 * @return bool
 */
function isImageOnlyContent($text) {
    $text = trim($text ?? '');
    if ($text === '') return true;
    $stripped = preg_replace('/[\x{1F4F7}\x{1F3AC}\x{1F4C4}\x{1F4FD}\x{1F4CE}\s]/u', '', $text);
    $stripped = preg_replace('/(?:uploads[\/\\\\]messages[\/\\\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:アップロード[\/\\\\]メッセージ[\/\\\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:(?:msg_|screenshot_|スクリーンショット_)[^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:https?:\/\/[^\s]+\.(jpg|jpeg|png|webp)(?:\?[^\s]*)?)/iu', '', $stripped);
    $stripped = preg_replace('/\s+/u', '', $stripped);
    return mb_strlen($stripped) < 3;
}

/**
 * DM相手の名前を一括取得（N+1問題の解消）
 * @param PDO $pdo
 * @param int $userId
 * @param array $conversationIds
 * @return array [conversation_id => ['display_name' => ...]]
 */
function getDmPartnerNames($pdo, $userId, $conversationIds) {
    if (empty($conversationIds)) {
        return [];
    }
    
    $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
    $stmt = $pdo->prepare("
        SELECT cm.conversation_id, u.display_name
        FROM conversation_members cm
        INNER JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id IN ($placeholders) 
        AND cm.user_id != ? 
        AND cm.left_at IS NULL
    ");
    $params = array_merge($conversationIds, [$userId]);
    $stmt->execute($params);
    
    $result = [];
    while ($row = $stmt->fetch()) {
        $result[$row['conversation_id']] = [
            'display_name' => $row['display_name']
        ];
    }
    return $result;
}

/**
 * チャット画面に必要なすべてのデータを取得
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function getChatPageData($pdo, $userId) {
    // ユーザー情報取得
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return ['error' => 'user_not_found'];
    }
    
    // 所属・管理している組織を取得
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.name, o.type, om.role as relationship
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE om.user_id = :user_id AND om.left_at IS NULL
        ORDER BY 
            CASE om.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, 
            o.name
    ");
    $stmt->execute([':user_id' => $userId]);
    $userOrganizations = $stmt->fetchAll();
    
    // 削除済みメッセージの除外条件（deleted_at / is_deleted のどちらかに対応）
    $msgDeletedCondM = "m.deleted_at IS NULL";
    $msgDeletedCond = "deleted_at IS NULL";
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
        $hasDeletedAt = $chk && $chk->rowCount() > 0;
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
        $hasIsDeleted = $chk && $chk->rowCount() > 0;
        if (!$hasDeletedAt && $hasIsDeleted) {
            $msgDeletedCondM = "(m.is_deleted = 0 OR m.is_deleted IS NULL)";
            $msgDeletedCond = "(is_deleted = 0 OR is_deleted IS NULL)";
        } elseif (!$hasDeletedAt && !$hasIsDeleted) {
            $msgDeletedCondM = "1=1";
            $msgDeletedCond = "1=1";
        }
    } catch (Exception $e) {}
    
    // 会話一覧を取得（未読数含む）。last_read_message_id がある場合はメッセージIDで未読判定、ない場合は last_read_at のみ使用
    $listSqlWithMsgId = "
        SELECT 
            c.*,
            cm.is_muted,
            cm.is_pinned,
            cm.role as my_role,
            cm.last_read_at,
            lm.content as last_message,
            lm.created_at as last_message_at,
            (SELECT COUNT(*) FROM conversation_members WHERE conversation_id = c.id AND left_at IS NULL) as member_count,
            (SELECT COUNT(*) FROM messages m 
             WHERE m.conversation_id = c.id 
             AND $msgDeletedCondM 
             AND m.sender_id != ?
             AND (
                 (cm.last_read_message_id IS NOT NULL AND m.id > cm.last_read_message_id)
                 OR (cm.last_read_message_id IS NULL AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at))
             )
            ) as unread_count
        FROM conversations c
        INNER JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN (
            SELECT m1.conversation_id, m1.content, m1.created_at
            FROM messages m1
            INNER JOIN (
                SELECT conversation_id, MAX(id) as max_id
                FROM messages
                WHERE $msgDeletedCond
                GROUP BY conversation_id
            ) m2 ON m1.id = m2.max_id
        ) lm ON lm.conversation_id = c.id
        WHERE cm.user_id = ? AND cm.left_at IS NULL
        ORDER BY cm.is_pinned DESC, COALESCE(lm.created_at, c.created_at) DESC
    ";
    $listSqlFallback = "
        SELECT 
            c.*,
            cm.is_muted,
            cm.is_pinned,
            cm.role as my_role,
            cm.last_read_at,
            lm.content as last_message,
            lm.created_at as last_message_at,
            (SELECT COUNT(*) FROM conversation_members WHERE conversation_id = c.id AND left_at IS NULL) as member_count,
            (SELECT COUNT(*) FROM messages m 
             WHERE m.conversation_id = c.id 
             AND $msgDeletedCondM 
             AND m.sender_id != ?
             AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at)
            ) as unread_count
        FROM conversations c
        INNER JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN (
            SELECT m1.conversation_id, m1.content, m1.created_at
            FROM messages m1
            INNER JOIN (
                SELECT conversation_id, MAX(id) as max_id
                FROM messages
                WHERE $msgDeletedCond
                GROUP BY conversation_id
            ) m2 ON m1.id = m2.max_id
        ) lm ON lm.conversation_id = c.id
        WHERE cm.user_id = ? AND cm.left_at IS NULL
        ORDER BY cm.is_pinned DESC, COALESCE(lm.created_at, c.created_at) DESC
    ";
    try {
        $stmt = $pdo->prepare($listSqlWithMsgId);
        $stmt->execute([$userId, $userId]);
        $conversations = $stmt->fetchAll();
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'last_read_message_id') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare($listSqlFallback);
            $stmt->execute([$userId, $userId]);
            $conversations = $stmt->fetchAll();
        } else {
            throw $e;
        }
    }
    
    // DM相手の名前を一括取得（N+1問題解消）
    // 2人チャットでは常に相手の名前を表示（DBのnameは作成者の視点で設定されるため、閲覧者側では相手名で上書き）
    $dmConversationIds = [];
    foreach ($conversations as $conv) {
        $isDmLike = ($conv['type'] === 'dm');
        if (!$isDmLike && (int)($conv['member_count'] ?? 0) == 2) {
            $isDmLike = true;
        }
        if ($isDmLike) {
            $dmConversationIds[] = $conv['id'];
        }
    }
    
    $dmPartners = getDmPartnerNames($pdo, $userId, $dmConversationIds);
    
    // DM・2人チャットでは常に相手の名前を設定（話しかけられた側は相手の名前が見えるように）
    foreach ($conversations as &$conv) {
        $isDmLike = ($conv['type'] === 'dm');
        if (!$isDmLike && (int)($conv['member_count'] ?? 0) == 2) {
            $isDmLike = true;
        }
        
        if ($isDmLike && isset($dmPartners[$conv['id']])) {
            $partnerName = $dmPartners[$conv['id']]['display_name'];
            $conv['name'] = $partnerName;
            $conv['name_en'] = $partnerName;
            $conv['name_zh'] = $partnerName;
        }
        // 左パネル組織フィルタ用：organization_id を必ずセット（カラムが無い環境では null）
        if (!array_key_exists('organization_id', $conv)) {
            $conv['organization_id'] = null;
        } elseif ($conv['organization_id'] !== null && $conv['organization_id'] !== '') {
            $conv['organization_id'] = (int)$conv['organization_id'];
        }
        // プライベートグループ設定（マスター計画 2.8: カラム存在時は int で統一）
        if (array_key_exists('is_private_group', $conv)) {
            $conv['is_private_group'] = (int)($conv['is_private_group'] ?? 0);
            $conv['allow_member_post'] = (int)($conv['allow_member_post'] ?? 1);
            $conv['allow_data_send'] = (int)($conv['allow_data_send'] ?? 1);
            $conv['member_list_visible'] = (int)($conv['member_list_visible'] ?? 1);
            $conv['allow_add_contact_from_group'] = (int)($conv['allow_add_contact_from_group'] ?? 1);
        }
    }
    unset($conv);
    
    // ユーザー一覧（新規会話用）
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id != ? ORDER BY display_name LIMIT 100");
    $stmt->execute([$userId]);
    $allUsers = $stmt->fetchAll();
    
    // 友達欄用：所属グループの全メンバー（重複排除、自分除く）
    $groupMembers = getGroupMembersForFriends($pdo, $userId);
    
    return [
        'user' => $user,
        'userOrganizations' => $userOrganizations,
        'conversations' => $conversations,
        'allUsers' => $allUsers,
        'groupMembers' => $groupMembers,
        'totalConversations' => count($conversations)
    ];
}

/**
 * 友達欄用：所属グループの全メンバーを取得（自分を除く、重複排除）
 * 「メンバー間DMを許可」にチェックがあるグループのメンバーのみ表示
 * システム管理者は全ユーザーを返す
 */
function getGroupMembersForFriends($pdo, $userId) {
    $current_role = $_SESSION['role'] ?? null;
    if ($current_role === null || $current_role === '') {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_role = $row['role'] ?? 'user';
    }
    $is_system_admin = in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin']);
    
    if ($is_system_admin) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.display_name, u.avatar_path, '全ユーザー' as group_names
            FROM users u
            WHERE u.id != ? AND u.status = 'active'
            ORDER BY u.display_name ASC
        ");
        $stmt->execute([$userId]);
    } else {
        // allow_member_dm カラムの存在確認（マイグレーション未実行時は全件表示にフォールバック）
        $hasAllowMemberDmColumn = false;
        try {
            $check = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'allow_member_dm'");
            $hasAllowMemberDmColumn = $check && $check->rowCount() > 0;
        } catch (PDOException $e) {
            // 無視
        }
        $dmCondition = $hasAllowMemberDmColumn ? "AND (c.allow_member_dm IS NULL OR c.allow_member_dm = 1)" : "";
        
        $stmt = $pdo->prepare("
            (
                SELECT DISTINCT u.id, u.display_name, u.avatar_path,
                    GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as group_names
                FROM users u
                INNER JOIN conversation_members cm ON u.id = cm.user_id AND cm.left_at IS NULL
                INNER JOIN conversations c ON cm.conversation_id = c.id AND c.type = 'group' $dmCondition
                INNER JOIN conversation_members my_cm ON c.id = my_cm.conversation_id
                    AND my_cm.user_id = ? AND my_cm.left_at IS NULL
                WHERE u.id != ?
                GROUP BY u.id, u.display_name, u.avatar_path
            )
            UNION
            (
                SELECT u.id, u.display_name, u.avatar_path, 'システム管理者' as group_names
                FROM users u
                WHERE u.role = 'system_admin' AND u.id != ? AND u.status = 'active'
            )
            ORDER BY display_name ASC
        ");
        $stmt->execute([$userId, $userId, $userId]);
    }
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $seen = [];
    $members = [];
    foreach ($raw as $m) {
        $id = (int)$m['id'];
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $m['id'] = $id;
            $members[] = $m;
        }
    }
    return $members;
}

/**
 * task_id付きメッセージにタスク詳細を付与
 * @param PDO $pdo
 * @param array $messages
 * @return array
 */
function enrichMessagesWithTaskDetails($pdo, $messages) {
    $taskIds = [];
    $msgIdToTask = [];
    $taskDeletedClause = '';
    try {
        $chkDel = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
        if ($chkDel && $chkDel->rowCount() > 0) $taskDeletedClause = ' AND t.deleted_at IS NULL';
    } catch (Exception $e) {}
    foreach ($messages as $m) {
        $tid = isset($m['task_id']) ? (int)$m['task_id'] : 0;
        if ($tid > 0) {
            $taskIds[$tid] = true;
        }
    }
    // notification_message_idからタスクを逆引き（task_idがない場合のフォールバック）
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'notification_message_id'");
        if ($chk && $chk->rowCount() > 0 && !empty($messages)) {
            $msgIds = array_map(function($m) { return (int)$m['id']; }, $messages);
            $ph = implode(',', array_fill(0, count($msgIds), '?'));
            $delCheck = '';
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
                if ($chk && $chk->rowCount() > 0) $delCheck = ' AND t.deleted_at IS NULL';
            } catch (Exception $e) {}
            $stmt = $pdo->prepare("
                SELECT t.id, t.notification_message_id, t.title, t.due_date, t.status,
                    t.created_by as requester_id,
                    t.assigned_to as worker_id,
                    creator.display_name as requester_name,
                    worker.display_name as worker_name
                FROM tasks t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN users worker ON t.assigned_to = worker.id
                WHERE t.notification_message_id IN ($ph) {$taskDeletedClause}
            ");
            $stmt->execute($msgIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mid = (int)$row['notification_message_id'];
                $msgIdToTask[$mid] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'] ?? '',
                    'due_date' => $row['due_date'] ?? null,
                    'requester_id' => isset($row['requester_id']) ? (int)$row['requester_id'] : null,
                    'worker_id' => isset($row['worker_id']) ? (int)$row['worker_id'] : null,
                    'requester_name' => $row['requester_name'] ?? null,
                    'worker_name' => $row['worker_name'] ?? null
                ];
                $taskIds[(int)$row['id']] = true;
            }
        }
    } catch (Exception $e) {}
    $taskIds = array_keys($taskIds);
    if (empty($taskIds)) {
        foreach ($messages as &$msg) {
            $mid = (int)$msg['id'];
            if (isset($msgIdToTask[$mid])) {
                $msg['task_id'] = $msgIdToTask[$mid]['id'];
                $msg['task_detail'] = $msgIdToTask[$mid];
            }
        }
        return $messages;
    }
    $ph = implode(',', array_fill(0, count($taskIds), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, t.due_date, t.status,
                t.created_by as requester_id,
                t.assigned_to as worker_id,
                creator.display_name as requester_name,
                worker.display_name as worker_name
            FROM tasks t
            LEFT JOIN users creator ON t.created_by = creator.id
            LEFT JOIN users worker ON t.assigned_to = worker.id
            WHERE t.id IN ($ph) {$taskDeletedClause}
        ");
        $stmt->execute($taskIds);
        $taskDetails = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $r = $row;
            if (isset($r['requester_id'])) $r['requester_id'] = (int)$r['requester_id'];
            if (isset($r['worker_id'])) $r['worker_id'] = (int)$r['worker_id'];
            $taskDetails[(int)$row['id']] = $r;
        }
        foreach ($messages as &$msg) {
            $tid = isset($msg['task_id']) ? (int)$msg['task_id'] : 0;
            if ($tid <= 0) {
                $mid = (int)$msg['id'];
                if (isset($msgIdToTask[$mid])) {
                    $msg['task_id'] = $msgIdToTask[$mid]['id'];
                    $tid = $msg['task_id'];
                }
            }
            if ($tid > 0) {
                $msg['task_detail'] = $taskDetails[$tid] ?? $msgIdToTask[$msg['id']] ?? null;
            }
        }
    } catch (Exception $e) {}
    
    $taskDeletedClause = '';
    try {
        $chkDel = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
        if ($chkDel && $chkDel->rowCount() > 0) $taskDeletedClause = ' AND t.deleted_at IS NULL';
    } catch (Exception $e) {}
    
    // 追加フォールバック1: task_idはあるがtask_detailが空の場合、直接タスクを取得
    foreach ($messages as &$msg) {
        if (!empty($msg['task_detail'])) continue;
        $tid = isset($msg['task_id']) ? (int)$msg['task_id'] : 0;
        if ($tid <= 0) continue;
        try {
            $stmt = $pdo->prepare("
                SELECT t.id, t.title, t.due_date, t.status,
                    t.created_by as requester_id,
                    t.assigned_to as worker_id,
                    creator.display_name as requester_name,
                    worker.display_name as worker_name
                FROM tasks t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN users worker ON t.assigned_to = worker.id
                WHERE t.id = ? {$taskDeletedClause}
            ");
            $stmt->execute([$tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $msg['task_detail'] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'] ?? '',
                    'due_date' => $row['due_date'] ?? null,
                    'requester_id' => isset($row['requester_id']) ? (int)$row['requester_id'] : null,
                    'worker_id' => isset($row['worker_id']) ? (int)$row['worker_id'] : null,
                    'requester_name' => $row['requester_name'] ?? null,
                    'worker_name' => $row['worker_name'] ?? null
                ];
            }
        } catch (Exception $e) {}
    }
    
    // 追加フォールバック2: タスクメッセージでtask_detailがない場合、作成者と時刻でタスクを検索
    foreach ($messages as &$msg) {
        if (!empty($msg['task_detail'])) continue;
        $content = $msg['content'] ?? '';
        $msgType = $msg['message_type'] ?? '';
        $isTaskMsg = $msgType === 'system' && (strpos($content, '📋') !== false || strpos($content, '✅') !== false || strpos($content, 'タスク') !== false);
        if (!$isTaskMsg) continue;
        
        try {
            $senderId = (int)($msg['sender_id'] ?? 0);
            $createdAt = $msg['created_at'] ?? '';
            if ($senderId > 0 && $createdAt) {
                $stmt = $pdo->prepare("
                    SELECT t.id, t.title, t.due_date, t.status,
                        t.created_by as requester_id,
                        t.assigned_to as worker_id,
                        creator.display_name as requester_name,
                        worker.display_name as worker_name
                    FROM tasks t
                    LEFT JOIN users creator ON t.created_by = creator.id
                    LEFT JOIN users worker ON t.assigned_to = worker.id
                    WHERE t.created_by = ?
                    AND t.created_at BETWEEN DATE_SUB(?, INTERVAL 10 SECOND) AND DATE_ADD(?, INTERVAL 10 SECOND)
                    {$taskDeletedClause}
                    ORDER BY t.id DESC
                    LIMIT 1
                ");
                $stmt->execute([$senderId, $createdAt, $createdAt]);
                $fallbackTask = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fallbackTask) {
                    $msg['task_id'] = (int)$fallbackTask['id'];
                    $msg['task_detail'] = [
                        'id' => (int)$fallbackTask['id'],
                        'title' => $fallbackTask['title'] ?? '',
                        'due_date' => $fallbackTask['due_date'] ?? null,
                        'requester_id' => isset($fallbackTask['requester_id']) ? (int)$fallbackTask['requester_id'] : null,
                        'worker_id' => isset($fallbackTask['worker_id']) ? (int)$fallbackTask['worker_id'] : null,
                        'requester_name' => $fallbackTask['requester_name'] ?? null,
                        'worker_name' => $fallbackTask['worker_name'] ?? null
                    ];
                }
            }
        } catch (Exception $e) {}
    }
    
    // 削除済みタスクのメッセージを除外
    $messages = array_values(array_filter($messages, function($m) {
        if (($m['message_type'] ?? '') !== 'system') return true;
        $tid = $m['task_id'] ?? 0;
        if (!$tid) return true;
        return !empty($m['task_detail']);
    }));
    
    return $messages;
}

/**
 * 選択中の会話のデータを取得
 * @param PDO $pdo
 * @param int $userId
 * @param int|null $conversationId
 * @return array
 */
function getSelectedConversationData($pdo, $userId, $conversationId) {
    if (!$conversationId) {
        return [
            'conversation' => null,
            'messages' => [],
            'members' => []
        ];
    }
    
    // 選択中の会話を取得
    $stmt = $pdo->prepare("
        SELECT c.*, cm.role as my_role
        FROM conversations c
        INNER JOIN conversation_members cm ON c.id = cm.conversation_id
        WHERE c.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
    ");
    $stmt->execute([$conversationId, $userId]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        return [
            'conversation' => null,
            'messages' => [],
            'members' => []
        ];
    }
    
    // DMまたは2人グループの場合、相手の名前を設定（常に相手名を表示・DBのnameは上書き）
    $isDmLike = ($conversation['type'] === 'dm');
    if (!$isDmLike) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM conversation_members 
            WHERE conversation_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversationId]);
        if ((int)$stmt->fetchColumn() == 2) {
            $isDmLike = true;
        }
    }
    
    if ($isDmLike) {
        $stmt = $pdo->prepare("
            SELECT u.display_name FROM conversation_members cm
            INNER JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ? AND cm.user_id != ? AND cm.left_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$conversationId, $userId]);
        $dmPartner = $stmt->fetch();
        if ($dmPartner) {
            $conversation['name'] = $dmPartner['display_name'];
            $conversation['name_en'] = $dmPartner['display_name'];
            $conversation['name_zh'] = $dmPartner['display_name'];
            $conversation['is_dm_like'] = true;
        }
    }
    // プライベートグループ設定（マスター計画 2.8: カラム存在時は int で統一）
    if (array_key_exists('is_private_group', $conversation)) {
        $conversation['is_private_group'] = (int)($conversation['is_private_group'] ?? 0);
        $conversation['allow_member_post'] = (int)($conversation['allow_member_post'] ?? 1);
        $conversation['allow_data_send'] = (int)($conversation['allow_data_send'] ?? 1);
        $conversation['member_list_visible'] = (int)($conversation['member_list_visible'] ?? 1);
        $conversation['allow_add_contact_from_group'] = (int)($conversation['allow_add_contact_from_group'] ?? 1);
    }
    
    // メッセージ取得（deleted_at / is_deleted のどちらかで削除済みを除外）
    $msgDeletedClause = " AND m.deleted_at IS NULL";
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
        $hasDeletedAt = $chk && $chk->rowCount() > 0;
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
        $hasIsDeleted = $chk && $chk->rowCount() > 0;
        $msgDeletedClause = ($hasDeletedAt ? " AND m.deleted_at IS NULL" : "") . ($hasIsDeleted ? " AND (m.is_deleted = 0 OR m.is_deleted IS NULL)" : "");
    } catch (Exception $e) {}
    $hasReplyToIdCol = false;
    try {
        $chkReply = $pdo->query("SHOW COLUMNS FROM messages LIKE 'reply_to_id'");
        $hasReplyToIdCol = $chkReply && $chkReply->rowCount() > 0;
    } catch (Exception $e) {}
    if ($hasReplyToIdCol) {
        $stmt = $pdo->prepare("
            SELECT m.*, u.display_name as sender_name, u.avatar_path as sender_avatar,
                   rm.content as reply_to_content, ru.display_name as reply_to_sender_name
            FROM messages m
            INNER JOIN users u ON m.sender_id = u.id
            LEFT JOIN messages rm ON m.reply_to_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id
            WHERE m.conversation_id = ?{$msgDeletedClause}
            ORDER BY m.created_at ASC
            LIMIT 100
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT m.*, u.display_name as sender_name, u.avatar_path as sender_avatar,
                   NULL as reply_to_content, NULL as reply_to_sender_name
            FROM messages m
            INNER JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?{$msgDeletedClause}
            ORDER BY m.created_at ASC
            LIMIT 100
        ");
    }
    $stmt->execute([$conversationId]);
    $messages = $stmt->fetchAll();
    
    // メンション・リアクション情報を取得
    $messages = enrichMessagesWithMentionsAndReactions($pdo, $messages, $userId);
    
    // task_id付きメッセージにタスク詳細を付与（タスクカード表示用）
    $messages = enrichMessagesWithTaskDetails($pdo, $messages);
    
    // 翻訳キャッシュを付与（一度翻訳した結果を更新後も残し、APIを無駄に呼ばない）
    $messages = enrichMessagesWithCachedTranslation($pdo, $messages, $userId);
    
    // クライアント用に日時をISO 8601で統一（現在時刻とずれない表示のため）
    if (function_exists('formatDatetimeForClient')) {
        foreach ($messages as &$m) {
            if (!empty($m['created_at'])) {
                $m['created_at'] = formatDatetimeForClient($m['created_at']);
            }
        }
        unset($m);
    }
    
    // 返信IDをクライアント用に数値統一（PDOが文字列で返す環境対策）
    $replyCount = 0;
    foreach ($messages as &$m) {
        if (isset($m['reply_to_id']) && $m['reply_to_id'] !== '' && (int)$m['reply_to_id'] > 0) {
            $m['reply_to_id'] = (int)$m['reply_to_id'];
            $replyCount++;
        } else {
            $m['reply_to_id'] = null;
        }
    }
    unset($m);
    if ($replyCount > 0) {
        error_log("[reply_quote] data.php: " . $replyCount . " message(s) with reply_to_id, conversationId=" . $conversationId);
    }
    
    // メンバー取得
    $stmt = $pdo->prepare("
        SELECT u.id, u.display_name, u.avatar_path, u.online_status, cm.role
        FROM conversation_members cm
        INNER JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id = ? AND cm.left_at IS NULL
        ORDER BY cm.role DESC, u.display_name ASC
    ");
    $stmt->execute([$conversationId]);
    $members = $stmt->fetchAll();
    
    return [
        'conversation' => $conversation,
        'messages' => $messages,
        'members' => $members
    ];
}

/**
 * メッセージにメンション・リアクション情報を付加
 * @param PDO $pdo
 * @param array $messages
 * @param int $userId
 * @return array
 */
function enrichMessagesWithMentionsAndReactions($pdo, $messages, $userId) {
    $messageIds = array_column($messages, 'id');
    
    if (empty($messageIds)) {
        return $messages;
    }
    
    // 数値型に統一（PDOが文字列で返す場合があるため）
    $messageIds = array_map('intval', $messageIds);
    
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    
    // message_mentions に mention_type カラムがあるか（無い環境では To が保存されていても SELECT が落ちるため分岐）
    $hasMentionTypeCol = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
        $hasMentionTypeCol = $chk && $chk->rowCount() > 0;
    } catch (Throwable $e) {}
    
    $mentionedMessages = [];
    $messageMentions = [];
    
    if ($hasMentionTypeCol) {
        // 自分宛のメッセージを取得（To Allも含む）
        $stmt = $pdo->prepare("
            SELECT message_id, mention_type 
            FROM message_mentions 
            WHERE message_id IN ($placeholders) AND (mentioned_user_id = ? OR mention_type = 'to_all')
        ");
        $stmt->execute(array_merge($messageIds, [$userId]));
        while ($row = $stmt->fetch()) {
            $msgId = (int)$row['message_id'];
            $mentionedMessages[$msgId] = $row['mention_type'] ?? 'to';
        }
        
        $stmt = $pdo->prepare("
            SELECT mm.message_id, mm.mentioned_user_id, mm.mention_type, u.display_name
            FROM message_mentions mm
            LEFT JOIN users u ON mm.mentioned_user_id = u.id
            WHERE mm.message_id IN ($placeholders)
            ORDER BY mm.message_id, mm.id
        ");
        $stmt->execute($messageIds);
        while ($row = $stmt->fetch()) {
            $msgId = (int)$row['message_id'];
            if (!isset($messageMentions[$msgId])) {
                $messageMentions[$msgId] = [
                    'type' => $row['mention_type'],
                    'users' => [],
                    'user_ids' => []
                ];
            }
            if ($row['mention_type'] === 'to_all') {
                $messageMentions[$msgId]['type'] = 'to_all';
                $messageMentions[$msgId]['users'] = ['全員'];
            } else {
                if ($row['mentioned_user_id']) {
                    $messageMentions[$msgId]['user_ids'][] = (int)$row['mentioned_user_id'];
                    $messageMentions[$msgId]['users'][] = $row['display_name'] ?? (string)$row['mentioned_user_id'];
                }
            }
        }
    } else {
        // mention_type が無い環境: 自分が mentioned_user_id に含まれるメッセージを「自分宛」とする
        $stmt = $pdo->prepare("
            SELECT message_id FROM message_mentions 
            WHERE message_id IN ($placeholders) AND mentioned_user_id = ?
        ");
        $stmt->execute(array_merge($messageIds, [$userId]));
        while ($row = $stmt->fetch()) {
            $mentionedMessages[(int)$row['message_id']] = 'to';
        }
        
        $stmt = $pdo->prepare("
            SELECT mm.message_id, mm.mentioned_user_id, u.display_name
            FROM message_mentions mm
            LEFT JOIN users u ON mm.mentioned_user_id = u.id
            WHERE mm.message_id IN ($placeholders)
            ORDER BY mm.message_id, mm.id
        ");
        $stmt->execute($messageIds);
        while ($row = $stmt->fetch()) {
            $msgId = (int)$row['message_id'];
            if (!isset($messageMentions[$msgId])) {
                $messageMentions[$msgId] = ['type' => 'to', 'users' => [], 'user_ids' => []];
            }
            if ($row['mentioned_user_id']) {
                $messageMentions[$msgId]['user_ids'][] = (int)$row['mentioned_user_id'];
                $messageMentions[$msgId]['users'][] = $row['display_name'] ?? (string)$row['mentioned_user_id'];
            }
        }
    }
    
    // リアクション詳細を取得（人別・誰がしたか分かるように users 付き）
    // message_reactions テーブルが無い環境ではスキップ（ページが落ちないように）
    $reactionDetails = [];
    try {
        $chkReactions = $pdo->query("SHOW TABLES LIKE 'message_reactions'");
        if ($chkReactions && $chkReactions->rowCount() > 0 && !empty($messageIds)) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $pdo->prepare("
                SELECT mr.message_id, mr.reaction_type, mr.user_id,
                       COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) as display_name
                FROM message_reactions mr
                LEFT JOIN users u ON mr.user_id = u.id
                WHERE mr.message_id IN ($placeholders)
            ");
            $stmt->execute($messageIds);
            while ($row = $stmt->fetch()) {
                $msgId = (int)$row['message_id'];
                if (!isset($reactionDetails[$msgId])) {
                    $reactionDetails[$msgId] = [];
                }
                $type = $row['reaction_type'];
                $uid = (int)$row['user_id'];
                $name = $row['display_name'] ?? (string)$uid;
                $key = null;
                foreach ($reactionDetails[$msgId] as $i => $detail) {
                    if ($detail['type'] === $type) {
                        $key = $i;
                        break;
                    }
                }
                if ($key !== null) {
                    $reactionDetails[$msgId][$key]['count']++;
                    $reactionDetails[$msgId][$key]['users'][] = ['id' => $uid, 'name' => $name];
                    if ($uid == $userId) {
                        $reactionDetails[$msgId][$key]['is_mine'] = 1;
                    }
                } else {
                    $reactionDetails[$msgId][] = [
                        'type' => $type,
                        'reaction_type' => $type,
                        'count' => 1,
                        'users' => [['id' => $uid, 'name' => $name]],
                        'is_mine' => ($uid == $userId) ? 1 : 0
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('enrichMessagesWithMentionsAndReactions reaction_details: ' . $e->getMessage());
    }
    
    // メッセージに情報を追加（リアクションは上で取得済み）
    foreach ($messages as &$msg) {
        $msgId = (int)$msg['id'];
        $msg['is_mentioned_me'] = isset($mentionedMessages[$msgId]);
        $msg['mention_type'] = $mentionedMessages[$msgId] ?? null;
        $msg['to_info'] = $messageMentions[$msgId] ?? null;
        $msg['reaction_details'] = $reactionDetails[$msgId] ?? [];
    }
    unset($msg);
    
    return $messages;
}

/**
 * メッセージに翻訳キャッシュを付与（表示言語が日本語以外のとき、message_translations の結果を付ける）
 * 一度翻訳したキャッシュを更新後も残し、再翻訳APIを呼ばないため。
 * @param PDO $pdo
 * @param array $messages
 * @param int $userId
 * @return array
 */
function enrichMessagesWithCachedTranslation($pdo, $messages, $userId) {
    $displayLang = function_exists('getCurrentLanguage') ? getCurrentLanguage() : null;
    if ($displayLang === null) {
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(display_language), ''), 'ja') AS lang FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $displayLang = $row ? ($row['lang'] ?? 'ja') : 'ja';
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                $displayLang = 'ja';
            } else {
                throw $e;
            }
        }
    }
    if ($displayLang === 'ja' || trim($displayLang) === '') {
        return $messages;
    }
    $messageIds = array_column($messages, 'id');
    if (empty($messageIds)) {
        return $messages;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $pdo->prepare("
            SELECT message_id, translated_text
            FROM message_translations
            WHERE message_id IN ($placeholders) AND target_lang = ?
        ");
        $params = array_merge($messageIds, [$displayLang]);
        $stmt->execute($params);
        $cachedMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cachedMap[(int)$row['message_id']] = $row['translated_text'];
        }
        foreach ($messages as &$m) {
            $mid = (int)$m['id'];
            if (isset($cachedMap[$mid])) {
                $m['cached_translation'] = $cachedMap[$mid];
            }
        }
        unset($m);
    } catch (Throwable $e) {
        error_log('enrichMessagesWithCachedTranslation: ' . $e->getMessage());
    }
    return $messages;
}

/**
 * 既読を更新（DBに永続化し、次回ログイン時も既読を維持）
 * last_read_at と last_read_message_id の両方を更新し、タイムゾーンに依存しない未読判定を可能にする。
 * @param PDO $pdo
 * @param int $conversationId
 * @param int $userId
 */
function updateLastReadAt($pdo, $conversationId, $userId) {
    $conversationId = (int) $conversationId;
    $userId = (int) $userId;
    if ($conversationId <= 0 || $userId <= 0) {
        return;
    }
    // 参加中（left_at IS NULL）の行のみ更新（MySQL では UPDATE の SET 内で更新対象テーブルを参照できないため、最大メッセージIDを別途取得）
    $msgDeletedClause = " AND deleted_at IS NULL";
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
        $hasDeletedAt = $chk && $chk->rowCount() > 0;
        $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
        $hasIsDeleted = $chk && $chk->rowCount() > 0;
        $msgDeletedClause = ($hasDeletedAt ? " AND deleted_at IS NULL" : "") . ($hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "");
    } catch (Exception $e) {}
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?{$msgDeletedClause}");
        $stmt->execute([$conversationId]);
        $maxMsgId = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("
            UPDATE conversation_members
            SET last_read_at = NOW(), last_read_message_id = ?
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$maxMsgId, $conversationId, $userId]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'last_read_message_id') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("
                UPDATE conversation_members SET last_read_at = NOW()
                WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$conversationId, $userId]);
        } else {
            throw $e;
        }
    }
}
