<?php
/**
 * OTP認証API
 * ワンタイムパスワード（認証コード）の送信・検証を行う
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/SmsSender.php';

start_session_once();

$pdo = getDB();

// POSTデータを取得
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'send_code':
            sendVerificationCode($pdo, $input);
            break;
            
        case 'verify_code':
            verifyCode($pdo, $input);
            break;
            
        case 'set_password':
            setPassword($pdo, $input);
            break;
            
        default:
            jsonError('無効なアクションです');
    }
} catch (Exception $e) {
    error_log("OTP API Error: " . $e->getMessage());
    jsonError('エラーが発生しました');
}

/**
 * 認証コード送信（メールまたは携帯電話）
 * 入力に phone があれば SMS、なければ email でメール送信
 */
function sendVerificationCode($pdo, $input) {
    $phone = preg_replace('/\D/', '', trim($input['phone'] ?? ''));
    $email = trim($input['email'] ?? '');

    if (!empty($phone)) {
        sendSmsVerificationCode($pdo, $phone);
        return;
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('有効なメールアドレスまたは携帯電話番号を入力してください');
    }

    // ユーザーが存在するかチェック
    $stmt = $pdo->prepare("SELECT id, display_name, password_hash FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $isNewUser = !$user;

    // レート制限: 1分以内に再送信を防止
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM email_verification_codes 
        WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        jsonError('1分以内に再送信できません。しばらくお待ちください');
    }

    // 4桁のランダムコードを生成
    $code = sprintf('%04d', random_int(0, 9999));

    $stmt = $pdo->query("SELECT NOW() as db_now");
    $dbNow = $stmt->fetch()['db_now'];
    $expiresAt = date('Y-m-d H:i:s', strtotime($dbNow . ' +15 minutes'));

    $pdo->prepare("DELETE FROM email_verification_codes WHERE email = ?")->execute([$email]);

    $stmt = $pdo->prepare("
        INSERT INTO email_verification_codes (email, code, expires_at, is_new_user, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$email, $code, $expiresAt, $isNewUser ? 1 : 0]);

    $mailer = new Mailer();
    $sent = $mailer->sendVerificationCode($email, $code, $isNewUser);

    error_log("OTP Send: email={$email}, code={$code}, driver=" . (defined('MAIL_DRIVER') ? MAIL_DRIVER : 'undefined') . ", host=" . (defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : 'undefined') . ", from=" . (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'undefined') . ", result=" . ($sent ? 'OK' : 'FAIL'));

    if (!$sent) {
        jsonError('メールの送信に失敗しました。しばらく経ってからお試しください。迷惑メールフォルダもご確認ください。送信設定の確認は管理者へお問い合わせください。');
    }

    jsonSuccess([
        'message' => '認証コードを送信しました',
        'is_new_user' => $isNewUser,
        'expires_in' => 15 * 60
    ]);
}

/**
 * SMS認証コード送信（携帯番号のみ正規化済み）
 */
function sendSmsVerificationCode($pdo, $phone) {
    if (strlen($phone) < 10 || strlen($phone) > 15) {
        jsonError('携帯電話番号は10〜15桁で入力してください');
    }

    $stmt = $pdo->prepare("SELECT id, display_name, password_hash FROM users WHERE phone = ? AND status = 'active'");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    $isNewUser = !$user;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM sms_verification_codes 
        WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$phone]);
    if ($stmt->fetchColumn() > 0) {
        jsonError('1分以内に再送信できません。しばらくお待ちください');
    }

    $code = sprintf('%04d', random_int(0, 9999));

    $stmt = $pdo->query("SELECT NOW() as db_now");
    $dbNow = $stmt->fetch()['db_now'];
    $expiresAt = date('Y-m-d H:i:s', strtotime($dbNow . ' +10 minutes'));

    $pdo->prepare("DELETE FROM sms_verification_codes WHERE phone = ?")->execute([$phone]);

    $stmt = $pdo->prepare("
        INSERT INTO sms_verification_codes (phone, code, expires_at, is_new_user, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$phone, $code, $expiresAt, $isNewUser ? 1 : 0]);

    $sms = new SmsSender();
    $sent = $sms->sendVerificationCode($phone, $code);
    if (!$sent) {
        jsonError('SMSの送信に失敗しました。しばらく経ってからお試しください');
    }

    jsonSuccess([
        'message' => '認証コードをSMSで送信しました',
        'is_new_user' => $isNewUser,
        'expires_in' => 10 * 60
    ]);
}

/**
 * 認証コード検証（メールまたは携帯電話）
 */
