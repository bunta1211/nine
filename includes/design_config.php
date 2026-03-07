<?php
/**
 * デザイン設定の一元管理
 * テーマ、スタイル、フォントの設定を集約。
 *
 * 規格・色の一覧: DOCS/STANDARD_DESIGN_SPEC.md を参照。
 * 標準デザインは theme id = lavender のみ（DESIGN_DEFAULT_THEME）。
 */

// ============================================
// 定数定義
// ============================================

// ファイルアップロード制限
const DESIGN_MAX_BACKGROUND_SIZE_MB = 10;
const DESIGN_BG_SIZE_MIN = 20;
const DESIGN_BG_SIZE_MAX = 200;

// デフォルト値
const DESIGN_DEFAULT_THEME = 'lavender';
const DESIGN_DEFAULT_STYLE = 'frame_round1'; // 枠線・丸み1
const DESIGN_DEFAULT_FONT = 'default';
const DESIGN_DEFAULT_ACCENT = '#6b7280';
// 初回入室者向け: 90%表示サイズ（compact）をデフォルトに
const DESIGN_DEFAULT_FONT_SIZE = 'compact';
const DESIGN_DEFAULT_BACKGROUND_SIZE = '80';

// 許可されるファイルタイプ
const DESIGN_ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const DESIGN_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// キャッシュバージョン（CSSやJS更新時に変更）
const DESIGN_ASSET_VERSION = '2.0.0';

// ============================================
// デザイントークンシステム
// ============================================

/**
 * デザイントークンのデフォルト値を取得
 * すべてのデザインの基本となる設定
 */
function getDefaultDesignTokens(): array {
    return [
        // ============================================
        // グローバル設定
        // ============================================
        // 白〜グレー基調、ボタン類はグレーで統一（DOCS/STANDARD_DESIGN_SPEC.md）
        'global' => [
            'accent' => '#6b7280',           // グレー（ボタン・アクセント統一）
            'accentHover' => '#4b5563',       // グレーホバー
            'textPrimary' => '#1a1a1a',      // メインテキスト（高コントラスト）
            'textMuted' => '#616161',        // 補助テキスト
            'textLight' => '#757575',        // プレースホルダー・hint
            'borderRadius' => '8px',
            'shadow' => '0 2px 8px rgba(0,0,0,0.06)',
        ],
        
        'panels' => [
            'header' => [
                'bg' => '#ffffff',
                'text' => '#1a1a1a',
                'border' => '#e0e0e0',
                // 上パネル立体・メタリック用（参考デザインB）
                'innerBgGradient' => 'linear-gradient(135deg, #f2f4f6 0%, #eceff3 50%, #e8eaef 100%)',
                'recessBg' => '#e8f0f8',
                'logoBgGradient' => 'linear-gradient(90deg, #d4dce8, #d8e4f0, #d4dce8)',
                'logoText' => '#345678',
                'convexBtnBg' => '#f0f2f5',
                'bezelBorder' => '#e0e0e0',
            ],
            'left' => [
                'bg' => '#fafafa',
                'text' => '#1a1a1a',
                'textMuted' => '#616161',
                'hover' => 'rgba(0,0,0,0.04)',
                'active' => 'rgba(0,0,0,0.06)',
            ],
            'center' => [
                'bg' => '#f5f5f5',
                'headerBg' => '#ffffff',
                'headerText' => '#1a1a1a',
                'inputAreaBg' => '#ffffff',
            ],
            'right' => [
                'bg' => '#fafafa',
                'text' => '#1a1a1a',
                'sectionBg' => '#ffffff',
                'sectionHeaderBg' => '#fafafa',
            ],
        ],
        
        'buttons' => [
            'primary' => [
                'bg' => '#6b7280',
                'text' => '#ffffff',
                'hover' => '#4b5563',
                'border' => 'transparent',
            ],
            'secondary' => [
                'bg' => '#ffffff',
                'text' => '#1a1a1a',
                'hover' => '#ffedd5',
                'border' => '#9ca3af',
                'borderWidth' => '2px',
            ],
            'icon' => [
                'color' => '#616161',
                'hover' => '#4b5563',
                'bg' => 'transparent',
                'hoverBg' => 'rgba(0,0,0,0.06)',
            ],
            'filter' => [
                'bg' => '#ffffff',
                'text' => '#424242',
                'hover' => '#ffedd5',
                'activeBg' => '#6b7280',
                'activeText' => '#ffffff',
            ],
        ],
        
        'inputs' => [
            'bg' => '#ffffff',
            'text' => '#1a1a1a',
            'placeholder' => '#757575',
            'border' => '#9ca3af',
            'focusBorder' => '#6b7280',
        ],
        
        'messages' => [
            'self' => [
                'bg' => '#eeeeee',
                'text' => '#1a1a1a',
                'border' => '#e0e0e0',
            ],
            'other' => [
                'bg' => '#ffffff',
                'text' => '#1a1a1a',
                'border' => '#e0e0e0',
            ],
            'mention' => [
                'bg' => '#fafafa',
                'text' => '#1a1a1a',
                'border' => '#6b7280',
            ],
        ],
        
        'cards' => [
            'bg' => '#ffffff',
            'border' => '#9ca3af',
            'shadow' => '0 2px 8px rgba(0,0,0,0.06)',
        ],
        
        'misc' => [
            'divider' => '#e0e0e0',
            'scrollThumb' => '#bdbdbd',
            'scrollThumbHover' => '#9e9e9e',
            'modalOverlay' => 'rgba(0,0,0,0.45)',
        ],
        
        // パターン設定（背景画像用）
        'pattern' => [
            'isPattern' => false,
            'patternSize' => '256px',
        ],
    ];
}

