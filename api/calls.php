<?php
/**
 * 通話API
 * 仕様書: 06_通話機能.md
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// 通話は電話認証を前提にしない（アプリ内通話のみ）
$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        // 通話を開始
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $call_type = $input['call_type'] ?? 'video';
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        if (!in_array($call_type, ['audio', 'video'])) {
            errorResponse('無効な通話タイプです');
        }
        
        // 会話メンバー確認
        $stmt = $pdo->prepare("
            SELECT c.*, cm.role
            FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE c.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            errorResponse('会話が見つかりません', 404);
        }
        
        // 未成年の通話制限チェック（組織ベース）
        $stmt = $pdo->prepare("SELECT is_minor FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user['is_minor']) {
            // 組織メンバーとして制限があるかチェック
            $stmt = $pdo->prepare("
                SELECT om.call_restriction
                FROM organization_members om
                WHERE om.user_id = ? AND om.role = 'restricted' AND om.left_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $restriction = $stmt->fetch();
            
            if ($restriction && $restriction['call_restriction'] !== 'allow') {
                // DMの場合は許可済み連絡先をチェック
                if ($conversation['type'] === 'dm') {
                    $stmt = $pdo->prepare("
                        SELECT cm.user_id 
                        FROM conversation_members cm
                        WHERE cm.conversation_id = ? AND cm.user_id != ? AND cm.left_at IS NULL
                        LIMIT 1
                    ");
                    $stmt->execute([$conversation_id, $user_id]);
                    $other = $stmt->fetch();
                    
                    if ($other) {
                        $stmt = $pdo->prepare("
                            SELECT ac.allow_call
                            FROM approved_contacts ac
                            INNER JOIN organization_members om ON ac.child_user_id = om.user_id OR ac.member_id = om.id
                            WHERE om.user_id = ? AND ac.approved_user_id = ?
                        ");
                        $stmt->execute([$user_id, $other['user_id']]);
                        $approval = $stmt->fetch();
                        
                        if (!$approval || !$approval['allow_call']) {
                            errorResponse('この相手との通話は許可されていません', 403);
                        }
                    }
                }
            }
        }
        
        // Jitsi用のRoom IDを生成
        $room_id = 'social9_' . $conversation_id . '_' . time() . '_' . bin2hex(random_bytes(4));
        
        // 通話レコードを作成
        $stmt = $pdo->prepare("
            INSERT INTO calls (conversation_id, initiator_id, room_id, call_type, status, created_at)
            VALUES (?, ?, ?, ?, 'ringing', NOW())
        ");
        $stmt->execute([$conversation_id, $user_id, $room_id, $call_type]);
        $call_id = $pdo->lastInsertId();
        
        // 参加者を追加（開始者）
        $pdo->prepare("
            INSERT INTO call_participants (call_id, user_id, status, joined_at)
            VALUES (?, ?, 'joined', NOW())
        ")->execute([$call_id, $user_id]);
        
        // 他のメンバーに通知
        $stmt = $pdo->prepare("
            SELECT user_id FROM conversation_members 
            WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL
        ");
        $stmt->execute([$conversation_id, $user_id]);
        $members = $stmt->fetchAll();
        
        $user_stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $caller = $user_stmt->fetch();
        
        foreach ($members as $member) {
            // 通知
            $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                VALUES (?, 'call_incoming', '着信', ?, 'call', ?)
            ")->execute([
                $member['user_id'],
                $caller['display_name'] . 'さんから' . ($call_type === 'video' ? 'ビデオ' : '音声') . '通話',
                $call_id
            ]);
            
            // 参加者リストに追加（招待状態）
            $pdo->prepare("
                INSERT INTO call_participants (call_id, user_id, status)
                VALUES (?, ?, 'invited')
            ")->execute([$call_id, $member['user_id']]);
        }
        
        // 相手端末で着信音・バイブを鳴らすため Web Push を送信
        $target_ids = array_column($members, 'user_id');
        if (!empty($target_ids)) {
            require_once __DIR__ . '/../includes/push_helper.php';
            triggerCallPushNotification(
                $pdo,
                $target_ids,
                (int)$call_id,
                $room_id,
                $conversation_id,
                $call_type,
                $caller['display_name'] ?? '通話'
            );
        }
        
        // Jitsi Meet URL（自前サーバー対応: config の JITSI_BASE_URL）
        $jitsi_base = rtrim(JITSI_BASE_URL, '/');
        $jitsi_url = $jitsi_base . '/' . $room_id;
        
        successResponse([
            'call_id' => (int)$call_id,
            'room_id' => $room_id,
            'join_url' => $jitsi_url
        ]);
        break;
        
    case 'join':
        // 通話に参加
        $call_id = (int)($input['call_id'] ?? 0);
        
        if (!$call_id) {
            errorResponse('通話IDが必要です');
        }
        
        // 通話を取得
        $stmt = $pdo->prepare("
            SELECT c.*, cp.status as participant_status
            FROM calls c
            LEFT JOIN call_participants cp ON c.id = cp.call_id AND cp.user_id = ?
            WHERE c.id = ? AND c.status IN ('ringing', 'active')
        ");
        $stmt->execute([$user_id, $call_id]);
        $call = $stmt->fetch();
        
        if (!$call) {
            errorResponse('通話が見つかりません', 404);
        }
        
        // 参加ステータスを更新
        $pdo->prepare("
            UPDATE call_participants SET status = 'joined', joined_at = NOW()
            WHERE call_id = ? AND user_id = ?
        ")->execute([$call_id, $user_id]);
        
        // 通話ステータスを active に
        if ($call['status'] === 'ringing') {
            $pdo->prepare("UPDATE calls SET status = 'active', started_at = NOW() WHERE id = ?")
                ->execute([$call_id]);
        }
        
        $jitsi_base = rtrim(JITSI_BASE_URL, '/');
        $jitsi_url = $jitsi_base . '/' . $call['room_id'];
        
        successResponse([
            'call_id' => (int)$call_id,
            'room_id' => $call['room_id'],
            'call_type' => $call['call_type'] ?? 'video',
            'join_url' => $jitsi_url
        ]);
        break;
        
    case 'leave':
        // 通話から退出
        $call_id = (int)($input['call_id'] ?? 0);
        
        if (!$call_id) {
            errorResponse('通話IDが必要です');
        }
        
        $pdo->prepare("
            UPDATE call_participants SET status = 'left', left_at = NOW()
            WHERE call_id = ? AND user_id = ?
        ")->execute([$call_id, $user_id]);
        
        // 全員が退出したら通話終了
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM call_participants 
            WHERE call_id = ? AND status = 'joined'
        ");
        $stmt->execute([$call_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            $pdo->prepare("
                UPDATE calls SET status = 'ended', ended_at = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
                WHERE id = ?
            ")->execute([$call_id]);
        }
        
        successResponse([]);
        break;
        
    case 'decline':
        // 通話を拒否
        $call_id = (int)($input['call_id'] ?? 0);
        
        if (!$call_id) {
            errorResponse('通話IDが必要です');
        }
        
        $pdo->prepare("
            UPDATE call_participants SET status = 'declined'
            WHERE call_id = ? AND user_id = ?
        ")->execute([$call_id, $user_id]);
        
        // 全員が拒否したらmissedに
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM call_participants 
            WHERE call_id = ? AND status IN ('invited', 'joined')
        ");
        $stmt->execute([$call_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            $pdo->prepare("UPDATE calls SET status = 'missed' WHERE id = ? AND status = 'ringing'")
                ->execute([$call_id]);
        }
        
        successResponse([]);
        break;
        
    case 'get_active':
        // 自分が参加者であるアクティブな通話のみ取得（着信UI用に自分の参加状態・発信者名を付与）
        $conversation_id = (int)($_GET['conversation_id'] ?? 0);
        
        $sql = "
            SELECT c.*,
                (SELECT COUNT(*) FROM call_participants WHERE call_id = c.id AND status = 'joined') as participant_count,
                cp.status as my_participant_status,
                u.display_name as initiator_name
            FROM calls c
            INNER JOIN call_participants cp ON cp.call_id = c.id AND cp.user_id = ?
            LEFT JOIN users u ON c.initiator_id = u.id
            WHERE c.status IN ('ringing', 'active')
        ";
        $params = [$user_id];
        
        if ($conversation_id) {
            $sql .= " AND c.conversation_id = ?";
            $params[] = $conversation_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $calls = $stmt->fetchAll();
        
        foreach ($calls as &$call) {
            $call['id'] = (int)$call['id'];
            $call['conversation_id'] = (int)$call['conversation_id'];
            $call['initiator_id'] = (int)$call['initiator_id'];
            $call['participant_count'] = (int)$call['participant_count'];
            $call['my_participant_status'] = $call['my_participant_status'] ?? 'invited';
            $call['initiator_name'] = $call['initiator_name'] ?? '';
            if (!empty($call['duration_seconds'])) {
                $call['duration_seconds'] = (int)$call['duration_seconds'];
            }
        }
        
        successResponse(['calls' => $calls]);
        break;
        
    case 'history':
        // 通話履歴
        $conversation_id = (int)($_GET['conversation_id'] ?? 0);
        $limit = min((int)($_GET['limit'] ?? 20), 50);
        
        $sql = "
            SELECT 
                c.id,
                c.conversation_id,
                c.call_type,
                c.status,
                c.started_at,
                c.ended_at,
                c.duration_seconds,
                u.display_name as initiator_name,
                (SELECT COUNT(*) FROM call_participants WHERE call_id = c.id AND status = 'joined') as participant_count
            FROM calls c
            INNER JOIN users u ON c.initiator_id = u.id
            INNER JOIN conversation_members cm ON c.conversation_id = cm.conversation_id
            WHERE cm.user_id = ? AND cm.left_at IS NULL
        ";
        $params = [$user_id];
        
        if ($conversation_id) {
            $sql .= " AND c.conversation_id = ?";
            $params[] = $conversation_id;
        }
        
        $sql .= " ORDER BY c.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        
        // 数値型をキャスト
        foreach ($history as &$h) {
            $h['id'] = (int)$h['id'];
            $h['conversation_id'] = (int)$h['conversation_id'];
            $h['participant_count'] = (int)$h['participant_count'];
            if ($h['duration_seconds']) {
                $h['duration_seconds'] = (int)$h['duration_seconds'];
            }
        }
        
        successResponse(['history' => $history]);
        break;
        
    default:
        errorResponse('不明なアクションです');
}








