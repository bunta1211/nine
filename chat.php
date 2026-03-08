<?php
/**
 * Social9 メインチャット画面
 * 仕様書: 05_チャット機能.md, 11_UIレイアウト.md
 * レイアウト: 上パネル、左パネル、中央パネル、右パネル
 * 
 * リファクタリング: 2026-01 - テンプレート分離による最適化
 */
ob_start();

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/asset_helper.php';
require_once __DIR__ . '/config/ai_config.php';
require_once __DIR__ . '/config/push.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/chat/data.php';

// ログインを要求
requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// デザイン設定を取得（URLパラメータ _theme による上書きに対応）
$designSettings = getDesignSettings($pdo, $user_id);
$display_name = $_SESSION['display_name'] ?? 'ユーザー';
$role = $_SESSION['role'] ?? 'user';

// 選択中の会話（先に判定し、既読更新を一覧取得より前に行う）
$selected_conversation_id = isset($_GET['c']) ? (int)$_GET['c'] : null;
$is_secretary_mode = isset($_GET['secretary']) && $_GET['secretary'] === '1';

// 携帯・モバイルでは常にグループ一覧を最初に表示（メッセージ数を先に見せるため）
// 携帯版ではグループチャット一覧がトップページの役割となる
$is_mobile_request = is_mobile_request();

// URLパラメータがない場合、セッションから復元してリダイレクト（秘書モード・モバイルの場合は除く）
// 組織フィルタ指定時はリダイレクトしない（A組織を開いた状態をリロードで維持するため）
$has_filter_param = isset($_GET['filter']) && preg_match('/^(all|unread|group|dm|org-\d+)$/', (string)$_GET['filter']);
if (!$selected_conversation_id && !$is_secretary_mode && !$is_mobile_request && isset($_SESSION['last_conversation_id']) && !$has_filter_param) {
    header('Location: chat.php?c=' . (int)$_SESSION['last_conversation_id']);
    exit;
}

