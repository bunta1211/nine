<?php
/**
 * Social9 デザイン設定画面
 * 左パネル: デザイン選択項目
 * 中央パネル: 会話プレビュー
 * 右パネル: 概要・ファイルプレビュー
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/asset_helper.php';
require_once __DIR__ . '/includes/design_config.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/lang.php';

requireLogin();

// 言語設定を初期化
$currentLang = getCurrentLanguage();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'ユーザー';

// トップバー用：ユーザー情報・所属組織
$user = [];
$userOrganizations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.name, o.type, om.role as relationship
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE om.user_id = ? AND om.left_at IS NULL
        ORDER BY CASE om.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, o.name
    ");
    $stmt->execute([$user_id]);
    $userOrganizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ユーザーの現在のデザイン設定を取得（共通設定から取得）
$design_settings = getDefaultDesignSettings();

try {
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        $design_settings = array_merge($design_settings, $settings);
    }
} catch (Exception $e) {
    // テーブルがない場合はデフォルト値を使用
}

// 標準デザイン（lavender）に統一
$design_settings['theme'] = 'lavender';
$design_settings['background_image'] = 'none';

// テーマ・スタイル・フォントを共通設定から取得
$themes = getThemesForUI();
$styles = getStylesForUI();
$fonts = getFontsForUI();

// 現在のスタイル設定を取得（旧IDは枠線スタイルに解決）
$current_style = $design_settings['ui_style'] ?? DESIGN_DEFAULT_STYLE;
$effective_style = function_exists('resolveStyleId') ? resolveStyleId($current_style) : $current_style;

// 現在のフォント設定を取得
$current_font = $design_settings['font_family'] ?? DESIGN_DEFAULT_FONT;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&family=Yomogi&family=Kosugi+Maru&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('design') ?> | Social9</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/layout/header.css?v=<?= assetVersion('assets/css/layout/header.css', __DIR__) ?>">
    <!-- 上パネル・メインコンテナの高さ・幅をトップ（チャット）と同一にする -->
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css?v=<?= assetVersion('assets/css/panel-panels-unified.css', __DIR__) ?>">
    <!-- 上パネルをトップ（チャット）と同じ立体デザインにする -->
    <?= generateDesignCSS($design_settings) ?>
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/pages-mobile.css?v=<?= assetVersion('assets/css/pages-mobile.css', __DIR__) ?>">
    <!-- スタイルCSS（外部ファイル・キャッシュ可能） -->
    <?= generateStyleLinks($current_style, $design_settings['theme']) ?>
    <style>
        :root {
            --header-height: 70px;
            --left-panel-width: 280px;
            --right-panel-width: 280px;
            --current-accent: <?= htmlspecialchars($design_settings['accent_color']) ?>;
        }
        
        .topbar-back-link { text-decoration: none; color: inherit; display: inline-flex; align-items: center; justify-content: center; }
        .design-page .top-panel .user-info .user-info-mobile-gear { display: none !important; }
        
        /* 右パネル収納時（トップページと同じ挙動） */
        .design-page .right-panel.collapsed {
            width: 0 !important;
            min-width: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
            flex: 0 0 0 !important;
        }
        .design-page .center-panel:has(+ .right-panel.collapsed) {
            flex: 1 1 auto;
        }
        
        /* 左パネル - 設定項目（デザイントークン使用） */
        .left-panel {
            width: var(--left-panel-width);
            background: var(--dt-left-bg, rgba(40,45,60,0.5));
            backdrop-filter: blur(12px);
            flex-shrink: 0;
            border-radius: 16px;
            overflow-y: auto;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            color: var(--dt-left-text, #ffffff);
        }
        
        /* 中央パネル - チャットプレビュー（デザイントークン使用） */
        .center-panel {
            flex: 1;
            background: var(--dt-center-bg, rgba(0,0,0,0.15));
            backdrop-filter: blur(12px);
            min-width: 0;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        
        /* 右パネル - 概要・ファイル（デザイントークン使用） */
        .right-panel {
            width: var(--right-panel-width);
            background: var(--dt-right-bg, rgba(40,45,60,0.5));
            backdrop-filter: blur(12px);
            flex-shrink: 0;
            border-radius: 16px;
            overflow-y: auto;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            color: var(--dt-right-text, #ffffff);
        }
        
        @media (max-width: 1200px) {
            .right-panel { display: none; }
        }
        @media (max-width: 768px) {
            /* モバイル: スクロール可能にする */
            html, body {
                height: auto !important;
                overflow: auto !important;
                overflow-x: hidden !important;
            }
            /* モバイル: 左パネルをメインコンテンツとして表示 */
            .main-container {
                display: block !important;
                margin-top: 56px !important;
                padding: 4px !important;
                height: auto !important;
                min-height: calc(100vh - 60px) !important;
                overflow-y: visible !important;
            }
            .left-panel {
                position: relative !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                border-radius: 12px !important;
                padding: 12px !important;
                margin-bottom: 8px !important;
                display: block !important;
                transform: none !important;
                box-shadow: none !important;
            }
            .center-panel {
                display: none !important; /* モバイルではプレビューを非表示 */
            }
            .right-panel {
                display: none !important;
            }
            /* おすすめデザインを3列に */
            .recommended-list {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 8px !important;
            }
            .recommended-preview {
                min-height: 60px !important;
            }
            .recommended-item {
                padding: 4px !important;
            }
            /* 設定セクションを少しコンパクトに */
            .setting-section {
                margin-bottom: 16px !important;
            }
            .setting-section-title {
                font-size: 12px !important;
                padding: 6px 10px !important;
                margin-bottom: 10px !important;
            }
            .theme-item, .style-item, .font-item {
                padding: 10px !important;
            }
            /* テーマリストを2列に */
            .theme-list {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px !important;
            }
        }
        @media (max-width: 900px) and (min-width: 769px) {
            .left-panel { 
                position: fixed;
                left: -300px;
                top: 64px;
                height: calc(100vh - 72px);
                z-index: 100;
                transition: left 0.3s;
            }
            .left-panel.open { left: 8px; }
        }
        
        /* 設定セクション */
        .setting-section {
            margin-bottom: 20px;
        }
        .setting-section-title {
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            backdrop-filter: blur(8px);
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        
        /* おすすめデザイン */
        .recommended-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .recommended-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 4px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(8px);
        }
        .recommended-item:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        .recommended-item.active {
            background: rgba(255,255,255,0.25);
            border-color: var(--current-accent, #22c55e);
            box-shadow: 0 4px 12px rgba(34,197,94,0.4);
            transform: scale(1.02);
        }
        .recommended-preview {
            width: 100%;
            aspect-ratio: 4/3;
            min-height: 70px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            background-size: cover !important;
            background-position: center !important;
        }
        
        /* 透明カスタムセクション */
        .transparent-custom-section {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .transparent-theme-btn {
            background: rgba(255,255,255,0.15) !important;
            backdrop-filter: blur(8px);
        }
        .custom-bg-upload {
            padding: 12px;
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            backdrop-filter: blur(8px);
        }
        .upload-placeholder {
            width: 100%;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eee;
            border: 2px dashed #ccc;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .upload-placeholder:hover {
            background: #e0e0e0;
            border-color: #999;
        }
        .upload-placeholder span {
            color: #666;
            font-size: 14px;
        }
        
        /* テーマカード */
        .theme-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .theme-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
        }
        .theme-item:hover {
            background: #f5f5f5;
        }
        .theme-item.active {
            background: #f0fdf4;
            border-color: var(--current-accent);
        }
        .theme-color-preview {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .theme-item-name {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        
        /* スタイル選択 */
        .style-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .style-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
        }
        .style-item:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        .style-item.active {
            background: rgba(255,255,255,0.3);
            border-color: var(--current-accent);
        }
        .style-icon {
            font-size: 24px;
            width: 36px;
            text-align: center;
        }
        .style-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .style-name {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        .style-desc {
            font-size: 11px;
            color: rgba(255,255,255,0.8);
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        .style-coming-soon {
            margin-top: 12px;
            padding: 10px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
        }
        
        /* フォント選択 */
        .font-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .font-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
        }
        .font-item:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        .font-item.active {
            background: rgba(255,255,255,0.3);
            border-color: var(--current-accent);
        }
        .font-preview {
            font-size: 18px;
            width: 50px;
            text-align: center;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        .font-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }
        .font-name {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        .font-desc {
            font-size: 11px;
            color: rgba(255,255,255,0.8);
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        
        /* 背景画像アップローダー */
        .background-uploader {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .background-preview {
            position: relative;
            width: 100%;
            height: 120px;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .background-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .background-preview .no-background {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.9);
            font-size: 14px;
        }
        .background-preview .no-background span:first-child {
            font-size: 32px;
        }
        .remove-bg-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(0,0,0,0.6);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .remove-bg-btn:hover {
            background: rgba(220,38,38,0.9);
        }
        .background-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .upload-btn {
            padding: 10px 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .upload-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }
        .upload-hint {
            font-size: 11px;
            color: #888;
            text-align: center;
        }
        
        /* サンプル背景選択 */
        .sample-backgrounds {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }
        .sample-bg-item {
            flex: 1;
            cursor: pointer;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            text-align: center;
        }
        .sample-bg-item:hover {
            border-color: rgba(100,150,255,0.5);
            transform: translateY(-2px);
        }
        .sample-bg-item.active {
            border-color: rgba(100,150,255,0.8);
            box-shadow: 0 4px 12px rgba(100,150,255,0.3);
        }
        .sample-bg-preview {
            height: 60px;
            border-radius: 10px 10px 0 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sample-bg-preview.custom-upload {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-size: 24px;
        }
        .sample-bg-name {
            display: block;
            padding: 6px 4px;
            font-size: 11px;
            font-weight: 500;
            background: rgba(0,0,0,0.03);
        }
        
        /* カラーピッカー */
        .color-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .color-input-wrapper {
            position: relative;
        }
        .color-input {
            width: 40px;
            height: 40px;
            border: 2px solid #ddd;
            border-radius: 10px;
            cursor: pointer;
            padding: 0;
        }
        .color-presets {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .color-preset {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: transform 0.2s;
        }
        .color-preset:hover {
            transform: scale(1.15);
        }
        .color-preset.active {
            border-color: #333;
        }
        
        /* トグル */
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
        }
        .setting-label {
            font-size: 14px;
        }
        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
        }
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #ccc;
            border-radius: 24px;
            transition: 0.3s;
        }
        .toggle-slider:before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .toggle input:checked + .toggle-slider {
            background: var(--current-accent);
        }
        .toggle input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        
        /* フォントサイズ */
        .font-size-btns {
            display: flex;
            gap: 4px;
        }
        .font-size-btn {
            padding: 6px 14px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .font-size-btn:first-child { border-radius: 6px 0 0 6px; }
        .font-size-btn:last-child { border-radius: 0 6px 6px 0; }
        .font-size-btn.active {
            background: var(--current-accent);
            color: white;
            border-color: var(--current-accent);
        }
        
        /* プレビュー：チャットエリア（デザイントークン使用） */
        .preview-header {
            padding: 12px 16px;
            background: var(--dt-center-header-bg, var(--dt-header-bg, rgba(50,55,70,0.55)));
            border-bottom: 1px solid var(--dt-divider, rgba(255,255,255,0.15));
            font-size: 13px;
            color: var(--dt-center-header-text, var(--dt-header-text, #ffffff));
        }
        .preview-chat {
            flex: 1;
            background: var(--dt-center-bg, transparent);
            padding: 16px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        /* プレビューメッセージ（デザイントークン使用） */
        .preview-message {
            max-width: 75%;
            padding: 10px 14px;
            border-radius: var(--ui-card-radius, 16px);
            font-size: 14px;
            line-height: 1.5;
            background: var(--dt-msg-other-bg, rgba(80,90,110,0.7));
            color: var(--dt-msg-other-text, #ffffff);
            border: 1px solid var(--dt-msg-other-border, transparent);
        }
        .preview-message.other {
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }
        .preview-message.self {
            background: var(--dt-msg-self-bg, rgba(80,90,110,0.7));
            color: var(--dt-msg-self-text, #ffffff);
            border: 1px solid var(--dt-msg-self-border, transparent);
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }
        .preview-message .name {
            font-size: 11px;
            opacity: 0.8;
            margin-bottom: 4px;
            color: inherit;
        }
        .preview-message .time {
            font-size: 10px;
            opacity: 0.7;
            margin-top: 4px;
            text-align: right;
            color: var(--dt-msg-other-time, inherit);
        }
        .preview-message.self .time {
            color: var(--dt-msg-self-time, inherit);
        }
        /* チャット入力欄プレビュー（本番と同じ構造） */
        .preview-input-area {
            background: var(--dt-center-input-bg, rgba(40,45,60,0.5));
            border-top: 1px solid var(--dt-divider, rgba(255,255,255,0.15));
            border-radius: 0 0 var(--ui-card-radius, 16px) var(--ui-card-radius, 16px);
        }
        .preview-input-area .input-container {
            display: flex;
            flex-direction: column;
        }
        .preview-input-area .input-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 16px;
            background: transparent;
            border: none;
        }
        .preview-input-area .input-toolbar-left,
        .preview-input-area .input-toolbar-right {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .preview-input-area .input-toolbar-left .toolbar-btn,
        .preview-input-area .toolbar-btn {
            width: 36px;
            min-width: 36px;
            height: 36px;
            min-height: 36px;
            border: none;
            border-radius: 50%;
            padding: 0;
            background: var(--dt-btn-primary-bg, var(--current-accent));
            color: var(--dt-btn-primary-text, white);
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: default;
            opacity: 0.9;
        }
        .preview-input-area .toolbar-btn .btn-icon {
            font-size: 14px;
        }
        .preview-input-area .input-toolbar-left .toolbar-btn.to-btn {
            background: rgba(255,255,255,0.2);
            color: var(--dt-input-text, #ffffff);
        }
        .preview-input-area .enter-send-label {
            font-size: 12px;
            color: var(--dt-input-text, #ffffff);
            opacity: 0.9;
            cursor: default;
        }
        .preview-input-area .enter-send-label input {
            margin-right: 4px;
        }
        .preview-input-area .input-toolbar-right .toolbar-toggle-btn,
        .preview-input-area .input-toolbar-right .input-send-btn {
            width: 36px;
            min-width: 36px;
            height: 36px;
            min-height: 36px;
            border-radius: 50%;
        }
        .preview-input-area .toolbar-toggle-btn {
            width: 36px;
            height: 28px;
            border: none;
            background: rgba(255,255,255,0.15);
            color: var(--dt-input-text, #ffffff);
            border-radius: var(--ui-btn-radius, 8px);
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: default;
        }
        .preview-input-area .input-row {
            display: flex;
            align-items: stretch;
            padding: 8px 16px 16px;
            gap: 8px;
        }
        .preview-input-area .input-wrapper {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: flex-end;
        }
        .preview-input-area .input-row textarea,
        .preview-input-area .message-input {
            flex: 1;
            width: 100%;
            padding: 10px 16px;
            border: 1px solid var(--dt-input-border, rgba(255,255,255,0.25));
            border-radius: var(--ui-input-radius, 20px);
            font-size: 14px;
            line-height: 1.5;
            min-height: 52px;
            max-height: 300px;
            background: var(--dt-input-bg, rgba(255,255,255,0.15));
            color: var(--dt-input-text, #ffffff);
            resize: none;
            font-family: inherit;
            outline: none;
        }
        .preview-input-area .input-row textarea::placeholder,
        .preview-input-area .message-input::placeholder {
            color: var(--dt-input-placeholder, rgba(255,255,255,0.6));
        }
        .preview-input-area .input-toolbar-right .input-send-btn:hover {
            filter: brightness(1.1);
        }
        
        /* 枠線スタイル別プレビュー（design_loader の .style-cute 等 !important より確実に効くよう !important で統一） */
        .center-panel.style-frame_square .preview-message,
        .center-panel.style-frame_square .preview-message.other,
        .center-panel.style-frame_square .preview-message.self {
            border-radius: 0 !important;
        }
        .center-panel.style-frame_square .preview-message.other { border-bottom-left-radius: 0 !important; }
        .center-panel.style-frame_square .preview-message.self { border-bottom-right-radius: 0 !important; }
        .center-panel.style-frame_square .preview-input-area {
            border-radius: 0 !important;
        }
        .center-panel.style-frame_square .preview-input-area .toolbar-btn,
        .center-panel.style-frame_square .preview-input-area .toolbar-toggle-btn,
        .center-panel.style-frame_square .preview-input-area .input-toolbar-right .input-send-btn {
            border-radius: 0 !important;
        }
        .center-panel.style-frame_square .preview-input-area .input-row textarea,
        .center-panel.style-frame_square .preview-input-area .message-input {
            border-radius: 0 !important;
        }
        
        .center-panel.style-frame_round1 .preview-message,
        .center-panel.style-frame_round1 .preview-message.other,
        .center-panel.style-frame_round1 .preview-message.self {
            border-radius: 8px !important;
        }
        .center-panel.style-frame_round1 .preview-message.other { border-bottom-left-radius: 4px !important; }
        .center-panel.style-frame_round1 .preview-message.self { border-bottom-right-radius: 4px !important; }
        .center-panel.style-frame_round1 .preview-input-area {
            border-radius: 0 0 8px 8px !important;
        }
        .center-panel.style-frame_round1 .preview-input-area .toolbar-btn,
        .center-panel.style-frame_round1 .preview-input-area .toolbar-toggle-btn,
        .center-panel.style-frame_round1 .preview-input-area .input-toolbar-right .input-send-btn {
            border-radius: 8px !important;
        }
        .center-panel.style-frame_round1 .preview-input-area .input-row textarea,
        .center-panel.style-frame_round1 .preview-input-area .message-input {
            border-radius: 8px !important;
        }
        
        .center-panel.style-frame_round2 .preview-message,
        .center-panel.style-frame_round2 .preview-message.other,
        .center-panel.style-frame_round2 .preview-message.self {
            border-radius: 16px !important;
        }
        .center-panel.style-frame_round2 .preview-message.other { border-bottom-left-radius: 4px !important; }
        .center-panel.style-frame_round2 .preview-message.self { border-bottom-right-radius: 4px !important; }
        .center-panel.style-frame_round2 .preview-input-area {
            border-radius: 0 0 16px 16px !important;
        }
        .center-panel.style-frame_round2 .preview-input-area .toolbar-btn,
        .center-panel.style-frame_round2 .preview-input-area .toolbar-toggle-btn,
        .center-panel.style-frame_round2 .preview-input-area .input-toolbar-right .input-send-btn {
            border-radius: 16px !important;
        }
        .center-panel.style-frame_round2 .preview-input-area .input-row textarea,
        .center-panel.style-frame_round2 .preview-input-area .message-input {
            border-radius: 16px !important;
        }
        
        /* 右パネル：概要・ファイル（デザイントークン使用） */
        .info-section {
            margin-bottom: 20px;
        }
        .info-section-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--dt-text-muted, rgba(255,255,255,0.8));
            margin-bottom: 12px;
        }
        .room-info {
            background: var(--dt-right-section-bg, rgba(50,55,70,0.45));
            border-radius: 12px;
            padding: 16px;
        }
        .room-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dt-right-text, #ffffff);
        }
        .room-members {
            font-size: 13px;
            color: var(--dt-text-muted, rgba(255,255,255,0.8));
            margin-bottom: 12px;
        }
        .member-avatars {
            display: flex;
            gap: -8px;
        }
        .member-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: 2px solid var(--dt-card-border, rgba(255,255,255,0.2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
            margin-left: -8px;
        }
        .member-avatar:first-child { margin-left: 0; }
        
        .file-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: var(--dt-right-section-bg, rgba(50,55,70,0.45));
            border-radius: 10px;
        }
        .file-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--dt-card-bg, rgba(50,55,70,0.5));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .file-info {
            flex: 1;
            min-width: 0;
        }
        .file-name {
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--dt-right-text, #ffffff);
        }
        .file-meta {
            font-size: 11px;
            color: var(--dt-text-muted, rgba(255,255,255,0.7));
        }
        
        /* リセットボタン（デザイントークン使用） */
        .reset-btn {
            width: 100%;
            padding: 10px;
            background: var(--dt-btn-secondary-bg, rgba(255,255,255,0.15));
            border: 1px solid var(--dt-btn-secondary-border, rgba(255,255,255,0.2));
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            color: var(--dt-btn-secondary-text, #ffffff);
            transition: background 0.2s;
        }
        .reset-btn:hover {
            background: var(--dt-btn-secondary-bg, rgba(255,255,255,0.25));
            filter: brightness(1.1);
        }
        
        /* プレビュー用スクロールバー（目立たない配色） */
        .preview-chat::-webkit-scrollbar,
        .left-panel::-webkit-scrollbar,
        .right-panel::-webkit-scrollbar,
        .center-panel::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .preview-chat::-webkit-scrollbar-track,
        .left-panel::-webkit-scrollbar-track,
        .right-panel::-webkit-scrollbar-track,
        .center-panel::-webkit-scrollbar-track {
            background: transparent;
        }
        .preview-chat::-webkit-scrollbar-thumb,
        .left-panel::-webkit-scrollbar-thumb,
        .right-panel::-webkit-scrollbar-thumb,
        .center-panel::-webkit-scrollbar-thumb {
            background: var(--dt-scroll-thumb, rgba(100,116,139,0.15));
            border-radius: 10px;
        }
        .preview-chat::-webkit-scrollbar-thumb:hover,
        .left-panel::-webkit-scrollbar-thumb:hover,
        .right-panel::-webkit-scrollbar-thumb:hover,
        .center-panel::-webkit-scrollbar-thumb:hover {
            background: var(--dt-scroll-thumb-hover, rgba(100,116,139,0.25));
        }
        
        /* ダークモード */
        body.dark-mode {
            background: #1a1a2e !important;
        }
        .left-panel.dark-mode,
        .center-panel.dark-mode,
        .right-panel.dark-mode {
            background: #16213e;
            color: #e4e4e7;
        }
        .dark-mode .setting-section-title {
            color: #a1a1aa;
        }
        .dark-mode .theme-item {
            background: #1a1a2e;
        }
        .dark-mode .theme-item:hover {
            background: #252550;
        }
        .dark-mode .theme-item.active {
            background: #252550;
        }
        .dark-mode .theme-item-name {
            color: #e4e4e7;
        }
        .dark-mode .preview-header {
            background: var(--dt-header-bg, #1a1a2e);
            color: var(--dt-header-text, #a1a1aa);
            border-color: var(--dt-divider, #333);
        }
        .dark-mode .preview-chat {
            background: var(--dt-center-bg, #16213e);
        }
        .dark-mode .preview-message.other {
            background: var(--dt-msg-other-bg, #252550);
            color: var(--dt-msg-other-text, #e4e4e7);
        }
        .dark-mode .preview-message.self {
            background: var(--dt-msg-self-bg, var(--current-accent));
            color: var(--dt-msg-self-text, white);
        }
        .dark-mode .preview-input-area {
            background: var(--dt-center-input-bg, #1a1a2e);
            border-color: var(--dt-divider, #333);
            border-radius: 16px;
        }
        .dark-mode .preview-input {
            background: var(--dt-input-bg, #252550);
            border-color: var(--dt-input-border, #444);
            color: var(--dt-input-text, #e4e4e7);
            min-height: 56px;
        }
        .dark-mode .room-info {
            background: #1a1a2e;
        }
        .dark-mode .room-name {
            color: #e4e4e7;
        }
        .dark-mode .file-item {
            background: #1a1a2e;
        }
        .dark-mode .file-name {
            color: #e4e4e7;
        }
        .dark-mode .info-section-title {
            color: #a1a1aa;
        }
        .dark-mode .setting-label {
            color: #e4e4e7;
        }
        .dark-mode .font-size-btn {
            background: #252550;
            border-color: #444;
            color: #e4e4e7;
        }
        .dark-mode .font-size-btn:hover {
            background: #333366;
        }
        .dark-mode .reset-btn {
            background: #252550;
            color: #a1a1aa;
        }
        .dark-mode .reset-btn:hover {
            background: #333366;
        }
        .dark-mode .color-input {
            border-color: #444;
        }
    </style>
</head>
<body class="design-page style-<?= htmlspecialchars($effective_style) ?>" data-theme="<?= htmlspecialchars($design_settings['theme']) ?>" data-style="<?= htmlspecialchars($effective_style) ?>">
    <?php
    $topbar_back_url = 'chat.php';
    $topbar_header_id = 'topPanel';
    include __DIR__ . '/includes/chat/topbar.php';
    ?>
    
    <!-- メインコンテナ -->
    <div class="main-container">
        <!-- 左パネル: デザイン選択 -->
        <aside class="left-panel" id="leftPanel">
            
            <!-- テーマ選択（基本の色＝標準デザイン固定） -->
            <div class="setting-section">
                <div class="setting-section-title">🎨 基本の色</div>
                <div class="theme-list">
                    <?php 
                    $themeTranslations = [
                        'default' => 'theme_forest',
                        'ocean' => 'theme_ocean',
                        'sunset' => 'theme_sunset',
                        'cherry' => 'theme_cherry',
                        'lavender' => null, // スカイグレイ：設定の name をそのまま表示
                        'midnight' => 'theme_midnight',
                    ];
                    foreach ($themes as $theme): 
                        if ($theme['id'] === 'transparent') continue;
                        $themeName = (isset($themeTranslations[$theme['id']]) && $themeTranslations[$theme['id']] !== null) ? __($themeTranslations[$theme['id']]) : $theme['name'];
                    ?>
                    <div class="theme-item <?= ($design_settings['theme'] === $theme['id']) ? 'active' : '' ?>" 
                         data-theme="<?= $theme['id'] ?>"
                         data-header="<?= htmlspecialchars($theme['headerGradient']) ?>"
                         data-bg="<?= $theme['bgColor'] ?>"
                         data-accent="<?= $theme['accent'] ?>"
                         data-panel-bg="<?= htmlspecialchars($theme['panelBg']) ?>"
                         data-text-color="<?= $theme['textColor'] ?>"
                         data-text-muted="<?= $theme['textMuted'] ?>"
                         data-card-bg="<?= htmlspecialchars($theme['cardBg']) ?>"
                         data-other-msg-bg="<?= htmlspecialchars($theme['otherMsgBg']) ?>"
                         data-self-msg-bg="<?= htmlspecialchars($theme['selfMsgBg'] ?? '') ?>"
                         data-is-transparent="<?= !empty($theme['isTransparent']) ? '1' : '0' ?>"
                         onclick="selectTheme(this)">
                        <div class="theme-color-preview" style="background: <?= $theme['headerGradient'] ?>;"></div>
                        <span class="theme-item-name"><?= $themeName ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- スタイル選択（枠線：直角・丸み1・丸み2） -->
            <div class="setting-section">
                <div class="setting-section-title">🎭 枠線</div>
                <div class="style-list">
                    <?php foreach ($styles as $style): ?>
                    <div class="style-item <?= $effective_style === $style['id'] ? 'active' : '' ?>" 
                         data-style="<?= $style['id'] ?>"
                         onclick="selectStyle(this)">
                        <span class="style-icon"><?= $style['icon'] ?></span>
                        <div class="style-info">
                            <span class="style-name"><?= $style['name'] ?></span>
                            <span class="style-desc"><?= $style['description'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- フォント選択 -->
            <div class="setting-section">
                <div class="setting-section-title">🔤 フォント</div>
                <div class="font-list">
                    <?php foreach ($fonts as $font): ?>
                    <div class="font-item <?= $current_font === $font['id'] ? 'active' : '' ?>" 
                         data-font="<?= $font['id'] ?>"
                         data-family="<?= htmlspecialchars($font['family']) ?>"
                         onclick="selectFont(this)">
                        <span class="font-preview" style="font-family: <?= $font['family'] ?>;">あA</span>
                        <div class="font-info">
                            <span class="font-name"><?= $font['name'] ?></span>
                            <span class="font-desc"><?= $font['description'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 文字サイズ -->
            <div class="setting-section">
                <div class="setting-section-title">📝 文字サイズ</div>
                <div class="font-size-btns">
                    <button class="font-size-btn <?= $design_settings['font_size'] === 'compact' ? 'active' : '' ?>" onclick="setFontSize('compact', this)">90%</button>
                    <button class="font-size-btn <?= $design_settings['font_size'] === 'small' ? 'active' : '' ?>" onclick="setFontSize('small', this)">小</button>
                    <button class="font-size-btn <?= $design_settings['font_size'] === 'medium' ? 'active' : '' ?>" onclick="setFontSize('medium', this)">中</button>
                    <button class="font-size-btn <?= $design_settings['font_size'] === 'large' ? 'active' : '' ?>" onclick="setFontSize('large', this)">大</button>
                </div>
            </div>
            
            <!-- リセット -->
            <div class="setting-section">
                <button class="reset-btn" onclick="resetToDefault()">🔄 初期設定に戻す</button>
            </div>
            
        </aside>
        
        <!-- 中央パネル: 会話プレビュー（枠線スタイルで .style-frame_* を付与してプレビューに即反映） -->
        <main class="center-panel style-<?= htmlspecialchars($effective_style) ?>">
            <div class="preview-header">💬 チャットプレビュー</div>
            <div class="preview-chat" id="previewChat">
                <div class="preview-message other">
                    <div class="name">田中さん</div>
                    おはようございます！今日のミーティングは予定通りですか？
                    <div class="time">9:00</div>
                </div>
                <div class="preview-message self">
                    おはようございます！はい、10時から開始予定です。資料の準備もできています👍
                    <div class="time">9:02</div>
                </div>
                <div class="preview-message other">
                    <div class="name">田中さん</div>
                    了解です！楽しみにしています。会議室Aでよろしいでしょうか？
                    <div class="time">9:05</div>
                </div>
                <div class="preview-message self">
                    はい、会議室Aで大丈夫です。プロジェクターも用意しておきますね📽️
                    <div class="time">9:07</div>
                </div>
                <div class="preview-message other">
                    <div class="name">鈴木さん</div>
                    私も参加します！新しい提案があるので共有させてください
                    <div class="time">9:10</div>
                </div>
                <div class="preview-message self">
                    もちろんです！楽しみにしています✨
                    <div class="time">9:12</div>
                </div>
            </div>
            <!-- チャット入力欄プレビュー（本番と同じ構造: ツールバー + 入力行 + 送信ボタン） -->
            <div class="preview-input-area input-area" id="previewInputArea">
                <div class="input-container">
                    <div class="input-toolbar">
                        <div class="input-toolbar-left">
                            <button type="button" class="toolbar-btn to-btn" disabled aria-hidden="true">To</button>
                            <button type="button" class="toolbar-btn gif-btn" disabled aria-hidden="true">GIF</button>
                            <button type="button" class="toolbar-btn call-toolbar-btn" disabled aria-hidden="true"><span class="btn-icon">☎</span></button>
                            <button type="button" class="toolbar-btn attach-btn" disabled aria-hidden="true"><span class="btn-icon">⊕</span></button>
                        </div>
                        <div class="input-toolbar-right">
                            <label class="enter-send-label"><input type="checkbox" disabled> Enterで送信</label>
                            <button type="button" class="toolbar-toggle-btn" disabled aria-hidden="true" title="入力欄を非表示">☰</button>
                        </div>
                    </div>
                    <div class="input-row">
                        <div class="input-wrapper">
                            <textarea id="previewMessageInput" class="message-input preview-input" placeholder="ここにメッセージ内容を入力&#10;(Shift + Enterキーで送信)" disabled rows="1" style="min-height:52px;max-height:300px;height:52px"></textarea>
                        </div>
                        <button type="button" id="previewSendBtn" class="input-send-btn theme-action-btn" disabled aria-hidden="true" title="送信">➤</button>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- 右パネル: 概要・ファイル（右収納ボタンで表示/非表示） -->
        <aside class="right-panel" id="rightPanel">
            
            <!-- ルーム情報 -->
            <div class="info-section">
                <div class="info-section-title">📌 ルーム概要</div>
                <div class="room-info">
                    <div class="room-name">プロジェクトチーム</div>
                    <div class="room-members">メンバー 5人</div>
                    <div class="member-avatars">
                        <div class="member-avatar" style="background: linear-gradient(135deg, #667eea, #764ba2);">田</div>
                        <div class="member-avatar" style="background: linear-gradient(135deg, #f093fb, #f5576c);">鈴</div>
                        <div class="member-avatar" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">佐</div>
                        <div class="member-avatar" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">山</div>
                        <div class="member-avatar" style="background: linear-gradient(135deg, #fa709a, #fee140);">+1</div>
                    </div>
                </div>
            </div>
            
            <!-- 共有ファイル -->
            <div class="info-section">
                <div class="info-section-title">📎 共有ファイル</div>
                <div class="file-list">
                    <div class="file-item">
                        <div class="file-icon">📄</div>
                        <div class="file-info">
                            <div class="file-name">企画書_v2.pdf</div>
                            <div class="file-meta">2.4 MB · 12/20</div>
                        </div>
                    </div>
                    <div class="file-item">
                        <div class="file-icon">🖼️</div>
                        <div class="file-info">
                            <div class="file-name">デザイン案.png</div>
                            <div class="file-meta">1.8 MB · 12/18</div>
                        </div>
                    </div>
                    <div class="file-item">
                        <div class="file-icon">📊</div>
                        <div class="file-info">
                            <div class="file-name">売上データ.xlsx</div>
                            <div class="file-meta">856 KB · 12/15</div>
                        </div>
                    </div>
                </div>
            </div>
            
        </aside>
    </div>
    
    <script src="assets/js/topbar-standalone.js"></script>
    <script>
        (function() {
            function designPageToggleRightPanel() {
                var rightPanel = document.getElementById('rightPanel');
                if (!rightPanel) return;
                rightPanel.classList.toggle('collapsed');
                try { localStorage.setItem('designRightPanelCollapsed', rightPanel.classList.contains('collapsed')); } catch (e) {}
            }
            window.toggleRightPanel = designPageToggleRightPanel;
            function initDesignTopbar() {
                var rightPanel = document.getElementById('rightPanel');
                if (rightPanel) {
                    try {
                        if (localStorage.getItem('designRightPanelCollapsed') === 'true') rightPanel.classList.add('collapsed');
                    } catch (e) {}
                }
                var btn = document.getElementById('toggleRightBtn');
                if (btn) {
                    btn.removeAttribute('onclick');
                    btn.addEventListener('click', function(e) { e.preventDefault(); designPageToggleRightPanel(); }, false);
                }
            }
            document.addEventListener('DOMContentLoaded', initDesignTopbar);
        })();
    </script>
    <!-- PHPからの設定データ注入 -->
    <script>
        // 設定状態（PHPから注入）
        const settings = {
            theme: '<?= htmlspecialchars($design_settings['theme']) ?>',
            dark_mode: <?= $design_settings['dark_mode'] ? 'true' : 'false' ?>,
            accent_color: '<?= htmlspecialchars($design_settings['accent_color']) ?>',
            font_size: '<?= htmlspecialchars($design_settings['font_size']) ?>',
            ui_style: '<?= htmlspecialchars($effective_style) ?>',
            font_family: '<?= htmlspecialchars($current_font) ?>',
            background_size: '<?= htmlspecialchars($design_settings['background_size'] ?? '80') ?>',
            background_image: '<?= htmlspecialchars($design_settings['background_image'] ?? 'none') ?>'
        };
        
        // テーマデータ（PHPから注入）
        const themesData = <?= json_encode($themes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        
        // スタイルデータ（PHPから注入）
        const stylesData = <?= json_encode($styles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        
        // フォントデータ（PHPから注入）
        const fontsData = <?= json_encode($fonts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        
        // 透明スタイル設定（PHPから注入）
        const transparentStyles = <?= json_encode(getTransparentStyles(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        
        // ファイルサイズ制限（PHPから注入）
        const maxSizeMB = <?= DESIGN_MAX_BACKGROUND_SIZE_MB ?>;
        
        // APIベースURL（サブディレクトリ対応: /nine 等）
        const API_BASE = '<?= rtrim((string)(parse_url(defined("APP_URL") ? APP_URL : "http://localhost/", PHP_URL_PATH) ?? ""), "/") ?>';
    </script>
    
    <!-- 外部JavaScriptファイル -->
    <script src="assets/js/design-settings.js?v=<?= assetVersion('assets/js/design-settings.js') ?>"></script>
</body>
</html>






