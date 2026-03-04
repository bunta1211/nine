<?php
/**
 * Guild カレンダーAPI
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/common.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        requireApiLogin();
        getEntry();
        break;
    case 'save':
        requireApiLogin();
        saveEntry();
        break;
    case 'delete':
        requireApiLogin();
        deleteEntry();
        break;
    case 'list':
        requireApiLogin();
        listEntries();
        break;
    default:
        jsonError('Invalid action', 400);
}

/**
 * エントリー取得
 */
function getEntry() {
    $date = $_GET['date'] ?? '';
    $userId = $_GET['user_id'] ?? getGuildUserId();
    
    if (!$date) {
        jsonError('日付が必要です');
    }
    
    // 他ユーザーのカレンダーを見る権限チェック
    $currentUserId = getGuildUserId();
    if ($userId != $currentUserId && !canViewUserCalendar($currentUserId, $userId)) {
        jsonError('閲覧権限がありません', 403);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM guild_calendar_entries 
        WHERE user_id = ? AND entry_date = ?
    ");
    $stmt->execute([$userId, $date]);
    $entry = $stmt->fetch();
    
    jsonSuccess(['entry' => $entry ?: null]);
}

/**
 * エントリー保存
 */
function saveEntry() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $date = $input['entry_date'] ?? '';
    $type = $input['entry_type'] ?? '';
    $location = trim($input['work_location'] ?? '');
    $startTime = $input['start_time'] ?? null;
    $endTime = $input['end_time'] ?? null;
    $note = trim($input['note'] ?? '');
    
    if (!$date) {
        jsonError('日付が必要です');
    }
    
    // タイプが空の場合は削除
    if (empty($type)) {
        $stmt = $pdo->prepare("
            DELETE FROM guild_calendar_entries 
            WHERE user_id = ? AND entry_date = ?
        ");
        $stmt->execute([$userId, $date]);
        
        jsonSuccess([], '予定を削除しました');
    }
    
    // 保存
    $stmt = $pdo->prepare("
        INSERT INTO guild_calendar_entries 
        (user_id, entry_date, entry_type, work_location, start_time, end_time, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            entry_type = VALUES(entry_type),
            work_location = VALUES(work_location),
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            note = VALUES(note),
            updated_at = NOW()
    ");
    $stmt->execute([
        $userId,
        $date,
        $type,
        $location ?: null,
        $startTime ?: null,
        $endTime ?: null,
        $note ?: null,
    ]);
    
    logActivity('save_calendar', 'calendar', null, [
        'date' => $date,
        'type' => $type,
    ]);
    
    jsonSuccess([], '保存しました');
}

/**
 * エントリー削除
 */
function deleteEntry() {
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    $date = $input['entry_date'] ?? '';
    
    if (!$date) {
        jsonError('日付が必要です');
    }
    
    $stmt = $pdo->prepare("
        DELETE FROM guild_calendar_entries 
        WHERE user_id = ? AND entry_date = ?
    ");
    $stmt->execute([$userId, $date]);
    
    jsonSuccess([], '削除しました');
}

/**
 * エントリー一覧取得
 */
function listEntries() {
    $startDate = $_GET['start'] ?? date('Y-m-01');
    $endDate = $_GET['end'] ?? date('Y-m-t');
    $userId = $_GET['user_id'] ?? getGuildUserId();
    
    // 権限チェック
    $currentUserId = getGuildUserId();
    if ($userId != $currentUserId && !canViewUserCalendar($currentUserId, $userId)) {
        jsonError('閲覧権限がありません', 403);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM guild_calendar_entries 
        WHERE user_id = ? AND entry_date BETWEEN ? AND ?
        ORDER BY entry_date
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    $entries = $stmt->fetchAll();
    
    jsonSuccess(['entries' => $entries]);
}

/**
 * ユーザーのカレンダーを閲覧できるかチェック
 */
function canViewUserCalendar($viewerId, $targetUserId) {
    // システム管理者は全員のカレンダーを見れる
    if (isGuildSystemAdmin()) {
        return true;
    }
    
    $pdo = getDB();
    
    // 同じギルドで3役ならOK
    $stmt = $pdo->prepare("
        SELECT gm1.guild_id
        FROM guild_members gm1
        INNER JOIN guild_members gm2 ON gm1.guild_id = gm2.guild_id
        WHERE gm1.user_id = ? AND gm1.is_active = 1
        AND gm1.role IN ('leader', 'sub_leader', 'coordinator')
        AND gm2.user_id = ? AND gm2.is_active = 1
    ");
    $stmt->execute([$viewerId, $targetUserId]);
    if ($stmt->fetch()) {
        return true;
    }
    
    // 個別許可チェック
    $stmt = $pdo->prepare("
        SELECT id FROM guild_calendar_permissions 
        WHERE viewer_user_id = ? 
        AND (target_user_id = ? OR target_user_id IS NULL)
        AND (expires_at IS NULL OR expires_at >= CURDATE())
    ");
    $stmt->execute([$viewerId, $targetUserId]);
    if ($stmt->fetch()) {
        return true;
    }
    
    return false;
}
