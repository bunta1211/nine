<?php
/**
 * 組織管理者向け 保護者機能API
 * 組織が未成年ユーザーを管理するための機能
 */

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$organization_id = $_SESSION['organization_id'] ?? null;
$is_org_admin = $_SESSION['is_org_admin'] ?? 0;

// 組織管理者権限チェック
if (!$is_org_admin && ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '組織管理者権限が必要です']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// テーブル確認
ensureOrgParentalTables($pdo);

try {
    switch ($action) {
        case 'get_managed_minors':
            // 組織が管理している未成年ユーザー一覧
            getManagedMinors($pdo, $organization_id);
            break;
            
        case 'add_managed_user':
            // 未成年ユーザーを組織管理に追加
            addManagedUser($pdo, $organization_id, $user_id, $input);
            break;
            
        case 'update_managed_user':
            // 管理ユーザー情報を更新
            updateManagedUser($pdo, $organization_id, $input);
            break;
            
        case 'remove_managed_user':
            // 管理対象から削除
            removeManagedUser($pdo, $organization_id, $input);
            break;
            
        case 'set_org_restrictions':
            // 組織のデフォルト制限設定
            setOrgRestrictions($pdo, $organization_id, $input);
            break;
            
        case 'get_org_restrictions':
            // 組織のデフォルト制限設定を取得
            getOrgRestrictions($pdo, $organization_id);
            break;
            
        case 'apply_org_restrictions':
            // 組織の制限を全管理ユーザーに適用
            applyOrgRestrictions($pdo, $organization_id, $user_id);
            break;
            
        case 'get_user_restrictions':
            // 特定ユーザーの制限を取得
            getUserRestrictions($pdo, $organization_id, $input);
            break;
            
        case 'update_user_restrictions':
            // 特定ユーザーの制限を更新
            updateUserRestrictions($pdo, $organization_id, $user_id, $input);
            break;
            
        default:
            jsonError('無効なアクションです');
    }
} catch (Exception $e) {
    error_log("Org Parental API Error: " . $e->getMessage());
    jsonError('エラーが発生しました');
}

/**
 * 組織が管理している未成年ユーザー一覧
 */
