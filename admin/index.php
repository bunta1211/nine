<?php
/**
 * 管理パネル - ダッシュボード
 * 仕様書: 13_管理機能.md
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/access_logger.php';

$currentPage = 'index';
require_once __DIR__ . '/_sidebar.php';

// 管理者権限チェック（developer, admin, system_admin, super_admin, org_admin）
if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();

// 統計情報を取得
$stats = [
    'total_users' => 0,
    'new_users_week' => 0,
    'online_users' => 0,
    'active_users_24h' => 0,
    'messages_today' => 0,
    'calls_today' => 0,
    'pending_reports' => 0,
    'active_requests' => 0,
    'total_organizations' => 0,
    'total_group_chats' => 0,
    'today_access' => 0,
    'search_referral' => 0,
    'bounce_rate' => null,
];

try {
    // ユーザー数
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $stats['total_users'] = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_users_week'] = $stmt->fetch()['count'];

    // オンラインユーザー
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE online_status = 'online'");
    $stats['online_users'] = $stmt->fetch()['count'];

    // 直近24時間アクティブユーザー（last_activity がある場合）
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        if ($stmt) {
            $stats['active_users_24h'] = (int)$stmt->fetch()['count'];
        }
    } catch (PDOException $e) {}
    if ($stats['active_users_24h'] === 0) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT sender_id) as count FROM messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            if ($stmt) {
                $stats['active_users_24h'] = (int)$stmt->fetch()['count'];
            }
        } catch (PDOException $e) {}
    }

    // メッセージ数
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stats['messages_today'] = $stmt->fetch()['count'];
} catch (PDOException $e) {
    // テーブルがなくても継続
}

try {
    // 通話数
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM calls WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stats['calls_today'] = $stmt->fetch()['count'];
} catch (PDOException $e) {}

try {
    // 通報数（未処理）
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reports WHERE status = 'pending'");
    $stats['pending_reports'] = $stmt->fetch()['count'];
} catch (PDOException $e) {}

try {
    // マッチングリクエスト
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM service_requests WHERE status = 'active'");
    $stats['active_requests'] = $stmt->fetch()['count'];
} catch (PDOException $e) {}

try {
    // 組織数
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM organizations");
    $stats['total_organizations'] = (int)$stmt->fetch()['count'];
} catch (PDOException $e) {}

try {
    // グループチャット数（type='group' の会話）
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM conversations WHERE type = 'group'");
    $stats['total_group_chats'] = (int)$stmt->fetch()['count'];
} catch (PDOException $e) {}

// 本日のアクセス・検索経由・離脱率（access_log テーブル）
$accessStats = get_access_stats_today($pdo);
$stats['today_access'] = $accessStats['today_access'];
$stats['search_referral'] = $accessStats['search_referral'];
$stats['bounce_rate'] = $accessStats['bounce_rate'];

// 最近のアクティビティ
$stmt = $pdo->query("
    SELECT 
        'user' as type,
        display_name as title,
        '新規登録' as action,
        created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
");
$recent_activities = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理パネル - Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        <?php adminSidebarCSS(); ?>
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            font-size: 24px;
            color: var(--text-primary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }
        
        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        
        .stat-card .icon.blue { background: #e0e7ff; }
        .stat-card .icon.green { background: #d1fae5; }
        .stat-card .icon.yellow { background: #fef3c7; }
        .stat-card .icon.red { background: #fee2e2; }
        .stat-card .icon.purple { background: #ede9fe; }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-card .label {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
        }
        
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            font-weight: 600;
        }
        
        .card-body { padding: 20px; }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .activity-item:last-child { border-bottom: none; }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .activity-content .title { font-weight: 500; }
        .activity-content .meta { font-size: 13px; color: var(--text-muted); }
        
        .quick-actions { display: flex; flex-direction: column; gap: 10px; }
        .quick-actions a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--bg-secondary);
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            transition: background 0.2s;
        }
        .quick-actions a:hover { background: var(--border-light); }
        
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>ダッシュボード</h2>
            </div>
            
            <div class="admin-dashboard-notice" style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; font-size: 14px; line-height: 1.6;">
                <p style="margin: 0 0 8px 0; font-weight: 600;">現在は試験運用の段階です。</p>
                <p style="margin: 0 0 8px 0;">2026年4月1日にプレオープンする予定です。</p>
                <p style="margin: 0;">サーバーへの負荷が高いサービス、容量を確保する実費が必要なサービス等に関する一部有料サービスの提供は、準備ができ次第開始します。</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon blue">👥</div>
                    <div class="value"><?= number_format($stats['total_users']) ?></div>
                    <div class="label">総ユーザー数</div>
                </div>
                <div class="stat-card">
                    <div class="icon green">🟢</div>
                    <div class="value"><?= number_format($stats['online_users']) ?></div>
                    <div class="label">オンラインユーザー</div>
                </div>
                <div class="stat-card">
                    <div class="icon blue">📊</div>
                    <div class="value"><?= number_format($stats['active_users_24h']) ?></div>
                    <div class="label">直近24時間アクティブ</div>
                </div>
                <div class="stat-card">
                    <div class="icon yellow">🌐</div>
                    <div class="value"><?= number_format($stats['today_access']) ?></div>
                    <div class="label">本日のアクセス</div>
                </div>
                <div class="stat-card">
                    <div class="icon purple">🔍</div>
                    <div class="value">検索 <?= number_format($stats['search_referral']) ?> ・ 離脱 <?= $stats['bounce_rate'] !== null ? number_format($stats['bounce_rate'], 1) . '%' : '—' ?></div>
                    <div class="label">検索経由・離脱率</div>
                </div>
                <div class="stat-card">
                    <div class="icon yellow">💬</div>
                    <div class="value"><?= number_format($stats['messages_today']) ?></div>
                    <div class="label">今日のメッセージ</div>
                </div>
                <div class="stat-card">
                    <div class="icon purple">📞</div>
                    <div class="value"><?= number_format($stats['calls_today']) ?></div>
                    <div class="label">今日の通話</div>
                </div>
                <div class="stat-card">
                    <div class="icon red">🚨</div>
                    <div class="value"><?= number_format($stats['pending_reports']) ?></div>
                    <div class="label">未処理の通報</div>
                </div>
                <div class="stat-card">
                    <div class="icon blue">🔄</div>
                    <div class="value"><?= number_format($stats['active_requests']) ?></div>
                    <div class="label">マッチング需要</div>
                </div>
                <div class="stat-card">
                    <div class="icon purple">🏢</div>
                    <div class="value"><?= number_format($stats['total_organizations']) ?></div>
                    <div class="label">組織数</div>
                </div>
                <div class="stat-card">
                    <div class="icon green">📁</div>
                    <div class="value"><?= number_format($stats['total_group_chats']) ?></div>
                    <div class="label">グループチャット数</div>
                </div>
            </div>
            
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">📈 最近のアクティビティ</div>
                    <div class="card-body">
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">👤</div>
                            <div class="activity-content">
                                <div class="title"><?= htmlspecialchars($activity['title']) ?></div>
                                <div class="meta"><?= $activity['action'] ?> - <?= date('m/d H:i', strtotime($activity['created_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">⚡ クイックアクション</div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="users.php?action=new">
                                👤 新規ユーザー追加
                            </a>
                            <a href="reports.php">
                                🚨 通報を確認
                            </a>
                            <a href="specs.php">
                                📋 仕様書を見る
                            </a>
                            <a href="backup.php">
                                🗄️ バックアップ管理
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>