/**
 * デザイントークンを深くマージする
 */
function mergeDesignTokens(array $defaults, array $overrides): array {
    $result = $defaults;
    
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
            $result[$key] = mergeDesignTokens($result[$key], $value);
        } else {
            $result[$key] = $value;
        }
    }
    
    return $result;
}

/**
 * 背景画像用のデザイントークンを取得
 * 旧形式との互換性を維持しつつ、新形式に変換
 */
function getDesignTokensForBackground(string $backgroundImage): array {
    $defaults = getDefaultDesignTokens();
    $overrides = getBackgroundDesignOverrides($backgroundImage);
    
    if ($overrides === null) {
        return $defaults;
    }
    
    return mergeDesignTokens($defaults, $overrides);
}

/**
 * 背景画像用のデザインオーバーライド（廃止済み）
 */
function getBackgroundDesignOverrides(string $backgroundImage): ?array {
    // 背景画像別のデザインオーバーライドは廃止（標準デザインのみ）
    return null;
}

// ============================================
// テーマ設定（カラー）
// ============================================
function getThemeConfigs(): array {
    return [
        // 標準デザイン - 白〜グレー基調、ボタン類はグレーで統一（DOCS/STANDARD_DESIGN_SPEC.md）
        'lavender' => [
            'id' => 'lavender',
            'name' => '標準デザイン',
            'headerGradient' => 'linear-gradient(180deg, #e6e8ec, #dcdee2)',
            'headerText' => '#1a1a1a',
            'bgColor' => '#f5f5f5',
            'accent' => '#6b7280',
            'accentHover' => '#4b5563',
            'panelBg' => '#fafafa',
            'panelBgCenter' => '#f5f5f5',
            'cardBg' => '#ffffff',
            'cardBorder' => '#e0e0e0',
            'cardShadow' => '0 2px 8px rgba(0,0,0,0.06)',
            'textColor' => '#1a1a1a',
            'textMuted' => '#616161',
            'textLight' => '#757575',
            'inputBg' => '#ffffff',
            'inputBorder' => '#e0e0e0',
            'inputFocus' => '#6b7280',
            'selfMsgBg' => '#eeeeee',
            'selfMsgText' => '#1a1a1a',
            'otherMsgBg' => '#ffffff',
            'otherMsgText' => '#1a1a1a',
            'otherMsgBorder' => '#e0e0e0',
            'mentionMsgBg' => '#fafafa',
            'mentionMsgText' => '#1a1a1a',
            'mentionMsgBorder' => '#6b7280',
            'scrollTrack' => '#f5f5f5',
            'scrollThumb' => '#bdbdbd',
            'scrollThumbHover' => '#9e9e9e',
            'divider' => '#e0e0e0',
            'hoverBg' => 'rgba(0,0,0,0.04)',
            'filterBtnBg' => '#f5f5f5',
            'filterBtnText' => '#424242',
            'filterBtnBorder' => '#e0e0e0',
            'filterBtnActiveBg' => '#6b7280',
            'filterBtnActiveText' => '#ffffff',
            'rightPanelBgDark' => '#424242',
            'isTransparent' => false,
        ],
    ];
}