// 未読区切り表示・「未読部分からの閲覧開始」用に、既読更新「前」の last_read を取得してから既読を更新する
$last_read_at = null;
$last_read_message_id = null;
if ($selected_conversation_id) {
    // 参加していない会話のURLの場合は選択を外してリダイレクト（404/403エラー防止）
    // 削除済み・退出済みの会話だとループするため、リダイレクト前にセッションの last_conversation_id をクリアする
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
    $stmt->execute([$selected_conversation_id, $user_id]);
    if (!$stmt->fetch()) {
        unset($_SESSION['last_conversation_id']);
        $selected_conversation_id = null;
        $redirectUrl = 'chat.php';
        if (isset($_GET['filter']) && preg_match('/^(all|unread|group|dm|org-\d+)$/', (string)$_GET['filter'])) {
            $redirectUrl .= '?filter=' . urlencode((string)$_GET['filter']);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
    $_SESSION['last_conversation_id'] = $selected_conversation_id;
    // 未読区切り描画用：last_read を取得（未読数は開いただけでは更新しない＝「読んだ」ときにだけ既読更新する）
    try {
        $stmt = $pdo->prepare("SELECT last_read_at, last_read_message_id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$selected_conversation_id, $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $last_read_at = $row['last_read_at'] ?? null;
            $last_read_message_id = isset($row['last_read_message_id']) ? (int)$row['last_read_message_id'] : null;
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'last_read_message_id') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("SELECT last_read_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$selected_conversation_id, $user_id]);
            $last_read_at = $stmt->fetchColumn();
        } else {
            throw $e;
        }
    }
    // 既読は「会話を離れたとき」にのみ更新（開いただけでは未読数のまま。別会話へ移動時に API で既読にする）
}

// ページデータを取得（未読数はDBの last_read に基づき正しくカウント）
$pageData = getChatPageData($pdo, $user_id);

if (isset($pageData['error'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// 変数を展開
$user = $pageData['user'];
$userOrganizations = $pageData['userOrganizations'];
$conversations = $pageData['conversations'];
$all_users = $pageData['allUsers'];
$groupMembers = $pageData['groupMembers'] ?? [];
$totalConversations = $pageData['totalConversations'];

// 言語設定を初期化
if (!isset($_SESSION['language'])) {
    $userLang = $user['language'] ?? $user['display_language'] ?? null;
    if (!empty($userLang)) {
        setLanguage($userLang);
    }
}
$currentLang = getCurrentLanguage();

// 選択中の会話のデータを取得
$convData = getSelectedConversationData($pdo, $user_id, $selected_conversation_id);
$selected_conversation = $convData['conversation'];
$messages = $convData['messages'];
$members = $convData['members'];

// 左パネルフィルタの初期値（リロードで組織を維持するため。URLのfilter > 選択会話の組織）
$initial_left_panel_filter = 'all';
if (isset($_GET['filter']) && preg_match('/^(all|unread|group|dm|org-\d+)$/', (string)$_GET['filter'])) {
    $initial_left_panel_filter = (string)$_GET['filter'];
} elseif (!empty($selected_conversation['organization_id'])) {
    $initial_left_panel_filter = 'org-' . (int)$selected_conversation['organization_id'];
}

// 秘書モード時: 強制リロードでも選択が消えないようDBの値をページに埋め込む
$ai_prefill = null;
if ($is_secretary_mode) {
    try {
        $stmt = $pdo->prepare("SELECT character_type, secretary_name FROM user_ai_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty(trim((string)($row['character_type'] ?? '')))) {
            $ai_prefill = [
                'character_selected' => true,
                'character_type' => $row['character_type'],
                'name' => !empty(trim((string)($row['secretary_name'] ?? ''))) ? $row['secretary_name'] : 'あなたの秘書',
            ];
        }
    } catch (Throwable $e) {
        // テーブル未作成等は無視
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?> - チャット</title>
    
    <?php $pwa_icon_v = file_exists(__DIR__.'/assets/icons/icon-192x192.png') ? filemtime(__DIR__.'/assets/icons/icon-192x192.png') : '1'; ?>
    <!-- PWA対応: マニフェストとアイコン（?v= でキャッシュ無効化し、ロゴ差し替え後に反映） -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#4a6741">
    <meta name="application-name" content="<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?>">
    
    <!-- PWA対応: ホーム画面追加 -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?>">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=<?= $pwa_icon_v ?>">
    
    <!-- ファビコン（SVGフォールバック付き） -->
    <link rel="icon" type="image/svg+xml" href="assets/icons/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png?v=<?= $pwa_icon_v ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png?v=<?= $pwa_icon_v ?>">
    
    <!-- パフォーマンス最適化: preconnect（外部リソース接続を事前確立） -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://<?= htmlspecialchars(JITSI_DOMAIN) ?>">
    <link rel="preconnect" href="https://media.giphy.com">
    <!-- 自前 Jitsi 対応: フロントでドメイン・ベースURLを参照 -->
    <script>window.__JITSI_DOMAIN = <?= json_encode(JITSI_DOMAIN) ?>; window.__JITSI_BASE_URL = <?= json_encode(rtrim(JITSI_BASE_URL, '/')) ?>;</script>
    
    <!-- DNS prefetch（接続頻度が低いドメイン用） -->
    <link rel="dns-prefetch" href="https://api.giphy.com">
    
    <?= generateFontLinks() ?>
    
    <!-- 重要CSSを先に読み込み -->
    <link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css') ?>">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= assetVersion('assets/css/mobile.css') ?>">
    <link rel="stylesheet" href="assets/css/chat-main.css?v=<?= assetVersion('assets/css/chat-main.css') ?>">
    <link rel="stylesheet" href="assets/css/ai-voice-input.css?v=<?= assetVersion('assets/css/ai-voice-input.css') ?>">
    <link rel="stylesheet" href="assets/css/secretary-rightpanel.css?v=<?= assetVersion('assets/css/secretary-rightpanel.css') ?>">
    <link rel="stylesheet" href="assets/css/ai-reply-suggest.css?v=<?= assetVersion('assets/css/ai-reply-suggest.css') ?>">
    <link rel="stylesheet" href="assets/css/panel-resize.css?v=<?= assetVersion('assets/css/panel-resize.css') ?>">
    <link rel="stylesheet" href="assets/css/layout/header.css?v=<?= assetVersion('assets/css/layout/header.css') ?>">
    <link rel="stylesheet" href="assets/css/components/task-card.css?v=<?= assetVersion('assets/css/components/task-card.css') ?>">
    <!-- 新コンポーネント分離CSS（CSS変数のみ読み込み） -->
    <link rel="stylesheet" href="assets/css/chat-new.css?v=<?= assetVersion('assets/css/chat-new.css') ?>">
    <link rel="stylesheet" href="assets/css/pwa-install.css?v=<?= assetVersion('assets/css/pwa-install.css') ?>">
    <link rel="stylesheet" href="assets/css/push-notifications.css?v=<?= assetVersion('assets/css/push-notifications.css') ?>">
    <link rel="stylesheet" href="assets/css/ai-personality.css?v=<?= assetVersion('assets/css/ai-personality.css') ?>">
    <link rel="stylesheet" href="assets/css/storage.css?v=<?= assetVersion('assets/css/storage.css') ?>">
    <!-- Jitsi Meet API / QRコードライブラリは使用時に動的読み込み（初期表示高速化） -->
    
    <?= generateDesignCSS($designSettings) ?>
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css?v=<?= assetVersion('assets/css/panel-panels-unified.css') ?>">
    
    <!-- モバイルCSSは最後に読み込み（デザインCSSを上書き） -->
    <link rel="stylesheet" href="assets/css/chat-mobile.css?v=<?= assetVersion('assets/css/chat-mobile.css') ?>">
    <!-- QRコード生成ライブラリ（必要時に遅延読み込み） -->
    <script>
    // QRCodeを使用する機能が呼び出されたときに読み込み
    window.loadQRCode = function() {
        return new Promise((resolve, reject) => {
            if (window.QRCode) { resolve(); return; }
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
            script.onload = resolve;
            script.onerror = () => reject(new Error('QRCode library failed to load'));
            document.head.appendChild(script);
        });
    };
    </script>
    
    <!-- エラー自動収集（最初に読み込む） -->
    <script src="assets/js/error-collector.js?v=<?= assetVersion('assets/js/error-collector.js') ?>"></script>
    <!-- APIは常に表示中のドメインへ送る（baseタグ・キャッシュで別ドメインに行くのを防ぐ） -->
    <script>
    window.__CHAT_API_BASE = window.location.origin + (window.location.pathname.replace(/\/[^/]*$/, '') || '/');
    if (!window.__CHAT_API_BASE.endsWith('/')) window.__CHAT_API_BASE += '/';
    </script>
    <?php if ($ai_prefill !== null): ?>
    <!-- 秘書モード: 強制リロードでも選択を維持（DBの値をJSの初期値として渡す） -->
    <script>
    window.__AI_SECRETARY_PREFILL = <?= json_encode($ai_prefill) ?>;
    </script>
    <?php endif; ?>
    
    <!-- チャットモジュール（新システム） -->
    <script src="assets/js/chat/config.js?v=<?= assetVersion('assets/js/chat/config.js') ?>"></script>
    <script src="assets/js/chat/utils.js?v=<?= assetVersion('assets/js/chat/utils.js') ?>"></script>
    <script src="assets/js/chat/debug.js?v=<?= assetVersion('assets/js/chat/debug.js') ?>"></script>
    <script src="assets/js/chat/api.js?v=<?= assetVersion('assets/js/chat/api.js') ?>"></script>
    <script src="assets/js/chat/ui.js?v=<?= assetVersion('assets/js/chat/ui.js') ?>"></script>
    <script src="assets/js/chat/lazy-loader.js?v=<?= assetVersion('assets/js/chat/lazy-loader.js') ?>"></script>
    <script src="assets/js/chat/index.js?v=<?= assetVersion('assets/js/chat/index.js') ?>"></script>
    
    <!-- 機能モジュール（後方互換性維持、scripts.phpと併用） -->
    <!-- 将来的にscripts.phpから移行後に有効化 -->
    <!--
    <script src="assets/js/chat/to-selector.js?v=<?= assetVersion('assets/js/chat/to-selector.js') ?>"></script>
    <script src="assets/js/chat/reactions.js?v=<?= assetVersion('assets/js/chat/reactions.js') ?>"></script>
    <script src="assets/js/chat/tasks.js?v=<?= assetVersion('assets/js/chat/tasks.js') ?>"></script>
    <script src="assets/js/chat/messages.js?v=<?= assetVersion('assets/js/chat/messages.js') ?>"></script>
    <script src="assets/js/chat/translation.js?v=<?= assetVersion('assets/js/chat/translation.js') ?>"></script>
    <script src="assets/js/chat/media.js?v=<?= assetVersion('assets/js/chat/media.js') ?>"></script>
    <script src="assets/js/chat/polling.js?v=<?= assetVersion('assets/js/chat/polling.js') ?>"></script>
    <script src="assets/js/chat/call.js?v=<?= assetVersion('assets/js/chat/call.js') ?>"></script>
    -->
    
    <!-- 開発・デバッグツール -->
    <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
    <script src="assets/js/test-runner.js?v=<?= assetVersion('assets/js/test-runner.js') ?>"></script>
    <script src="assets/js/page-inspector.js?v=<?= assetVersion('assets/js/page-inspector.js') ?>"></script>
    <?php endif; ?>
    <style>
    /* To chip: <b data-to> タグの表示保証 */
    .message-card .content b[data-to] {
        display: inline-block !important;
        visibility: visible !important;
        opacity: 1 !important;
        background: #7cb342 !important;
        color: #fff !important;
        padding: 1px 8px !important;
        border-radius: 4px !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        margin: 2px 4px 2px 0 !important;
        vertical-align: middle !important;
        line-height: 1.6 !important;
    }
    /* 携帯・会話選択時: 初回描画でメッセージ表示欄の高さを確保（外部CSSより先に効く） */
    @media (max-width: 768px) {
        body.page-chat .center-panel #messagesArea,
        body.page-chat .center-panel .messages-area {
            min-height: 55vh !important;
            height: auto !important;
            max-height: none !important;
            display: block !important;
            flex: 1 1 0% !important;
            overflow-y: auto !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        body.page-chat .center-panel {
            display: flex !important;
            flex-direction: column !important;
            min-height: 60vh !important;
        }
    }
    </style>
</head>
<?php
    // 標準デザイン固定（テーマ・背景画像は廃止）
    $themeId = DESIGN_DEFAULT_THEME;
    $effectiveStyle = function_exists('getEffectiveStyleId') ? getEffectiveStyleId($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE) : ($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE);
?>
<?php
    // 携帯版: トップバーのロゴ位置に表示するグループ名（会話選択時のみ）
    $topbar_mobile_title = '';
    if (!empty($selected_conversation)) {
        $topbar_mobile_title = getLocalizedName($selected_conversation, 'name') ?: ($currentLang === 'en' ? 'Chat' : ($currentLang === 'zh' ? '聊天' : 'チャット'));
        if (isset($selected_conversation['type']) && $selected_conversation['type'] !== 'dm' && empty($selected_conversation['is_dm_like']) && !empty($members)) {
            $topbar_mobile_title .= ' (' . count($members) . ')';
        }
    } elseif ($is_secretary_mode && !empty($ai_prefill['name'])) {
        $topbar_mobile_title = $ai_prefill['name'];
    }
?>
<body class="page-chat style-<?= htmlspecialchars($effectiveStyle) ?>" data-theme="<?= htmlspecialchars($themeId) ?>" data-style="<?= htmlspecialchars($effectiveStyle) ?>" data-bg-style="" data-bg-design="" data-display-name="<?= htmlspecialchars($display_name) ?>" data-user-id="<?= (int)$user_id ?>" data-user-auth-level="<?= (int)($user['auth_level'] ?? 0) ?>" data-bg-light="0" data-mobile-list-first="<?= ($is_mobile_request && !$selected_conversation_id) ? '1' : '0' ?>" data-has-conversation="<?= $selected_conversation_id ? '1' : '0' ?>" data-initial-left-panel-filter="<?= htmlspecialchars($initial_left_panel_filter) ?>">
<script>(function(){if(window.innerWidth<=768){if(typeof history!=='undefined'){try{history.scrollRestoration='manual';}catch(e){}}if(document.body.getAttribute('data-has-conversation')==='0'){document.body.setAttribute('data-mobile-list-first','1');}}})();</script>
    
    <!-- スクリーンリーダー用h1 -->
    <h1 class="sr-only"><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?> チャット</h1>
    
    <?php include __DIR__ . '/includes/chat/topbar.php'; ?>
    
    <!-- ========== メインコンテナ ========== -->
    <div class="main-container" id="mainContainer">
        <!-- モバイル用オーバーレイ -->
        <div class="mobile-overlay" id="mobileOverlay" onclick="(typeof closeMobileAllPanels==='function'?closeMobileAllPanels:closeMobilePanels)()"></div>
        
        <!-- 携帯: 3ページ横並びストリップ（左｜中央｜右）。PCでは display:contents でレイアウトに影響しない -->
        <div class="mobile-pages-strip" id="mobilePagesStrip">
        <?php include __DIR__ . '/includes/chat/sidebar.php'; ?>
        
        <!-- 左パネルリサイズハンドル -->
        <div class="panel-resize-handle panel-resize-left" id="resizeLeftHandle" title="ドラッグで左パネル幅を変更" aria-label="左パネル幅を変更"></div>
        
        <!-- 中央パネル -->
        <main class="center-panel"<?php if ($is_mobile_request && $selected_conversation_id): ?> style="display: flex !important; flex-direction: column !important; min-height: 50vh !important; height: 100% !important; overflow: hidden !important;"<?php endif; ?>>
            <?php if ($selected_conversation): ?>
            <div class="chat-header">
                <div class="chat-header-left">
                    <?php
                    $header_conv_name = getLocalizedName($selected_conversation, 'name') ?: ($currentLang === 'en' ? 'Chat' : ($currentLang === 'zh' ? '聊天' : 'チャット'));
                    $is_group_header = ($selected_conversation['type'] !== 'dm' && empty($selected_conversation['is_dm_like']));
                    $iconStyleBg = [
                        'default' => '#6b7280',
                        'white' => '#FFFFFF',
                        'black' => '#1a1a1a',
                        'gray' => '#6b7280',
                        'red' => 'linear-gradient(135deg, #FF6B6B, #ee5a5a)',
                        'orange' => 'linear-gradient(135deg, #FFA500, #FF8C00)',
                        'yellow' => 'linear-gradient(135deg, #FFD700, #FFC107)',
                        'green' => 'linear-gradient(135deg, #4CAF50, #43A047)',
                        'blue' => 'linear-gradient(135deg, #2196F3, #1976D2)',
                        'purple' => 'linear-gradient(135deg, #9C27B0, #7B1FA2)',
                        'pink' => 'linear-gradient(135deg, #FF69B4, #FF1493)'
                    ];
                    $iconStyleBorder = ['white' => '1px solid #e0e0e0'];
                    $header_icon_style = $selected_conversation['icon_style'] ?? 'default';
                    $header_icon_pos_x = (float)($selected_conversation['icon_pos_x'] ?? 0);
                    $header_icon_pos_y = (float)($selected_conversation['icon_pos_y'] ?? 0);
                    $header_icon_size = (int)($selected_conversation['icon_size'] ?? 100);
                    $header_bg = $iconStyleBg[$header_icon_style] ?? $iconStyleBg['default'];
                    $header_border = $iconStyleBorder[$header_icon_style] ?? 'none';
                    $header_pos_transform = "translate({$header_icon_pos_x}%, {$header_icon_pos_y}%)";
                    $header_icon_path = $selected_conversation['icon_path'] ?? '';
                    $header_has_icon = !empty($header_icon_path);
                    $header_partner_avatar = null;
                    if (!$is_group_header && !empty($members)) {
                        foreach ($members as $m) {
                            if ((int)($m['id'] ?? 0) !== (int)$user_id) {
                                $header_partner_avatar = $m['avatar_path'] ?? '';
                                break;
                            }
                        }
                    }
                    ?>
                    <div class="chat-title-area <?= $is_group_header ? 'clickable-group' : '' ?>" <?php if ($is_group_header): ?>onclick="openAddMemberModal()" title="<?= $currentLang === 'en' ? 'Member Management' : ($currentLang === 'zh' ? '成员管理' : 'メンバー管理') ?>"<?php endif; ?>>
                        <?php if ($is_group_header || $header_partner_avatar): ?>
                        <div class="chat-header-icon" <?php if ($is_group_header): ?>style="background: <?= htmlspecialchars($header_bg) ?>; border: <?= htmlspecialchars($header_border) ?>;"<?php endif; ?>>
                            <?php if ($is_group_header && $header_has_icon): ?>
                            <img src="<?= htmlspecialchars($header_icon_path) ?>" alt="" class="chat-header-icon-img" style="width: <?= $header_icon_size ?>%; height: <?= $header_icon_size ?>%; transform: <?= htmlspecialchars($header_pos_transform) ?>;">
                            <?php elseif ($is_group_header): ?>
                            <span class="chat-header-icon-group">👥</span>
                            <?php elseif ($header_partner_avatar): ?>
                            <img src="<?= htmlspecialchars($header_partner_avatar) ?>" alt="" class="chat-header-icon-img chat-header-icon-avatar">
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <h2>
                            <?= htmlspecialchars($header_conv_name) ?>
                            <?php if ($selected_conversation['type'] !== 'dm' && empty($selected_conversation['is_dm_like'])): ?>
                            (<?= count($members) ?>)
                            <?php endif; ?>
                            <?php if ($selected_conversation['my_role'] === 'admin'): ?>
                            <span class="badge"><?= $currentLang === 'en' ? 'Admin' : ($currentLang === 'zh' ? '管理员' : '管理者') ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>
                </div>
                <div class="chat-header-right">
                    <button class="mobile-detail-btn" onclick="toggleMobileRightPanel()" title="詳細">☰</button>
                </div>
            </div>
            
            <div class="messages-area" id="messagesArea" data-conversation-id="<?= (int)($selected_conversation_id ?? 0) ?>"<?php if ($is_mobile_request && $selected_conversation_id): ?> style="min-height: 50vh !important; display: block !important; flex: 1 1 0% !important; overflow-y: auto !important; visibility: visible !important; opacity: 1 !important;"<?php endif; ?>>
                <?php 
                $unread_divider_shown = false;
                foreach ($messages as $msg): 
                    $is_own = $msg['sender_id'] == $user_id;
                    $is_edited = !empty($msg['is_edited']);
                    $can_edit = $is_own && (time() - strtotime($msg['created_at']) < 300);
                    $is_mentioned = !empty($msg['is_mentioned_me']);
                    // message_mentions に無くても to_info（TO全員 or user_ids）または本文の [To:ID] に自分が含まれる場合は自分宛とする
                    if (!$is_mentioned && !$is_own) {
                        $uid = (int)$user_id;
                        $toInfo = $msg['to_info'] ?? null;
                        if (!empty($toInfo)) {
                            if (isset($toInfo['type']) && $toInfo['type'] === 'to_all') {
                                $is_mentioned = true;
                            } elseif (!empty($toInfo['user_ids']) && in_array($uid, array_map('intval', $toInfo['user_ids']), true)) {
                                $is_mentioned = true;
                            }
                        }
                        if (!$is_mentioned) {
                            $textToCheck = (string)($msg['content'] ?? '');
                            if (!empty($msg['extracted_text'])) {
                                $textToCheck .= "\n" . (string)$msg['extracted_text'];
                            }
                            if ($textToCheck !== '' && preg_match_all('/\[To:\s*(\d+)\]/', $textToCheck, $toMatches)) {
                                $toIds = array_map('intval', $toMatches[1]);
                                if (in_array($uid, $toIds, true)) {
                                    $is_mentioned = true;
                                }
                            }
                        }
                    }
                    $mention_type = $msg['mention_type'] ?? null;
                    $is_system = ($msg['message_type'] ?? '') === 'system';
                    
                    // 未読区切りを表示（last_read_message_id 優先＝リロード後も既読がずれない。なければ last_read_at で判定）
                    $is_unread = false;
                    if (!$unread_divider_shown && !$is_own) {
                        $msg_id = (int)($msg['id'] ?? 0);
                        if ($last_read_message_id !== null && $last_read_message_id >= 0) {
                            if ($msg_id > $last_read_message_id) {
                                $is_unread = true;
                                $unread_divider_shown = true;
                            }
                        } elseif ($last_read_at) {
                            if (strtotime($msg['created_at']) > strtotime($last_read_at)) {
                                $is_unread = true;
                                $unread_divider_shown = true;
                            }
                        }
                    }
                ?>
                <?php if ($is_unread): ?>
                <div class="unread-divider" id="unreadDivider">
                    <span class="unread-divider-text">↓ ここから未読 ↓</span>
                </div>
                <?php endif; ?>
                <?php 
                    // 自動翻訳対象かどうかを判定（3日以内）
                    $auto_translate_days = defined('AUTO_TRANSLATION_DAYS') ? AUTO_TRANSLATION_DAYS : 3;
                    $is_auto_translate_target = (time() - strtotime($msg['created_at'])) < ($auto_translate_days * 24 * 60 * 60);
                    $source_lang = $msg['source_lang'] ?? null;
                ?>
                <?php if ($is_system): ?>
                <?php
                    $has_task_emoji = !empty($msg['content']) && (strpos($msg['content'], '📋') !== false || strpos($msg['content'], '✅') !== false);
                    $has_task_ref = !empty($msg['task_id']) || !empty($msg['task_detail']);
                    $is_task_msg = $has_task_emoji || $has_task_ref;
                    if ($is_task_msg):
                        $raw = trim($msg['content'] ?? '');
                        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
                        $header_line = $lines[0] ?? '';
                        $is_complete = strpos($header_line, '✅') !== false;
                        $header_title = preg_replace('/^[📋✅\s*]+/u', '', $header_line);
                        $header_title = trim(preg_replace('/[\s*]+$/u', '', $header_title));
                        $header_title = preg_replace('/[📋✅]/u', '', $header_title);
                        $header_title = trim($header_title) ?: ($is_complete ? 'タスク完了' : 'タスク依頼');
                        $posted_at = date('Y年n月j日 H:i', strtotime($msg['created_at']));
                        $header_meta = '';
                        $body_content = '';
                        $footer_deadline = '';
                        /* パースしてラベル・値を抽出 */
                        $parsed = [];
                        for ($i = 1; $i < count($lines); $i++) {
                            $line = $lines[$i];
                            if (preg_match('/^\*\*(.+?)\*\*[:\x{FF1A}]\s*(.*)$/u', $line, $m)) {
                                $parsed[trim($m[1])] = trim($m[2]);
                            } elseif (preg_match('/[:\x{FF1A}]/u', $line, $cm)) {
                                $idx = strpos($line, $cm[0]);
                                $lbl = trim(preg_replace('/\*\*/', '', substr($line, 0, $idx)));
                                $val = trim(substr($line, $idx + strlen($cm[0])));
                                if ($lbl !== '') $parsed[$lbl] = $val;
                            }
                        }
                        if (!empty($parsed)) {
                            $req = htmlspecialchars($parsed['依頼者'] ?? '');
                            $wrk = htmlspecialchars($parsed['担当者'] ?? '');
                            $compl = htmlspecialchars($parsed['完了者'] ?? '');
                            $title = htmlspecialchars($parsed['内容'] ?? '（内容なし）');
                            $due = $parsed['期限'] ?? '';
                            if ($is_complete) {
                                $header_meta = $compl ? '完了者 '.$compl : '';
                                $body_content = '<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">'.$title.'</span></div>';
                            } else {
                                $header_meta = ($req ? '依頼者 '.$req : '').($wrk ? '　担当者 '.$wrk : '');
                                $body_content = '<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">'.$title.'</span></div>';
                                if ($due !== '') $footer_deadline = htmlspecialchars($due);
                            }
                        }
                        if ($body_content === '' && !empty($msg['task_detail'])) {
                            $td = $msg['task_detail'];
                            $title = htmlspecialchars(trim($td['title'] ?? '') ?: '（内容なし）');
                            if ($is_complete) {
                                $compl = htmlspecialchars(trim($td['worker_name'] ?? $td['requester_name'] ?? '') ?: '（不明）');
                                $header_meta = '完了者 '.$compl;
                                $body_content = '<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">'.$title.'</span></div>';
                            } else {
                                $req = htmlspecialchars(trim($td['requester_name'] ?? '') ?: '（不明）');
                                $wrk = htmlspecialchars(trim($td['worker_name'] ?? '') ?: '（未定）');
                                $header_meta = $req.' ⇒ '.$wrk;
                                $body_content = '<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">'.$title.'</span></div>';
                                if (!empty($td['due_date'])) {
                                    $footer_deadline = date('Y年m月d日', strtotime($td['due_date']));
                                }
                            }
                        }
                        if ($body_content === '' && count($lines) > 1) {
                            $fallback = implode('<br>', array_map(function($l) {
                                return htmlspecialchars(preg_replace('/\*\*([^*]+)\*\*/', '$1', $l));
                            }, array_slice($lines, 1)));
                            $body_content = '<div class="task-card-fallback">'.$fallback.'</div>';
                        } elseif ($body_content === '') {
                            $fmt = htmlspecialchars(preg_replace('/\*\*([^*]+)\*\*/', '$1', $raw));
                            $fmt = nl2br($fmt);
                            $fmt = preg_replace('/^[📋✅]\s*/u', '', $fmt);
                            $body_content = '<div class="task-card-fallback">'.$fmt.'</div>';
                        }
                        $card_class = 'system-message task-system-message task-card' . ($is_complete ? ' task-card-complete' : '');
                        $task_id_attr = !empty($msg['task_id']) ? ' data-task-id="'.(int)$msg['task_id'].'"' : '';
                        $td = $msg['task_detail'] ?? [];
                        $requester_id = isset($td['requester_id']) ? (int)$td['requester_id'] : 0;
                        $worker_id = isset($td['worker_id']) ? (int)$td['worker_id'] : 0;
                        $sender_id = (int)($msg['sender_id'] ?? 0);
                        $can_delete_task = ($requester_id && $requester_id === (int)$user_id)
                            || ($worker_id && $worker_id === (int)$user_id)
                            || ($sender_id && $sender_id === (int)$user_id);
                ?>
                <div class="<?= $card_class ?>" data-message-id="<?= $msg['id'] ?>"<?= $task_id_attr ?>>
                    <div class="task-card-header">
                        <span class="task-card-label task-card-title"><?= htmlspecialchars($header_title) ?></span>
                        <span class="task-card-posted">（<?= $posted_at ?>）</span>
                        <?php if ($header_meta !== ''): ?><span class="task-card-meta"><?= $header_meta ?></span><?php endif; ?>
                    </div>
                    <div class="task-card-body"><?= $body_content ?></div>
                    <div class="task-card-footer">
                        <?php if ($footer_deadline !== ''): ?><span class="task-card-label">期限</span><span class="task-card-deadline"><?= $footer_deadline ?></span><?php endif; ?>
                        <?php if ($can_delete_task && !empty($msg['task_id'])): ?>
                        <button type="button" class="task-card-delete-btn" onclick="deleteTaskDisplay(<?= (int)$msg['task_id'] ?>, this)" title="<?= $currentLang === 'en' ? 'Delete task' : ($currentLang === 'zh' ? '删除任务' : 'タスク表示を削除') ?>">🗑️</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="system-message" data-message-id="<?= $msg['id'] ?>">
                    <span class="system-message-content"><?= htmlspecialchars($msg['content']) ?></span>
                    <span class="system-message-time"><?= date('Y年n月j日 H:i', strtotime($msg['created_at'])) ?></span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <?php
                // To情報の処理: to_allの場合は["all"]を設定
                $toType = !empty($msg['to_info']) ? $msg['to_info']['type'] : '';
                $toUsers = '[]';
                if (!empty($msg['to_info'])) {
                    if ($msg['to_info']['type'] === 'to_all') {
                        $toUsers = '["all"]';
                    } elseif (!empty($msg['to_info']['user_ids'])) {
                        $toUsers = json_encode($msg['to_info']['user_ids']);
                    }
                }
                $is_image_only = isImageOnlyContent($msg['content'] ?? '');
                    $use_cached_translation = !empty($msg['cached_translation']) && $currentLang !== 'ja';
                    $card_extra_class = $use_cached_translation ? ' showing-translation' : '';
                    $data_translated_attr = $use_cached_translation ? ' data-translated-content="' . htmlspecialchars($msg['cached_translation'], ENT_QUOTES) . '"' : '';
                $data_sender_name_attr = isset($msg['sender_name']) && $msg['sender_name'] !== '' ? ' data-sender-name="' . htmlspecialchars($msg['sender_name'], ENT_QUOTES) . '"' : '';
                ?><div class="message-card<?= $card_extra_class ?> <?= $is_own ? 'own' : '' ?> <?= $is_mentioned ? 'mentioned-me' : '' ?>" data-message-id="<?= $msg['id'] ?>" data-content="<?= htmlspecialchars($msg['content'], ENT_QUOTES) ?>" data-to-type="<?= htmlspecialchars($toType) ?>" data-to-users="<?= htmlspecialchars($toUsers) ?>" data-created-at="<?= $msg['created_at'] ?>" data-source-lang="<?= htmlspecialchars($source_lang ?? '') ?>" data-auto-translate="<?= ($is_auto_translate_target && !$is_own && !$is_image_only) ? '1' : '0' ?>" data-is-own="<?= $is_own ? '1' : '0' ?>"<?= $data_translated_attr ?><?= $data_sender_name_attr ?>>
                    <?php
                    $rid = isset($msg['reply_to_id']) && $msg['reply_to_id'] !== '' && (int)$msg['reply_to_id'] > 0 ? (int)$msg['reply_to_id'] : 0;
                    if ($rid):
                        $reply_content_raw = isset($msg['reply_to_content']) && $msg['reply_to_content'] !== '' ? $msg['reply_to_content'] : '';
                        $reply_sender_name = isset($msg['reply_to_sender_name']) && $msg['reply_to_sender_name'] !== '' ? $msg['reply_to_sender_name'] : '削除されたユーザー';
                        $reply_unavailable_label = $currentLang === 'en' ? 'Message deleted or unavailable' : ($currentLang === 'zh' ? '消息已删除或不可用' : '削除されたメッセージまたは利用できません');
                        $reply_text_html = $reply_content_raw !== '' ? nl2br(htmlspecialchars($reply_content_raw)) : htmlspecialchars($reply_unavailable_label);
                        $reply_has_more = $reply_content_raw !== '' && (strpos($reply_content_raw, "\n") !== false || mb_strlen($reply_content_raw) > 60);
                        $goto_label = $currentLang === 'en' ? 'Go to original message' : ($currentLang === 'zh' ? '跳转到原消息' : '元メッセージに移動');
                        $show_more_label = $currentLang === 'en' ? 'Show more' : ($currentLang === 'zh' ? '展开' : '続きを見る');
                        $show_less_label = $currentLang === 'en' ? 'Show less' : ($currentLang === 'zh' ? '收起' : '閉じる');
                    ?>
                    <div class="reply-preview reply-preview-collapsed" id="reply-preview-<?= $msg['id'] ?>" data-reply-to-id="<?= $rid ?>" data-owner-msg-id="<?= $msg['id'] ?>" onclick="handleReplyPreviewAreaClick(event, <?= $msg['id'] ?>, <?= $rid ?>)">
                        <span class="reply-preview-icon">↩️</span>
                        <span class="reply-preview-sender"><?= htmlspecialchars($reply_sender_name) ?></span>
                        <div class="reply-preview-body">
                            <span class="reply-preview-text"><?= $reply_text_html ?></span>
                            <?php if ($reply_has_more): ?>
                            <span class="reply-preview-links"><button type="button" class="reply-preview-toggle" onclick="event.stopPropagation(); toggleReplyPreviewExpand(<?= $msg['id'] ?>)"><?= $show_more_label ?></button><a href="#" class="reply-preview-goto" onclick="event.preventDefault(); event.stopPropagation(); scrollToMessage(<?= $rid ?>); return false;"><?= $goto_label ?></a></span>
                            <?php else: ?>
                            <span class="reply-preview-links"><a href="#" class="reply-preview-goto" onclick="event.preventDefault(); event.stopPropagation(); scrollToMessage(<?= $rid ?>); return false;"><?= $goto_label ?></a></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- ホバーアクションバー -->
                    <div class="message-hover-actions">
                        <button class="hover-action-btn" onclick="replyToMessage(<?= $msg['id'] ?>)" title="返信"><span class="icon">↩</span><span>返信</span></button>
                        <button class="hover-action-btn reaction-trigger" onclick="event.stopPropagation(); toggleReactionPicker(<?= $msg['id'] ?>, event)" title="リアクション"><span class="icon">😊</span><span>リアクション</span></button>
                        <button class="hover-action-btn" onclick="addToMemo(<?= $msg['id'] ?>)" title="ブックマーク"><span class="icon">🔖</span><span>保存</span></button>
                        <button class="hover-action-btn" onclick="addToTask(<?= $msg['id'] ?>)" title="タスク"><span class="icon">📋</span><span>タスク</span></button>
                        <?php if ($is_own): ?>
                        <button class="hover-action-btn" onclick="editMessage(<?= $msg['id'] ?>)" title="編集"><span class="icon">✏️</span><span>編集</span></button>
                        <button class="hover-action-btn" onclick="deleteMessage(<?= $msg['id'] ?>)" title="削除"><span class="icon">🗑️</span><span>削除</span></button>
                        <?php endif; ?>
                    </div>
                    <?php
                    // 送信者名のみ表示（To宛先は本文中の緑のTOチップで表示するため、グレーのTo行は出さない）
                    $sender_name_esc = htmlspecialchars($msg['sender_name'] ?? '');
                    if (!$is_own && $sender_name_esc !== '') {
                        echo '<div class="from-label message-sender-to-line"><span class="from-name">' . $sender_name_esc . '</span></div>';
                    }
                    ?>
                    <div class="content"><?php
                        $msgContent = ($use_cached_translation && !empty($msg['cached_translation'])) ? $msg['cached_translation'] : $msg['content'];
                        $originalMsgContent = $msg['content']; // 表示名抽出用に元の内容を保持
                        $has_editable_file = false;
                        $fileDisplayNameFromContent = ''; // 分離前に抽出した表示名
                        
                        // 本文テキストを [To:ID] 変換してHTML出力用に整形（ファイル分離パスでも使うので先に定義）
                        $toChipStyle = 'display:inline-block;background:#7cb342;color:#fff;padding:1px 8px;border-radius:4px;font-size:12px;font-weight:600;margin:2px 4px 2px 0;vertical-align:middle;line-height:1.6';
                        $formatMessageTextHtml = function($text) use ($members, $toChipStyle) {
                            $text = trim($text);
                            if ($text === '') return '';
                            $memberMap = [];
                            foreach ($members as $mem) {
                                $mid = isset($mem['id']) ? (int)$mem['id'] : (int)($mem['user_id'] ?? 0);
                                if ($mid) {
                                    $memberMap[$mid] = [
                                        'name' => $mem['display_name'] ?? $mem['name'] ?? 'ID:' . $mid,
                                    ];
                                }
                            }
                            $pos = 0;
                            $out = '';
                            while (preg_match('/\[To:(\d+)\]([^\n]*)/', $text, $m, PREG_OFFSET_CAPTURE, $pos)) {
                                $out .= nl2br(htmlspecialchars(substr($text, $pos, $m[0][1] - $pos)));
                                $tid = (int)$m[1][0];
                                $namePart = trim($m[2][0]);
                                $name = $namePart !== '' ? $namePart : (isset($memberMap[$tid]) ? $memberMap[$tid]['name'] : 'ID:' . $tid);
                                $out .= '<b data-to="' . $tid . '" style="' . $toChipStyle . '">TO ' . htmlspecialchars($name) . '</b>';
                                $pos = $m[0][1] + strlen($m[0][0]);
                            }
                            $out .= nl2br(htmlspecialchars(substr($text, $pos)));
                            return $out;
                        };
                        
                        // メッセージとファイル添付を分離して表示
                        // 絵文字: 📷📬📄📝📊📽️📎🎵📦📃📁
                        $fileEmojis = '[\x{1F4F7}\x{1F3AC}\x{1F4C4}\x{1F4DD}\x{1F4CA}\x{1F4FD}\x{1F4CE}\x{1F3B5}\x{1F4E6}\x{1F4C3}\x{1F4C1}]';
                        $filePathPattern = '(uploads\/messages\/|アップロード[\/\\\\／]メッセージ[\/\\\\／])';
                        $filePattern = '/^' . $fileEmojis . '?\s*' . $filePathPattern . '[^\s\n]+$/mu';
                        $hasFileAttachment = preg_match($filePattern, $msgContent);
                        
                        // 表示名付きファイル添付パターンを先にチェック: 📄 表示名\npath
                        $normalizedContent = preg_replace('/\r\n|\r/', "\n", trim($msgContent));
                        if (preg_match('/^([📄📷📬📝📊📽️📎🎵📦📃])\s*([^\n]+)\n((?:uploads\/messages\/|アップロード[\/\\\\／]メッセージ[\/\\\\／])[^\s\n]+)\s*$/su', $normalizedContent, $dnPreMatch)) {
                            $fileDisplayNameFromContent = trim($dnPreMatch[2]);
                            $msgContent = $dnPreMatch[3]; // ファイルパスのみで続行
                        }
                        // 複数行で、ファイルパスを含む場合はテキストとファイルを分離
                        elseif (strpos($msgContent, "\n") !== false && $hasFileAttachment) {
                            $lines = explode("\n", $msgContent);
                            $textLines = [];
                            $fileLine = '';
                            
                            foreach ($lines as $line) {
                                $trimmedLine = trim($line);
                                // ファイル添付行かどうかを判定（英語パスと日本語パス両対応、全角スラッシュ対応）
                                if (preg_match('/^' . $fileEmojis . '?\s*(uploads\/messages\/|アップロード[\/\\\\／]メッセージ[\/\\\\／])/u', $trimmedLine)) {
                                    $fileLine = $trimmedLine;
                                } elseif (!empty($trimmedLine)) {
                                    $textLines[] = $trimmedLine;
                                }
                            }
                            
                            // テキスト部分を先に表示（[To:ID]パターンをTOチップに変換）
                            if (!empty($textLines)) {
                                $textJoined = implode("\n", $textLines);
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $formatMessageTextHtml($textJoined) . '</div>';
                            }
                            
                            // ファイル部分を$msgContentに設定して続行
                            if (!empty($fileLine)) {
                                $msgContent = $fileLine;
                                $originalMsgContent = $fileLine; // テキスト部分は上で出力済みなので、$getTextBeforeFile の二重出力を防止
                            }
                        }
                        
                        // パス正規化関数（日本語パス→英語パス、ファイル名のみ→フルパス）
                        $normalizeFilePath = function($path) {
                            if (!$path) return $path;
                            // 全角スラッシュを半角に変換
                            $normalized = str_replace('／', '/', $path);
                            // 日本語パスを英語に変換（様々な区切り文字対応）
                            $normalized = preg_replace('/アップロード[\/\\\\]/', 'uploads/', $normalized);
                            $normalized = preg_replace('/メッセージ[\/\\\\]/', 'messages/', $normalized);
                            $normalized = str_replace('\\', '/', $normalized);
                            // ファイル名のみの場合はパスを追加
                            if (strpos($normalized, '/') === false) {
                                $normalized = 'uploads/messages/' . $normalized;
                            }
                            return $normalized;
                        };
                        
                        // ファイルより前のテキストを取得（メッセージ＋ファイル形式で本文を表示するため）
                        $getTextBeforeFile = function($fullContent, $filePathInContent) {
                            $pos = mb_strpos($fullContent, $filePathInContent);
                            if ($pos === false || $pos <= 0) return '';
                            $text = trim(mb_substr($fullContent, 0, $pos));
                            $text = preg_replace('/\n\s*[\x{1F4F7}\x{1F3AC}\x{1F4C4}\x{1F4DD}\x{1F4CA}\x{1F4FD}\x{1F4CE}\x{1F3B5}\x{1F4E6}\x{1F4C3}\x{1F4C1}]\s*[^\n]*\s*$/u', '', $text);
                            return trim($text);
                        };
                        // メンションのみで付いたTo（画像送信時など本文に[To:ID]が無い場合）をTOチップHTMLで返す
                        $renderToInfoChips = function() use ($msg, $members, $toChipStyle) {
                            $info = $msg['to_info'] ?? null;
                            if (empty($info)) return '';
                            $memberMap = [];
                            foreach ($members as $mem) {
                                $mid = isset($mem['id']) ? (int)$mem['id'] : (int)($mem['user_id'] ?? 0);
                                if ($mid) $memberMap[$mid] = ['name' => $mem['display_name'] ?? $mem['name'] ?? 'ID:' . $mid];
                            }
                            if (($info['type'] ?? '') === 'to_all') {
                                return '<b data-to="all" style="' . $toChipStyle . '">TO ALL</b>';
                            }
                            $userIds = $info['user_ids'] ?? [];
                            $users = $info['users'] ?? [];
                            if (empty($userIds)) return '';
                            $html = '';
                            foreach ($userIds as $i => $uid) {
                                $name = isset($users[$i]) ? $users[$i] : (isset($memberMap[$uid]) ? $memberMap[$uid]['name'] : 'ID:' . $uid);
                                $html .= '<b data-to="' . (int)$uid . '" style="' . $toChipStyle . '">TO ' . htmlspecialchars($name) . '</b> ';
                            }
                            return $html;
                        };
                        
                        // 外部GIF URL（Giphy, Tenor, その他のGIF画像）
                        if (preg_match('/(https?:\/\/[^\s]+\.gif(\?[^\s]*)?)/i', $msgContent, $matches)) {
                            $gifUrl = $matches[1];
                            echo '<img src="' . htmlspecialchars($gifUrl) . '" alt="GIF画像" loading="lazy" style="max-width:100%;max-height:250px;border-radius:12px;cursor:pointer;display:block;" onclick="openMediaViewer(\'image\', \'' . htmlspecialchars($gifUrl) . '\', \'GIF\')" onerror="this.onerror=null;this.style.display=\'none\';this.insertAdjacentHTML(\'afterend\', \'<a href=\\\'' . htmlspecialchars($gifUrl) . '\\\' target=_blank>GIFを開く</a>\');">';
                        // 外部画像URL（一般的な画像リンク）
                        } elseif (preg_match('/(https?:\/\/[^\s]+\.(jpg|jpeg|png|webp)(\?[^\s]*)?)/i', $msgContent, $matches)) {
                            $imageUrl = $matches[1];
                            echo '<img src="' . htmlspecialchars($imageUrl) . '" alt="添付画像" loading="lazy" style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;" onclick="openMediaViewer(\'image\', \'' . htmlspecialchars($imageUrl) . '\', \'画像\')" onerror="this.onerror=null;this.outerHTML=\'<a href=\\\'' . htmlspecialchars($imageUrl) . '\\\' target=_blank>' . htmlspecialchars($imageUrl) . '</a>\';">';
                        // ローカル画像ファイル（英語パス、日本語パス、ファイル名のみすべて対応）
                        } elseif (($isLocalImage = (
                            preg_match('/(uploads\/messages\/[^\s\n]+\.(jpg|jpeg|png|gif|webp))/i', $msgContent, $m1) ? ($matches = $m1) :
                            (preg_match('/(アップロード[\/\\\\]メッセージ[\/\\\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', $msgContent, $m2) ? ($matches = $m2) :
                            (preg_match('/((?:msg_|screenshot_|スクリーンショット_)[^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', $msgContent, $m3) ? ($matches = $m3) :
                            (preg_match('/(?:^|[\s\x{1F4F7}])([^\s\n]+\.(jpg|jpeg|png|gif|webp))$/imu', $msgContent, $m4) ? ($matches = $m4) : false)))
                        ))) {
                            $rawPath = $matches[1];
                            $toChipsImg = $renderToInfoChips();
                            if ($toChipsImg !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $toChipsImg . '</div>';
                            }
                            $textBeforeImage = $getTextBeforeFile($originalMsgContent, $rawPath);
                            if ($textBeforeImage !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $formatMessageTextHtml($textBeforeImage) . '</div>';
                            }
                            $imagePath = $normalizeFilePath($rawPath);
                            echo '<img src="' . htmlspecialchars($imagePath) . '" alt="添付画像" loading="lazy" style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;" onclick="openMediaViewer(\'image\', \'' . htmlspecialchars($imagePath) . '\', \'画像\')" onerror="this.onerror=null;this.style.background=\'#f0f0f0\';this.style.padding=\'20px\';this.alt=\'画像を読み込めません\';">';
                        // ローカル動画ファイル（英語パス、日本語パス、ファイル名のみすべて対応）
                        } elseif (($isLocalVideo = (
                            preg_match('/(uploads\/messages\/[^\s\n]+\.(mp4|webm|ogg))/i', $msgContent, $m1) ? ($matches = $m1) :
                            (preg_match('/(アップロード[\/\\\\]メッセージ[\/\\\\][^\s\n]+\.(mp4|webm|ogg))/iu', $msgContent, $m2) ? ($matches = $m2) :
                            (preg_match('/((?:msg_|video_)[^\s\n]+\.(mp4|webm|ogg))/iu', $msgContent, $m3) ? ($matches = $m3) :
                            (preg_match('/(?:^|[\s])([^\s\n]+\.(mp4|webm|ogg))$/imu', $msgContent, $m4) ? ($matches = $m4) : false)))
                        ))) {
                            $rawPath = $matches[1];
                            $toChipsVid = $renderToInfoChips();
                            if ($toChipsVid !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $toChipsVid . '</div>';
                            }
                            $textBeforeVideo = $getTextBeforeFile($originalMsgContent, $rawPath);
                            if ($textBeforeVideo !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $formatMessageTextHtml($textBeforeVideo) . '</div>';
                            }
                            $videoPath = $normalizeFilePath($rawPath);
                            echo '<video src="' . htmlspecialchars($videoPath) . '" controls style="max-width:100%;max-height:300px;border-radius:8px;"></video>';
                            echo '<div style="margin-top:4px;"><button onclick="openMediaViewer(\'video\', \'' . htmlspecialchars($videoPath) . '\', \'動画\')" style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;">🔍 拡大再生</button></div>';
                        // PDFファイル（アップロード済みパスのみ。本文中の.filename.pdf参照はファイル扱いしない）
                        } elseif (preg_match('/(uploads\/messages\/[^\s\n]+\.pdf)/i', $msgContent, $matches) ||
                                  preg_match('/(アップロード[\/\\\\／]メッセージ[\/\\\\／][^\s\n]+\.pdf)/u', $msgContent, $matches)) {
                            $rawPath = $matches[1];
                            $toChipsPdf = $renderToInfoChips();
                            if ($toChipsPdf !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $toChipsPdf . '</div>';
                            }
                            $textBeforePdf = $getTextBeforeFile($originalMsgContent, $rawPath);
                            if ($textBeforePdf !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $formatMessageTextHtml($textBeforePdf) . '</div>';
                            }
                            $pdfPath = $normalizeFilePath($rawPath);
                            $pdfFileName = basename($rawPath);
                            // 表示名の取得: 1. 分離時に抽出済み 2. 元のコンテンツからregex 3. フォールバック=basename
                            if (!empty($fileDisplayNameFromContent)) {
                                $pdfFileName = $fileDisplayNameFromContent;
                            } elseif (preg_match('/[📄📷📬📝📊📽️📎🎵📦📃]\s*([^\n]+)\n\s*(?:uploads[\/\\\\]messages[\/\\\\][^\s\n]+\.pdf)/iu', $originalMsgContent, $dnMatch) && strpos($dnMatch[1], 'uploads') === false) {
                                $pdfFileName = trim($dnMatch[1]);
                            }
                            $has_editable_file = true;
                            echo '<div class="file-attachment-card" data-file-path="' . htmlspecialchars($pdfPath) . '" data-file-display-name="' . htmlspecialchars($pdfFileName) . '" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
                            echo '<span style="font-size:32px;">📄</span>';
                            echo '<div class="file-attachment-card__title" style="flex:1;min-width:0;overflow:hidden;padding:4px 0;">';
                            echo '<div style="font-weight:500;color:var(--text);text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">' . htmlspecialchars($pdfFileName) . '</div>';
                            echo '<div style="font-size:11px;color:var(--text-light);">PDF ドキュメント</div>';
                            echo '</div>';
                            echo '<a href="' . htmlspecialchars($pdfPath) . '" target="_blank" style="background:var(--bg-hover);color:var(--text);border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;flex-shrink:0;">開く</a>';
                            echo '<button onclick="openMediaViewer(\'pdf\', \'' . htmlspecialchars($pdfPath) . '\', \'PDF\')" style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;flex-shrink:0;">プレビュー</button>';
                            if ($is_own) {
                                echo '<button type="button" class="js-edit-file-display-name" data-edit-file-message-id="' . (int)$msg['id'] . '" onclick="openEditFileDisplayNameModal(' . (int)$msg['id'] . '); return false;" title="名前を変更" style="background:none;color:var(--text-light);border:none;padding:4px 6px;border-radius:4px;cursor:pointer;font-size:14px;flex-shrink:0;" onmouseover="this.style.color=\'var(--primary)\'" onmouseout="this.style.color=\'var(--text-light)\'">✏️</button>';
                            }
                            echo '</div>';
                        // Word/Excel/PowerPointファイル（絵文字プレフィックス、日本語パス、様々な区切り文字に対応）
                        } elseif (preg_match('/(uploads\/messages\/[^\s\n]+\.(docx?|xlsx?|pptx?))/i', $msgContent, $matches) ||
                                  preg_match('/(アップロード[\/\\\\／]メッセージ[\/\\\\／][^\s\n]+\.(docx?|xlsx?|pptx?))/u', $msgContent, $matches) ||
                                  preg_match('/([a-zA-Z0-9_\-]+\.(docx?|xlsx?|pptx?))$/i', $msgContent, $matches) ||
                                  (preg_match('/\.(docx?|xlsx?|pptx?)$/i', $msgContent) && preg_match('/([^\s\n\x{1F300}-\x{1F9FF}]+\.(docx?|xlsx?|pptx?))/iu', $msgContent, $matches))) {
                            $rawPath = $matches[1];
                            $toChipsOffice = $renderToInfoChips();
                            if ($toChipsOffice !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $toChipsOffice . '</div>';
                            }
                            $textBeforeOffice = $getTextBeforeFile($originalMsgContent, $rawPath);
                            if ($textBeforeOffice !== '') {
                                echo '<div class="message-text-part" style="margin-bottom:8px;">' . $formatMessageTextHtml($textBeforeOffice) . '</div>';
                            }
                            $has_editable_file = true;
                            $filePath = $normalizeFilePath($rawPath);
                            $ext = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
                            $emoji = '📎';
                            $typeName = 'ファイル';
                            if (in_array($ext, ['doc', 'docx'])) { $emoji = '📝'; $typeName = 'Word'; }
                            elseif (in_array($ext, ['xls', 'xlsx'])) { $emoji = '📊'; $typeName = 'Excel'; }
                            elseif (in_array($ext, ['ppt', 'pptx'])) { $emoji = '📽️'; $typeName = 'PowerPoint'; }
                            $officeFileName = basename($rawPath);
                            // 表示名の取得: 1. 分離時に抽出済み 2. 元のコンテンツからregex 3. フォールバック=basename
                            if (!empty($fileDisplayNameFromContent)) {
                                $officeFileName = $fileDisplayNameFromContent;
                            } elseif (preg_match('/[📄📷📬📝📊📽️📎🎵📦📃]\s*([^\n]+)\n\s*(?:uploads[\/\\\\]messages[\/\\\\][^\s\n]+\.(?:docx?|xlsx?|pptx?))/iu', $originalMsgContent, $odMatch) && strpos($odMatch[1], 'uploads') === false) {
                                $officeFileName = trim($odMatch[1]);
                            }
                            echo '<div class="file-attachment-card" data-file-path="' . htmlspecialchars($filePath) . '" data-file-display-name="' . htmlspecialchars($officeFileName) . '" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
                            echo '<span style="font-size:32px;">' . $emoji . '</span>';
                            echo '<div class="file-attachment-card__title" style="flex:1;min-width:0;overflow:hidden;padding:4px 0;">';
                            echo '<div style="font-weight:500;color:var(--text);text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">' . htmlspecialchars($officeFileName) . '</div>';
                            echo '<div style="font-size:11px;color:var(--text-light);">' . $typeName . '</div>';
                            echo '</div>';
                            echo '<a href="' . htmlspecialchars($filePath) . '" download="' . htmlspecialchars($officeFileName) . '" style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;">⬇ ダウンロード</a>';
                            if ($is_own) {
                                echo '<button type="button" class="js-edit-file-display-name" data-edit-file-message-id="' . (int)$msg['id'] . '" onclick="openEditFileDisplayNameModal(' . (int)$msg['id'] . '); return false;" title="名前を変更" style="background:none;color:var(--text-light);border:none;padding:4px 6px;border-radius:4px;cursor:pointer;font-size:14px;flex-shrink:0;" onmouseover="this.style.color=\'var(--primary)\'" onmouseout="this.style.color=\'var(--text-light)\'">✏️</button>';
                            }
                            echo '</div>';
                        // 音声ファイル（アップロード済みパスのみ）
                        } elseif (preg_match('/(uploads\/messages\/[^\s\n]+\.(mp3|wav|ogg|m4a))/i', $msgContent, $matches) ||
                                  preg_match('/(アップロード[\/\\\\／]メッセージ[\/\\\\／][^\s\n]+\.(mp3|wav|ogg|m4a))/u', $msgContent, $matches)) {
                            $rawPath = $matches[1];
                            $audioPath = $normalizeFilePath($rawPath);
                            echo '<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;">';
                            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"><span style="font-size:24px;">🎵</span><span style="font-size:13px;color:var(--text-light);">' . htmlspecialchars(basename($rawPath)) . '</span></div>';
                            echo '<audio src="' . htmlspecialchars($audioPath) . '" controls style="width:100%;"></audio>';
                            echo '</div>';
                        // 圧縮ファイル（絵文字プレフィックス、日本語パス、様々な区切り文字に対応）
                        } elseif (preg_match('/(uploads\/messages\/[^\s\n]+\.(zip|rar|7z))/i', $msgContent, $matches) ||
                                  preg_match('/(アップロード[\/\\\\／]メッセージ[\/\\\\／][^\s\n]+\.(zip|rar|7z))/u', $msgContent, $matches) ||
                                  (preg_match('/\.(zip|rar|7z)$/i', $msgContent) && preg_match('/([^\s\n\x{1F300}-\x{1F9FF}]+\.(zip|rar|7z))/iu', $msgContent, $matches))) {
                            $rawPath = $matches[1];
                            $filePath = $normalizeFilePath($rawPath);
                            echo '<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;">';
                            echo '<span style="font-size:32px;">📦</span>';
                            echo '<div class="file-attachment-card__title" style="flex:1;overflow:hidden;"><div style="font-weight:500;color:var(--text);text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">' . htmlspecialchars(basename($rawPath)) . '</div><div style="font-size:11px;color:var(--text-light);">圧縮ファイル</div></div>';
                            echo '<a href="' . htmlspecialchars($filePath) . '" download style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;">⬇ ダウンロード</a>';
                            echo '</div>';
                        // テキスト/CSV/JSONファイル（アップロード済みパスのみ）
                        } elseif (preg_match('/(uploads\/messages\/[^\s\n]+\.(txt|csv|json|xml|html|css|js))/i', $msgContent, $matches) ||
                                  preg_match('/(アップロード[\/\\\\／]メッセージ[\/\\\\／][^\s\n]+\.(txt|csv|json|xml|html|css|js))/u', $msgContent, $matches)) {
                            $rawPath = $matches[1];
                            $filePath = $normalizeFilePath($rawPath);
                            $ext = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
                            $typeName = strtoupper($ext);
                            echo '<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;">';
                            echo '<span style="font-size:32px;">📃</span>';
                            echo '<div class="file-attachment-card__title" style="flex:1;overflow:hidden;"><div style="font-weight:500;color:var(--text);text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">' . htmlspecialchars(basename($rawPath)) . '</div><div style="font-size:11px;color:var(--text-light);">' . $typeName . '</div></div>';
                            echo '<a href="' . htmlspecialchars($filePath) . '" target="_blank" style="background:var(--bg-hover);color:var(--text);border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;margin-right:4px;">👁 開く</a>';
                            echo '<a href="' . htmlspecialchars($filePath) . '" download style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;">⬇</a>';
                            echo '</div>';
                        // 通常のテキスト（[To:ID]名前 をChatwork風TOチップに変換）
                        } else {
                            $memberMap = [];
                            foreach ($members as $mem) {
                                $mid = isset($mem['id']) ? (int)$mem['id'] : (int)($mem['user_id'] ?? 0);
                                if ($mid) {
                                    $memberMap[$mid] = [
                                        'name' => $mem['display_name'] ?? $mem['name'] ?? 'ID:' . $mid,
                                        'avatar' => $mem['avatar_path'] ?? $mem['avatar'] ?? ''
                                    ];
                                }
                            }
                            // 本文に[To:ID]が無いが to_info がある場合（Toボタンのみで送信した場合）はメンションからTOチップを表示
                            $hasToInContent = preg_match('/\[To:(?:\d+|all)\]/i', $msgContent);
                            if (!$hasToInContent) {
                                $toChipsPlain = $renderToInfoChips();
                                if ($toChipsPlain !== '') {
                                    echo '<div class="message-text-part" style="margin-bottom:8px;">' . $toChipsPlain . '</div>';
                                }
                            }
                            echo $formatMessageTextHtml($msgContent);
                        }
                    ?></div>
                    <?php if (!empty($msg['reaction_details'])): ?>
                    <div class="message-reactions">
                        <?php foreach ($msg['reaction_details'] as $r):
                            $names = !empty($r['users']) ? implode(', ', array_column($r['users'], 'name')) : '';
                            $title = $names !== '' ? htmlspecialchars($names, ENT_QUOTES, 'UTF-8') : 'クリックでリアクション';
                        ?>
                        <span class="reaction-badge<?= $r['is_mine'] ? ' my-reaction' : '' ?>" onclick="addReaction(<?= (int)$msg['id'] ?>, <?= json_encode($r['type'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)" title="<?= $title ?>"><?= htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="message-footer">
                        <span class="timestamp"><?= date('Y年n月j日 H:i', strtotime($msg['created_at'])) ?></span>
                        <div class="message-actions-inline">
                            <button class="inline-action-btn" onclick="replyToMessage(<?= $msg['id'] ?>)" title="返信">↩ 返信</button>
                            <button class="inline-action-btn reaction-trigger" onclick="event.stopPropagation(); toggleReactionPicker(<?= $msg['id'] ?>, event)" title="リアクション">😊 リアクション</button>
                            <button class="inline-action-btn" onclick="addToMemo(<?= $msg['id'] ?>)" title="メモ">📝 メモ</button>
                            <button class="inline-action-btn" onclick="addToTask(<?= $msg['id'] ?>)" title="タスク">📋 タスク</button>
                            <?php if ($is_own): ?>
                            <button class="inline-action-btn" onclick="editMessage(<?= $msg['id'] ?>)" title="編集">✏️ 編集</button>
                            <button class="inline-action-btn danger" onclick="deleteMessage(<?= $msg['id'] ?>)" title="削除">🗑️ 削除</button>
                            <?php endif; ?>
                            <button class="inline-action-btn translate-btn" onclick="toggleTranslation(<?= $msg['id'] ?>)" title="<?= ($use_cached_translation || $is_auto_translate_target) ? '原文を表示' : '翻訳' ?>" data-mode="<?= $use_cached_translation ? 'original' : ($is_auto_translate_target ? 'auto' : 'manual') ?>"><?= ($use_cached_translation || $is_auto_translate_target) ? '🌍' : '🌐' ?></button>
                        </div>
                    </div>
                </div>
                <?php
                $msg_created_ts = strtotime($msg['created_at']);
                $within_3_days = $msg_created_ts >= (time() - 3 * 24 * 60 * 60);
                if ($is_mentioned && !$is_own && !$is_system && $within_3_days):
                ?>
                <div class="ai-reply-suggest-bar" data-msg-id="<?= (int)$msg['id'] ?>">
                    <button type="button" class="ai-reply-suggest-btn" onclick="AIReplySuggest.generate(<?= (int)$msg['id'] ?>, <?= (int)$selected_conversation_id ?>, this)">🤖 AI返信提案を生成</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <!-- 共有フォルダビュー -->
            <div class="sv-vault-view" id="storageVaultView" style="display:none;">
                <div class="sv-vault-header">
                    <button class="sv-back-btn" onclick="closeStorageVault()" title="チャットに戻る">← チャット</button>
                    <div class="sv-breadcrumbs" id="svBreadcrumbs"></div>
                    <div class="sv-header-actions">
                        <input type="text" class="sv-search-input" id="svSearchInput" placeholder="ファイル・フォルダを検索..." oninput="storageSearch(this.value)">
                        <button class="sv-trash-btn" onclick="openStorageTrash()" title="ゴミ箱">🗑️</button>
                    </div>
                </div>
                <div class="sv-usage-header" id="svUsageHeader">
                    <div class="sv-usage-bar-lg">
                        <div class="sv-usage-bar-fill-lg" id="svUsageBarFillLg" style="width:0%"></div>
                    </div>
                    <span class="sv-usage-text-lg" id="svUsageTextLg">-- / --</span>
                </div>
                <div class="sv-content" id="svContent">
                    <div class="sv-drop-zone" id="svDropZone">
                        <div class="sv-drop-overlay" id="svDropOverlay">
                            <div class="sv-drop-overlay-inner">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <p>ここにファイルをドロップ</p>
                            </div>
                        </div>
                        <div class="sv-toolbar" id="svToolbar">
                            <button class="sv-new-folder-btn" onclick="createNewFolder()">+ 新規フォルダ</button>
                            <button class="sv-upload-btn" onclick="document.getElementById('svFileInput').click()">アップロード</button>
                            <button class="sv-upload-btn sv-album-btn" onclick="document.getElementById('svAlbumInput').click()">アルバムで追加（最大50枚）</button>
                            <input type="file" id="svFileInput" style="display:none" multiple onchange="handleStorageFileSelect(this)">
                            <input type="file" id="svAlbumInput" style="display:none" accept="image/*" multiple onchange="handleStorageAlbumSelect(this)">
                        </div>
                        <div class="sv-shared-section" id="svSharedSection" style="display:none;">
                            <h4 class="sv-section-title">他グループから共有</h4>
                            <div id="svSharedGrid"></div>
                        </div>
                        <div class="sv-list-header" id="svListHeader">
                            <span class="sv-lh-name">名前</span>
                            <span class="sv-lh-date">更新日時</span>
                            <span class="sv-lh-type">種類</span>
                            <span class="sv-lh-size">サイズ</span>
                        </div>
                        <div id="svGrid"></div>
                        <div class="sv-empty" id="svEmpty" style="display:none;">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                            <p>フォルダが空です</p>
                            <p class="sv-empty-hint">フォルダを作成するかファイルをドラッグ&ドロップしてください</p>
                        </div>
                        <div class="sv-search-results" id="svSearchResults" style="display:none;"></div>
                        <div class="sv-trash-view" id="svTrashView" style="display:none;">
                            <div class="sv-trash-header">
                                <h3>ゴミ箱</h3>
                                <p class="sv-trash-info">削除したファイルは30日間保持されます。30日を過ぎると自動的に完全削除されます。</p>
                                <button class="sv-empty-trash-btn" onclick="emptyTrash()">ゴミ箱を空にする</button>
                            </div>
                            <div id="svTrashGrid"></div>
                        </div>
                    </div>
                </div>
                <div class="sv-upload-progress" id="svUploadProgress" style="display:none;"></div>
                <!-- フォルダ作成ダイアログ -->
                <div class="sv-folder-dialog" id="svFolderDialog" style="display:none;">
                    <div class="sv-folder-dialog-inner">
                        <h4>新しいフォルダ</h4>
                        <input type="text" id="svFolderNameInput" class="sv-folder-name-input" placeholder="フォルダ名" maxlength="100" autocomplete="off">
                        <div class="sv-folder-dialog-btns">
                            <button type="button" class="sv-folder-dialog-cancel" onclick="closeFolderDialog()">キャンセル</button>
                            <button type="button" class="sv-folder-dialog-ok" onclick="submitNewFolder()">作成</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 保管庫プレビューモーダル -->
            <div class="sv-preview-modal" id="svPreviewModal" style="display:none;">
                <div class="sv-preview-header">
                    <span class="sv-preview-title" id="svPreviewTitle"></span>
                    <div class="sv-preview-actions">
                        <button onclick="downloadPreviewFile()" title="ダウンロード">⬇</button>
                        <button onclick="closeStoragePreview()">×</button>
                    </div>
                </div>
                <div class="sv-preview-content" id="svPreviewContent"></div>
            </div>
            
            <!-- 保管庫共有モーダル -->
            <div class="sv-share-modal" id="svShareModal" style="display:none;">
                <div class="sv-share-inner">
                    <div class="sv-share-header">
                        <h3>フォルダ共有設定</h3>
                        <button onclick="closeShareModal()">×</button>
                    </div>
                    <div class="sv-share-body" id="svShareBody"></div>
                </div>
            </div>
            
            <!-- 保管庫権限モーダル -->
            <div class="sv-perm-modal" id="svPermModal" style="display:none;">
                <div class="sv-perm-inner">
                    <div class="sv-perm-header">
                        <h3>メンバー権限設定</h3>
                        <button onclick="closePermModal()">×</button>
                    </div>
                    <div class="sv-perm-body" id="svPermBody"></div>
                </div>
            </div>
            
            <!-- 保管庫フォルダパスワード設定モーダル -->
            <div class="sv-password-modal" id="svPasswordModal" style="display:none;">
                <div class="sv-password-inner">
                    <div class="sv-password-header">
                        <h3>パスワード設定</h3>
                        <button type="button" onclick="closePasswordModal()">×</button>
                    </div>
                    <div class="sv-password-body">
                        <p class="sv-password-desc">このフォルダを開く際に入力するパスワードを設定します。空欄のまま設定するとパスワードを解除します。</p>
                        <label class="sv-password-label">新しいパスワード（4文字以上）</label>
                        <input type="password" id="svPasswordInput" class="sv-password-input" placeholder="入力して設定 / 空で解除" autocomplete="new-password">
                        <div class="sv-password-actions">
                            <button type="button" class="sv-password-btn sv-password-btn-primary" onclick="submitFolderPassword()">設定</button>
                            <button type="button" class="sv-password-btn" onclick="closePasswordModal()">キャンセル</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="input-area" id="inputArea">
                <div class="input-area-resize-handle" id="inputAreaResizeHandle" title="ドラッグで入力欄の高さを変更" aria-label="入力欄の高さを変更"></div>
                <form id="messageForm" method="post" action="#" onsubmit="if(typeof sendMessage==='function'){sendMessage();} return false;" aria-label="メッセージ送信">
                <div class="input-container">
                    <div class="edit-mode-bar" id="editModeBar" style="display:none;">
                        <span class="edit-mode-icon">✏️</span>
                        <span class="edit-mode-text">メッセージを編集中</span>
                        <button class="edit-mode-cancel" onclick="cancelEdit()">✕ キャンセル</button>
                    </div>
                    <div class="reply-mode-bar" id="replyModeBar" style="display:none;">
                        <span class="reply-mode-icon">↩️</span>
                        <div class="reply-mode-content">
                            <span class="reply-mode-label">返信先:</span>
                            <span class="reply-mode-sender" id="replySender"></span>
                            <span class="reply-mode-text" id="replyPreview"></span>
                        </div>
                        <button class="reply-mode-cancel" onclick="cancelReply()">✕</button>
                    </div>
                    <div class="to-row-bar" id="toRowBar" style="display:none;">
                        <span class="to-row-label">To:</span>
                        <div class="to-row-chips" id="toRowChips"></div>
                    </div>
                    <div class="input-toolbar">
                        <div class="input-toolbar-left">
                            <button type="button" class="toolbar-btn to-btn" id="toBtn" title="To" aria-label="To">To</button>
                            <button class="toolbar-btn gif-btn" title="絵文字・GIF" onclick="toggleEmojiPicker()">GIF</button>
                            <button class="toolbar-btn call-toolbar-btn" title="通話" onclick="openCallMenu(event)"><span class="btn-icon">☎</span></button>
                            <button type="button" class="toolbar-btn attach-btn" id="mainAttachBtn" title="ファイル・写真添付" aria-label="ファイル・写真添付"><span class="btn-icon">⊕</span></button>
                            <button type="button" class="toolbar-btn mic-btn" id="chatMicBtn" title="音声入力" aria-label="音声入力"><span class="btn-icon btn-icon-mic" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 2a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V5a3 3 0 0 1 3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg></span></button>
                        </div>
                        <div class="input-toolbar-right">
                            <label class="enter-send-label">
                                <input type="checkbox" id="enterSendCheck" onchange="saveEnterSendSetting(this.checked)"> <?= __('enter_to_send_label') ?>
                            </label>
                            <button class="toolbar-toggle-btn" onclick="toggleInputArea()" title="入力欄を非表示">☰</button>
                            <button class="input-send-btn theme-action-btn" onclick="sendMessage()" title="送信" aria-label="送信">➤</button>
                        </div>
                    </div>
                    <div class="input-row">
                        <div class="input-wrapper">
                            <textarea id="messageInput" class="message-input" placeholder="<?= $is_mobile_request ? __('message_input_placeholder') : __('message_input_placeholder') . "&#10;" . __('shift_enter_hint') ?>" rows="1" style="min-height:168px;max-height:280px;height:168px" onkeydown="handleKeyDown(event)" oninput="autoResizeInput(this)"></textarea>
                        </div>
                    </div>
                </div>
                </form>
                <input type="file" id="fileInput" style="display:none;" aria-label="ファイルを添付">
                <input type="file" id="galleryInput" style="display:none;" accept="image/png,image/jpeg,image/heic,image/webp,image/gif" onchange="handleFileSelect(this)" aria-label="写真を選ぶ（LINE同様にギャラリーが開きます）">
                <input type="file" id="imageInput" style="display:none;" accept="application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,image/png,image/jpeg,image/heic,image/webp,image/gif,video/*,application/*" onchange="handleFileSelect(this)" aria-label="ファイル・画像を添付">
            </div>
            <button class="input-show-btn" id="inputShowBtn" onclick="showInputArea()" title="入力欄を表示">💬</button>
            <?php else: ?>
            <div class="center-panel-empty-state" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:white;text-shadow:0 2px 4px rgba(0,0,0,0.3);">
                <div class="center-panel-empty-state-prompt">
                    <div style="font-size:64px;margin-bottom:16px;">💬</div>
                    <h3 style="font-size:20px;margin-bottom:8px;">会話を選択してください</h3>
                    <p style="opacity:0.8;">左のリストから会話を選択するか、新しい会話を始めましょう</p>
                    <button class="add-media-btn" style="margin-top:20px;width:auto;padding:12px 24px;" onclick="openNewConversation()">➕ 新しい会話を始める</button>
                </div>
                <nav class="center-panel-start-menu" id="centerPanelStartMenu" aria-label="メニュー">
                    <a href="javascript:void(0)" class="center-panel-start-menu-item" data-action="groups"><span class="center-panel-start-menu-icon"><img src="assets/icons/line/users.svg" alt="" class="icon-line" width="20" height="20" aria-hidden="true"></span><span class="center-panel-start-menu-label">チャット</span></a>
                    <a href="javascript:void(0)" class="center-panel-start-menu-item" data-action="storage-new"><span class="center-panel-start-menu-icon"><img src="assets/icons/line/folder.svg" alt="" class="icon-line" width="20" height="20" aria-hidden="true"></span><span class="center-panel-start-menu-label">共有フォルダ</span></a>
                    <a href="tasks.php" class="center-panel-start-menu-item"><span class="center-panel-start-menu-icon"><img src="assets/icons/line/clipboard.svg" alt="" class="icon-line" width="20" height="20" aria-hidden="true"></span><span class="center-panel-start-menu-label">タスク/メモ</span></a>
                    <a href="notifications.php" class="center-panel-start-menu-item"><span class="center-panel-start-menu-icon"><img src="assets/icons/line/bell.svg" alt="" class="icon-line" width="20" height="20" aria-hidden="true"></span><span class="center-panel-start-menu-label">お知らせ</span></a>
                    <a href="settings.php" class="center-panel-start-menu-item"><span class="center-panel-start-menu-icon"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="20" height="20" aria-hidden="true"></span><span class="center-panel-start-menu-label">設定</span></a>
                </nav>
            </div>
            <?php endif; ?>
            
            <!-- メディアビューアー -->
            <div class="media-viewer" id="mediaViewer" style="display:none;">
                <div class="media-viewer-header">
                    <span class="media-viewer-title" id="mediaViewerTitle">メディア</span>
                    <button class="media-viewer-close" onclick="closeMediaViewer()">×</button>
                </div>
                <div class="media-viewer-content" id="mediaViewerContent"></div>
            </div>
        </main>
        
        <!-- 右パネルリサイズハンドル -->
        <div class="panel-resize-handle panel-resize-right" id="resizeRightHandle" title="ドラッグで右パネル幅を変更" aria-label="右パネル幅を変更"></div>
        
        <?php include __DIR__ . '/includes/chat/rightpanel.php'; ?>
        <?php include __DIR__ . '/includes/chat/rightpanel_secretary.php'; ?>
        </div>
        <!-- / .mobile-pages-strip -->
        <script>
        (function(){
            var m = document.getElementById('mainContainer');
            if (!m) return;
            var hasConversation = <?= $selected_conversation_id ? 'true' : 'false' ?>;
            function ensureMessagesAreaVisible() {
                if (window.innerWidth > 768 || !hasConversation) return;
                var ma = document.getElementById('messagesArea');
                if (!ma) return;
                ma.style.setProperty('min-height', '55vh', 'important');
                ma.style.setProperty('height', 'auto', 'important');
                ma.style.setProperty('display', 'block', 'important');
                ma.style.setProperty('overflow-y', 'auto', 'important');
                ma.style.setProperty('visibility', 'visible', 'important');
                ma.style.setProperty('opacity', '1', 'important');
                var panel = ma.closest('.center-panel');
                if (panel) {
                    panel.style.setProperty('display', 'flex', 'important');
                    panel.style.setProperty('flex-direction', 'column', 'important');
                    panel.style.setProperty('min-height', '60vh', 'important');
                }
            }
            function go() {
                if (window.innerWidth <= 768) {
                    if (hasConversation) {
                        m.scrollLeft = window.innerWidth;
                        ensureMessagesAreaVisible();
                    } else {
                        m.scrollLeft = 0; /* 携帯: グループ一覧を最初に表示 */
                    }
                }
            }
            go();
            ensureMessagesAreaVisible();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (window.innerWidth <= 768 && typeof history !== 'undefined') { try { history.scrollRestoration = 'manual'; } catch (e) {} }
                    go();
                    ensureMessagesAreaVisible();
                });
            } else {
                go();
                ensureMessagesAreaVisible();
            }
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function() { go(); ensureMessagesAreaVisible(); });
            }
            setTimeout(function() { go(); ensureMessagesAreaVisible(); }, 0);
            setTimeout(function() { go(); ensureMessagesAreaVisible(); }, 50);
            setTimeout(function() { go(); ensureMessagesAreaVisible(); }, 150);
            setTimeout(function() { go(); ensureMessagesAreaVisible(); }, 400);
            window.addEventListener('load', function() { go(); ensureMessagesAreaVisible(); });
            window.addEventListener('pageshow', function(ev) {
                if (window.innerWidth <= 768 && !hasConversation) { m.scrollLeft = 0; }
                go();
                ensureMessagesAreaVisible();
            });
            if (typeof ResizeObserver === 'function') {
                try {
                    var ro = new ResizeObserver(function() {
                        if (window.innerWidth <= 768 && hasConversation && m.scrollLeft < window.innerWidth * 0.5) {
                            m.scrollLeft = window.innerWidth;
                        }
                        ensureMessagesAreaVisible();
                    });
                    ro.observe(m);
                    var maEl = document.getElementById('messagesArea');
                    if (maEl) ro.observe(maEl);
                } catch (e) {}
            }
        })();
        </script>
    </div>
    
    <?php include __DIR__ . '/includes/chat/call-ui.php'; ?>
    <?php include __DIR__ . '/includes/chat/modals.php'; ?>
    <?php include __DIR__ . '/includes/chat/member-popup.php'; ?>
    <!-- 表示名変更案内モーダル -->
    <div id="displayNamePromptModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>🎉 ようこそ <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?> へ！</h3>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 16px; color: #666;">
                    現在の表示名がメールアドレスになっています。<br>
                    他のユーザーに表示される名前を設定しましょう。
                </p>
                <div class="form-group">
                    <label style="font-weight: 500; margin-bottom: 8px; display: block;">新しい表示名</label>
                    <input type="text" id="newDisplayNameInput" class="form-input" 
                           placeholder="ニックネームや本名を入力" 
                           maxlength="50" 
                           style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>
            </div>
            <div class="modal-footer" style="display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="skipDisplayNameChange()">後で設定する</button>
                <button class="btn btn-primary" onclick="saveNewDisplayName()">設定する</button>
            </div>
        </div>
    </div>
    
    
    <script src="assets/js/chat/ui-sounds.js?v=<?= assetVersion('assets/js/chat/ui-sounds.js') ?>"></script>
    <?php
    require_once __DIR__ . '/config/ringtone_sounds.php';
    $ringtone_paths_js = [];
    foreach (ringtone_valid_sound_ids() as $id) {
        $resolved = ringtone_resolve_sound_id($id);
        $ringtone_paths_js[$id] = ($resolved === 'silent') ? '' : ringtone_sound_path($resolved);
    }
    ?>
    <script>window.__RINGTONE_PATHS = <?= json_encode($ringtone_paths_js) ?>;</script>
    <?php include __DIR__ . '/includes/chat/scripts.php'; ?>
    <script src="assets/js/chat/panel-resize.js?v=<?= assetVersion('assets/js/chat/panel-resize.js') ?>"></script>
    <script src="assets/js/chat/input-area-resize.js?v=<?= assetVersion('assets/js/chat/input-area-resize.js') ?>"></script>
    <script>
    window.__TASK_LABEL = <?= json_encode($currentLang === 'en' ? 'Task' : ($currentLang === 'zh' ? '任务' : 'タスク')) ?>;
    window.__TASK_ADDED_MSG = <?= json_encode($currentLang === 'en' ? 'Added to task' : ($currentLang === 'zh' ? '已添加到任务' : 'タスクに追加しました')) ?>;
    </script>
    <script src="assets/js/chat-mobile.js?v=<?= assetVersion('assets/js/chat-mobile.js') ?>"></script>
    <script src="assets/js/pwa-install.js?v=<?= assetVersion('assets/js/pwa-install.js') ?>"></script>
    <script src="assets/js/storage.js?v=<?= assetVersion('assets/js/storage.js') ?>"></script>
    <script src="assets/js/push-notifications.js?v=<?= assetVersion('assets/js/push-notifications.js') ?>"></script>
    <script src="assets/js/ai-personality.js?v=<?= assetVersion('assets/js/ai-personality.js') ?>"></script>
    <script src="assets/js/ai-deliberation.js?v=<?= assetVersion('assets/js/ai-deliberation.js') ?>"></script>
    <script src="assets/js/secretary-rightpanel.js?v=<?= assetVersion('assets/js/secretary-rightpanel.js') ?>"></script>
    <script src="assets/js/ai-reply-suggest.js?v=<?= assetVersion('assets/js/ai-reply-suggest.js') ?>"></script>
    <script>
    // 返信提案バー挿入の遅延フォールバック（PHP/JSの実行順で挿入が漏れた場合の補完）
    (function(){
        function run() {
            if (window.AIReplySuggest && typeof window.AIReplySuggest.injectBarsForInitialMessages === 'function') {
                window.AIReplySuggest.injectBarsForInitialMessages();
            }
        }
        if (document.readyState === 'complete') {
            setTimeout(run, 150);
        } else {
            window.addEventListener('load', function(){ setTimeout(run, 150); });
        }
    })();
    </script>
    <script>
    // 表示名変更案内の処理（表示名なし・メールアドレス表示の新規入室者向け）
    (function() {
        const displayName = (document.body.dataset.displayName || '').trim();
        const skipped = localStorage.getItem('social9_display_name_prompt_skipped');
        
        // 表示名が未設定（空）またはメールアドレスのままの場合、入室直後にアナウンス
        const needsDisplayName = !displayName || displayName.includes('@');
        if (needsDisplayName && !skipped) {
            // 入室後すぐに表示（短い遅延でスムーズに）
            setTimeout(() => {
                document.getElementById('displayNamePromptModal').style.display = 'flex';
                document.getElementById('newDisplayNameInput').focus();
            }, 300);
        }
    })();
    
    function skipDisplayNameChange() {
        localStorage.setItem('social9_display_name_prompt_skipped', '1');
        document.getElementById('displayNamePromptModal').style.display = 'none';
    }
    
    async function saveNewDisplayName() {
        const newName = document.getElementById('newDisplayNameInput').value.trim();
        
        if (!newName) {
            alert('表示名を入力してください');
            return;
        }
        
        if (newName.length > 50) {
            alert('表示名は50文字以内で入力してください');
            return;
        }
        
        try {
            const response = await fetch('api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_display_name',
                    display_name: newName
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // ページをリロードして新しい表示名を反映
                localStorage.setItem('social9_display_name_prompt_skipped', '1');
                location.reload();
            } else {
                alert(data.message || 'エラーが発生しました');
            }
        } catch (e) {
            console.error('Display name update error:', e);
            alert('通信エラーが発生しました');
        }
    }
    
    // プッシュ通知の初期化
    document.addEventListener('DOMContentLoaded', async () => {
        const vapidPublicKey = '<?= VAPID_PUBLIC_KEY ?>';
        await PushNotifications.init(vapidPublicKey);
        
        // モバイルでは早めに、PCでは少し遅れて通知許可バナーを表示
        const isMobile = window.innerWidth <= 768;
        setTimeout(() => {
            PushNotifications.showPermissionBanner();
        }, isMobile ? 1500 : 5000);
        
        // タスクバーのアプリバッジを更新＋自分宛の通知が来たら「通知をONに」お知らせ
        let prevNotificationCount = -1;
        const checkNotificationAndShowPermissionHop = async () => {
            const count = await PushNotifications.updateBadgeFromServer();
            if (typeof count === 'number' && count > 0 && count > prevNotificationCount && prevNotificationCount >= 0) {
                if (typeof PushNotifications.showNotificationPermissionHop === 'function') {
                    PushNotifications.showNotificationPermissionHop();
                }
            }
            if (typeof count === 'number') prevNotificationCount = count;
        };
        setTimeout(checkNotificationAndShowPermissionHop, 500);
        // 定期的にチェック（携帯は15秒、PCは20秒）
        const notifInterval = isMobile ? 15000 : 20000;
        setInterval(checkNotificationAndShowPermissionHop, notifInterval);
        // ページ表示・フォーカス時にチェック（通知を受けてアプリを開いた場合）
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                checkNotificationAndShowPermissionHop();
            }
        });
        window.addEventListener('focus', () => {
            checkNotificationAndShowPermissionHop();
        });
        
        // Service Workerからの通知クリックメッセージを受信
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'NOTIFICATION_CLICK') {
                const conversationId = event.data.conversationId;
                if (conversationId && typeof selectConversation === 'function') {
                    selectConversation(conversationId);
                }
            }
        });
        
        // 保護者機能: 利用制限チェック
        checkParentalRestrictions();
        
        // 1分ごとにアクティビティをログ
        setInterval(() => {
            logParentalActivity();
        }, 60000);
    });
    
    // 保護者機能: 利用制限チェック
    async function checkParentalRestrictions() {
        try {
            const response = await fetch('api/parental.php?action=check_usage_limit');
            const data = await response.json();
            
            if (data.success && !data.allowed) {
                // 利用制限中
                showUsageBlockedMessage(data.reason);
            } else if (data.success && data.restrictions) {
                // 制限が設定されている場合、残り時間を計算
                if (data.restrictions.daily_limit) {
                    const myRestrictionsRes = await fetch('api/parental.php?action=get_my_restrictions');
                    const myRestrictionsData = await myRestrictionsRes.json();
                    
                    if (myRestrictionsData.success && myRestrictionsData.restrictions) {
                        const limit = myRestrictionsData.restrictions.daily_usage_limit_minutes;
                        const used = myRestrictionsData.today_usage_minutes || 0;
                        const remaining = limit - used;
                        
                        if (remaining <= 10 && remaining > 0) {
                            showUsageWarning(remaining);
                        }
                    }
                }
            }
        } catch (e) {
            console.log('Parental check skipped:', e);
        }
    }
    
    // 保護者機能: アクティビティログ
    async function logParentalActivity() {
        try {
            await fetch('api/parental.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'log_activity' })
            });
            
            // 利用制限を再チェック
            checkParentalRestrictions();
        } catch (e) {
            console.log('Activity log skipped');
        }
    }
    
    // 利用制限メッセージを表示
    function showUsageBlockedMessage(reason) {
        const overlay = document.createElement('div');
        overlay.id = 'usageBlockedOverlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            color: white;
            text-align: center;
            padding: 20px;
        `;
        overlay.innerHTML = `
            <div style="font-size: 64px; margin-bottom: 20px;">⏰</div>
            <h2 style="font-size: 24px; margin-bottom: 16px;">利用できません</h2>
            <p style="font-size: 16px; opacity: 0.9; max-width: 400px;">${reason || '保護者による利用制限が設定されています'}</p>
            <button onclick="location.href='settings.php'" style="margin-top: 24px; padding: 12px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px;">設定を確認</button>
        `;
        document.body.appendChild(overlay);
    }
    
    // 利用時間警告を表示
    function showUsageWarning(remainingMinutes) {
        if (document.getElementById('usageWarningBanner')) return;
        
        const banner = document.createElement('div');
        banner.id = 'usageWarningBanner';
        banner.style.cssText = `
            position: fixed;
            top: 60px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 9999;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        `;
        banner.innerHTML = `
            <span>⏰</span>
            <span>残り利用時間: ${remainingMinutes}分</span>
            <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; margin-left: 12px;">×</button>
        `;
        document.body.appendChild(banner);
        
        // 30秒後に自動で消える
        setTimeout(() => {
            banner.remove();
        }, 30000);
    }
    </script>
</body>
</html>
