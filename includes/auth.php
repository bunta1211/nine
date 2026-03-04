<?php
/**
 * 認証ミドルウェア（組織管理画面用）
 * 既存のconfig/session.phpと互換性を保ちつつ、組織管理画面で使用
 */

// 既存のセッション設定をインクルード
require_once __DIR__ . '/../config/session.php';

/**
 * ログイン中のユーザー情報を取得
 */
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'organization_id' => $_SESSION['organization_id'] ?? 1,
        'email' => $_SESSION['email'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? $_SESSION['display_name'] ?? '',
        'display_name' => $_SESSION['display_name'] ?? '',
        'avatar' => $_SESSION['avatar'] ?? '',
        'is_org_admin' => $_SESSION['is_org_admin'] ?? 0,
        'role' => $_SESSION['role'] ?? 'user'
    ];
}

/**
 * 組織管理者かチェック（既存のisOrgAdminUser()のエイリアス）
 */
function isOrgAdmin() {
    return isOrgAdminUser();
}






