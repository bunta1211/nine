<?php
/**
 * ユーザーが管理できる組織一覧API
 * organization_members テーブルを使用
 */
define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$pdo = getDB();
$userId = $_SESSION['user_id'];

try {
    // ユーザーが所属している組織を organization_members から取得
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            o.id, 
            o.name, 
            o.type, 
            o.logo_path,
            om.role as relationship
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE om.user_id = :user_id AND om.left_at IS NULL
        ORDER BY 
            CASE om.role 
                WHEN 'owner' THEN 0 
                WHEN 'admin' THEN 1 
                ELSE 2 
            END,
            o.name
    ");
    $stmt->execute([':user_id' => $userId]);
    $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 数値型キャスト
    foreach ($organizations as &$org) {
        $org['id'] = (int)$org['id'];
    }
    
    // 現在選択中の組織ID（未設定の場合は最初の組織を自動選択してセッションに保存）
    $currentOrgId = $_SESSION['current_org_id'] ?? null;
    
    if (!$currentOrgId && !empty($organizations)) {
        $currentOrgId = $organizations[0]['id'];
        $_SESSION['current_org_id'] = (int)$currentOrgId;
        $_SESSION['current_org_name'] = $organizations[0]['name'];
        $_SESSION['current_org_type'] = $organizations[0]['type'];
    }
    
    echo json_encode([
        'success' => true,
        'organizations' => $organizations,
        'current_org_id' => (int)$currentOrgId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
