<?php
/**
 * Guild 初期セットアップページ
 * 
 * このページでデータベースのセットアップとシステム管理者の設定を行います。
 * セットアップ完了後、このファイルは削除してください。
 */

// エラー表示を有効化（デバッグ用）
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// データベース設定を読み込み（親ディレクトリのSocial9設定を使用）
require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';
$step = $_GET['step'] ?? '1';
$users = [];
$setupComplete = false;

// テーブルが存在するかチェック
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SELECT 1 FROM $tableName LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$pdo = getDB();

// ステップ1: テーブル作成
if ($step === '1') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_tables'])) {
        try {
            $sql = file_get_contents(__DIR__ . '/database/schema.sql');
            
            // 複数のSQLステートメントを分割して実行
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            foreach ($statements as $statement) {
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    $pdo->exec($statement);
                }
            }
            
            $message = 'テーブルを作成しました。';
            $step = '2';
        } catch (PDOException $e) {
            $error = 'テーブル作成エラー: ' . $e->getMessage();
        }
    }
    
    // テーブル存在チェック
    if (tableExists($pdo, 'guild_guilds')) {
        $message = 'テーブルは既に存在します。';
        $step = '2';
    }
}

// ステップ2: システム管理者設定
if ($step === '2') {
    // ユーザー一覧を取得（Social9のusersテーブルから）
    $stmt = $pdo->query("SELECT id, email, display_name FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_admin'])) {
        $adminUserId = (int)$_POST['admin_user_id'];
        
        if ($adminUserId > 0) {
            try {
                // システム管理者権限を設定
                $stmt = $pdo->prepare("
                    INSERT INTO guild_system_permissions 
                    (user_id, is_system_admin, can_manage_users, can_manage_guilds, 
                     can_approve_large_requests, can_approve_advances, can_view_all_data, 
                     can_export_data, can_manage_fiscal_year, can_register_qualifications)
                    VALUES (?, 1, 1, 1, 1, 1, 1, 1, 1, 1)
                    ON DUPLICATE KEY UPDATE
                        is_system_admin = 1,
                        can_manage_users = 1,
                        can_manage_guilds = 1,
                        can_approve_large_requests = 1,
                        can_approve_advances = 1,
                        can_view_all_data = 1,
                        can_export_data = 1,
                        can_manage_fiscal_year = 1,
                        can_register_qualifications = 1
                ");
                $stmt->execute([$adminUserId]);
                
                // 年度を有効化
                $pdo->exec("UPDATE guild_fiscal_years SET status = 'active', opened_at = NOW() WHERE fiscal_year = 2026");
                
                $message = 'システム管理者を設定しました。';
                $step = '3';
            } catch (PDOException $e) {
                $error = 'エラー: ' . $e->getMessage();
            }
        } else {
            $error = 'ユーザーを選択してください。';
        }
    }
}

// ステップ3: 完了
if ($step === '3') {
    $setupComplete = true;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guild セットアップ</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        h1 {
            text-align: center;
            margin-bottom: 10px;
            color: #1e293b;
        }
        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 30px;
        }
        .steps {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            gap: 20px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
        }
        .step-item.active { color: #6366f1; font-weight: 600; }
        .step-item.done { color: #22c55e; }
        .step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .step-item.active .step-num { background: #6366f1; color: white; }
        .step-item.done .step-num { background: #22c55e; color: white; }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: #374151; }
        select, input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
        }
        select:focus, input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        .btn:hover { background: #4f46e5; }
        .btn-success { background: #22c55e; }
        .btn-success:hover { background: #16a34a; }
        .info-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-box h3 { margin-bottom: 10px; color: #374151; }
        .info-box ul { margin-left: 20px; color: #64748b; }
        .info-box li { margin-bottom: 5px; }
        .complete-icon {
            text-align: center;
            font-size: 64px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🍀 Guild</h1>
        <p class="subtitle">報酬分配システム セットアップ</p>
        
        <div class="steps">
            <div class="step-item <?= $step >= '1' ? ($step > '1' ? 'done' : 'active') : '' ?>">
                <span class="step-num"><?= $step > '1' ? '✓' : '1' ?></span>
                <span>テーブル作成</span>
            </div>
            <div class="step-item <?= $step >= '2' ? ($step > '2' ? 'done' : 'active') : '' ?>">
                <span class="step-num"><?= $step > '2' ? '✓' : '2' ?></span>
                <span>管理者設定</span>
            </div>
            <div class="step-item <?= $step >= '3' ? 'active' : '' ?>">
                <span class="step-num">3</span>
                <span>完了</span>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($step === '1'): ?>
        <div class="info-box">
            <h3>ステップ1: データベーステーブルの作成</h3>
            <ul>
                <li>Guildに必要なテーブルを作成します</li>
                <li>Social9と同じデータベースを使用します</li>
                <li>既存のテーブルには影響しません</li>
            </ul>
        </div>
        <form method="POST">
            <button type="submit" name="create_tables" class="btn">テーブルを作成</button>
        </form>
        <?php endif; ?>
        
        <?php if ($step === '2'): ?>
        <div class="info-box">
            <h3>ステップ2: システム管理者の設定</h3>
            <ul>
                <li>Guildの全機能を管理できるユーザーを選択します</li>
                <li>後から他のユーザーにも権限を付与できます</li>
            </ul>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>システム管理者を選択</label>
                <select name="admin_user_id" required>
                    <option value="">-- ユーザーを選択 --</option>
                    <?php foreach ($users as $user): ?>
                    <option value="<?= (int)$user['id'] ?>">
                        <?= htmlspecialchars($user['display_name']) ?> (<?= htmlspecialchars($user['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="set_admin" class="btn">管理者を設定</button>
        </form>
        <?php endif; ?>
        
        <?php if ($step === '3'): ?>
        <div class="complete-icon">🎉</div>
        <div class="info-box">
            <h3>セットアップ完了！</h3>
            <ul>
                <li>Guildアプリの準備が完了しました</li>
                <li>設定したシステム管理者でログインしてください</li>
                <li><strong>重要:</strong> セキュリティのため、このファイル (setup.php) を削除してください</li>
            </ul>
        </div>
        <a href="index.php" class="btn btn-success">Guildにログイン →</a>
        <?php endif; ?>
    </div>
</body>
</html>
