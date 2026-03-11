<?php
/**
 * Guild セッション設定
 * Social9と同じセッションを共有
 */

/**
 * セッションを開始（Social9と共有）
 */
function guild_start_session() {
    if (session_status() == PHP_SESSION_NONE) {
        if (!headers_sent()) {
            // Social9と同じセッション保存先を使用（ログイン状態を共有するため）
            $session_save_path = __DIR__ . '/../../tmp/sessions';
            if (!is_dir($session_save_path)) {
                @mkdir($session_save_path, 0770, true);
            }
            if (is_dir($session_save_path) && is_writable($session_save_path)) {
                @session_save_path($session_save_path);
            }
            @ini_set('session.cookie_httponly', 1);
            @ini_set('session.use_strict_mode', 1);
            @session_start();
        }
    }
}

/**
 * Guildにログイン済みかチェック（Social9のセッションを参照）
 */
function isGuildLoggedIn() {
    guild_start_session();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Guildログインを要求
 */
function requireGuildLogin() {
    if (!isGuildLoggedIn()) {
        // Social9のログインページにリダイレクト
        $social9Url = getSocial9Url();
        header('Location: ' . $social9Url . '/index.php');
        exit;
    }
}

/**
 * 現在のユーザーIDを取得（Social9と共有）
 */
function getGuildUserId() {
    guild_start_session();
    return $_SESSION['user_id'] ?? null;
}

/**
 * GuildのベースURLを取得
 */
function getGuildBaseUrl() {
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    
    // Guild内のサブフォルダからの場合
    if (strpos($script_dir, '/Guild/admin') !== false) {
        return dirname(dirname($_SERVER['SCRIPT_NAME']));
    }
    if (strpos($script_dir, '/Guild') !== false) {
        $pos = strpos($script_dir, '/Guild');
        return substr($script_dir, 0, $pos + 6);
    }
    
    return $script_dir;
}

/**
 * Social9のURLを取得
 */
function getSocial9Url() {
    $guildUrl = getGuildBaseUrl();
    // /nine/Guild -> /nine
    return dirname($guildUrl);
}

/**
 * システム管理者かチェック
 */
function isGuildSystemAdmin() {
    $userId = getGuildUserId();
    if (!$userId) return false;
    
    // DBから権限を確認
    static $isAdmin = null;
    if ($isAdmin === null) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT is_system_admin FROM guild_system_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            $isAdmin = $result && (int)$result['is_system_admin'] === 1;
        } catch (PDOException $e) {
            $isAdmin = false;
        }
    }
    return $isAdmin;
}

/**
 * システム管理者権限を要求
 */
function requireGuildSystemAdmin() {
    requireGuildLogin();
    
    if (!isGuildSystemAdmin()) {
        http_response_code(403);
        if (defined('GUILD_IS_API') && GUILD_IS_API) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'システム管理者権限が必要です']);
        } else {
            include __DIR__ . '/../templates/access_denied.php';
        }
        exit;
    }
}

/**
 * 給与担当者かチェック
 */
function isGuildPayrollAdmin() {
    $userId = getGuildUserId();
    if (!$userId) return false;
    
    static $isPayroll = null;
    if ($isPayroll === null) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT is_payroll_admin FROM guild_system_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            $isPayroll = $result && (int)$result['is_payroll_admin'] === 1;
        } catch (PDOException $e) {
            $isPayroll = false;
        }
    }
    return $isPayroll;
}

/**
 * 給与担当者権限を要求
 */
function requireGuildPayrollAdmin() {
    requireGuildLogin();
    
    if (!isGuildSystemAdmin() && !isGuildPayrollAdmin()) {
        http_response_code(403);
        if (defined('GUILD_IS_API') && GUILD_IS_API) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '給与担当者権限が必要です']);
        } else {
            include __DIR__ . '/../templates/access_denied.php';
        }
        exit;
    }
}

// このファイルをインクルードするだけでセッションが開始される
guild_start_session();
