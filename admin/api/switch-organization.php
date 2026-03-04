<?php
/**
 * 管理する組織を切り替えるAPI
 * organization_members テーブルを使用
 */
define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orgId = isset($data['organization_id']) ? (int)$data['organization_id'] : 0;

if (!$orgId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '組織IDが必要です']);
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

try {
    // ユーザーがこの組織に所属しているか organization_members で確認
    $stmt = $pdo->prepare("
        SELECT o.id, o.name, o.type, om.role
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE o.id = :org_id 
          AND om.user_id = :user_id 
          AND om.left_at IS NULL
    ");
    $stmt->execute([
        ':org_id' => $orgId,
        ':user_id' => $userId
    ]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$org) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'この組織を管理する権限がありません']);
        exit;
    }
    
    // セッションに現在の組織を保存
    $_SESSION['current_org_id'] = (int)$org['id'];
    $_SESSION['current_org_name'] = $org['name'];
    $_SESSION['current_org_type'] = $org['type'];
    $_SESSION['current_org_role'] = $org['role'];
    
    echo json_encode([
        'success' => true,
        'message' => '組織を切り替えました',
        'organization' => [
            'id' => (int)$org['id'],
            'name' => $org['name'],
            'type' => $org['type'],
            'role' => $org['role']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


