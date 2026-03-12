<?php
/**
 * Guild ヘッダーテンプレート
 */

require_once __DIR__ . '/../includes/common.php';
requireGuildLogin();

$currentUser = getCurrentUser();
$unreadCount = getUnreadNotificationCount();
$userGuilds = getUserGuilds();
$earthBalance = getUserEarthBalance();
$showSettlementWarning = shouldShowSettlementWarning();
$isFreezeZone = isFreezeZPeriod();

// テスト運用期間（3月31日まで。4月1日から新年度運用）
$guildTestPeriodEnd = new DateTime('2026-04-01', new DateTimeZone(date_default_timezone_get()));
$isGuildTestPeriod = (new DateTime('now', new DateTimeZone(date_default_timezone_get()))) < $guildTestPeriodEnd;

// ダークモード
$darkMode = isDarkMode();

// 現在のページ
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// ベースURL
$baseUrl = getGuildBaseUrl();
?>
<!DOCTYPE html>
<html lang="<?= h(getCurrentLanguage()) ?>" class="<?= $darkMode ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(generateCsrfToken()) ?>">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - ' : '' ?><?= __('app_name') ?></title>
    <link rel="stylesheet" href="<?= asset('css/common.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
    <?php if (isset($extraCss)): ?>
    <?php foreach ((array)$extraCss as $css): ?>
    <link rel="stylesheet" href="<?= asset('css/' . $css) ?>">
    <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <!-- サイドバー -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="<?= $baseUrl ?>/home.php" class="sidebar-logo">
                <svg viewBox="0 0 100 100" class="logo-icon">
                    <circle cx="50" cy="50" r="45" fill="currentColor" opacity="0.1"/>
                    <path d="M50 15 L75 35 L75 65 L50 85 L25 65 L25 35 Z" 
                          fill="none" stroke="currentColor" stroke-width="3"/>
                    <circle cx="50" cy="50" r="15" fill="currentColor"/>
                </svg>
                <span class="logo-text"><?= __('app_name') ?></span>
            </a>
        </div>
        
        <!-- Social9に戻る -->
        <div style="padding:0 16px 12px;">
            <?php
            // 現在のパスからGuildフォルダを検出して適切な相対パスを生成
            $scriptPath = $_SERVER['SCRIPT_NAME'];
            if (strpos($scriptPath, '/Guild/admin/') !== false) {
                $backToSocial9 = '../../chat.php';
            } else {
                $backToSocial9 = '../chat.php';
            }
            ?>
            <a href="<?= $backToSocial9 ?>" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(255,255,255,0.1);border-radius:10px;color:rgba(255,255,255,0.8);text-decoration:none;font-size:13px;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)';this.style.color='#fff';" onmouseout="this.style.background='rgba(255,255,255,0.1)';this.style.color='rgba(255,255,255,0.8)';">
                <span style="font-size:16px;">←</span>
                <span>Social9 に戻る</span>
            </a>
        </div>
        
        <!-- Earth残高表示 -->
        <div class="sidebar-earth">
            <div class="earth-card">
                <div class="earth-label"><?= __('your_earth') ?></div>
                <div class="earth-value">
                    <span class="earth-icon">🌍</span>
                    <span class="earth-amount" id="earth-balance"><?= number_format($earthBalance['current_balance']) ?></span>
                </div>
                <div class="earth-sub">
                    <span class="earth-unpaid"><?= __('unpaid_earth') ?>: <?= number_format($earthBalance['current_balance'] - $earthBalance['total_paid']) ?></span>
                </div>
            </div>
        </div>
        
        <!-- メインナビゲーション -->
        <nav class="sidebar-nav">
            <a href="<?= $baseUrl ?>/home.php" class="nav-item <?= $currentPage === 'home' ? 'active' : '' ?>">
                <span class="nav-icon">🏠</span>
                <span class="nav-text"><?= __('home') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/requests.php" class="nav-item <?= $currentPage === 'requests' ? 'active' : '' ?>">
                <span class="nav-icon">📋</span>
                <span class="nav-text"><?= __('requests') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/my-requests.php" class="nav-item <?= $currentPage === 'my-requests' ? 'active' : '' ?>">
                <span class="nav-icon">📝</span>
                <span class="nav-text"><?= __('my_requests') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/calendar.php" class="nav-item <?= $currentPage === 'calendar' ? 'active' : '' ?>">
                <span class="nav-icon">📅</span>
                <span class="nav-text"><?= __('calendar') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/guilds.php" class="nav-item <?= $currentPage === 'guilds' ? 'active' : '' ?>">
                <span class="nav-icon">🍀</span>
                <span class="nav-text"><?= __('my_guilds') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/payments.php" class="nav-item <?= $currentPage === 'payments' ? 'active' : '' ?>">
                <span class="nav-icon">💰</span>
                <span class="nav-text"><?= __('payments') ?></span>
            </a>
            
            <a href="<?= $baseUrl ?>/notifications.php" class="nav-item <?= $currentPage === 'notifications' ? 'active' : '' ?>">
                <span class="nav-icon">🔔</span>
                <span class="nav-text"><?= __('notifications') ?></span>
                <?php if ($unreadCount > 0): ?>
                <span class="nav-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </a>
        </nav>
        
        <!-- ギルド長ページ（リーダー・サブリーダーのみ表示） -->
        <?php if (isGuildLeaderOrSubLeader()): ?>
        <div class="sidebar-section">
            <div class="section-title"><?= __('guild_leader') ?></div>
            <nav class="sidebar-nav">
                <a href="<?= $baseUrl ?>/leader.php" class="nav-item <?= $currentPage === 'leader' ? 'active' : '' ?>">
                    <span class="nav-icon">👑</span>
                    <span class="nav-text">ギルド長ページ</span>
                </a>
            </nav>
        </div>
        <?php endif; ?>
        
        <!-- 管理者メニュー -->
        <?php if (isGuildSystemAdmin() || isGuildPayrollAdmin()): ?>
        <div class="sidebar-section">
            <div class="section-title"><?= __('admin') ?></div>
            <nav class="sidebar-nav">
                <?php if (isGuildSystemAdmin()): ?>
                <a href="<?= $baseUrl ?>/admin/index.php" class="nav-item <?= strpos($currentPage, 'admin') === 0 ? 'active' : '' ?>">
                    <span class="nav-icon">⚙️</span>
                    <span class="nav-text"><?= __('system_admin') ?></span>
                </a>
                <?php endif; ?>
                <?php if (isGuildPayrollAdmin()): ?>
                <a href="<?= $baseUrl ?>/admin/payroll.php" class="nav-item">
                    <span class="nav-icon">💳</span>
                    <span class="nav-text">支払い管理</span>
                </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
        
        <!-- フッター -->
        <div class="sidebar-footer">
            <a href="<?= $baseUrl ?>/settings.php" class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                <span class="nav-icon">⚙️</span>
                <span class="nav-text"><?= __('settings') ?></span>
            </a>
            <a href="<?= $baseUrl ?>/help.php" class="nav-item <?= $currentPage === 'help' ? 'active' : '' ?>">
                <span class="nav-icon">❓</span>
                <span class="nav-text"><?= __('help') ?></span>
            </a>
        </div>
    </aside>
    
    <!-- メインコンテンツ -->
    <main class="main-content">
        <!-- トップバー -->
        <header class="topbar">
            <button class="menu-toggle" id="menu-toggle" aria-label="メニュー">
                <span></span>
                <span></span>
                <span></span>
            </button>
            
            <div class="topbar-title">
                <?= isset($pageTitle) ? h($pageTitle) : __('app_name') ?>
            </div>
            
            <div class="topbar-actions">
                <!-- 言語切替 -->
                <div id="guild-lang-selector" style="position:relative;margin-right:12px;">
                    <button type="button" id="guild-lang-btn" onclick="toggleGuildLang()" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:#f1f5f9;border:none;border-radius:10px;font-size:14px;font-weight:600;color:#475569;cursor:pointer;">
                        🌐 <?= getCurrentLanguage() === 'en' ? 'EN' : (getCurrentLanguage() === 'zh' ? '中' : 'JP') ?>
                    </button>
                    <div id="guild-lang-menu" style="display:none;position:absolute;top:100%;right:0;margin-top:8px;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,0.15);min-width:100px;z-index:2000;overflow:hidden;">
                        <a href="?lang=ja" style="display:block;padding:12px 16px;color:#333;text-decoration:none;<?= getCurrentLanguage() === 'ja' ? 'background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;' : '' ?>">🇯🇵 JP</a>
                        <a href="?lang=en" style="display:block;padding:12px 16px;color:#333;text-decoration:none;<?= getCurrentLanguage() === 'en' ? 'background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;' : '' ?>">🇺🇸 EN</a>
                        <a href="?lang=zh" style="display:block;padding:12px 16px;color:#333;text-decoration:none;<?= getCurrentLanguage() === 'zh' ? 'background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;' : '' ?>">🇨🇳 中</a>
                    </div>
                </div>
                <script>
                function toggleGuildLang(){var m=document.getElementById('guild-lang-menu');m.style.display=m.style.display==='none'?'block':'none';}
                document.addEventListener('click',function(e){var s=document.getElementById('guild-lang-selector');if(s&&!s.contains(e.target)){document.getElementById('guild-lang-menu').style.display='none';}});
                </script>
                
                <!-- 通知ボタン -->
                <a href="<?= $baseUrl ?>/notifications.php" class="topbar-btn notification-btn" title="<?= __('notifications') ?>">
                    <span class="icon">🔔</span>
                    <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- ユーザーメニュー -->
                <div class="user-menu" id="user-menu">
                    <button class="user-menu-toggle" id="user-menu-toggle">
                        <div class="user-avatar">
                            <?php if (!empty($currentUser['avatar'])): ?>
                            <img src="<?= h($currentUser['avatar']) ?>" alt="">
                            <?php else: ?>
                            <span class="avatar-placeholder"><?= mb_substr($currentUser['display_name'] ?? 'U', 0, 1) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="user-name"><?= h($currentUser['display_name'] ?? 'ユーザー') ?></span>
                    </button>
                    
                    <div class="user-dropdown" id="user-dropdown">
                        <a href="<?= $baseUrl ?>/settings.php" class="dropdown-item">
                            <span class="icon">👤</span>
                            <?= __('profile') ?>
                        </a>
                        <a href="<?= $baseUrl ?>/settings.php" class="dropdown-item">
                            <span class="icon">⚙️</span>
                            <?= __('settings') ?>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item" id="logout-btn">
                            <span class="icon">🚪</span>
                            <?= __('logout') ?>
                        </a>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- 警告バナー -->
        <?php if ($showSettlementWarning): ?>
        <div class="warning-banner">
            <span class="warning-icon">⚠️</span>
            <span class="warning-text"><?= __('settlement_warning') ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($isFreezeZone): ?>
        <div class="warning-banner warning-danger">
            <span class="warning-icon">🚫</span>
            <span class="warning-text"><?= __('freeze_period') ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($isGuildTestPeriod): ?>
        <div class="warning-banner guild-test-period-banner" style="background:#e0f2fe;border:1px solid #0284c7;color:#0369a1;">
            <span class="warning-icon">📋</span>
            <span class="warning-text">3月31日までテスト運用期間です。4月1日から新年度運用が始まります。</span>
        </div>
        <?php endif; ?>
        
        <!-- ページコンテンツ -->
        <div class="page-content">
