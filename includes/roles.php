<?php
/**
 * ロール管理ヘルパー
 * 
 * ## ロールの定義
 * 
 * ### users.role（システムレベルロール）
 * システム全体での権限を表す。ユーザー固有の属性。
 * - system_admin: システム管理者（開発者）。全機能へのアクセス権。
 * - org_admin: 組織管理者。組織を作成・管理できる。
 * - user: 一般ユーザー。基本機能のみ利用可能。
 * 
 * ### organization_members.role（組織レベルロール）
 * 特定の組織内での権限を表す。組織ごとに異なる。
 * - owner: 組織オーナー。組織の全管理権限。削除・解散も可能。
 * - admin: 組織管理者。メンバー管理、グループ管理等が可能。
 * - member: 一般メンバー。組織のリソースを利用可能。
 * - restricted: 制限付きメンバー（未成年等）。親/管理者の承認が必要。
 * 
 * ### 使い分け
 * - システム全体の機能（ユーザー作成、システム設定等）→ users.role を使用
 * - 組織内の機能（メンバー管理、グループ管理等）→ organization_members.role を使用
 */

// システムレベルロール
const SYSTEM_ROLE_ADMIN = 'system_admin';
const SYSTEM_ROLE_ORG_ADMIN = 'org_admin';
const SYSTEM_ROLE_USER = 'user';

// 組織レベルロール
const ORG_ROLE_OWNER = 'owner';
const ORG_ROLE_ADMIN = 'admin';
const ORG_ROLE_MEMBER = 'member';
const ORG_ROLE_RESTRICTED = 'restricted';

/** organization_members に left_at カラムがあるか（キャッシュ） */
function _orgMembersHasLeftAt(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'left_at'");
        $cache = $stmt !== false && $stmt->rowCount() > 0;
        return $cache;
    } catch (Throwable $e) {
        $cache = false;
        return false;
    }
}

/**
 * ユーザーがシステム管理者かどうか
 * @param array $user ユーザー情報（roleを含む）
 * @return bool
 */
function isSystemAdmin($user) {
    return ($user['role'] ?? '') === SYSTEM_ROLE_ADMIN;
}

/**
 * ユーザーが組織管理者以上かどうか
 * @param array $user ユーザー情報（roleを含む）
 * @return bool
 */
function isOrgAdminOrHigher($user) {
    $role = $user['role'] ?? '';
    return $role === SYSTEM_ROLE_ADMIN || $role === SYSTEM_ROLE_ORG_ADMIN;
}

/**
 * 組織内で管理者以上の権限を持つか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return bool
 */
function hasOrgAdminRole($pdo, $userId, $orgId) {
    $stmt = $pdo->prepare("
        SELECT role FROM organization_members 
        WHERE organization_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$orgId, $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) return false;
    
    return in_array($result['role'], [ORG_ROLE_OWNER, ORG_ROLE_ADMIN]);
}

/**
 * 組織内でオーナーかどうか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return bool
 */
function isOrgOwner($pdo, $userId, $orgId) {
    $leftCond = _orgMembersHasLeftAt($pdo) ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT 1 FROM organization_members 
        WHERE organization_id = ? AND user_id = ? AND role = 'owner'" . $leftCond . "
    ");
    $stmt->execute([$orgId, $userId]);
    return (bool)$stmt->fetch();
}

/**
 * 組織内でのユーザーのロールを取得
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return string|null ロール名、または所属していない場合null
 */
function getOrgRole($pdo, $userId, $orgId) {
    $leftCond = _orgMembersHasLeftAt($pdo) ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT role FROM organization_members 
        WHERE organization_id = ? AND user_id = ?" . $leftCond . "
    ");
    $stmt->execute([$orgId, $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['role'] : null;
}

/**
 * 組織に所属しているかどうか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return bool
 */
function isOrgMember($pdo, $userId, $orgId) {
    $leftCond = _orgMembersHasLeftAt($pdo) ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT 1 FROM organization_members 
        WHERE organization_id = ? AND user_id = ?" . $leftCond . "
    ");
    $stmt->execute([$orgId, $userId]);
    return (bool)$stmt->fetch();
}

/**
 * ユーザーが制限付きメンバー（未成年等）かどうか
 * @param PDO $pdo
 * @param int $userId
 * @param int $orgId
 * @return bool
 */
function isRestrictedMember($pdo, $userId, $orgId) {
    $leftCond = _orgMembersHasLeftAt($pdo) ? ' AND left_at IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT 1 FROM organization_members 
        WHERE organization_id = ? AND user_id = ? AND role = 'restricted'" . $leftCond . "
    ");
    $stmt->execute([$orgId, $userId]);
    return (bool)$stmt->fetch();
}

/**
 * ロールの階層（数字が大きいほど権限が高い）
 */
function getOrgRoleLevel($role) {
    $levels = [
        ORG_ROLE_RESTRICTED => 0,
        ORG_ROLE_MEMBER => 1,
        ORG_ROLE_ADMIN => 2,
        ORG_ROLE_OWNER => 3
    ];
    return $levels[$role] ?? -1;
}

/**
 * 自分より上位または同等のロールを持つユーザーを変更できるかチェック
 * @param string $myRole 自分のロール
 * @param string $targetRole 対象のロール
 * @return bool
 */
function canManageRole($myRole, $targetRole) {
    $myLevel = getOrgRoleLevel($myRole);
    $targetLevel = getOrgRoleLevel($targetRole);
    
    // 自分より下位のロールのみ管理可能
    return $myLevel > $targetLevel;
}


