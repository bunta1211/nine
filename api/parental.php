<?php
/**
 * 保護者機能 API
 * 保護者リンク、制限設定、承認フローを管理
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Mailer.php';

header('Content-Type: application/json; charset=utf-8');

// ログインチェック（一部アクションを除く）
$publicActions = ['approve_link', 'reject_link', 'approve_request', 'reject_request'];
$action = $_GET['action'] ?? $_POST['action'] ?? json_decode(file_get_contents('php://input'), true)['action'] ?? '';

if (!in_array($action, $publicActions)) {
    requireLogin();
}

$pdo = getDB();
$user_id = $_SESSION['user_id'] ?? null;
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// テーブルの存在確認・作成
ensureParentalTables($pdo);

try {
    switch ($action) {
        // ========== 子側の操作 ==========
        
        case 'request_parent_link':
            // 保護者リンク申請（子→保護者）
            requestParentLink($pdo, $user_id, $input);
            break;
            
        case 'get_my_parent':
            // 自分の保護者情報を取得（子側）
            getMyParent($pdo, $user_id);
            break;
            
        case 'remove_parent_link':
            // 保護者リンク解除申請（子側）
            removeParentLink($pdo, $user_id, $input);
            break;
            
        case 'get_my_restrictions':
            // 自分に適用されている制限を取得（子側）
            getMyRestrictions($pdo, $user_id);
            break;
            
        // ========== 保護者側の操作 ==========
        
        case 'approve_link':
            // リンク承認（メールからのトークン認証）
            approveLinkByToken($pdo, $input);
            break;
            
        case 'reject_link':
            // リンク拒否（メールからのトークン認証）
            rejectLinkByToken($pdo, $input);
            break;
            
        case 'get_my_children':
            // 管理している子の一覧を取得（保護者側）
            getMyChildren($pdo, $user_id);
            break;
            
        case 'get_child_restrictions':
            // 特定の子の制限設定を取得（保護者側）
            getChildRestrictions($pdo, $user_id, $input);
            break;
            
        case 'update_restrictions':
            // 子の制限設定を更新（保護者側）
            updateRestrictions($pdo, $user_id, $input);
            break;
            
        case 'get_child_usage':
            // 子の利用状況を取得（保護者側）
            getChildUsage($pdo, $user_id, $input);
            break;
            
        case 'revoke_link':
            // 子とのリンクを解除（保護者側）
            revokeLink($pdo, $user_id, $input);
            break;
            
        // ========== 承認リクエスト ==========
        
        case 'get_pending_requests':
            // 承認待ちリクエスト一覧（保護者側）
            getPendingRequests($pdo, $user_id);
            break;
            
        case 'approve_request':
            // リクエスト承認
            approveRequest($pdo, $input);
            break;
            
        case 'reject_request':
            // リクエスト拒否
            rejectRequest($pdo, $input);
            break;
            
        case 'submit_approval_request':
            // 承認リクエスト送信（子側）
            submitApprovalRequest($pdo, $user_id, $input);
            break;
            
        // ========== 利用時間記録 ==========
        
        case 'log_activity':
            // アクティビティログ記録
            logActivity($pdo, $user_id);
            break;
            
        case 'check_usage_limit':
            // 利用制限チェック
            checkUsageLimit($pdo, $user_id);
            break;
            
        default:
            jsonError('無効なアクションです');
    }
} catch (Exception $e) {
    error_log("Parental API Error: " . $e->getMessage());
    jsonError('エラーが発生しました');
}

// ========================================
// 子側の関数
// ========================================

/**
 * 保護者リンク申請
 */
