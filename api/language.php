<?php
/**
 * 言語設定API
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lang.php';

header('Content-Type: application/json; charset=utf-8');

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // 現在の言語を取得
    echo json_encode([
        'success' => true,
        'language' => getCurrentLanguage(),
        'options' => getLanguageOptions()
    ]);
    exit;
}

if ($method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $lang = $input['language'] ?? '';
        
        if (!setLanguage($lang)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid language']);
            exit;
        }
        
        // ユーザー設定をDBにも保存（オプション・失敗してもセッションは更新済み）
        try {
            $db = getDB();
            $stmt = $db->prepare("UPDATE users SET language = ? WHERE id = ?");
            $stmt->execute([$lang, $_SESSION['user_id']]);
        } catch (Throwable $e1) {
            try {
                $db = getDB();
                $stmt = $db->prepare("UPDATE users SET display_language = ? WHERE id = ?");
                $stmt->execute([$lang, $_SESSION['user_id']]);
            } catch (Throwable $e2) {
                // どちらも失敗してもセッションには保存済みなので続行
            }
        }
        
        $reloadMessages = [
            'ja' => '言語を変更しました。ページをリロードしてください。',
            'en' => 'Language changed. Please reload the page.',
            'zh' => '语言已更改。请刷新页面。'
        ];
        $reloadButtons = ['ja' => '今すぐリロード', 'en' => 'Reload Now', 'zh' => '立即刷新'];
        
        echo json_encode([
            'success' => true,
            'language' => $lang,
            'message' => $reloadMessages[$lang] ?? $reloadMessages['en'],
            'reload_button' => $reloadButtons[$lang] ?? $reloadButtons['en']
        ]);
    } catch (Throwable $e) {
        error_log('language.php POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);

