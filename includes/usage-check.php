<?php
/**
 * 利用時間制限チェックミドルウェア
 * 
 * 組織管理者が設定した利用時間制限を適用
 */

/**
 * ユーザーの利用時間をチェック
 * 制限に違反している場合はエラーメッセージを返す
 * 
 * @param PDO $pdo
 * @param int $userId
 * @param int|null $orgId 組織ID（指定しない場合は全所属組織をチェック）
 * @return array ['allowed' => bool, 'message' => string, 'restrictions' => array]
 */
function checkUsageRestrictions($pdo, $userId, $orgId = null) {
    // 組織ごとの制限を取得
    $sql = "
        SELECT 
            om.organization_id,
            o.name as org_name,
            om.role,
            om.usage_start_time,
            om.usage_end_time,
            om.daily_limit_minutes
        FROM organization_members om
        INNER JOIN organizations o ON om.organization_id = o.id
        WHERE om.user_id = ? AND om.left_at IS NULL
    ";
    $params = [$userId];
    
    if ($orgId) {
        $sql .= " AND om.organization_id = ?";
        $params[] = $orgId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 制限がない場合は許可
    if (empty($memberships)) {
        return ['allowed' => true, 'message' => '', 'restrictions' => []];
    }
    
    $currentTime = new DateTime();
    $currentTimeStr = $currentTime->format('H:i:s');
    
    $restrictions = [];
    $blocked = false;
    $blockMessage = '';
    
    foreach ($memberships as $membership) {
        // 管理者・オーナーは制限なし
        if (in_array($membership['role'], ['owner', 'admin'])) {
            continue;
        }
        
        $startTime = $membership['usage_start_time'];
        $endTime = $membership['usage_end_time'];
        $dailyLimit = (int)($membership['daily_limit_minutes'] ?? 0);
        
        // 利用時間帯チェック
        if ($startTime && $endTime) {
            $inTimeRange = isInTimeRange($currentTimeStr, $startTime, $endTime);
            
            if (!$inTimeRange) {
                $blocked = true;
                $blockMessage = sprintf(
                    '%s では %s から %s の間のみ利用できます。',
                    $membership['org_name'],
                    formatTime($startTime),
                    formatTime($endTime)
                );
                $restrictions[] = [
                    'type' => 'time_range',
                    'org_name' => $membership['org_name'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'message' => $blockMessage
                ];
            }
        }
        
        // 日次利用時間制限チェック（オプション：利用履歴テーブルが必要）
        if ($dailyLimit > 0) {
            $usedMinutes = getDailyUsageMinutes($pdo, $userId, $membership['organization_id']);
            if ($usedMinutes >= $dailyLimit) {
                $blocked = true;
                $blockMessage = sprintf(
                    '%s での1日の利用時間（%d分）を超えました。',
                    $membership['org_name'],
                    $dailyLimit
                );
                $restrictions[] = [
                    'type' => 'daily_limit',
                    'org_name' => $membership['org_name'],
                    'limit_minutes' => $dailyLimit,
                    'used_minutes' => $usedMinutes,
                    'message' => $blockMessage
                ];
            }
        }
    }
    
    return [
        'allowed' => !$blocked,
        'message' => $blockMessage,
        'restrictions' => $restrictions
    ];
}

/**
 * 時間が範囲内かチェック
 * @param string $current HH:MM:SS
 * @param string $start HH:MM:SS
 * @param string $end HH:MM:SS
 * @return bool
 */
function isInTimeRange($current, $start, $end) {
    // 時間を秒に変換
    $currentSec = timeToSeconds($current);
    $startSec = timeToSeconds($start);
    $endSec = timeToSeconds($end);
    
    // 通常の範囲（開始 < 終了）
    if ($startSec <= $endSec) {
        return $currentSec >= $startSec && $currentSec <= $endSec;
    }
    
    // 日をまたぐ範囲（例: 22:00 - 06:00）
    return $currentSec >= $startSec || $currentSec <= $endSec;
}

/**
 * HH:MM:SS を秒に変換
 */
function timeToSeconds($time) {
    $parts = explode(':', $time);
    $hours = (int)($parts[0] ?? 0);
    $minutes = (int)($parts[1] ?? 0);
    $seconds = (int)($parts[2] ?? 0);
    return $hours * 3600 + $minutes * 60 + $seconds;
}

/**
 * 時間をフォーマット
 */
function formatTime($time) {
    $parts = explode(':', $time);
    return sprintf('%d:%02d', (int)$parts[0], (int)($parts[1] ?? 0));
}

/**
 * 今日の利用時間（分）を取得
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return int
 */
function getDailyUsageMinutes($pdo, $userId, $orgId) {
    // usage_logs テーブルがない場合は0を返す
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(duration_minutes), 0) as total
            FROM usage_logs
            WHERE user_id = ? 
              AND organization_id = ?
              AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId, $orgId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // テーブルが存在しない場合は0を返す
        return 0;
    }
}

/**
 * 利用時間を記録
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @param int $durationMinutes
 * @param string $activityType
 */
function logUsage($pdo, $userId, $orgId, $durationMinutes, $activityType = 'general') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usage_logs (user_id, organization_id, duration_minutes, activity_type, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $orgId, $durationMinutes, $activityType]);
    } catch (PDOException $e) {
        // テーブルが存在しない場合は無視
        error_log('logUsage: ' . $e->getMessage());
    }
}

/**
 * 利用制限チェック（簡易版）
 * 利用不可の場合はエラーを返して終了
 * @param PDO $pdo
 * @param int $userId
 * @param int|null $orgId
 */
function requireUsageAllowed($pdo, $userId, $orgId = null) {
    $check = checkUsageRestrictions($pdo, $userId, $orgId);
    
    if (!$check['allowed']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $check['message'],
            'error_type' => 'usage_restricted',
            'restrictions' => $check['restrictions']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


