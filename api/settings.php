<?php
/**
 * ユーザー設定API
 * デザイン設定、通知設定などの保存・取得
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/design_config.php';
require_once __DIR__ . '/../config/ringtone_sounds.php';

// テーブルカラムキャッシュ（リクエスト内で再利用）
$_apiTableColumnsCache = [];

/**
 * テーブルのカラム一覧を取得（キャッシュ付き）
 */
function getApiTableColumns(PDO $pdo, string $table): array {
    global $_apiTableColumnsCache;
    
    if (!isset($_apiTableColumnsCache[$table])) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
            $_apiTableColumnsCache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {
            $_apiTableColumnsCache[$table] = [];
        }
    }
    
    return $_apiTableColumnsCache[$table];
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です']);
    exit;
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

/**
 * user_settingsテーブルの存在と必要カラムを確認・作成
 */
function ensureSettingsTable($pdo) {
    try {
        // テーブルの存在確認
        $result = $pdo->query("SHOW TABLES LIKE 'user_settings'");
        if ($result->rowCount() === 0) {
            // テーブルを作成
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE COMMENT 'ユーザーID',
                    theme VARCHAR(50) DEFAULT 'default' COMMENT 'テーマ',
                    dark_mode TINYINT(1) DEFAULT 0 COMMENT 'ダークモード',
                    accent_color VARCHAR(20) DEFAULT '#10b981' COMMENT 'アクセントカラー',
                    background_image VARCHAR(50) DEFAULT 'none' COMMENT '背景画像',
                    font_size ENUM('compact', 'small', 'medium', 'large') DEFAULT 'medium' COMMENT 'フォントサイズ',
                    language VARCHAR(10) DEFAULT 'ja' COMMENT '表示言語',
                    auto_translate TINYINT(1) DEFAULT 0 COMMENT '自動翻訳',
                    enter_to_send TINYINT(1) DEFAULT 1 COMMENT 'Enterで送信',
                    show_typing_indicator TINYINT(1) DEFAULT 1 COMMENT 'タイピング表示',
                    message_preview TINYINT(1) DEFAULT 1 COMMENT 'メッセージプレビュー',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // 必要なカラムを追加（存在しない場合のみ）- キャッシュ利用で効率化
            $existingColumns = getApiTableColumns($pdo, 'user_settings');
            
            $columnDefinitions = [
                'dark_mode' => "TINYINT(1) DEFAULT 0 COMMENT 'ダークモード'",
                'accent_color' => "VARCHAR(20) DEFAULT '" . DESIGN_DEFAULT_ACCENT . "' COMMENT 'アクセントカラー'",
                'background_image' => "VARCHAR(50) DEFAULT 'none' COMMENT '背景画像'",
                'ui_style' => "VARCHAR(20) DEFAULT '" . DESIGN_DEFAULT_STYLE . "' COMMENT 'UIスタイル'",
                'font_family' => "VARCHAR(30) DEFAULT '" . DESIGN_DEFAULT_FONT . "' COMMENT 'フォント'",
                'background_size' => "INT DEFAULT 80 COMMENT '背景サイズ'",
                'notification_sound' => "VARCHAR(30) DEFAULT 'default' COMMENT '着信音（自分宛メッセージ）'",
                'notification_trigger_pc' => "VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（PC）'",
                'notification_trigger_mobile' => "VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（携帯）'",
                'notification_preview_duration' => "TINYINT DEFAULT 3 COMMENT '試聴再生時間（1/3/5秒）'",
                'ringtone_preview_duration' => "TINYINT DEFAULT 3 COMMENT '通話試聴再生時間（1/3/5秒）'",
            ];
            
            foreach ($columnDefinitions as $col => $definition) {
                if (!in_array($col, $existingColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE user_settings ADD COLUMN {$col} {$definition}");
                    } catch (PDOException $e) {
                        // カラム追加エラーは無視
                    }
                }
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log('Settings table error: ' . $e->getMessage());
        return false;
    }
}

switch ($action) {
    case 'get':
        // 設定を取得
        ensureSettingsTable($pdo);
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings) {
                // デフォルト設定を返す（共通設定 + 追加設定）
                $settings = array_merge(getDefaultDesignSettings(), [
                    'language' => 'ja',
                    'auto_translate' => 0,
                    'enter_to_send' => 1,
                    'show_typing_indicator' => 1,
                    'message_preview' => 1,
                    'notification_sound' => 'default',
                    'notification_trigger_pc' => 'to_me',
                    'notification_trigger_mobile' => 'to_me'
                ]);
            }
            
            // 数値型のキャスト
            $settings['dark_mode'] = (int)($settings['dark_mode'] ?? 0);
            $settings['auto_translate'] = (int)($settings['auto_translate'] ?? 0);
            $settings['enter_to_send'] = (int)($settings['enter_to_send'] ?? 1);
            $settings['show_typing_indicator'] = (int)($settings['show_typing_indicator'] ?? 1);
            $settings['message_preview'] = (int)($settings['message_preview'] ?? 1);
            $settings['notification_sound'] = isset($settings['notification_sound']) ? (string)$settings['notification_sound'] : 'default';
            $settings['notification_trigger_pc'] = isset($settings['notification_trigger_pc']) && in_array($settings['notification_trigger_pc'], ['all', 'to_me']) ? (string)$settings['notification_trigger_pc'] : 'to_me';
            $settings['notification_trigger_mobile'] = isset($settings['notification_trigger_mobile']) && in_array($settings['notification_trigger_mobile'], ['all', 'to_me']) ? (string)$settings['notification_trigger_mobile'] : 'to_me';
            // 通話着信音（チャットで startIncomingCallAlert が参照する）
            $settings['call_ringtone'] = 'default';
            try {
                $stmtCall = $pdo->prepare("SELECT ringtone FROM user_call_settings WHERE user_id = ?");
                $stmtCall->execute([$user_id]);
                $rowCall = $stmtCall->fetch(PDO::FETCH_ASSOC);
                if ($rowCall && isset($rowCall['ringtone']) && $rowCall['ringtone'] !== '') {
                    $settings['call_ringtone'] = (string)$rowCall['ringtone'];
                }
            } catch (Exception $e) { /* テーブルなし時は default のまま */ }
            
            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '設定の取得に失敗しました']);
        }
        break;
        
    case 'update_design':
        // デザイン設定を更新
        ensureSettingsTable($pdo);
        
        $theme = 'lavender';
        $dark_mode = 0;
        $accent_color = $input['accent_color'] ?? '#10b981';
        $background_image = 'none';
        $font_size = $input['font_size'] ?? 'medium';
        $ui_style = $input['ui_style'] ?? 'simple';
        $font_family = $input['font_family'] ?? 'default';
        $background_size = $input['background_size'] ?? '80';
        
        // バリデーション（共通設定から取得）
        $valid_themes = getValidThemes();
        $valid_font_sizes = getValidFontSizes();
        $valid_ui_styles = getValidStyles();
        $valid_font_families = getValidFonts();
        
        if (!in_array($theme, $valid_themes)) {
            $theme = DESIGN_DEFAULT_THEME;
        }
        if (!in_array($font_size, $valid_font_sizes)) {
            $font_size = DESIGN_DEFAULT_FONT_SIZE;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent_color) && !preg_match('/^rgba?\(/', $accent_color)) {
            $accent_color = DESIGN_DEFAULT_ACCENT;
        }
        if (!in_array($ui_style, $valid_ui_styles)) {
            $ui_style = DESIGN_DEFAULT_STYLE;
        }
        if (!in_array($font_family, $valid_font_families)) {
            $font_family = DESIGN_DEFAULT_FONT;
        }
        // background_sizeは定数で範囲制限
        $background_size = max(DESIGN_BG_SIZE_MIN, min(DESIGN_BG_SIZE_MAX, intval($background_size)));
        
        try {
            // カラムの存在確認（キャッシュ利用で効率化）
            $existingColumns = getApiTableColumns($pdo, 'user_settings');
            $hasUiStyle = in_array('ui_style', $existingColumns);
            $hasFontFamily = in_array('font_family', $existingColumns);
            $hasBgSize = in_array('background_size', $existingColumns);
            
            if ($hasUiStyle && $hasFontFamily && $hasBgSize) {
                // 全カラムがある場合
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, theme, dark_mode, accent_color, background_image, font_size, ui_style, font_family, background_size)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        theme = VALUES(theme),
                        dark_mode = VALUES(dark_mode),
                        accent_color = VALUES(accent_color),
                        background_image = VALUES(background_image),
                        font_size = VALUES(font_size),
                        ui_style = VALUES(ui_style),
                        font_family = VALUES(font_family),
                        background_size = VALUES(background_size),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $theme, $dark_mode, $accent_color, $background_image, $font_size, $ui_style, $font_family, $background_size]);
            } elseif ($hasUiStyle && $hasFontFamily) {
                // background_sizeなし
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, theme, dark_mode, accent_color, background_image, font_size, ui_style, font_family)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        theme = VALUES(theme),
                        dark_mode = VALUES(dark_mode),
                        accent_color = VALUES(accent_color),
                        background_image = VALUES(background_image),
                        font_size = VALUES(font_size),
                        ui_style = VALUES(ui_style),
                        font_family = VALUES(font_family),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $theme, $dark_mode, $accent_color, $background_image, $font_size, $ui_style, $font_family]);
            } elseif ($hasUiStyle) {
                // ui_styleのみある場合
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, theme, dark_mode, accent_color, background_image, font_size, ui_style)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        theme = VALUES(theme),
                        dark_mode = VALUES(dark_mode),
                        accent_color = VALUES(accent_color),
                        background_image = VALUES(background_image),
                        font_size = VALUES(font_size),
                        ui_style = VALUES(ui_style),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $theme, $dark_mode, $accent_color, $background_image, $font_size, $ui_style]);
            } else {
                // 基本カラムのみの場合
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, theme, dark_mode, accent_color, background_image, font_size)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        theme = VALUES(theme),
                        dark_mode = VALUES(dark_mode),
                        accent_color = VALUES(accent_color),
                        background_image = VALUES(background_image),
                        font_size = VALUES(font_size),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $theme, $dark_mode, $accent_color, $background_image, $font_size]);
            }
            
            echo json_encode(['success' => true, 'message' => 'デザイン設定を保存しました']);
        } catch (PDOException $e) {
            error_log('Design settings error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
        }
        break;
        
    case 'update_notifications':
        // 通知設定を更新（将来用）
        echo json_encode(['success' => true, 'message' => '通知設定を保存しました']);
        break;
        
    case 'update_privacy':
        // プライバシー設定を更新
        $exclude_from_search = isset($input['exclude_from_search']) ? ((int)$input['exclude_from_search'] ? 1 : 0) : 0;
        $hide_online_status = isset($input['hide_online_status']) ? ((int)$input['hide_online_status'] ? 1 : 0) : 0;
        $hide_read_receipts = isset($input['hide_read_receipts']) ? ((int)$input['hide_read_receipts'] ? 1 : 0) : 0;
        
        try {
            // user_privacy_settingsテーブルが存在するか確認
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_privacy_settings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL UNIQUE,
                    hide_online_status TINYINT(1) DEFAULT 0,
                    hide_read_receipts TINYINT(1) DEFAULT 0,
                    profile_visibility ENUM('everyone', 'chatted', 'group_members') DEFAULT 'everyone',
                    exclude_from_search TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO user_privacy_settings (user_id, exclude_from_search, hide_online_status, hide_read_receipts)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    exclude_from_search = VALUES(exclude_from_search),
                    hide_online_status = VALUES(hide_online_status),
                    hide_read_receipts = VALUES(hide_read_receipts),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$user_id, $exclude_from_search, $hide_online_status, $hide_read_receipts]);
            
            echo json_encode(['success' => true, 'message' => 'プライバシー設定を保存しました']);
        } catch (PDOException $e) {
            error_log('Privacy settings error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
        }
        break;
        
    case 'get_privacy':
        // プライバシー設定を取得
        try {
            $stmt = $pdo->prepare("SELECT * FROM user_privacy_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $privacy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$privacy) {
                // デフォルト値（検索可能＝携帯番号・名前で検索ヒットする）
                $privacy = [
                    'exclude_from_search' => 0,
                    'hide_online_status' => 0,
                    'hide_read_receipts' => 0,
                    'profile_visibility' => 'everyone'
                ];
            }
            
            // 数値型にキャスト
            $privacy['exclude_from_search'] = (int)$privacy['exclude_from_search'];
            $privacy['hide_online_status'] = (int)$privacy['hide_online_status'];
            $privacy['hide_read_receipts'] = (int)$privacy['hide_read_receipts'];
            
            echo json_encode(['success' => true, 'privacy' => $privacy]);
        } catch (PDOException $e) {
            // テーブルが存在しない場合はデフォルト値を返す
            echo json_encode(['success' => true, 'privacy' => [
                'exclude_from_search' => 0,
                'hide_online_status' => 0,
                'hide_read_receipts' => 0,
                'profile_visibility' => 'everyone'
            ]]);
        }
        break;
    
    case 'update_avatar':
        // アバターを更新
        $avatar_path = trim($input['avatar_path'] ?? '');
        $avatar_style = trim($input['avatar_style'] ?? 'default');
        $avatar_pos_x = (float)($input['avatar_pos_x'] ?? 0);
        $avatar_pos_y = (float)($input['avatar_pos_y'] ?? 0);
        $avatar_size = (int)($input['avatar_size'] ?? 100);
        
        // パスが指定されている場合のみ検証
        if (!empty($avatar_path)) {
            // パストラバーサル対策
            if (strpos($avatar_path, '..') !== false) {
                echo json_encode(['success' => false, 'message' => '無効なパスです']);
                break;
            }
            // パスの検証（サンプルアイコンまたはアップロードされた画像）
            // upload.php は "uploads/2026/02/xxx.jpg" を返すため、先頭スラッシュなしも許可
            $allowedPrefixes = ['/assets/icons/samples/', 'uploads/', '/uploads/'];
            $isValid = false;
            foreach ($allowedPrefixes as $prefix) {
                if (strpos($avatar_path, $prefix) === 0) {
                    $isValid = true;
                    break;
                }
            }
            
            if (!$isValid) {
                echo json_encode(['success' => false, 'message' => '無効なパスです']);
                break;
            }
        }
        
        try {
            // すべてのカラムを更新
            $pdo->prepare("
                UPDATE users SET avatar_path = ?, avatar_style = ?, avatar_pos_x = ?, avatar_pos_y = ?, avatar_size = ?, updated_at = NOW() WHERE id = ?
            ")->execute([$avatar_path, $avatar_style, $avatar_pos_x, $avatar_pos_y, $avatar_size, $user_id]);
            
            // セッションも更新
            $_SESSION['avatar_path'] = $avatar_path;
            
            echo json_encode(['success' => true, 'message' => 'アバターを更新しました', 'avatar_path' => $avatar_path]);
        } catch (PDOException $e) {
            // カラムがない場合はavatar_pathのみ更新
            try {
                $stmt = $pdo->prepare("UPDATE users SET avatar_path = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$avatar_path, $user_id]);
                $_SESSION['avatar_path'] = $avatar_path;
                echo json_encode(['success' => true, 'message' => 'アバターを更新しました', 'avatar_path' => $avatar_path]);
            } catch (PDOException $e2) {
                echo json_encode(['success' => false, 'message' => '更新に失敗しました']);
            }
        }
        break;
        
    case 'update_display_name':
        // 表示名を更新
        $display_name = trim($input['display_name'] ?? '');
        
        if (empty($display_name)) {
            echo json_encode(['success' => false, 'message' => '表示名を入力してください']);
            break;
        }
        
        if (mb_strlen($display_name) > 50) {
            echo json_encode(['success' => false, 'message' => '表示名は50文字以内で入力してください']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$display_name, $user_id]);
            
            // セッションも更新
            $_SESSION['display_name'] = $display_name;
            
            echo json_encode(['success' => true, 'message' => '表示名を更新しました']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '更新に失敗しました']);
        }
        break;
        
    case 'get_invite_info':
        // 自分の招待情報を取得
        try {
            // ユーザー情報を取得
            $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 招待URL生成
            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $invite_url = $base_url . '/invite.php?u=' . $user_id;
            
            echo json_encode([
                'success' => true,
                'invite_url' => $invite_url,
                'user_id' => (int)$user_id,
                'display_name' => $user_info['display_name']
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '招待情報の取得に失敗しました']);
        }
        break;
        
    case 'update_chat':
        // チャット設定を更新
        ensureSettingsTable($pdo);
        
        $enter_to_send = !empty($input['enter_to_send']) ? 1 : 0;
        $show_typing_indicator = !empty($input['show_typing_indicator']) ? 1 : 0;
        $message_preview = !empty($input['message_preview']) ? 1 : 0;
        $notification_sound = isset($input['notification_sound']) ? (string)$input['notification_sound'] : 'default';
        $valid_sounds = ringtone_valid_sound_ids();
        if (!in_array($notification_sound, $valid_sounds)) {
            $notification_sound = 'default';
        }
        
        $existingColumns = getApiTableColumns($pdo, 'user_settings');
        $hasNotificationSound = in_array('notification_sound', $existingColumns);
        
        try {
            if ($hasNotificationSound) {
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, enter_to_send, show_typing_indicator, message_preview, notification_sound)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        enter_to_send = VALUES(enter_to_send),
                        show_typing_indicator = VALUES(show_typing_indicator),
                        message_preview = VALUES(message_preview),
                        notification_sound = VALUES(notification_sound),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $enter_to_send, $show_typing_indicator, $message_preview, $notification_sound]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, enter_to_send, show_typing_indicator, message_preview)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        enter_to_send = VALUES(enter_to_send),
                        show_typing_indicator = VALUES(show_typing_indicator),
                        message_preview = VALUES(message_preview),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$user_id, $enter_to_send, $show_typing_indicator, $message_preview]);
            }
            
            echo json_encode(['success' => true, 'message' => 'チャット設定を保存しました']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
        }
        break;
        
    case 'get_blocklist':
        // ブロックリスト取得
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.display_name 
                FROM blocked_users b
                JOIN users u ON b.blocked_user_id = u.id
                WHERE b.user_id = ?
                ORDER BY b.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $blocked_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'blocked_users' => $blocked_users]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'blocked_users' => []]);
        }
        break;
        
    case 'unblock_user':
        // ブロック解除
        $target_user_id = (int)($input['user_id'] ?? 0);
        if (!$target_user_id) {
            echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?");
            $stmt->execute([$user_id, $target_user_id]);
            echo json_encode(['success' => true, 'message' => 'ブロックを解除しました']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'ブロック解除に失敗しました']);
        }
        break;
        
    case 'block_user':
        // ユーザーをブロック
        $target_user_id = (int)($input['user_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        
        if (!$target_user_id) {
            echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
            break;
        }
        
        if ($target_user_id === $user_id) {
            echo json_encode(['success' => false, 'error' => '自分自身をブロックすることはできません']);
            break;
        }
        
        try {
            // blocked_usersテーブルの存在確認・作成
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS blocked_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    blocked_user_id INT NOT NULL,
                    reason VARCHAR(200),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_block (user_id, blocked_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO blocked_users (user_id, blocked_user_id, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)
            ");
            $stmt->execute([$user_id, $target_user_id, $reason]);
            echo json_encode(['success' => true, 'message' => 'ユーザーをブロックしました']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'ブロックに失敗しました']);
        }
        break;
        
    case 'get_data_stats':
        // データ統計を取得
        try {
            // メッセージ数
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?");
            $stmt->execute([$user_id]);
            $message_count = (int)$stmt->fetchColumn();
            
            // Wish数
            $wish_count = 0;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = ?");
                $stmt->execute([$user_id]);
                $wish_count = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // メモ数（tasks テーブル統合対応）
            $memo_count = 0;
            try {
                $hasType = false;
                try { $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'"); $hasType = $chk && $chk->rowCount() > 0; } catch (Exception $e) {}
                if ($hasType) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = ? AND type = 'memo' AND (deleted_at IS NULL OR deleted_at = '')");
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memos WHERE created_by = ?");
                }
                $stmt->execute([$user_id]);
                $memo_count = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // 友だち数
            $friend_count = 0;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE user_id = ? AND status = 'accepted'");
                $stmt->execute([$user_id]);
                $friend_count = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'message_count' => $message_count,
                    'wish_count' => $wish_count,
                    'memo_count' => $memo_count,
                    'friend_count' => $friend_count
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'データ取得に失敗しました']);
        }
        break;
        
    case 'export_messages':
        // メッセージ履歴エクスポート
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    m.id,
                    m.content,
                    m.created_at,
                    c.name as conversation_name,
                    c.type as conversation_type
                FROM messages m
                JOIN conversations c ON m.conversation_id = c.id
                WHERE m.sender_id = ?
                ORDER BY m.created_at DESC
                LIMIT 10000
            ");
            $stmt->execute([$user_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // グループ化
            $grouped = [];
            foreach ($messages as $msg) {
                $convName = $msg['conversation_name'] ?: ($msg['conversation_type'] === 'dm' ? 'ダイレクトメッセージ' : '不明');
                if (!isset($grouped[$convName])) {
                    $grouped[$convName] = [];
                }
                $grouped[$convName][] = [
                    'content' => $msg['content'],
                    'sent_at' => $msg['created_at']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'count' => count($messages),
                'messages' => $grouped
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'エクスポートに失敗しました']);
        }
        break;
        
    case 'export_data':
        // データエクスポート
        try {
            // ユーザー情報
            $stmt = $pdo->prepare("SELECT id, email, display_name, created_at FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // メッセージ数
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?");
            $stmt->execute([$user_id]);
            $message_count = (int)$stmt->fetchColumn();
            
            // Wish数
            $wish_count = 0;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = ?");
                $stmt->execute([$user_id]);
                $wish_count = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // メモ数（tasks テーブル統合対応）
            $memo_count = 0;
            try {
                $hasType = false;
                try { $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'"); $hasType = $chk && $chk->rowCount() > 0; } catch (Exception $e) {}
                if ($hasType) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = ? AND type = 'memo' AND (deleted_at IS NULL OR deleted_at = '')");
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memos WHERE created_by = ?");
                }
                $stmt->execute([$user_id]);
                $memo_count = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // 設定
            $settings = [];
            try {
                $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
            
            $export_data = [
                'export_date' => date('Y-m-d H:i:s'),
                'user' => $user_data,
                'statistics' => [
                    'message_count' => $message_count,
                    'wish_count' => $wish_count,
                    'memo_count' => $memo_count
                ],
                'settings' => $settings
            ];
            
            echo json_encode(['success' => true, 'export_data' => $export_data]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'エクスポートに失敗しました']);
        }
        break;
        
    case 'submit_contact':
        // お問い合わせ送信
        $type = $input['type'] ?? '';
        $subject = $input['subject'] ?? '';
        $message = $input['message'] ?? '';
        
        if (empty($type) || empty($subject) || empty($message)) {
            echo json_encode(['success' => false, 'error' => '必須項目を入力してください']);
            break;
        }
        
        // バリデーション
        $valid_types = ['bug', 'feature', 'account', 'privacy', 'other'];
        if (!in_array($type, $valid_types)) {
            echo json_encode(['success' => false, 'error' => '不正な種別です']);
            break;
        }
        
        try {
            // お問い合わせテーブルに保存（テーブルがなければ作成）
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contact_inquiries (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    status ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO contact_inquiries (user_id, type, subject, message)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $type, $subject, $message]);
            
            echo json_encode(['success' => true, 'message' => 'お問い合わせを送信しました']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => '送信に失敗しました']);
        }
        break;
        
    case 'delete_account':
        // アカウント削除
        try {
            $pdo->beginTransaction();
            
            // 関連データを削除（外部キー制約がある場合は自動削除される）
            // メッセージは送信者情報を残すため、sender_idをNULLに更新
            $pdo->prepare("UPDATE messages SET sender_id = NULL WHERE sender_id = ?")->execute([$user_id]);
            
            // ユーザー設定を削除
            try {
                $pdo->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$user_id]);
            } catch (Exception $e) {}
            
            // ブロックリストを削除
            try {
                $pdo->prepare("DELETE FROM blocked_users WHERE user_id = ? OR blocked_user_id = ?")->execute([$user_id, $user_id]);
            } catch (Exception $e) {}
            
            // 通知を削除
            try {
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user_id]);
            } catch (Exception $e) {}
            
            // タスク/Wishを削除
            try {
                $pdo->prepare("DELETE FROM tasks WHERE created_by = ?")->execute([$user_id]);
            } catch (Exception $e) {}
            
            // メモを削除
            try {
                $pdo->prepare("DELETE FROM memos WHERE created_by = ?")->execute([$user_id]);
            } catch (Exception $e) {}
            
            // 会話メンバーシップを削除
            try {
                $pdo->prepare("DELETE FROM conversation_members WHERE user_id = ?")->execute([$user_id]);
            } catch (Exception $e) {}
            
            // 最後にユーザーを削除
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            
            $pdo->commit();
            
            // セッションを破棄
            session_destroy();
            
            echo json_encode(['success' => true, 'message' => 'アカウントを削除しました']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Account deletion error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'アカウント削除に失敗しました']);
        }
        break;
        
    case 'upload_background':
        // 標準デザイン固定のため背景画像アップロードは無効
        echo json_encode(['success' => false, 'error' => '標準デザインのため背景画像のアップロードはできません']);
        break;
        
    case 'upload_background_legacy':
        // 標準デザイン固定のため背景画像アップロードは無効
        echo json_encode(['success' => false, 'error' => '標準デザインのため背景画像のアップロードはできません']);
        break;
        
    case 'delete_background':
        // 背景画像を削除
        try {
            ensureSettingsTable($pdo);
            
            // 現在の背景画像を取得
            $stmt = $pdo->prepare("SELECT background_image FROM user_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['background_image'] && $result['background_image'] !== 'none') {
                // ファイルを削除
                $filepath = __DIR__ . '/../uploads/backgrounds/' . $result['background_image'];
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
            
            // データベースを更新
            $stmt = $pdo->prepare("
                UPDATE user_settings SET background_image = 'none', updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            
            echo json_encode(['success' => true, 'message' => '背景画像を削除しました']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '削除に失敗しました']);
        }
        break;
        
    case 'get_background':
        // 背景画像を取得
        try {
            ensureSettingsTable($pdo);
            $stmt = $pdo->prepare("SELECT background_image FROM user_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $background = $result['background_image'] ?? 'none';
            $url = ($background && $background !== 'none') ? 'uploads/backgrounds/' . $background : null;
            
            echo json_encode([
                'success' => true,
                'background_image' => $background,
                'url' => $url
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'background_image' => 'none', 'url' => null]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => '無効なアクションです']);
        break;
}






