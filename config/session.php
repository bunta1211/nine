<?php
/**
 * セッション設定
 */

/**
 * セッションを開始（一度だけ）
 */
function start_session_once() {
    if (session_status() == PHP_SESSION_NONE) {
        // ヘッダーがまだ送信されていない場合のみセッション設定を変更
        if (!headers_sent()) {
            // セッション保存先を明示（移転後も確実に永続化・本番/ローカル共通）
            $session_save_path = __DIR__ . '/../tmp/sessions';
            if (!is_dir($session_save_path)) {
                @mkdir($session_save_path, 0770, true);
            }
            if (is_dir($session_save_path) && is_writable($session_save_path)) {
                session_save_path($session_save_path);
            }
            
            // セッション設定
            @ini_set('session.cookie_httponly', 1);
            @ini_set('session.use_strict_mode', 1);
            // HTTPS の場合はセキュアフラグを付与（リバースプロキシ対応: X-Forwarded-Proto）
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            if ($isHttps) {
                @ini_set('session.cookie_secure', 1);
            }
            // 複数アカウント同時ログイン：サブドメイン毎に別セッションにする（domainを空=現在のホストのみ）
            @ini_set('session.cookie_domain', '');
            
            // 全デバイス（アプリ・PC・携帯）で常時ログオン：ブラウザを閉じてもログイン維持
            $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (86400 * 30); // 30日
            @ini_set('session.cookie_lifetime', $lifetime);
            @ini_set('session.gc_maxlifetime', $lifetime);
            
            session_start();
        }
        
        // 自動ログアウトチェック
        if (isset($_SESSION)) {
            checkAutoLogout();
        }
    }
}

/**
 * 自動ログアウトチェック
 */
function checkAutoLogout() {
    // ログインしていない場合はスキップ
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    
    // 自動ログアウト時間を取得
    $auto_logout_minutes = $_SESSION['auto_logout_minutes'] ?? null;
    
    // セッションに保存されていない場合はDBから取得（デフォルト0＝自動ログアウトしない・常時ログオン）
    if ($auto_logout_minutes === null) {
        try {
            require_once __DIR__ . '/database.php';
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT auto_logout_minutes FROM user_advanced_settings WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $auto_logout_minutes = $result ? (int)$result['auto_logout_minutes'] : 0;
            $_SESSION['auto_logout_minutes'] = $auto_logout_minutes;
        } catch (Exception $e) {
            $auto_logout_minutes = 0; // デフォルト：常時ログオン
        }
    }
    
    // 許容するのは 0（しない）と 1440（24時間）のみ。それ以外は「しない」に強制
    if (!in_array($auto_logout_minutes, [0, 1440], true)) {
        $auto_logout_minutes = 0;
        $_SESSION['auto_logout_minutes'] = 0;
    }
    
    // 自動ログアウトが無効（0）の場合はスキップ
    if ($auto_logout_minutes === 0) {
        $_SESSION['last_activity'] = time();
        return;
    }
    
    // 最終アクティビティ時刻をチェック
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        $timeout_seconds = $auto_logout_minutes * 60;
        
        if ($inactive_time > $timeout_seconds) {
            // セッションを破棄してログアウト
            $user_id = $_SESSION['user_id'];
            session_unset();
            session_destroy();
            
            // 新しいセッションを開始
            session_start();
            $_SESSION['logout_reason'] = 'timeout';
            
            // ログインページにリダイレクト（移転後も絶対URLで確実に）
            if (function_exists('getBaseUrl')) {
                $base = getBaseUrl();
                header('Location: ' . ($base !== '' ? $base . '/index.php?timeout=1' : '/index.php?timeout=1'));
            } else {
                $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
                $base_path = rtrim($script_dir, '/');
                if (strpos($script_dir, '/admin') !== false || strpos($script_dir, '/system') !== false) {
                    $base_path = dirname($script_dir);
                }
                header('Location: ' . $base_path . '/index.php?timeout=1');
            }
            exit;
        }
    }
    
    // 最終アクティビティ時刻を更新
    $_SESSION['last_activity'] = time();
}

/**
 * ログイン済みかチェック
 */
