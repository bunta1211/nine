<?php
/**
 * デザイン設定ローダー
 * ユーザーのデザイン設定を読み込み、CSSを生成する
 */

// 共通設定ファイルを読み込み（まだ読み込まれていない場合）
if (!function_exists('getThemeConfigs')) {
    require_once __DIR__ . '/design_config.php';
}

// テーブルカラムキャッシュ（リクエスト内で再利用）
$_tableColumnsCache = [];

/**
 * テーブルのカラム一覧を取得（キャッシュ付き）
 */
function getTableColumns(PDO $pdo, string $table): array {
    global $_tableColumnsCache;
    
    if (!isset($_tableColumnsCache[$table])) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
            $_tableColumnsCache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {
            $_tableColumnsCache[$table] = [];
        }
    }
    
    return $_tableColumnsCache[$table];
}

/**
 * ユーザーのデザイン設定を取得
 */
function getDesignSettings(PDO $pdo, ?int $user_id): array {
    $defaults = getDefaultDesignSettings();
    
    if (!$user_id) {
        return applyTestThemeOverride($defaults);
    }
    
    try {
        // カラムが存在するか確認して動的にSELECT（キャッシュ利用）
        $existingColumns = getTableColumns($pdo, 'user_settings');
        
        if (empty($existingColumns)) {
            return $defaults;
        }
        
        // 基本カラム
        $columns = ['theme', 'dark_mode', 'accent_color', 'background_image', 'font_size'];
        // 追加カラム（存在確認）
        $optionalColumns = ['ui_style', 'font_family', 'background_size'];
        
        foreach ($optionalColumns as $col) {
            if (in_array($col, $existingColumns)) {
                $columns[] = $col;
            }
        }
        
        // 存在するカラムのみ選択
        $selectColumns = array_intersect($columns, $existingColumns);
        if (empty($selectColumns)) {
            return $defaults;
        }
        
        $columnList = implode(', ', $selectColumns);
        $stmt = $pdo->prepare("SELECT {$columnList} FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($settings) {
            $merged = array_merge($defaults, $settings);
            // 標準デザイン（lavender）に統一 — 旧テーマは全てlavenderにフォールバック
            $merged['theme'] = 'lavender';
            $merged['background_image'] = 'none';
            $merged['dark_mode'] = 0;
            return $merged;
        }
    } catch (Exception $e) {
        // テーブルがない場合はデフォルト
    }
    
    return applyTestThemeOverride($defaults);
}

/**
 * テスト用テーマ上書き（ページチェッカー用）
 * URLパラメータ _theme でテーマを一時的に上書き
 */
function applyTestThemeOverride(array $settings): array {
    // テスト用テーマ上書きは廃止（標準デザインのみ）
    return $settings;
}

/**
 * テーマデータを取得（共通設定から取得）
 */
function getThemeData(string $themeId): array {
    return getThemeById($themeId);
}

/**
 * デザイントークンからCSSを生成（新システム）
 * @param array $tokens デザイントークン
 * @param array $settings ユーザー設定
 * @return string 生成されたCSS
 */