function getManagedMinors($pdo, $organization_id) {
    $stmt = $pdo->prepare("
        SELECT 
            omu.id as management_id,
            omu.guardian_name,
            omu.guardian_contact,
            omu.enrollment_date,
            omu.graduation_date,
            omu.notes,
            omu.is_active,
            omu.created_at as managed_since,
            u.id as user_id,
            u.display_name,
            u.email,
            u.birth_date,
            u.is_minor,
            u.last_seen,
            u.online_status,
            pr.daily_usage_limit_minutes,
            pr.search_restricted,
            pr.dm_restricted,
            pr.group_join_restricted,
            pr.is_active as restrictions_active,
            (SELECT total_minutes FROM usage_time_logs WHERE user_id = u.id AND log_date = CURDATE()) as today_usage,
            manager.display_name as managed_by_name
        FROM organization_managed_users omu
        JOIN users u ON omu.user_id = u.id
        LEFT JOIN parental_restrictions pr ON pr.child_user_id = u.id
        LEFT JOIN users manager ON omu.managed_by_user_id = manager.id
        WHERE omu.organization_id = ? AND omu.is_active = 1
        ORDER BY u.display_name
    ");
    $stmt->execute([$organization_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 数値型にキャスト
    foreach ($users as &$user) {
        $user['user_id'] = (int)$user['user_id'];
        $user['is_minor'] = (int)($user['is_minor'] ?? 0);
        $user['today_usage'] = (int)($user['today_usage'] ?? 0);
        $user['daily_usage_limit_minutes'] = $user['daily_usage_limit_minutes'] ? (int)$user['daily_usage_limit_minutes'] : null;
        $user['search_restricted'] = (int)($user['search_restricted'] ?? 0);
        $user['dm_restricted'] = (int)($user['dm_restricted'] ?? 0);
        $user['group_join_restricted'] = (int)($user['group_join_restricted'] ?? 0);
        $user['restrictions_active'] = (int)($user['restrictions_active'] ?? 0);
        $user['is_active'] = (int)$user['is_active'];
    }
    
    jsonSuccess(['users' => $users, 'count' => count($users)]);
}

/**
 * 未成年ユーザーを組織管理に追加
 */
function addManagedUser($pdo, $organization_id, $manager_id, $input) {
    $target_user_id = (int)($input['user_id'] ?? 0);
    $guardian_name = trim($input['guardian_name'] ?? '');
    $guardian_contact = trim($input['guardian_contact'] ?? '');
    $notes = trim($input['notes'] ?? '');
    
    if (!$target_user_id) {
        jsonError('ユーザーIDが必要です');
    }
    
    // ユーザーが存在するかチェック
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$target_user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonError('ユーザーが見つかりません');
    }
    
    // 既に管理されているかチェック
    $stmt = $pdo->prepare("SELECT id FROM organization_managed_users WHERE organization_id = ? AND user_id = ?");
    $stmt->execute([$organization_id, $target_user_id]);
    if ($stmt->fetch()) {
        jsonError('このユーザーは既に管理対象です');
    }
    
    // 追加
    $stmt = $pdo->prepare("
        INSERT INTO organization_managed_users 
            (organization_id, user_id, managed_by_user_id, guardian_name, guardian_contact, notes, enrollment_date, is_active)
        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 1)
    ");
    $stmt->execute([$organization_id, $target_user_id, $manager_id, $guardian_name, $guardian_contact, $notes]);
    
    // デフォルトの制限を適用
    applyDefaultRestrictions($pdo, $organization_id, $target_user_id, $manager_id);
    
    jsonSuccess(['message' => $user['display_name'] . 'さんを管理対象に追加しました']);
}

/**
 * 組織のデフォルト制限を特定ユーザーに適用
 */
function applyDefaultRestrictions($pdo, $organization_id, $target_user_id, $manager_id) {
    // 組織のデフォルト制限を取得
    $stmt = $pdo->prepare("SELECT minor_default_restrictions FROM organizations WHERE id = ?");
    $stmt->execute([$organization_id]);
    $org = $stmt->fetch();
    
    if (!$org || empty($org['minor_default_restrictions'])) {
        return;
    }
    
    $defaults = json_decode($org['minor_default_restrictions'], true);
    if (!$defaults) return;
    
    $stmt = $pdo->prepare("
        INSERT INTO parental_restrictions (
            child_user_id, parent_user_id,
            daily_usage_limit_minutes, usage_start_time, usage_end_time,
            search_restricted, dm_restricted, group_join_restricted, call_restricted,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            parent_user_id = VALUES(parent_user_id),
            daily_usage_limit_minutes = VALUES(daily_usage_limit_minutes),
            usage_start_time = VALUES(usage_start_time),
            usage_end_time = VALUES(usage_end_time),
            search_restricted = VALUES(search_restricted),
            dm_restricted = VALUES(dm_restricted),
            group_join_restricted = VALUES(group_join_restricted),
            call_restricted = VALUES(call_restricted),
            is_active = 1
    ");
    $stmt->execute([
        $target_user_id,
        $manager_id,
        $defaults['daily_usage_limit_minutes'] ?? null,
        $defaults['usage_start_time'] ?? null,
        $defaults['usage_end_time'] ?? null,
        $defaults['search_restricted'] ?? 0,
        $defaults['dm_restricted'] ?? 0,
        $defaults['group_join_restricted'] ?? 0,
        $defaults['call_restricted'] ?? 0
    ]);
}

/**
 * 組織のデフォルト制限設定
 */
function setOrgRestrictions($pdo, $organization_id, $input) {
    $restrictions = [
        'daily_usage_limit_minutes' => isset($input['daily_usage_limit_minutes']) ? (int)$input['daily_usage_limit_minutes'] : null,
        'usage_start_time' => $input['usage_start_time'] ?? null,
        'usage_end_time' => $input['usage_end_time'] ?? null,
        'search_restricted' => isset($input['search_restricted']) ? ((int)$input['search_restricted'] ? 1 : 0) : 0,
        'dm_restricted' => isset($input['dm_restricted']) ? ((int)$input['dm_restricted'] ? 1 : 0) : 0,
        'group_join_restricted' => isset($input['group_join_restricted']) ? ((int)$input['group_join_restricted'] ? 1 : 0) : 0,
        'call_restricted' => isset($input['call_restricted']) ? ((int)$input['call_restricted'] ? 1 : 0) : 0
    ];
    
    // minor_default_restrictionsカラムを確認・追加
    try {
        $pdo->query("SELECT minor_default_restrictions FROM organizations LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE organizations ADD COLUMN minor_default_restrictions JSON NULL COMMENT 'デフォルト制限設定'");
    }
    
    $stmt = $pdo->prepare("UPDATE organizations SET minor_default_restrictions = ? WHERE id = ?");
    $stmt->execute([json_encode($restrictions), $organization_id]);
    
    jsonSuccess(['message' => 'デフォルト制限設定を保存しました']);
}

/**
 * 組織のデフォルト制限設定を取得
 */
function getOrgRestrictions($pdo, $organization_id) {
    try {
        $stmt = $pdo->prepare("SELECT minor_default_restrictions FROM organizations WHERE id = ?");
        $stmt->execute([$organization_id]);
        $org = $stmt->fetch();
        
        $restrictions = null;
        if ($org && !empty($org['minor_default_restrictions'])) {
            $restrictions = json_decode($org['minor_default_restrictions'], true);
        }
        
        jsonSuccess(['restrictions' => $restrictions]);
    } catch (PDOException $e) {
        jsonSuccess(['restrictions' => null]);
    }
}

/**
 * 組織の制限を全管理ユーザーに適用
 */
function applyOrgRestrictions($pdo, $organization_id, $manager_id) {
    // 管理ユーザー一覧を取得
    $stmt = $pdo->prepare("SELECT user_id FROM organization_managed_users WHERE organization_id = ? AND is_active = 1");
    $stmt->execute([$organization_id]);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $count = 0;
    foreach ($users as $userId) {
        applyDefaultRestrictions($pdo, $organization_id, $userId, $manager_id);
        $count++;
    }
    
    jsonSuccess(['message' => $count . '人のユーザーに制限を適用しました', 'count' => $count]);
}

/**
 * 特定ユーザーの制限を更新
 */
function updateUserRestrictions($pdo, $organization_id, $manager_id, $input) {
    $target_user_id = (int)($input['user_id'] ?? 0);
    
    if (!$target_user_id) {
        jsonError('ユーザーIDが必要です');
    }
    
    // 権限チェック
    $stmt = $pdo->prepare("SELECT id FROM organization_managed_users WHERE organization_id = ? AND user_id = ? AND is_active = 1");
    $stmt->execute([$organization_id, $target_user_id]);
    if (!$stmt->fetch()) {
        jsonError('このユーザーの制限を変更する権限がありません');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO parental_restrictions (
            child_user_id, parent_user_id,
            daily_usage_limit_minutes, usage_start_time, usage_end_time,
            search_restricted, dm_restricted, group_join_restricted, call_restricted,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            parent_user_id = VALUES(parent_user_id),
            daily_usage_limit_minutes = VALUES(daily_usage_limit_minutes),
            usage_start_time = VALUES(usage_start_time),
            usage_end_time = VALUES(usage_end_time),
            search_restricted = VALUES(search_restricted),
            dm_restricted = VALUES(dm_restricted),
            group_join_restricted = VALUES(group_join_restricted),
            call_restricted = VALUES(call_restricted),
            is_active = 1,
            updated_at = NOW()
    ");
    $stmt->execute([
        $target_user_id,
        $manager_id,
        isset($input['daily_usage_limit_minutes']) ? (int)$input['daily_usage_limit_minutes'] : null,
        $input['usage_start_time'] ?? null,
        $input['usage_end_time'] ?? null,
        isset($input['search_restricted']) ? ((int)$input['search_restricted'] ? 1 : 0) : 0,
        isset($input['dm_restricted']) ? ((int)$input['dm_restricted'] ? 1 : 0) : 0,
        isset($input['group_join_restricted']) ? ((int)$input['group_join_restricted'] ? 1 : 0) : 0,
        isset($input['call_restricted']) ? ((int)$input['call_restricted'] ? 1 : 0) : 0
    ]);
    
    jsonSuccess(['message' => '制限設定を更新しました']);
}

/**
 * 管理対象から削除
 */
function removeManagedUser($pdo, $organization_id, $input) {
    $target_user_id = (int)($input['user_id'] ?? 0);
    
    if (!$target_user_id) {
        jsonError('ユーザーIDが必要です');
    }
    
    $stmt = $pdo->prepare("UPDATE organization_managed_users SET is_active = 0 WHERE organization_id = ? AND user_id = ?");
    $stmt->execute([$organization_id, $target_user_id]);
    
    // 制限も解除
    $stmt = $pdo->prepare("UPDATE parental_restrictions SET is_active = 0 WHERE child_user_id = ?");
    $stmt->execute([$target_user_id]);
    
    jsonSuccess(['message' => '管理対象から削除しました']);
}

// ========================================
// ヘルパー関数
// ========================================

function ensureOrgParentalTables($pdo) {
    // organization_managed_users テーブル
    try {
        $pdo->query("SELECT 1 FROM organization_managed_users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS organization_managed_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                managed_by_user_id INT UNSIGNED NOT NULL,
                guardian_name VARCHAR(100) NULL,
                guardian_contact VARCHAR(200) NULL,
                enrollment_date DATE NULL,
                graduation_date DATE NULL,
                notes TEXT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_org_user (organization_id, user_id),
                INDEX idx_org (organization_id),
                INDEX idx_user (user_id)
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
