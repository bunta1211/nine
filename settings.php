<?php
/**
 * Social9 設定画面
 * 基本設定、通知、トーク、通話、友だち管理、データ、詳細設定など
 * Version: 2026-01-28-v2 (Avatar fix)
 */
ob_start(); // 出力バッファリング開始

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/push.php';
require_once __DIR__ . '/config/google_calendar.php';
require_once __DIR__ . '/config/ringtone_sounds.php';
require_once __DIR__ . '/includes/asset_helper.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/lang.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// デザイン設定を取得
$designSettings = getDesignSettings($pdo, $user_id);

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$display_name = $user['display_name'] ?? __('user');
$userOrganizations = [];
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
} catch (Exception $e) {
    $userOrganizations = [];
}

// 言語設定をセッションに反映（セッションにない場合はDBから読み込み）
if (!isset($_SESSION['language'])) {
    // languageまたはdisplay_languageカラムから取得
    $userLang = $user['language'] ?? $user['display_language'] ?? null;
    if (!empty($userLang)) {
        setLanguage($userLang);
    }
}
$currentLang = getCurrentLanguage();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$success_message = '';
$error_message = '';

// 都道府県リスト
$prefectures = [
    '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
    '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
    '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
    '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
    '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
    '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
    '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
];

// 現在のセクション
$current_section = $_GET['section'] ?? 'basic';
$valid_sections = ['basic', 'notification', 'talk', 'ringtone', 'call', 'calendar', 'sheets', 'friends', 'privacy', 'parental', 'data', 'advanced', 'shortcuts', 'about'];
if (!in_array($current_section, $valid_sections)) {
    $current_section = 'basic';
}

// 通知設定を取得
$notification_settings = [
    'notify_message' => 1,
    'notify_mention' => 1,
    'notify_call' => 1,
    'notify_announcement' => 1
];
try {
    $stmt = $pdo->prepare("SELECT * FROM user_notification_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $ns = $stmt->fetch();
    if ($ns) {
        $notification_settings = [
            'notify_message' => (int)$ns['notify_message'],
            'notify_mention' => (int)$ns['notify_mention'],
            'notify_call' => (int)$ns['notify_call'],
            'notify_announcement' => (int)$ns['notify_announcement']
        ];
    }
} catch (Exception $e) {
    // テーブルがない場合はデフォルト値を使用
}

// 通話設定を取得
$call_settings = [
    'ringtone' => 'default',
    'camera_default_on' => 1,
    'mic_default_on' => 1,
    'blur_default_on' => 0,
    'noise_cancel' => 1,
    'echo_cancel' => 1,
    'call_quality' => 'standard',
    'share_audio' => 0
];
try {
    $stmt = $pdo->prepare("SELECT * FROM user_call_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cs = $stmt->fetch();
    if ($cs) {
        $call_settings = [
            'ringtone' => $cs['ringtone'] ?? 'default',
            'camera_default_on' => (int)($cs['camera_default_on'] ?? 1),
            'mic_default_on' => (int)($cs['mic_default_on'] ?? 1),
            'blur_default_on' => (int)($cs['blur_default_on'] ?? 0),
            'noise_cancel' => (int)($cs['noise_cancel'] ?? 1),
            'echo_cancel' => (int)($cs['echo_cancel'] ?? 1),
            'call_quality' => $cs['call_quality'] ?? 'standard',
            'share_audio' => (int)($cs['share_audio'] ?? 0)
        ];
    }
} catch (Exception $e) {
    // テーブルがない場合はデフォルト値を使用
}

// グーグルアカウント連携一覧（カレンダーセクション用）
$calendar_accounts = [];
if ($current_section === 'calendar') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, display_name, google_email, is_default, created_at
            FROM google_calendar_accounts
            WHERE user_id = ?
            ORDER BY is_default DESC, display_name ASC
        ");
        $stmt->execute([$user_id]);
        $calendar_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $calendar_accounts = [];
    }
}

// Googleスプレッドシート連携（スプレッドシートセクション用）
$sheets_account = null;
if ($current_section === 'sheets') {
    try {
        $stmt = $pdo->prepare("SELECT id, google_email, created_at FROM google_sheets_accounts WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $sheets_account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $sheets_account = null;
    }
}

// トーク設定を取得
$talk_settings = [
    'send_read_receipt' => 1,
    'show_typing' => 1,
    'show_link_preview' => 1,
    'message_sound' => 1,
    'time_format' => '24h'
];
try {
    $stmt = $pdo->prepare("SELECT * FROM user_talk_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $ts = $stmt->fetch();
    if ($ts) {
        $talk_settings = [
            'send_read_receipt' => (int)($ts['send_read_receipt'] ?? 1),
            'show_typing' => (int)($ts['show_typing'] ?? 1),
            'show_link_preview' => (int)($ts['show_link_preview'] ?? 1),
            'message_sound' => (int)($ts['message_sound'] ?? 1),
            'time_format' => $ts['time_format'] ?? '24h'
        ];
    }
} catch (Exception $e) {
    // テーブルがない場合はデフォルト値を使用
}

// 着信音設定（自分宛メッセージ）を user_settings から取得
$notification_sound = 'default';
$notification_trigger_pc = 'to_me';
$notification_trigger_mobile = 'to_me';
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_settings'");
    if ($stmt && $stmt->rowCount() > 0) {
        $stmt2 = $pdo->query("SHOW COLUMNS FROM user_settings LIKE 'notification_sound'");
        if ($stmt2 && $stmt2->rowCount() === 0) {
            $pdo->exec("ALTER TABLE user_settings ADD COLUMN notification_sound VARCHAR(30) DEFAULT 'default' COMMENT '着信音（自分宛メッセージ）'");
        }
        foreach ([
            'notification_trigger_pc' => "VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（PC）'",
            'notification_trigger_mobile' => "VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（携帯）'",
            'notification_preview_duration' => "TINYINT DEFAULT 3 COMMENT '試聴再生時間（1/3/5秒）'",
            'ringtone_preview_duration' => "TINYINT DEFAULT 3 COMMENT '通話試聴再生時間（1/3/5秒）'"
        ] as $col => $def) {
            $chk = $pdo->query("SHOW COLUMNS FROM user_settings LIKE '" . $col . "'");
            if ($chk && $chk->rowCount() === 0) {
                $pdo->exec("ALTER TABLE user_settings ADD COLUMN {$col} {$def}");
            }
        }
        $stmt3 = $pdo->prepare("SELECT notification_sound, notification_trigger_pc, notification_trigger_mobile, notification_preview_duration, ringtone_preview_duration FROM user_settings WHERE user_id = ?");
        $stmt3->execute([$user_id]);
        $row = $stmt3->fetch();
        if ($row) {
            if (isset($row['notification_sound']) && $row['notification_sound'] !== '') {
                $notification_sound = $row['notification_sound'];
            }
            if (isset($row['notification_trigger_pc']) && $row['notification_trigger_pc'] !== '') {
                $notification_trigger_pc = $row['notification_trigger_pc'];
            }
            if (isset($row['notification_trigger_mobile']) && $row['notification_trigger_mobile'] !== '') {
                $notification_trigger_mobile = $row['notification_trigger_mobile'];
            }
            if (isset($row['notification_preview_duration']) && in_array((int)$row['notification_preview_duration'], [1, 3, 5])) {
                $notification_preview_duration = (int)$row['notification_preview_duration'];
            }
            if (isset($row['ringtone_preview_duration']) && in_array((int)$row['ringtone_preview_duration'], [1, 3, 5])) {
                $ringtone_preview_duration = (int)$row['ringtone_preview_duration'];
            }
        }
    }
} catch (Exception $e) {
    // 無視
}
$notification_trigger_pc = $notification_trigger_pc ?? 'to_me';
$notification_trigger_mobile = $notification_trigger_mobile ?? 'to_me';
if ($notification_trigger_pc === 'none') $notification_trigger_pc = 'to_me';
if ($notification_trigger_mobile === 'none') $notification_trigger_mobile = 'to_me';
$valid_notification_sounds = $RINGTONE_DISPLAY_LABELS;
$valid_ringtones = $RINGTONE_DISPLAY_LABELS;
$valid_notification_triggers = [
    'all' => '全メッセージに反応（他人からの全てのメッセージで鳴る）',
    'to_me' => '自分へのメッセージに反応（メンション・To指定・To全員のときのみ鳴る）'
];

// モバイル判定関数
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|Opera Mini|IEMobile/i', $userAgent);
}

// デフォルト自動ログアウト時間（全デバイスで常時ログオン＝0）
$default_auto_logout = 0;

