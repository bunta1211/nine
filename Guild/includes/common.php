<?php
/**
 * Guild 共通インクルードファイル
 */

// エラーレポート設定（デバッグ用：本番では display_errors を 0 に）
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// 設定ファイル読み込み
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/lang.php';

// ヘルパーファイル読み込み（存在する場合のみ）
if (file_exists(__DIR__ . '/app_notify.php')) {
    require_once __DIR__ . '/app_notify.php';
}

// セッション開始
guild_start_session();

// 言語切替処理
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], SUPPORTED_LANGUAGES)) {
    setLanguage($_GET['lang']);
}

/**
 * HTMLエスケープ
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * CSRFトークン生成
 */
function generateCsrfToken() {
    if (!isset($_SESSION['guild_csrf_token'])) {
        $_SESSION['guild_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['guild_csrf_token'];
}

/**
 * CSRFトークン検証
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['guild_csrf_token']) && 
           hash_equals($_SESSION['guild_csrf_token'], $token);
}

/**
 * CSRFトークンをリフレッシュ
 */
function refreshCsrfToken() {
    $_SESSION['guild_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['guild_csrf_token'];
}

/**
 * 現在のユーザー情報を取得
 */
function getCurrentUser() {
    static $user = null;
    
    if ($user !== null) {
        return $user;
    }
    
    $userId = getGuildUserId();
    if (!$userId) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.display_name, u.avatar_path as avatar,
               gup.hire_date, gup.qualifications, gup.skills, gup.teachable_lessons,
               gup.availability_today, gup.availability_today_percent,
               gup.availability_week, gup.availability_week_percent,
               gup.availability_month, gup.availability_month_percent,
               gup.availability_next, gup.availability_next_percent,
               gup.unavailable_until, gup.language, gup.dark_mode,
               gup.notify_new_request, gup.notify_assigned, gup.notify_approved,
               gup.notify_earth_received, gup.notify_thanks, gup.notify_advance_payment,
               gup.email_notifications,
               gsp.is_system_admin, gsp.is_payroll_admin
        FROM users u
        LEFT JOIN guild_user_profiles gup ON u.id = gup.user_id
        LEFT JOIN guild_system_permissions gsp ON u.id = gsp.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    return $user;
}

/**
 * ユーザーのEarth残高を取得
 */
function getUserEarthBalance($userId = null, $fiscalYear = null) {
    if ($userId === null) {
        $userId = getGuildUserId();
    }
    if ($fiscalYear === null) {
        $fiscalYear = getCurrentFiscalYear();
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM guild_earth_balances 
        WHERE user_id = ? AND fiscal_year = ?
    ");
    $stmt->execute([$userId, $fiscalYear]);
    $balance = $stmt->fetch();
    
    if (!$balance) {
        return [
            'user_id' => $userId,
            'fiscal_year' => $fiscalYear,
            'total_earned' => 0,
            'total_spent' => 0,
            'total_paid' => 0,
            'current_balance' => 0,
        ];
    }
    
    return $balance;
}

/**
 * ユーザーのギルド権限を取得
 */
function getUserGuildRole($userId, $guildId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT role, can_issue_requests FROM guild_members 
        WHERE user_id = ? AND guild_id = ? AND is_active = 1
    ");
    $stmt->execute([$userId, $guildId]);
    return $stmt->fetch();
}

/**
 * ユーザーが所属するギルド一覧を取得
 */
function getUserGuilds($userId = null) {
    if ($userId === null) {
        $userId = getGuildUserId();
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT g.*, gm.role, gm.can_issue_requests
        FROM guild_guilds g
        INNER JOIN guild_members gm ON g.id = gm.guild_id
        WHERE gm.user_id = ?
        ORDER BY g.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * ギルド長またはサブリーダーであるか（いずれかのギルドで）
 */
function isGuildLeaderOrSubLeader($userId = null) {
    $guilds = getUserGuilds($userId);
    foreach ($guilds as $g) {
        if (in_array($g['role'], ['leader', 'sub_leader'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * ギルド長ページ用：リーダー・サブリーダーを務めるギルド一覧を取得
 */
function getGuildsWhereLeaderOrSubLeader($userId = null) {
    $guilds = getUserGuilds($userId);
    return array_filter($guilds, function ($g) {
        return in_array($g['role'], ['leader', 'sub_leader'], true);
    });
}

/**
 * 依頼発行権限チェック
 */
function canIssueRequest($userId, $guildId, $requestType) {
    $role = getUserGuildRole($userId, $guildId);
    if (!$role) {
        return false;
    }
    
    $roleLevel = GUILD_ROLES[$role['role']]['level'] ?? 0;
    
    // リーダー、サブリーダー、コーディネーターは依頼発行可能
    if ($roleLevel >= 1) {
        return true;
    }
    
    // リーダーから権限を付与されている場合
    if ($role['can_issue_requests']) {
        return true;
    }
    
    return false;
}

/**
 * 業務指令発行権限チェック
 */
function canIssueOrder($userId, $guildId) {
    $role = getUserGuildRole($userId, $guildId);
    if (!$role) {
        return false;
    }
    
    $roleLevel = GUILD_ROLES[$role['role']]['level'] ?? 0;
    
    // リーダー、サブリーダーのみ業務指令発行可能
    return $roleLevel >= 2;
}

/**
 * 勤務交代承認権限チェック
 */
function canApproveShiftSwap($userId, $guildId) {
    $role = getUserGuildRole($userId, $guildId);
    if (!$role) {
        return false;
    }
    
    $roleLevel = GUILD_ROLES[$role['role']]['level'] ?? 0;
    
    // リーダー、サブリーダーは勤務交代承認可能
    return $roleLevel >= 2;
}

/**
 * 未読通知数を取得
 */
function getUnreadNotificationCount($userId = null) {
    if ($userId === null) {
        $userId = getGuildUserId();
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM guild_notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    return (int)$result['count'];
}

/**
 * ダークモードかどうか
 */
function isDarkMode() {
    $user = getCurrentUser();
    return $user && $user['dark_mode'] == 1;
}

/**
 * アセットURLを取得
 */
function asset($path) {
    $baseUrl = getGuildBaseUrl();
    return $baseUrl . '/assets/' . ltrim($path, '/');
}

/**
 * 日付をフォーマット
 */
function formatDate($date, $format = 'Y/m/d') {
    if (empty($date)) {
        return '';
    }
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * 日時をフォーマット
 */
function formatDateTime($datetime, $format = 'Y/m/d H:i') {
    if (empty($datetime)) {
        return '';
    }
    $dt = new DateTime($datetime);
    return $dt->format($format);
}

/**
 * 金額をフォーマット
 */
function formatEarth($amount) {
    return number_format((int)$amount) . ' Earth';
}

/**
 * 円をフォーマット
 */
function formatYen($amount) {
    return '¥' . number_format((int)$amount);
}

/**
 * ログを記録
 */
function logActivity($actionType, $targetType = null, $targetId = null, $details = null) {
    $userId = getGuildUserId();
    if (!$userId) {
        return;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO guild_activity_logs 
        (user_id, action_type, target_type, target_id, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $actionType,
        $targetType,
        $targetId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
