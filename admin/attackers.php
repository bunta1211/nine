<?php
/**
 * 攻撃者情報表示画面
 * 
 * 素人にもわかりやすく攻撃者の情報を表示
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ipinfo.php';

$currentPage = 'attackers';
require_once __DIR__ . '/_sidebar.php';

// 管理者チェック（developer, admin, system_admin, super_admin）
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// データベース接続
$pdo = getDB();

// 攻撃者リストを取得
$attackers = [];
try {
    $stmt = $pdo->query("
        SELECT 
            ip_address,
            COUNT(*) as attack_count,
            MAX(severity) as max_severity,
            GROUP_CONCAT(DISTINCT event_type) as event_types,
            MIN(created_at) as first_seen,
            MAX(created_at) as last_seen,
            MAX(ip_info) as ip_info,
            MAX(user_agent_parsed) as ua_info,
            MAX(fingerprint_data) as fingerprint
        FROM security_logs
        WHERE severity IN ('high', 'critical')
        AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY ip_address
        ORDER BY attack_count DESC, last_seen DESC
        LIMIT 50
    ");
    $attackers = $stmt->fetchAll();
} catch (PDOException $e) {
    // テーブルなし
}

// 日本語変換
function translateEventType($type) {
    $types = [
        'login_failed' => 'ログイン攻撃',
        'brute_force' => '総当たり攻撃',
        'sql_injection' => 'データベース攻撃',
        'xss_attempt' => 'スクリプト攻撃',
        'path_traversal' => 'ファイル窃取試行',
        'command_injection' => 'サーバー乗っ取り試行',
        'suspicious_activity' => '不審な行動',
        'scanner' => '脆弱性スキャン',
        'rate_limit' => '過剰アクセス'
    ];
    
    $results = [];
    foreach (explode(',', $type) as $t) {
        $results[] = $types[trim($t)] ?? trim($t);
    }
    return implode('、', array_unique($results));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>攻撃者情報 - Social9</title>
    <style>
        <?php adminSidebarCSS(); ?>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f23;
            color: #fff;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }
        
        .header h1 {
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header h1 .icon {
            font-size: 40px;
        }
        
        /* 統計カード */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #1a1a3e 0%, #16213e 100%);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            border: 1px solid #333;
        }
        
        .stat-card.danger {
            border-color: #ef4444;
            background: linear-gradient(135deg, #2d1f1f 0%, #1a1a2e 100%);
        }
        
        .stat-value {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-card.danger .stat-value {
            color: #ef4444;
        }
        
        .stat-label {
            color: #888;
            font-size: 14px;
        }
        
        /* 攻撃者カード */
        .attacker-card {
            background: linear-gradient(135deg, #1a1a3e 0%, #16213e 100%);
            border-radius: 15px;
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #333;
        }
        
        .attacker-card.critical {
            border-color: #ef4444;
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);
        }
        
        .attacker-card.high {
            border-color: #f97316;
        }
        
        .attacker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: rgba(0,0,0,0.2);
            cursor: pointer;
        }
        
        .attacker-header:hover {
            background: rgba(0,0,0,0.3);
        }
        
        .attacker-main-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .threat-level {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .threat-level.critical {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
        }
        
        .threat-level.high {
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
        }
        
        .attacker-ip {
            font-size: 24px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        
        .attacker-location {
            color: #888;
            font-size: 16px;
            margin-top: 5px;
        }
        
        .attacker-stats {
            display: flex;
            gap: 30px;
            text-align: center;
        }
        
        .attacker-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #ef4444;
        }
        
        .attacker-stat-label {
            font-size: 12px;
            color: #666;
        }
        
        .attacker-details {
            display: none;
            padding: 25px;
            border-top: 1px solid #333;
        }
        
        .attacker-details.open {
            display: block;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        
        .detail-section {
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            padding: 20px;
        }
        
        .detail-section-title {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 15px;
            letter-spacing: 1px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #333;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #888;
        }
        
        .detail-value {
            color: #fff;
            font-weight: 500;
            text-align: right;
            max-width: 60%;
            word-break: break-all;
        }
        
        .detail-value.warning {
            color: #f59e0b;
        }
        
        .detail-value.danger {
            color: #ef4444;
        }
        
        .attack-types {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .attack-type-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        /* アクションボタン */
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-secondary {
            background: #374151;
            color: white;
        }
        
        .btn-info {
            background: #3b82f6;
            color: white;
        }
        
        /* 人物カード */
        .person-card {
            background: linear-gradient(135deg, #1e3a5f 0%, #1a1a3e 100%);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            border: 2px solid #3b82f6;
        }
        
        .person-avatar {
            width: 80px;
            height: 80px;
            background: #333;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        
        .person-label {
            color: #888;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .person-value {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        /* マップリンク */
        .map-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #3b82f6;
            text-decoration: none;
            padding: 8px 15px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .map-link:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        
        /* 空状態 */
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #666;
        }
        
        .empty-state .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        
        .expand-icon {
            font-size: 20px;
            transition: transform 0.3s;
        }
        
        .attacker-header.open .expand-icon {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        <main class="main-content">
            <div class="container">
        <div class="header">
            <h1>
                <span class="icon">🎯</span>
                攻撃者情報
            </h1>
        </div>
        
        <!-- 統計 -->
        <div class="stats">
            <div class="stat-card danger">
                <div class="stat-value"><?= count($attackers) ?></div>
                <div class="stat-label">過去7日間の攻撃者</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($attackers, 'attack_count')) ?></div>
                <div class="stat-label">総攻撃回数</div>
            </div>
        </div>
        
        <?php if (empty($attackers)): ?>
        <div class="empty-state">
            <div class="icon">✅</div>
            <h2>攻撃者は検出されていません</h2>
            <p>過去7日間で重大な攻撃は記録されていません。</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($attackers as $index => $attacker): 
            $ipData = json_decode($attacker['ip_info'] ?? '{}', true);
            $uaData = json_decode($attacker['ua_info'] ?? '{}', true);
            $fingerprint = json_decode($attacker['fingerprint'] ?? '{}', true);
            $display = $ipData['display'] ?? [];
            
            // IP情報がない場合は取得
            if (empty($ipData) || empty($ipData['country'])) {
                $ipInfo = new IPInfo($pdo);
                $ipData = $ipInfo->lookup($attacker['ip_address']);
                $display = $ipData['display'] ?? [];
            }
        ?>
        <div class="attacker-card <?= $attacker['max_severity'] ?>">
            <div class="attacker-header" onclick="toggleDetails(<?= $index ?>)">
                <div class="attacker-main-info">
                    <div class="threat-level <?= $attacker['max_severity'] ?>">
                        <?= $attacker['max_severity'] === 'critical' ? '🔴' : '🟠' ?>
                    </div>
                    <div>
                        <div class="attacker-ip"><?= htmlspecialchars($attacker['ip_address']) ?></div>
                        <div class="attacker-location">
                            <?= $ipData['country_flag'] ?? '🌍' ?>
                            <?= htmlspecialchars($display['location'] ?? '場所不明') ?>
                        </div>
                    </div>
                </div>
                <div class="attacker-stats">
                    <div>
                        <div class="attacker-stat-value"><?= $attacker['attack_count'] ?></div>
                        <div class="attacker-stat-label">攻撃回数</div>
                    </div>
                </div>
                <span class="expand-icon">▼</span>
            </div>
            
            <div class="attacker-details" id="details-<?= $index ?>">
                <div class="detail-grid">
                    <!-- 誰からのアクセスか -->
                    <div class="detail-section">
                        <div class="detail-section-title">👤 この人物について</div>
                        <div class="detail-row">
                            <span class="detail-label">IPアドレス</span>
                            <span class="detail-value"><?= htmlspecialchars($attacker['ip_address']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">危険度</span>
                            <span class="detail-value danger"><?= htmlspecialchars($display['threat_level'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">最初の攻撃</span>
                            <span class="detail-value"><?= date('Y/m/d H:i', strtotime($attacker['first_seen'])) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">最後の攻撃</span>
                            <span class="detail-value"><?= date('Y/m/d H:i', strtotime($attacker['last_seen'])) ?></span>
                        </div>
                    </div>
                    
                    <!-- 場所 -->
                    <div class="detail-section">
                        <div class="detail-section-title">📍 場所（推定）</div>
                        <div class="detail-row">
                            <span class="detail-label">国</span>
                            <span class="detail-value">
                                <?= $ipData['country_flag'] ?? '' ?>
                                <?= htmlspecialchars($ipData['country'] ?? '不明') ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">地域</span>
                            <span class="detail-value"><?= htmlspecialchars($ipData['region'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">都市</span>
                            <span class="detail-value"><?= htmlspecialchars($ipData['city'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">郵便番号</span>
                            <span class="detail-value"><?= htmlspecialchars($ipData['zip'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">住所（推定）</span>
                            <span class="detail-value"><?= htmlspecialchars($display['address'] ?? '不明') ?></span>
                        </div>
                        <?php if (!empty($ipData['latitude']) && !empty($ipData['longitude'])): ?>
                        <a href="<?= htmlspecialchars($display['map_url'] ?? '#') ?>" target="_blank" class="map-link">
                            🗺️ 地図で見る
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 接続情報 -->
                    <div class="detail-section">
                        <div class="detail-section-title">🌐 接続情報</div>
                        <div class="detail-row">
                            <span class="detail-label">プロバイダ(ISP)</span>
                            <span class="detail-value"><?= htmlspecialchars($display['provider'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">組織名</span>
                            <span class="detail-value"><?= htmlspecialchars($display['organization'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">ネットワーク</span>
                            <span class="detail-value"><?= htmlspecialchars($display['network'] ?? '不明') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">接続タイプ</span>
                            <span class="detail-value <?= !empty($ipData['is_proxy']) ? 'warning' : '' ?>">
                                <?= htmlspecialchars($display['connection_type'] ?? '不明') ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">タイムゾーン</span>
                            <span class="detail-value"><?= htmlspecialchars($display['timezone'] ?? '不明') ?></span>
                        </div>
                    </div>
                    
                    <!-- 端末情報 -->
                    <div class="detail-section">
                        <div class="detail-section-title">💻 端末情報</div>
                        <div class="detail-row">
                            <span class="detail-label">OS</span>
                            <span class="detail-value"><?= htmlspecialchars(($uaData['os'] ?? '不明') . ' ' . ($uaData['os_version'] ?? '')) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">ブラウザ</span>
                            <span class="detail-value"><?= htmlspecialchars(($uaData['browser'] ?? '不明') . ' ' . ($uaData['browser_version'] ?? '')) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">デバイス</span>
                            <span class="detail-value"><?= htmlspecialchars($uaData['device'] ?? '不明') ?></span>
                        </div>
                        <?php if (!empty($fingerprint['screen'])): ?>
                        <div class="detail-row">
                            <span class="detail-label">画面サイズ</span>
                            <span class="detail-value"><?= htmlspecialchars($fingerprint['screen']['width'] ?? '') ?>×<?= htmlspecialchars($fingerprint['screen']['height'] ?? '') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($fingerprint['language'])): ?>
                        <div class="detail-row">
                            <span class="detail-label">言語設定</span>
                            <span class="detail-value"><?= htmlspecialchars($fingerprint['language']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 攻撃内容 -->
                <div class="detail-section" style="margin-top: 25px;">
                    <div class="detail-section-title">⚔️ この攻撃者が行った攻撃</div>
                    <div class="attack-types">
                        <?php foreach (explode(',', translateEventType($attacker['event_types'])) as $type): ?>
                        <span class="attack-type-badge"><?= htmlspecialchars(trim($type)) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- アクション -->
                <div class="actions">
                    <button class="btn btn-danger" onclick="permanentBlock('<?= htmlspecialchars($attacker['ip_address']) ?>')">
                        🚫 永久ブロック
                    </button>
                    <button class="btn btn-info" onclick="lookupMore('<?= htmlspecialchars($attacker['ip_address']) ?>')">
                        🔍 詳細調査
                    </button>
                    <button class="btn btn-secondary" onclick="copyInfo(<?= $index ?>)">
                        📋 情報をコピー
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
            </main>
    </div>
    
    <script>
        function toggleDetails(index) {
            const details = document.getElementById('details-' + index);
            const header = details.previousElementSibling;
            
            details.classList.toggle('open');
            header.classList.toggle('open');
        }
        
        function permanentBlock(ip) {
            if (!confirm(ip + ' を永久ブロックしますか？\n\nこのIPからは二度とアクセスできなくなります。')) {
                return;
            }
            
            fetch('../api/security.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=block_ip&ip=' + ip + '&reason=管理者による永久ブロック&permanent=1'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + ip + ' を永久ブロックしました');
                } else {
                    alert('エラー: ' + data.error);
                }
            });
        }
        
        function lookupMore(ip) {
            // 複数のサービスで調査
            const services = [
                { name: 'AbuseIPDB', url: 'https://www.abuseipdb.com/check/' + ip },
                { name: 'VirusTotal', url: 'https://www.virustotal.com/gui/ip-address/' + ip },
                { name: 'Shodan', url: 'https://www.shodan.io/host/' + ip },
                { name: 'IPinfo', url: 'https://ipinfo.io/' + ip }
            ];
            
            let message = ip + ' を以下のサービスで調査できます:\n\n';
            services.forEach((s, i) => {
                message += (i + 1) + '. ' + s.name + '\n';
            });
            message += '\nどのサービスを開きますか？（番号を入力）';
            
            const choice = prompt(message);
            if (choice && services[parseInt(choice) - 1]) {
                window.open(services[parseInt(choice) - 1].url, '_blank');
            }
        }
        
        function copyInfo(index) {
            const card = document.querySelectorAll('.attacker-card')[index];
            const ip = card.querySelector('.attacker-ip').textContent;
            const location = card.querySelector('.attacker-location').textContent;
            
            const details = card.querySelector('.attacker-details');
            let text = `攻撃者情報\n`;
            text += `==========\n`;
            text += `IP: ${ip}\n`;
            text += `場所: ${location}\n\n`;
            
            details.querySelectorAll('.detail-row').forEach(row => {
                const label = row.querySelector('.detail-label').textContent;
                const value = row.querySelector('.detail-value').textContent;
                text += `${label}: ${value}\n`;
            });
            
            navigator.clipboard.writeText(text).then(() => {
                alert('📋 情報をクリップボードにコピーしました');
            });
        }
    </script>
    <script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