function isLoggedIn() {
    start_session_once();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * ログインを要求
 */
function requireLogin() {
    if (!isLoggedIn()) {
        // 移転後も確実に同じオリジンへ（getBaseUrl があれば絶対URL）
        if (function_exists('getBaseUrl')) {
            $base = getBaseUrl();
            header('Location: ' . ($base !== '' ? $base . '/index.php' : '/index.php'));
            exit;
        }
        // フォールバック: 相対パス
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $base_path = rtrim($script_dir, '/');
        if (strpos($script_dir, '/admin') !== false) {
            $base_path = dirname($script_dir);
        }
        if (strpos($script_dir, '/investor') !== false) {
            $base_path = dirname($script_dir);
        }
        header('Location: ' . $base_path . '/index.php');
        exit;
    }
}

/**
 * アクセスルートをチェック
 */
function check_access_route($expected_route) {
    start_session_once();

    if (!isLoggedIn()) {
        if (function_exists('getBaseUrl')) {
            $base = getBaseUrl();
            header('Location: ' . ($base !== '' ? $base . '/index.php' : '/index.php'));
        } else {
            header('Location: /index.php');
        }
        exit;
    }

    if (!isset($_SESSION['access_route']) || $_SESSION['access_route'] !== $expected_route) {
        http_response_code(403);
        include __DIR__ . '/../templates/access_denied.php';
        exit;
    }
}

/**
 * 認証レベルをチェック
 */
function requireAuthLevel($required_level) {
    requireLogin();
    
    $current_level = $_SESSION['auth_level'] ?? 0;
    
    if ($current_level < $required_level) {
        if (defined('IS_API') && IS_API) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => '認証レベルが不足しています',
                'required_level' => $required_level,
                'current_level' => $current_level
            ]);
            exit;
        } else {
            header('Location: /verify_phone.php?required=' . $required_level);
            exit;
        }
    }
}

/**
 * システム管理者チェック
 */
function requireSystemAdmin() {
    requireLogin();
    
    $role = $_SESSION['role'] ?? 'user';
    // developer, admin, system_admin, super_admin はシステム管理にアクセス可能
    if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
        http_response_code(403);
        if (defined('IS_API') && IS_API) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'システム管理者権限が必要です']);
        } else {
            include __DIR__ . '/../templates/access_denied.php';
        }
        exit;
    }
}

/**
 * 組織管理者以上チェック
 * グローバルな管理者のほか、現在選択中の組織のオーナー/管理者も許可する
 */
function requireOrgAdmin() {
    requireLogin();
    
    $role = $_SESSION['role'] ?? 'user';
    $is_org_admin = $_SESSION['is_org_admin'] ?? 0;
    $current_org_role = $_SESSION['current_org_role'] ?? '';
    
    $is_global_admin = in_array($role, ['developer', 'system_admin', 'super_admin', 'org_admin', 'admin']) || $is_org_admin == 1;
    $is_current_org_owner_or_admin = !empty($_SESSION['current_org_id']) && in_array($current_org_role, ['owner', 'admin'], true);
    
    if (!$is_global_admin && !$is_current_org_owner_or_admin) {
        http_response_code(403);
        if (defined('IS_API') && IS_API) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
        } else {
            include __DIR__ . '/../templates/access_denied.php';
        }
        exit;
    }
}

/**
 * 組織管理者かどうかをチェック
 */
function isOrgAdminUser() {
    $role = $_SESSION['role'] ?? 'user';
    $is_org_admin = $_SESSION['is_org_admin'] ?? 0;
    return in_array($role, ['developer', 'system_admin', 'super_admin', 'org_admin', 'admin']) || $is_org_admin == 1;
}

/**
 * システム開発者かどうかをチェック
 */
function isDeveloper() {
    $role = $_SESSION['role'] ?? 'user';
    return $role === 'developer';
}

/**
 * システム開発者権限を要求
 */
function requireDeveloper() {
    requireLogin();
    
    $role = $_SESSION['role'] ?? 'user';
    if ($role !== 'developer') {
        http_response_code(403);
        if (defined('IS_API') && IS_API) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'システム開発者権限が必要です']);
        } else {
            include __DIR__ . '/../templates/access_denied.php';
        }
        exit;
    }
}

/**
 * 現在のユーザーIDを取得
 */
function getCurrentUserId() {
    start_session_once();
    return $_SESSION['user_id'] ?? null;
}

// このファイルを直接インクルードするだけでセッションが開始されるようにする
start_session_once();
