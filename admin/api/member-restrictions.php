<?php
/**
 * メンバー利用制限設定 API
 * 組織管理者が子どもメンバーの利用時間などを設定できる
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/roles.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// 現在の組織ID取得
$currentOrgId = $_SESSION['current_org_id'] ?? null;
if (!$currentOrgId) {
    errorResponse('組織が選択されていません');
}

$userId = $_SESSION['user_id'];

// 組織内で管理者権限を持っているか確認
if (!hasOrgAdminRole($pdo, $userId, $currentOrgId)) {
    errorResponse('管理者権限が必要です', 403);
}

try {
    switch ($method) {
        case 'GET':
            // メンバーの制限設定を取得
            getRestrictions($pdo, $currentOrgId);
            break;
            
        case 'PUT':
            // 制限設定を更新
            $data = json_decode(file_get_contents('php://input'), true);
            updateRestrictions($pdo, $currentOrgId, $data);
            break;
            
        default:
            http_response_code(405);
            errorResponse('Method not allowed');
    }
} catch (PDOException $e) {
    error_log('Member restrictions API error: ' . $e->getMessage());
    errorResponse('データベースエラー', 500);
}

/**
 * メンバーの制限設定を取得
 */
function getRestrictions($pdo, $orgId) {
    $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
    
    if ($memberId) {
        // 特定メンバーの設定
        $stmt = $pdo->prepare("
            SELECT 
                om.user_id,
                u.display_name,
                u.is_minor,
                om.role,
                om.usage_start_time,
                om.usage_end_time,
                om.daily_limit_minutes,
                om.external_contact,
                om.call_restriction,
                om.can_view_messages,
                om.can_delete_messages,
                om.can_create_groups,
                om.can_leave_org
            FROM organization_members om
            INNER JOIN users u ON om.user_id = u.id
            WHERE om.organization_id = ? AND om.user_id = ? AND om.left_at IS NULL
        ");
        $stmt->execute([$orgId, $memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$member) {
            errorResponse('メンバーが見つかりません', 404);
        }
        
        // 数値キャスト
        $member['user_id'] = (int)$member['user_id'];
        $member['is_minor'] = (int)($member['is_minor'] ?? 0);
        $member['daily_limit_minutes'] = (int)($member['daily_limit_minutes'] ?? 120);
        $member['external_contact'] = (int)($member['external_contact'] ?? 0);
        $member['call_restriction'] = $member['call_restriction'] ?? 'none';
        $member['can_view_messages'] = (int)($member['can_view_messages'] ?? 1);
        $member['can_delete_messages'] = (int)($member['can_delete_messages'] ?? 1);
        $member['can_create_groups'] = (int)($member['can_create_groups'] ?? 0);
        $member['can_leave_org'] = (int)($member['can_leave_org'] ?? 0);
        
        successResponse(['member' => $member]);
    } else {
        // 全メンバー（未成年または制限付き）の設定一覧
        $stmt = $pdo->prepare("
            SELECT 
                om.user_id,
                u.display_name,
                u.is_minor,
                om.role,
                om.usage_start_time,
                om.usage_end_time,
                om.daily_limit_minutes
            FROM organization_members om
            INNER JOIN users u ON om.user_id = u.id
            WHERE om.organization_id = ? 
              AND om.left_at IS NULL
              AND (om.role = 'restricted' OR u.is_minor = 1)
            ORDER BY u.display_name
        ");
        $stmt->execute([$orgId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($members as &$m) {
            $m['user_id'] = (int)$m['user_id'];
            $m['is_minor'] = (int)($m['is_minor'] ?? 0);
            $m['daily_limit_minutes'] = (int)($m['daily_limit_minutes'] ?? 120);
        }
        
        successResponse(['members' => $members]);
    }
}

/**
 * 制限設定を更新
 */
function updateRestrictions($pdo, $orgId, $data) {
    $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;
    
    if (!$memberId) {
        errorResponse('メンバーIDが必要です');
    }
    
    // メンバーが組織に存在するか確認
    $stmt = $pdo->prepare("
        SELECT role FROM organization_members 
        WHERE organization_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$orgId, $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        errorResponse('メンバーが見つかりません', 404);
    }
    
    // 更新可能なフィールド
    $allowedFields = [
        'usage_start_time',      // 利用開始時間（TIME形式）
        'usage_end_time',        // 利用終了時間（TIME形式）
        'daily_limit_minutes',   // 1日の利用制限（分）
        'external_contact',      // 組織外ユーザーへのコンタクト許可
        'call_restriction',      // 通話制限（none, org_only, approved_only）
        'can_view_messages',     // メッセージ閲覧可否
        'can_delete_messages',   // メッセージ削除可否
        'can_create_groups',     // グループ作成可否
        'can_leave_org'          // 組織退出可否
    ];
    
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = $data[$field];
            
            // バリデーション
            switch ($field) {
                case 'usage_start_time':
                case 'usage_end_time':
                    // TIME形式チェック
                    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value)) {
                        errorResponse("$field は有効な時間形式（HH:MM）で指定してください");
                    }
                    break;
                    
                case 'daily_limit_minutes':
                    $value = max(0, min(1440, (int)$value)); // 0-1440分
                    break;
                    
                case 'call_restriction':
                    if (!in_array($value, ['none', 'org_only', 'approved_only'])) {
                        errorResponse("call_restriction は none, org_only, approved_only のいずれかで指定してください");
                    }
                    break;
                    
                case 'external_contact':
                case 'can_view_messages':
                case 'can_delete_messages':
                case 'can_create_groups':
                case 'can_leave_org':
                    $value = $value ? 1 : 0;
                    break;
            }
            
            $updates[] = "$field = ?";
            $params[] = $value;
        }
    }
    
    if (empty($updates)) {
        errorResponse('更新する項目がありません');
    }
    
    $params[] = $orgId;
    $params[] = $memberId;
    
    $sql = "UPDATE organization_members SET " . implode(', ', $updates) . 
           " WHERE organization_id = ? AND user_id = ? AND left_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    successResponse([], '制限設定を更新しました');
}

// successResponse/errorResponse は config/database.php で定義済み
// フォールバック用（単独で使用する場合のみ）
if (!function_exists('successResponse')) {
    function successResponse($data = [], $message = '') {
        $response = ['success' => true];
        if ($message) {
            $response['message'] = $message;
        }
        echo json_encode(array_merge($response, $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

