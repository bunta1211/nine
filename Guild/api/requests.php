<?php
/**
 * Guild 依頼API
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/common.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        requireApiLogin();
        createRequest();
        break;
    case 'update':
        requireApiLogin();
        updateRequest();
        break;
    case 'cancel':
        requireApiLogin();
        cancelRequest();
        break;
    case 'apply':
        requireApiLogin();
        applyToRequest();
        break;
    case 'withdraw':
        requireApiLogin();
        withdrawApplication();
        break;
    case 'accept_application':
        requireApiLogin();
        acceptApplication();
        break;
    case 'reject_application':
        requireApiLogin();
        rejectApplication();
        break;
    case 'start_work':
        requireApiLogin();
        startWork();
        break;
    case 'complete_work':
        requireApiLogin();
        completeWork();
        break;
    case 'approve_complete':
        requireApiLogin();
        approveComplete();
        break;
    case 'send_thanks':
        requireApiLogin();
        sendThanks();
        break;
    case 'list':
        requireApiLogin();
        listRequests();
        break;
    case 'detail':
        requireApiLogin();
        getRequestDetail();
        break;
    default:
        jsonError('Invalid action', 400);
}

/**
 * 依頼作成
 */
function createRequest() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    $fiscalYear = getCurrentFiscalYear();
    
    // 依頼停止期間チェック
    if (isFreezeZPeriod()) {
        jsonError('依頼停止期間中は新規依頼を作成できません');
    }
    
    // バリデーション
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $requestType = $input['request_type'] ?? 'public';
    $guildId = (int)($input['guild_id'] ?? 0);
    $earthAmount = (int)($input['earth_amount'] ?? 0);
    $deadline = $input['deadline'] ?? null;
    $distributionTiming = $input['distribution_timing'] ?? 'on_complete';
    $distributionDate = $input['distribution_date'] ?? null;
    $maxApplicants = (int)($input['max_applicants'] ?? 1);
    $requiredQualifications = trim($input['required_qualifications'] ?? '');
    $targetUserIds = $input['target_user_ids'] ?? [];
    $earthPerUser = $input['earth_per_user'] ?? [];
    
    if (empty($title)) {
        jsonError('タイトルを入力してください');
    }
    
    if ($earthAmount < 1) {
        jsonError('Earth額を1以上で設定してください');
    }
    
    // Earth源の決定
    $earthSource = REQUEST_TYPES[$requestType]['earth_source'] ?? 'guild';
    
    // 権限チェック
    if ($earthSource === 'guild') {
        if (!$guildId) {
            jsonError('ギルドを選択してください');
        }
        
        // ギルド予算チェック
        $stmt = $pdo->prepare("SELECT remaining_budget FROM guild_guilds WHERE id = ? AND fiscal_year = ?");
        $stmt->execute([$guildId, $fiscalYear]);
        $guild = $stmt->fetch();
        
        if (!$guild || $guild['remaining_budget'] < $earthAmount) {
            jsonError('ギルドの予算が不足しています');
        }
        
        // 発行権限チェック
        if (!canIssueRequest($userId, $guildId, $requestType)) {
            jsonError('この依頼を発行する権限がありません');
        }
        
        // 業務指令の権限チェック
        if ($requestType === 'order' && !canIssueOrder($userId, $guildId)) {
            jsonError('業務指令を発行する権限がありません');
        }
    } else {
        // 個人Earthチェック
        $balance = getUserEarthBalance($userId, $fiscalYear);
        if ($balance['current_balance'] < $earthAmount) {
            jsonError('Earthが不足しています');
        }
    }
    
    // 1万Earth以上は承認が必要
    $requiresApproval = $earthAmount >= LARGE_REQUEST_THRESHOLD;
    $status = $requiresApproval ? 'pending_approval' : 'open';
    
    try {
        $pdo->beginTransaction();
        
        // 依頼を作成
        $stmt = $pdo->prepare("
            INSERT INTO guild_requests (
                guild_id, requester_id, title, description, request_type,
                earth_amount, earth_source, distribution_timing, distribution_date,
                required_qualifications, max_applicants, deadline,
                status, requires_approval, fiscal_year, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $guildId ?: null,
            $userId,
            $title,
            $description,
            $requestType,
            $earthAmount,
            $earthSource,
            $distributionTiming,
            $distributionDate,
            $requiredQualifications,
            $maxApplicants,
            $deadline,
            $status,
            $requiresApproval ? 1 : 0,
            $fiscalYear,
        ]);
        
        $requestId = $pdo->lastInsertId();
        
        // 指名依頼・業務指令の場合、対象者を登録
        if (in_array($requestType, ['designated', 'order']) && !empty($targetUserIds)) {
            $stmt = $pdo->prepare("
                INSERT INTO guild_request_targets (request_id, user_id, earth_amount)
                VALUES (?, ?, ?)
            ");
            
            foreach ($targetUserIds as $i => $targetUserId) {
                $userEarth = $earthPerUser[$i] ?? ($earthAmount / count($targetUserIds));
                $stmt->execute([$requestId, $targetUserId, $userEarth]);
            }
        }
        
        // ギルド予算を減らす
        if ($earthSource === 'guild') {
            $stmt = $pdo->prepare("
                UPDATE guild_guilds SET remaining_budget = remaining_budget - ?
                WHERE id = ?
            ");
            $stmt->execute([$earthAmount, $guildId]);
        }
        
        // 活動ログ
        logActivity('create_request', 'request', $requestId, [
            'title' => $title,
            'type' => $requestType,
            'earth' => $earthAmount,
        ]);
        
        $pdo->commit();
        
        jsonSuccess(['request_id' => (int)$requestId], '依頼を作成しました');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Create request error: ' . $e->getMessage());
        jsonError('依頼の作成に失敗しました');
    }
}

/**
 * 立候補
 */
function applyToRequest() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    
    if (!$requestId) {
        jsonError('依頼IDが必要です');
    }
    
    // 依頼の状態チェック
    $stmt = $pdo->prepare("SELECT * FROM guild_requests WHERE id = ? AND status = 'open'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        jsonError('この依頼は募集を終了しています');
    }
    
    // 自分の依頼には立候補できない
    if ($request['requester_id'] == $userId) {
        jsonError('自分の依頼には立候補できません');
    }
    
    // 既に立候補済みかチェック
    $stmt = $pdo->prepare("
        SELECT id FROM guild_request_applications 
        WHERE request_id = ? AND user_id = ?
    ");
    $stmt->execute([$requestId, $userId]);
    if ($stmt->fetch()) {
        jsonError('既に立候補しています');
    }
    
    // 立候補を登録
    $stmt = $pdo->prepare("
        INSERT INTO guild_request_applications (request_id, user_id, comment, status, created_at)
        VALUES (?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$requestId, $userId, $comment]);
    
    // 通知を送信
    createNotification(
        $request['requester_id'],
        'new_application',
        '立候補がありました',
        '「' . $request['title'] . '」に立候補がありました',
        $requestId,
        'request'
    );
    
    logActivity('apply_request', 'request', $requestId);
    
    jsonSuccess([], '立候補しました');
}

/**
 * 立候補取り消し
 */
function withdrawApplication() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        UPDATE guild_request_applications 
        SET status = 'withdrawn'
        WHERE request_id = ? AND user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$requestId, $userId]);
    
    if ($stmt->rowCount() === 0) {
        jsonError('立候補が見つからないか、既に処理済みです');
    }
    
    logActivity('withdraw_application', 'request', $requestId);
    
    jsonSuccess([], '立候補を取り消しました');
}