/**
 * 背景画像ごとの専用テーマ設定を取得
 * 背景画像別のテーマオーバーライドは廃止（標準デザインのみ）
 */
function getBackgroundThemeOverrides(string $backgroundImage): ?array {
    return null;
}

/**
 * design.php用のテーマ配列を取得（UI表示用）
 * @return array<int, array<string, mixed>> テーマ配列
 */
function getThemesForUI(): array {
    $themes = getThemeConfigs();
    $result = [];
    
    foreach ($themes as $theme) {
        $panelBg = $theme['panelBg'] ?? 'rgba(255,255,255,0.95)';
        $panelBgCenter = $theme['panelBgCenter'] ?? $panelBg;
        $rightPanelBg = $theme['rightPanelBg'] ?? $panelBg;
        $inputBg = $theme['inputBg'] ?? '#ffffff';
        $inputBorder = $theme['inputBorder'] ?? 'rgba(0,0,0,0.15)';
        $textColor = $theme['textColor'] ?? '#333';
        $textMuted = $theme['textMuted'] ?? '#666';
        $result[] = [
            'id' => $theme['id'],
            'name' => $theme['name'],
            'headerGradient' => $theme['headerGradient'],
            'bgColor' => $theme['bgColor'],
            'accent' => $theme['accent'],
            'panelBg' => $panelBg,
            'textColor' => $textColor,
            'textMuted' => $textMuted,
            'cardBg' => $theme['cardBg'],
            'otherMsgBg' => $theme['otherMsgBg'],
            'selfMsgBg' => $theme['selfMsgBg'] ?? '',
            'isTransparent' => $theme['isTransparent'] ?? false,
            // デザインページのプレビュー用（左パネル・中央・入力欄の --dt-* 即時反映）
            'dtLeftBg' => $theme['leftPanelBg'] ?? $panelBg,
            'dtLeftText' => $theme['leftPanelText'] ?? $textColor,
            'dtRightBg' => $rightPanelBg,
            'dtRightText' => $theme['rightPanelText'] ?? $textColor,
            'dtCenterBg' => $panelBgCenter,
            'dtCenterHeaderBg' => $theme['headerGradient'] ?? $panelBg,
            'dtCenterHeaderText' => $theme['headerText'] ?? '#ffffff',
            'dtCenterInputBg' => $inputBg,
            'dtInputBg' => $inputBg,
            'dtInputText' => $theme['inputText'] ?? $textColor,
            'dtInputPlaceholder' => $textMuted,
            'dtInputBorder' => $inputBorder,
            'dtDivider' => $theme['divider'] ?? 'rgba(0,0,0,0.1)',
        ];
    }
    
    return $result;
}

/**
 * 単一テーマのデータを取得
 */
function getThemeById(string $themeId): array {
    $themes = getThemeConfigs();
    if (isset($themes[$themeId])) {
        return $themes[$themeId];
    }
    // 標準デザイン（lavender）のみ。廃止テーマはすべて lavender にフォールバック
    return $themes[DESIGN_DEFAULT_THEME];
}

// ============================================
// スタイル設定（枠線スタイル：直角・丸み1・丸み2）
// ============================================
function getStyleConfigs(): array {
    return [
        'frame_square' => [
            'id' => 'frame_square',
            'name' => '直角',
            'icon' => '▢',
            'description' => '枠線スタイル・角ばったデザイン',
            'borderRadius' => '0',
            'shadow' => '0 2px 6px rgba(0,0,0,0.08)',
            'border' => '1px solid rgba(0,0,0,0.12)',
            'fontFamily' => "'Hiragino Sans', 'Meiryo', sans-serif",
            'buttonRadius' => '0',
            'cardRadius' => '0',
            'inputRadius' => '0',
        ],
        'frame_round1' => [
            'id' => 'frame_round1',
            'name' => '丸み1',
            'icon' => '◐',
            'description' => '枠線スタイル・やや丸み',
            'borderRadius' => '8px',
            'shadow' => '0 2px 8px rgba(0,0,0,0.08)',
            'border' => '1px solid rgba(0,0,0,0.12)',
            'fontFamily' => "'Hiragino Sans', 'Meiryo', sans-serif",
            'buttonRadius' => '8px',
            'cardRadius' => '8px',
            'inputRadius' => '8px',
        ],
        'frame_round2' => [
            'id' => 'frame_round2',
            'name' => '丸み2',
            'icon' => '○',
            'description' => '枠線スタイル・より丸み',
            'borderRadius' => '16px',
            'shadow' => '0 2px 8px rgba(0,0,0,0.08)',
            'border' => '1px solid rgba(0,0,0,0.12)',
            'fontFamily' => "'Hiragino Sans', 'Meiryo', sans-serif",
            'buttonRadius' => '16px',
            'cardRadius' => '16px',
            'inputRadius' => '16px',
        ],
    ];
}