function verifyCode($pdo, $input) {
    $code = trim($input['code'] ?? '');
    $code = mb_convert_kana($code, 'n', 'UTF-8');
    $code = preg_replace('/[^0-9]/', '', $code);

    $phone = preg_replace('/\D/', '', trim($input['phone'] ?? ''));
    $email = trim($input['email'] ?? '');

    if (empty($code)) {
        jsonError('認証コードを入力してください');
    }

    if (!empty($phone)) {
        verifyCodeByPhone($pdo, $phone, $code);
        return;
    }

    if (empty($email)) {
        jsonError('メールアドレスと認証コードを入力してください');
    }

    $stmt = $pdo->prepare("
        SELECT * FROM email_verification_codes 
        WHERE email = ? AND expires_at > NOW() AND verified_at IS NULL
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$email]);
    $record = $stmt->fetch();

    if (!$record) {
        $stmt2 = $pdo->prepare("
            SELECT id, expires_at, verified_at FROM email_verification_codes 
            WHERE email = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmt2->execute([$email]);
        $lastRecord = $stmt2->fetch();
        if ($lastRecord && $lastRecord['verified_at']) {
            jsonError('この認証コードは既に使用されています。新しいコードを取得してください');
        }
        if ($lastRecord && strtotime($lastRecord['expires_at']) < time()) {
            jsonError('認証コードの有効期限が切れています。新しいコードを取得してください');
        }
        jsonError('認証コードが無効または期限切れです');
    }

    $attempts = (int)($record['attempts'] ?? 0);
    if ($attempts >= 5) {
        jsonError('試行回数の上限に達しました。新しいコードを取得してください');
    }

    $pdo->prepare("UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$record['id']]);

    $storedCode = $record['code'];
    $isMatch = (strlen($storedCode) > 10) ? password_verify($code, $storedCode) : ($code === $storedCode);
    if (!$isMatch) {
        $remaining = 5 - $attempts - 1;
        jsonError("認証コードが正しくありません（残り{$remaining}回）");
    }

    $pdo->prepare("UPDATE email_verification_codes SET verified_at = NOW() WHERE id = ?")->execute([$record['id']]);

    $stmt = $pdo->prepare("SELECT id, display_name, password_hash FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $isNewUser = !$user;
    $needsPassword = $isNewUser || empty($user['password_hash']);

    if (!$isNewUser && !$needsPassword) {
        setLoginSession($pdo, $user['id']);
        jsonSuccess(['action' => 'login', 'message' => 'ログインしました', 'redirect' => 'chat.php']);
    }

    $token = bin2hex(random_bytes(32));
    if ($isNewUser) {
        $stmt = $pdo->prepare("
            INSERT INTO users (email, display_name, status, created_at, updated_at)
            VALUES (?, ?, 'active', NOW(), NOW())
        ");
        $stmt->execute([$email, $email]);
        $userId = $pdo->lastInsertId();
        initializePrivacySettings($pdo, $userId);
    } else {
        $userId = $user['id'];
    }

    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $pdo->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?")->execute([$token, $expiresAt, $userId]);

    jsonSuccess([
        'action' => 'set_password',
        'message' => 'パスワードを設定してください',
        'token' => $token,
        'email' => $email,
        'phone' => '',
        'is_new_user' => $isNewUser
    ]);
}

/**
 * 電話番号で認証コード検証
 */
function verifyCodeByPhone($pdo, $phone, $code) {
    $stmt = $pdo->prepare("
        SELECT * FROM sms_verification_codes 
        WHERE phone = ? AND expires_at > NOW() AND verified_at IS NULL
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$phone]);
    $record = $stmt->fetch();

    if (!$record) {
        $stmt2 = $pdo->prepare("
            SELECT id, expires_at, verified_at FROM sms_verification_codes 
            WHERE phone = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmt2->execute([$phone]);
        $last = $stmt2->fetch();
        if ($last && $last['verified_at']) {
            jsonError('この認証コードは既に使用されています。新しいコードを取得してください');
        }
        if ($last && strtotime($last['expires_at']) < time()) {
            jsonError('認証コードの有効期限が切れています。新しいコードを取得してください');
        }
        jsonError('認証コードが無効または期限切れです');
    }

    $attempts = (int)($record['attempts'] ?? 0);
    if ($attempts >= 5) {
        jsonError('試行回数の上限に達しました。新しいコードを取得してください');
    }

    $pdo->prepare("UPDATE sms_verification_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$record['id']]);

    $storedCode = $record['code'];
    $isMatch = (strlen($storedCode) > 10) ? password_verify($code, $storedCode) : ($code === $storedCode);
    if (!$isMatch) {
        $remaining = 5 - $attempts - 1;
        jsonError("認証コードが正しくありません（残り{$remaining}回）");
    }

    $pdo->prepare("UPDATE sms_verification_codes SET verified_at = NOW() WHERE id = ?")->execute([$record['id']]);

    $stmt = $pdo->prepare("SELECT id, display_name, password_hash FROM users WHERE phone = ? AND status = 'active'");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    $isNewUser = !$user;
    $needsPassword = $isNewUser || empty($user['password_hash']);

    if (!$isNewUser && !$needsPassword) {
        setLoginSession($pdo, $user['id']);
        jsonSuccess(['action' => 'login', 'message' => 'ログインしました', 'redirect' => 'chat.php']);
    }

    $token = bin2hex(random_bytes(32));
    if ($isNewUser) {
        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, phone, password_hash, display_name, status, created_at, updated_at)
            VALUES (NULL, ?, ?, ?, 'active', NOW(), NOW())
        ");
        $stmt->execute([$phone, $placeholderHash, $phone]);
        $userId = $pdo->lastInsertId();
        initializePrivacySettings($pdo, $userId);
    } else {
        $userId = $user['id'];
    }

    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $pdo->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?")->execute([$token, $expiresAt, $userId]);

    jsonSuccess([
        'action' => 'set_password',
        'message' => 'パスワードを設定してください',
        'token' => $token,
        'email' => '',
        'phone' => $phone,
        'is_new_user' => $isNewUser
    ]);
}

/**
 * ログインセッションを設定
 */
function setLoginSession($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $fullUser = $stmt->fetch();
    $_SESSION['user_id'] = $userId;
    $_SESSION['email'] = $fullUser['email'] ?? '';
    $_SESSION['phone'] = $fullUser['phone'] ?? '';
    $_SESSION['display_name'] = $fullUser['display_name'] ?? '';
    $_SESSION['full_name'] = $fullUser['full_name'] ?? '';
    $_SESSION['role'] = $fullUser['role'] ?? 'user';
    $_SESSION['auth_level'] = (int)($fullUser['auth_level'] ?? 1);
    $_SESSION['is_minor'] = (bool)($fullUser['is_minor'] ?? false);
    $_SESSION['is_org_admin'] = in_array($fullUser['role'] ?? '', ['developer', 'system_admin', 'org_admin', 'admin']) ? 1 : 0;
    $_SESSION['organization_id'] = (int)($fullUser['organization_id'] ?? 1);
    $pdo->prepare("UPDATE users SET last_login = NOW(), online_status = 'online', last_seen = NOW() WHERE id = ?")->execute([$userId]);
}

/**
 * パスワード設定（メールまたは携帯電話でユーザー特定）
 */
function setPassword($pdo, $input) {
    $token = trim($input['token'] ?? '');
    $password = $input['password'] ?? '';
    $passwordConfirm = $input['password_confirm'] ?? '';
    $email = trim($input['email'] ?? '');
    $phone = preg_replace('/\D/', '', trim($input['phone'] ?? ''));

    if (empty($token) || empty($password)) {
        jsonError('必要な情報が不足しています');
    }

    if (strlen($password) < 8) {
        jsonError('パスワードは8文字以上で設定してください');
    }

    if ($password !== $passwordConfirm) {
        jsonError('パスワードが一致しません');
    }

    if (!empty($phone)) {
        $stmt = $pdo->prepare("
            SELECT id, display_name FROM users 
            WHERE phone = ? AND password_reset_token = ? AND password_reset_expires > NOW() AND status = 'active'
        ");
        $stmt->execute([$phone, $token]);
    } else {
        if (empty($email)) {
            jsonError('メールアドレスまたは電話番号が必要です');
        }
        $stmt = $pdo->prepare("
            SELECT id, display_name FROM users 
            WHERE email = ? AND password_reset_token = ? AND password_reset_expires > NOW() AND status = 'active'
        ");
        $stmt->execute([$email, $token]);
    }
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('無効または期限切れのリクエストです');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("
        UPDATE users SET 
            password_hash = ?,
            password_reset_token = NULL,
            password_reset_expires = NULL,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$passwordHash, $user['id']]);

    // 携帯で登録した場合、任意でメールを追加（両方でログイン可能にする）
    if (!empty($phone) && !empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('有効なメールアドレスを入力してください');
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            jsonError('このメールアドレスは既に別のアカウントで使用されています');
        }
        $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?")->execute([$email, $user['id']]);
    }

    setLoginSession($pdo, $user['id']);

    jsonSuccess([
        'message' => 'パスワードを設定しました',
        'redirect' => 'chat.php'
    ]);
}

/**
 * 成功レスポンス
 */
function jsonSuccess($data = []) {
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラーレスポンス
 */
function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * プライバシー設定を初期化（デフォルト: 検索可能＝携帯番号・名前で検索ヒットする）
 */
function initializePrivacySettings($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_privacy_settings (user_id, exclude_from_search, created_at, updated_at)
            VALUES (?, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        // テーブルが存在しない場合は無視
        error_log('Privacy settings init error: ' . $e->getMessage());
    }
}