function generateDesignTokensCSS(array $tokens, array $settings): string {
    $g = $tokens['global'];
    $panels = $tokens['panels'];
    $buttons = $tokens['buttons'];
    $inputs = $tokens['inputs'];
    $messages = $tokens['messages'];
    $cards = $tokens['cards'];
    $misc = $tokens['misc'];
    $pattern = $tokens['pattern'] ?? ['isPattern' => false];
    $bgSettings = $tokens['background'] ?? null;

    // 透明/暗いヘッダー（白文字）の場合: ホバー時に白系に変わらないように暗いオーバーレイを使用
    $headerText = $panels['header']['text'] ?? '#1e293b';
    $btnSecondaryHover = $buttons['secondary']['hover'] ?? 'rgba(0,0,0,0.06)';
    $isLightHeaderText = (preg_match('/#fff|#ffffff|255\s*,\s*255\s*,\s*255|rgba\s*\(\s*255\s*,\s*255\s*,\s*255/i', $headerText) || in_array(strtolower(trim($headerText)), ['#fff', '#ffffff', 'white']));
    if ($isLightHeaderText) {
        // 白/明るい文字 = 暗いヘッダー → ホバーは暗いオーバーレイ（読みやすさ維持）
        $btnSecondaryHover = 'rgba(0,0,0,0.45)';
    }
    
    // フォント設定
    $fontSize = $settings['font_size'] ?? DESIGN_DEFAULT_FONT_SIZE;
    $fontFamily = $settings['font_family'] ?? DESIGN_DEFAULT_FONT;
    $fs = getFontSizeById($fontSize);
    $fontData = getFontById($fontFamily);
    $ff = $fontData['family'];
    
    // 背景画像は廃止（標準デザイン固定）
    $backgroundImage = 'none';
    $bgImageUrl = '';
    
    $css = "
    <style id=\"design-tokens-css\">
        /* ============================================
           デザイントークンシステム v1.0
           ============================================ */
        
        :root {
            /* グローバル設定 */
            --dt-accent: {$g['accent']};
            --dt-accent-hover: {$g['accentHover']};
            --dt-text-primary: {$g['textPrimary']};
            --dt-text-muted: {$g['textMuted']};
            --dt-text-light: " . ($g['textLight'] ?? $g['textMuted']) . ";
            
            /* フォント */
            --dt-font-family: {$ff};
            --dt-font-base: {$fs['base']};
            --dt-font-message: {$fs['message']};
            --dt-font-title: {$fs['title']};
            
            /* パネル */
            --dt-header-bg: {$panels['header']['bg']};
            --dt-header-text: {$panels['header']['text']};
            --dt-header-inner-bg-gradient: " . ($panels['header']['innerBgGradient'] ?? 'linear-gradient(135deg, #f2f4f6 0%, #eceff3 50%, #e8eaef 100%)') . ";
            --dt-header-recess-bg: " . ($panels['header']['recessBg'] ?? '#e8f0f8') . ";
            --dt-header-logo-bg-gradient: " . ($panels['header']['logoBgGradient'] ?? 'linear-gradient(90deg, #d4dce8, #d8e4f0, #d4dce8)') . ";
            --dt-header-logo-text: " . ($panels['header']['logoText'] ?? '#345678') . ";
            --dt-header-convex-btn-bg: " . ($panels['header']['convexBtnBg'] ?? '#f0f2f5') . ";
            --dt-header-bezel-border: " . ($panels['header']['bezelBorder'] ?? '#e0e0e0') . ";
            --dt-left-bg: {$panels['left']['bg']};
            --dt-left-text: {$panels['left']['text']};
            --dt-center-bg: {$panels['center']['bg']};
            --dt-center-header-bg: {$panels['center']['headerBg']};
            --dt-center-header-text: {$panels['center']['headerText']};
            --dt-center-input-bg: {$panels['center']['inputAreaBg']};
            --dt-right-bg: {$panels['right']['bg']};
            --dt-right-text: {$panels['right']['text']};
            --dt-right-section-bg: " . ($panels['right']['sectionBg'] ?? $panels['right']['bg']) . ";
            
            /* ボタン */
            --dt-btn-primary-bg: {$buttons['primary']['bg']};
            --dt-btn-primary-text: {$buttons['primary']['text']};
            --dt-btn-primary-hover: " . ($buttons['primary']['hover'] ?? $g['accentHover']) . ";
            --dt-btn-secondary-bg: {$buttons['secondary']['bg']};
            --dt-btn-secondary-text: {$buttons['secondary']['text']};
            --dt-btn-secondary-hover: {$btnSecondaryHover};
            --dt-btn-secondary-border: {$buttons['secondary']['border']};
            --dt-btn-filter-bg: " . ($buttons['filter']['bg'] ?? $buttons['secondary']['bg']) . ";
            --dt-btn-filter-text: " . ($buttons['filter']['text'] ?? $buttons['secondary']['text']) . ";
            --dt-btn-filter-active-bg: " . ($buttons['filter']['activeBg'] ?? $g['accent']) . ";
            --dt-btn-filter-active-text: " . ($buttons['filter']['activeText'] ?? '#ffffff') . ";
            
            /* 入力欄 */
            --dt-input-bg: {$inputs['bg']};
            --dt-input-text: {$inputs['text']};
            --dt-input-placeholder: {$inputs['placeholder']};
            --dt-input-border: {$inputs['border']};
            --dt-input-focus-border: " . ($inputs['focusBorder'] ?? $g['accent']) . ";
            
            /* 上パネル検索バー（1つの枠・テーマの入力色で見やすく） */
            --dt-search-bg: " . ($inputs['searchBarBg'] ?? $inputs['bg']) . ";
            --dt-search-text: " . ($inputs['searchBarText'] ?? $inputs['text']) . ";
            --dt-search-placeholder: " . ($inputs['searchBarPlaceholder'] ?? $inputs['placeholder']) . ";
            --dt-search-border: " . ($inputs['searchBarBorder'] ?? $inputs['border']) . ";
            
            /* メッセージ */
            --dt-msg-self-bg: {$messages['self']['bg']};
            --dt-msg-self-text: {$messages['self']['text']};
            --dt-msg-self-border: " . ($messages['self']['border'] ?? 'transparent') . ";
            --dt-msg-self-time: " . ($messages['self']['time'] ?? $messages['self']['text']) . ";
            --dt-msg-other-bg: {$messages['other']['bg']};
            --dt-msg-other-text: {$messages['other']['text']};
            --dt-msg-other-border: " . ($messages['other']['border'] ?? 'transparent') . ";
            --dt-msg-other-time: " . ($messages['other']['time'] ?? $messages['other']['text']) . ";
            /* メンション（自分宛てメッセージ） */
            --dt-msg-mention-bg: " . ($messages['mention']['bg'] ?? 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)') . ";
            --dt-msg-mention-text: " . ($messages['mention']['text'] ?? '#92400e') . ";
            --dt-msg-mention-border: " . ($messages['mention']['border'] ?? 'rgba(251,191,36,0.4)') . ";
            /* Toバッジ・自分宛枠（各デザインのメンション枠色に合わせる） */
            --dt-mention-badge-bg: {$g['accent']};
            --dt-mention-badge-text: " . ($buttons['primary']['text'] ?? '#ffffff') . ";
            --dt-mention-border: " . ($messages['mention']['border'] ?? $g['accent']) . ";
            
            /* カード */
            --dt-card-bg: {$cards['bg']};
            --dt-card-border: {$cards['border']};
            
            /* その他 */
            --dt-divider: {$misc['divider']};
            --dt-scroll-thumb: {$misc['scrollThumb']};
            --dt-scroll-thumb-hover: {$misc['scrollThumbHover']};
            
            /* ============================================
               レガシー変数（互換性維持）
               chat-main.css が参照する変数名
               ============================================ */
            --theme-accent: {$g['accent']};
            --theme-accent-hover: {$g['accentHover']};
            --theme-btn-primary: {$buttons['primary']['bg']};
            --theme-btn-primary-hover: " . ($buttons['primary']['hover'] ?? $g['accentHover']) . ";
            --theme-btn-text: {$buttons['primary']['text']};
            --theme-btn-secondary: {$buttons['secondary']['bg']};
            --theme-text: {$g['textPrimary']};
            --theme-text-muted: {$g['textMuted']};
            --theme-panel-bg: {$panels['left']['bg']};
            --theme-right-panel-bg: {$panels['right']['bg']};
            --theme-self-msg-bg: {$messages['self']['bg']};
        }
        
        /* ============================================
           上パネル（ヘッダー）
           ============================================ */
        .top-panel,
        header.top-panel,
        .top-panel .top-left,
        .top-panel .top-right {
            background: var(--dt-header-bg, #ffffff) !important;
        }
        .top-panel,
        .top-panel *,
        .top-panel .app-title,
        .top-panel .nav-item,
        .top-panel button,
        .top-panel a {
            color: var(--dt-header-text) !important;
        }
        .top-panel .search-input {
            background: var(--dt-input-bg) !important;
            color: var(--dt-input-text) !important;
            border-color: var(--dt-input-border) !important;
        }
        .top-panel .search-input::placeholder {
            color: var(--dt-input-placeholder) !important;
        }
        /* 上パネル検索バー内のinput：枠線を見えなくする（フォーカス有無どちらも） */
        .top-panel .search-box .search-box-input,
        .search-box .search-box-input {
            background: var(--dt-search-bg, var(--dt-input-bg)) !important;
            border: none !important;
            border-width: 0 !important;
            border-color: var(--dt-search-bg, var(--dt-input-bg)) !important;
            box-shadow: none !important;
        }
        .top-panel .search-box .search-box-input:focus,
        .search-box .search-box-input:focus {
            border: none !important;
            border-width: 0 !important;
            border-color: var(--dt-search-bg, var(--dt-input-bg)) !important;
            box-shadow: none !important;
            outline: none !important;
        }
        /* 上パネルメニューボタン（アプリ・デザイン・タスク・メモ・通知・ユーザー名・設定・左右トグル）：外枠のみ・子は枠なし */
        .top-panel .top-btn,
        .top-panel .nav-item,
        .top-panel .action-btn,
        .top-panel .icon-btn,
        .top-panel .user-info,
        .top-panel .settings-btn,
        .top-panel .toggle-left-btn,
        .top-panel .toggle-right-btn {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text, var(--dt-header-text)) !important;
            text-shadow: none !important;
        }
        .top-panel .toggle-left-btn,
        .top-panel .toggle-right-btn {
            border: none !important;
        }
        .top-panel .top-btn *,
        .top-panel .nav-item * {
            border: none !important;
            background: transparent !important;
        }
        .top-panel .top-btn:hover,
        .top-panel .nav-item:hover,
        .top-panel .action-btn:hover,
        .top-panel .user-info:hover,
        .top-panel .settings-btn:hover,
        .top-panel .toggle-left-btn:hover,
        .top-panel .toggle-right-btn:hover {
            background: var(--dt-btn-secondary-hover) !important;
            color: var(--dt-btn-secondary-text, var(--dt-header-text)) !important;
        }
        /* 検索枠：白・太めグレー枠・ホバーで薄いオレンジ */
        .top-panel .search-box {
            background: var(--dt-search-bg, #ffffff) !important;
            border: 2px solid var(--dt-btn-secondary-border, #9ca3af) !important;
        }
        .top-panel .search-box:hover {
            background: var(--dt-btn-secondary-hover, #ffedd5) !important;
        }
        .top-panel .top-btn .badge,
        .top-panel .nav-item .badge,
        .top-panel .unread-badge {
            background: var(--dt-accent) !important;
            color: #ffffff !important;
        }
        
        /* ============================================
           左パネル
           ============================================ */
        .left-panel,
        .left-spacer {
            background: var(--dt-left-bg) !important;
        }
        .left-panel,
        .left-panel *,
        .left-panel .conv-item,
        .left-panel .conv-item *,
        .left-panel .group-name,
        .left-panel .last-message,
        .left-panel .time {
            color: var(--dt-left-text) !important;
        }
        .left-panel .last-message,
        .left-panel .time {
            opacity: 0.7;
        }
        
        /* フィルターボタン：白・太めグレー枠・ホバーで薄いオレンジ */
        .filter-tabs button,
        .filter-btn,
        .tab-btn,
        .left-header-tabs button {
            background: var(--dt-btn-filter-bg, #ffffff) !important;
            color: var(--dt-btn-filter-text) !important;
            border: 2px solid #9ca3af !important;
        }
        .filter-tabs button:hover:not(.active),
        .filter-btn:hover:not(.active),
        .tab-btn:hover:not(.active),
        .left-header-tabs button:hover:not(.active) {
            background: #ffedd5 !important;
        }
        .filter-tabs button.active,
        .filter-btn.active,
        .tab-btn.active,
        .left-header-tabs button.active {
            background: var(--dt-btn-filter-active-bg) !important;
            color: var(--dt-btn-filter-active-text) !important;
            border: 2px solid var(--dt-btn-filter-active-bg) !important;
        }
        
        /* ============================================
           中央パネル
           ============================================ */
        .center-panel {
            background: var(--dt-center-bg) !important;
        }
        /* 時計クローバー等：中央の装飾オーバーレイを外して背景をはっきり表示（chat-main.css の ::before を無効化） */
        .center-panel::before {
            display: none !important;
        }
        .center-panel .chat-header,
        .center-panel .room-header {
            background: var(--dt-center-header-bg) !important;
        }
        .center-panel .chat-header h2,
        .center-panel .chat-header .chat-title-area h2,
        .center-panel .chat-header .chat-title-area .status,
        .center-panel .room-header h2,
        .center-panel .room-header .status {
            color: var(--dt-center-header-text) !important;
            /* 透明デザイン統一規格: 明るい背景でも必ず読めるように軽いシャドウ */
            text-shadow: 0 1px 2px rgba(0,0,0,0.08), 0 0 1px rgba(255,255,255,0.5);
        }
        .center-panel .chat-header .badge {
            background: rgba(0,0,0,0.1) !important;
            color: var(--dt-center-header-text) !important;
        }
        .center-panel .chat-header-right .btn,
        .center-panel .chat-header .action-btn {
            color: var(--dt-center-header-text) !important;
            border-color: var(--dt-input-border) !important;
        }
        
        /* 入力エリア */
        #inputArea,
        .input-area,
        .message-input-area,
        .input-container,
        .input-wrapper {
            background: var(--dt-center-input-bg) !important;
            border-radius: 16px !important;
            overflow: hidden !important;
        }
        #messageInput,
        .input-area input,
        .input-area textarea,
        .input-wrapper textarea {
            background: var(--dt-input-bg) !important;
            color: var(--dt-input-text) !important;
            border-color: var(--dt-input-border) !important;
            -webkit-text-fill-color: var(--dt-input-text) !important;
        }
        #messageInput::placeholder,
        .input-area input::placeholder,
        .input-area textarea::placeholder {
            color: var(--dt-input-placeholder) !important;
            -webkit-text-fill-color: var(--dt-input-placeholder) !important;
            opacity: 1;
        }
        
        /* TOボタン（ツールバー内はGIF/電話と同様にプライマリ色） */
        .mention-btn,
        .to-btn:not(.toolbar-btn) {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text) !important;
            border: 1px solid var(--dt-btn-secondary-border) !important;
        }
        .toolbar-btn.to-btn {
            background: var(--dt-btn-primary-bg) !important;
            color: #ffffff !important;
            border: none !important;
        }
        
        /* 送信ボタン（文字・アイコンは白で統一） */
        .send-btn,
        .input-send-btn,
        .input-row > .input-send-btn {
            background: var(--dt-btn-primary-bg) !important;
            color: #ffffff !important;
        }
        
        /* メッセージ（チャット画面） */
        .message.self .message-bubble,
        .message.self .bubble {
            background: var(--dt-msg-self-bg) !important;
            color: var(--dt-msg-self-text) !important;
            border: 1px solid var(--dt-msg-self-border) !important;
        }
        .message.other .message-bubble,
        .message.other .bubble {
            background: var(--dt-msg-other-bg) !important;
            color: var(--dt-msg-other-text) !important;
            border: 1px solid var(--dt-msg-other-border) !important;
        }
        
        /* プレビューメッセージ（デザイン設定画面） */
        .preview-message {
            background: var(--dt-msg-other-bg) !important;
            color: var(--dt-msg-other-text) !important;
            border: 1px solid var(--dt-msg-other-border) !important;
        }
        .preview-message.self {
            background: var(--dt-msg-self-bg) !important;
            color: var(--dt-msg-self-text) !important;
            border: 1px solid var(--dt-msg-self-border) !important;
        }
        .preview-message .time {
            color: var(--dt-msg-other-time) !important;
            opacity: 0.8;
        }
        .preview-message.self .time {
            color: var(--dt-msg-self-time) !important;
        }
        .preview-message .name {
            color: inherit !important;
            opacity: 0.7;
        }
        
        /* メンション（自分宛て）メッセージ */
        .message.mention .message-bubble,
        .message.mention .bubble,
        .message[data-mention='true'] .message-bubble,
        .message[data-mention='true'] .bubble,
        .message.has-mention .message-bubble,
        .message.has-mention .bubble {
            background: var(--dt-msg-mention-bg) !important;
            color: var(--dt-msg-mention-text) !important;
            border: 1px solid var(--dt-msg-mention-border) !important;
        }
        
        /* ============================================
           右パネル
           ============================================ */
        .right-panel,
        .right-spacer {
            background: var(--dt-right-bg) !important;
        }
        .right-panel,
        .right-panel *,
        .right-panel .section-header,
        .right-panel h4,
        .right-panel h5 {
            color: var(--dt-right-text) !important;
        }
        .right-panel .section,
        .right-panel .info-section,
        .right-panel .media-section,
        .right-panel .group-settings,
        .right-panel [class*='section'] {
            background: var(--dt-right-section-bg) !important;
        }
        
        /* 右パネル入力欄 */
        #mediaTitleInput,
        #mediaUrlInput,
        .right-panel input,
        .right-panel textarea {
            background: var(--dt-input-bg) !important;
            color: var(--dt-input-text) !important;
            border: 1px solid var(--dt-input-border) !important;
            -webkit-text-fill-color: var(--dt-input-text) !important;
        }
        #mediaTitleInput::placeholder,
        #mediaUrlInput::placeholder,
        .right-panel input::placeholder {
            color: var(--dt-input-placeholder) !important;
            -webkit-text-fill-color: var(--dt-input-placeholder) !important;
        }
        
        /* 右パネルボタン */
        .right-panel .btn,
        .right-panel button:not(.close-btn),
        .media-add-row button {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text) !important;
            border: 1px solid var(--dt-btn-secondary-border) !important;
        }
        
        /* ============================================
           共通要素（全パネル統一）
           ============================================ */
        
        /* カード */
        .card,
        .conv-item {
            background: var(--dt-card-bg) !important;
            border-color: var(--dt-card-border) !important;
        }
        
        /* ============================================
           統一ボタンスタイル
           ============================================ */
        
        /* プライマリボタン（全パネル共通） */
        .btn-primary,
        button[type='submit']:not(.send-btn),
        .primary-btn,
        .action-btn-primary {
            background: var(--dt-btn-primary-bg) !important;
            color: var(--dt-btn-primary-text) !important;
            border: 1px solid transparent !important;
        }
        .btn-primary:hover,
        button[type='submit']:not(.send-btn):hover,
        .primary-btn:hover {
            background: var(--dt-btn-primary-hover) !important;
        }
        
        /* セカンダリボタン（全パネル共通） */
        .btn-secondary,
        .secondary-btn,
        .action-btn,
        .modal-btn,
        .dialog-btn:not(.btn-primary) {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text) !important;
            border: 1px solid var(--dt-btn-secondary-border) !important;
        }
        
        /* アイコンボタン（全パネル共通） */
        .icon-btn,
        .emoji-btn,
        .input-emoji-btn,
        .input-attach-btn {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text) !important;
            border: 1px solid var(--dt-btn-secondary-border) !important;
        }
        
        /* ============================================
           テーマ連動アクションボタン（グループ化）
           TO, GIF, 通話, 添付, 送信, メディア追加, 保存
           ============================================ */
        .toolbar-btn.to-btn,
        .toolbar-btn.gif-btn,
        .toolbar-btn.call-toolbar-btn,
        .toolbar-btn.attach-btn,
        .send-btn,
        .input-send-btn,
        .media-file-btn,
        .media-add-btn,
        .media-add-compact button,
        .media-add-row button,
        .save-memo-btn {
            background: var(--dt-btn-primary-bg, var(--theme-btn-primary, var(--dt-accent))) !important;
            color: var(--dt-btn-primary-text, #ffffff) !important;
            border: none !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
        }
        .toolbar-btn.to-btn:hover,
        .toolbar-btn.gif-btn:hover,
        .toolbar-btn.call-toolbar-btn:hover,
        .toolbar-btn.attach-btn:hover,
        .send-btn:hover,
        .input-send-btn:hover,
        .media-file-btn:hover,
        .media-add-btn:hover,
        .media-add-compact button:hover,
        .media-add-row button:hover,
        .save-memo-btn:hover {
            filter: brightness(1.1) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;
        }
        
        /* ============================================
           統一入力欄スタイル
           ============================================ */
        
        /* 全パネル共通入力欄（太めグレー枠・内側白） */
        input:not([type='checkbox']):not([type='radio']):not([type='range']),
        textarea,
        select {
            background: var(--dt-input-bg) !important;
            color: var(--dt-input-text) !important;
            border: 2px solid var(--dt-input-border) !important;
            -webkit-text-fill-color: var(--dt-input-text) !important;
        }
        input::placeholder,
        textarea::placeholder {
            color: var(--dt-input-placeholder) !important;
            -webkit-text-fill-color: var(--dt-input-placeholder) !important;
        }
        input:focus,
        textarea:focus,
        select:focus {
            border-color: var(--dt-input-focus-border) !important;
            outline: none !important;
        }
        /* 上パネル検索バー内のinput：未フォーカス時も枠線を見えなくする（グローバルinputルールより後に記述） */
        .top-panel .search-box input.search-box-input,
        .search-box input.search-box-input {
            border-width: 0 !important;
            border-style: solid !important;
            border-color: var(--dt-search-bg, var(--dt-input-bg)) !important;
            box-shadow: none !important;
        }
        .top-panel .search-box input.search-box-input:focus,
        .search-box input.search-box-input:focus {
            border-width: 0 !important;
            border-color: var(--dt-search-bg, var(--dt-input-bg)) !important;
            box-shadow: none !important;
        }
        
        /* スクロールバー - デザイントークン優先 */
        ::-webkit-scrollbar {
            width: 6px !important;
            height: 6px !important;
        }
        ::-webkit-scrollbar-track {
            background: transparent !important;
        }
        ::-webkit-scrollbar-thumb {
            background: var(--dt-scroll-thumb) !important;
            border-radius: 10px !important;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--dt-scroll-thumb-hover) !important;
        }
        
        /* 主要エリアのスクロールバー */
        .messages-area::-webkit-scrollbar-thumb,
        .left-panel::-webkit-scrollbar-thumb,
        .right-panel::-webkit-scrollbar-thumb,
        .group-list::-webkit-scrollbar-thumb {
            background: var(--dt-scroll-thumb) !important;
        }
        .messages-area::-webkit-scrollbar-thumb:hover,
        .left-panel::-webkit-scrollbar-thumb:hover,
        .right-panel::-webkit-scrollbar-thumb:hover,
        .group-list::-webkit-scrollbar-thumb:hover {
            background: var(--dt-scroll-thumb-hover) !important;
        }
        
        /* フォント設定 */
        body {
            font-family: var(--dt-font-family) !important;
            font-size: var(--dt-font-base) !important;
        }
        
        /* ============================================
           グループ設定ボタン（右パネル内）
           ============================================ */
        .group-setting-item {
            background: var(--dt-btn-secondary-bg) !important;
            color: var(--dt-btn-secondary-text) !important;
            border: 2px solid var(--dt-btn-secondary-border) !important;
        }
        .group-setting-item:hover {
            background: var(--dt-left-hover) !important;
            opacity: 0.9;
        }
        .group-setting-item.danger {
            color: #ef4444 !important;
        }
        .group-setting-item.danger:hover {
            background: rgba(239, 68, 68, 0.15) !important;
        }
        
        /* 概要編集ボタン: 編集中は白背景のため明示的な配色（.right-panel * の白文字継承を上書き） */
        .overview-entry.editing .overview-entry-btn.save-btn {
            background: var(--dt-accent, #3b82f6) !important;
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }
        .overview-entry.editing .overview-entry-btn.save-btn:hover {
            background: var(--dt-accent-hover, #2563eb) !important;
        }
        .overview-entry.editing .overview-entry-btn.edit-btn {
            background: rgba(100, 116, 139, 0.15) !important;
            color: #334155 !important;
            -webkit-text-fill-color: #334155 !important;
        }
        .overview-entry.editing .overview-entry-btn.edit-btn:hover {
            background: rgba(100, 116, 139, 0.25) !important;
        }
        .overview-entry.editing .overview-entry-btn.delete-btn {
            background: rgba(239, 68, 68, 0.1) !important;
            color: #dc2626 !important;
            -webkit-text-fill-color: #dc2626 !important;
        }
        .overview-entry.editing .overview-entry-btn.delete-btn:hover {
            background: rgba(239, 68, 68, 0.2) !important;
        }
        
        /* ============================================
           モーダル（グループ設定のポップアップ）
           ============================================ */
        .modal-content,
        .modal {
            background: var(--dt-card-bg, #ffffff) !important;
            color: var(--dt-text-primary, #1e293b) !important;
        }
        .modal-header,
        .modal-footer {
            background: var(--dt-right-section-bg, #f8f9fa) !important;
            border-color: var(--dt-card-border, #e2e8f0) !important;
        }
        .modal-header h3,
        .modal-header h2,
        .modal-body h4,
        .modal-body label {
            color: var(--dt-text-primary, #1e293b) !important;
        }
        .modal-body {
            color: var(--dt-text-primary, #1e293b) !important;
        }
        .modal-body p,
        .modal-body span,
        .modal-body .form-hint {
            color: var(--dt-text-muted, #64748b) !important;
        }
        .modal input,
        .modal textarea,
        .modal select {
            background: var(--dt-input-bg, #ffffff) !important;
            color: var(--dt-input-text, #1e293b) !important;
            border: 1px solid var(--dt-input-border, #e2e8f0) !important;
        }
        .modal input::placeholder,
        .modal textarea::placeholder {
            color: var(--dt-input-placeholder, #a0aec0) !important;
        }
        
        /* メンバー管理リスト */
        .member-manage-item,
        .member-management-item {
            background: var(--dt-card-bg, #ffffff) !important;
            color: var(--dt-text-primary, #1e293b) !important;
            border-color: var(--dt-card-border, #e2e8f0) !important;
        }
        .member-manage-item:hover,
        .member-management-item:hover {
            background: var(--dt-left-hover, rgba(0,0,0,0.05)) !important;
        }
        .member-management-section h4 {
            color: var(--dt-text-muted, #64748b) !important;
        }
    </style>
    ";
    
    return $css;
}

/**
 * UIスタイルのCSSを生成（枠線: 直角 frame_square / 丸み1 frame_round1 / 丸み2 frame_round2）
 */
function generateUIStyleCSS(string $uiStyle): string {
    $style = getStyleById($uiStyle);
    $effectiveId = function_exists('resolveStyleId') ? resolveStyleId($uiStyle) : $uiStyle;
    
    return "
    <style id=\"ui-style-css\">
        /* UIスタイル: {$style['name']} */
        body.style-{$effectiveId} {
            --ui-border-radius: {$style['borderRadius']};
            --ui-shadow: {$style['shadow']};
            --ui-border: {$style['border']};
            --ui-font: {$style['fontFamily']};
            --ui-btn-radius: {$style['buttonRadius']};
            --ui-card-radius: {$style['cardRadius']};
            --ui-input-radius: {$style['inputRadius']};
        }
    </style>
    ";
}

/**
 * デザインCSSを生成
 */
function generateDesignCSS(array $settings): string {
    $themeId = 'lavender';
    $theme = getThemeById($themeId);
    $accentColor = $settings['accent_color'] ?? DESIGN_DEFAULT_ACCENT;
    $fontSize = $settings['font_size'] ?? DESIGN_DEFAULT_FONT_SIZE;
    $uiStyle = $settings['ui_style'] ?? DESIGN_DEFAULT_STYLE;
    $fontFamily = $settings['font_family'] ?? DESIGN_DEFAULT_FONT;
    
    // フォントサイズ（共通設定から取得）
    $fs = getFontSizeById($fontSize);
    
    // フォントファミリー（共通設定から取得）
    $fontData = getFontById($fontFamily);
    $ff = $fontData['family'];
    
    // テーマから詳細設定を取得
    $panelBg = $theme['panelBg'] ?? 'rgba(255,255,255,0.95)';
    $rightPanelBg = $theme['rightPanelBg'] ?? $panelBg;
    $panelBgCenter = $theme['panelBgCenter'] ?? 'rgba(255,255,255,0.98)';
    $cardBg = $theme['cardBg'] ?? 'linear-gradient(180deg, #ffffff 0%, #f8f9fa 100%)';
    $cardBorder = $theme['cardBorder'] ?? 'rgba(0,0,0,0.1)';
    $cardShadow = $theme['cardShadow'] ?? '0 2px 8px rgba(0,0,0,0.08)';
    $textColor = $theme['textColor'] ?? '#333';
    $textMuted = $theme['textMuted'] ?? '#666';
    $textLight = $theme['textLight'] ?? '#888';
    $inputBg = $theme['inputBg'] ?? '#fff';
    $inputBorder = $theme['inputBorder'] ?? 'rgba(0,0,0,0.15)';
    $inputFocus = $theme['inputFocus'] ?? 'rgba(0,0,0,0.1)';
    $selfMsgBg = $theme['selfMsgBg'] ?? $theme['accent'];
    $selfMsgText = $theme['selfMsgText'] ?? '#ffffff';
    $otherMsgBg = $theme['otherMsgBg'] ?? 'linear-gradient(180deg, #f0f0f0 0%, #e8e8e8 100%)';
    $otherMsgText = $theme['otherMsgText'] ?? $textColor;
    $otherMsgBorder = $theme['otherMsgBorder'] ?? 'rgba(0,0,0,0.08)';
    $mentionMsgBg = $theme['mentionMsgBg'] ?? 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
    $mentionMsgText = $theme['mentionMsgText'] ?? '#92400e';
    $mentionMsgBorder = $theme['mentionMsgBorder'] ?? 'rgba(251,191,36,0.4)';
    $scrollTrack = $theme['scrollTrack'] ?? 'rgba(0,0,0,0.05)';
    $scrollThumb = $theme['scrollThumb'] ?? 'rgba(0,0,0,0.2)';
    $scrollThumbHover = $theme['scrollThumbHover'] ?? 'rgba(0,0,0,0.35)';
    $divider = $theme['divider'] ?? 'rgba(0,0,0,0.1)';
    $hoverBg = $theme['hoverBg'] ?? 'rgba(0,0,0,0.05)';
    $accentHover = $theme['accentHover'] ?? $theme['accent'];
    $rightPanelBg = $theme['rightPanelBg'] ?? $rightPanelBg;
    
    // 標準デザイン固定（透明テーマ・背景画像は廃止）
    $isTransparent = false;
    $backgroundImage = 'none';
    $bgOverrides = null;
    
    $themeIdForComment = $theme['id'] ?? $themeId;
    $css = "
    <!-- デザインCSS ver.2 (theme={$themeIdForComment}) 更新時は includes/design_loader.php をデプロイしPHP opcacheをクリア -->
    <style id=\"user-design-settings\">
        :root {
            /* テーマカラー */
            --theme-header: {$theme['headerGradient']};
            --theme-bg: {$theme['bgColor']};
            --theme-accent: {$theme['accent']};
            --theme-accent-hover: {$accentHover};
            
            /* テキストカラー */
            --theme-text: {$textColor};
            --theme-text-muted: {$textMuted};
            --theme-text-light: {$textLight};
            --theme-header-text: " . ($theme['headerText'] ?? '#ffffff') . ";
            
            /* パネル・カード */
            --theme-panel-bg: {$panelBg};
            --theme-right-panel-bg: {$rightPanelBg};
            --theme-card-bg: {$cardBg};
            --theme-card-border: {$cardBorder};
            --theme-card-shadow: {$cardShadow};
            
            /* 入力欄 */
            --theme-input-bg: {$inputBg};
            --theme-input-border: {$inputBorder};
            --theme-input-focus: {$inputFocus};
            
            /* デザインページ・チャット共通で参照する --dt-*（左パネル・中央・入力欄） */
            --dt-left-bg: " . ($theme['leftPanelBg'] ?? $panelBg) . ";
            --dt-left-text: " . ($theme['leftPanelText'] ?? $textColor) . ";
            --dt-right-bg: {$rightPanelBg};
            --dt-right-text: " . ($theme['rightPanelText'] ?? $textColor) . ";
            --dt-center-bg: {$panelBgCenter};
            --dt-center-header-bg: " . ($theme['headerGradient'] ?? $panelBg) . ";
            --dt-center-header-text: " . ($theme['headerText'] ?? '#ffffff') . ";
            --dt-center-input-bg: {$inputBg};
            --dt-input-bg: {$inputBg};
            --dt-input-text: " . ($theme['inputText'] ?? $textColor) . ";
            --dt-input-placeholder: {$textMuted};
            --dt-input-border: {$inputBorder};
            --dt-divider: {$divider};
            
            /* メッセージ（.message-card で参照する --dt-msg-* をレガシーでも出力） */
            --theme-self-msg-bg: {$selfMsgBg};
            --theme-self-msg-text: {$selfMsgText};
            --theme-other-msg-bg: {$otherMsgBg};
            --theme-other-msg-text: {$otherMsgText};
            --dt-msg-self-bg: {$selfMsgBg};
            --dt-msg-self-text: {$selfMsgText};
            --dt-msg-self-border: transparent;
            --dt-msg-other-bg: {$otherMsgBg};
            --dt-msg-other-text: {$otherMsgText};
            --dt-msg-other-border: {$otherMsgBorder};
            --dt-msg-mention-bg: {$mentionMsgBg};
            --dt-msg-mention-text: {$mentionMsgText};
            --dt-msg-mention-border: {$mentionMsgBorder};
            --dt-mention-border: {$mentionMsgBorder};
            --dt-mention-badge-bg: {$theme['accent']};
            --dt-mention-badge-text: #ffffff;
            
            /* スクロールバー */
            --theme-scroll-track: {$scrollTrack};
            --theme-scroll-thumb: {$scrollThumb};
            --theme-scroll-thumb-hover: {$scrollThumbHover};
            
            /* その他 */
            --theme-divider: {$divider};
            --theme-hover-bg: {$hoverBg};
            
            /* アクションボタン（テーマ連動） */
            --theme-btn-primary: {$theme['accent']};
            --theme-btn-primary-hover: {$accentHover};
            --theme-btn-secondary: {$selfMsgBg};
            --theme-btn-text: #ffffff;
            
            /* フォント */
            --font-base: {$fs['base']};
            --font-message: {$fs['message']};
            --user-font: {$ff};
            --font-title: {$fs['title']};
        }
        
        /* === 背景（パネル間のギャップ色） === */
        html, body {
            background: {$theme['bgColor']} !important;
            font-size: var(--font-base);
            font-family: var(--user-font) !important;
            color: {$textColor} !important;
            overflow: hidden;
            height: 100%;
        }
        
        /* === 上パネル（ヘッダー）=== 背面を白に統一 */
        .top-panel,
        header.top-panel,
        .header,
        .top-panel .top-left,
        .top-panel .top-right {
            background: {$theme['headerGradient']} !important;
        }
        /* 上パネル内の文字・ボタン色（テーマの headerText で統一・チェリーは濃い色で可読性確保） */
        .top-panel,
        .top-panel *,
        .top-panel .top-btn,
        .top-panel .logo,
        .top-panel .user-info,
        .top-panel .search-box,
        .top-panel .search-box-input,
        .top-panel button,
        .top-panel a,
        .top-panel .toggle-left-btn,
        .top-panel .toggle-right-btn,
        .top-panel .settings-btn {
            color: var(--theme-header-text, #ffffff) !important;
        }
        /* テーマ：headerText で上パネル文字色を制御（チェリー規格：明るい背景＋濃い文字で可読性確保） */
        .top-panel .search-box-input::placeholder {
            color: var(--theme-header-text, #ffffff);
            opacity: 0.8;
        }
        /* 白枠ボタン・検索枠は濃い文字で可読性確保（枠線グレー・内側白の統一デザイン） */
        .top-panel .top-btn,
        .top-panel .top-btn *,
        .top-panel .user-info,
        .top-panel .user-info *,
        .top-panel .search-box,
        .top-panel .search-box-input,
        .top-panel .settings-btn,
        .top-panel .toggle-left-btn,
        .top-panel .toggle-right-btn {
            color: var(--dt-btn-secondary-text, #424242) !important;
        }
        .top-panel .search-box-input::placeholder {
            color: var(--dt-text-muted, #757575) !important;
            opacity: 0.8;
        }
        
        
        /* === 左パネル === */
        .left-panel,
        .left-spacer {
            background: {$panelBg} !important;
            color: {$textColor} !important;
        }
        
        
        /* === フィルターボタン（すべて・未読・グループ・DM） === */
        .filter-tabs button,
        .filter-btn,
        .tab-btn,
        .left-header-tabs button {
            background: " . ($theme['filterBtnBg'] ?? 'rgba(255,255,255,0.85)') . " !important;
            color: " . ($theme['filterBtnText'] ?? $textColor) . " !important;
            border: 2px solid " . ($theme['filterBtnBorder'] ?? '#9ca3af') . " !important;
        }
        .filter-tabs button.active,
        .filter-btn.active,
        .tab-btn.active,
        .left-header-tabs button.active {
            background: " . ($theme['filterBtnActiveBg'] ?? $theme['accent']) . " !important;
            color: " . ($theme['filterBtnActiveText'] ?? '#ffffff') . " !important;
        }
        
        /* === 中央パネル === */
        .center-panel {
            background: {$panelBgCenter} !important;
            color: {$textColor} !important;
        }
        
        
        /* === 右パネル === */
        .right-panel,
        .right-spacer {
            background: {$rightPanelBg} !important;
            color: {$textColor} !important;
        }
        
        
        " . (!empty($theme['isLightBackground']) ? "
        /* === 明るい背景画像用のスタイル上書き === */
        
        /* 会話リスト - 明るい背景用 */
        .conv-item,
        .conversation-item {
            background: " . ($theme['convItemBg'] ?? 'rgba(255,255,255,0.7)') . " !important;
            color: " . ($theme['convItemText'] ?? '#1a3d1a') . " !important;
        }
        .conv-item:hover,
        .conversation-item:hover {
            background: " . ($theme['convItemBg'] ?? 'rgba(255,255,255,0.8)') . " !important;
        }
        .conv-item.active,
        .conversation-item.active {
            background: " . ($theme['convItemActiveBg'] ?? $theme['accent']) . " !important;
            border-color: transparent !important;
            border-left: 3px solid " . ($theme['accentHover'] ?? $theme['accent']) . " !important;
        }
        .conv-item.active .conv-name,
        .conv-item.active .conv-preview,
        .conv-item.active .conv-time,
        .conv-item.active .conv-member-count,
        .conversation-item.active .conv-name,
        .conversation-item.active * {
            color: " . ($theme['convItemActiveText'] ?? '#ffffff') . " !important;
        }
        .conv-item .conv-name,
        .conv-item .conv-preview,
        .conv-item .conv-time,
        .conv-item .conv-member-count {
            color: " . ($theme['convItemText'] ?? '#1a3d1a') . " !important;
        }
        .conv-item .conv-preview,
        .conv-item .conv-time {
            opacity: 0.8;
        }
        
        /* 左パネル - 明るい背景用 */
        .left-panel,
        .left-panel * {
            color: " . ($theme['leftPanelText'] ?? $theme['textColor'] ?? '#1a3d1a') . " !important;
        }
        .left-panel .filter-tabs button {
            background: " . ($theme['filterBtnBg'] ?? 'rgba(255,255,255,0.85)') . " !important;
            color: " . ($theme['filterBtnText'] ?? '#1a3d1a') . " !important;
        }
        .left-panel .filter-tabs button.active {
            background: " . ($theme['filterBtnActiveBg'] ?? $theme['accent']) . " !important;
            color: " . ($theme['filterBtnActiveText'] ?? '#ffffff') . " !important;
        }
        
        /* 右パネル - 明るい背景用 */
        .right-panel,
        .right-panel * {
            color: " . ($theme['rightPanelText'] ?? $theme['textColor'] ?? '#1a3d1a') . " !important;
        }
        .right-panel,
        .right-spacer {
            background: " . ($theme['rightPanelBg'] ?? $panelBg) . " !important;
        }
        .right-panel .section-header,
        .right-panel .section-header h5,
        .right-panel .setting-section-title {
            color: " . ($theme['rightPanelText'] ?? '#1a3d1a') . " !important;
        }
        .right-panel .info-card,
        .right-panel .file-item,
        .right-panel .member-item {
            background: rgba(255,255,255,0.6) !important;
            color: " . ($theme['rightPanelText'] ?? '#1a3d1a') . " !important;
        }
        
        /* バッジ - 明るい背景用 */
        .badge,
        .unread-badge {
            background: " . ($theme['accent'] ?? '#4a7c59') . " !important;
            color: #ffffff !important;
        }
        " : "") . "
        
        " . (isset($theme['headerText']) ? "
        /* === 明るい背景用のスタイル上書き === */
        
        /* ヘッダーの文字色 */
        .top-panel,
        .top-panel *,
        header.top-panel,
        header.top-panel * {
            color: {$theme['headerText']} !important;
        }
        .top-panel .app-title,
        .top-panel .search-input,
        .top-panel .nav-item,
        .top-panel button {
            color: {$theme['headerText']} !important;
        }
        .top-panel .search-input::placeholder {
            color: {$theme['headerText']} !important;
            opacity: 0.6;
        }
        
        /* 左パネルの文字色 */
        .left-panel,
        .left-panel * {
            color: " . ($theme['leftPanelText'] ?? '#1e293b') . " !important;
        }
        .left-panel .conv-item,
        .left-panel .conv-item *,
        .left-panel .group-name,
        .left-panel .last-message,
        .left-panel .time {
            color: " . ($theme['leftPanelText'] ?? '#1e293b') . " !important;
        }
        .left-panel .last-message,
        .left-panel .time {
            opacity: 0.7;
        }
        
        /* フィルターボタン */
        .filter-tabs button,
        .filter-btn,
        .tab-btn,
        .left-header-tabs button {
            background: " . ($theme['filterBtnBg'] ?? 'rgba(241,245,249,0.95)') . " !important;
            color: " . ($theme['filterBtnText'] ?? '#334155') . " !important;
            border: 1px solid rgba(100,116,139,0.2) !important;
        }
        .filter-tabs button.active,
        .filter-btn.active,
        .tab-btn.active,
        .left-header-tabs button.active {
            background: " . ($theme['filterBtnActiveBg'] ?? '#3b82f6') . " !important;
            color: " . ($theme['filterBtnActiveText'] ?? '#ffffff') . " !important;
        }
        
        /* 中央パネルヘッダー */
        .center-panel .chat-header,
        .center-panel .room-header {
            background: {$theme['headerGradient']} !important;
        }
        .center-panel .chat-header h2,
        .center-panel .chat-header .chat-title-area h2,
        .center-panel .chat-header .chat-title-area .status,
        .center-panel .room-header h2,
        .center-panel .room-header .status {
            color: {$theme['headerText']} !important;
        }
        .center-panel .chat-header .badge {
            background: rgba(59,130,246,0.15) !important;
            color: {$theme['headerText']} !important;
        }
        .center-panel .chat-header-right .btn,
        .center-panel .chat-header .action-btn {
            color: {$theme['headerText']} !important;
            border-color: rgba(100,116,139,0.3) !important;
        }
        
        /* 右パネルの文字色 */
        .right-panel,
        .right-panel *,
        .right-header,
        .section-header,
        .section-header h5 {
            color: " . ($theme['rightPanelText'] ?? '#1e293b') . " !important;
        }
        
        /* 入力欄 */
        .message-input-area input,
        .message-input-area textarea,
        .input-area input,
        .input-area textarea,
        .search-input,
        input[type='text'],
        textarea {
            background: " . ($theme['inputBg'] ?? '#ffffff') . " !important;
            color: " . ($theme['inputText'] ?? '#1e293b') . " !important;
            border-color: " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.3)') . " !important;
        }
        input::placeholder,
        textarea::placeholder {
            color: " . ($theme['inputText'] ?? '#1e293b') . " !important;
            opacity: 0.5;
        }
        
        /* 中央パネルの入力エリア全体 */
        .message-input-area,
        .input-area,
        .center-panel .input-area {
            background: rgba(255,255,255,0.98) !important;
        }
        
        /* 右パネルのセクション（概要、メディア、グループ設定） */
        .right-panel .section,
        .right-panel .info-section,
        .right-panel .media-section,
        .right-panel .group-settings,
        .right-panel .room-overview,
        .right-panel .collapsible-section,
        .right-panel .accordion-section,
        .right-panel [class*='section'] {
            background: rgba(255,255,255,0.98) !important;
        }
        
        /* 右パネルのセクションヘッダー */
        .right-panel .section-header,
        .right-panel .info-section-title,
        .right-panel .section-title,
        .right-panel h4,
        .right-panel h5,
        .right-panel .collapsible-header,
        .right-panel .accordion-header {
            background: rgba(255,255,255,0.98) !important;
            color: " . ($theme['rightPanelText'] ?? '#1e293b') . " !important;
        }
        
        /* 右パネルの入力欄（デザイントークン使用） */
        .right-panel input,
        .right-panel textarea,
        .right-panel .form-control {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
            border-color: var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.25)') . ") !important;
            -webkit-text-fill-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
        }
        
        /* 概要メモ入力欄の明示的スタイル */
        .right-panel .memo-input,
        #conversationMemo {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
            border: 1px solid var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.25)') . ") !important;
            -webkit-text-fill-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
            caret-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
        }
        .right-panel .memo-input::placeholder,
        #conversationMemo::placeholder {
            color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            -webkit-text-fill-color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            opacity: 1 !important;
        }
        .right-panel .memo-input[readonly],
        #conversationMemo[readonly] {
            background: var(--dt-right-panel-bg, " . ($theme['rightPanelBg'] ?? 'rgba(248,250,252,0.98)') . ") !important;
            border-color: transparent !important;
            cursor: default !important;
            opacity: 0.85;
        }
        
        /* 概要エントリ（複数対応） */
        .overview-entry {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            border-color: var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.25)') . ") !important;
        }
        .overview-entry.saved {
            background: var(--dt-right-panel-bg, " . ($theme['rightPanelBg'] ?? 'rgba(248,250,252,0.98)') . ") !important;
            border-color: " . ($theme['rightPanelBg'] ?? 'rgba(200,210,220,0.5)') . " !important;
        }
        .overview-entry.editing {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            border-color: var(--dt-accent, " . ($theme['accent'] ?? '#3b82f6') . ") !important;
        }
        .overview-entry textarea {
            color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
            -webkit-text-fill-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
        }
        .overview-entry.saved textarea {
            color: var(--dt-text-muted, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            -webkit-text-fill-color: var(--dt-text-muted, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
        }
        .overview-entry textarea::placeholder {
            color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            -webkit-text-fill-color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
        }
        .overview-add-btn {
            background: rgba(59, 130, 246, 0.08) !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
            color: var(--dt-accent, " . ($theme['accent'] ?? '#3b82f6') . ") !important;
        }
        .overview-add-btn:hover {
            background: rgba(59, 130, 246, 0.15) !important;
            border-color: var(--dt-accent, " . ($theme['accent'] ?? '#3b82f6') . ") !important;
        }
        .overview-entry-btn.save-btn {
            background: var(--dt-accent, " . ($theme['accent'] ?? '#3b82f6') . ") !important;
        }
        .overview-empty {
            color: var(--dt-text-muted, " . ($theme['textMuted'] ?? '#94a3b8') . ") !important;
        }
        
        /* 右パネルのボタン */
        .right-panel .btn,
        .right-panel button:not(.close-btn) {
            background: rgba(255,255,255,0.95) !important;
            color: " . ($theme['rightPanelText'] ?? '#1e293b') . " !important;
            border: 1px solid rgba(100,116,139,0.2) !important;
        }
        .right-panel .btn:hover,
        .right-panel button:not(.close-btn):hover {
            background: rgba(241,245,249,1) !important;
        }
        
        /* 右パネルのリストアイテム */
        .right-panel .list-item,
        .right-panel .menu-item,
        .right-panel .action-item {
            background: rgba(255,255,255,0.95) !important;
            color: " . ($theme['rightPanelText'] ?? '#1e293b') . " !important;
        }
        
        /* メディアセクションの入力欄（デザイントークン使用） */
        #mediaTitleInput,
        #mediaUrlInput,
        .media-add-compact input,
        .media-add-row input,
        .right-panel .media-section input,
        .right-panel .media-section textarea,
        .right-panel .media-input,
        .media-form input,
        .media-form textarea,
        .add-media-form input,
        .add-media-form textarea {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-input-text, " . ($theme['inputText'] ?? $theme['textColor'] ?? '#1e293b') . ") !important;
            border: 1px solid var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.3)') . ") !important;
            -webkit-text-fill-color: var(--dt-input-text, " . ($theme['inputText'] ?? $theme['textColor'] ?? '#1e293b') . ") !important;
        }
        #mediaTitleInput::placeholder,
        #mediaUrlInput::placeholder,
        .media-add-compact input::placeholder,
        .media-add-row input::placeholder {
            color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            -webkit-text-fill-color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            opacity: 1 !important;
        }
        
        /* メディア追加ボタン（デザイントークン使用） */
        .media-add-row button,
        .media-add-compact button {
            background: var(--dt-btn-secondary-bg, " . ($theme['cardBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-btn-secondary-text, " . ($theme['textColor'] ?? '#1e293b') . ") !important;
            border: 1px solid var(--dt-btn-secondary-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.3)') . ") !important;
        }
        .media-add-row button:hover,
        .media-add-compact button:hover {
            background: #f1f5f9 !important;
        }
        
        /* チャット入力エリア全体（背景含む） */
        #inputArea,
        .input-area,
        .message-input-area,
        .center-panel .input-area,
        .center-panel .message-input-area,
        .chat-input-area,
        .input-container,
        .input-wrapper,
        .message-input-wrapper {
            background: rgba(255,255,255,0.98) !important;
            border-top: 1px solid rgba(100,116,139,0.15) !important;
            border-radius: 16px !important;
            overflow: hidden !important;
        }
        
        /* 入力行 */
        .input-row,
        .input-actions {
            background: transparent !important;
        }
        
        /* チャット入力欄本体 */
        /* 入力欄（デザイントークン使用） */
        #messageInput,
        .input-area input,
        .input-area textarea,
        .input-wrapper textarea,
        .message-input,
        .chat-input,
        .center-panel input[type='text'],
        .center-panel textarea {
            background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-input-text, " . ($theme['textColor'] ?? '#1e293b') . ") !important;
            border: 1px solid var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.25)') . ") !important;
            -webkit-text-fill-color: var(--dt-input-text, " . ($theme['textColor'] ?? '#1e293b') . ") !important;
        }
        #messageInput::placeholder,
        .input-area input::placeholder,
        .input-area textarea::placeholder,
        .input-wrapper textarea::placeholder,
        .message-input::placeholder {
            color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            -webkit-text-fill-color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            opacity: 1 !important;
        }
        
        /* TOボタン・メンションボタン（デザイントークン使用・ツールバーTOはアクセント色でGIF/電話と統一） */
        .mention-btn,
        .input-area button.to-btn:not(.toolbar-btn),
        button[class*='to-']:not(.toolbar-btn),
        .input-actions button:not(.toolbar-btn),
        .input-area > button:not(.toolbar-btn):not(.input-send-btn) {
            background: var(--dt-btn-secondary-bg, " . ($theme['cardBg'] ?? '#ffffff') . ") !important;
            color: var(--dt-btn-secondary-text, " . ($theme['textColor'] ?? '#1e293b') . ") !important;
            border: 1px solid var(--dt-btn-secondary-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.3)') . ") !important;
        }
        .mention-btn:hover {
            background: var(--dt-btn-secondary-hover, rgba(241,245,249,1)) !important;
        }
        /* ツールバーTOボタン＝GIF・電話・添付と同じアクセント色（文字は白で統一） */
        .toolbar-btn.to-btn,
        .input-area .toolbar-btn.to-btn {
            background: {$theme['accent']} !important;
            color: #ffffff !important;
            border: none !important;
        }
        .toolbar-btn.to-btn:hover,
        .input-area .toolbar-btn.to-btn:hover {
            background: {$accentHover} !important;
        }
        
        /* 絵文字・添付ボタン（デザイントークン使用） */
        .emoji-btn,
        .attach-btn,
        .file-btn,
        .input-area .icon-btn {
            background: transparent !important;
            color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
        }
        .emoji-btn:hover,
        .attach-btn:hover,
        .file-btn:hover {
            background: rgba(241,245,249,0.8) !important;
        }
        
        /* 送信ボタン */
        .send-btn,
        .input-area .send-btn,
        button[type='submit'] {
            background: #3b82f6 !important;
            color: #ffffff !important;
            border: none !important;
        }
        " : "") . "
        
        /* === テキストカラー === */
        .setting-section-title,
        .preview-header,
        .info-section-title {
            color: {$textMuted} !important;
        }
        
        .style-name,
        .font-name,
        .theme-item-name {
            color: {$textColor} !important;
        }
        
        .style-desc,
        .font-desc {
            color: {$textMuted} !important;
        }
        
        /* === カード類（テーマ対応グラデーション） === */
        .theme-item,
        .style-item,
        .font-item,
        .memo-card,
        .notification-item,
        .task-card {
            background: {$cardBg} !important;
            border: 1px solid {$cardBorder} !important;
            box-shadow: {$cardShadow} !important;
            color: {$textColor} !important;
            transition: all 0.2s ease;
        }
        
        .theme-item:hover,
        .style-item:hover,
        .font-item:hover,
        .memo-card:hover {
            background: {$hoverBg} !important;
            border-color: {$theme['accent']} !important;
        }
        
        /* === 入力欄 === */
        .preview-input,
        input[type='text'],
        input[type='email'],
        input[type='password'],
        input[type='search'],
        input[type='number'],
        input[type='tel'],
        textarea,
        select {
            background: {$inputBg} !important;
            color: {$textColor} !important;
            border: 1px solid {$inputBorder} !important;
            transition: all 0.2s ease;
        }
        
        .preview-input:focus,
        input:focus,
        textarea:focus,
        select:focus {
            border-color: {$theme['accent']} !important;
            box-shadow: 0 0 0 3px {$inputFocus} !important;
            outline: none !important;
        }
        
        input::placeholder,
        textarea::placeholder {
            color: {$textLight} !important;
        }
        
        /* === 相手のメッセージ（デザイントークン使用） === */
        .preview-message.other,
        .message-bubble.other,
        .message.other .bubble {
            background: var(--dt-msg-other-bg, {$otherMsgBg}) !important;
            color: var(--dt-msg-other-text, {$otherMsgText}) !important;
            border: 1px solid var(--dt-msg-other-border, {$otherMsgBorder}) !important;
        }
        
        /* === 自分のメッセージ（デザイントークン使用） === */
        .preview-message.self,
        .message-bubble.self,
        .message.self .bubble {
            background: var(--dt-msg-self-bg, {$selfMsgBg}) !important;
            color: var(--dt-msg-self-text, {$selfMsgText}) !important;
            border: 1px solid var(--dt-msg-self-border, transparent) !important;
        }
        
        /* === メッセージカード（テーマ別・body付きで優先度確保） === */
        body .message-card:not(.own) {
            background: {$otherMsgBg} !important;
            color: {$otherMsgText} !important;
            border: 1px solid {$otherMsgBorder} !important;
        }
        body .message-card:not(.own) .content,
        body .message-card:not(.own) .message-content {
            color: {$otherMsgText} !important;
        }
        body .message-card:not(.own) .time,
        body .message-card:not(.own) .timestamp {
            color: {$otherMsgText} !important;
            opacity: 0.9;
        }
        body .message-card.own {
            background: {$selfMsgBg} !important;
            color: {$selfMsgText} !important;
            border: none !important;
        }
        body .message-card.own .content,
        body .message-card.own .message-content {
            color: {$selfMsgText} !important;
        }
        body .message-card.own .time,
        body .message-card.own .timestamp {
            color: {$selfMsgText} !important;
            opacity: 0.9;
        }
        body .message-card.own .label {
            color: {$selfMsgText} !important;
        }
        body .message-card:not(.own) .label {
            color: {$otherMsgText} !important;
        }
        body .message-card.mention-frame,
        body .message-card.mentioned-me {
            background: {$mentionMsgBg} !important;
            color: {$mentionMsgText} !important;
            border: 2px solid {$mentionMsgBorder} !important;
        }
        body .message-card.mention-frame .content,
        body .message-card.mention-frame .message-content,
        body .message-card.mentioned-me .content,
        body .message-card.mentioned-me .message-content {
            color: {$mentionMsgText} !important;
        }
        
        /* === アクセントカラー（ボタン類） === */
        .btn-primary,
        .toggle input:checked + .toggle-slider,
        .toggle-switch input:checked + .toggle-slider {
            background: {$theme['accent']} !important;
        }
        
        .btn-primary:hover {
            background: {$accentHover} !important;
        }
        
        .preview-send-btn,
        .send-btn,
        .input-send-btn,
        .input-row > .input-send-btn,
        .input-area .input-send-btn {
            background: {$theme['accent']} !important;
            color: #ffffff !important;
            border: none !important;
            transition: all 0.2s ease;
        }
        
        .preview-send-btn:hover,
        .send-btn:hover,
        .input-send-btn:hover,
        .input-row > .input-send-btn:hover {
            background: {$accentHover} !important;
            transform: translateY(-1px);
        }
        
        /* チャット入力欄：Toボタン・送信ボタンの文字・アイコンは常に白（単一ルールで他を上書き） */
        .input-area .input-toolbar-left .toolbar-btn.to-btn,
        .input-area .input-toolbar-right .input-send-btn,
        body.page-chat .input-area .input-toolbar-left .toolbar-btn.to-btn,
        body.page-chat .input-area .input-toolbar-right .input-send-btn {
            color: #ffffff !important;
        }
        
        a.active,
        .tabs a.active {
            color: {$theme['accent']} !important;
        }
        
        .theme-item.active,
        .style-item.active,
        .font-item.active,
        .menu-item.active {
            border-color: {$theme['accent']} !important;
            box-shadow: 0 0 0 2px {$inputFocus} !important;
        }
        
        .font-size-btn {
            background: {$cardBg} !important;
            color: {$textColor} !important;
            border: 1px solid {$cardBorder} !important;
            transition: all 0.2s ease;
        }
        
        .font-size-btn:hover {
            border-color: {$theme['accent']} !important;
        }
        
        .font-size-btn.active {
            background: {$theme['accent']} !important;
            border-color: {$theme['accent']} !important;
            color: {$selfMsgText} !important;
        }
        
        /* === 区切り線 === */
        hr,
        .divider {
            border-color: {$divider} !important;
        }
        
        /* === スクロールバー（テーマ統合・目立たない配色） === */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: {$scrollTrack};
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: {$scrollThumb};
            border-radius: 10px;
            transition: background 0.2s ease;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: {$scrollThumbHover};
        }
        
        ::-webkit-scrollbar-corner {
            background: transparent;
        }
        
        /* === ボタン類 === */
        .reset-btn {
            background: {$cardBg} !important;
            color: {$textMuted} !important;
            border-color: {$cardBorder} !important;
        }
        
        /* === メッセージ文字サイズ === */
        .message-content,
        .preview-message,
        .memo-content,
        .message-text {
            font-size: var(--font-message);
        }
        
        /* === 会話リスト（左パネル内） === */
        .conv-item,
        .conversation-item {
            background: {$cardBg} !important;
            color: {$textColor} !important;
            border-color: {$cardBorder} !important;
        }
        
        .conv-item:hover,
        .conversation-item:hover,
        .menu-item:hover {
            filter: brightness(0.97);
        }
        
        " . (in_array($backgroundImage, ['sample_tokei_clover01.jpg', 'sample_suika01.jpg', 'sample_yukidaruma01.jpg', 'sample_snow.jpg', 'sample_city01.jpg']) ? "
        /* 明るい背景用：選択時は薄い背景＋黒文字（高詳細度） */
        .left-panel .conv-item.active,
        .left-panel .conversation-item.active,
        aside.left-panel .conv-item.active,
        .conv-item.active,
        .conversation-item.active {
            background: rgba(74,124,89,0.3) !important;
            color: #1a3d1a !important;
            border-left: 3px solid #4a7c59 !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        
        .left-panel .conv-item.active .conv-name,
        .left-panel .conv-item.active .conv-preview,
        .left-panel .conv-item.active .conv-time,
        .left-panel .conv-item.active .conv-member-count,
        aside.left-panel .conv-item.active .conv-name,
        aside.left-panel .conv-item.active .conv-time,
        aside.left-panel .conv-item.active .conv-member-count,
        .conv-item.active .conv-name,
        .conv-item.active .conv-preview,
        .conv-item.active .conv-time,
        .conv-item.active .conv-member-count {
            color: #1a3d1a !important;
        }
        " : "
        /* 通常テーマ：選択時はアクセントカラー＋白文字 */
        .conv-item.active,
        .conversation-item.active {
            background: linear-gradient(135deg, {$accentColor} 0%, {$accentColor}cc 100%) !important;
            color: white !important;
            box-shadow: 0 2px 8px {$accentColor}4d;
        }
        
        .conv-item.active .conv-name,
        .conv-item.active .conv-preview,
        .conv-item.active .conv-time {
            color: white !important;
        }
        ") . "
        
        /* === ファイル・情報セクション === */
        .room-info,
        .file-item,
        .info-card {
            background: {$cardBg} !important;
            color: {$textColor} !important;
        }
        
        /* === プレビューエリア === */
        .preview-chat,
        .messages-container,
        .chat-messages {
            background: transparent !important;
        }
        
        .preview-header,
        .preview-input-area {
            background: transparent !important;
            border-color: {$cardBorder} !important;
        }
    ";
    
    // モバイル版の右パネル入力欄スタイル（概要メモ）
    $css .= "
        /* === モバイル版 右パネル入力欄修正 === */
        @media (max-width: 768px) {
            /* 概要メモ入力欄 - 入力中 */
            .right-panel .memo-input,
            .right-panel #conversationMemo,
            .right-panel textarea,
            .right-panel input[type='text'] {
                background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
                color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
                -webkit-text-fill-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
                caret-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
                border: 1px solid var(--dt-input-border, " . ($theme['inputBorder'] ?? 'rgba(100,116,139,0.25)') . ") !important;
            }
            
            /* 概要メモ入力欄 - 保存済み（readonly） */
            .right-panel .memo-input[readonly],
            .right-panel #conversationMemo[readonly] {
                background: var(--dt-right-panel-bg, " . ($theme['rightPanelBg'] ?? 'rgba(248,250,252,0.98)') . ") !important;
                border-color: transparent !important;
                opacity: 0.85;
            }
            
            /* プレースホルダー */
            .right-panel .memo-input::placeholder,
            .right-panel #conversationMemo::placeholder,
            .right-panel textarea::placeholder {
                color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
                -webkit-text-fill-color: var(--dt-input-placeholder, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            }
            
            /* 概要エントリ（モバイル版） */
            .overview-entry.saved {
                background: var(--dt-right-panel-bg, " . ($theme['rightPanelBg'] ?? 'rgba(248,250,252,0.98)') . ") !important;
            }
            .overview-entry.saved textarea {
                color: var(--dt-text-muted, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
                -webkit-text-fill-color: var(--dt-text-muted, " . ($theme['textMuted'] ?? '#64748b') . ") !important;
            }
            .overview-entry.editing {
                background: var(--dt-input-bg, " . ($theme['inputBg'] ?? '#ffffff') . ") !important;
            }
            .overview-entry.editing textarea {
                color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
                -webkit-text-fill-color: var(--dt-input-text, " . ($theme['rightPanelText'] ?? '#1e293b') . ") !important;
            }
        }
    ";
    
    // 統一デザイン：枠線で囲う・内側は白・フォントは黒（テーマより優先）
    $css .= "
    /* 背面を白に統一（ヘッダー直下ストリップ・メインコンテナのグレーを解消） */
    html,
    body {
        background: #ffffff !important;
    }
    body.page-chat .main-container {
        background: #ffffff !important;
    }
    /* 上パネル共有ページ: body の overflow を visible に（インラインの overflow:hidden がドロップダウンをクリップするため上書き） */
    body.page-settings,
    body.design-page,
    body.tasks-page,
    body.notifications-page {
        overflow: visible !important;
    }
    /* 上パネル: 立体ベゼル（B仕様・白＋黒の二重影・金属縁取り） */
    .top-panel,
    header.top-panel {
        background: linear-gradient(180deg, #e6e8ec 0%, #dcdee2 100%) !important;
        border: 1px solid var(--dt-header-bezel-border, #e0e0e0) !important;
        border-radius: 18px !important;
        padding: 7px !important;
        box-shadow: 0 4px 12px rgba(0,0,0,0.10),
                    0 1px 3px rgba(0,0,0,0.06),
                    2px 2px 6px rgba(255,255,255,0.5),
                    inset 0 1px 0 rgba(255,255,255,0.6),
                    inset 1px 0 0 rgba(255,255,255,0.3),
                    inset 1px 1px 2px rgba(0,0,0,0.06) !important;
        overflow: visible !important;
    }
    .top-panel .top-left,
    .top-panel .top-right {
        background: transparent !important;
    }
    /* 上パネル共有ページで立体スタイルを確実に適用（ページインラインより優先） */
    body.page-settings .top-panel,
    body.page-settings header.top-panel,
    body.design-page .top-panel,
    body.design-page header.top-panel,
    body.tasks-page .top-panel,
    body.tasks-page header.top-panel,
    body.notifications-page .top-panel,
    body.notifications-page header.top-panel {
        background: linear-gradient(180deg, #e6e8ec 0%, #dcdee2 100%) !important;
        border: 1px solid var(--dt-header-bezel-border, #e0e0e0) !important;
        border-radius: 18px !important;
        padding: 7px !important;
        box-shadow: 0 4px 12px rgba(0,0,0,0.10),
                    0 1px 3px rgba(0,0,0,0.06),
                    2px 2px 6px rgba(255,255,255,0.5),
                    inset 0 1px 0 rgba(255,255,255,0.6),
                    inset 1px 0 0 rgba(255,255,255,0.3),
                    inset 1px 1px 2px rgba(0,0,0,0.06) !important;
        overflow: visible !important;
    }
    body.page-settings .top-panel .top-left,
    body.page-settings .top-panel .top-right,
    body.design-page .top-panel .top-left,
    body.design-page .top-panel .top-right,
    body.tasks-page .top-panel .top-left,
    body.tasks-page .top-panel .top-right,
    body.notifications-page .top-panel .top-left,
    body.notifications-page .top-panel .top-right {
        background: transparent !important;
        overflow: visible !important;
    }
    body.page-settings .top-panel .top-panel-inner,
    body.design-page .top-panel .top-panel-inner,
    body.tasks-page .top-panel .top-panel-inner,
    body.notifications-page .top-panel .top-panel-inner {
        display: flex !important;
        flex: 1 !important;
        min-width: 0 !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px !important;
        padding: 5px 14px !important;
        background: var(--dt-header-inner-bg-gradient, linear-gradient(135deg, #f2f4f6 0%, #eceff3 50%, #e8eaef 100%)) !important;
        border-radius: 12px !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
        overflow: visible !important;
    }
    body.page-settings .top-panel .user-menu-container,
    body.design-page .top-panel .user-menu-container,
    body.tasks-page .top-panel .user-menu-container,
    body.notifications-page .top-panel .user-menu-container {
        position: relative !important;
    }
    body.page-settings .top-panel .search-box,
    body.design-page .top-panel .search-box,
    body.tasks-page .top-panel .search-box,
    body.notifications-page .top-panel .search-box {
        min-width: 0 !important;
        max-width: 100% !important;
        background: var(--dt-header-recess-bg, #e8f0f8) !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        color: #555 !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
    }
    body.page-settings .top-panel .task-memo-buttons,
    body.design-page .top-panel .task-memo-buttons,
    body.tasks-page .top-panel .task-memo-buttons,
    body.notifications-page .top-panel .task-memo-buttons {
        background: var(--dt-header-recess-bg, #e8f0f8) !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
    }
    body.page-settings .top-panel .toggle-left-btn,
    body.page-settings .top-panel .toggle-right-btn,
    body.page-settings .top-panel .user-info,
    body.page-settings .top-panel .settings-btn,
    body.page-settings .top-panel .top-panel-toggle-btn#toggleTaskMemoBtn,
    body.design-page .top-panel .toggle-left-btn,
    body.design-page .top-panel .toggle-right-btn,
    body.design-page .top-panel .user-info,
    body.design-page .top-panel .settings-btn,
    body.design-page .top-panel .top-panel-toggle-btn#toggleTaskMemoBtn,
    body.tasks-page .top-panel .toggle-left-btn,
    body.tasks-page .top-panel .toggle-right-btn,
    body.tasks-page .top-panel .user-info,
    body.tasks-page .top-panel .settings-btn,
    body.tasks-page .top-panel .top-panel-toggle-btn#toggleTaskMemoBtn,
    body.notifications-page .top-panel .toggle-left-btn,
    body.notifications-page .top-panel .toggle-right-btn,
    body.notifications-page .top-panel .user-info,
    body.notifications-page .top-panel .settings-btn,
    body.notifications-page .top-panel .top-panel-toggle-btn#toggleTaskMemoBtn {
        background: var(--dt-header-convex-btn-bg, #f0f2f5) !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        color: #555 !important;
        box-shadow: inset 1px 1px 2px rgba(255,255,255,0.8),
                    2px 2px 4px rgba(0,0,0,0.12) !important;
    }
    body.page-settings .top-panel .logo,
    body.design-page .top-panel .logo,
    body.tasks-page .top-panel .logo,
    body.notifications-page .top-panel .logo {
        color: var(--dt-header-logo-text, #345678) !important;
        background: var(--dt-header-logo-bg-gradient, linear-gradient(90deg, #d4dce8, #d8e4f0, #d4dce8)) !important;
    }
    body.page-settings .top-panel .logo *,
    body.design-page .top-panel .logo *,
    body.tasks-page .top-panel .logo *,
    body.notifications-page .top-panel .logo * {
        color: var(--dt-header-logo-text, #345678) !important;
        background: transparent !important;
    }
    body.page-settings .top-panel .top-btn *,
    body.page-settings .top-panel .user-info *,
    body.page-settings .top-panel .search-box-input,
    body.page-settings .top-panel .settings-btn *,
    body.design-page .top-panel .top-btn *,
    body.design-page .top-panel .user-info *,
    body.design-page .top-panel .search-box-input,
    body.design-page .top-panel .settings-btn *,
    body.tasks-page .top-panel .top-btn *,
    body.tasks-page .top-panel .user-info *,
    body.tasks-page .top-panel .search-box-input,
    body.tasks-page .top-panel .settings-btn *,
    body.notifications-page .top-panel .top-btn *,
    body.notifications-page .top-panel .user-info *,
    body.notifications-page .top-panel .search-box-input,
    body.notifications-page .top-panel .settings-btn * {
        border: none !important;
        background: transparent !important;
        color: #555 !important;
    }
    body.page-settings .top-panel .search-box-input::placeholder,
    body.design-page .top-panel .search-box-input::placeholder,
    body.tasks-page .top-panel .search-box-input::placeholder,
    body.notifications-page .top-panel .search-box-input::placeholder {
        color: #757575 !important;
    }
    body.page-settings .top-panel .task-memo-buttons .top-btn,
    body.design-page .top-panel .task-memo-buttons .top-btn,
    body.tasks-page .top-panel .task-memo-buttons .top-btn,
    body.notifications-page .top-panel .task-memo-buttons .top-btn {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        color: #555 !important;
    }
    /* 上パネル: 内側凹み（微細グラデ・窪み強調 B仕様） */
    .top-panel .top-panel-inner {
        display: flex !important;
        flex: 1 !important;
        min-width: 0 !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px !important;
        padding: 5px 14px !important;
        background: var(--dt-header-inner-bg-gradient, linear-gradient(135deg, #f2f4f6 0%, #eceff3 50%, #e8eaef 100%)) !important;
        border-radius: 12px !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
        overflow: visible !important;
    }
    .top-panel .top-left {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        min-width: 0 !important;
        flex-shrink: 1 !important;
    }
    .top-panel .top-right {
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        min-width: 0 !important;
        flex-shrink: 0 !important;
        overflow: visible !important;
    }
    /* 上パネル: 凸ボタン（戻る・Ken・設定・⇒）B仕様 */
    .top-panel .toggle-left-btn,
    .top-panel .toggle-right-btn,
    .top-panel .user-info,
    .top-panel .settings-btn,
    .top-panel .top-panel-toggle-btn#toggleTaskMemoBtn {
        background: var(--dt-header-convex-btn-bg, #f0f2f5) !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        color: #555 !important;
        box-shadow: inset 1px 1px 2px rgba(255,255,255,0.8),
                    2px 2px 4px rgba(0,0,0,0.12) !important;
    }
    /* 上パネル: 凹み検索バー（青みグレー・窪み強調） */
    .top-panel .search-box {
        min-width: 0 !important;
        max-width: 100% !important;
        background: var(--dt-header-recess-bg, #e8f0f8) !important;
        border: 1px solid rgba(0,0,0,0.04) !important;
        color: #555 !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
    }
    /* 上パネル: 凹みコンテナ（アイコン群） */
    .task-memo-buttons {
        background: var(--dt-header-recess-bg, #e8f0f8) !important;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,0.12),
                    inset -2px -2px 5px rgba(255,255,255,0.7) !important;
    }
    /* 上パネル: 子要素（box-shadow は指定しない） */
    .top-panel .top-btn *,
    .top-panel .nav-item *,
    .top-panel .user-info *,
    .top-panel .search-box-input,
    .top-panel .settings-btn * {
        border: none !important;
        background: transparent !important;
        color: #555 !important;
    }
    .top-panel .search-box-input::placeholder {
        color: #757575 !important;
    }
    /* アイコン群コンテナ内のボタン: 個別影なし */
    .task-memo-buttons .top-btn {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        color: #555 !important;
    }
    /* ロゴ: 凸・淡いブルー＋文字色（B仕様） */
    .top-panel .logo {
        color: var(--dt-header-logo-text, #345678) !important;
        background: var(--dt-header-logo-bg-gradient, linear-gradient(90deg, #d4dce8, #d8e4f0, #d4dce8)) !important;
    }
    .top-panel .logo * {
        color: var(--dt-header-logo-text, #345678) !important;
        background: transparent !important;
    }
    .left-panel .conv-item,
    .left-panel .conv-item.active,
    .left-panel .conversation-item.active {
        background: #ffffff !important;
        border: 2px solid #9ca3af !important;
        color: #1a1a1a !important;
    }
    .left-panel .conv-item.has-unread {
        border-left: 4px solid #ef4444 !important;
    }
    .left-panel .conv-item .conv-name,
    .left-panel .conv-item .conv-time,
    .left-panel .conv-item .conv-member-count,
    .left-panel .conv-item.active .conv-name,
    .left-panel .conv-item.active .conv-time,
    .left-panel .conv-item.active .conv-member-count {
        color: #1a1a1a !important;
    }
    .left-panel .conv-item.active .conv-avatar {
        background: #ffffff !important;
        border: 2px solid #9ca3af !important;
        color: #374151 !important;
    }
    ";
    
    // UIスタイル用CSS
    $css .= generateStyleCSS($uiStyle);
    
    $css .= "</style>";
    
    return $css;
}

/**
 * UIスタイル用CSSを生成
 */
function generateStyleCSS(string $styleId): string {
    // 共通設定からスタイルを取得
    $style = getStyleById($styleId);
    
    $css = "
    /* ===============================
       UIスタイル: {$styleId}
       =============================== */
    
    body.style-{$styleId},
    .style-{$styleId} {
        --ui-border-radius: {$style['borderRadius']};
        --ui-shadow: {$style['shadow']};
        --ui-border: {$style['border']};
        --ui-font: {$style['fontFamily']};
        --ui-btn-radius: {$style['buttonRadius']};
        --ui-card-radius: {$style['cardRadius']};
        --ui-input-radius: {$style['inputRadius']};
    }
    
    /* ===============================
       可愛いスタイル - 丸み、ふんわり
       =============================== */
    .style-cute .left-panel,
    .style-cute .center-panel,
    .style-cute .right-panel {
        border-radius: 20px !important;
        box-shadow: 0 4px 20px rgba(255,182,193,0.15) !important;
        border: 2px solid rgba(255,182,193,0.3) !important;
    }
    
    .style-cute .top-panel,
    .style-cute header.top-panel {
        border-radius: 20px !important;
    }
    
    .style-cute .conv-item,
    .style-cute .theme-item,
    .style-cute .style-item,
    .style-cute .memo-card,
    .style-cute .task-card {
        border-radius: 16px !important;
        border: 2px solid rgba(255,182,193,0.3) !important;
        box-shadow: 0 3px 10px rgba(255,182,193,0.12) !important;
        transition: all 0.25s ease;
    }
    
    .style-cute .conv-item:hover,
    .style-cute .theme-item:hover,
    .style-cute .style-item:hover {
        box-shadow: 0 5px 15px rgba(255,182,193,0.25) !important;
        border-color: rgba(255,182,193,0.5) !important;
        transform: translateY(-2px);
    }
    
    .style-cute .preview-message,
    .style-cute .message-bubble,
    .style-cute .message-card {
        border-radius: 18px !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
    }
    
    .style-cute .preview-message.self,
    .style-cute .message-bubble.self,
    .style-cute .message-card.own {
        border-radius: 18px 18px 4px 18px !important;
    }
    
    .style-cute .preview-message.other,
    .style-cute .message-bubble.other,
    .style-cute .message-card:not(.own) {
        border-radius: 18px 18px 18px 4px !important;
    }
    
    .style-cute button,
    .style-cute .btn,
    .style-cute .font-size-btn {
        border-radius: 25px !important;
        font-weight: 500;
        transition: all 0.25s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    }
    
    .style-cute button:hover,
    .style-cute .btn:hover {
        transform: translateY(-2px) scale(1.02);
        box-shadow: 0 4px 12px rgba(0,0,0,0.12) !important;
    }
    
    .style-cute input,
    .style-cute textarea,
    .style-cute select {
        border-radius: 20px !important;
        border: 2px solid rgba(255,182,193,0.4) !important;
        padding-left: 16px !important;
        transition: all 0.25s ease;
    }
    
    .style-cute input:focus,
    .style-cute textarea:focus,
    .style-cute select:focus {
        border-color: rgba(255,105,180,0.6) !important;
        box-shadow: 0 0 0 4px rgba(255,182,193,0.2) !important;
        outline: none;
    }
    
    .style-cute .setting-section-title,
    .style-cute .preview-header {
        font-weight: 600 !important;
        letter-spacing: 0.02em !important;
    }
    
    /* 可愛いスタイル用の特別な装飾 */
    .style-cute .setting-section {
        position: relative;
    }
    
    .style-cute .preview-send-btn,
    .style-cute .send-btn,
    .style-cute .input-send-btn {
        border-radius: 24px !important;
        width: 48px !important;
        min-width: 48px !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    /* ===============================
       ナチュラルスタイル - 柔らか、温かみ
       =============================== */
    .style-natural .left-panel,
    .style-natural .center-panel,
    .style-natural .right-panel {
        border-radius: 12px !important;
        box-shadow: 0 2px 12px rgba(139,119,101,0.1) !important;
        border: 1px solid rgba(139,119,101,0.15) !important;
        /* 背景色はテーマに従う */
    }
    
    .style-natural .top-panel,
    .style-natural header.top-panel {
        border-radius: 12px !important;
    }
    
    .style-natural .conv-item,
    .style-natural .theme-item,
    .style-natural .style-item,
    .style-natural .font-item,
    .style-natural .memo-card,
    .style-natural .task-card {
        border-radius: 12px !important;
        border: 1px solid rgba(139,119,101,0.15) !important;
        box-shadow: 0 2px 6px rgba(139,119,101,0.08) !important;
        /* 背景色はテーマに従う */
        transition: all 0.3s ease;
    }
    
    .style-natural .conv-item:hover,
    .style-natural .theme-item:hover,
    .style-natural .style-item:hover,
    .style-natural .font-item:hover {
        box-shadow: 0 4px 12px rgba(139,119,101,0.15) !important;
        border-color: rgba(139,119,101,0.25) !important;
        transform: translateY(-1px);
    }
    
    .style-natural .preview-message,
    .style-natural .message-bubble,
    .style-natural .message-card {
        border-radius: 14px !important;
        box-shadow: 0 1px 4px rgba(139,119,101,0.1) !important;
    }
    
    .style-natural .preview-message.self,
    .style-natural .message-bubble.self,
    .style-natural .message-card.own {
        border-radius: 14px 14px 4px 14px !important;
    }
    
    .style-natural .preview-message.other,
    .style-natural .message-bubble.other,
    .style-natural .message-card:not(.own) {
        border-radius: 14px 14px 14px 4px !important;
    }
    
    /* 透明テーマ以外でのみ背景を適用 */
    body:not([data-theme^=\"transparent\"]).style-natural .message-card:not(.own) {
        background: linear-gradient(180deg, #f5f3f0 0%, #ebe8e4 100%) !important;
    }
    
    .style-natural button,
    .style-natural .btn,
    .style-natural .font-size-btn {
        border-radius: 10px !important;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(139,119,101,0.1) !important;
    }
    
    .style-natural button:hover,
    .style-natural .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(139,119,101,0.15) !important;
    }
    
    .style-natural input,
    .style-natural textarea,
    .style-natural select {
        border-radius: 10px !important;
        border: 1px solid rgba(139,119,101,0.2) !important;
        background: #fffefa !important;
        transition: all 0.3s ease;
    }
    
    .style-natural input:focus,
    .style-natural textarea:focus,
    .style-natural select:focus {
        border-color: rgba(139,119,101,0.4) !important;
        box-shadow: 0 0 0 3px rgba(139,119,101,0.1) !important;
        outline: none;
    }
    
    .style-natural .setting-section-title,
    .style-natural .preview-header {
        color: #6b5b4f !important;
        font-weight: 500 !important;
    }
    
    /* ナチュラルスタイル用の特別な装飾 */
    .style-natural .preview-send-btn,
    .style-natural .send-btn,
    .style-natural .input-send-btn {
        border-radius: 12px !important;
    }
    
    /* ===============================
       クラシックスタイル - 上品、伝統的
       =============================== */
    .style-classic .left-panel,
    .style-classic .center-panel,
    .style-classic .right-panel {
        border-radius: 4px !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
        border: 1px solid #c9b99a !important;
        /* 背景色はテーマに従う */
    }
    
    .style-classic .top-panel,
    .style-classic header.top-panel {
        border-radius: 4px !important;
    }
    
    .style-classic .conv-item,
    .style-classic .theme-item,
    .style-classic .style-item,
    .style-classic .font-item,
    .style-classic .memo-card,
    .style-classic .task-card {
        border-radius: 4px !important;
        border: 1px solid #d4c4a8 !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.5), 0 1px 3px rgba(0,0,0,0.05) !important;
        /* 背景色はテーマに従う */
        transition: all 0.2s ease;
    }
    
    .style-classic .conv-item:hover,
    .style-classic .theme-item:hover,
    .style-classic .style-item:hover,
    .style-classic .font-item:hover {
        background: linear-gradient(180deg, #fffef9 0%, #f0ebe0 100%) !important;
        border-color: #b8a888 !important;
    }
    
    .style-classic .preview-message,
    .style-classic .message-bubble,
    .style-classic .message-card {
        border-radius: 6px !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.06) !important;
    }
    
    .style-classic .preview-message.self,
    .style-classic .message-bubble.self,
    .style-classic .message-card.own {
        border-radius: 6px 6px 2px 6px !important;
    }
    
    .style-classic .preview-message.other,
    .style-classic .message-bubble.other,
    .style-classic .message-card:not(.own) {
        border-radius: 6px 6px 6px 2px !important;
    }
    
    /* 透明テーマ以外でのみ背景を適用 */
    body:not([data-theme^=\"transparent\"]).style-classic .message-card:not(.own) {
        background: linear-gradient(180deg, #f5f2eb 0%, #ebe6db 100%) !important;
        border: 1px solid #d4c4a8 !important;
    }
    
    .style-classic button,
    .style-classic .btn,
    .style-classic .font-size-btn {
        border-radius: 4px !important;
        border: 1px solid #c9b99a !important;
        background: linear-gradient(180deg, #f8f4eb 0%, #ebe6db 100%) !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 1px 2px rgba(0,0,0,0.08) !important;
        transition: all 0.2s ease;
    }
    
    .style-classic button:hover,
    .style-classic .btn:hover {
        background: linear-gradient(180deg, #fffef9 0%, #f0ebe0 100%) !important;
        border-color: #b8a888 !important;
    }
    
    .style-classic input,
    .style-classic textarea,
    .style-classic select {
        border-radius: 4px !important;
        border: 1px solid #c9b99a !important;
        background: #fffef9 !important;
        transition: all 0.2s ease;
    }
    
    .style-classic input:focus,
    .style-classic textarea:focus,
    .style-classic select:focus {
        border-color: #a89878 !important;
        box-shadow: 0 0 0 2px rgba(168,152,120,0.15) !important;
        outline: none;
    }
    
    .style-classic .setting-section-title,
    .style-classic .preview-header {
        font-family: 'Georgia', 'Yu Mincho', 'Hiragino Mincho ProN', serif !important;
        font-weight: 500 !important;
        color: #5a4a3a !important;
        letter-spacing: 0.05em !important;
    }
    
    /* クラシックスタイル用の特別な装飾 */
    .style-classic .preview-send-btn,
    .style-classic .send-btn,
    .style-classic .input-send-btn {
        border-radius: 6px !important;
    }
    
    /* ===============================
       スクロールバー - 各スタイル対応
       =============================== */
    
    /* ナチュラルスタイル用スクロールバー（デザイントークン優先） */
    .style-natural ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }
    .style-natural ::-webkit-scrollbar-track {
        background: transparent;
        border-radius: 10px;
        margin: 8px 0;
    }
    .style-natural ::-webkit-scrollbar-thumb {
        background: var(--dt-scroll-thumb, rgba(139,119,101,0.15));
        border-radius: 10px;
    }
    .style-natural ::-webkit-scrollbar-thumb:hover {
        background: var(--dt-scroll-thumb-hover, rgba(139,119,101,0.25));
    }
    .style-natural ::-webkit-scrollbar-corner {
        background: transparent;
    }
    
    /* 可愛いスタイル用スクロールバー（デザイントークン優先） */
    .style-cute ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }
    .style-cute ::-webkit-scrollbar-track {
        background: transparent;
        border-radius: 20px;
        margin: 10px 0;
    }
    .style-cute ::-webkit-scrollbar-thumb {
        background: var(--dt-scroll-thumb, rgba(255,182,193,0.2));
        border-radius: 20px;
    }
    .style-cute ::-webkit-scrollbar-thumb:hover {
        background: var(--dt-scroll-thumb-hover, rgba(255,182,193,0.35));
    }
    .style-cute ::-webkit-scrollbar-corner {
        background: transparent;
    }
    
    /* 可愛いスタイル：パネルのスクロール調整 */
    .style-cute .left-panel,
    .style-cute .right-panel {
        scrollbar-gutter: stable;
    }
    
    /* クラシックスタイル用スクロールバー（デザイントークン優先） */
    .style-classic ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }
    .style-classic ::-webkit-scrollbar-track {
        background: transparent;
        border-radius: 4px;
        margin: 4px 0;
    }
    .style-classic ::-webkit-scrollbar-thumb {
        background: var(--dt-scroll-thumb, rgba(201,185,154,0.2));
        border-radius: 4px;
    }
    .style-classic ::-webkit-scrollbar-thumb:hover {
        background: var(--dt-scroll-thumb-hover, rgba(201,185,154,0.35));
    }
    .style-classic ::-webkit-scrollbar-corner {
        background: transparent;
    }
    ";
    
    return $css;
}

/**
 * 外部スタイルCSSファイルへのリンクタグを生成
 * ※ スタイルはgenerateDesignCSS内でインライン生成されるため、この関数は空文字を返す
 * @deprecated generateDesignCSS()がスタイルCSSを含むため不要
 */
function generateStyleLinks(string $styleId = '', string $themeId = ''): string {
    // スタイルはgenerateStyleCSS()でインライン生成されるため、外部ファイルは不要
    return '';
}

/**
 * Google Fontsの読み込みリンクを生成
 * @return string Google Fonts用のリンクタグ
 */
function generateFontLinks(): string {
    return '
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&family=Yomogi&family=Kosugi+Maru&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    ';
}

/**
 * JavaScriptでデザイン設定をLocalStorageに保存するスクリプトを生成
 * @param array $settings ユーザー設定
 * @return string 生成されたスクリプトタグ
 */
function generateDesignJS(array $settings): string {
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    return "
    <script>
        // デザイン設定をLocalStorageに保存（他ページとの同期用）
        (function() {
            const serverSettings = {$json};
            const localSettings = JSON.parse(localStorage.getItem('social9_design') || '{}');
            
            // サーバー設定を優先
            if (serverSettings.theme) {
                localStorage.setItem('social9_design', JSON.stringify(serverSettings));
            }
        })();
    </script>
    ";
}





