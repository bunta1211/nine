<?php
/**
 * オンラインステータスAPI
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// ログイン確認
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ログインが必要です']);
    exit;
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// リクエストボディを取得
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'heartbeat':
        // ハートビート - オンライン維持
        $stmt = $pdo->prepare("
            UPDATE users 
            SET online_status = CASE 
                WHEN online_status = 'offline' THEN 'online'
                ELSE online_status 
            END,
            last_seen = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'online':
        $pdo->prepare("UPDATE users SET online_status = 'online', last_seen = NOW() WHERE id = ?")
            ->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'away':
        $pdo->prepare("UPDATE users SET online_status = 'away', last_seen = NOW() WHERE id = ?")
            ->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'busy':
        $pdo->prepare("UPDATE users SET online_status = 'busy', last_seen = NOW() WHERE id = ?")
            ->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'offline':
        $pdo->prepare("UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = ?")
            ->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'custom':
        // カスタムステータス設定
        $custom_status = trim($input['status'] ?? '');
        $emoji = trim($input['emoji'] ?? '');
        $expires_minutes = (int)($input['expires_minutes'] ?? 0);
        
        if (mb_strlen($custom_status) > 100) {
            echo json_encode(['success' => false, 'message' => 'ステータスが長すぎます']);
            exit;
        }
        
        $expires_at = null;
        if ($expires_minutes > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_minutes} minutes"));
        }
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET custom_status = ?, custom_status_emoji = ?, status_expires_at = ?, last_seen = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$custom_status ?: null, $emoji ?: null, $expires_at, $user_id]);
        
        echo json_encode(['success' => true]);
        break;
        
    case 'clear_custom':
        $pdo->prepare("
            UPDATE users 
            SET custom_status = NULL, custom_status_emoji = NULL, status_expires_at = NULL
            WHERE id = ?
        ")->execute([$user_id]);
        
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '不明なアクションです']);
}

// 5分以上アクティビティがないユーザーをオフラインに
$pdo->exec("
    UPDATE users 
    SET online_status = 'offline' 
    WHERE online_status != 'offline' 
    AND last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");

// 期限切れのカスタムステータスをクリア
$pdo->exec("
    UPDATE users 
    SET custom_status = NULL, custom_status_emoji = NULL, status_expires_at = NULL
    WHERE status_expires_at IS NOT NULL AND status_expires_at < NOW()
");