// 詳細設定を取得
$advanced_settings = [
    'auto_logout_minutes' => $default_auto_logout
];
try {
    $stmt = $pdo->prepare("SELECT * FROM user_advanced_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $as = $stmt->fetch();
    if ($as) {
        $v = (int)($as['auto_logout_minutes'] ?? $default_auto_logout);
        // 許容は 0 と 1440 のみ。それ以外は「自動ログアウトしない」として表示
        $advanced_settings = [
            'auto_logout_minutes' => in_array($v, [0, 1440], true) ? $v : 0
        ];
    }
} catch (Exception $e) {
    // テーブルがない場合はデフォルト値を使用
}

/**
 * user_settings テーブルと着信音用カラムを確保する（存在しなければ作成）
 */
function ensureUserSettingsForRingtone(PDO $pdo): void {
    try {
        $r = $pdo->query("SHOW TABLES LIKE 'user_settings'");
        if ($r && $r->rowCount() === 0) {
            $pdo->exec("
                CREATE TABLE user_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE,
                    theme VARCHAR(50) DEFAULT 'default',
                    language VARCHAR(10) DEFAULT 'ja',
                    notification_sound VARCHAR(30) DEFAULT 'default',
                    notification_trigger_pc VARCHAR(20) DEFAULT 'to_me',
                    notification_trigger_mobile VARCHAR(20) DEFAULT 'to_me',
                    notification_preview_duration TINYINT DEFAULT 3,
                    ringtone_preview_duration TINYINT DEFAULT 3,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }
        $cols = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM user_settings");
            if ($stmt) {
                $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
            }
        } catch (Exception $e) {}
        foreach ([
            'notification_sound' => "VARCHAR(30) DEFAULT 'default'",
            'notification_trigger_pc' => "VARCHAR(20) DEFAULT 'to_me'",
            'notification_trigger_mobile' => "VARCHAR(20) DEFAULT 'to_me'",
            'notification_preview_duration' => 'TINYINT DEFAULT 3',
            'ringtone_preview_duration' => 'TINYINT DEFAULT 3'
        ] as $col => $def) {
            if (!in_array($col, $cols)) {
                try {
                    $pdo->exec("ALTER TABLE user_settings ADD COLUMN {$col} {$def}");
                } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {
        error_log('ensureUserSettingsForRingtone: ' . $e->getMessage());
    }
}

/**
 * user_call_settings テーブルを確保する（存在しなければ作成）
 */
function ensureUserCallSettingsTable(PDO $pdo): void {
    try {
        $r = $pdo->query("SHOW TABLES LIKE 'user_call_settings'");
        if ($r && $r->rowCount() === 0) {
            $pdo->exec("
                CREATE TABLE user_call_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE,
                    ringtone VARCHAR(30) DEFAULT 'default',
                    camera_default_on TINYINT(1) DEFAULT 1,
                    mic_default_on TINYINT(1) DEFAULT 1,
                    blur_default_on TINYINT(1) DEFAULT 0,
                    noise_cancel TINYINT(1) DEFAULT 1,
                    echo_cancel TINYINT(1) DEFAULT 1,
                    call_quality VARCHAR(20) DEFAULT 'standard',
                    share_audio TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (Exception $e) {
        error_log('ensureUserCallSettingsTable: ' . $e->getMessage());
    }
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_basic') {
        $full_name = trim($_POST['full_name'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');
        $display_name_en = trim($_POST['display_name_en'] ?? '') ?: null;
        $display_name_zh = trim($_POST['display_name_zh'] ?? '') ?: null;
        $bio = trim($_POST['bio'] ?? '');
        $prefecture = $_POST['prefecture'] ?? null;
        $city = trim($_POST['city'] ?? '');
        $language = $_POST['language'] ?? 'ja';
        $phone_raw = trim($_POST['phone'] ?? '');
        $phone = $phone_raw === '' ? null : preg_replace('/\D/', '', $phone_raw);
        
        if (empty($display_name)) {
            $error_message = '表示名は必須です';
        } elseif ($phone !== null && strlen($phone) < 10) {
            $error_message = '携帯電話番号は10桁以上で入力してください。';
        } elseif ($phone !== null && strlen($phone) > 15) {
            $error_message = '携帯電話番号は15桁以内で入力してください。';
        } else {
            // 携帯電話の重複チェック（他ユーザーが同一番号を登録していないか）
            if ($phone !== null) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
                $stmt->execute([$phone, $user_id]);
                if ($stmt->fetch()) {
                    $error_message = 'この携帯電話番号は既に他のアカウントで登録されています。';
                }
            }
            if (empty($error_message)) {
                $stmt = $pdo->prepare("
                    UPDATE users SET
                        full_name = ?,
                        display_name = ?,
                        display_name_en = ?,
                        display_name_zh = ?,
                        bio = ?,
                        prefecture = ?,
                        city = ?,
                        phone = ?,
                        display_language = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$full_name, $display_name, $display_name_en, $display_name_zh, $bio, $prefecture, $city, $phone, $language, $user_id]);
                $_SESSION['display_name'] = $display_name;
                $_SESSION['language'] = $language;
                $success_message = '基本設定を保存しました';

                // 再取得（トップバー等の表示名をこのリクエスト内で反映するため $user と $display_name を更新）
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                $display_name = $user['display_name'] ?? $display_name;
            }
        }
    } elseif ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = 'すべてのパスワード欄を入力してください';
        } elseif (!password_verify($current_password, $user['password_hash'])) {
            $error_message = '現在のパスワードが正しくありません';
        } elseif (strlen($new_password) < 8) {
            $error_message = 'パスワードは8文字以上で入力してください';
        } elseif ($new_password !== $confirm_password) {
            $error_message = '新しいパスワードが一致しません';
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_hash, $user_id]);
            $success_message = 'パスワードを変更しました';
        }
    } elseif ($action === 'update_notification') {
        $notify_message = isset($_POST['notify_message']) ? 1 : 0;
        $notify_mention = isset($_POST['notify_mention']) ? 1 : 0;
        $notify_call = isset($_POST['notify_call']) ? 1 : 0;
        $notify_announcement = isset($_POST['notify_announcement']) ? 1 : 0;
        
        try {
            // UPSERT（存在すれば更新、なければ挿入）
            $stmt = $pdo->prepare("
                INSERT INTO user_notification_settings (user_id, notify_message, notify_mention, notify_call, notify_announcement)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    notify_message = VALUES(notify_message),
                    notify_mention = VALUES(notify_mention),
                    notify_call = VALUES(notify_call),
                    notify_announcement = VALUES(notify_announcement)
            ");
            $stmt->execute([$user_id, $notify_message, $notify_mention, $notify_call, $notify_announcement]);
            
            $notification_settings = [
                'notify_message' => $notify_message,
                'notify_mention' => $notify_mention,
                'notify_call' => $notify_call,
                'notify_announcement' => $notify_announcement
            ];
            $success_message = '通知設定を保存しました';
        } catch (Exception $e) {
            $error_message = '通知設定の保存に失敗しました';
        }
    } elseif ($action === 'update_talk') {
        $send_read_receipt = isset($_POST['send_read_receipt']) ? 1 : 0;
        $show_typing = isset($_POST['show_typing']) ? 1 : 0;
        $show_link_preview = isset($_POST['show_link_preview']) ? 1 : 0;
        $message_sound = isset($_POST['message_sound']) ? 1 : 0;
        $time_format = $_POST['time_format'] ?? '24h';
        if (!in_array($time_format, ['12h', '24h'])) {
            $time_format = '24h';
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_talk_settings (user_id, send_read_receipt, show_typing, show_link_preview, message_sound, time_format)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    send_read_receipt = VALUES(send_read_receipt),
                    show_typing = VALUES(show_typing),
                    show_link_preview = VALUES(show_link_preview),
                    message_sound = VALUES(message_sound),
                    time_format = VALUES(time_format)
            ");
            $stmt->execute([$user_id, $send_read_receipt, $show_typing, $show_link_preview, $message_sound, $time_format]);
            
            $talk_settings = [
                'send_read_receipt' => $send_read_receipt,
                'show_typing' => $show_typing,
                'show_link_preview' => $show_link_preview,
                'message_sound' => $message_sound,
                'time_format' => $time_format
            ];
            $success_message = 'トーク設定を保存しました';
        } catch (Exception $e) {
            $error_message = 'トーク設定の保存に失敗しました';
        }
    } elseif ($action === 'update_ringtone') {
        $notification_sound = $_POST['notification_sound'] ?? 'default';
        $ringtone = $_POST['ringtone'] ?? 'default';
        $notification_trigger_pc = $_POST['notification_trigger_pc'] ?? 'to_me';
        $notification_trigger_mobile = $_POST['notification_trigger_mobile'] ?? 'to_me';
        if (!array_key_exists($notification_sound, $valid_notification_sounds)) {
            $notification_sound = 'default';
        }
        if (!array_key_exists($ringtone, $valid_ringtones)) {
            $ringtone = 'default';
        }
        $valid_triggers = ['all', 'to_me'];
        if (!in_array($notification_trigger_pc, $valid_triggers)) {
            $notification_trigger_pc = 'to_me';
        }
        if (!in_array($notification_trigger_mobile, $valid_triggers)) {
            $notification_trigger_mobile = 'to_me';
        }
        $notification_preview_duration = 3;
        $ringtone_preview_duration = 3;
        try {
            // テーブル・カラムの確保を try の外で実施（失敗時はここで throw）
            ensureUserSettingsForRingtone($pdo);
            ensureUserCallSettingsTable($pdo);

            // user_settings: INSERT ... ON DUPLICATE KEY UPDATE で確実に保存
            $stmt = $pdo->prepare("
                INSERT INTO user_settings (user_id, notification_sound, notification_trigger_pc, notification_trigger_mobile, notification_preview_duration, ringtone_preview_duration)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    notification_sound = VALUES(notification_sound),
                    notification_trigger_pc = VALUES(notification_trigger_pc),
                    notification_trigger_mobile = VALUES(notification_trigger_mobile),
                    notification_preview_duration = VALUES(notification_preview_duration),
                    ringtone_preview_duration = VALUES(ringtone_preview_duration),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $user_id,
                $notification_sound,
                $notification_trigger_pc,
                $notification_trigger_mobile,
                $notification_preview_duration,
                $ringtone_preview_duration
            ]);

            // user_call_settings: INSERT ... ON DUPLICATE KEY UPDATE で確実に保存
            $stmt = $pdo->prepare("
                INSERT INTO user_call_settings (user_id, ringtone, camera_default_on, mic_default_on, blur_default_on, noise_cancel, echo_cancel, call_quality, share_audio)
                VALUES (?, ?, 1, 1, 0, 1, 1, 'standard', 0)
                ON DUPLICATE KEY UPDATE ringtone = VALUES(ringtone), updated_at = NOW()
            ");
            $stmt->execute([$user_id, $ringtone]);

            $call_settings['ringtone'] = $ringtone;
            $success_message = '着信音設定を保存しました';
        } catch (Exception $e) {
            error_log('update_ringtone error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $error_message = '着信音設定の保存に失敗しました。データベースのテーブル・カラムが不足している場合は database/migration_user_settings_ringtone.sql を実行してください。';
        }
    } elseif ($action === 'update_advanced') {
        $auto_logout_minutes = (int)($_POST['auto_logout_minutes'] ?? $default_auto_logout);
        
        // 選択肢は「自動ログアウトしない」(0) と「24時間でログアウト」(1440) のみ
        $valid_times = [0, 1440];
        if (!in_array($auto_logout_minutes, $valid_times)) {
            $auto_logout_minutes = $default_auto_logout;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_advanced_settings (user_id, auto_logout_minutes)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                    auto_logout_minutes = VALUES(auto_logout_minutes),
                    updated_at = NOW()
            ");
            $stmt->execute([$user_id, $auto_logout_minutes]);
            
            $advanced_settings = [
                'auto_logout_minutes' => $auto_logout_minutes
            ];
            
            // セッションタイムアウトを更新
            if ($auto_logout_minutes > 0) {
                ini_set('session.gc_maxlifetime', $auto_logout_minutes * 60);
            }
            
            $success_message = '詳細設定を保存しました';
        } catch (Exception $e) {
            $error_message = '詳細設定の保存に失敗しました';
        }
    } elseif ($action === 'update_call') {
        $ringtone = $_POST['ringtone'] ?? ($call_settings['ringtone'] ?? 'default');
        $camera_default_on = isset($_POST['camera_default_on']) ? 1 : 0;
        $mic_default_on = isset($_POST['mic_default_on']) ? 1 : 0;
        $blur_default_on = isset($_POST['blur_default_on']) ? 1 : 0;
        $noise_cancel = isset($_POST['noise_cancel']) ? 1 : 0;
        $echo_cancel = isset($_POST['echo_cancel']) ? 1 : 0;
        $call_quality = $_POST['call_quality'] ?? 'standard';
        $share_audio = isset($_POST['share_audio']) ? 1 : 0;
        
        // バリデーション
        $valid_ringtones = ['default', 'gentle', 'bright', 'classic', 'silent'];
        if (!in_array($ringtone, $valid_ringtones)) $ringtone = 'default';
        
        $valid_qualities = ['high', 'standard', 'low'];
        if (!in_array($call_quality, $valid_qualities)) $call_quality = 'standard';
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_call_settings (user_id, ringtone, camera_default_on, mic_default_on, blur_default_on, noise_cancel, echo_cancel, call_quality, share_audio)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    ringtone = VALUES(ringtone),
                    camera_default_on = VALUES(camera_default_on),
                    mic_default_on = VALUES(mic_default_on),
                    blur_default_on = VALUES(blur_default_on),
                    noise_cancel = VALUES(noise_cancel),
                    echo_cancel = VALUES(echo_cancel),
                    call_quality = VALUES(call_quality),
                    share_audio = VALUES(share_audio)
            ");
            $stmt->execute([$user_id, $ringtone, $camera_default_on, $mic_default_on, $blur_default_on, $noise_cancel, $echo_cancel, $call_quality, $share_audio]);
            
            $call_settings = [
                'ringtone' => $ringtone,
                'camera_default_on' => $camera_default_on,
                'mic_default_on' => $mic_default_on,
                'blur_default_on' => $blur_default_on,
                'noise_cancel' => $noise_cancel,
                'echo_cancel' => $echo_cancel,
                'call_quality' => $call_quality,
                'share_audio' => $share_audio
            ];
            $success_message = '通話設定を保存しました';
        } catch (Exception $e) {
            $error_message = '通話設定の保存に失敗しました';
        }
    }
}
?>
<!DOCTYPE html>
<!-- Settings Page Version: 2026-01-28-v2 -->
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('settings') ?> | <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?= generateFontLinks() ?>
    <link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/layout/header.css?v=<?= assetVersion('assets/css/layout/header.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css?v=<?= assetVersion('assets/css/panel-panels-unified.css', __DIR__) ?>">
    <?= generateDesignCSS($designSettings) ?>
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/pages-mobile.css">
    <link rel="stylesheet" href="assets/css/push-notifications.css">
    <style>
        :root {
            --header-height: 70px;
            --left-panel-width: 260px;
            --right-panel-width: 280px;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        /* overflow は指定しない（上パネルドロップダウンが body でクリップされないように。design_loader の body.page-settings と併用） */
        html, body { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; height: 100vh; }
        
        /* 上パネルは includes/chat/topbar.php で共通表示。設定ページでは右パネルなしのため右収納ボタンを非表示 */
        .page-settings .top-panel .toggle-right-btn { display: none !important; }
        
        /* メインコンテナ - panel-panels-unified.css で高さ・余白を統一。レイアウトは flex のまま */
        .page-settings .main-container { display: flex; }
        
        /* 左パネル（空白） - chat.phpの左パネルと同じ幅 */
        .left-spacer {
            width: var(--left-panel-width);
            background: var(--dt-panel-bg, rgba(255,255,255,0.95));
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        /* 中央パネル（設定コンテンツ） */
        .center-panel {
            flex: 1;
            display: flex;
            background: var(--dt-panel-bg, rgba(255,255,255,0.98));
            min-width: 0;
            border-radius: 16px;
            overflow: hidden;
        }
        
        /* 右パネル（空白） - chat.phpの右パネルと同じ幅 */
        .right-spacer {
            width: var(--right-panel-width);
            background: var(--dt-panel-bg, rgba(255,255,255,0.95));
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        /* 設定ページの読みやすさ：透明テーマ時もパネル・ラベル・ヒントを不透明で表示 */
        .page-settings .left-spacer,
        .page-settings .right-spacer {
            background: #f1f5f9 !important;
        }
        .page-settings .center-panel {
            background: #ffffff !important;
        }
        .page-settings .sidebar {
            background: #f8fafc !important;
            border-right: 1px solid #e2e8f0;
        }
        .page-settings .content {
            background: #ffffff !important;
            color: #1e293b !important;
            border-left: 1px solid #e2e8f0;
        }
        .page-settings .section-title {
            color: #0f172a !important;
            border-bottom-color: #e2e8f0 !important;
        }
        .page-settings .form-group label,
        .page-settings .subsection-title,
        .page-settings .toggle-desc,
        .page-settings .sound-col-name,
        .page-settings .sound-col-preview {
            color: #1e293b !important;
        }
        .page-settings .form-hint {
            color: #64748b !important;
        }
        .page-settings .nav-item {
            color: #334155 !important;
        }
        .page-settings .nav-item:hover {
            background: #f1f5f9 !important;
        }
        .page-settings .nav-item.active {
            color: #15803d !important;
            background: #dcfce7 !important;
            border-left-color: #22c55e;
        }
        .page-settings .divider {
            border-color: #e2e8f0 !important;
        }
        .page-settings .text-muted {
            color: #64748b !important;
        }
        
        /* 設定レイアウト */
        .settings-layout {
            display: flex;
            flex: 1;
        }
        
        .sidebar {
            width: 200px;
            background: var(--dt-sidebar-bg, #f8f9fa);
            border-right: 1px solid var(--dt-card-border, #e2e8f0);
            padding: 16px 0;
            flex-shrink: 0;
        }
        
        .nav-item {
            display: block;
            width: 100%;
            padding: 12px 24px;
            border: none;
            background: transparent;
            text-align: left;
            font-size: 14px;
            color: var(--dt-text-primary, #1e293b);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .nav-item:hover {
            background: var(--dt-card-hover-bg, rgba(0, 0, 0, 0.03));
        }
        
        .nav-item.active {
            color: var(--dt-accent, #22c55e);
            background: rgba(34, 197, 94, 0.08);
            font-weight: 600;
            border-left: 3px solid var(--dt-accent, #22c55e);
        }
        
        .content {
            flex: 1;
            padding: 32px 40px;
            background: var(--dt-card-bg, #ffffff);
            color: var(--dt-text-primary, #1e293b);
            overflow-y: auto;
            max-height: calc(100vh - var(--header-height) - 40px);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-light);
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert.success { background: #dcfce7; color: #166534; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        
        .form-row {
            display: flex;
            gap: 24px;
            margin-bottom: 20px;
        }
        
        .form-group {
            flex: 1;
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s;
            background: white;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }
        
        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-danger:hover { background: #dc2626; }
        
        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text);
            border: 1px solid var(--border-light);
        }
        .btn-secondary:hover { background: var(--border-light); }
        
        .divider {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 32px 0;
        }
        
        .toggle-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .toggle-item:last-child {
            border-bottom: none;
        }
        
        .toggle-item span {
            font-size: 14px;
        }
        
        .toggle-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .toggle-label {
            font-size: 14px;
            font-weight: 500;
        }
        
        .toggle-desc {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .toggle-switch {
            position: relative;
            width: 48px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #ccc;
            border-radius: 26px;
            transition: 0.3s;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background: var(--primary);
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(22px);
        }
        
        /* ラジオボタンオプション */
        .time-format-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid rgba(0, 0, 0, 0.1);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .radio-option:hover {
            background: rgba(255, 255, 255, 1);
            border-color: rgba(16, 185, 129, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .radio-option input[type="radio"] {
            width: 20px;
            height: 20px;
            accent-color: #10b981;
            flex-shrink: 0;
        }
        
        .radio-option input[type="radio"]:checked + .radio-label {
            font-weight: 600;
            color: #10b981;
        }
        
        .radio-option:has(input:checked) {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.08);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }
        
        .radio-label {
            font-size: 15px;
            flex: 1;
            color: #1a1a2e;
            font-weight: 500;
        }
        
        .radio-example {
            font-size: 12px;
            color: #6b7280;
            background: rgba(107, 114, 128, 0.12);
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        /* セクション区切り */
        .settings-divider {
            height: 1px;
            background: var(--border-light, #e5e5e5);
            margin: 24px 0;
        }
        
        .subsection-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* 友だち管理タブ */
        .friends-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid rgba(0,0,0,0.1);
            padding-bottom: 12px;
        }
        
        .friends-tab {
            padding: 10px 16px;
            background: rgba(255,255,255,0.9);
            border: none;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #4b5563;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1.3;
            min-height: 48px;
        }
        
        .friends-tab span {
            display: block;
        }
        
        .friends-tab:hover {
            background: rgba(255,255,255,1);
            color: #10b981;
        }
        
        .friends-tab.active {
            background: #10b981;
            color: white;
        }
        
        .friends-tab-content {
            display: none;
        }
        
        .friends-tab-content.active {
            display: block;
        }
        
        .friends-search {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        /* 友だち候補ボタン */
        .friend-suggestions-section {
            margin-bottom: 20px;
        }
        
        .btn-friend-suggestions {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-friend-suggestions:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }
        
        .btn-friend-suggestions:active {
            transform: translateY(0);
        }
        
        .btn-friend-suggestions .btn-icon {
            font-size: 20px;
        }
        
        .btn-friend-suggestions .btn-text {
            flex: 1;
            text-align: left;
        }
        
        .btn-friend-suggestions .btn-arrow {
            font-size: 18px;
            opacity: 0.8;
        }
        
        /* 友だち候補モーダル */
        .suggestions-modal-content {
            max-width: 500px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .suggestions-permission {
            text-align: center;
            padding: 40px 20px;
        }
        
        .suggestions-permission-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .suggestions-permission h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        
        .suggestions-permission p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        
        .suggestions-privacy-note {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #166534;
        }
        
        .suggestions-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .suggestions-buttons .btn {
            min-width: 120px;
        }
        
        /* 招待ボタン */
        .btn-invite {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-invite.sms {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .btn-invite.email {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-invite:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .btn-invite:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-invite.sent {
            background: #9ca3af;
            cursor: default;
        }
        
        .contact-type-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e5e7eb;
            color: #4b5563;
            margin-left: 8px;
        }
        
        .contact-type-badge.registered {
            background: #dcfce7;
            color: #166534;
        }
        
        .suggestions-loading {
            text-align: center;
            padding: 40px 20px;
        }
        
        .suggestions-loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e5e7eb;
            border-top-color: #10b981;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .suggestions-results {
            padding: 20px;
        }
        
        .suggestions-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .suggestions-header-icon {
            font-size: 32px;
        }
        
        .suggestions-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .suggestions-header p {
            font-size: 13px;
            color: #6b7280;
        }
        
        .suggestions-list {
            max-height: 400px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .suggestion-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        
        .suggestion-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .suggestion-info {
            flex: 1;
            min-width: 0;
        }
        
        .suggestion-name {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 2px;
        }
        
        .suggestion-email {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .suggestion-actions {
            display: flex;
            gap: 8px;
        }
        
        .suggestion-actions .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }
        
        .no-suggestions {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .no-suggestions-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .suggestions-method-toggle {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .method-btn {
            flex: 1;
            padding: 12px;
            background: #f3f4f6;
            border: 2px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        
        .method-btn:hover {
            background: #e5e7eb;
        }
        
        .method-btn.active {
            background: #ecfdf5;
            border-color: #10b981;
        }
        
        .method-btn-icon {
            font-size: 24px;
            margin-bottom: 4px;
        }
        
        .method-btn-text {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
        }
        
        .friends-search .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            font-size: 14px;
            background: rgba(255,255,255,0.95);
            color: #1a1a2e;
        }
        
        .friends-search .search-input:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .friends-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .friend-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.08);
            transition: all 0.2s;
        }
        
        .friend-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .friend-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: 600;
            position: relative;
            flex-shrink: 0;
        }
        
        .friend-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .online-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 3px solid white;
        }
        
        .online-indicator.online { background: #22c55e; }
        .online-indicator.away { background: #f59e0b; }
        .online-indicator.offline { background: #6b7280; }
        
        .friend-info {
            flex: 1;
            min-width: 0;
        }
        
        .friend-name {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        
        .friend-status {
            font-size: 13px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .friend-status .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .friend-actions {
            display: flex;
            gap: 8px;
        }
        
        .friend-action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .friend-action-btn.primary {
            background: #10b981;
            color: white;
        }
        
        .friend-action-btn.primary:hover {
            background: #059669;
        }
        
        .friend-action-btn.danger {
            background: #ef4444;
            color: white;
        }
        
        .friend-action-btn.danger:hover {
            background: #dc2626;
        }
        
        .friend-action-btn.secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .friend-action-btn.secondary:hover {
            background: #d1d5db;
        }
        
        .loading-text, .empty-text {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            font-size: 14px;
        }
        
        /* 連絡先インポート */
        .import-options {
            display: flex;
            gap: 16px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .import-option {
            flex: 1;
            min-width: 200px;
        }
        
        .import-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px 24px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .import-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        /* モーダル */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #1a1a2e;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
        }
        
        /* データセクション */
        .data-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }
        
        .data-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.08);
        }
        
        .data-stat-icon {
            font-size: 28px;
        }
        
        .data-stat-info {
            display: flex;
            flex-direction: column;
        }
        
        .data-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .data-stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .data-action-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 20px;
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.08);
            margin-bottom: 12px;
        }
        
        .data-action-info {
            flex: 1;
        }
        
        .data-action-title {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        
        .data-action-desc {
            font-size: 13px;
            color: #6b7280;
        }
        
        .danger-zone {
            background: rgba(239, 68, 68, 0.05);
            border: 2px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 20px;
        }
        
        .danger-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        
        .danger-info {
            flex: 1;
        }
        
        .danger-title {
            font-size: 15px;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 4px;
        }
        
        .danger-desc {
            font-size: 13px;
            color: #6b7280;
        }
        
        /* 法的文書モーダル */
        .legal-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 20px;
        }
        
        .legal-modal-content {
            background: #1a1a2e !important;
            border-radius: 16px;
            max-width: 700px;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        .legal-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.3) !important;
        }
        
        .legal-modal-header h3 {
            margin: 0;
            font-size: 20px;
            color: #ffffff !important;
        }
        
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.7) !important;
            line-height: 1;
            padding: 0;
        }
        
        .modal-close-btn:hover {
            color: #ffffff !important;
        }
        
        .legal-modal-body {
            padding: 24px;
            overflow-y: auto;
            color: #e5e7eb !important;
            line-height: 1.8;
            background: #1a1a2e !important;
        }
        
        .legal-modal-body h4 {
            color: #ffffff !important;
            font-size: 16px;
            margin: 24px 0 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #6366f1;
        }
        
        .legal-modal-body h4:first-of-type {
            margin-top: 0;
        }
        
        .legal-modal-body p {
            margin: 0 0 12px;
            font-size: 14px;
            color: #d1d5db !important;
        }
        
        .legal-modal-body ul, .legal-modal-body ol {
            margin: 0 0 16px;
            padding-left: 24px;
        }
        
        .legal-modal-body li {
            margin-bottom: 8px;
            font-size: 14px;
            color: #d1d5db !important;
        }
        
        .legal-modal-body strong {
            color: #ffffff !important;
        }
        
        .legal-update {
            font-size: 13px;
            color: #9ca3af !important;
            background: rgba(255, 255, 255, 0.1) !important;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 20px !important;
        }
        
        .legal-modal-body .terms-intro {
            background: linear-gradient(135deg, rgba(99,102,241,0.2) 0%, rgba(139,92,246,0.2) 100%) !important;
            border-left-color: #6366f1 !important;
        }
        
        .legal-modal-body .terms-intro h4 {
            border-bottom: none !important;
            padding-bottom: 0 !important;
        }
        
        .legal-modal-body .terms-intro p {
            color: #e5e7eb !important;
        }
        
        .legal-modal-body .terms-section {
            background: rgba(99, 102, 241, 0.15) !important;
        }
        
        .legal-modal-body table td {
            color: #d1d5db !important;
        }
        
        /* セレクトボックス */
        .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-light, #ddd);
            border-radius: 8px;
            font-size: 14px;
            background: var(--bg-secondary, #f9f9f9);
            color: var(--text, #333);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .shortcut-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .shortcut-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .shortcut-item:last-child {
            border-bottom: none;
        }
        
        .shortcut-item kbd {
            background: #f1f1f1;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-family: monospace;
            border: 1px solid #ddd;
        }
        
        .info-text {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.8;
            margin-bottom: 12px;
        }
        
        .link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .link-btn:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 1024px) {
            .right-spacer { display: none; }
        }
        
        @media (max-width: 768px) {
            html, body {
                height: auto !important;
                overflow: auto !important;
                overflow-x: hidden !important;
                width: 100% !important;
            }
            .left-spacer { display: none; }
            .right-spacer { display: none; }
            .main-container {
                height: auto !important;
                min-height: calc(100vh - var(--header-height) - 16px);
                overflow: visible !important;
                padding: 0 4px 8px 4px !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .center-panel {
                overflow: visible !important;
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 8px !important;
            }
            .settings-layout {
                flex-direction: column;
                height: auto;
                min-height: auto;
                width: 100% !important;
            }
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-light);
                display: flex;
                overflow-x: auto;
                padding: 0;
                flex-shrink: 0;
            }
            .nav-item {
                white-space: nowrap;
                padding: 14px 16px;
                border-left: none !important;
                font-size: 13px;
            }
            .nav-item.active {
                border-bottom: 3px solid var(--primary);
            }
            .content {
                padding: 16px 12px;
                padding-bottom: 100px;
                max-height: none !important;
                overflow-y: visible !important;
                overflow-x: hidden !important;
                height: auto !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            .form-group {
                width: 100% !important;
                max-width: 100% !important;
            }
            .form-group input,
            .form-group select,
            .form-group textarea {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .section-title {
                font-size: 18px;
            }
            .form-section-title {
                font-size: 15px;
            }
        }
        
        /* 言語変更リロードバナー */
        .lang-reload-banner {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .lang-reload-banner button {
            background: white;
            color: #1d4ed8;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .lang-reload-banner button:hover {
            background: #f0f0f0;
            transform: scale(1.02);
        }
    </style>
    <!-- QRコード生成ライブラリ (qrcodejs: ブラウザ用QRCodeコンストラクタ) -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="page-settings style-<?= htmlspecialchars($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE) ?>" data-theme="<?= htmlspecialchars($designSettings['theme'] ?? DESIGN_DEFAULT_THEME) ?>">
    <?php
    $topbar_back_url = 'chat.php';
    $topbar_header_id = 'topPanel';
    include __DIR__ . '/includes/chat/topbar.php';
    ?>
    <!-- メインコンテナ - トップページと同じ上パネル構造 -->
    <div class="main-container">
        <!-- 左パネル（空白スペーサー） -->
        <aside class="left-spacer"></aside>
        
        <!-- 中央パネル（設定コンテンツ） -->
        <main class="center-panel">
            <div class="settings-layout">
                <nav class="sidebar">
                    <a href="?section=basic" class="nav-item <?= $current_section === 'basic' ? 'active' : '' ?>"><?= __('settings_basic') ?></a>
                    <a href="?section=notification" class="nav-item <?= $current_section === 'notification' ? 'active' : '' ?>"><?= __('settings_notification') ?></a>
                    <a href="?section=talk" class="nav-item <?= $current_section === 'talk' ? 'active' : '' ?>"><?= __('settings_talk') ?></a>
                    <a href="?section=ringtone" class="nav-item <?= $current_section === 'ringtone' ? 'active' : '' ?>">着信音</a>
                    <a href="?section=call" class="nav-item <?= $current_section === 'call' ? 'active' : '' ?>"><?= __('settings_call') ?></a>
                    <a href="?section=calendar" class="nav-item <?= $current_section === 'calendar' ? 'active' : '' ?>">📅 グーグルアカウントと連携</a>
                    <a href="?section=sheets" class="nav-item <?= $current_section === 'sheets' ? 'active' : '' ?>">📊 スプレッドシート</a>
                    <a href="?section=friends" class="nav-item <?= $current_section === 'friends' ? 'active' : '' ?>"><?= __('settings_friends') ?></a>
                    <a href="?section=privacy" class="nav-item <?= $current_section === 'privacy' ? 'active' : '' ?>">🔒 プライバシー</a>
                    <a href="?section=parental" class="nav-item <?= $current_section === 'parental' ? 'active' : '' ?>">👨‍👩‍👧 保護者機能</a>
                    <a href="?section=data" class="nav-item <?= $current_section === 'data' ? 'active' : '' ?>"><?= __('settings_data') ?></a>
                    <a href="?section=advanced" class="nav-item <?= $current_section === 'advanced' ? 'active' : '' ?>"><?= __('settings_advanced') ?></a>
                    <a href="?section=shortcuts" class="nav-item <?= $current_section === 'shortcuts' ? 'active' : '' ?>"><?= __('settings_shortcuts') ?></a>
                    <a href="?section=about" class="nav-item <?= $current_section === 'about' ? 'active' : '' ?>"><?= __('settings_about') ?></a>
                </nav>
                
                <div class="content">
            <?php if ($success_message): ?>
            <div class="alert success" id="settingsSuccessAlert"><?= htmlspecialchars($success_message) ?></div>
            <?php if ($current_section === 'ringtone'): ?>
            <script>(function(){ try { var c=new BroadcastChannel('social9-settings'); c.postMessage({type:'ringtone_saved'}); } catch(e){} })();</script>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            
            <?php if ($current_section === 'basic'): ?>
            <!-- 基本設定 -->
            <h2 class="section-title"><?= __('settings_basic') ?></h2>
            <form method="POST" id="basicSettingsForm">
                <input type="hidden" name="action" value="update_basic">
                <input type="hidden" name="avatar_path" id="avatarPathInput" value="">
                
                <!-- アバター設定（変更はチャット画面のユーザーメニューから） -->
                <div class="form-group avatar-settings">
                    <label><?= __('avatar') ?></label>
                    <div class="avatar-preview-container">
                        <div class="avatar-preview" id="userAvatarPreview">
                            <?php if (!empty($user['avatar_path'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar_path']) ?>" alt="Avatar">
                            <?php else: ?>
                                <?= mb_substr($user['display_name'] ?? 'U', 0, 1) ?>
                            <?php endif; ?>
                        </div>
                        <span class="text-muted" style="font-size: 12px;"><?= $currentLang === 'en' ? 'Change from chat screen user menu' : ($currentLang === 'zh' ? '从聊天屏幕用户菜单更改' : 'チャット画面のユーザーメニューから変更できます') ?></span>
                    </div>
                </div>
                
                <hr class="divider">
                
                <!-- 名前 -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= __('full_name') ?></label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="奈良健太郎">
                        <div class="form-hint"><?= __('full_name_hint') ?></div>
                    </div>
                    <div class="form-group">
                        <label><?= __('display_name') ?> <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" placeholder="Ken" required>
                        <div class="form-hint"><?= __('display_name_hint') ?></div>
                    </div>
                </div>
                
                <!-- 多言語表示名 -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= __('display_name') ?> (English)</label>
                        <input type="text" name="display_name_en" value="<?= htmlspecialchars($user['display_name_en'] ?? '') ?>" placeholder="Ken">
                    </div>
                    <div class="form-group">
                        <label><?= __('display_name') ?> (中文)</label>
                        <input type="text" name="display_name_zh" value="<?= htmlspecialchars($user['display_name_zh'] ?? '') ?>" placeholder="健">
                    </div>
                </div>
                
                <hr class="divider">
                
                <!-- 自己紹介 -->
                <div class="form-group">
                    <label><?= __('bio') ?></label>
                    <textarea name="bio" rows="3" placeholder="<?= __('bio_placeholder') ?>"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                </div>
                
                <!-- 居住地 -->
                <div class="form-row">
                    <div class="form-group">
                        <label><?= __('prefecture') ?></label>
                        <select name="prefecture">
                            <option value=""><?= __('select_prefecture') ?></option>
                            <?php foreach ($prefectures as $pref): ?>
                            <option value="<?= $pref ?>" <?= ($user['prefecture'] ?? '') === $pref ? 'selected' : '' ?>><?= $pref ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= __('city') ?></label>
                        <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>" placeholder="<?= __('city_placeholder') ?>">
                    </div>
                </div>
                
                <!-- 携帯電話 -->
                <div class="form-group">
                    <label><?= __('phone') ?></label>
                    <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="09012345678" maxlength="15" autocomplete="tel">
                    <div class="form-hint"><?= __('phone_hint') ?></div>
                </div>
                
                <hr class="divider">
                
                <!-- 言語設定 -->
                <div id="langReloadBanner" class="lang-reload-banner" style="display: none;">
                    <span id="langReloadMessage"></span>
                    <button type="button" id="langReloadBtn" onclick="location.reload()"></button>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>🌐 <?= __('language') ?></label>
                        <select name="language" id="languageSelect">
                            <option value="ja" <?= $currentLang === 'ja' ? 'selected' : '' ?>>🇯🇵 日本語</option>
                            <option value="en" <?= $currentLang === 'en' ? 'selected' : '' ?>>🇺🇸 English</option>
                            <option value="zh" <?= $currentLang === 'zh' ? 'selected' : '' ?>>🇨🇳 中文</option>
                        </select>
                        <div class="form-hint"><?= __('language_hint') ?></div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary"><?= __('save_settings') ?></button>
            </form>
            
            <!-- パスワード変更 -->
            <h2 class="section-title" style="margin-top: 40px;">🔑 パスワード変更</h2>
            <form method="POST" action="settings.php?section=basic">
                <input type="hidden" name="action" value="update_password">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>現在のパスワード</label>
                        <input type="password" name="current_password" required autocomplete="current-password">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>新しいパスワード</label>
                        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                        <div class="form-hint">8文字以上で入力してください</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>新しいパスワード（確認）</label>
                        <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">パスワードを変更</button>
            </form>
            
            <?php elseif ($current_section === 'notification'): ?>
            <!-- 通知 -->
            <h2 class="section-title">通知</h2>
            <form method="POST" action="settings.php?section=notification">
                <input type="hidden" name="action" value="update_notification">
                
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">メッセージ通知</span>
                        <span class="toggle-desc">新しいメッセージを受信したときに通知</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="notify_message" <?= $notification_settings['notify_message'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">メンション通知</span>
                        <span class="toggle-desc">@メンションやTO宛てメッセージを受信したときに通知</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="notify_mention" <?= $notification_settings['notify_mention'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">通話着信通知</span>
                        <span class="toggle-desc">音声通話・ビデオ通話の着信を通知</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="notify_call" <?= $notification_settings['notify_call'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">運営からのお知らせ</span>
                        <span class="toggle-desc">アップデート情報やメンテナンス情報などを通知</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="notify_announcement" <?= $notification_settings['notify_announcement'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 24px;">設定を保存</button>
            </form>
            
            <!-- プッシュ通知（ブラウザ・モバイル） -->
            <div class="form-section" style="margin-top: 32px;">
                <h3 class="form-section-title">🔔 プッシュ通知</h3>
                <p style="font-size: 13px; color: var(--text-muted, #6b7280); margin-bottom: 16px;">
                    ブラウザを閉じていても、新着メッセージをスマートフォンやPCに通知します。
                </p>
                
                <div id="pushStatus" class="push-status disabled">
                    プッシュ通知を確認中...
                </div>
                
                <div id="pushDeniedHelp" class="push-denied-help" style="display: none; margin-top: 12px; padding: 16px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px;">
                    <strong>📌 Chromeで通知を有効にする手順：</strong>
                    <ol style="margin: 12px 0 0 16px; padding-left: 8px; font-size: 13px; line-height: 1.8;">
                        <li>アドレスバー左の鍵アイコン（🔒）または「接続は保護されています」をクリック</li>
                        <li>「通知」の右側をクリック</li>
                        <li>「許可」を選択</li>
                        <li>このページを再読み込み（F5）</li>
                    </ol>
                    <p style="margin: 8px 0 0; font-size: 12px; color: #92400e;">※ 一度「ブロック」するとブラウザが再度許可を求めないため、上記の手順で手動で変更してください。</p>
                </div>
                
                <div class="push-actions" style="margin-top: 12px;">
                    <button id="pushEnableBtn" class="btn btn-primary" onclick="enablePushNotifications()" style="display: none;">
                        🔔 プッシュ通知を有効にする
                    </button>
                    <button id="pushDisableBtn" class="btn btn-secondary" onclick="disablePushNotifications()" style="display: none;">
                        プッシュ通知を無効にする
                    </button>
                    <button id="pushTestBtn" class="btn btn-secondary" onclick="testPushNotification()" style="display: none; margin-left: 8px;">
                        テスト通知を送信
                    </button>
                </div>
            </div>
            
            <?php elseif ($current_section === 'talk'): ?>
            <!-- トーク -->
            <h2 class="section-title">トーク</h2>
            <form method="POST" action="settings.php?section=talk">
                <input type="hidden" name="action" value="update_talk">
                
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">入力中を表示</span>
                        <span class="toggle-desc">メッセージ入力中であることを相手に表示</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="show_typing" <?= $talk_settings['show_typing'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">リンクプレビューを表示</span>
                        <span class="toggle-desc">URLを送信したときにプレビューを自動生成</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="show_link_preview" <?= $talk_settings['show_link_preview'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">メッセージ送信音</span>
                        <span class="toggle-desc">メッセージ送信時にサウンドを再生</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="message_sound" <?= $talk_settings['message_sound'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
                
                <div class="form-group" style="margin-top: 24px;">
                    <label class="toggle-label">時刻表示形式</label>
                    <span class="toggle-desc" style="display: block; margin-bottom: 12px;">メッセージの時刻表示形式を選択</span>
                    <div class="time-format-options">
                        <label class="radio-option">
                            <input type="radio" name="time_format" value="24h" <?= $talk_settings['time_format'] === '24h' ? 'checked' : '' ?>>
                            <span class="radio-label">24時間表示</span>
                            <span class="radio-example">例: 14:30</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="time_format" value="12h" <?= $talk_settings['time_format'] === '12h' ? 'checked' : '' ?>>
                            <span class="radio-label">12時間表示</span>
                            <span class="radio-example">例: 午後2:30</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 24px;">設定を保存</button>
            </form>
            
            <?php elseif ($current_section === 'ringtone'): ?>
            <?php
            $ringtone_paths = [];
            foreach (ringtone_valid_sound_ids() as $id) {
                $resolved = ringtone_resolve_sound_id($id);
                $ringtone_paths[$id] = ($resolved === 'silent') ? '' : ringtone_sound_path($resolved);
            }
            ?>
            <script>window.__RINGTONE_PATHS = <?= json_encode($ringtone_paths) ?>;</script>
            <!-- 着信音 -->
            <h2 class="section-title">着信音</h2>
            <form method="POST" action="settings.php?section=ringtone">
                <input type="hidden" name="action" value="update_ringtone">
                
                <h3 class="subsection-title" style="margin-top: 0;">着信音が鳴る条件</h3>
                <p class="toggle-desc" style="margin-bottom: 16px;">チャットでメッセージを受信したときに、どのタイミングで着信音を鳴らすか選択します。</p>
                
                <div class="form-group notification-trigger-group" style="margin-bottom: 24px;">
                    <label class="toggle-label">パソコン版</label>
                    <div class="notification-trigger-options">
                        <?php foreach ($valid_notification_triggers as $val => $lbl): ?>
                        <label class="radio-option">
                            <input type="radio" name="notification_trigger_pc" value="<?= htmlspecialchars($val) ?>" <?= $notification_trigger_pc === $val ? 'checked' : '' ?>>
                            <span class="radio-label"><?= htmlspecialchars($lbl) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group notification-trigger-group" style="margin-bottom: 32px;">
                    <label class="toggle-label">携帯版</label>
                    <div class="notification-trigger-options">
                        <?php foreach ($valid_notification_triggers as $val => $lbl): ?>
                        <label class="radio-option">
                            <input type="radio" name="notification_trigger_mobile" value="<?= htmlspecialchars($val) ?>" <?= $notification_trigger_mobile === $val ? 'checked' : '' ?>>
                            <span class="radio-label"><?= htmlspecialchars($lbl) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <h3 class="subsection-title">着信音（自分宛メッセージ）</h3>
                <div class="form-group sound-preview-form" style="max-width: 640px; margin-bottom: 32px;">
                    <span class="toggle-desc" style="display: block; margin-bottom: 8px;">クリックで試聴・選択（ファイルを1回再生）。適用後は「設定を保存」で保存します。</span>
                    <p style="margin-bottom: 12px;"><button type="button" id="ringtoneTestBtn" class="btn btn-secondary btn-sm">🔔 着信音テスト（選択中の音を再生）</button></p>
                    <input type="hidden" name="notification_sound" id="notificationSoundInput" value="<?= htmlspecialchars($notification_sound) ?>">
                    <div class="sound-preview-table">
                        <?php foreach ($valid_notification_sounds as $value => $label): ?>
                        <div class="sound-preview-row" data-preset="<?= htmlspecialchars($value) ?>">
                            <span class="sound-col-name"><?= htmlspecialchars($label) ?></span>
                            <span class="sound-col-preview">試聴</span>
                            <div class="sound-col-btns">
                                <?php if ($value === 'silent'): ?>
                                <button type="button" class="btn btn-sm btn-secondary btn-sound-silent<?= $notification_sound === 'silent' ? ' selected' : '' ?>" data-input="notificationSoundInput" data-preset="silent">無音</button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-secondary btn-sound-preview<?= $notification_sound === $value ? ' selected' : '' ?>" data-input="notificationSoundInput" data-preset="<?= htmlspecialchars($value) ?>">試聴</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <h3 class="subsection-title">着信音（通話）</h3>
                <div class="form-group sound-preview-form" style="max-width: 640px;">
                    <span class="toggle-desc" style="display: block; margin-bottom: 8px;">クリックで試聴・選択（ファイルを1回再生）。適用後は「設定を保存」で保存します。</span>
                    <input type="hidden" name="ringtone" id="ringtoneInput" value="<?= htmlspecialchars($call_settings['ringtone']) ?>">
                    <div class="sound-preview-table sound-preview-call">
                        <?php
                        $cr = $call_settings['ringtone'] ?? 'default';
                        foreach ($valid_ringtones as $rval => $rlabel):
                        ?>
                        <div class="sound-preview-row" data-preset="<?= htmlspecialchars($rval) ?>">
                            <span class="sound-col-name"><?= htmlspecialchars($rlabel) ?></span>
                            <span class="sound-col-preview">試聴</span>
                            <div class="sound-col-btns">
                                <?php if ($rval === 'silent'): ?>
                                <button type="button" class="btn btn-sm btn-secondary btn-sound-silent<?= $cr === 'silent' ? ' selected' : '' ?>" data-input="ringtoneInput" data-preset="silent">無音</button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-secondary btn-sound-preview<?= $cr === $rval ? ' selected' : '' ?>" data-input="ringtoneInput" data-preset="<?= htmlspecialchars($rval) ?>">試聴</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="button" id="btnTestMessageSound" class="btn btn-secondary btn-test-sound" style="margin-top: 16px; margin-right: 8px;">メッセージ着信音をテスト</button>
                <button type="button" id="btnTestCallSound" class="btn btn-secondary btn-test-sound" style="margin-top: 16px; margin-right: 8px;">通話着信音をテスト</button>
                <button type="submit" class="btn btn-primary" style="margin-top: 24px;">設定を保存</button>
            </form>
            
            <?php elseif ($current_section === 'call'): ?>
            <!-- 通話 -->
            <h2 class="section-title">通話</h2>
            <form method="POST" action="settings.php?section=call">
                <input type="hidden" name="action" value="update_call">
                
                <h3 class="subsection-title" style="margin-top: 0;">通話開始時の設定</h3>
                
            <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">カメラをデフォルトでON</span>
                        <span class="toggle-desc">ビデオ通話開始時にカメラを自動でオンにする</span>
                    </div>
                <label class="toggle-switch">
                        <input type="checkbox" name="camera_default_on" <?= $call_settings['camera_default_on'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">マイクをデフォルトでON</span>
                        <span class="toggle-desc">通話開始時にマイクを自動でオンにする</span>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="mic_default_on" <?= $call_settings['mic_default_on'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <div class="toggle-item">
                    <div class="toggle-info">
                        <span class="toggle-label">背景ぼかしをデフォルトでON</span>
                        <span class="toggle-desc">ビデオ通話時に背景を自動でぼかす</span>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="blur_default_on" <?= $call_settings['blur_default_on'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
                
                <div class="settings-divider"></div>
                <h3 class="subsection-title">通話品質</h3>
                
                <div class="form-group">
                    <span class="toggle-desc" style="display: block; margin-bottom: 12px;">通信環境に合わせて選択してください</span>
                    <div class="time-format-options">
                        <label class="radio-option">
                            <input type="radio" name="call_quality" value="high" <?= $call_settings['call_quality'] === 'high' ? 'checked' : '' ?>>
                            <span class="radio-label">高画質</span>
                            <span class="radio-example">Wi-Fi推奨</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="call_quality" value="standard" <?= $call_settings['call_quality'] === 'standard' ? 'checked' : '' ?>>
                            <span class="radio-label">標準</span>
                            <span class="radio-example">バランス重視</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="call_quality" value="low" <?= $call_settings['call_quality'] === 'low' ? 'checked' : '' ?>>
                            <span class="radio-label">低画質</span>
                            <span class="radio-example">データ節約</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 24px;">設定を保存</button>
            </form>
            
            <?php elseif ($current_section === 'calendar'): ?>
            <!-- グーグルアカウントと連携（カレンダー等） -->
            <h2 class="section-title">📅 グーグルアカウントと連携</h2>
            <p class="calendar-desc" style="margin-bottom: 20px; color: var(--text-secondary);">
                グーグルアカウントと連携すると、あなたの秘書が「〇〇カレンダーに予定を入れて」などと指示したときにカレンダーに追加できます。カレンダーに名前を付けて管理してください。
            </p>
            <?php if (function_exists('getGoogleCalendarRedirectUriForDisplay') && function_exists('isGoogleCalendarEnabled') && isGoogleCalendarEnabled()): ?>
            <div class="calendar-redirect-uri-hint" style="margin-bottom: 16px; padding: 12px; background: var(--bg-secondary, #f5f5f5); border-radius: 8px; font-size: 13px;">
                <strong>Google Cloud Console に登録するリダイレクトURI：</strong><br>
                <code style="word-break: break-all; display: block; margin-top: 6px;"><?= htmlspecialchars(getGoogleCalendarRedirectUriForDisplay()) ?></code>
                <span style="display: block; margin-top: 6px; color: var(--text-secondary);">redirect_uri_mismatch の場合は、上記を承認済みリダイレクトURIに追加してください。</span>
            </div>
            <?php endif; ?>
            <?php if (!empty($_GET['error'])): ?>
            <div class="alert error"><?php
                $err = $_GET['error'];
                $errMsg = [
                    'display_name_required' => 'カレンダー名を入力してください',
                    'display_name_too_long' => '名前は50文字以内で入力してください',
                    'calendar_not_configured' => 'Googleアカウント連携が設定されていません（管理者に連絡してください）',
                    'user_denied' => '連携がキャンセルされました',
                    'token_failed' => '認証に失敗しました',
                    'no_email' => 'メールアドレスを取得できませんでした',
                    'invalid_callback' => '認証コールバックに失敗しました',
                    'state_mismatch' => 'セッションが無効です。再度お試しください',
                    'invalid_state' => 'パラメータが不正です',
                    'login_required' => 'ログインが必要です',
                    'calendar_callback_failed' => 'カレンダー連携中にエラーが発生しました。リダイレクトURIがGoogle Consoleの設定と一致しているか確認してください。',
                ];
                echo htmlspecialchars($errMsg[$err] ?? 'エラーが発生しました');
            ?></div>
            <?php endif; ?>
            <?php if (!empty($_GET['success']) && $_GET['success'] === 'calendar_connected'): ?>
            <div class="alert success">カレンダーを連携しました</div>
            <?php endif; ?>
            <?php if (function_exists('isGoogleCalendarEnabled') && isGoogleCalendarEnabled()): ?>
            <div class="calendar-accounts-section">
                <?php foreach ($calendar_accounts as $cal): ?>
                <div class="calendar-account-card" data-id="<?= (int)$cal['id'] ?>">
                    <div class="calendar-account-info">
                        <span class="calendar-account-name"><?= htmlspecialchars($cal['display_name']) ?></span>
                        <?php if ((int)$cal['is_default']): ?><span class="calendar-default-badge">★デフォルト</span><?php endif; ?>
                        <span class="calendar-account-email"><?= htmlspecialchars($cal['google_email']) ?></span>
                    </div>
                    <div class="calendar-account-actions">
                        <button type="button" class="btn-calendar-rename" data-id="<?= (int)$cal['id'] ?>" data-name="<?= htmlspecialchars($cal['display_name']) ?>">名前変更</button>
                        <?php if (!(int)$cal['is_default']): ?>
                        <button type="button" class="btn-calendar-default" data-id="<?= (int)$cal['id'] ?>">デフォルト</button>
                        <?php endif; ?>
                        <button type="button" class="btn-calendar-disconnect" data-id="<?= (int)$cal['id'] ?>">切断</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="calendar-add-area">
                    <form method="POST" action="api/google-calendar-auth.php" class="calendar-add-form">
                        <input type="text" name="display_name" class="calendar-name-input" placeholder="カレンダー名（例: 岡崎西カレンダー）" required maxlength="50">
                        <button type="submit" class="btn btn-primary">+ カレンダーを追加</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="alert">Googleアカウント連携は管理者による設定が必要です。</div>
            <?php endif; ?>
            
            <?php elseif ($current_section === 'sheets'): ?>
            <!-- Googleスプレッドシート連携 -->
            <h2 class="section-title">📊 Googleスプレッドシート</h2>
            <p style="margin-bottom: 20px; color: var(--text-secondary);">
                あなたの秘書に「スプレッドシートのA1に〇〇を入れて」などと指示すると、連携したGoogleアカウントのスプレッドシートを編集できます。スプレッドシートのURLの <code>/d/</code> と <code>/edit</code> の間のIDを指定して利用します。
            </p>
            <?php
            $sheets_enabled = false;
            if (file_exists(__DIR__ . '/config/google_sheets.php')) {
                require_once __DIR__ . '/config/google_sheets.php';
                $sheets_enabled = function_exists('isGoogleSheetsEnabled') && isGoogleSheetsEnabled();
            }
            ?>
            <?php if ($sheets_enabled): ?>
            <?php if (!empty($_GET['error'])): ?>
            <div class="alert error"><?php
                $err = $_GET['error'];
                $sheetsErr = [
                    'sheets_not_configured' => 'スプレッドシート連携が設定されていません',
                    'client_unavailable' => 'Google API クライアントを読み込めません',
                    'user_denied' => '連携がキャンセルされました',
                    'token_failed' => '認証に失敗しました',
                    'no_email' => 'メールアドレスを取得できませんでした',
                    'invalid_callback' => '認証コールバックに失敗しました',
                    'state_mismatch' => 'セッションが無効です。再度お試しください',
                    'invalid_state' => 'パラメータが不正です',
                    'callback_failed' => '連携中にエラーが発生しました。',
                ];
                echo htmlspecialchars($sheetsErr[$err] ?? 'エラーが発生しました');
            ?></div>
            <?php endif; ?>
            <?php if (!empty($_GET['success']) && $_GET['success'] === 'sheets_connected'): ?>
            <div class="alert success">Googleスプレッドシートを連携しました</div>
            <?php endif; ?>
            <div class="calendar-accounts-section" style="max-width: 480px;">
                <?php if ($sheets_account): ?>
                <div class="calendar-account-card">
                    <div class="calendar-account-info">
                        <span class="calendar-account-email"><?= htmlspecialchars($sheets_account['google_email']) ?></span>
                    </div>
                    <div class="calendar-account-actions">
                        <a href="api/sheets-disconnect.php" class="btn-calendar-disconnect" onclick="return confirm('連携を解除しますか？');">切断</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="calendar-add-area">
                    <a href="api/google-sheets-auth.php" class="btn btn-primary">Googleスプレッドシートと連携</a>
                </div>
                <?php endif; ?>
            </div>
            <p style="margin-top: 16px; font-size: 13px; color: var(--text-secondary);">
                リダイレクトURI（Google Cloud Console に登録）: <code style="word-break: break-all;"><?= htmlspecialchars(function_exists('getGoogleSheetsRedirectUri') ? getGoogleSheetsRedirectUri() : (defined('APP_URL') ? rtrim(APP_URL,'/').'/api/google-sheets-callback.php' : '')) ?></code>
            </p>
            <?php else: ?>
            <div class="alert">スプレッドシート連携は管理者による設定（config/google_sheets.php）が必要です。</div>
            <?php endif; ?>
            
            <?php elseif ($current_section === 'friends'): ?>
            <!-- 個人アドレス帳（旧: 友だち管理） -->
            <h2 class="section-title">個人アドレス帳</h2>
            
            <!-- タブナビゲーション -->
            <div class="friends-tabs">
                <button class="friends-tab active" data-tab="list"><span>アドレス帳</span><span>リスト</span></button>
                <button class="friends-tab" data-tab="requests">申請</button>
                <button class="friends-tab" data-tab="blocked">ブロック</button>
                <button class="friends-tab" data-tab="import"><span>連絡先</span><span>インポート</span></button>
            </div>
            
            <!-- アドレス帳リストタブ -->
            <div class="friends-tab-content active" id="tab-list">
                <div class="friends-search">
                    <input type="text" id="friendSearchInput" placeholder="個人アドレス帳で検索..." class="search-input">
                    <button class="btn btn-primary" onclick="searchUsers()">検索</button>
                </div>
                
                <!-- アドレス帳の候補ボタン -->
                <div class="friend-suggestions-section">
                    <button class="btn-friend-suggestions" onclick="openFriendSuggestions()">
                        <span class="btn-icon">📱</span>
                        <span class="btn-text">アドレス帳の候補を見つける</span>
                        <span class="btn-arrow">→</span>
                    </button>
                </div>
                
                <div id="friendsList" class="friends-list">
                    <div class="loading-text">読み込み中...</div>
                </div>
            </div>
            
            <!-- 申請タブ -->
            <div class="friends-tab-content" id="tab-requests">
                <h3 class="subsection-title">受信した申請</h3>
                <div id="pendingRequests" class="friends-list">
                    <div class="loading-text">読み込み中...</div>
                </div>
                
                <h3 class="subsection-title" style="margin-top: 24px;">送信した申請</h3>
                <div id="sentRequests" class="friends-list">
                    <div class="loading-text">読み込み中...</div>
                </div>
            </div>
            
            <!-- ブロックタブ -->
            <div class="friends-tab-content" id="tab-blocked">
                <div id="blockedList" class="friends-list">
                    <div class="loading-text">読み込み中...</div>
                </div>
            </div>
            
            <!-- 連絡先インポートタブ -->
            <div class="friends-tab-content" id="tab-import">
                <div class="import-section">
                    <h3 class="subsection-title">連絡先をインポート</h3>
                    <p class="info-text">CSVファイルまたはvCardファイルから連絡先をインポートして、Social9に登録しているユーザーを見つけることができます。</p>
                    
                    <div class="import-options">
                        <div class="import-option">
                            <label for="csvUpload" class="import-btn">
                                📄 CSVファイルをアップロード
                            </label>
                            <input type="file" id="csvUpload" accept=".csv" onchange="uploadCSV(event)" style="display: none;">
                            <div class="form-hint">形式: 名前,メールアドレス,電話番号</div>
                        </div>
                        
                        <div class="import-option">
                            <label for="vcfUpload" class="import-btn">
                                📇 vCardファイルをアップロード
                            </label>
                            <input type="file" id="vcfUpload" accept=".vcf" onchange="uploadVCard(event)" style="display: none;">
                            <div class="form-hint">iPhoneやAndroidの連絡先エクスポート形式</div>
                        </div>
                    </div>
                    
                    <div class="settings-divider"></div>
                    
                    <h3 class="subsection-title">インポート済み連絡先</h3>
                    <div id="importedContacts" class="friends-list">
                        <div class="loading-text">読み込み中...</div>
                    </div>
                </div>
            </div>
            
            <!-- 検索結果モーダル -->
            <div id="searchResultsModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>検索結果</h3>
                        <button class="modal-close" onclick="closeSearchModal()">×</button>
                    </div>
                    <div class="modal-body">
                        <div id="searchResults" class="friends-list"></div>
                    </div>
                </div>
            </div>
            
            <!-- アドレス帳の候補モーダル -->
            <div id="friendSuggestionsModal" class="modal" style="display: none;">
                <div class="modal-content suggestions-modal-content">
                    <div class="modal-header">
                        <h3>アドレス帳の候補</h3>
                        <button class="modal-close" onclick="closeSuggestionsModal()">×</button>
                    </div>
                    <div class="modal-body" id="suggestionsModalBody">
                        <!-- 読み込み中 -->
                        <div id="suggestionsLoading" class="suggestions-loading" style="display: none;">
                            <div class="suggestions-loading-spinner"></div>
                            <p>連絡先を読み込んでいます...</p>
                        </div>
                        
                        <!-- 連絡先リスト表示 -->
                        <div id="suggestionsResults" class="suggestions-results" style="display: none;">
                            <div class="suggestions-privacy-note" style="margin-bottom: 16px;">
                                🔒 連絡先データはサーバーに保存されません。招待送信のみに使用されます。
                            </div>
                            <div class="suggestions-header">
                                <div>
                                    <h3 id="suggestionsCount">連絡先</h3>
                                    <p id="suggestionsSubtext">招待を送信して個人アドレス帳に追加しましょう</p>
                                </div>
                            </div>
                            <div id="suggestionsList" class="suggestions-list"></div>
                            <div style="margin-top: 16px; text-align: center;">
                                <button class="btn btn-secondary" onclick="closeSuggestionsModal()">閉じる</button>
                            </div>
                        </div>
                        
                        <!-- 連絡先なし -->
                        <div id="suggestionsEmpty" class="no-suggestions" style="display: none;">
                            <h3>連絡先が見つかりませんでした</h3>
                            <p>連絡先へのアクセスを許可してください。</p>
                            <button class="btn btn-secondary" onclick="closeSuggestionsModal()" style="margin-top: 16px;">閉じる</button>
                        </div>
                        
                        <!-- 招待送信完了 -->
                        <div id="inviteSent" class="suggestions-loading" style="display: none;">
                            <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
                            <h3 id="inviteSentMessage">招待を送信しました</h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($current_section === 'privacy'): ?>
            <!-- プライバシー設定 -->
            <h2 class="section-title">🔒 プライバシー設定</h2>
            <p class="info-text">検索設定やオンライン状態の公開設定を管理できます。</p>
            
            <div id="privacySettingsContainer">
                <div class="loading-text">読み込み中...</div>
            </div>
            
            <!-- 個人アドレス帳を増やす方法の案内 -->
            <div id="friendGuidanceSection" style="margin-top: 32px; padding: 20px; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-radius: 12px; border: 1px solid #7dd3fc;">
                <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #0369a1;">📱 個人アドレス帳を増やす方法</h3>
                <p style="margin: 0 0 16px 0; color: #0c4a6e; font-size: 14px; line-height: 1.6;">
                    検索を非公開にしていても、以下の方法でアドレス帳に追加することができます。
                </p>
                <div style="display: grid; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                        <span style="font-size: 24px;">🔗</span>
                        <div>
                            <div style="font-weight: 500; color: #1e3a5f;">招待リンクを送る</div>
                            <div style="font-size: 12px; color: #64748b;">LINE、メールなどで招待リンクを共有</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                        <span style="font-size: 24px;">📷</span>
                        <div>
                            <div style="font-weight: 500; color: #1e3a5f;">QRコードを見せる</div>
                            <div style="font-size: 12px; color: #64748b;">対面で相手にスキャンしてもらう</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                        <span style="font-size: 24px;">🔍</span>
                        <div>
                            <div style="font-weight: 500; color: #1e3a5f;">自分から相手を検索</div>
                            <div style="font-size: 12px; color: #64748b;">検索許可をしている相手を見つける</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                        <span style="font-size: 24px;">👥</span>
                        <div>
                            <div style="font-weight: 500; color: #1e3a5f;">グループに参加する</div>
                            <div style="font-size: 12px; color: #64748b;">同じグループのメンバーと繋がれます</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 招待リンク/QRコード -->
            <div id="myInviteSection" style="margin-top: 24px; padding: 20px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                <h3 style="margin: 0 0 16px 0; font-size: 16px; color: #334155;">📨 あなたの招待リンク</h3>
                <div style="display: flex; gap: 8px; margin-bottom: 16px;">
                    <input type="text" id="myInviteUrl" readonly class="form-input" style="flex: 1; background: white;">
                    <button class="btn btn-primary" onclick="copyMyInviteUrl()">コピー</button>
                </div>
                <div style="text-align: center;">
                    <div id="myInviteQrCode" style="display: inline-block; padding: 16px; background: white; border-radius: 8px; border: 1px solid #e2e8f0;"></div>
                    <div style="margin-top: 8px;">
                        <button class="btn btn-secondary btn-sm" onclick="downloadMyInviteQr()">QRコードをダウンロード</button>
                    </div>
                </div>
            </div>
            
            <?php elseif ($current_section === 'parental'): ?>
            <!-- 保護者機能 -->
            <h2 class="section-title">👨‍👩‍👧 保護者機能</h2>
            <p class="info-text">お子様のアカウントを管理したり、保護者とのリンクを設定できます。</p>
            
            <!-- ローディング -->
            <div id="parentalLoading" class="loading-text">読み込み中...</div>
            
            <!-- 子として：保護者リンク状態 -->
            <div id="childSection" style="display: none;">
                <div style="padding: 20px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 12px; margin-bottom: 24px;">
                    <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #92400e;">👧 あなたのアカウント</h3>
                    
                    <div id="noParentLinked" style="display: none;">
                        <p style="color: #78350f; margin-bottom: 16px;">保護者とリンクすると、保護者があなたの利用状況を確認できるようになります。</p>
                        <div class="form-group">
                            <label class="form-label">保護者のメールアドレス</label>
                            <input type="email" id="parentEmailInput" class="form-input" placeholder="parent@example.com">
                        </div>
                        <button class="btn btn-primary" onclick="requestParentLink()">リンク申請を送信</button>
                    </div>
                    
                    <div id="parentLinkPending" style="display: none;">
                        <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.7); border-radius: 8px;">
                            <span style="font-size: 24px;">⏳</span>
                            <div>
                                <div style="font-weight: 500; color: #78350f;">承認待ち</div>
                                <div id="pendingParentEmail" style="font-size: 13px; color: #92400e;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="parentLinked" style="display: none;">
                        <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.7); border-radius: 8px; margin-bottom: 12px;">
                            <span style="font-size: 24px;">✅</span>
                            <div style="flex: 1;">
                                <div style="font-weight: 500; color: #166534;">保護者とリンク済み</div>
                                <div id="linkedParentName" style="font-size: 13px; color: #15803d;"></div>
                            </div>
                        </div>
                        
                        <div id="myRestrictionsInfo" style="background: rgba(255,255,255,0.5); border-radius: 8px; padding: 12px;">
                            <div style="font-size: 13px; color: #78350f; font-weight: 500; margin-bottom: 8px;">適用されている制限:</div>
                            <div id="myRestrictionsList" style="font-size: 12px; color: #92400e;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 保護者として：子の管理 -->
            <div id="parentSection" style="display: none;">
                <div style="padding: 20px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 12px;">
                    <h3 style="margin: 0 0 16px 0; font-size: 16px; color: #1e40af;">👨‍👩‍👧 管理している子アカウント</h3>
                    
                    <div id="noChildren" style="display: none; color: #1e3a8a;">
                        <p>リンクしている子アカウントはありません。</p>
                        <p style="font-size: 13px; margin-top: 8px;">お子様のSocial9アカウントの設定画面から、あなたのメールアドレスを入力してリンク申請を送信してもらってください。</p>
                    </div>
                    
                    <div id="childrenList"></div>
                    
                    <!-- 承認待ち申請 -->
                    <div id="pendingChildRequests" style="margin-top: 16px;"></div>
                </div>
            </div>
            
            <!-- 子の制限設定モーダル -->
            <div id="restrictionModal" class="modal" style="display: none;">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3 id="restrictionModalTitle">制限設定</h3>
                        <button class="modal-close" onclick="closeRestrictionModal()">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="restrictionChildId">
                        
                        <!-- 利用時間制限 -->
                        <div class="form-group">
                            <label class="form-label">1日の利用時間上限（分）</label>
                            <input type="number" id="dailyUsageLimit" class="form-input" placeholder="空欄で無制限" min="0" max="1440">
                            <div class="form-hint">例: 60 = 1時間</div>
                        </div>
                        
                        <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="form-group">
                                <label class="form-label">利用開始時間</label>
                                <input type="time" id="usageStartTime" class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">利用終了時間</label>
                                <input type="time" id="usageEndTime" class="form-input">
                            </div>
                        </div>
                        
                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
                        
                        <!-- 機能制限 -->
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0;">
                                <div>
                                    <div style="font-weight: 500;">検索を制限</div>
                                    <div style="font-size: 12px; color: #666;">グループメンバーのみ検索可能</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="searchRestricted">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0;">
                                <div>
                                    <div style="font-weight: 500;">DMを制限</div>
                                    <div style="font-size: 12px; color: #666;">新規DMは保護者の承認が必要</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="dmRestricted">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0;">
                                <div>
                                    <div style="font-weight: 500;">グループ参加を制限</div>
                                    <div style="font-size: 12px; color: #666;">新規グループ参加は保護者の承認が必要</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="groupJoinRestricted">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0;">
                                <div>
                                    <div style="font-weight: 500;">通話を制限</div>
                                    <div style="font-size: 12px; color: #666;">音声・ビデオ通話を無効化</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="callRestricted">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button class="btn btn-secondary" onclick="closeRestrictionModal()">キャンセル</button>
                        <button class="btn btn-primary" onclick="saveRestrictions()">保存</button>
                    </div>
                </div>
            </div>
            
            <!-- 利用状況モーダル -->
            <div id="usageModal" class="modal" style="display: none;">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3 id="usageModalTitle">利用状況</h3>
                        <button class="modal-close" onclick="closeUsageModal()">×</button>
                    </div>
                    <div class="modal-body">
                        <div id="usageChart" style="min-height: 200px;"></div>
                        <div id="usageSummary" style="margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 8px;"></div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($current_section === 'data'): ?>
            <!-- データ -->
            <h2 class="section-title">データ</h2>
            <p class="info-text">キャッシュのクリアやデータのエクスポートができます。</p>
            
            <!-- データ使用量の概要 -->
            <div class="data-overview" id="dataOverview">
                <div class="data-stat">
                    <div class="data-stat-icon">💬</div>
                    <div class="data-stat-info">
                        <span class="data-stat-value" id="messageCount">-</span>
                        <span class="data-stat-label">送信メッセージ</span>
                </div>
                </div>
                <div class="data-stat">
                    <div class="data-stat-icon">⭐</div>
                    <div class="data-stat-info">
                        <span class="data-stat-value" id="wishCount">-</span>
                        <span class="data-stat-label">Wish</span>
                    </div>
                </div>
                <div class="data-stat">
                    <div class="data-stat-icon">📝</div>
                    <div class="data-stat-info">
                        <span class="data-stat-value" id="memoCount">-</span>
                        <span class="data-stat-label">メモ</span>
                    </div>
                </div>
                <div class="data-stat">
                    <div class="data-stat-icon">👥</div>
                    <div class="data-stat-info">
                        <span class="data-stat-value" id="friendCount">-</span>
                        <span class="data-stat-label">アドレス帳</span>
                    </div>
                </div>
            </div>
            
            <div class="settings-divider"></div>
            
            <!-- キャッシュ管理 -->
            <h3 class="subsection-title">キャッシュ管理</h3>
            <div class="data-action-card">
                <div class="data-action-info">
                    <div class="data-action-title">🗑️ キャッシュをクリア</div>
                    <div class="data-action-desc">
                        検索履歴とメディアアイテム（一時保存分）をリセットします。<br>
                        <span style="color: #10b981; font-size: 12px;">※ メッセージや設定などのデータは削除されません</span>
                    </div>
                </div>
                <button class="btn btn-secondary" onclick="clearCache()">クリア</button>
            </div>
            
            <div class="settings-divider"></div>
            
            <!-- データエクスポート -->
            <h3 class="subsection-title">データエクスポート</h3>
            <div class="data-action-card">
                <div class="data-action-info">
                    <div class="data-action-title">📥 データをエクスポート</div>
                    <div class="data-action-desc">アカウント情報、統計データをJSON形式でダウンロードします</div>
                </div>
                <button class="btn btn-secondary" onclick="exportData()">エクスポート</button>
            </div>
            
            <div class="data-action-card">
                <div class="data-action-info">
                    <div class="data-action-title">💬 メッセージ履歴をエクスポート</div>
                    <div class="data-action-desc">
                        自分が送信したメッセージ履歴をJSON形式でダウンロードします（バックアップ用）<br>
                        <span style="color: #6b7280; font-size: 12px;">※ ダウンロードのみで、サーバーからは削除されません</span>
                    </div>
                </div>
                <button class="btn btn-secondary" onclick="exportMessages()">エクスポート</button>
            </div>
            
            <div class="settings-divider"></div>
            
            <!-- 危険な操作 -->
            <h3 class="subsection-title" style="color: #ef4444;">⚠️ 危険な操作</h3>
            <div class="danger-zone">
                <div class="danger-card">
                    <div class="danger-info">
                        <div class="danger-title">アカウントを削除</div>
                        <div class="danger-desc">この操作は取り消せません。すべてのデータが完全に削除されます。</div>
                    </div>
            <button class="btn btn-danger" onclick="deleteAccount()">アカウントを削除</button>
                </div>
            </div>
            
            <?php elseif ($current_section === 'advanced'): ?>
            <!-- 詳細設定 -->
            <h2 class="section-title">詳細設定</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_advanced">
                
                <!-- セキュリティ設定 -->
                <h3 class="subsection-title">セキュリティ</h3>
                
                <div class="form-group">
                    <label>自動ログアウト時間</label>
                    <div class="form-hint" style="margin-bottom: 12px;">
                        一定時間操作がない場合に自動ログアウトするかどうか。通常は「自動ログアウトしない」でログインを維持します。
                    </div>
                    <div class="time-format-options">
                        <label class="radio-option">
                            <input type="radio" name="auto_logout_minutes" value="0" <?= $advanced_settings['auto_logout_minutes'] === 0 ? 'checked' : '' ?>>
                            <span class="radio-label">自動ログアウトしない</span>
                            <span class="radio-example">常にログイン状態を維持（推奨）</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="auto_logout_minutes" value="1440" <?= $advanced_settings['auto_logout_minutes'] === 1440 ? 'checked' : '' ?>>
                            <span class="radio-label">24時間でログアウト</span>
                            <span class="radio-example">24時間操作がなければログアウト</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 24px;">設定を保存</button>
            </form>
            
            <?php elseif ($current_section === 'shortcuts'): ?>
            <!-- ショートカット -->
            <h2 class="section-title"><?= __('shortcuts') ?></h2>
            
            <!-- 基本操作 -->
            <h3 class="subsection-title">基本操作</h3>
            <div class="shortcut-list">
                <div class="shortcut-item">
                    <span>メッセージを送信</span>
                    <kbd>Enter</kbd>
                </div>
                <div class="shortcut-item">
                    <span>改行</span>
                    <kbd>Shift + Enter</kbd>
                </div>
                <div class="shortcut-item">
                    <span>画像を貼り付け</span>
                    <kbd>Ctrl + V</kbd>
                </div>
            </div>
            
            <!-- ナビゲーション -->
            <h3 class="subsection-title" style="margin-top: 24px;">ナビゲーション</h3>
            <div class="shortcut-list">
                <div class="shortcut-item">
                    <span>検索を開く</span>
                    <kbd>Ctrl + K</kbd>
                </div>
                <div class="shortcut-item">
                    <span>設定を開く</span>
                    <kbd>Ctrl + ,</kbd>
                </div>
                <div class="shortcut-item">
                    <span>新しい会話</span>
                    <kbd>Ctrl + N</kbd>
                </div>
                <div class="shortcut-item">
                    <span>メモを開く</span>
                    <kbd>Ctrl + Shift + M</kbd>
                </div>
                <div class="shortcut-item">
                    <span><?= __('open_task') ?></span>
                    <kbd>Ctrl + Shift + W</kbd>
                </div>
            </div>
            
            <!-- その他 -->
            <h3 class="subsection-title" style="margin-top: 24px;">その他</h3>
            <div class="shortcut-list">
                <div class="shortcut-item">
                    <span>ショートカット一覧を表示</span>
                    <kbd>Ctrl + /</kbd>
                </div>
                <div class="shortcut-item">
                    <span>モーダルを閉じる</span>
                    <kbd>Escape</kbd>
                </div>
            </div>
            
            <div class="info-box" style="margin-top: 24px; padding: 16px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; border-left: 4px solid #10b981;">
                <p style="margin: 0; color: #10b981; font-size: 14px;">
                    💡 チャット画面で <kbd style="background: #374151; padding: 2px 6px; border-radius: 4px; font-size: 12px;">Ctrl + /</kbd> を押すと、ショートカット一覧をいつでも確認できます。
                </p>
            </div>
            
            <?php elseif ($current_section === 'about'): ?>
            <!-- Social9情報 -->
            <h2 class="section-title"><?= __('about_social9') ?></h2>
            <div style="line-height: 2;">
                <p class="info-text"><strong><?= __('version') ?>:</strong> 1.0.0</p>
                <p class="info-text"><strong><?= __('operator') ?>:</strong> Social9開発グループ</p>
            </div>
            
            <hr class="divider">
            
            <p class="info-text"><?= __('about_description') ?></p>
            
            <div style="margin-top: 24px; display: flex; gap: 16px; flex-wrap: wrap;">
                <button class="btn btn-secondary" onclick="showPrivacyPolicy()">プライバシーポリシー</button>
                <button class="btn btn-secondary" onclick="showTermsOfService()">利用規約</button>
            </div>
            
            <div class="info-box" style="margin-top: 24px; padding: 16px; background: rgba(99, 102, 241, 0.1); border-radius: 8px; border-left: 4px solid #6366f1;">
                <p style="margin: 0; color: #6366f1; font-size: 14px;">
                    💬 ご質問・ご要望はチャット画面の「あなたの秘書」からお気軽にお問い合わせください。
                </p>
            </div>
            
            <!-- プライバシーポリシーモーダル -->
            <div id="privacyModal" class="legal-modal" style="display: none;">
                <div class="legal-modal-content">
                    <div class="legal-modal-header">
                        <h3>プライバシーポリシー</h3>
                        <button onclick="closePrivacyModal()" class="modal-close-btn">×</button>
                    </div>
                    <div class="legal-modal-body">
                        <p class="legal-update">最終更新日: 2024年1月1日</p>
                        
                        <h4>1. はじめに</h4>
                        <p>Social9（以下「当サービス」）は、ユーザーのプライバシーを尊重し、個人情報の保護に努めています。本プライバシーポリシーは、当サービスがどのような情報を収集し、どのように使用・保護するかを説明します。</p>
                        
                        <h4>2. 収集する情報</h4>
                        <p>当サービスでは、以下の情報を収集することがあります：</p>
                        <ul>
                            <li><strong>アカウント情報：</strong>氏名、メールアドレス、表示名、プロフィール画像</li>
                            <li><strong>利用情報：</strong>メッセージ内容、通話履歴、利用時間、アクセスログ</li>
                            <li><strong>デバイス情報：</strong>IPアドレス、ブラウザ種類、OS情報</li>
                            <li><strong>位置情報：</strong>都道府県・市区町村（任意で入力された場合のみ）</li>
                        </ul>
                        
                        <h4>3. 情報の利用目的</h4>
                        <p>収集した情報は、以下の目的で利用します：</p>
                        <ul>
                            <li>サービスの提供・運営・改善</li>
                            <li>ユーザーサポートの提供</li>
                            <li>不正利用の防止・セキュリティの確保</li>
                            <li>サービスに関するお知らせの送信</li>
                            <li>統計データの作成（個人を特定しない形式）</li>
                        </ul>
                        
                        <h4>4. 情報の共有</h4>
                        <p>当サービスは、以下の場合を除き、ユーザーの個人情報を第三者に提供しません：</p>
                        <ul>
                            <li>ユーザーの同意がある場合</li>
                            <li>法令に基づく開示請求があった場合</li>
                            <li>人の生命・身体・財産の保護のために必要な場合</li>
                            <li>サービス提供に必要な業務委託先への提供（機密保持契約を締結）</li>
                        </ul>
                        
                        <h4>5. データの保存と保護</h4>
                        <p>当サービスは、適切な技術的・組織的措置を講じて、ユーザーの個人情報を不正アクセス、紛失、破壊、改ざん、漏洩から保護します。データは暗号化された通信（SSL/TLS）を通じて送受信され、安全なサーバーに保存されます。</p>
                        
                        <h4>6. ユーザーの権利</h4>
                        <p>ユーザーは以下の権利を有します：</p>
                        <ul>
                            <li><strong>アクセス権：</strong>自身の個人情報へのアクセスを請求できます</li>
                            <li><strong>訂正権：</strong>不正確な情報の訂正を請求できます</li>
                            <li><strong>削除権：</strong>アカウント削除により、個人情報の削除を請求できます</li>
                            <li><strong>データポータビリティ：</strong>自身のデータをエクスポートできます</li>
                        </ul>
                        
                        <h4>7. Cookie（クッキー）の使用</h4>
                        <p>当サービスでは、ユーザー体験の向上とセッション管理のためにCookieを使用します。ブラウザの設定でCookieを無効にすることも可能ですが、一部の機能が利用できなくなる場合があります。</p>
                        
                        <h4>8. 未成年者のプライバシー</h4>
                        <p>当サービスは、子どもから大人まで安心して利用できるプラットフォームを目指しています。18歳未満のユーザーは、保護者の同意を得た上でサービスをご利用ください。保護者は、組織管理機能を通じて未成年者の利用状況を管理できます。</p>
                        
                        <h4>9. プライバシーポリシーの変更</h4>
                        <p>当サービスは、必要に応じて本ポリシーを変更することがあります。重要な変更がある場合は、サービス内で通知します。</p>
                        
                        <h4>10. お問い合わせ</h4>
                        <p>プライバシーに関するご質問やご要望は、設定画面の「お問い合わせ」からご連絡ください。</p>
                    </div>
                </div>
            </div>
            
            <!-- 利用規約モーダル -->
            <div id="termsModal" class="legal-modal" style="display: none;">
                <div class="legal-modal-content">
                    <div class="legal-modal-header">
                        <h3>Social9 ご利用前のご挨拶・利用規約</h3>
                        <button onclick="closeTermsModal()" class="modal-close-btn">×</button>
                    </div>
                    <div class="legal-modal-body">
                        <div class="terms-intro" style="background: linear-gradient(135deg, rgba(99,102,241,0.1) 0%, rgba(139,92,246,0.1) 100%); padding: 24px; border-radius: 12px; margin-bottom: 24px; border-left: 4px solid #6366f1;">
                            <h4 style="color: #6366f1; margin-bottom: 16px; font-size: 18px;">「人として、人のために。」</h4>
                            <p style="line-height: 1.8; margin-bottom: 12px;">はじめに、私たちがこのアプリ「Social9」を世に送り出そうと決めた想いをお伝えさせてください。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">私たちは、すべての人々が人として、誰かのために動き、明るく豊かな社会を創っていく。そんな未来への一助となるために、このアプリの開発をスタートしました。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">正直に申し上げます。私たちはプログラミングのプロ集団ではありません。志を共にする有志が集まり、AIの力を借りて開発を進めている、知識も経験も未熟なチームです。そのため、ご利用いただく中で不具合が発生したり、ご不便をおかけしたりすることも多々あるかと思います。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">しかし、どうか知っていただきたいのです。その不具合のひとつひとつは、私たちが「より多くの方に便利で、楽しく、豊かな時間を過ごしてほしい」と願い、必死に努力を続けている、挑戦の証であることを。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">今の世の中は、一度のミスでこれまでのすべてが否定されてしまうような「マイナス評価」が溢れています。でも、本当に大切なのは、その人が毎日コツコツと積み重ねてきた「プラスの影響」ではないでしょうか。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">私たちは、その「プラス」を可視化したいのです。たとえ一つの問題が起きたとしても、「ほら、これまでにこんなにたくさんのプラスをこの社会に生んできたんだよ！」と、誰もが認め合える場所にしたい。それが「Social9」の願いです。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">もし、私たちのこの志に賛同し、背中を押してくださる方がいらっしゃいましたら、ぜひご寄付という形でお力添えをいただけますと幸いです。寄付の方法は、アプリのどこかにそっと用意しておきました。見つけていただけたなら、私たちは飛び上がるほど喜びます。</p>
                            <p style="line-height: 1.8; margin-bottom: 12px;">これからご覧いただく利用規約には、運営を継続するために厳しいことも記載しております。しかし、私たちは「Social9」を共に創っていくナインとなってくださる皆様に、心からの感謝を抱いています。</p>
                            <p style="line-height: 1.8; font-style: italic;">皆様の毎日が健やかで、より豊かなものとなりますように。感謝を込めて。</p>
                        </div>
                        
                        <h4>第1条（本サービスの内容と性質）</h4>
                        <p>本サービスは、AIを用いて開発された「試験的サービス」であり、ユーザーは未完成かつ不完全なものであるリスクを承諾して利用するものとします。</p>
                        <p>本サービスには、一定の要件を満たしたユーザーのみが利用可能な「特定投資家向けセクション」が含まれます。</p>
                        
                        <h4>第2条（非保証および免責）</h4>
                        <ul>
                            <li><strong>無保証:</strong> 弊社は、本サービスの正確性、完全性、有用性、およびウイルス等の有害要素がないことについて一切保証しません。</li>
                            <li><strong>一切の責任の否定:</strong> 弊社は、本サービスの利用から生じる一切の損害（直接・間接・データ消失・精神的苦痛等）について、予見可能性の有無を問わず一切の責任を負いません。</li>
                            <li><strong>自己責任:</strong> ユーザーは自己の責任において利用し、弊社に対していかなる請求も行わないものとします。</li>
                        </ul>
                        
                        <h4>第3条（損害賠償請求の放棄と制限）</h4>
                        <p>ユーザーは、本サービスに関連して損害を被った場合でも、一切の法的請求権を放棄するものとします。</p>
                        <p>消費者契約法が適用される場合、弊社の過失（重過失を除く）による賠償責任は、ユーザーに直接生じた通常の損害に限定され、かつ金100円を上限とします。</p>
                        
                        <h4>第4条（禁止事項）</h4>
                        <p>ユーザーは、以下の行為を行ってはなりません。違反した場合、事前の通知なくアカウント停止等の措置を講じます。</p>
                        <ul>
                            <li><strong>反社会的活動:</strong> 反社会的勢力への利益供与、またはこれらに類する行為。</li>
                            <li><strong>投資関連の禁止:</strong>
                                <ul>
                                    <li>弊社が認めた「特定投資家セクション」以外での投資勧誘、宣伝、および金融情報の拡散。</li>
                                    <li>ユーザー間での株式・持分の直接売買、譲渡の打診、またはそれらのマッチング行為。</li>
                                </ul>
                            </li>
                            <li><strong>権利侵害:</strong> 差別、誹謗中傷、著作権侵害、なりすまし行為。</li>
                            <li><strong>システム妨害:</strong> 不正アクセス、リバースエンジニアリング、脆弱性の意図的悪用。</li>
                        </ul>
                        
                        <h4>第5条（特定投資家向け機能の特則）</h4>
                        <ul>
                            <li><strong>入室要件:</strong> 特定投資家セクションの利用には、弊社が定める基準（純資産1億円以上および投資経験1年以上等）を満たし、かつ反社チェックを通過したことを条件とします。</li>
                            <li><strong>情報の正確性:</strong> ユーザーは、資産状況等の確認において真実かつ正確な情報を提供するものとします。虚偽の申告により弊社が損害を被った場合、ユーザーはその全てを賠償するものとします。</li>
                            <li><strong>資金調達の性質:</strong> 本機能は、会社法に基づき運営者が行う自己募集の管理を目的としたものであり、ユーザー間での取引の場を提供するものではありません。</li>
                        </ul>
                        
                        <h4>第6条（反社チェックと違反への処置）</h4>
                        <p>弊社は、投資実行前または定期的に、外部APIを用いた反社会的勢力チェックを実施します。</p>
                        <p>反社チェックにおいて疑義が生じた場合、弊社は催告なく即時に全ての投資権利を失効させ、アカウントを凍結できるものとします。</p>
                        
                        <h4>第7条（管理者権限と責任）</h4>
                        <p>本サービスには、組織およびグループを管理するための権限が設けられています。各管理者は、その権限を適正に行使し、他のユーザーの利益を不当に侵害しないものとします。</p>
                        
                        <div style="background: rgba(245, 158, 11, 0.1); padding: 16px; border-radius: 8px; margin: 12px 0; border-left: 4px solid #f59e0b;">
                            <h5 style="color: #f59e0b; margin-bottom: 10px;">【組織管理者の権限】</h5>
                            <p style="margin-bottom: 8px; font-size: 13px;">組織管理者とは、組織（企業、団体、家族等）を作成・管理する権限を持つユーザーです。</p>
                            <ul style="font-size: 13px; margin-left: 20px;">
                                <li>組織の作成、名称変更、削除</li>
                                <li>組織メンバーの招待、承認、削除</li>
                                <li>組織メンバーの権限変更（管理者への昇格・降格）</li>
                                <li>組織内すべてのグループの管理権限</li>
                                <li>制限付きメンバー（未成年等）の連絡先許可設定</li>
                                <li>組織全体の設定変更</li>
                            </ul>
                        </div>
                        
                        <div style="background: rgba(59, 130, 246, 0.1); padding: 16px; border-radius: 8px; margin: 12px 0; border-left: 4px solid #3b82f6;">
                            <h5 style="color: #3b82f6; margin-bottom: 10px;">【グループ管理者の権限】</h5>
                            <p style="margin-bottom: 8px; font-size: 13px;">グループ管理者とは、個別のグループチャットを管理する権限を持つユーザーです。</p>
                            <ul style="font-size: 13px; margin-left: 20px;">
                                <li>グループへのメンバー招待</li>
                                <li>グループ名の変更</li>
                                <li>グループアイコンの変更</li>
                                <li>グループからのメンバー削除（キック）</li>
                                <li>他のメンバーをグループ管理者に任命・解任</li>
                                <li>メンバーの発言制限（ミュート）の設定・解除</li>
                                <li>グループ招待リンクの生成・無効化</li>
                                <li>グループ内の他ユーザーのメッセージ削除</li>
                                <li>グループの削除</li>
                            </ul>
                        </div>
                        
                        <div style="background: rgba(239, 68, 68, 0.1); padding: 16px; border-radius: 8px; margin: 12px 0; border-left: 4px solid #ef4444;">
                            <h5 style="color: #ef4444; margin-bottom: 10px;">【管理者の責任】</h5>
                            <p style="font-size: 13px;">管理者は、以下の責任を負うものとします。</p>
                            <ul style="font-size: 13px; margin-left: 20px;">
                                <li>権限の濫用をしないこと</li>
                                <li>メンバーに対して差別的または不当な扱いをしないこと</li>
                                <li>グループ削除時は、メンバーへの影響を考慮すること</li>
                                <li>個人情報の適切な取り扱い</li>
                            </ul>
                            <p style="font-size: 13px; margin-top: 10px; font-weight: 600;">管理者による権限の濫用があった場合、弊社は当該管理者のアカウントを停止できるものとします。</p>
                        </div>
                        
                        <h4>第8条（コンテンツの権利とバックアップ）</h4>
                        <ul>
                            <li>投稿コンテンツの著作権はユーザーに帰属しますが、弊社はこれを無償かつ永続的に利用（加工、AI学習等を含む）できるものとします。</li>
                            <li>ユーザーは、弊社および継承者に対し、著作者人格権を行使しないことに同意するものとします。</li>
                            <li>弊社はデータのバックアップ義務を負わず、データ消失について一切責任を負いません。</li>
                        </ul>
                        
                        <div class="terms-section" style="background: rgba(99,102,241,0.05); padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h4 style="display: flex; align-items: center; gap: 8px;">🔒 プライバシーポリシー（投資家情報を含む）</h4>
                            <ul>
                                <li><strong>取得情報:</strong> 氏名、連絡先に加え、適格投資家確認情報（資産、経験、本人確認書類）、反社チェック結果を取得します。</li>
                                <li><strong>利用目的:</strong> サービスの維持、投資家資格の審査、反社排除、不正利用防止のために利用します。</li>
                                <li><strong>第三者提供:</strong> 法令に基づく場合や業務委託、事業承継を除き、同意なく第三者に提供しません。</li>
                            </ul>
                        </div>
                        
                        <div class="terms-section" style="background: rgba(99,102,241,0.05); padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h4 style="display: flex; align-items: center; gap: 8px;">📝 特定商取引法に基づく表記</h4>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr><td style="padding: 8px 0; font-weight: 600; width: 30%;">事業者名</td><td>株式会社Social9</td></tr>
                                <tr><td style="padding: 8px 0; font-weight: 600;">住所</td><td>〒444-0825 愛知県岡崎市福岡町菱田56-6</td></tr>
                                <tr><td style="padding: 8px 0; font-weight: 600;">責任者</td><td>代表取締役 才谷 文太</td></tr>
                                <tr><td style="padding: 8px 0; font-weight: 600;">連絡先</td><td>support-ai@social9.jp</td></tr>
                                <tr><td style="padding: 8px 0; font-weight: 600;">対価</td><td>各購入ページに表示。支払確定後のキャンセル・返金は不可。</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- 右パネル（空白スペーサー） -->
        <aside class="right-spacer"></aside>
    </div>
    
    <script>
        // ========== データセクション ==========
        
        // データ統計を読み込み
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('dataOverview')) {
                loadDataStats();
            }
        });
        
        async function loadDataStats() {
            try {
                const response = await fetch('api/settings.php?action=get_data_stats');
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('messageCount').textContent = formatNumber(data.stats.message_count);
                    document.getElementById('wishCount').textContent = formatNumber(data.stats.wish_count);
                    document.getElementById('memoCount').textContent = formatNumber(data.stats.memo_count);
                    document.getElementById('friendCount').textContent = formatNumber(data.stats.friend_count);
                }
            } catch (e) {
                console.error('Failed to load data stats:', e);
            }
        }
        
        function formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        }
        
        function clearCache() {
            if (confirm('検索履歴とメディアアイテムをクリアしますか？')) {
                // 検索履歴をクリア
                localStorage.removeItem('social9_search_history');
                localStorage.removeItem('searchHistory');
                
                // メディアアイテムをクリア（会話ごとのキーを削除）
                const keysToRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key && (key.startsWith('media_items_') || key.includes('media'))) {
                        keysToRemove.push(key);
                    }
                }
                keysToRemove.forEach(key => localStorage.removeItem(key));
                
                alert('検索履歴とメディアアイテムをクリアしました');
            }
        }
        
        // メッセージ履歴エクスポート
        async function exportMessages() {
            if (!confirm('すべてのメッセージ履歴をエクスポートしますか？\nデータ量によっては時間がかかる場合があります。')) {
                return;
            }
            
            try {
                const response = await fetch('api/settings.php?action=export_messages');
                const data = await response.json();
                
                if (data.success) {
                    const blob = new Blob([JSON.stringify(data.messages, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'social9_messages_' + new Date().toISOString().slice(0,10) + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    
                    alert(`${data.count}件のメッセージをエクスポートしました`);
                } else {
                    alert(data.error || 'エクスポートに失敗しました');
                }
            } catch (e) {
                alert('エクスポート中にエラーが発生しました');
            }
        }
        
        // ========== 個人アドレス帳機能（旧: 友だち管理） ==========
        
        // タブ切り替え
        document.querySelectorAll('.friends-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.dataset.tab;
                
                // タブのアクティブ状態切り替え
                document.querySelectorAll('.friends-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // コンテンツの表示切り替え
                document.querySelectorAll('.friends-tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById('tab-' + tabId).classList.add('active');
                
                // データ読み込み
                switch(tabId) {
                    case 'list': loadFriendsList(); break;
                    case 'requests': loadRequests(); break;
                    case 'blocked': loadBlockedList(); break;
                    case 'import': loadImportedContacts(); break;
                }
            });
        });
        
        // 初期読み込み
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('tab-list')) {
                loadFriendsList();
            }
        });
        
        // 友だちリスト読み込み
        async function loadFriendsList() {
            const container = document.getElementById('friendsList');
            if (!container) return;
            
            container.innerHTML = '<div class="loading-text">読み込み中...</div>';
            
            try {
                const response = await fetch('api/friends.php?action=list&status=accepted');
                const data = await response.json();
                
                if (data.success && data.friends.length > 0) {
                    container.innerHTML = data.friends.map(friend => renderFriendCard(friend)).join('');
                } else {
                    container.innerHTML = '<div class="empty-text">アドレス帳が空です。検索してアドレス追加申請を送りましょう。</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="empty-text">読み込みに失敗しました</div>';
            }
        }
        
        // 友だちカードのレンダリング
        function renderFriendCard(friend, type = 'friend') {
            const avatar = friend.avatar 
                ? `<img src="${escapeHtml(friend.avatar)}" alt="">` 
                : escapeHtml((friend.display_name || '?')[0].toUpperCase());
            
            let actions = '';
            if (type === 'friend') {
                actions = `
                    <button class="friend-action-btn secondary" onclick="viewProfile(${friend.user_id})">プロフィール</button>
                    <button class="friend-action-btn danger" onclick="removeFriend(${friend.user_id})">削除</button>
                `;
            } else if (type === 'pending') {
                actions = `
                    <button class="friend-action-btn primary" onclick="acceptRequest(${friend.friendship_id})">承認</button>
                    <button class="friend-action-btn danger" onclick="rejectRequest(${friend.friendship_id})">拒否</button>
                    <button class="friend-action-btn secondary" onclick="deferRequest(${friend.friendship_id})" style="margin-left:4px;">保留</button>
                    <button class="friend-action-btn danger" onclick="blockRequester(${friend.user_id}, ${JSON.stringify(friend.display_name || 'このユーザー')})" style="margin-left:4px;">ブロックする</button>
                `;
            } else if (type === 'sent') {
                actions = `
                    <button class="friend-action-btn secondary" disabled>申請中</button>
                    <button class="friend-action-btn danger" onclick="cancelSentRequest(${friend.user_id})" style="margin-left:6px;">取り消し</button>
                `;
            } else if (type === 'blocked') {
                actions = `
                    <button class="friend-action-btn primary" onclick="unblockUserNew(${friend.user_id})">解除</button>
                `;
            } else if (type === 'search') {
                if (friend.friendship_status === 'accepted') {
                    actions = `<span style="color: #10b981;">✓ アドレス帳に追加済み</span>`;
                } else if (friend.friendship_status === 'pending') {
                    actions = `<span style="color: #f59e0b;">申請中</span>`;
                } else if (friend.friendship_status === 'blocked') {
                    actions = `<span style="color: #ef4444;">ブロック中</span>`;
                } else {
                    actions = `<button class="friend-action-btn primary" onclick="addFriend(${friend.id})">アドレス追加申請</button>`;
                }
            } else if (type === 'imported') {
                if (friend.matched_user_id) {
                    actions = `<button class="friend-action-btn primary" onclick="addFriend(${friend.matched_user_id})">アドレス追加申請</button>`;
                } else {
                    actions = `<span style="color: #6b7280;">未登録</span>`;
                }
            }
            
            const statusHtml = friend.online_status ? `
                <div class="friend-status">
                    <span class="status-dot" style="background: ${friend.online_status_color}"></span>
                    <span>${friend.online_status_label}</span>
                    ${friend.online_status !== 'online' && friend.last_activity_formatted ? `<span>・${friend.last_activity_formatted}</span>` : ''}
                </div>
            ` : '';
            
            const nameToShow = friend.display_name || friend.matched_user_name || friend.contact_name || '不明';
            
            return `
                <div class="friend-card">
                    <div class="friend-avatar">
                        ${avatar}
                        ${friend.online_status ? `<div class="online-indicator ${friend.online_status}"></div>` : ''}
                    </div>
                    <div class="friend-info">
                        <div class="friend-name">${escapeHtml(nameToShow)}</div>
                        ${statusHtml}
                    </div>
                    <div class="friend-actions">
                        ${actions}
                    </div>
                </div>
            `;
        }
        
        // 申請リスト読み込み
        async function loadRequests() {
            const pendingContainer = document.getElementById('pendingRequests');
            const sentContainer = document.getElementById('sentRequests');
            
            if (!pendingContainer) return;
            
            pendingContainer.innerHTML = '<div class="loading-text">読み込み中...</div>';
            sentContainer.innerHTML = '<div class="loading-text">読み込み中...</div>';
            
            try {
                // 受信した申請
                const pendingRes = await fetch('api/friends.php?action=pending');
                const pendingData = await pendingRes.json();
                
                if (pendingData.success && pendingData.requests.length > 0) {
                    pendingContainer.innerHTML = pendingData.requests.map(r => renderFriendCard(r, 'pending')).join('');
                } else {
                    pendingContainer.innerHTML = '<div class="empty-text">受信した申請はありません</div>';
                }
                
                // 送信した申請
                const sentRes = await fetch('api/friends.php?action=sent');
                const sentData = await sentRes.json();
                
                if (sentData.success && sentData.sent.length > 0) {
                    sentContainer.innerHTML = sentData.sent.map(r => renderFriendCard(r, 'sent')).join('');
                } else {
                    sentContainer.innerHTML = '<div class="empty-text">送信した申請はありません</div>';
                }
            } catch (e) {
                pendingContainer.innerHTML = '<div class="empty-text">読み込みに失敗しました</div>';
                sentContainer.innerHTML = '<div class="empty-text">読み込みに失敗しました</div>';
            }
        }
        
        // ブロックリスト読み込み
        async function loadBlockedList() {
            const container = document.getElementById('blockedList');
            if (!container) return;
            
            container.innerHTML = '<div class="loading-text">読み込み中...</div>';
            
            try {
                const response = await fetch('api/friends.php?action=blocked');
                const data = await response.json();
                
                if (data.success && data.blocked.length > 0) {
                    container.innerHTML = data.blocked.map(b => renderFriendCard(b, 'blocked')).join('');
                } else {
                    container.innerHTML = '<div class="empty-text">ブロック中のユーザーはいません</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="empty-text">読み込みに失敗しました</div>';
            }
        }
        
        // インポート済み連絡先読み込み
        async function loadImportedContacts() {
            const container = document.getElementById('importedContacts');
            if (!container) return;
            
            container.innerHTML = '<div class="loading-text">読み込み中...</div>';
            
            try {
                const response = await fetch('api/friends.php?action=imported_contacts');
                const data = await response.json();
                
                if (data.success && data.contacts.length > 0) {
                    container.innerHTML = data.contacts.map(c => renderFriendCard(c, 'imported')).join('');
                } else {
                    container.innerHTML = '<div class="empty-text">インポートした連絡先はありません</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="empty-text">読み込みに失敗しました</div>';
            }
        }
        
        // ユーザー検索
        async function searchUsers() {
            const query = document.getElementById('friendSearchInput').value.trim();
            if (query.length < 2) {
                alert('2文字以上入力してください');
                return;
            }
            
            const modal = document.getElementById('searchResultsModal');
            const resultsContainer = document.getElementById('searchResults');
            
            modal.style.display = 'flex';
            resultsContainer.innerHTML = '<div class="loading-text">検索中...</div>';
            
            try {
                const response = await fetch(`api/friends.php?action=search&query=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.success && data.users.length > 0) {
                    resultsContainer.innerHTML = data.users.map(u => renderFriendCard(u, 'search')).join('');
                } else {
                    resultsContainer.innerHTML = '<div class="empty-text">ユーザーが見つかりませんでした</div>';
                }
            } catch (e) {
                resultsContainer.innerHTML = '<div class="empty-text">検索に失敗しました</div>';
            }
        }
        
        function closeSearchModal() {
            document.getElementById('searchResultsModal').style.display = 'none';
        }
        
        // ========== アドレス帳の候補機能（連絡先マッチ） ==========
        
        // 連絡先データを保持
        let loadedContacts = [];
        
        async function openFriendSuggestions() {
            const modal = document.getElementById('friendSuggestionsModal');
            modal.style.display = 'flex';
            
            // 状態をリセット
            document.getElementById('suggestionsLoading').style.display = 'block';
            document.getElementById('suggestionsResults').style.display = 'none';
            document.getElementById('suggestionsEmpty').style.display = 'none';
            const inviteSent = document.getElementById('inviteSent');
            if (inviteSent) inviteSent.style.display = 'none';
            
            // Contact Picker APIで連絡先を取得
            try {
                if ('contacts' in navigator && 'ContactsManager' in window) {
                    const props = ['email', 'name', 'tel'];
                    const opts = { multiple: true };
                    
                    const contacts = await navigator.contacts.select(props, opts);
                    
                    if (contacts.length > 0) {
                        await processContactsForInvite(contacts);
                    } else {
                        showSuggestionsEmpty();
                    }
                } else {
                    // Contact Picker APIがサポートされていない
                    alert('お使いのブラウザは連絡先へのアクセスに対応していません。\nCSVまたはvCardファイルからインポートしてください。');
                    closeSuggestionsModal();
                }
            } catch (e) {
                console.error('Contact access error:', e);
                if (e.name === 'SecurityError' || e.name === 'NotAllowedError') {
                    alert('連絡先へのアクセスが許可されませんでした。');
                }
                showSuggestionsEmpty();
            }
        }
        
        function closeSuggestionsModal() {
            document.getElementById('friendSuggestionsModal').style.display = 'none';
        }
        
        async function processContactsForInvite(contacts) {
            loadedContacts = [];
            
            // 連絡先データを整理
            for (const contact of contacts) {
                const name = contact.name && contact.name.length > 0 ? contact.name[0] : '';
                const emails = contact.email || [];
                const phones = contact.tel || [];
                
                // メールアドレスがある場合
                for (const email of emails) {
                    if (email && email.includes('@')) {
                        loadedContacts.push({
                            name: name,
                            contact: email.toLowerCase().trim(),
                            type: 'email'
                        });
                    }
                }
                
                // 電話番号がある場合
                for (const phone of phones) {
                    if (phone) {
                        // 電話番号を正規化（数字のみ）
                        const normalizedPhone = phone.replace(/[^\d+]/g, '');
                        if (normalizedPhone.length >= 10) {
                            loadedContacts.push({
                                name: name,
                                contact: normalizedPhone,
                                type: 'phone'
                            });
                        }
                    }
                }
            }
            
            if (loadedContacts.length === 0) {
                showSuggestionsEmpty();
                return;
            }
            
            // サーバーでユーザーマッチングを確認
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'check_contacts',
                        contacts: loadedContacts.map(c => ({
                            contact: c.contact,
                            type: c.type
                        }))
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // マッチング結果をマージ
                    for (const contact of loadedContacts) {
                        const match = data.matches.find(m => m.contact === contact.contact);
                        if (match) {
                            contact.user_id = match.user_id;
                            contact.display_name = match.display_name;
                            contact.is_registered = true;
                            contact.is_friend = match.is_friend;
                            contact.is_pending = match.is_pending;
                        } else {
                            contact.is_registered = false;
                        }
                    }
                }
            } catch (e) {
                console.error('Contact check error:', e);
            }
            
            showContactsList();
        }
        
        function showContactsList() {
            document.getElementById('suggestionsLoading').style.display = 'none';
            document.getElementById('suggestionsResults').style.display = 'block';
            
            // 登録済みを先に、未登録を後に
            const registered = loadedContacts.filter(c => c.is_registered);
            const unregistered = loadedContacts.filter(c => !c.is_registered);
            
            document.getElementById('suggestionsCount').textContent = 
                `${loadedContacts.length}件の連絡先`;
            document.getElementById('suggestionsSubtext').textContent = 
                `${registered.length}人が登録済み、${unregistered.length}人に招待を送信できます`;
            
            const listContainer = document.getElementById('suggestionsList');
            
            let html = '';
            
            // 登録済みユーザー
            for (const contact of registered) {
                html += `
                    <div class="suggestion-card" id="contact-${btoa(contact.contact).replace(/[^a-zA-Z0-9]/g, '')}">
                        <div class="suggestion-avatar">${contact.name ? contact.name.charAt(0).toUpperCase() : '?'}</div>
                        <div class="suggestion-info">
                            <div class="suggestion-name">
                                ${escapeHtml(contact.name || '名前なし')}
                                <span class="contact-type-badge registered">登録済み</span>
                            </div>
                            <div class="suggestion-email">${escapeHtml(contact.contact)}</div>
                        </div>
                        <div class="suggestion-actions">
                            ${contact.is_friend 
                                ? '<span style="color: #10b981; font-size: 12px;">✓ アドレス帳に追加済み</span>'
                                : contact.is_pending
                                    ? '<span style="color: #f59e0b; font-size: 12px;">申請中</span>'
                                    : `<button class="btn btn-primary btn-sm" onclick="addFriendFromContact(${contact.user_id})">アドレス追加申請</button>`
                            }
                        </div>
                    </div>
                `;
            }
            
            // 未登録ユーザー
            for (const contact of unregistered) {
                const contactId = btoa(contact.contact).replace(/[^a-zA-Z0-9]/g, '');
                html += `
                    <div class="suggestion-card" id="contact-${contactId}">
                        <div class="suggestion-avatar" style="background: #9ca3af;">${contact.name ? contact.name.charAt(0).toUpperCase() : '?'}</div>
                        <div class="suggestion-info">
                            <div class="suggestion-name">
                                ${escapeHtml(contact.name || '名前なし')}
                                <span class="contact-type-badge">${contact.type === 'phone' ? '電話' : 'メール'}</span>
                            </div>
                            <div class="suggestion-email">${escapeHtml(contact.contact)}</div>
                        </div>
                        <div class="suggestion-actions" id="actions-${contactId}">
                            <button class="btn-invite ${contact.type === 'phone' ? 'sms' : 'email'}" 
                                    onclick="sendInvite('${escapeHtml(contact.contact)}', '${contact.type}', '${contactId}')">
                                ${contact.type === 'phone' ? 'SMS招待' : 'メール招待'}
                            </button>
                        </div>
                    </div>
                `;
            }
            
            listContainer.innerHTML = html;
        }
        
        function showSuggestionsEmpty() {
            document.getElementById('suggestionsLoading').style.display = 'none';
            document.getElementById('suggestionsEmpty').style.display = 'block';
        }
        
        async function addFriendFromContact(userId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', friend_id: userId })
                });
                const data = await response.json();
                
                if (data.success) {
                    // ボタンを更新
                    const contact = loadedContacts.find(c => c.user_id === userId);
                    if (contact) {
                        contact.is_pending = true;
                        showContactsList();
                    }
                } else {
                    alert(data.error || 'アドレス追加申請に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        async function sendInvite(contact, type, contactId) {
            const actionsDiv = document.getElementById('actions-' + contactId);
            if (!actionsDiv) return;
            
            // ボタンを無効化
            const btn = actionsDiv.querySelector('.btn-invite');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '送信中...';
            }
            
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'send_invite',
                        contact: contact,
                        type: type
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    actionsDiv.innerHTML = '<span style="color: #10b981; font-size: 12px;">✓ 招待済み</span>';
                } else {
                    alert(data.error || '招待の送信に失敗しました');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = type === 'phone' ? 'SMS招待' : 'メール招待';
                    }
                }
            } catch (e) {
                console.error('Invite error:', e);
                alert('招待の送信に失敗しました');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = type === 'phone' ? 'SMS招待' : 'メール招待';
                }
            }
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // アドレス追加申請送信（候補から）
        async function addFriend(userId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', friend_id: userId })
                });
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    closeSearchModal();
                    loadFriendsList();
                    loadImportedContacts();
                } else {
                    alert(data.error || 'アドレス追加申請に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 申請を承認
        async function acceptRequest(friendshipId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'accept', friendship_id: friendshipId })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadRequests();
                    loadFriendsList();
                } else {
                    alert(data.error || '承認に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 申請を拒否
        async function rejectRequest(friendshipId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reject', friendship_id: friendshipId })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadRequests();
                } else {
                    alert(data.error || '拒否に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 受信した申請を保留（双方の一覧から削除）
        async function deferRequest(friendshipId) {
            if (!confirm('保留にすると、自分と相手の両方の申請一覧から消えます。よろしいですか？')) return;
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'defer', friendship_id: friendshipId })
                });
                const data = await response.json();
                if (data.success) {
                    loadRequests();
                    alert(data.message || '申請を保留しました');
                } else {
                    alert(data.error || '保留に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 受信した申請からブロック（相手は再申請不可）
        async function blockRequester(userId, displayName) {
            if (!confirm(displayName + ' をブロックしますか？\nブロックすると、相手からは友達申請できなくなります。')) return;
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'block', friend_id: userId })
                });
                const data = await response.json();
                if (data.success) {
                    loadRequests();
                    loadBlockedList();
                    alert(data.message || 'ブロックしました');
                } else {
                    alert(data.error || 'ブロックに失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 送信した申請を取り消す
        async function cancelSentRequest(userId) {
            if (!confirm('このアドレス追加申請を取り消しますか？')) return;
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'cancel_sent', friend_id: userId })
                });
                const data = await response.json();
                if (data.success) {
                    loadRequests();
                    alert(data.message || '申請を取り消しました');
                } else {
                    alert(data.error || '取り消しに失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // 友だち削除
        async function removeFriend(userId) {
            if (!confirm('このアドレス帳から削除しますか？')) return;
            
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove', friend_id: userId })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadFriendsList();
                } else {
                    alert(data.error || '削除に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // ブロック解除（新）
        async function unblockUserNew(userId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'unblock', friend_id: userId })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadBlockedList();
                } else {
                    alert(data.error || 'ブロック解除に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // プロフィール表示（DMチャットへ遷移）
        async function viewProfile(userId) {
            try {
                const res = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create_or_get_dm', user_id: parseInt(userId, 10) })
                });
                const data = await res.json();
                if (data.success && data.conversation_id) {
                    window.location.href = 'chat.php?c=' + data.conversation_id;
                } else {
                    alert(data.error || 'チャットを開けませんでした');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // CSV アップロード
        async function uploadCSV(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = async function(e) {
                const text = e.target.result;
                const lines = text.split('\n').filter(line => line.trim());
                const contacts = [];
                
                lines.forEach((line, index) => {
                    // ヘッダー行をスキップ
                    if (index === 0 && (line.includes('名前') || line.toLowerCase().includes('name'))) return;
                    
                    const parts = line.split(',').map(p => p.trim().replace(/^"|"$/g, ''));
                    if (parts.length >= 1 && parts[0]) {
                        contacts.push({
                            name: parts[0],
                            email: parts[1] || null,
                            phone: parts[2] || null
                        });
                    }
                });
                
                if (contacts.length === 0) {
                    alert('有効な連絡先が見つかりませんでした');
                    return;
                }
                
                await importContacts(contacts);
            };
            reader.readAsText(file, 'UTF-8');
            event.target.value = '';
        }
        
        // vCard アップロード
        async function uploadVCard(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = async function(e) {
                const text = e.target.result;
                const contacts = parseVCard(text);
                
                if (contacts.length === 0) {
                    alert('有効な連絡先が見つかりませんでした');
                    return;
                }
                
                await importContacts(contacts);
            };
            reader.readAsText(file, 'UTF-8');
            event.target.value = '';
        }
        
        // vCard パース
        function parseVCard(text) {
            const contacts = [];
            const vcards = text.split('BEGIN:VCARD');
            
            vcards.forEach(vcard => {
                if (!vcard.trim()) return;
                
                let name = '';
                let email = null;
                let phone = null;
                
                const lines = vcard.split('\n');
                lines.forEach(line => {
                    line = line.trim();
                    if (line.startsWith('FN:') || line.startsWith('FN;')) {
                        name = line.split(':').slice(1).join(':').trim();
                    } else if (line.startsWith('EMAIL') && !email) {
                        email = line.split(':').slice(1).join(':').trim();
                    } else if (line.startsWith('TEL') && !phone) {
                        phone = line.split(':').slice(1).join(':').trim();
                    }
                });
                
                if (name) {
                    contacts.push({ name, email, phone });
                }
            });
            
            return contacts;
        }
        
        // 連絡先インポート実行
        async function importContacts(contacts) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'import_contacts', contacts })
                });
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    loadImportedContacts();
                } else {
                    alert(data.error || 'インポートに失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // エスケープ関数
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ========== 保護者機能 ==========
        
        // ページ読み込み時に保護者機能を読み込む
        document.addEventListener('DOMContentLoaded', () => {
            const parentalLoading = document.getElementById('parentalLoading');
            if (parentalLoading) {
                loadParentalData();
            }
        });
        
        async function loadParentalData() {
            const loading = document.getElementById('parentalLoading');
            const childSection = document.getElementById('childSection');
            const parentSection = document.getElementById('parentSection');
            
            try {
                // 自分が子として登録されているか確認
                const myParentRes = await fetch('api/parental.php?action=get_my_parent');
                const myParentData = await myParentRes.json();
                
                // 自分が保護者として登録されているか確認
                const myChildrenRes = await fetch('api/parental.php?action=get_my_children');
                const myChildrenData = await myChildrenRes.json();
                
                loading.style.display = 'none';
                
                // 子セクションの表示
                childSection.style.display = 'block';
                if (myParentData.success && myParentData.parent) {
                    const parent = myParentData.parent;
                    if (parent.status === 'pending') {
                        document.getElementById('noParentLinked').style.display = 'none';
                        document.getElementById('parentLinkPending').style.display = 'block';
                        document.getElementById('pendingParentEmail').textContent = parent.parent_email + ' に申請中';
                    } else if (parent.status === 'approved') {
                        document.getElementById('noParentLinked').style.display = 'none';
                        document.getElementById('parentLinked').style.display = 'block';
                        document.getElementById('linkedParentName').textContent = parent.parent_name;
                        
                        // 制限情報を表示
                        const restrictions = [];
                        if (parent.daily_usage_limit_minutes) restrictions.push('利用時間: ' + parent.daily_usage_limit_minutes + '分/日');
                        if (parent.search_restricted == 1) restrictions.push('検索制限あり');
                        if (parent.dm_restricted == 1) restrictions.push('DM制限あり');
                        if (parent.group_join_restricted == 1) restrictions.push('グループ参加制限あり');
                        if (parent.call_restricted == 1) restrictions.push('通話制限あり');
                        
                        document.getElementById('myRestrictionsList').textContent = 
                            restrictions.length > 0 ? restrictions.join('、') : '制限なし';
                    }
                } else {
                    document.getElementById('noParentLinked').style.display = 'block';
                }
                
                // 保護者セクションの表示
                if (myChildrenData.success) {
                    parentSection.style.display = 'block';
                    
                    const children = myChildrenData.children || [];
                    const pending = myChildrenData.pending_requests || [];
                    
                    if (children.length === 0 && pending.length === 0) {
                        document.getElementById('noChildren').style.display = 'block';
                    } else {
                        document.getElementById('noChildren').style.display = 'none';
                        
                        // 子リストを表示
                        const childrenList = document.getElementById('childrenList');
                        childrenList.innerHTML = children.map(child => `
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.7); border-radius: 8px; margin-bottom: 8px;">
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                    ${(child.child_name || '?').charAt(0)}
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 500; color: #1e3a8a;">${escapeHtml(child.child_name)}</div>
                                    <div style="font-size: 12px; color: #3b82f6;">
                                        今日: ${child.today_usage || 0}分${child.daily_usage_limit_minutes ? ' / ' + child.daily_usage_limit_minutes + '分' : ''}
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <button class="btn btn-secondary btn-sm" onclick="openUsageModal(${child.child_id}, '${escapeHtml(child.child_name)}')">📊 利用状況</button>
                                    <button class="btn btn-primary btn-sm" onclick="openRestrictionModal(${child.child_id}, '${escapeHtml(child.child_name)}')">⚙️ 制限設定</button>
                                </div>
                            </div>
                        `).join('');
                        
                        // 承認待ち申請を表示
                        if (pending.length > 0) {
                            const pendingDiv = document.getElementById('pendingChildRequests');
                            pendingDiv.innerHTML = `
                                <div style="font-size: 14px; font-weight: 500; color: #b45309; margin-bottom: 8px;">⏳ 承認待ちの申請</div>
                                ${pending.map(p => `
                                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(254,243,199,0.7); border-radius: 8px; margin-bottom: 8px;">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; color: #78350f;">${escapeHtml(p.child_name)}</div>
                                            <div style="font-size: 12px; color: #92400e;">${escapeHtml(p.child_email)}</div>
                                        </div>
                                        <div style="font-size: 12px; color: #92400e;">メールで承認してください</div>
                                    </div>
                                `).join('')}
                            `;
                        }
                    }
                }
            } catch (e) {
                console.error('Parental data load error:', e);
                loading.textContent = 'データの読み込みに失敗しました';
            }
        }
        
        async function requestParentLink() {
            const email = document.getElementById('parentEmailInput').value.trim();
            if (!email) {
                alert('保護者のメールアドレスを入力してください');
                return;
            }
            
            try {
                const response = await fetch('api/parental.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'request_parent_link',
                        parent_email: email
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('保護者にリンク申請メールを送信しました');
                    loadParentalData();
                } else {
                    alert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                console.error('Parent link request error:', e);
                alert('通信エラーが発生しました');
            }
        }
        
        let currentRestrictionChildId = null;
        
        async function openRestrictionModal(childId, childName) {
            currentRestrictionChildId = childId;
            document.getElementById('restrictionModalTitle').textContent = childName + 'さんの制限設定';
            document.getElementById('restrictionChildId').value = childId;
            document.getElementById('restrictionModal').style.display = 'flex';
            
            // 現在の設定を取得
            try {
                const response = await fetch('api/parental.php?action=get_child_restrictions&child_user_id=' + childId);
                const data = await response.json();
                
                if (data.success && data.restrictions) {
                    const r = data.restrictions;
                    document.getElementById('dailyUsageLimit').value = r.daily_usage_limit_minutes || '';
                    document.getElementById('usageStartTime').value = r.usage_start_time ? r.usage_start_time.substr(0, 5) : '';
                    document.getElementById('usageEndTime').value = r.usage_end_time ? r.usage_end_time.substr(0, 5) : '';
                    document.getElementById('searchRestricted').checked = r.search_restricted == 1;
                    document.getElementById('dmRestricted').checked = r.dm_restricted == 1;
                    document.getElementById('groupJoinRestricted').checked = r.group_join_restricted == 1;
                    document.getElementById('callRestricted').checked = r.call_restricted == 1;
                } else {
                    // デフォルト値をリセット
                    document.getElementById('dailyUsageLimit').value = '';
                    document.getElementById('usageStartTime').value = '';
                    document.getElementById('usageEndTime').value = '';
                    document.getElementById('searchRestricted').checked = false;
                    document.getElementById('dmRestricted').checked = false;
                    document.getElementById('groupJoinRestricted').checked = false;
                    document.getElementById('callRestricted').checked = false;
                }
            } catch (e) {
                console.error('Get restrictions error:', e);
            }
        }
        
        function closeRestrictionModal() {
            document.getElementById('restrictionModal').style.display = 'none';
            currentRestrictionChildId = null;
        }
        
        async function saveRestrictions() {
            if (!currentRestrictionChildId) return;
            
            const data = {
                action: 'update_restrictions',
                child_user_id: currentRestrictionChildId,
                daily_usage_limit_minutes: document.getElementById('dailyUsageLimit').value || null,
                usage_start_time: document.getElementById('usageStartTime').value || null,
                usage_end_time: document.getElementById('usageEndTime').value || null,
                search_restricted: document.getElementById('searchRestricted').checked ? 1 : 0,
                dm_restricted: document.getElementById('dmRestricted').checked ? 1 : 0,
                group_join_restricted: document.getElementById('groupJoinRestricted').checked ? 1 : 0,
                call_restricted: document.getElementById('callRestricted').checked ? 1 : 0
            };
            
            try {
                const response = await fetch('api/parental.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                
                if (result.success) {
                    alert('制限設定を保存しました');
                    closeRestrictionModal();
                    loadParentalData();
                } else {
                    alert(result.error || 'エラーが発生しました');
                }
            } catch (e) {
                console.error('Save restrictions error:', e);
                alert('通信エラーが発生しました');
            }
        }
        
        async function openUsageModal(childId, childName) {
            document.getElementById('usageModalTitle').textContent = childName + 'さんの利用状況';
            document.getElementById('usageModal').style.display = 'flex';
            
            try {
                const response = await fetch('api/parental.php?action=get_child_usage&child_user_id=' + childId + '&days=7');
                const data = await response.json();
                
                if (data.success) {
                    const chart = document.getElementById('usageChart');
                    const summary = document.getElementById('usageSummary');
                    
                    // シンプルな棒グラフを表示
                    const logs = data.logs || [];
                    const maxMinutes = Math.max(...logs.map(l => parseInt(l.total_minutes) || 0), 60);
                    
                    chart.innerHTML = logs.length > 0 ? `
                        <div style="display: flex; align-items: flex-end; gap: 8px; height: 150px; padding-bottom: 30px; position: relative;">
                            ${logs.reverse().map(log => {
                                const height = Math.max(((parseInt(log.total_minutes) || 0) / maxMinutes) * 100, 5);
                                const date = new Date(log.log_date);
                                const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
                                return `
                                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                                        <div style="background: #3b82f6; width: 100%; height: ${height}px; border-radius: 4px 4px 0 0;"></div>
                                        <div style="font-size: 10px; color: #666; margin-top: 4px;">${date.getMonth()+1}/${date.getDate()}</div>
                                        <div style="font-size: 10px; color: #999;">${dayNames[date.getDay()]}</div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    ` : '<div style="text-align: center; color: #666; padding: 40px;">利用データがありません</div>';
                    
                    summary.innerHTML = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <div style="font-size: 12px; color: #666;">過去7日間の合計</div>
                                <div style="font-size: 20px; font-weight: bold; color: #1e3a8a;">${data.total_minutes}分</div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #666;">1日平均</div>
                                <div style="font-size: 20px; font-weight: bold; color: #1e3a8a;">${data.average_minutes}分</div>
                            </div>
                        </div>
                    `;
                }
            } catch (e) {
                console.error('Get usage error:', e);
                document.getElementById('usageChart').innerHTML = '<div style="color: red;">データ取得に失敗しました</div>';
            }
        }
        
        function closeUsageModal() {
            document.getElementById('usageModal').style.display = 'none';
        }
        
        // ========== プライバシー設定 ==========
        
        // ページ読み込み時にプライバシー設定を読み込む
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('privacySettingsContainer');
            if (container) {
                loadPrivacySettings();
                loadMyInviteInfo();
            }
        });
        
        async function loadPrivacySettings() {
            const container = document.getElementById('privacySettingsContainer');
            if (!container) return;
            
            try {
                const response = await fetch('api/settings.php?action=get_privacy');
                const data = await response.json();
                
                if (data.success) {
                    const privacy = data.privacy;
                    const allowSearch = privacy.exclude_from_search == 0 || privacy.exclude_from_search === '0';
                    const hideOnline = privacy.hide_online_status == 1 || privacy.hide_online_status === '1';
                    const hideRead = privacy.hide_read_receipts == 1 || privacy.hide_read_receipts === '1';
                    
                    container.innerHTML = `
                        <div class="form-group" style="margin-bottom: 24px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: ${allowSearch ? '#f0fdf4' : '#fef3c7'}; border-radius: 12px; border: 1px solid ${allowSearch ? '#86efac' : '#fcd34d'};">
                                <div>
                                    <div style="font-weight: 600; margin-bottom: 4px; color: ${allowSearch ? '#166534' : '#92400e'};">
                                        🔍 検索許可
                                    </div>
                                    <div style="font-size: 13px; color: #666;">
                                        ${allowSearch 
                                            ? '他のユーザーがあなたを検索で見つけることができます' 
                                            : '現在、他のユーザーはあなたを検索できません'}
                                    </div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="allowSearchToggle" ${allowSearch ? 'checked' : ''} onchange="savePrivacySettings()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 16px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px; background: #f8fafc; border-radius: 8px;">
                                <div>
                                    <div style="font-weight: 500; margin-bottom: 2px;">オンライン状態を非公開</div>
                                    <div style="font-size: 12px; color: #666;">他のユーザーにオンライン状態を表示しない</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="hideOnlineToggle" ${hideOnline ? 'checked' : ''} onchange="savePrivacySettings()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px; background: #f8fafc; border-radius: 8px;">
                                <div>
                                    <div style="font-weight: 500; margin-bottom: 2px;">既読を非表示</div>
                                    <div style="font-size: 12px; color: #666;">メッセージを読んでも相手に通知しない</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="hideReadToggle" ${hideRead ? 'checked' : ''} onchange="savePrivacySettings()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="privacySaveStatus" style="margin-top: 12px; text-align: center; font-size: 13px; color: #16a34a; display: none;">
                            ✓ 保存しました
                        </div>
                    `;
                } else {
                    container.innerHTML = '<div class="error-text">設定の読み込みに失敗しました</div>';
                }
            } catch (e) {
                console.error('Privacy settings load error:', e);
                container.innerHTML = '<div class="error-text">設定の読み込みに失敗しました</div>';
            }
        }
        
        async function savePrivacySettings() {
            const allowSearch = document.getElementById('allowSearchToggle')?.checked ?? false;
            const hideOnline = document.getElementById('hideOnlineToggle')?.checked ?? false;
            const hideRead = document.getElementById('hideReadToggle')?.checked ?? false;
            
            try {
                const response = await fetch('api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_privacy',
                        exclude_from_search: allowSearch ? 0 : 1,
                        hide_online_status: hideOnline ? 1 : 0,
                        hide_read_receipts: hideRead ? 1 : 0
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const statusEl = document.getElementById('privacySaveStatus');
                    if (statusEl) {
                        statusEl.style.display = 'block';
                        setTimeout(() => { statusEl.style.display = 'none'; }, 2000);
                    }
                    // UIを再読み込みして状態を反映
                    loadPrivacySettings();
                }
            } catch (e) {
                console.error('Privacy settings save error:', e);
                alert('設定の保存に失敗しました');
            }
        }
        
        async function loadMyInviteInfo() {
            try {
                const response = await fetch('api/settings.php?action=get_invite_info');
                const data = await response.json();
                
                if (data.success) {
                    const urlInput = document.getElementById('myInviteUrl');
                    if (urlInput) {
                        urlInput.value = data.invite_url;
                    }
                    
                    // QRコード生成
                    const qrContainer = document.getElementById('myInviteQrCode');
                    if (qrContainer && typeof QRCode !== 'undefined') {
                        qrContainer.innerHTML = '';
                        new QRCode(qrContainer, {
                            text: data.invite_url,
                            width: 150,
                            height: 150,
                            colorDark: '#000000',
                            colorLight: '#ffffff',
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                }
            } catch (e) {
                console.error('Invite info load error:', e);
            }
        }
        
        function copyMyInviteUrl() {
            const urlInput = document.getElementById('myInviteUrl');
            if (urlInput) {
                urlInput.select();
                document.execCommand('copy');
                alert('招待リンクをコピーしました');
            }
        }
        
        function downloadMyInviteQr() {
            const qrContainer = document.getElementById('myInviteQrCode');
            const canvas = qrContainer?.querySelector('canvas');
            if (canvas) {
                const link = document.createElement('a');
                link.download = 'social9-invite-qr.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            } else {
                alert('QRコードの生成中です。しばらくお待ちください。');
            }
        }
        
        // ========== プライバシー・利用規約モーダル ==========
        
        function showPrivacyPolicy() {
            document.getElementById('privacyModal').style.display = 'flex';
        }
        
        function closePrivacyModal() {
            document.getElementById('privacyModal').style.display = 'none';
        }
        
        function showTermsOfService() {
            document.getElementById('termsModal').style.display = 'flex';
        }
        
        function closeTermsModal() {
            document.getElementById('termsModal').style.display = 'none';
        }
        
        // モーダル外クリックで閉じる
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('legal-modal')) {
                e.target.style.display = 'none';
            }
        });
        
        // Escapeキーでモーダルを閉じる
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.legal-modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });
        
        // ========== 既存の機能 ==========
        
        // ブロックリスト表示（既存 - 互換性維持）
        async function openBlockList() {
            const area = document.getElementById('blockListArea');
            const content = document.getElementById('blockListContent');
            
            if (area.style.display === 'block') {
                area.style.display = 'none';
                return;
            }
            
            content.innerHTML = '<p style="color: #888;">読み込み中...</p>';
            area.style.display = 'block';
            
            try {
                const response = await fetch('api/settings.php?action=get_blocklist');
                const data = await response.json();
                
                if (data.success && data.blocked_users && data.blocked_users.length > 0) {
                    let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
                    data.blocked_users.forEach(user => {
                        html += `
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                                <span>${escapeHtml(user.display_name)}</span>
                                <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="unblockUser(${user.id})">解除</button>
                            </div>
                        `;
                    });
                    html += '</div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<p style="color: #888; text-align: center; padding: 20px;">ブロック中のユーザーはいません</p>';
                }
            } catch (e) {
                content.innerHTML = '<p style="color: #888; text-align: center; padding: 20px;">ブロック中のユーザーはいません</p>';
            }
        }
        
        async function unblockUser(userId) {
            if (!confirm('このユーザーのブロックを解除しますか？')) return;
            
            try {
                const response = await fetch('api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'unblock_user', user_id: userId })
                });
                const data = await response.json();
                
                if (data.success) {
                    openBlockList(); // リスト更新
                } else {
                    alert(data.error || 'ブロック解除に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        // データエクスポート
        async function exportData() {
            if (!confirm('アカウントデータをエクスポートしますか？')) return;
            
            try {
                const response = await fetch('api/settings.php?action=export_data');
                const data = await response.json();
                
                if (data.success) {
                    // JSONファイルとしてダウンロード
                    const blob = new Blob([JSON.stringify(data.export_data, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'social9_export_' + new Date().toISOString().slice(0,10) + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    alert(data.error || 'エクスポートに失敗しました');
                }
            } catch (e) {
                alert('エクスポートに失敗しました');
            }
        }
        
        // アカウント削除
        function deleteAccount() {
            const confirmText = prompt('アカウントを削除するには「削除」と入力してください：');
            if (confirmText !== '削除') {
                if (confirmText !== null) {
                    alert('入力が一致しません。アカウント削除はキャンセルされました。');
                }
                return;
            }
            
            if (!confirm('本当にアカウントを削除しますか？\n\nこの操作は取り消せません。すべてのデータが完全に削除されます。')) {
                return;
            }
            
            fetch('api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_account' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('アカウントが削除されました。ご利用ありがとうございました。');
                    location.href = 'index.php';
                } else {
                    alert(data.error || 'アカウント削除に失敗しました');
                }
            })
            .catch(() => {
                alert('エラーが発生しました');
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ===============================================
        // 言語切替機能
        // ===============================================
        document.addEventListener('DOMContentLoaded', function() {
            const languageSelect = document.getElementById('languageSelect');
            if (languageSelect) {
                languageSelect.addEventListener('change', async function() {
                    const newLang = this.value;
                    
                    try {
                        const response = await fetch('api/language.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ language: newLang })
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            // リロードバナーを表示
                            const banner = document.getElementById('langReloadBanner');
                            const message = document.getElementById('langReloadMessage');
                            const button = document.getElementById('langReloadBtn');
                            
                            if (banner && message && button) {
                                message.textContent = data.message;
                                button.textContent = data.reload_button;
                                banner.style.display = 'flex';
                                
                                // バナーにスクロール
                                banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        } else {
                            console.error('Language change failed:', data.error);
                        }
                    } catch (e) {
                        console.error('Error changing language:', e);
                    }
                });
            }
        });
        
        // ========== プッシュ通知設定 ==========
        const vapidPublicKey = '<?= defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '' ?>';
        
        // プッシュ通知の初期化
        async function initPushSettings() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                document.getElementById('pushStatus').textContent = 'このブラウザはプッシュ通知に対応していません';
                document.getElementById('pushStatus').className = 'push-status unsupported';
                return;
            }
            
            const permission = Notification.permission;
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            const statusEl = document.getElementById('pushStatus');
            const enableBtn = document.getElementById('pushEnableBtn');
            const disableBtn = document.getElementById('pushDisableBtn');
            const testBtn = document.getElementById('pushTestBtn');
            const deniedHelp = document.getElementById('pushDeniedHelp');
            
            if (permission === 'denied') {
                statusEl.textContent = '通知がブロックされています。下記の手順でChromeの通知を許可してください。';
                statusEl.className = 'push-status denied';
                if (deniedHelp) deniedHelp.style.display = 'block';
            } else if (subscription) {
                // ブラウザに購読があってもDBにない場合があるため、毎回同期する（失敗してもUIは表示継続）
                try {
                    const syncRes = await fetch('/api/push.php?action=subscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ subscription: subscription.toJSON() })
                    });
                    const syncCt = syncRes.headers.get('Content-Type') || '';
                    if (syncCt.includes('application/json')) {
                        const syncResult = await syncRes.json();
                        if (!syncResult.success) {
                            console.warn('Push sync:', syncResult.message || syncRes.status);
                        }
                    } else if (!syncRes.ok) {
                        const syncText = await syncRes.text();
                        console.warn('Push sync: HTTP', syncRes.status, syncText.slice(0, 200));
                    }
                } catch (e) {
                    console.warn('Push sync:', e.message || e);
                }
                statusEl.textContent = 'プッシュ通知が有効です';
                statusEl.className = 'push-status enabled';
                disableBtn.style.display = 'inline-block';
                testBtn.style.display = 'inline-block';
                if (deniedHelp) deniedHelp.style.display = 'none';
            } else {
                statusEl.textContent = 'プッシュ通知を有効にすると、新着メッセージを受け取れます';
                statusEl.className = 'push-status disabled';
                enableBtn.style.display = 'inline-block';
                if (deniedHelp) deniedHelp.style.display = 'none';
            }
        }
        
        async function enablePushNotifications() {
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    alert('通知の許可が必要です');
                    return;
                }
                
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                });
                
                // サーバーに保存
                const response = await fetch('/api/push.php?action=subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subscription: subscription.toJSON() }),
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                
                let result;
                const ct = response.headers.get('Content-Type') || '';
                if (ct.includes('application/json')) {
                    result = await response.json();
                } else {
                    const text = await response.text();
                    console.error('Push subscribe: non-JSON response', response.status, text.slice(0, 300));
                    throw new Error('サーバーがJSON以外を返しました (HTTP ' + response.status + ')');
                }
                if (result.success) {
                    const savedCount = result.subscription_count ?? 0;
                    if (savedCount === 0) {
                        console.warn('Push subscribe: サーバーに購読が保存されませんでした（subscription_count=0）');
                    }
                    initPushSettings();
                    alert('プッシュ通知を有効にしました');
                } else {
                    const msg = result.message || ('HTTP ' + response.status);
                    console.error('Push subscribe error:', msg);
                    alert('エラー: ' + msg);
                }
            } catch (error) {
                console.error('Push subscription error:', error);
                alert('プッシュ通知の有効化に失敗しました: ' + (error.message || String(error)));
            }
        }
        
        async function disablePushNotifications() {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                
                if (subscription) {
                    // ブラウザの購読を先に解除（サーバーと状態を揃える）
                    await subscription.unsubscribe();
                    // サーバーから削除
                    await fetch('/api/push.php?action=unsubscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: subscription.endpoint }),
                        credentials: 'same-origin'
                    });
                }
                
                alert('プッシュ通知を無効にしました');
                location.reload(); // 購読状態を確実に反映
            } catch (error) {
                console.error('Push unsubscribe error:', error);
                alert('プッシュ通知の無効化に失敗しました');
            }
        }
        
        async function testPushNotification() {
            try {
                const response = await fetch('/api/push.php?action=test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin'
                });
                const ct = response.headers.get('Content-Type') || '';
                if (!ct.includes('application/json')) {
                    const text = await response.text();
                    console.error('Push API returned non-JSON:', text.slice(0, 200));
                    alert('テスト通知の送信に失敗しました（サーバーエラー）');
                    return;
                }
                const result = await response.json();
                console.log('Push test result:', result);
                
                if (result.success) {
                    const sentCount = result.sent_count || 0;
                    alert(`テスト通知を送信しました（${sentCount}件）\n\n数秒後にOS通知が表示されます。\n表示されない場合は、ブラウザの通知設定を確認してください。`);
                } else {
                    let debugMsg = result.message || '不明なエラー';
                    if (result.debug) {
                        debugMsg += '\n\n【デバッグ情報】';
                        debugMsg += '\n購読数: ' + (result.debug.subscription_count || 0);
                        debugMsg += '\nライブラリ: ' + (result.debug.web_push_library || '不明');
                    }
                    if (result.recent_logs && result.recent_logs.length > 0) {
                        debugMsg += '\n\n【最近のログ】';
                        result.recent_logs.forEach((log, i) => {
                            debugMsg += `\n${i+1}. ${log.status}: ${log.error_message || '(なし)'}`;
                        });
                    }
                    alert('テスト通知の送信に失敗しました:\n' + debugMsg);
                }
            } catch (error) {
                console.error('Test notification error:', error);
                alert('テスト通知の送信に失敗しました: ' + error.message);
            }
        }
        
        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }
        
        // ページ読み込み時に初期化
        if (document.getElementById('pushStatus')) {
            initPushSettings();
        }
        
    </script>
    <?php if ($current_section === 'calendar'): ?>
    <style>
    .calendar-accounts-section { margin-top: 16px; }
    .calendar-account-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 16px;
        margin-bottom: 10px;
        background: var(--bg-secondary, #f5f5f5);
        border-radius: 8px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .calendar-account-info { display: flex; flex-direction: column; gap: 4px; }
    .calendar-account-name { font-weight: 600; font-size: 15px; }
    .calendar-default-badge { font-size: 12px; color: var(--accent, #e67e22); }
    .calendar-account-email { font-size: 12px; color: var(--text-secondary, #666); }
    .calendar-account-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .calendar-account-actions button {
        padding: 6px 12px;
        font-size: 13px;
        border-radius: 6px;
        border: 1px solid #ddd;
        background: #fff;
        cursor: pointer;
    }
    .calendar-account-actions button:hover { background: #eee; }
    .btn-calendar-disconnect { color: #c0392b; border-color: #e74c3c; }
    .calendar-add-area { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
    .calendar-add-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .calendar-name-input {
        flex: 1;
        min-width: 200px;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.btn-calendar-rename').forEach(function(btn) {
            btn.onclick = function() {
                var id = this.dataset.id;
                var current = this.dataset.name;
                var newName = prompt('新しいカレンダー名を入力してください', current);
                if (!newName || newName.trim() === '') return;
                if (newName.length > 50) { alert('50文字以内で入力してください'); return; }
                fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_name', id: parseInt(id), display_name: newName.trim() })
                }).then(function(r) { return r.json(); }).then(function(d) {
                    if (d.success) { location.reload(); } else { alert(d.message || 'エラー'); }
                }).catch(function() { alert('エラーが発生しました'); });
            };
        });
        document.querySelectorAll('.btn-calendar-default').forEach(function(btn) {
            btn.onclick = function() {
                var id = this.dataset.id;
                fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_default', id: parseInt(id) })
                }).then(function(r) { return r.json(); }).then(function(d) {
                    if (d.success) { location.reload(); } else { alert(d.message || 'エラー'); }
                }).catch(function() { alert('エラーが発生しました'); });
            };
        });
        document.querySelectorAll('.btn-calendar-disconnect').forEach(function(btn) {
            btn.onclick = function() {
                if (!confirm('このカレンダーの連携を解除しますか？')) return;
                var id = this.dataset.id;
                fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'disconnect', id: parseInt(id) })
                }).then(function(r) { return r.json(); }).then(function(d) {
                    if (d.success) { location.reload(); } else { alert(d.message || 'エラー'); }
                }).catch(function() { alert('エラーが発生しました'); });
            };
        });
    });
    </script>
    <?php endif; ?>
    <script>
    (function() {
        function playSoundFile(soundId) {
            if (soundId === 'silent' || !soundId) return;
            var paths = window.__RINGTONE_PATHS;
            if (!paths || !paths[soundId]) return;
            var path = paths[soundId];
            if (!path) return;
            try {
                var a = new Audio(path);
                a.play().catch(function() {});
            } catch (e) {}
        }
        function setSoundPreviewSelected(btn, inputId) {
            var tbl = btn.closest('.sound-preview-table');
            if (tbl) {
                tbl.querySelectorAll('.btn-sound-preview.selected, .btn-sound-silent.selected').forEach(function(b) { b.classList.remove('selected'); });
            }
            btn.classList.add('selected');
            if (inputId) {
                var inp = document.getElementById(inputId);
                if (inp && btn.getAttribute('data-preset')) inp.value = btn.getAttribute('data-preset');
            }
        }
        function setSilentSelected(btn) {
            var tbl = btn.closest('.sound-preview-table');
            if (tbl) {
                tbl.querySelectorAll('.btn-sound-preview.selected, .btn-sound-silent.selected').forEach(function(b) { b.classList.remove('selected'); });
            }
            btn.classList.add('selected');
        }
        function initSoundPreviewSelection() {
            var notifInp = document.getElementById('notificationSoundInput');
            if (notifInp) {
                var val = notifInp.value;
                var form = notifInp.closest('form');
                var tbl = form ? form.querySelector('.sound-preview-table:not(.sound-preview-call)') : null;
                if (tbl) {
                    var row = tbl.querySelector('.sound-preview-row[data-preset="' + val + '"]');
                    if (row) {
                        var btn = val === 'silent' ? row.querySelector('.btn-sound-silent') : row.querySelector('.btn-sound-preview');
                        if (btn) btn.classList.add('selected');
                    }
                }
            }
            var ringInp = document.getElementById('ringtoneInput');
            if (ringInp) {
                var val = ringInp.value;
                var form = ringInp.closest('form');
                var tbl = form ? form.querySelector('.sound-preview-call') : null;
                if (tbl) {
                    var row = tbl.querySelector('.sound-preview-row[data-preset="' + val + '"]');
                    if (row) {
                        var btn = val === 'silent' ? row.querySelector('.btn-sound-silent') : row.querySelector('.btn-sound-preview');
                        if (btn) btn.classList.add('selected');
                    }
                }
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            initSoundPreviewSelection();
            var ringtoneTestBtn = document.getElementById('ringtoneTestBtn');
            if (ringtoneTestBtn) {
                ringtoneTestBtn.addEventListener('click', function() {
                    var inp = document.getElementById('notificationSoundInput');
                    var preset = inp ? inp.value : 'default';
                    if (preset === 'silent') {
                        alert('サイレントが選択されています。音は鳴りません。');
                        return;
                    }
                    playSoundFile(preset);
                });
            }
            var testMsgBtn = document.getElementById('btnTestMessageSound');
            if (testMsgBtn) {
                testMsgBtn.addEventListener('click', function() {
                    var inp = document.getElementById('notificationSoundInput');
                    var preset = inp ? inp.value : 'default';
                    playSoundFile(preset);
                });
            }
            var testCallBtn = document.getElementById('btnTestCallSound');
            if (testCallBtn) {
                testCallBtn.addEventListener('click', function() {
                    var inp = document.getElementById('ringtoneInput');
                    var preset = inp ? inp.value : 'default';
                    playSoundFile(preset);
                });
            }
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.btn-sound-preview');
                if (btn) {
                    var preset = btn.getAttribute('data-preset');
                    var inputId = btn.getAttribute('data-input');
                    if (inputId) {
                        var inp = document.getElementById(inputId);
                        if (inp) inp.value = preset;
                    }
                    setSoundPreviewSelected(btn, inputId);
                    playSoundFile(preset);
                    return;
                }
                btn = e.target.closest('.btn-sound-silent');
                if (btn) {
                    var inputId = btn.getAttribute('data-input');
                    if (inputId) {
                        var inp = document.getElementById(inputId);
                        if (inp) inp.value = 'silent';
                    }
                    setSilentSelected(btn);
                }
            });
        });
    })();
    </script>

    
        
    <style>
        /* アバター設定 */
        .avatar-settings {
            margin-bottom: 20px;
        }
        
        .avatar-preview-container {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 10px;
        }
        
        .avatar-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
            overflow: hidden;
        }
        
        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* 着信音が鳴る条件 */
        .notification-trigger-group .notification-trigger-options {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 8px;
        }
        .notification-trigger-group .radio-option .radio-label {
            font-size: 0.95em;
        }
        
        /* 着信音試聴（全デザイン共通で確実に表示） */
        .sound-preview-form .sound-preview-table {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: visible;
        }
        .sound-preview-form .sound-preview-row {
            display: grid;
            grid-template-columns: max-content 1fr max-content;
            gap: 6px;
            align-items: center;
            padding: 10px 12px;
            border-top: 1px solid #eee;
        }
        .sound-preview-form .sound-preview-row:first-of-type {
            border-top: none;
        }
        .sound-preview-form .sound-col-name {
            font-size: 0.95em;
            min-width: 100px;
            white-space: nowrap;
        }
        .sound-preview-form .sound-col-name input {
            margin: 0;
        }
        .sound-preview-form .sound-col-preview {
            font-size: 0.85em;
            color: #666;
        }
        .sound-preview-form .sound-col-btns {
            display: flex !important;
            flex-wrap: wrap;
            gap: 6px;
            min-width: 0;
        }
        .sound-preview-form .btn-sound-preview,
        .sound-preview-form .btn-sound-silent {
            min-width: 44px;
            flex-shrink: 0;
            /* 全デザイン共通：テーマ変数に依存せず必ず表示 */
            background: #e5e7eb !important;
            color: #374151 !important;
            border: 1px solid #d1d5db !important;
            -webkit-text-fill-color: #374151 !important;
        }
        .sound-preview-form .btn-sound-preview:hover,
        .sound-preview-form .btn-sound-silent:hover {
            background: #d1d5db !important;
            color: #1f2937 !important;
            border-color: #9ca3af !important;
        }
        .sound-preview-form .btn-sound-preview.selected,
        .sound-preview-form .btn-sound-silent.selected,
        .sound-preview-form .btn-sound-preview.selected:hover,
        .sound-preview-form .btn-sound-silent.selected:hover {
            background: #f97316 !important;
            color: #fff !important;
            border-color: #ea580c !important;
            -webkit-text-fill-color: #fff !important;
        }
        
        /* サンプルアバターグリッド */
        .sample-avatars-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .sample-avatar-item {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 50%;
            cursor: pointer;
            overflow: hidden;
            border: 3px solid transparent;
            transition: all 0.2s ease;
            background: #fff;
        }
        
        .sample-avatar-item:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .sample-avatar-item.selected {
            border-color: #667eea;
            box-shadow: 0 0 0 2px #667eea;
        }
        
        .sample-avatar-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* アバターエディター横並びレイアウト */
        .avatar-editor-row {
            display: flex;
            gap: 16px;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            padding: 12px 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        
        .avatar-preview-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
        }
        
        .avatar-preview-large {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            overflow: hidden;
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
        }
        
        .avatar-adjust-area {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
            align-items: center;
        }
        
        .avatar-adjust-section {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: center;
        }
        
        .avatar-adjust-section label {
            font-size: 11px;
            font-weight: 600;
            color: #666;
            margin: 0;
        }
        
        .avatar-position-controls {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        
        .avatar-pos-row {
            display: flex;
            gap: 2px;
        }
        
        .avatar-pos-btn {
            width: 26px;
            height: 26px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .avatar-pos-btn:hover {
            background: #e0e0e0;
        }
        
        .avatar-pos-btn:active {
            background: var(--primary, #667eea);
            color: white;
        }
        
        .avatar-pos-reset {
            background: #f0f0f0;
        }
        
        .avatar-pos-value {
            font-size: 9px;
            color: #888;
            margin-top: 2px;
        }
        
        .avatar-size-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 12px;
            color: #333;
        }
        
        .avatar-size-btn {
            width: 26px;
            height: 26px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .avatar-size-btn:hover {
            background: #e0e0e0;
        }
        
        .avatar-size-btn:active {
            background: var(--primary, #667eea);
            color: white;
        }
        
        /* アバタースタイルグリッド */
        .avatar-style-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .avatar-style-item {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
        }
        
        .avatar-style-item:hover {
            transform: scale(1.1);
        }
        
        .avatar-style-item.selected {
            border-color: #333;
            box-shadow: 0 0 0 2px rgba(0,0,0,0.2);
        }
        
        .icon-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 15px 0;
            color: #999;
            font-size: 13px;
        }
        
        .icon-divider::before,
        .icon-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #ddd;
        }
        
        .icon-divider span {
            padding: 0 15px;
        }
        
        /* モーダル */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .modal {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .modal-close:hover {
            color: #333;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
    </style>
    <script src="assets/js/topbar-standalone.js"></script>
</body>
</html>