/**
 * design.php用のスタイル配列を取得（UI表示用）
 */
function getStylesForUI(): array {
    $styles = getStyleConfigs();
    $result = [];
    
    foreach ($styles as $style) {
        $result[] = [
            'id' => $style['id'],
            'name' => $style['name'],
            'icon' => $style['icon'],
            'description' => $style['description'],
            // 枠線プレビュー即時反映用（--ui-* に設定）
            'borderRadius' => $style['borderRadius'],
            'buttonRadius' => $style['buttonRadius'],
            'cardRadius' => $style['cardRadius'],
            'inputRadius' => $style['inputRadius'],
            'shadow' => $style['shadow'] ?? '0 2px 8px rgba(0,0,0,0.08)',
            'border' => $style['border'] ?? '1px solid rgba(0,0,0,0.12)',
        ];
    }
    
    return $result;
}

/** 旧スタイルIDを枠線スタイルIDにマッピング（bodyクラス等で利用） */
function getEffectiveStyleId(string $styleId): string {
    return resolveStyleId($styleId);
}

/** 旧スタイルIDを枠線スタイルIDにマッピング */
function resolveStyleId(string $styleId): string {
    $legacyMap = ['natural' => 'frame_round1', 'cute' => 'frame_round2', 'classic' => 'frame_square', 'modern' => 'frame_round1'];
    $resolved = $legacyMap[$styleId] ?? $styleId;
    $valid = array_keys(getStyleConfigs());
    return in_array($resolved, $valid, true) ? $resolved : DESIGN_DEFAULT_STYLE;
}

/**
 * 単一スタイルのデータを取得（旧IDは枠線スタイルにマッピング）
 */
function getStyleById(string $styleId): array {
    $styles = getStyleConfigs();
    $resolvedId = resolveStyleId($styleId);
    return $styles[$resolvedId] ?? $styles[DESIGN_DEFAULT_STYLE];
}

// ============================================
// フォント設定
// ============================================
function getFontConfigs(): array {
    return [
        'default' => [
            'id' => 'default',
            'name' => '標準',
            'family' => "'Hiragino Sans', 'Meiryo', sans-serif",
            'description' => 'システム標準',
        ],
        'zen-maru' => [
            'id' => 'zen-maru',
            'name' => 'まるゴシック',
            'family' => "'Zen Maru Gothic', sans-serif",
            'description' => '丸みのある優しい',
        ],
        'yomogi' => [
            'id' => 'yomogi',
            'name' => 'よもぎ',
            'family' => "'Yomogi', cursive",
            'description' => '手書き風',
        ],
        'kosugi-maru' => [
            'id' => 'kosugi-maru',
            'name' => 'コスギ丸',
            'family' => "'Kosugi Maru', sans-serif",
            'description' => 'コロンと丸い',
        ],
        'noto-sans' => [
            'id' => 'noto-sans',
            'name' => 'Noto Sans',
            'family' => "'Noto Sans JP', sans-serif",
            'description' => 'モダン',
        ],
    ];
}

/**
 * design.php用のフォント配列を取得（UI表示用）
 */
function getFontsForUI(): array {
    $fonts = getFontConfigs();
    $result = [];
    
    foreach ($fonts as $font) {
        $result[] = [
            'id' => $font['id'],
            'name' => $font['name'],
            'family' => $font['family'],
            'description' => $font['description'],
        ];
    }
    
    return $result;
}

