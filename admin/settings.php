<?php
/**
 * 管理パネル - システム設定
 * 仕様書: 13_管理機能.md
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

requireLogin();
requireSystemAdmin();

$pdo = getDB();

// 設定を取得（system_settingsテーブルがあれば）
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // テーブルがない場合はデフォルト値を使用
}

// デフォルト設定
$default_settings = [
    'site_name' => 'Social9',
    'site_description' => 'みんなのためのSNSアプリ',
    'maintenance_mode' => '0',
    'registration_enabled' => '1',
    'max_file_size' => '10',
    'max_group_members' => '50',
    'matching_enabled' => '0',
    'investor_mode_enabled' => '1'
];

$settings = array_merge($default_settings, $settings);

$success_message = '';
$error_message = '';

/**
 * system_settings テーブルが無ければ作成し、レコードが無ければデフォルトを挿入する
 */
function ensureSystemSettingsTable(PDO $pdo, array $default_settings) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $stmt = $pdo->query("SELECT COUNT(*) FROM system_settings");
    if ($stmt && (int)$stmt->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())");
        foreach ($default_settings as $k => $v) {
            $ins->execute([$k, $v]);
        }
    }
}

// 設定を保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ensureSystemSettingsTable($pdo, $default_settings);
        foreach ($_POST as $key => $value) {
            if (array_key_exists($key, $default_settings)) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $value]);
                $settings[$key] = $value;
            }
        }
        $success_message = '設定を保存しました。';
    } catch (PDOException $e) {
        $error_message = '設定の保存に失敗しました。' . (defined('DEBUG') && DEBUG ? $e->getMessage() : 'データベースを確認してください。');
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システム設定 - 管理パネル | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); font-family: 'Hiragino Sans', 'Meiryo', sans-serif; }
        <?php adminSidebarCSS(); ?>
        
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h2 { font-size: 24px; }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        
        .toggle-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .toggle-group:last-child { border-bottom: none; }
        .toggle-label .title { font-weight: 500; }
        .toggle-label .desc { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        
        .toggle {
            position: relative;
            width: 50px;
            height: 28px;
        }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #e5e7eb;
            border-radius: 28px;
            transition: 0.3s;
        }
        .toggle .slider:before {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .toggle input:checked + .slider { background: var(--primary); }
        .toggle input:checked + .slider:before { transform: translateX(22px); }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert.success { background: #dcfce7; color: #166534; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        
        .info-box {
            background: var(--bg-secondary);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .info-box h4 { font-size: 14px; margin-bottom: 8px; }
        .info-box p { font-size: 13px; color: var(--text-muted); }
        
        .btn-row { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>⚙️ システム設定</h2>
            </div>
            
            <?php if ($success_message): ?>
            <div class="alert success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <!-- 基本設定 -->
                <div class="card">
                    <div class="card-header">📋 基本設定</div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>サイト名</label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label>サイト説明</label>
                            <textarea name="site_description" rows="2"><?= htmlspecialchars($settings['site_description']) ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- 機能設定 -->
                <div class="card">
                    <div class="card-header">🔧 機能設定</div>
                    <div class="card-body">
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <div class="title">メンテナンスモード</div>
                                <div class="desc">有効にすると管理者以外はアクセスできなくなります</div>
                            </div>
                            <label class="toggle">
                                <input type="hidden" name="maintenance_mode" value="0">
                                <input type="checkbox" name="maintenance_mode" value="1" <?= $settings['maintenance_mode'] == '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <div class="title">新規登録</div>
                                <div class="desc">新規ユーザー登録を許可するかどうか</div>
                            </div>
                            <label class="toggle">
                                <input type="hidden" name="registration_enabled" value="0">
                                <input type="checkbox" name="registration_enabled" value="1" <?= $settings['registration_enabled'] == '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <div class="title">マッチング機能</div>
                                <div class="desc">ユニバーサルマッチング機能を有効にする（50万ユーザー到達後に有効化推奨）</div>
                            </div>
                            <label class="toggle">
                                <input type="hidden" name="matching_enabled" value="0">
                                <input type="checkbox" name="matching_enabled" value="1" <?= $settings['matching_enabled'] == '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <div class="title">投資家モード</div>
                                <div class="desc">特定投資家向けの別エントランスを有効にする</div>
                            </div>
                            <label class="toggle">
                                <input type="hidden" name="investor_mode_enabled" value="0">
                                <input type="checkbox" name="investor_mode_enabled" value="1" <?= $settings['investor_mode_enabled'] == '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- 制限設定 -->
                <div class="card">
                    <div class="card-header">📏 制限設定</div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>最大ファイルサイズ（MB）</label>
                            <input type="number" name="max_file_size" value="<?= htmlspecialchars($settings['max_file_size']) ?>" min="1" max="500">
                            <div class="form-hint">一般ユーザーがアップロードできる最大ファイルサイズ</div>
                        </div>
                        <div class="form-group">
                            <label>最大グループメンバー数</label>
                            <input type="number" name="max_group_members" value="<?= htmlspecialchars($settings['max_group_members']) ?>" min="2" max="500">
                            <div class="form-hint">通常グループの最大参加人数（組織ルームは除く）</div>
                        </div>
                    </div>
                </div>
                
                <!-- システム情報 -->
                <div class="card">
                    <div class="card-header">ℹ️ システム情報</div>
                    <div class="card-body">
                        <div class="info-box">
                            <h4>アプリケーション</h4>
                            <p>Social9 v<?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?></p>
                        </div>
                        <div class="info-box">
                            <h4>環境</h4>
                            <p><?= defined('APP_ENV') ? APP_ENV : 'development' ?></p>
                        </div>
                        <div class="info-box">
                            <h4>PHP バージョン</h4>
                            <p><?= PHP_VERSION ?></p>
                        </div>
                        <div class="info-box">
                            <h4>サーバー時刻</h4>
                            <p><?= date('Y-m-d H:i:s') ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">設定を保存</button>
                </div>
            </form>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>








