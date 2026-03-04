<?php
/**
 * ブラウザテスト用ヘルパーAPI
 * 
 * AIがブラウザテストを行う際に使用
 * 開発環境のみで有効
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

header('Content-Type: application/json');

// 本番環境では無効
if (defined('APP_ENV') && APP_ENV === 'production') {
    // テスト用ログイン機能は無効、他の機能は許可
    $action = $_GET['action'] ?? '';
    if ($action === 'login') {
        http_response_code(403);
        echo json_encode(['error' => 'Test login disabled in production']);
        exit;
    }
}

$action = $_GET['action'] ?? 'status';

switch ($action) {
    
    // ========================================
    // 現在の状態を取得
    // ========================================
    case 'status':
        echo json_encode([
            'success' => true,
            'logged_in' => isset($_SESSION['user_id']),
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'session_id' => session_id(),
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'environment' => defined('APP_ENV') ? APP_ENV : 'unknown'
        ], JSON_PRETTY_PRINT);
        break;
    
    // ========================================
    // ページ情報を取得（AIがどのページか判断するため）
    // ========================================
    case 'page_info':
        $url = $_GET['url'] ?? '';
        $info = getPageInfo($url);
        echo json_encode([
            'success' => true,
            'page_info' => $info
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
    
    // ========================================
    // テスト用ログイン（開発環境のみ）
    // ========================================
    case 'login':
        $username = $_POST['username'] ?? $_GET['username'] ?? '';
        $password = $_POST['password'] ?? $_GET['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Username and password required']);
            break;
        }
        
        // ユーザー認証
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            echo json_encode([
                'success' => true,
                'message' => 'Logged in successfully',
                'user_id' => $user['id'],
                'username' => $user['username']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
        break;
    
    // ========================================
    // ログアウト
    // ========================================
    case 'logout':
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out']);
        break;
    
    // ========================================
    // 画面要素の説明を取得
    // ========================================
    case 'elements':
        echo json_encode([
            'success' => true,
            'pages' => [
                'index.php' => [
                    'description' => 'ログイン画面',
                    'elements' => [
                        'input[name="username"]' => 'ユーザー名入力',
                        'input[name="password"]' => 'パスワード入力',
                        'button[type="submit"]' => 'ログインボタン'
                    ]
                ],
                'chat.php' => [
                    'description' => 'メインチャット画面',
                    'elements' => [
                        '.sidebar' => '左サイドバー（会話一覧）',
                        '.center-panel' => '中央パネル（メッセージ表示）',
                        '.right-panel' => '右パネル（メンバー一覧）',
                        '#messageInput' => 'メッセージ入力欄',
                        '.send-btn' => '送信ボタン',
                        '.conversation-item' => '会話アイテム（クリックで選択）',
                        '.message-card' => 'メッセージカード',
                        '.topbar' => 'トップバー（メニュー）'
                    ]
                ],
                'settings.php' => [
                    'description' => '設定画面',
                    'elements' => [
                        '.settings-nav' => '設定ナビゲーション',
                        '.settings-content' => '設定コンテンツ'
                    ]
                ],
                'design.php' => [
                    'description' => 'デザイン設定画面',
                    'elements' => [
                        '.theme-card' => 'テーマカード（クリックで選択）',
                        '.background-options' => '背景オプション'
                    ]
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
    
    // ========================================
    // コンソールエラーを取得
    // ========================================
    case 'errors':
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'error_logs'");
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => true, 'errors' => [], 'message' => 'Error logs table not initialized']);
                break;
            }
            
            $limit = min((int)($_GET['limit'] ?? 20), 100);
            $stmt = $pdo->prepare("
                SELECT id, error_type, error_message, url, occurrence_count, 
                       first_occurred_at, last_occurred_at, is_resolved
                FROM error_logs 
                ORDER BY last_occurred_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $errors = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'count' => count($errors),
                'errors' => $errors
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // テストシナリオ一覧
    // ========================================
    case 'scenarios':
        echo json_encode([
            'success' => true,
            'scenarios' => [
                [
                    'name' => 'ログインテスト',
                    'steps' => [
                        '1. index.php を開く',
                        '2. ユーザー名とパスワードを入力',
                        '3. ログインボタンをクリック',
                        '4. chat.php にリダイレクトされることを確認'
                    ]
                ],
                [
                    'name' => 'メッセージ送信テスト',
                    'steps' => [
                        '1. chat.php を開く',
                        '2. 会話を選択',
                        '3. メッセージ入力欄にテキストを入力',
                        '4. 送信ボタンをクリックまたはEnter',
                        '5. メッセージが表示されることを確認'
                    ]
                ],
                [
                    'name' => 'レスポンシブテスト',
                    'steps' => [
                        '1. chat.php を開く',
                        '2. ウィンドウサイズを375x667に変更（モバイル）',
                        '3. レイアウトが崩れていないか確認',
                        '4. サイドバーの開閉が動作するか確認'
                    ]
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

/**
 * URLからページ情報を推測
 */
function getPageInfo($url) {
    $pages = [
        'index.php' => ['name' => 'ログイン', 'auth_required' => false],
        'chat.php' => ['name' => 'チャット', 'auth_required' => true],
        'settings.php' => ['name' => '設定', 'auth_required' => true],
        'design.php' => ['name' => 'デザイン', 'auth_required' => true],
        'tasks.php' => ['name' => 'タスク', 'auth_required' => true],
        'memos.php' => ['name' => 'メモ', 'auth_required' => true],
        'notifications.php' => ['name' => '通知', 'auth_required' => true],
        'admin/' => ['name' => '管理画面', 'auth_required' => true, 'admin_only' => true],
    ];
    
    foreach ($pages as $path => $info) {
        if (strpos($url, $path) !== false) {
            return $info;
        }
    }
    
    return ['name' => '不明', 'auth_required' => false];
}