/**
 * 単一フォントのデータを取得
 */
function getFontById(string $fontId): array {
    $fonts = getFontConfigs();
    return $fonts[$fontId] ?? $fonts[DESIGN_DEFAULT_FONT];
}

// ============================================
// フォントサイズ設定
// ============================================
function getFontSizeConfigs(): array {
    return [
        'compact' => ['base' => '12px', 'message' => '12px', 'title' => '14px'],  // 90%表示（初回入室者デフォルト）
        'small' => ['base' => '13px', 'message' => '13px', 'title' => '15px'],
        'medium' => ['base' => '14px', 'message' => '14px', 'title' => '16px'],
        'large' => ['base' => '16px', 'message' => '16px', 'title' => '18px'],
    ];
}

function getFontSizeById(string $sizeId): array {
    $sizes = getFontSizeConfigs();
    return $sizes[$sizeId] ?? $sizes[DESIGN_DEFAULT_FONT_SIZE];
}

// ============================================
// バリデーション用配列
// ============================================
function getValidThemes(): array {
    return array_keys(getThemeConfigs());
}

function getValidStyles(): array {
    return array_keys(getStyleConfigs());
}

function getValidFonts(): array {
    return array_keys(getFontConfigs());
}

function getValidFontSizes(): array {
    return array_keys(getFontSizeConfigs());
}

// ============================================
// デフォルト設定
// ============================================
function getDefaultDesignSettings(): array {
    return [
        'theme' => DESIGN_DEFAULT_THEME,
        'dark_mode' => 0,
        'accent_color' => DESIGN_DEFAULT_ACCENT,
        'background_image' => 'none',
        'font_size' => DESIGN_DEFAULT_FONT_SIZE,
        'ui_style' => DESIGN_DEFAULT_STYLE,
        'font_family' => DESIGN_DEFAULT_FONT,
        'background_size' => '80',
    ];
}

// ============================================
// エラーハンドリング
// ============================================

/**
 * デザイン関連のエラーをログに記録
 * @param string $context エラーが発生したコンテキスト
 * @param Exception|string $error エラー内容
 * @param array $data 追加データ
 */
function logDesignError(string $context, $error, array $data = []): void {
    $message = $error instanceof Exception ? $error->getMessage() : (string)$error;
    $logEntry = sprintf(
        "[Design Error][%s] %s | Data: %s",
        $context,
        $message,
        json_encode($data, JSON_UNESCAPED_UNICODE)
    );
    error_log($logEntry);
}

/**
 * ユーザーフレンドリーなエラーメッセージを取得
 * @param string $errorType エラータイプ
 * @return string ユーザー向けメッセージ
 */
function getDesignErrorMessage(string $errorType): string {
    $messages = [
        'file_too_large' => 'ファイルサイズが大きすぎます（' . DESIGN_MAX_BACKGROUND_SIZE_MB . 'MB以下）',
        'invalid_file_type' => 'JPG、PNG、GIF、WebP形式のみ対応しています',
        'upload_failed' => 'アップロードに失敗しました',
        'save_failed' => '設定の保存に失敗しました',
        'invalid_theme' => '無効なテーマです',
        'invalid_style' => '無効なスタイルです',
        'invalid_font' => '無効なフォントです',
        'db_error' => 'データベースエラーが発生しました',
        'unknown' => 'エラーが発生しました',
    ];
    
    return $messages[$errorType] ?? $messages['unknown'];
}

/**
 * APIエラーレスポンスを生成
 * @param string $errorType エラータイプ
 * @param string|null $customMessage カスタムメッセージ（オプション）
 * @return array レスポンス配列
 */
function createDesignErrorResponse(string $errorType, ?string $customMessage = null): array {
    return [
        'success' => false,
        'error' => $customMessage ?? getDesignErrorMessage($errorType),
        'error_type' => $errorType
    ];
}

/**
 * API成功レスポンスを生成
 * @param string $message 成功メッセージ
 * @param array $data 追加データ
 * @return array レスポンス配列
 */
function createDesignSuccessResponse(string $message, array $data = []): array {
    return array_merge([
        'success' => true,
        'message' => $message
    ], $data);
}

// 透明テーマ用スタイル（廃止。design.phpとの互換性のためスタブを残す）
function getTransparentStyles(): array {
    return [];
}




