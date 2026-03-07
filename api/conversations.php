<?php
/**
 * 会話管理API
 * 仕様書: 08_グループ会話管理.md
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // 会話一覧を取得（N+1問題回避）
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $hasNameI18n = false;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'name_en'")->fetch();
            $hasNameI18n = ($col !== false);
        } catch (Throwable $e) { /* テーブルなし等 */ }
        
        $nameEnZh = $hasNameI18n ? "c.name_en,\n                c.name_zh," : '';
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.type,
                c.name,
                " . $nameEnZh . "
                c.description,
                c.icon_path,
                c.icon_style,
                c.icon_pos_x,
                c.icon_pos_y,
                c.icon_size,
                c.organization_id,
                c.is_public,
                c.created_at,
                cm.role as my_role,
                cm.is_pinned,
                cm.is_muted,
                cm.last_read_at,
                lm.content as last_message,
                lm.created_at as last_message_at,
                (
                    SELECT COUNT(*) FROM messages m
                    WHERE m.conversation_id = c.id 
                    AND m.sender_id != ?
                    AND m.deleted_at IS NULL
                    AND (
                        (cm.last_read_message_id IS NOT NULL AND m.id > cm.last_read_message_id)
                        OR (cm.last_read_message_id IS NULL AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01'))
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
                    WHERE deleted_at IS NULL
                    GROUP BY conversation_id
                ) m2 ON m1.id = m2.max_id
            ) lm ON lm.conversation_id = c.id
            WHERE cm.user_id = ? AND cm.left_at IS NULL
            ORDER BY cm.is_pinned DESC, COALESCE(lm.created_at, c.created_at) DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $user_id, $limit, $offset]);
        $conversations = $stmt->fetchAll();
        
        // DMの場合、相手の情報を取得 / グループの場合、メンバー数を取得
        foreach ($conversations as &$conv) {
            // 数値型をキャスト
            $conv['id'] = (int)$conv['id'];
            $conv['is_pinned'] = (int)$conv['is_pinned'];
            $conv['is_muted'] = (int)$conv['is_muted'];
            $conv['unread_count'] = (int)$conv['unread_count'];
            $conv['is_public'] = (int)($conv['is_public'] ?? 0);
            $conv['icon_pos_x'] = (float)($conv['icon_pos_x'] ?? 0);
            $conv['icon_pos_y'] = (float)($conv['icon_pos_y'] ?? 0);
            $conv['icon_size'] = (int)($conv['icon_size'] ?? 100);
            if ($conv['organization_id']) {
                $conv['organization_id'] = (int)$conv['organization_id'];
            }
            if (!$hasNameI18n) {
                $conv['name_en'] = null;
                $conv['name_zh'] = null;
            }
            
            if ($conv['type'] === 'dm') {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.display_name, u.avatar_path, u.online_status
                    FROM conversation_members cm
                    INNER JOIN users u ON cm.user_id = u.id
                    WHERE cm.conversation_id = ? AND cm.user_id != ? AND cm.left_at IS NULL
                    LIMIT 1
                ");
                $stmt->execute([$conv['id'], $user_id]);
                $other = $stmt->fetch();
                if ($other) {
                    $other['id'] = (int)$other['id'];
                    $conv['dm_partner'] = $other;
                    $conv['name'] = $other['display_name'];
                    $conv['name_en'] = $other['display_name'];
                    $conv['name_zh'] = $other['display_name'];
                }
            } else {
                // グループのメンバー数
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM conversation_members
                    WHERE conversation_id = ? AND left_at IS NULL
                ");
                $stmt->execute([$conv['id']]);
                $conv['member_count'] = (int)$stmt->fetch()['count'];
            }
        }
        
        successResponse(['conversations' => $conversations]);
        break;
    
    case 'list_my_admin_groups':
        // 本人確認未済で組織ルームを作る際の「既存のグループをこの組織に追加」用。自分が管理者のグループ一覧を返す
        $hasNameI18n = false;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'name_en'")->fetch();
            $hasNameI18n = ($col !== false);
        } catch (Throwable $e) {}
        $nameCols = $hasNameI18n ? 'c.name, c.name_en, c.name_zh' : 'c.name, NULL as name_en, NULL as name_zh';
        $stmt = $pdo->prepare("
            SELECT c.id, " . $nameCols . "
            FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL AND cm.role = 'admin'
            WHERE c.type = 'group'
            ORDER BY c.name
        ");
        $stmt->execute([$user_id]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as &$g) {
            $g['id'] = (int)$g['id'];
        }
        successResponse(['groups' => $groups]);
        break;
    
    case 'list_with_unread':
        // ポーリング用：未読数と時刻表示を含む軽量版リスト（アイコン表示用のカラムも取得）
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.type,
                c.name,
                c.icon_path,
                c.icon_style,
                c.icon_pos_x,
                c.icon_pos_y,
                c.icon_size,
                cm.is_pinned,
                lm.created_at as last_message_at,
                (
                    SELECT COUNT(*) FROM messages m
                    WHERE m.conversation_id = c.id 
                    AND m.sender_id != ?
                    AND m.deleted_at IS NULL
                    AND (
                        (cm.last_read_message_id IS NOT NULL AND m.id > cm.last_read_message_id)
                        OR (cm.last_read_message_id IS NULL AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01'))
                    )
                ) as unread_count
            FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id
            LEFT JOIN (
                SELECT m1.conversation_id, m1.created_at
                FROM messages m1
                INNER JOIN (
                    SELECT conversation_id, MAX(id) as max_id
                    FROM messages
                    WHERE deleted_at IS NULL
                    GROUP BY conversation_id
                ) m2 ON m1.id = m2.max_id
            ) lm ON lm.conversation_id = c.id
            WHERE cm.user_id = ? AND cm.left_at IS NULL
            ORDER BY cm.is_pinned DESC, COALESCE(lm.created_at, c.created_at) DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $conversations = $stmt->fetchAll();
        
        // 時刻表示を整形（数値型をキャスト）
        foreach ($conversations as &$conv) {
            $conv['id'] = (int)$conv['id'];
            $conv['is_pinned'] = (int)$conv['is_pinned'];
            $conv['unread_count'] = (int)$conv['unread_count'];
            $conv['icon_pos_x'] = isset($conv['icon_pos_x']) ? (float)$conv['icon_pos_x'] : 0;
            $conv['icon_pos_y'] = isset($conv['icon_pos_y']) ? (float)$conv['icon_pos_y'] : 0;
            $conv['icon_size'] = isset($conv['icon_size']) ? (int)$conv['icon_size'] : 100;
            
            // 時刻表示（例：今日なら時刻、昨日以前なら日付）
            if ($conv['last_message_at']) {
                $msgTime = strtotime($conv['last_message_at']);
                $today = strtotime('today');
                $yesterday = strtotime('yesterday');
                
                if ($msgTime >= $today) {
                    $conv['time_display'] = date('H:i', $msgTime);
                } elseif ($msgTime >= $yesterday) {
                    $conv['time_display'] = '昨日';
                } else {
                    $conv['time_display'] = date('n/j', $msgTime);
                }
            } else {
                $conv['time_display'] = '';
            }
        }
        
        successResponse(['conversations' => $conversations]);
        break;
        
    case 'mark_read':
        // 会話を既読にする（DBに永続化し、次回ログイン時も既読を維持）
        $conversation_id = (int)($input['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
        if ($conversation_id <= 0) {
            errorResponse('会話IDが必要です');
        }
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ? AND deleted_at IS NULL");
            $stmt->execute([$conversation_id]);
            $max_msg_id = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("
                UPDATE conversation_members
                SET last_read_at = NOW(), last_read_message_id = ?
                WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$max_msg_id, $conversation_id, (int)$user_id]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'last_read_message_id') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                $stmt = $pdo->prepare("
                    UPDATE conversation_members SET last_read_at = NOW()
                    WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
                ");
                $stmt->execute([$conversation_id, (int)$user_id]);
            } else {
                throw $e;
            }
        }
        successResponse(['marked' => true]);
        break;
        
    case 'get':
        // 会話詳細を取得
        $conversation_id = (int)($_GET['conversation_id'] ?? $_GET['id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        $stmt = $pdo->prepare("
            SELECT c.*, cm.role as my_role
            FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE c.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            errorResponse('会話が見つかりません', 404);
        }
        
        // メンバー一覧
        $stmt = $pdo->prepare("
            SELECT u.id, u.display_name, u.avatar_path, u.online_status, cm.role, cm.joined_at
            FROM conversation_members cm
            INNER JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ? AND cm.left_at IS NULL
            ORDER BY cm.role DESC, cm.joined_at ASC
        ");
        $stmt->execute([$conversation_id]);
        $members = $stmt->fetchAll();
        
        // メンバーIDを整数にキャスト
        foreach ($members as &$member) {
            $member['id'] = (int)$member['id'];
        }
        $conversation['members'] = $members;
        
        // 会話IDも整数にキャスト
        $conversation['id'] = (int)$conversation['id'];
        
        successResponse(['conversation' => $conversation]);
        break;
        
    case 'create_or_get_dm':
    case 'create_direct_chat':
        // 個人チャット（2人グループ）を取得または作成
        // 同じ相手との既存チャットがあればそれを返す
        $target_user_id = (int)($input['user_id'] ?? 0);
        
        if (!$target_user_id) {
            errorResponse('ユーザーIDが必要です');
        }
        
        if ($target_user_id === $user_id) {
            errorResponse('自分自身とチャットはできません');
        }
        
        // 対象ユーザーの名前を取得
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            errorResponse('ユーザーが見つかりません');
        }
        $targetName = $targetUser['display_name'];
        
        // 自分の名前を取得（システムメッセージ用）
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $myUser = $stmt->fetch();
        $myName = $myUser ? $myUser['display_name'] : 'Unknown';
        
        // 既存の2人チャットを検索（同じ2人だけが参加しているグループ）
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM conversations c
            INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ? AND cm1.left_at IS NULL
            INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ? AND cm2.left_at IS NULL
            WHERE c.type = 'group'
            AND (SELECT COUNT(*) FROM conversation_members cm3 WHERE cm3.conversation_id = c.id AND cm3.left_at IS NULL) = 2
            LIMIT 1
        ");
        $stmt->execute([$user_id, $target_user_id]);
        $existingChat = $stmt->fetch();
        
        // 既存チャットがあればそれを返す
        if ($existingChat) {
            successResponse([
                'conversation_id' => (int)$existingChat['id'],
                'is_new' => false
            ]);
            break;
        }
        
        // 2人チャットの場合は相手の名前のみ使用
        $groupName = $targetName;
        
        // 新しい2人グループを作成
        $pdo->beginTransaction();
        try {
            // 会話を作成（type='group'として作成し、2人でも独立したチャットに）
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, created_by, created_at, updated_at)
                VALUES ('group', ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$groupName, $user_id]);
            $conversation_id = (int)$pdo->lastInsertId();
            
            // 両方のユーザーを管理者として追加
            $stmt = $pdo->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                VALUES (?, ?, 'admin', NOW())
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $stmt->execute([$conversation_id, $target_user_id]);
            
            // システムメッセージを送信
            $systemMessage = $myName . ' さんがチャットを開始しました';
            $pdo->prepare("
                INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
                VALUES (?, ?, ?, 'system', NOW())
            ")->execute([$conversation_id, $user_id, $systemMessage]);
            
            $pdo->commit();
            
            successResponse([
                'conversation_id' => $conversation_id,
                'is_new' => true
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            errorResponse('チャットの作成に失敗しました: ' . $e->getMessage());
        }
        break;
        
    case 'create':
        // 新規会話を作成
        $type = $input['type'] ?? 'group';
        $name = trim($input['name'] ?? '');
        $name_en = trim($input['name_en'] ?? '') ?: null;
        $name_zh = trim($input['name_zh'] ?? '') ?: null;
        $description = trim($input['description'] ?? '');
        $description_en = trim($input['description_en'] ?? '') ?: null;
        $description_zh = trim($input['description_zh'] ?? '') ?: null;
        $member_ids = $input['member_ids'] ?? [];
        $organization_id = isset($input['organization_id']) ? (int)$input['organization_id'] : null;
        
        // DMの場合
        if ($type === 'dm') {
            if (count($member_ids) !== 1) {
                errorResponse('DMには1人の相手が必要です');
            }
            
            $other_id = (int)$member_ids[0];
            
            // 既存のDMをチェック
            $stmt = $pdo->prepare("
                SELECT c.id FROM conversations c
                INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ? AND cm1.left_at IS NULL
                INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ? AND cm2.left_at IS NULL
                WHERE c.type = 'dm'
                LIMIT 1
            ");
            $stmt->execute([$user_id, $other_id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                successResponse(['conversation_id' => (int)$existing['id'], 'existing' => true]);
                exit;
            }
            
            $name = ''; // DMは名前なし
        } else {
            // グループ
            if (empty($name)) {
                errorResponse('グループ名が必要です');
            }
            
            // 組織紐付けの場合、本人確認が必要（既存グループを組織に追加する場合は不要）
            if ($organization_id) {
                $clone_from_conversation_id = isset($input['clone_from_conversation_id']) ? (int)$input['clone_from_conversation_id'] : null;
                $allow_via_clone = false;
                if ($clone_from_conversation_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT c.id, c.type, c.name, c.name_en, c.name_zh, cm.role
                        FROM conversations c
                        INNER JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ? AND cm.left_at IS NULL
                        WHERE c.id = ? AND c.type = 'group'
                    ");
                    $stmt->execute([$user_id, $clone_from_conversation_id]);
                    $source = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($source && $source['role'] === 'admin') {
                        $allow_via_clone = true;
                        $name = $source['name'] ?? $name;
                        $name_en = !empty(trim($source['name_en'] ?? '')) ? trim($source['name_en']) : $name_en;
                        $name_zh = !empty(trim($source['name_zh'] ?? '')) ? trim($source['name_zh']) : $name_zh;
                    }
                }
                if (!$allow_via_clone) {
                    $auth_level = $_SESSION['auth_level'] ?? 0;
                    if ($auth_level < AUTH_LEVEL_IDENTITY) {
                        errorResponse('組織ルームを作成するには本人確認が必要です', 403);
                    }
                }
                
                // 組織のメンバーか確認
                $stmt = $pdo->prepare("
                    SELECT role FROM organization_members 
                    WHERE organization_id = ? AND user_id = ? AND left_at IS NULL
                ");
                $stmt->execute([$organization_id, $user_id]);
                $orgMember = $stmt->fetch();
                
                if (!$orgMember || !in_array($orgMember['role'], ['owner', 'admin'])) {
                    errorResponse('組織の管理者権限が必要です', 403);
                }
            }
            
            // メンバー数チェック（50人まで）
            if (!$organization_id && count($member_ids) > 49) {
                errorResponse('グループは最大50人までです。それ以上は組織ルームをご利用ください。');
            }
        }
        
        // 会話を作成
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (type, name, name_en, name_zh, description, description_en, description_zh, organization_id, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$type, $name, $name_en, $name_zh, $description, $description_en, $description_zh, $organization_id, $user_id]);
            $conversation_id = $pdo->lastInsertId();
            
            // 自分を管理者として追加
            $pdo->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                VALUES (?, ?, 'admin', NOW())
            ")->execute([$conversation_id, $user_id]);
            
            // メンバーを追加
            foreach ($member_ids as $member_id) {
                $member_id = (int)$member_id;
                if ($member_id && $member_id != $user_id) {
                    $pdo->prepare("
                        INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                        VALUES (?, ?, 'member', NOW())
                    ")->execute([$conversation_id, $member_id]);
                }
            }
            
            $pdo->commit();
            
            successResponse([
                'conversation_id' => (int)$conversation_id,
                'existing' => false
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Conversation creation failed: ' . $e->getMessage());
            errorResponse('会話の作成に失敗しました');
        }
        break;
        
    case 'update_settings':
        // グループ設定を更新（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 管理者権限を確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $membership = $stmt->fetch();
        
        if (!$membership) {
            errorResponse('この会話のメンバーではありません', 403);
        }
        
        if ($membership['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        if ($membership['type'] !== 'group') {
            errorResponse('グループ会話のみ設定変更できます');
        }
        
        // 更新可能な設定
        $updates = [];
        $params = [];
        
        if (isset($input['allow_member_dm'])) {
            $updates[] = 'allow_member_dm = ?';
            $params[] = (int)$input['allow_member_dm'];
        }
        
        if (empty($updates)) {
            errorResponse('更新する設定がありません');
        }
        
        $params[] = $conversation_id;
        $sql = "UPDATE conversations SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        successResponse(['updated' => true]);
        break;
        
    case 'update':
        // 会話を更新（概要のみならメンバー誰でも可、名前・アイコン・公開設定は管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // メンバーか確認
        $stmt = $pdo->prepare("
            SELECT role FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member) {
            errorResponse('この会話のメンバーではありません', 403);
        }
        
        $updates = [];
        $params = [];
        
        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $params[] = trim($input['name']);
        }
        if (array_key_exists('name_en', $input)) {
            $updates[] = 'name_en = ?';
            $params[] = $input['name_en'] ? trim($input['name_en']) : null;
        }
        if (array_key_exists('name_zh', $input)) {
            $updates[] = 'name_zh = ?';
            $params[] = $input['name_zh'] ? trim($input['name_zh']) : null;
        }
        if (isset($input['description'])) {
            $updates[] = 'description = ?';
            $params[] = trim($input['description']);
        }
        if (array_key_exists('description_en', $input)) {
            $updates[] = 'description_en = ?';
            $params[] = $input['description_en'] ? trim($input['description_en']) : null;
        }
        if (array_key_exists('description_zh', $input)) {
            $updates[] = 'description_zh = ?';
            $params[] = $input['description_zh'] ? trim($input['description_zh']) : null;
        }
        if (isset($input['icon_path'])) {
            $updates[] = 'icon_path = ?';
            $params[] = $input['icon_path'];
        }
        if (isset($input['is_public'])) {
            $updates[] = 'is_public = ?';
            $params[] = $input['is_public'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            errorResponse('更新する項目がありません');
        }
        
        // 名前・アイコン・公開設定の変更は管理者のみ。概要（description）のみの更新はメンバー誰でも可
        $requires_admin = isset($input['name']) || array_key_exists('name_en', $input) || array_key_exists('name_zh', $input) || isset($input['icon_path']) || isset($input['is_public']);
        if ($requires_admin && $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        $updates[] = 'updated_at = NOW()';
        $params[] = $conversation_id;
        
        try {
            $sql = "UPDATE conversations SET " . implode(', ', $updates) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);
            
            successResponse([], 'グループを更新しました');
        } catch (PDOException $e) {
            // カラムが存在しない場合、name_en, name_zh を除いて再試行
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                $updates = [];
                $params = [];
                
                if (isset($input['name'])) {
                    $updates[] = 'name = ?';
                    $params[] = trim($input['name']);
                }
                if (isset($input['description'])) {
                    $updates[] = 'description = ?';
                    $params[] = trim($input['description']);
                }
                
                if (!empty($updates)) {
                    $updates[] = 'updated_at = NOW()';
                    $params[] = $conversation_id;
                    
                    $sql = "UPDATE conversations SET " . implode(', ', $updates) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($params);
                    
                    successResponse([], 'グループを更新しました（多言語カラム未対応）');
                } else {
                    errorResponse('更新する項目がありません');
                }
            } else {
                error_log('Conversation update error: ' . $e->getMessage());
                errorResponse('グループの更新に失敗しました: ' . $e->getMessage());
            }
        }
        break;
    
    case 'get_invite_link':
        try {
            $conversation_id = (int)($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                errorResponse('グループIDが必要です');
            }
            $stmt = $pdo->prepare("
                SELECT cm.role, c.name
                FROM conversation_members cm
                JOIN conversations c ON cm.conversation_id = c.id
                WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $membership = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$membership) {
                errorResponse('このグループのメンバーではありません');
            }
            $inviteToken = null;
            $hasInviteToken = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'invite_token'");
                $hasInviteToken = ($chk && $chk->rowCount() > 0);
            } catch (PDOException $e) {}
            if ($hasInviteToken) {
                $stmt = $pdo->prepare("SELECT invite_token FROM conversations WHERE id = ?");
                $stmt->execute([$conversation_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $inviteToken = $row['invite_token'] ?? null;
            }
            if (!$inviteToken) {
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'invite_code'");
                    if ($chk && $chk->rowCount() > 0) {
                        $stmt = $pdo->prepare("SELECT invite_code FROM conversations WHERE id = ?");
                        $stmt->execute([$conversation_id]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $inviteToken = $row['invite_code'] ?? null;
                    }
                } catch (PDOException $e) {}
            }
            if (!$inviteToken) {
                $inviteToken = bin2hex(random_bytes(16));
                if ($hasInviteToken) {
                    $pdo->prepare("UPDATE conversations SET invite_token = ? WHERE id = ?")->execute([$inviteToken, $conversation_id]);
                } else {
                    try {
                        $chk = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'invite_code'");
                        if ($chk && $chk->rowCount() > 0) {
                            $pdo->prepare("UPDATE conversations SET invite_code = ? WHERE id = ?")->execute([$inviteToken, $conversation_id]);
                        }
                    } catch (PDOException $e) {}
                }
            }
            $baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $inviteLink = ($baseUrl !== '' ? $baseUrl . '/' : '') . 'join_group.php?token=' . $inviteToken;
            successResponse(['invite_link' => $inviteLink, 'invite_token' => $inviteToken, 'group_name' => $membership['name']]);
        } catch (PDOException $e) {
            error_log('get_invite_link: ' . $e->getMessage());
            errorResponse('招待リンクの取得に失敗しました', 500);
        }
        break;
        
    case 'add_member':
        // メンバーを追加
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $new_member_id = (int)($input['user_id'] ?? 0);
        
        if (!$conversation_id || !$new_member_id) {
            errorResponse('必要なパラメータがありません');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type, c.organization_id
            FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        $isDM = ($member['type'] === 'dm');
        
        // 組織紐付けグループの場合、追加するメンバーが組織に所属しているか確認
        if ($member['organization_id']) {
            $stmt = $pdo->prepare("
                SELECT 1 FROM organization_members 
                WHERE organization_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$member['organization_id'], $new_member_id]);
            if (!$stmt->fetch()) {
                errorResponse('この組織のメンバーのみ追加できます', 403);
            }
        } else {
            // メンバー数チェック（組織紐付けでなければ50人まで）
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM conversation_members
                WHERE conversation_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$conversation_id]);
            if ((int)$stmt->fetch()['count'] >= 50) {
                errorResponse('グループは最大50人までです');
            }
        }
        
        // DMの場合は新規グループを作成（既存DMは維持してプライバシーを保護）
        if ($isDM) {
            // 現在のDMメンバーのIDと名前を取得
            $stmt = $pdo->prepare("
                SELECT cm.user_id, u.display_name 
                FROM conversation_members cm
                INNER JOIN users u ON cm.user_id = u.id
                WHERE cm.conversation_id = ? AND cm.left_at IS NULL
            ");
            $stmt->execute([$conversation_id]);
            $currentMembers = $stmt->fetchAll();
            
            // 追加するユーザーの名前を取得
            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$new_member_id]);
            $newMember = $stmt->fetch();
            $newMemberName = $newMember ? $newMember['display_name'] : 'Unknown';
            
            // グループ名を設定（全メンバーの名前を組み合わせる）
            $allNames = array_merge(array_column($currentMembers, 'display_name'), [$newMemberName]);
            sort($allNames);
            $groupName = implode(', ', array_slice($allNames, 0, 3));
            if (count($allNames) > 3) {
                $groupName .= ' 他' . (count($allNames) - 3) . '人';
            }
            
            // 新規グループを作成
            $pdo->prepare("
                INSERT INTO conversations (type, name, created_by, created_at, updated_at)
                VALUES ('group', ?, ?, NOW(), NOW())
            ")->execute([$groupName, $user_id]);
            $newGroupId = (int)$pdo->lastInsertId();
            
            // 元のDMメンバーを管理者として追加
            $stmt = $pdo->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                VALUES (?, ?, 'admin', NOW())
            ");
            foreach ($currentMembers as $m) {
                $stmt->execute([$newGroupId, $m['user_id']]);
            }
            
            // 新メンバーを通常メンバーとして追加
            $pdo->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                VALUES (?, ?, 'member', NOW())
            ")->execute([$newGroupId, $new_member_id]);
            
            // 招待したユーザー（自分）の名前を取得
            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $inviter = $stmt->fetch();
            $inviterName = $inviter ? $inviter['display_name'] : 'Unknown';
            
            // システムメッセージを送信
            $systemMessage = $inviterName . ' さんが新しいグループを作成しました';
            $pdo->prepare("
                INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
                VALUES (?, ?, ?, 'system', NOW())
            ")->execute([$newGroupId, $user_id, $systemMessage]);
            
            $systemMessage2 = $inviterName . ' さんが ' . $newMemberName . ' さんを招待しました';
            $pdo->prepare("
                INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
                VALUES (?, ?, ?, 'system', NOW())
            ")->execute([$newGroupId, $user_id, $systemMessage2]);
            
            successResponse([
                'new_group_created' => true,
                'new_conversation_id' => $newGroupId
            ], '新しいグループを作成しました');
            break;
        }
        
        // 通常のグループへのメンバー追加
        $pdo->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
            VALUES (?, ?, 'member', NOW())
            ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW(), role = 'member'
        ")->execute([$conversation_id, $new_member_id]);
        
        // 追加したユーザーの名前を取得
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$new_member_id]);
        $newMember = $stmt->fetch();
        $newMemberName = $newMember ? $newMember['display_name'] : 'Unknown';
        
        // 招待したユーザー（自分）の名前を取得
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $inviter = $stmt->fetch();
        $inviterName = $inviter ? $inviter['display_name'] : 'Unknown';
        
        // システムメッセージを送信（誰が誰を招待したか）
        $systemMessage = $inviterName . ' さんが ' . $newMemberName . ' さんを招待しました';
        $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
            VALUES (?, ?, ?, 'system', NOW())
        ")->execute([$conversation_id, $user_id, $systemMessage]);
        
        successResponse([], 'メンバーを追加しました');
        break;
        
    case 'admin_join':
        // システム管理者が任意のグループに参加（検索から入室するため）
        $conversation_id = (int)($input['conversation_id'] ?? $input['conversationId'] ?? $_GET['conversation_id'] ?? 0);
        if (!$conversation_id) {
            errorResponse('グループIDが必要です');
        }
        $current_role = $_SESSION['role'] ?? null;
        if ($current_role === null || $current_role === '') {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_role = $row['role'] ?? 'user';
        }
        if (!in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin'])) {
            errorResponse('システム管理者のみ利用できます', 403);
        }
        $stmt = $pdo->prepare("SELECT id, type FROM conversations WHERE id = ?");
        $stmt->execute([$conversation_id]);
        $conv = $stmt->fetch();
        if (!$conv || !in_array($conv['type'], ['group', 'organization'])) {
            errorResponse('グループが見つかりません');
        }
        $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
        $stmt->execute([$conversation_id, $user_id]);
        if ($stmt->fetch()) {
            successResponse(['conversation_id' => $conversation_id], '既に参加しています');
            break;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL");
        $stmt->execute([$conversation_id]);
        if ((int)$stmt->fetch()['cnt'] >= 50) {
            errorResponse('グループは満員です');
        }
        $pdo->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
            VALUES (?, ?, 'member', NOW())
            ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW(), role = 'member'
        ")->execute([$conversation_id, $user_id]);
        successResponse(['conversation_id' => $conversation_id], 'グループに参加しました');
        break;
        
    case 'remove_member':
        // メンバーを削除
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $target_id = (int)($input['user_id'] ?? 0);
        
        if (!$conversation_id || !$target_id) {
            errorResponse('必要なパラメータがありません');
        }
        
        // 管理者か、自分自身の退出か確認
        $stmt = $pdo->prepare("
            SELECT role FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if ($target_id !== $user_id && (!$member || $member['role'] !== 'admin')) {
            errorResponse('管理者権限が必要です', 403);
        }
        
        $pdo->prepare("
            UPDATE conversation_members SET left_at = NOW()
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$conversation_id, $target_id]);
        
        successResponse([], $target_id === $user_id ? 'グループを退出しました' : 'メンバーを削除しました');
        break;
        
    case 'toggle_admin':
        // 管理者権限のトグル
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $target_id = (int)($input['user_id'] ?? 0);
        $make_admin = $input['make_admin'] ?? false;
        
        if (!$conversation_id || !$target_id) {
            errorResponse('必要なパラメータがありません');
        }
        
        // 自分が管理者か確認
        $stmt = $pdo->prepare("
            SELECT role FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        // 自分自身の権限は変更不可（最後の管理者になる可能性があるため）
        if ($target_id === $user_id) {
            errorResponse('自分自身の権限は変更できません');
        }
        
        // 対象メンバーの権限を変更
        $newRole = $make_admin ? 'admin' : 'member';
        $pdo->prepare("
            UPDATE conversation_members SET role = ?
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ")->execute([$newRole, $conversation_id, $target_id]);
        
        // 対象ユーザーの名前を取得
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $targetUser = $stmt->fetch();
        $targetName = $targetUser ? $targetUser['display_name'] : 'Unknown';
        
        // システムメッセージを送信
        $systemMessage = $make_admin 
            ? $targetName . ' さんが管理者に任命されました'
            : $targetName . ' さんの管理者権限が解除されました';
        $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
            VALUES (?, ?, ?, 'system', NOW())
        ")->execute([$conversation_id, $user_id, $systemMessage]);
        
        successResponse([], $make_admin ? '管理者に任命しました' : '管理者権限を解除しました');
        break;
        
    case 'pin':
        // ピン留め
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $is_pinned = $input['is_pinned'] ?? true;
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        $pdo->prepare("
            UPDATE conversation_members SET is_pinned = ?
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$is_pinned ? 1 : 0, $conversation_id, $user_id]);
        
        successResponse([]);
        break;
        
    case 'mute':
        // ミュート
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $is_muted = $input['is_muted'] ?? true;
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        $pdo->prepare("
            UPDATE conversation_members SET is_muted = ?
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$is_muted ? 1 : 0, $conversation_id, $user_id]);
        
        successResponse([]);
        break;
        
    case 'change_role':
        // メンバーの権限を変更（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $target_user_id = (int)($input['user_id'] ?? 0);
        $new_role = $input['role'] ?? 'member';
        
        if (!$conversation_id || !$target_user_id) {
            errorResponse('必要なパラメータがありません');
        }
        
        if (!in_array($new_role, ['admin', 'member'])) {
            errorResponse('無効な権限です');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type
            FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        if ($member['type'] === 'dm') {
            errorResponse('DMでは権限を変更できません');
        }
        
        // 対象が存在するか確認
        $stmt = $pdo->prepare("
            SELECT id FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $target_user_id]);
        if (!$stmt->fetch()) {
            errorResponse('メンバーが見つかりません');
        }
        
        // 権限を更新
        $pdo->prepare("
            UPDATE conversation_members SET role = ?
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$new_role, $conversation_id, $target_user_id]);
        
        $roleName = $new_role === 'admin' ? 'グループ管理者' : '一般メンバー';
        successResponse([], "{$roleName}に変更しました");
        break;
        
    case 'silence_member':
        // メンバーの発言を制限（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $target_user_id = (int)($input['user_id'] ?? 0);
        $is_silenced = $input['is_silenced'] ?? true;
        
        if (!$conversation_id || !$target_user_id) {
            errorResponse('必要なパラメータがありません');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT role FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        // 対象が存在するか確認
        $stmt = $pdo->prepare("
            SELECT role FROM conversation_members
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $target_user_id]);
        $target = $stmt->fetch();
        if (!$target) {
            errorResponse('メンバーが見つかりません');
        }
        
        // 管理者は発言制限できない
        if ($target['role'] === 'admin') {
            errorResponse('管理者を発言制限することはできません');
        }
        
        // is_silenced カラムを更新
        $pdo->prepare("
            UPDATE conversation_members SET is_silenced = ?
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$is_silenced ? 1 : 0, $conversation_id, $target_user_id]);
        
        successResponse([], $is_silenced ? '発言を制限しました' : '発言制限を解除しました');
        break;
        
    case 'generate_invite_link':
        // 招待リンクを生成（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type, c.invite_code
            FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        if ($member['type'] === 'dm') {
            errorResponse('DMには招待リンクを作成できません');
        }
        
        // 既存のコードがあればそのまま返す、なければ生成
        $invite_code = $member['invite_code'];
        if (!$invite_code) {
            $invite_code = bin2hex(random_bytes(16));
            $pdo->prepare("
                UPDATE conversations SET invite_code = ? WHERE id = ?
            ")->execute([$invite_code, $conversation_id]);
        }
        
        successResponse(['invite_code' => $invite_code]);
        break;
        
    case 'reset_invite_link':
        // 招待リンクを無効化して新規生成（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type
            FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        if ($member['type'] === 'dm') {
            errorResponse('DMには招待リンクを作成できません');
        }
        
        // 新しいコードを生成
        $invite_code = bin2hex(random_bytes(16));
        $pdo->prepare("
            UPDATE conversations SET invite_code = ? WHERE id = ?
        ")->execute([$invite_code, $conversation_id]);
        
        successResponse(['invite_code' => $invite_code], '招待リンクを更新しました');
        break;
        
    case 'update_icon':
        // グループアイコンを更新（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $icon_path = $input['icon_path'] ?? '';
        $icon_style = $input['icon_style'] ?? 'default';
        $icon_pos_x = (float)($input['icon_pos_x'] ?? 0);
        $icon_pos_y = (float)($input['icon_pos_y'] ?? 0);
        $icon_size = (int)($input['icon_size'] ?? 100);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT cm.role, c.type
            FROM conversation_members cm
            INNER JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member || $member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        // DMでもアイコン変更を許可（以前は禁止していた）
        // if ($member['type'] === 'dm') {
        //     errorResponse('DMのアイコンは変更できません');
        // }
        
        // 位置・サイズ・スタイルのみ更新する場合（icon_path が空）は既存の icon_path を維持する
        if ($icon_path === '' || $icon_path === null) {
            $cur = $pdo->prepare("SELECT icon_path FROM conversations WHERE id = ?");
            $cur->execute([$conversation_id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            $icon_path = $row['icon_path'] ?? '';
        }
        
        // icon_style, icon_pos_x, icon_pos_y, icon_sizeカラムが存在するか確認して更新
        try {
            $pdo->prepare("
                UPDATE conversations SET icon_path = ?, icon_style = ?, icon_pos_x = ?, icon_pos_y = ?, icon_size = ?, updated_at = NOW() WHERE id = ?
            ")->execute([$icon_path, $icon_style, $icon_pos_x, $icon_pos_y, $icon_size, $conversation_id]);
        } catch (PDOException $e) {
            error_log("Update icon (full) failed: " . $e->getMessage());
            // カラムがない場合は順番に試す
            try {
                $pdo->prepare("
                    UPDATE conversations SET icon_path = ?, icon_style = ?, updated_at = NOW() WHERE id = ?
                ")->execute([$icon_path, $icon_style, $conversation_id]);
            } catch (PDOException $e2) {
                error_log("Update icon (style) failed: " . $e2->getMessage());
                try {
                    $pdo->prepare("
                        UPDATE conversations SET icon_path = ?, updated_at = NOW() WHERE id = ?
                    ")->execute([$icon_path, $conversation_id]);
                } catch (PDOException $e3) {
                    error_log("Update icon (path only) failed: " . $e3->getMessage());
                    errorResponse('アイコンの更新に失敗しました: ' . $e3->getMessage());
                }
            }
        }
        
        successResponse([], 'グループアイコンを更新しました');
        break;
        
    case 'members':
    case 'get_members':
        try {
            $conversation_id = (int)($input['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
            if (!$conversation_id) {
                errorResponse('会話IDが必要です');
            }
            $stmt = $pdo->prepare("
                SELECT cm.role FROM conversation_members cm
                WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $myRole = $stmt->fetch();
            if (!$myRole) {
                errorResponse('アクセス権がありません', 403);
            }
            $hasSilenced = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'is_silenced'");
                $hasSilenced = ($chk && $chk->rowCount() > 0);
            } catch (Exception $e) {}
            $selectCols = "u.id, u.display_name, u.avatar_path, u.online_status, cm.role";
            if ($hasSilenced) {
                $selectCols .= ", cm.is_silenced";
            }
            $stmt = $pdo->prepare("
                SELECT {$selectCols}
                FROM conversation_members cm
                INNER JOIN users u ON cm.user_id = u.id
                WHERE cm.conversation_id = ? AND cm.left_at IS NULL
                ORDER BY cm.role = 'admin' DESC, u.display_name
            ");
            $stmt->execute([$conversation_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($members as &$m) {
                $m['id'] = (int)$m['id'];
                $m['is_silenced'] = $hasSilenced ? (int)($m['is_silenced'] ?? 0) : 0;
            }
            successResponse(['members' => $members, 'my_role' => $myRole['role']]);
        } catch (PDOException $e) {
            error_log('get_members: ' . $e->getMessage());
            errorResponse('メンバーの取得に失敗しました', 500);
        }
        break;
        
    case 'delete':
        // グループを削除（管理者のみ）
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 管理者か確認
        $stmt = $pdo->prepare("
            SELECT c.type, cm.role 
            FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE c.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $member = $stmt->fetch();
        
        if (!$member) {
            errorResponse('会話が見つかりません', 404);
        }
        
        // 管理者権限チェック（DMも含めすべての会話で管理者のみ削除可能）
        if ($member['role'] !== 'admin') {
            errorResponse('管理者権限が必要です', 403);
        }
        
        $pdo->beginTransaction();
        
        try {
            // メッセージのメンションを削除
            $pdo->prepare("
                DELETE mm FROM message_mentions mm
                INNER JOIN messages m ON mm.message_id = m.id
                WHERE m.conversation_id = ?
            ")->execute([$conversation_id]);
            
            // メッセージのリアクションを削除
            $pdo->prepare("
                DELETE mr FROM message_reactions mr
                INNER JOIN messages m ON mr.message_id = m.id
                WHERE m.conversation_id = ?
            ")->execute([$conversation_id]);
            
            // メッセージを削除
            $pdo->prepare("DELETE FROM messages WHERE conversation_id = ?")->execute([$conversation_id]);
            
            // メンバーを削除
            $pdo->prepare("DELETE FROM conversation_members WHERE conversation_id = ?")->execute([$conversation_id]);
            
            // グループを削除
            $pdo->prepare("DELETE FROM conversations WHERE id = ?")->execute([$conversation_id]);
            
            $pdo->commit();
            
            successResponse([], 'グループを削除しました');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Group deletion failed: ' . $e->getMessage());
            errorResponse('グループの削除に失敗しました: ' . $e->getMessage());
        }
        break;
        
    default:
        errorResponse('不明なアクションです');
}