function requestParentLink($pdo, $user_id, $input) {
    $parent_email = trim($input['parent_email'] ?? '');
    
    if (empty($parent_email) || !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
        jsonError('有効なメールアドレスを入力してください');
    }
    
    // 自分のメールと同じでないかチェック
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $myEmail = $stmt->fetchColumn();
    
    if (strtolower($parent_email) === strtolower($myEmail)) {
        jsonError('自分のメールアドレスは指定できません');
    }
    
    // 既に保護者リンクがあるかチェック
    $stmt = $pdo->prepare("
        SELECT id FROM parent_child_links 
        WHERE child_user_id = ? AND status IN ('pending', 'approved')
    ");
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        jsonError('既に保護者リンクが設定されています');
    }
    
    // 保護者がユーザーとして存在するかチェック
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$parent_email]);
    $parent = $stmt->fetch();
    
    $parent_user_id = $parent ? $parent['id'] : null;
    
    // トークン生成
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    if ($parent_user_id) {
        // 保護者がユーザーとして存在する場合
        $stmt = $pdo->prepare("
            INSERT INTO parent_child_links 
                (parent_user_id, child_user_id, status, requested_by, request_token, token_expires_at)
            VALUES (?, ?, 'pending', 'child', ?, ?)
        ");
        $stmt->execute([$parent_user_id, $user_id, $token, $expiresAt]);
    } else {
        // 保護者が未登録の場合、メールアドレスのみで仮登録
        // 後で保護者が登録したときに紐付く
        // まず仮の保護者ユーザーを作成
        $stmt = $pdo->prepare("
            INSERT INTO users (email, display_name, status, created_at)
            VALUES (?, '保護者（未登録）', 'pending', NOW())
        ");
        $stmt->execute([$parent_email]);
        $parent_user_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("
            INSERT INTO parent_child_links 
                (parent_user_id, child_user_id, status, requested_by, request_token, token_expires_at)
            VALUES (?, ?, 'pending', 'child', ?, ?)
        ");
        $stmt->execute([$parent_user_id, $user_id, $token, $expiresAt]);
    }
    
    // 子の情報を取得
    $stmt = $pdo->prepare("SELECT display_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $child = $stmt->fetch();
    
    // メール送信
    $mailer = new Mailer();
    $approveUrl = getBaseUrl() . '/api/parental.php?action=approve_link&token=' . $token;
    $rejectUrl = getBaseUrl() . '/api/parental.php?action=reject_link&token=' . $token;
    
    $subject = '【Social9】保護者リンク申請';
    $body = <<<EOT
{$child['display_name']}さんから保護者リンクの申請がありました。

■ 申請者情報
表示名: {$child['display_name']}
メール: {$child['email']}

■ 承認する場合
以下のリンクをクリックしてください：
{$approveUrl}

■ 拒否する場合
以下のリンクをクリックしてください：
{$rejectUrl}

※このリンクは7日間有効です。

承認すると、お子様のSocial9アカウントに対して以下の管理機能が使えるようになります：
- 利用時間の確認・制限
- 検索範囲の制限
- DM・グループ参加の承認制

--
Social9 保護者機能
EOT;
    
    $sent = $mailer->send($parent_email, $subject, $body);
    
    jsonSuccess([
        'message' => '保護者にリンク申請メールを送信しました',
        'parent_email' => $parent_email,
        'expires_at' => $expiresAt
    ]);
}

/**
 * 自分の保護者情報を取得
 */
function getMyParent($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            pcl.id as link_id,
            pcl.status,
            pcl.approved_at,
            pcl.created_at as requested_at,
            u.id as parent_id,
            u.display_name as parent_name,
            u.email as parent_email,
            pr.daily_usage_limit_minutes,
            pr.usage_start_time,
            pr.usage_end_time,
            pr.search_restricted,
            pr.dm_restricted,
            pr.group_join_restricted,
            pr.call_restricted
        FROM parent_child_links pcl
        JOIN users u ON pcl.parent_user_id = u.id
        LEFT JOIN parental_restrictions pr ON pr.child_user_id = pcl.child_user_id AND pr.is_active = 1
        WHERE pcl.child_user_id = ? AND pcl.status IN ('pending', 'approved')
        ORDER BY pcl.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parent) {
        // 数値型にキャスト
        $parent['parent_id'] = (int)$parent['parent_id'];
        $parent['daily_usage_limit_minutes'] = $parent['daily_usage_limit_minutes'] ? (int)$parent['daily_usage_limit_minutes'] : null;
        $parent['search_restricted'] = (int)($parent['search_restricted'] ?? 0);
        $parent['dm_restricted'] = (int)($parent['dm_restricted'] ?? 0);
        $parent['group_join_restricted'] = (int)($parent['group_join_restricted'] ?? 0);
        $parent['call_restricted'] = (int)($parent['call_restricted'] ?? 0);
    }
    
    jsonSuccess(['parent' => $parent]);
}

/**
 * 自分に適用されている制限を取得
 */
function getMyRestrictions($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM parental_restrictions 
        WHERE child_user_id = ? AND is_active = 1
    ");
    $stmt->execute([$user_id]);
    $restrictions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($restrictions) {
        // 数値型にキャスト
        $restrictions['daily_usage_limit_minutes'] = $restrictions['daily_usage_limit_minutes'] ? (int)$restrictions['daily_usage_limit_minutes'] : null;
        $restrictions['search_restricted'] = (int)$restrictions['search_restricted'];
        $restrictions['dm_restricted'] = (int)$restrictions['dm_restricted'];
        $restrictions['group_join_restricted'] = (int)$restrictions['group_join_restricted'];
        $restrictions['call_restricted'] = (int)$restrictions['call_restricted'];
        $restrictions['file_upload_restricted'] = (int)$restrictions['file_upload_restricted'];
        
        // JSON decode
        if ($restrictions['allowed_days']) {
            $restrictions['allowed_days'] = json_decode($restrictions['allowed_days'], true);
        }
    }
    
    // 今日の利用時間も取得
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT total_minutes FROM usage_time_logs WHERE user_id = ? AND log_date = ?");
    $stmt->execute([$user_id, $today]);
    $todayUsage = (int)$stmt->fetchColumn();
    
    jsonSuccess([
        'restrictions' => $restrictions,
        'today_usage_minutes' => $todayUsage,
        'has_restrictions' => (bool)$restrictions
    ]);
}

// ========================================
// 保護者側の関数
// ========================================

/**
 * リンク承認（トークン認証）
 */
function approveLinkByToken($pdo, $input) {
    $token = trim($input['token'] ?? $_GET['token'] ?? '');
    
    if (empty($token)) {
        redirectWithMessage('index.php', 'error', '無効なリンクです');
    }
    
    $stmt = $pdo->prepare("
        SELECT pcl.*, u.display_name as child_name
        FROM parent_child_links pcl
        JOIN users u ON pcl.child_user_id = u.id
        WHERE pcl.request_token = ? AND pcl.status = 'pending' AND pcl.token_expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch();
    
    if (!$link) {
        redirectWithMessage('index.php', 'error', 'リンクが無効または期限切れです');
    }
    
    // 承認処理
    $pdo->prepare("
        UPDATE parent_child_links 
        SET status = 'approved', approved_at = NOW(), request_token = NULL, token_expires_at = NULL
        WHERE id = ?
    ")->execute([$link['id']]);
    
    // デフォルトの制限設定を作成
    $pdo->prepare("
        INSERT INTO parental_restrictions (child_user_id, parent_user_id, is_active)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE parent_user_id = VALUES(parent_user_id), is_active = 1
    ")->execute([$link['child_user_id'], $link['parent_user_id']]);
    
    redirectWithMessage('settings.php?section=parental', 'success', 
        $link['child_name'] . 'さんとの保護者リンクを承認しました。設定画面から制限を設定できます。');
}

/**
 * リンク拒否（トークン認証）
 */
function rejectLinkByToken($pdo, $input) {
    $token = trim($input['token'] ?? $_GET['token'] ?? '');
    
    if (empty($token)) {
        redirectWithMessage('index.php', 'error', '無効なリンクです');
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM parent_child_links 
        WHERE request_token = ? AND status = 'pending'
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch();
    
    if (!$link) {
        redirectWithMessage('index.php', 'error', 'リンクが無効です');
    }
    
    $pdo->prepare("
        UPDATE parent_child_links 
        SET status = 'rejected', request_token = NULL, token_expires_at = NULL
        WHERE id = ?
    ")->execute([$link['id']]);
    
    redirectWithMessage('index.php', 'info', '保護者リンク申請を拒否しました');
}

/**
 * 管理している子の一覧を取得
 */
function getMyChildren($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            pcl.id as link_id,
            pcl.status,
            pcl.approved_at,
            pcl.created_at as linked_at,
            u.id as child_id,
            u.display_name as child_name,
            u.email as child_email,
            u.last_seen,
            u.online_status,
            pr.daily_usage_limit_minutes,
            pr.search_restricted,
            pr.dm_restricted,
            pr.is_active as restrictions_active,
            (SELECT total_minutes FROM usage_time_logs WHERE user_id = u.id AND log_date = CURDATE()) as today_usage
        FROM parent_child_links pcl
        JOIN users u ON pcl.child_user_id = u.id
        LEFT JOIN parental_restrictions pr ON pr.child_user_id = u.id
        WHERE pcl.parent_user_id = ? AND pcl.status = 'approved'
        ORDER BY pcl.approved_at DESC
    ");
    $stmt->execute([$user_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 数値型にキャスト
    foreach ($children as &$child) {
        $child['child_id'] = (int)$child['child_id'];
        $child['today_usage'] = (int)($child['today_usage'] ?? 0);
        $child['daily_usage_limit_minutes'] = $child['daily_usage_limit_minutes'] ? (int)$child['daily_usage_limit_minutes'] : null;
        $child['search_restricted'] = (int)($child['search_restricted'] ?? 0);
        $child['dm_restricted'] = (int)($child['dm_restricted'] ?? 0);
        $child['restrictions_active'] = (int)($child['restrictions_active'] ?? 0);
    }
    
    // 承認待ちの申請も取得
    $stmt = $pdo->prepare("
        SELECT 
            pcl.id as link_id,
            pcl.created_at as requested_at,
            pcl.token_expires_at,
            u.id as child_id,
            u.display_name as child_name,
            u.email as child_email
        FROM parent_child_links pcl
        JOIN users u ON pcl.child_user_id = u.id
        WHERE pcl.parent_user_id = ? AND pcl.status = 'pending' AND pcl.token_expires_at > NOW()
    ");
    $stmt->execute([$user_id]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonSuccess([
        'children' => $children,
        'pending_requests' => $pending
    ]);
}

/**
 * 特定の子の制限設定を取得
 */
function getChildRestrictions($pdo, $user_id, $input) {
    $child_user_id = (int)($input['child_user_id'] ?? $_GET['child_user_id'] ?? 0);
    
    if (!$child_user_id) {
        jsonError('子のユーザーIDが必要です');
    }
    
    // 権限チェック
    $stmt = $pdo->prepare("
        SELECT id FROM parent_child_links 
        WHERE parent_user_id = ? AND child_user_id = ? AND status = 'approved'
    ");
    $stmt->execute([$user_id, $child_user_id]);
    if (!$stmt->fetch()) {
        jsonError('この子の制限を確認する権限がありません');
    }
    
    // 制限設定を取得
    $stmt = $pdo->prepare("SELECT * FROM parental_restrictions WHERE child_user_id = ?");
    $stmt->execute([$child_user_id]);
    $restrictions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($restrictions) {
        // 数値型にキャスト
        $restrictions['daily_usage_limit_minutes'] = $restrictions['daily_usage_limit_minutes'] ? (int)$restrictions['daily_usage_limit_minutes'] : null;
        $restrictions['search_restricted'] = (int)$restrictions['search_restricted'];
        $restrictions['dm_restricted'] = (int)$restrictions['dm_restricted'];
        $restrictions['group_join_restricted'] = (int)$restrictions['group_join_restricted'];
        $restrictions['call_restricted'] = (int)$restrictions['call_restricted'];
        $restrictions['file_upload_restricted'] = (int)$restrictions['file_upload_restricted'];
        $restrictions['is_active'] = (int)$restrictions['is_active'];
        
        if ($restrictions['allowed_days']) {
            $restrictions['allowed_days'] = json_decode($restrictions['allowed_days'], true);
        }
    }
    
    jsonSuccess(['restrictions' => $restrictions]);
}

/**
 * 子の制限設定を更新
 */
function updateRestrictions($pdo, $user_id, $input) {
    $child_user_id = (int)($input['child_user_id'] ?? 0);
    
    if (!$child_user_id) {
        jsonError('子のユーザーIDが必要です');
    }
    
    // 権限チェック
    $stmt = $pdo->prepare("
        SELECT id FROM parent_child_links 
        WHERE parent_user_id = ? AND child_user_id = ? AND status = 'approved'
    ");
    $stmt->execute([$user_id, $child_user_id]);
    if (!$stmt->fetch()) {
        jsonError('この子の制限を設定する権限がありません');
    }
    
    // 制限設定を更新
    $daily_limit = isset($input['daily_usage_limit_minutes']) ? (int)$input['daily_usage_limit_minutes'] : null;
    $start_time = $input['usage_start_time'] ?? null;
    $end_time = $input['usage_end_time'] ?? null;
    $allowed_days = isset($input['allowed_days']) ? json_encode($input['allowed_days']) : null;
    $search_restricted = isset($input['search_restricted']) ? ((int)$input['search_restricted'] ? 1 : 0) : 0;
    $dm_restricted = isset($input['dm_restricted']) ? ((int)$input['dm_restricted'] ? 1 : 0) : 0;
    $group_join_restricted = isset($input['group_join_restricted']) ? ((int)$input['group_join_restricted'] ? 1 : 0) : 0;
    $call_restricted = isset($input['call_restricted']) ? ((int)$input['call_restricted'] ? 1 : 0) : 0;
    $file_upload_restricted = isset($input['file_upload_restricted']) ? ((int)$input['file_upload_restricted'] ? 1 : 0) : 0;
    $notify_dm = isset($input['notify_parent_on_dm']) ? ((int)$input['notify_parent_on_dm'] ? 1 : 0) : 0;
    $notify_group = isset($input['notify_parent_on_group_join']) ? ((int)$input['notify_parent_on_group_join'] ? 1 : 0) : 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO parental_restrictions (
            child_user_id, parent_user_id, 
            daily_usage_limit_minutes, usage_start_time, usage_end_time, allowed_days,
            search_restricted, dm_restricted, group_join_restricted, call_restricted, file_upload_restricted,
            notify_parent_on_dm, notify_parent_on_group_join, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            parent_user_id = VALUES(parent_user_id),
            daily_usage_limit_minutes = VALUES(daily_usage_limit_minutes),
            usage_start_time = VALUES(usage_start_time),
            usage_end_time = VALUES(usage_end_time),
            allowed_days = VALUES(allowed_days),
            search_restricted = VALUES(search_restricted),
            dm_restricted = VALUES(dm_restricted),
            group_join_restricted = VALUES(group_join_restricted),
            call_restricted = VALUES(call_restricted),
            file_upload_restricted = VALUES(file_upload_restricted),
            notify_parent_on_dm = VALUES(notify_parent_on_dm),
            notify_parent_on_group_join = VALUES(notify_parent_on_group_join),
            is_active = 1,
            updated_at = NOW()
    ");
    $stmt->execute([
        $child_user_id, $user_id,
        $daily_limit, $start_time, $end_time, $allowed_days,
        $search_restricted, $dm_restricted, $group_join_restricted, $call_restricted, $file_upload_restricted,
        $notify_dm, $notify_group
    ]);
    
    jsonSuccess(['message' => '制限設定を更新しました']);
}

/**
 * 子の利用状況を取得
 */
function getChildUsage($pdo, $user_id, $input) {
    $child_user_id = (int)($input['child_user_id'] ?? $_GET['child_user_id'] ?? 0);
    $days = (int)($input['days'] ?? $_GET['days'] ?? 7);
    
    if (!$child_user_id) {
        jsonError('子のユーザーIDが必要です');
    }
    
    // 権限チェック
    $stmt = $pdo->prepare("
        SELECT id FROM parent_child_links 
        WHERE parent_user_id = ? AND child_user_id = ? AND status = 'approved'
    ");
    $stmt->execute([$user_id, $child_user_id]);
    if (!$stmt->fetch()) {
        jsonError('この子の利用状況を確認する権限がありません');
    }
    
    // 利用履歴を取得
    $stmt = $pdo->prepare("
        SELECT log_date, total_minutes, session_count, last_activity_at
        FROM usage_time_logs
        WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ORDER BY log_date DESC
    ");
    $stmt->execute([$child_user_id, $days]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 合計・平均を計算
    $totalMinutes = 0;
    foreach ($logs as $log) {
        $totalMinutes += (int)$log['total_minutes'];
    }
    $avgMinutes = count($logs) > 0 ? round($totalMinutes / count($logs)) : 0;
    
    jsonSuccess([
        'logs' => $logs,
        'total_minutes' => $totalMinutes,
        'average_minutes' => $avgMinutes,
        'days' => $days
    ]);
}

/**
 * 利用時間をログ記録
 */
function logActivity($pdo, $user_id) {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        INSERT INTO usage_time_logs (user_id, log_date, total_minutes, last_activity_at, session_count)
        VALUES (?, ?, 1, NOW(), 1)
        ON DUPLICATE KEY UPDATE
            total_minutes = total_minutes + 1,
            last_activity_at = NOW(),
            session_count = session_count + 1
    ");
    $stmt->execute([$user_id, $today]);
    
    jsonSuccess(['logged' => true]);
}

/**
 * 利用制限をチェック
 */
function checkUsageLimit($pdo, $user_id) {
    // 制限設定を取得
    $stmt = $pdo->prepare("
        SELECT * FROM parental_restrictions 
        WHERE child_user_id = ? AND is_active = 1
    ");
    $stmt->execute([$user_id]);
    $restrictions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$restrictions) {
        jsonSuccess(['allowed' => true, 'reason' => null]);
        return;
    }
    
    $now = new DateTime();
    $blocked = false;
    $reason = null;
    
    // 時間帯チェック
    if ($restrictions['usage_start_time'] && $restrictions['usage_end_time']) {
        $startTime = DateTime::createFromFormat('H:i:s', $restrictions['usage_start_time']);
        $endTime = DateTime::createFromFormat('H:i:s', $restrictions['usage_end_time']);
        $currentTime = DateTime::createFromFormat('H:i:s', $now->format('H:i:s'));
        
        if ($currentTime < $startTime || $currentTime > $endTime) {
            $blocked = true;
            $reason = '利用可能時間外です（' . substr($restrictions['usage_start_time'], 0, 5) . '〜' . substr($restrictions['usage_end_time'], 0, 5) . '）';
        }
    }
    
    // 曜日チェック
    if (!$blocked && $restrictions['allowed_days']) {
        $allowedDays = json_decode($restrictions['allowed_days'], true);
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $today = (int)$now->format('w');
        
        $allowed = false;
        foreach ($allowedDays as $day => $isAllowed) {
            if ($isAllowed && isset($dayMap[$day]) && $dayMap[$day] === $today) {
                $allowed = true;
                break;
            }
        }
        
        if (!$allowed) {
            $blocked = true;
            $reason = '今日は利用できない曜日です';
        }
    }
    
    // 利用時間チェック
    if (!$blocked && $restrictions['daily_usage_limit_minutes']) {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT total_minutes FROM usage_time_logs WHERE user_id = ? AND log_date = ?");
        $stmt->execute([$user_id, $today]);
        $usedMinutes = (int)$stmt->fetchColumn();
        
        if ($usedMinutes >= $restrictions['daily_usage_limit_minutes']) {
            $blocked = true;
            $reason = '本日の利用時間（' . $restrictions['daily_usage_limit_minutes'] . '分）を超えました';
        }
    }
    
    jsonSuccess([
        'allowed' => !$blocked,
        'reason' => $reason,
        'restrictions' => [
            'daily_limit' => $restrictions['daily_usage_limit_minutes'],
            'start_time' => $restrictions['usage_start_time'],
            'end_time' => $restrictions['usage_end_time']
        ]
    ]);
}

// ========================================
// ヘルパー関数
// ========================================

function ensureParentalTables($pdo) {
    // parent_child_links テーブル
    try {
        $pdo->query("SELECT 1 FROM parent_child_links LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS parent_child_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_user_id INT UNSIGNED NOT NULL,
                child_user_id INT UNSIGNED NOT NULL,
                status ENUM('pending', 'approved', 'rejected', 'revoked') DEFAULT 'pending',
                requested_by ENUM('parent', 'child') DEFAULT 'child',
                request_token VARCHAR(64) NULL,
                token_expires_at DATETIME NULL,
                approved_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_parent_child (parent_user_id, child_user_id),
                INDEX idx_child (child_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // parental_restrictions テーブル
    try {
        $pdo->query("SELECT 1 FROM parental_restrictions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS parental_restrictions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_user_id INT UNSIGNED NOT NULL UNIQUE,
                parent_user_id INT UNSIGNED NOT NULL,
                daily_usage_limit_minutes INT NULL,
                usage_start_time TIME NULL,
                usage_end_time TIME NULL,
                allowed_days JSON NULL,
                search_restricted TINYINT(1) DEFAULT 0,
                dm_restricted TINYINT(1) DEFAULT 0,
                group_join_restricted TINYINT(1) DEFAULT 0,
                call_restricted TINYINT(1) DEFAULT 0,
                file_upload_restricted TINYINT(1) DEFAULT 0,
                notify_parent_on_dm TINYINT(1) DEFAULT 0,
                notify_parent_on_group_join TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // usage_time_logs テーブル
    try {
        $pdo->query("SELECT 1 FROM usage_time_logs LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usage_time_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                log_date DATE NOT NULL,
                total_minutes INT DEFAULT 0,
                last_activity_at DATETIME NULL,
                session_count INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_date (user_id, log_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}

function redirectWithMessage($url, $type, $message) {
    $baseUrl = getBaseUrl();
    $fullUrl = strpos($url, 'http') === 0 ? $url : $baseUrl . '/../' . $url;
    $separator = strpos($fullUrl, '?') !== false ? '&' : '?';
    header('Location: ' . $fullUrl . $separator . 'msg_type=' . $type . '&msg=' . urlencode($message));
    exit;
}

function jsonSuccess($data = []) {
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
