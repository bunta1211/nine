<?php
/**
 * Guild 設定API
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/common.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'update':
        requireApiLogin();
        updateSettings();
        break;
    case 'get':
        requireApiLogin();
        getSettings();
        break;
    default:
        jsonError('Invalid action', 400);
}

/**
 * 設定を更新
 */
function updateSettings() {
    $section = $_GET['section'] ?? '';
    $input = getJsonInput();
    $pdo = getDB();
    $userId = getGuildUserId();
    
    switch ($section) {
        case 'profile':
            updateProfile($pdo, $userId, $input);
            break;
        case 'availability':
            updateAvailability($pdo, $userId, $input);
            break;
        case 'notifications':
            updateNotifications($pdo, $userId, $input);
            break;
        case 'display':
            updateDisplay($pdo, $userId, $input);
            break;
        default:
            jsonError('Invalid section');
    }
}

/**
 * プロフィール更新
 */
function updateProfile($pdo, $userId, $input) {
    $hireDate = $input['hire_date'] ?? null;
    $qualifications = trim($input['qualifications'] ?? '');
    $skills = trim($input['skills'] ?? '');
    $teachableLessons = trim($input['teachable_lessons'] ?? '');
    
    $stmt = $pdo->prepare("
        INSERT INTO guild_user_profiles 
        (user_id, hire_date, qualifications, skills, teachable_lessons)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            hire_date = VALUES(hire_date),
            qualifications = VALUES(qualifications),
            skills = VALUES(skills),
            teachable_lessons = VALUES(teachable_lessons),
            updated_at = NOW()
    ");
    $stmt->execute([
        $userId,
        $hireDate ?: null,
        $qualifications ?: null,
        $skills ?: null,
        $teachableLessons ?: null,
    ]);
    
    logActivity('update_profile', 'user', $userId);
    
    jsonSuccess([], '保存しました');
}

/**
 * 余力設定更新
 */
function updateAvailability($pdo, $userId, $input) {
    $stmt = $pdo->prepare("
        INSERT INTO guild_user_profiles 
        (user_id, availability_today, availability_today_percent,
         availability_week, availability_week_percent,
         availability_month, availability_month_percent,
         availability_next, availability_next_percent,
         unavailable_until)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            availability_today = VALUES(availability_today),
            availability_today_percent = VALUES(availability_today_percent),
            availability_week = VALUES(availability_week),
            availability_week_percent = VALUES(availability_week_percent),
            availability_month = VALUES(availability_month),
            availability_month_percent = VALUES(availability_month_percent),
            availability_next = VALUES(availability_next),
            availability_next_percent = VALUES(availability_next_percent),
            unavailable_until = VALUES(unavailable_until),
            updated_at = NOW()
    ");
    $stmt->execute([
        $userId,
        $input['availability_today'] ?? 'available',
        (int)($input['availability_today_percent'] ?? 100),
        $input['availability_week'] ?? 'available',
        (int)($input['availability_week_percent'] ?? 100),
        $input['availability_month'] ?? 'available',
        (int)($input['availability_month_percent'] ?? 100),
        $input['availability_next'] ?? 'available',
        (int)($input['availability_next_percent'] ?? 100),
        $input['unavailable_until'] ?: null,
    ]);
    
    logActivity('update_availability', 'user', $userId);
    
    jsonSuccess([], '保存しました');
}

/**
 * 通知設定更新
 */
function updateNotifications($pdo, $userId, $input) {
    $stmt = $pdo->prepare("
        INSERT INTO guild_user_profiles 
        (user_id, notify_new_request, notify_assigned, notify_approved,
         notify_earth_received, notify_thanks, notify_advance_payment,
         email_notifications)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            notify_new_request = VALUES(notify_new_request),
            notify_assigned = VALUES(notify_assigned),
            notify_approved = VALUES(notify_approved),
            notify_earth_received = VALUES(notify_earth_received),
            notify_thanks = VALUES(notify_thanks),
            notify_advance_payment = VALUES(notify_advance_payment),
            email_notifications = VALUES(email_notifications),
            updated_at = NOW()
    ");
    $stmt->execute([
        $userId,
        (int)($input['notify_new_request'] ?? 1),
        (int)($input['notify_assigned'] ?? 1),
        (int)($input['notify_approved'] ?? 1),
        (int)($input['notify_earth_received'] ?? 1),
        (int)($input['notify_thanks'] ?? 1),
        (int)($input['notify_advance_payment'] ?? 1),
        (int)($input['email_notifications'] ?? 1),
    ]);
    
    logActivity('update_notifications', 'user', $userId);
    
    jsonSuccess([], '保存しました');
}

/**
 * 表示設定更新
 */
function updateDisplay($pdo, $userId, $input) {
    $language = $input['language'] ?? 'ja';
    $darkMode = (int)($input['dark_mode'] ?? 0);
    
    // 言語の検証
    if (!array_key_exists($language, SUPPORTED_LANGUAGES)) {
        $language = 'ja';
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO guild_user_profiles 
        (user_id, language, dark_mode)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            language = VALUES(language),
            dark_mode = VALUES(dark_mode),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $language, $darkMode]);
    
    // セッションも更新
    $_SESSION['guild_language'] = $language;
    $_SESSION['guild_dark_mode'] = $darkMode;
    
    logActivity('update_display', 'user', $userId);
    
    jsonSuccess([], '保存しました');
}

/**
 * 設定取得
 */
function getSettings() {
    $userId = getGuildUserId();
    $pdo = getDB();
    
    $stmt = $pdo->prepare("
        SELECT * FROM guild_user_profiles WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $settings = $stmt->fetch();
    
    jsonSuccess(['settings' => $settings ?: []]);
}