/**
 * 立候補を承認（選定）
 */
function acceptApplication() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $applicationId = (int)($input['application_id'] ?? 0);
    $earthAmount = (int)($input['earth_amount'] ?? 0);
    
    // 立候補情報を取得
    $stmt = $pdo->prepare("
        SELECT a.*, r.requester_id, r.earth_amount as total_earth, r.title,
               r.distribution_timing, r.max_applicants
        FROM guild_request_applications a
        INNER JOIN guild_requests r ON a.request_id = r.id
        WHERE a.id = ? AND a.status = 'pending'
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        jsonError('立候補が見つかりません');
    }
    
    // 権限チェック
    if ($application['requester_id'] != $userId && !isGuildSystemAdmin()) {
        jsonError('この操作を行う権限がありません');
    }
    
    // Earth額が指定されていなければ均等割り
    if ($earthAmount <= 0) {
        $earthAmount = $application['total_earth'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // 立候補を承認
        $stmt = $pdo->prepare("
            UPDATE guild_request_applications 
            SET status = 'accepted', accepted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        
        // 担当者として登録
        $stmt = $pdo->prepare("
            INSERT INTO guild_request_assignees 
            (request_id, user_id, earth_amount, status, created_at)
            VALUES (?, ?, ?, 'assigned', NOW())
        ");
        $stmt->execute([$application['request_id'], $application['user_id'], $earthAmount]);
        
        // 依頼ステータスを更新
        $stmt = $pdo->prepare("
            UPDATE guild_requests SET status = 'in_progress'
            WHERE id = ? AND status = 'open'
        ");
        $stmt->execute([$application['request_id']]);
        
        // 受諾時分配の場合はEarthを即時付与
        if ($application['distribution_timing'] === 'on_accept') {
            distributeEarth($application['request_id'], $application['user_id'], $earthAmount);
        }
        
        // 通知を送信
        createNotification(
            $application['user_id'],
            'application_approved',
            '立候補が承認されました',
            '「' . $application['title'] . '」への立候補が承認されました',
            $application['request_id'],
            'request'
        );
        
        $pdo->commit();
        
        jsonSuccess([], '選定しました');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Accept application error: ' . $e->getMessage());
        jsonError('処理に失敗しました');
    }
}

/**
 * 作業開始
 */
function startWork() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        UPDATE guild_request_assignees 
        SET status = 'in_progress', started_at = NOW()
        WHERE request_id = ? AND user_id = ? AND status = 'assigned'
    ");
    $stmt->execute([$requestId, $userId]);
    
    if ($stmt->rowCount() === 0) {
        jsonError('担当情報が見つかりません');
    }
    
    logActivity('start_work', 'request', $requestId);
    
    jsonSuccess([], '作業を開始しました');
}

/**
 * 完了報告
 */
function completeWork() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    $report = trim($input['report'] ?? '');
    
    // 担当情報を更新
    $stmt = $pdo->prepare("
        UPDATE guild_request_assignees 
        SET status = 'completed', completed_at = NOW(), completion_report = ?
        WHERE request_id = ? AND user_id = ? AND status = 'in_progress'
    ");
    $stmt->execute([$report, $requestId, $userId]);
    
    if ($stmt->rowCount() === 0) {
        jsonError('担当情報が見つかりません');
    }
    
    // 全員が完了したかチェック
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM guild_request_assignees WHERE request_id = ?
    ");
    $stmt->execute([$requestId]);
    $counts = $stmt->fetch();
    
    if ($counts['total'] == $counts['completed']) {
        // 全員完了 → 完了待ちステータスに
        $stmt = $pdo->prepare("
            UPDATE guild_requests SET status = 'pending_complete'
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
        
        // 依頼者に通知
        $stmt = $pdo->prepare("SELECT requester_id, title FROM guild_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        createNotification(
            $request['requester_id'],
            'request_completed',
            '完了報告が届きました',
            '「' . $request['title'] . '」の完了報告が届きました',
            $requestId,
            'request'
        );
    }
    
    logActivity('complete_work', 'request', $requestId);
    
    jsonSuccess([], '完了報告を送信しました');
}

/**
 * 完了承認
 */
function approveComplete() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    
    // 依頼情報を取得
    $stmt = $pdo->prepare("
        SELECT * FROM guild_requests 
        WHERE id = ? AND status = 'pending_complete'
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        jsonError('依頼が見つかりません');
    }
    
    // 権限チェック
    if ($request['requester_id'] != $userId && !isGuildSystemAdmin()) {
        jsonError('この操作を行う権限がありません');
    }
    
    try {
        $pdo->beginTransaction();
        
        // 担当者にEarthを分配（完了時分配の場合）
        if ($request['distribution_timing'] === 'on_complete') {
            $stmt = $pdo->prepare("
                SELECT user_id, earth_amount FROM guild_request_assignees
                WHERE request_id = ? AND status = 'completed' AND earth_paid = 0
            ");
            $stmt->execute([$requestId]);
            $assignees = $stmt->fetchAll();
            
            foreach ($assignees as $assignee) {
                distributeEarth($requestId, $assignee['user_id'], $assignee['earth_amount']);
            }
        }
        
        // 依頼を完了に
        $stmt = $pdo->prepare("
            UPDATE guild_requests SET status = 'completed'
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
        
        // 担当者に承認済みマーク
        $stmt = $pdo->prepare("
            UPDATE guild_request_assignees 
            SET approved_by = ?, approved_at = NOW()
            WHERE request_id = ?
        ");
        $stmt->execute([$userId, $requestId]);
        
        $pdo->commit();
        
        jsonSuccess([], '完了を承認しました');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Approve complete error: ' . $e->getMessage());
        jsonError('処理に失敗しました');
    }
}

/**
 * 依頼キャンセル
 */
function cancelRequest() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $requestId = (int)($input['request_id'] ?? 0);
    
    // 依頼情報を取得
    $stmt = $pdo->prepare("SELECT * FROM guild_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        jsonError('依頼が見つかりません');
    }
    
    // 権限チェック
    if ($request['requester_id'] != $userId && !isGuildSystemAdmin()) {
        jsonError('この操作を行う権限がありません');
    }
    
    try {
        $pdo->beginTransaction();
        
        // 依頼をキャンセル
        $stmt = $pdo->prepare("
            UPDATE guild_requests SET status = 'cancelled'
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
        
        // ギルド予算を返却
        if ($request['earth_source'] === 'guild' && $request['guild_id']) {
            $stmt = $pdo->prepare("
                UPDATE guild_guilds SET remaining_budget = remaining_budget + ?
                WHERE id = ?
            ");
            $stmt->execute([$request['earth_amount'], $request['guild_id']]);
        }
        
        // 立候補者に通知
        $stmt = $pdo->prepare("
            SELECT user_id FROM guild_request_applications 
            WHERE request_id = ? AND status = 'pending'
        ");
        $stmt->execute([$requestId]);
        $applicants = $stmt->fetchAll();
        
        foreach ($applicants as $applicant) {
            createNotification(
                $applicant['user_id'],
                'request_cancelled',
                '依頼がキャンセルされました',
                '「' . $request['title'] . '」がキャンセルされました',
                $requestId,
                'request'
            );
        }
        
        $pdo->commit();
        
        logActivity('cancel_request', 'request', $requestId);
        
        jsonSuccess([], '依頼をキャンセルしました');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Cancel request error: ' . $e->getMessage());
        jsonError('処理に失敗しました');
    }
}

/**
 * Earth分配
 */
function distributeEarth($requestId, $toUserId, $amount) {
    $pdo = getDB();
    $fiscalYear = getCurrentFiscalYear();
    
    // 依頼情報を取得
    $stmt = $pdo->prepare("SELECT * FROM guild_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    // 取引を記録
    $stmt = $pdo->prepare("
        INSERT INTO guild_earth_transactions 
        (fiscal_year, from_user_id, to_user_id, from_guild_id, amount, 
         transaction_type, request_id, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'request_reward', ?, 'completed', NOW())
    ");
    $stmt->execute([
        $fiscalYear,
        $request['requester_id'],
        $toUserId,
        $request['guild_id'],
        $amount,
        $requestId,
    ]);
    
    // ユーザーの残高を更新
    $stmt = $pdo->prepare("
        INSERT INTO guild_earth_balances (user_id, fiscal_year, total_earned, current_balance)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            total_earned = total_earned + ?,
            current_balance = current_balance + ?
    ");
    $stmt->execute([$toUserId, $fiscalYear, $amount, $amount, $amount, $amount]);
    
    // 担当者テーブルを更新
    $stmt = $pdo->prepare("
        UPDATE guild_request_assignees 
        SET earth_paid = 1, earth_paid_at = NOW()
        WHERE request_id = ? AND user_id = ?
    ");
    $stmt->execute([$requestId, $toUserId]);
    
    // 通知
    createNotification(
        $toUserId,
        'earth_received',
        'Earthを受け取りました',
        $amount . ' Earthを受け取りました',
        $requestId,
        'request'
    );
}

/**
 * 通知作成
 */
function createNotification($userId, $type, $title, $message, $relatedId = null, $relatedType = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO guild_notifications 
        (user_id, notification_type, title, message, related_id, related_type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $type, $title, $message, $relatedId, $relatedType]);
}

/**
 * 依頼更新
 */
function updateRequest() {
    // TODO: 実装
    jsonError('Not implemented');
}

/**
 * 依頼一覧取得
 */
function listRequests() {
    // TODO: 実装
    jsonError('Not implemented');
}

/**
 * 依頼詳細取得
 */
function getRequestDetail() {
    // TODO: 実装
    jsonError('Not implemented');
}

/**
 * 感謝を送る
 */
function sendThanks() {
    // TODO: 実装
    jsonError('Not implemented');
}
