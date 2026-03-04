<?php
/**
 * Social9 ログイン画面
 * - メール認証（OTP）でログイン/新規登録
 * - パスワードでログイン
 */
ob_start();

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/google_login.php';
require_once __DIR__ . '/includes/auth/Auth.php';
require_once __DIR__ . '/includes/lang.php';

// 既にログイン済みの場合はチャットへリダイレクト（絶対URLで移転後も確実）
// 携帯版ではグループチャット一覧がトップページの役割のため、常に chat.php（会話未指定）へ
if (isLoggedIn()) {
    $base = getBaseUrl();
    $chatUrl = ($base !== '' ? $base . '/chat.php' : 'chat.php');
    header('Location: ' . $chatUrl);
    exit;
}

$currentLang = getCurrentLanguage();
$pdo = getDB();
$auth = new Auth($pdo);

$error = '';
$success = '';
$mode = $_GET['mode'] ?? 'password'; // password, otp, set_password

// GETパラメータからのエラー処理
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid':
            $error = __('login_error');
            break;
        case 'required':
            $error = __('required_field');
            break;
        case 'session':
            $error = __('session_expired');
            break;
        case 'google_denied':
            $error = 'Googleログインをキャンセルしました。';
            break;
        case 'google_auth_failed':
        case 'google_no_email':
        case 'token_failed':
        case 'state_mismatch':
        case 'invalid_callback':
            $error = 'Googleログインに失敗しました。もう一度お試しください。';
            break;
        case 'google_webview_blocked':
            $error = 'Googleログインは、アプリ内ブラウザでは利用できません。ChromeやSafariなどのブラウザで social9.jp を開いてから、もう一度「Googleでログイン」をお試しください。';
            break;
        case 'google_login_disabled':
            $error = 'Googleログインは設定されていません。';
            break;
        case 'user_create_failed':
            $error = 'アカウントの作成に失敗しました。管理者にお問い合わせください。';
            break;
        case 'server_error':
        case 'google_login_unavailable':
            $error = 'Googleログイン処理でエラーが発生しました。しばらくしてから再度お試しください。解決しない場合は管理者にお問い合わせください。';
            break;
    }
}

// パスワードログイン処理（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'メールアドレスとパスワードを入力してください。';
    } else {
        $result = $auth->login($email, $password);
        
        if ($result['success']) {
            $base = getBaseUrl();
            $chatUrl = ($base !== '' ? $base . '/chat.php' : 'chat.php');
            header('Location: ' . $chatUrl);
            exit;
        } else {
            $error = $result['message'];
            $mode = 'password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Social9 - <?= __('login') ?></title>
    
    <?php $pwa_icon_v = file_exists(__DIR__.'/assets/icons/icon-192x192.png') ? filemtime(__DIR__.'/assets/icons/icon-192x192.png') : '1'; ?>
    <!-- PWA対応: マニフェストとアイコン（?v= でキャッシュ無効化し、ロゴ差し替え後に反映） -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#4a6741">
    <meta name="application-name" content="Social9">
    
    <!-- iOS Safari対応: ホーム画面追加 -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Social9">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=<?= $pwa_icon_v ?>">
    
    <!-- ファビコン（SVGフォールバック付き） -->
    <link rel="icon" type="image/svg+xml" href="assets/icons/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png?v=<?= $pwa_icon_v ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png?v=<?= $pwa_icon_v ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/pwa-install.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Noto Sans JP', 'Hiragino Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(180deg, #f5f5f5 0%, #e8e8e8 100%);
        }
        
        .login-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 48px 56px;
            width: 100%;
            max-width: 480px;
            text-align: center;
        }
        
        .logo { margin-bottom: 24px; }
        .logo img { width: 140px; height: 140px; object-fit: contain; }
        
        .philosophy {
            font-size: 15px;
            color: #333;
            line-height: 1.8;
            margin-bottom: 32px;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .section-header span { font-size: 13px; color: #888; font-weight: 400; letter-spacing: 1px; }
        .section-header .brand { font-size: 18px; font-weight: 600; color: #6b8e23; }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
        }
        
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        
        .form-section { display: none; }
        .form-section.active { display: block; }
        
        .form-group { margin-bottom: 20px; text-align: left; }
        
        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-label .icon { font-size: 16px; }
        
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafafa;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #6b8e23;
            box-shadow: 0 0 0 3px rgba(107, 142, 35, 0.1);
            background: white;
        }
        
        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 20px 0;
        }
        
        .otp-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid #ddd;
            border-radius: 10px;
            transition: all 0.2s;
        }
        
        .otp-input:focus {
            outline: none;
            border-color: #6b8e23;
            box-shadow: 0 0 0 3px rgba(107, 142, 35, 0.1);
        }
        
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6b8e23 0%, #7aa329 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a7a1e 0%, #6b8e23 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(107, 142, 35, 0.3);
        }
        
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-secondary {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background: #eee;
            border-color: #ccc;
        }
        
        .btn-google {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px 24px;
            border: 1px solid #dadce0;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            color: #3c4043;
            background: #fff;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-google:hover {
            background: #f8f9fa;
            border-color: #dadce0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .google-login-hint {
            font-size: 12px;
            color: #888;
            margin-top: 10px;
            margin-bottom: 0;
            line-height: 1.5;
            text-align: center;
        }
        
        .btn .arrow { font-size: 18px; }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 28px 0;
            color: #999;
            font-size: 13px;
        }
        
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e0e0e0;
        }
        
        .divider span { padding: 0 16px; }
        
        .alt-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #666;
            font-size: 14px;
            cursor: pointer;
            padding: 12px;
            border-radius: 8px;
            transition: background 0.2s;
            text-decoration: none;
            background: none;
            border: none;
            width: 100%;
        }
        
        .alt-login:hover { background: #f5f5f5; color: #333; }
        .alt-login .icon { font-size: 16px; }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #888;
            font-size: 13px;
            text-decoration: none;
            margin-bottom: 20px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        
        .back-link:hover { color: #333; }
        
        .timer {
            font-size: 13px;
            color: #888;
            margin: 16px 0;
        }
        
        .timer.expired { color: #dc2626; }
        
        .resend-link {
            color: #6b8e23;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
        }
        
        .resend-link:hover { text-decoration: underline; }
        .resend-link.disabled { color: #999; cursor: not-allowed; }
        
        .password-requirements {
            font-size: 12px;
            color: #888;
            text-align: left;
            margin-top: 8px;
        }
        
        .password-requirements li { margin: 4px 0; }
        
        .form-subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }
        
        .loading {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .loading.show { display: flex; }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #ddd;
            border-top-color: #6b8e23;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="assets/images/logo-socialnine.png" alt="Social Nine">
        </div>
        
        <p class="philosophy">
            人として 人のために動き<br>
            明るい豊かな社会を創造する
        </p>
        
        <div class="section-header">
            <span>Our Philosophy</span>
            <span class="brand">Social9</span>
        </div>
        
        <div id="alertArea">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
        </div>
        
        <!-- ① パスワードログイン（デフォルト） -->
        <div class="form-section <?= $mode === 'password' ? 'active' : '' ?>" id="passwordSection">
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="icon">📧</span>
                        メールアドレスまたは携帯電話番号
                    </label>
                    <input type="text" name="email" id="loginEmail" class="form-input" placeholder="example@email.com または 09012345678" autocomplete="username" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="icon">🔑</span>
                        パスワード
                    </label>
                    <input type="password" name="password" class="form-input" placeholder="パスワードを入力" autocomplete="current-password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    ログイン
                    <span class="arrow">→</span>
                </button>
            </form>
            
            <div class="divider">
                <span>または</span>
            </div>
            
            <?php if (isGoogleLoginEnabled()): ?>
            <a href="api/google-login-auth.php" class="btn btn-google" id="googleLoginBtn">
                <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Googleでログイン
            </a>
            <p class="google-login-hint">※アプリ内ブラウザではGoogleがブロックする場合があります。その場合はChromeやSafariで social9.jp を開いてログインしてください。</p>
            <div class="divider">
                <span>または</span>
            </div>
            <?php endif; ?>
            
            <button type="button" class="btn btn-secondary" id="sendCodeBtn" onclick="sendVerificationCodeFromLogin()">
                <span id="sendCodeText">📧 コード送信（新規登録）</span>
                <span class="loading" id="sendCodeLoading">
                    <span class="spinner"></span>
                    送信中...
                </span>
            </button>
            
            <div style="margin-top: 16px; text-align: center;">
                <a href="forgot_password.php" class="alt-login" style="display: inline-flex; width: auto;">
                    <span class="icon">🔓</span>
                    パスワードを忘れた方
                </a>
            </div>
            <div style="margin-top: 8px; text-align: center;">
                <a href="multi_account_login.php" class="alt-login" style="display: inline-flex; width: auto;">
                    <span class="icon">👥</span>
                    複数アカウントで同時ログイン（会話テスト用）
                </a>
            </div>
        </div>
        
        <!-- ② 認証コード入力 -->
        <div class="form-section <?= $mode === 'otp' ? 'active' : '' ?>" id="otpSection">
            <button type="button" class="back-link" onclick="showSection('password')">
                ← 戻る
            </button>
            
            <p class="form-subtitle">
                <span id="otpEmail"></span> に<br>認証コードを送信しました
            </p>
            
            <div class="otp-inputs">
                <input type="text" class="otp-input" maxlength="1" data-index="0" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" data-index="1" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" data-index="2" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" data-index="3" inputmode="numeric">
            </div>
            
            <div class="timer" id="otpTimer">有効期限: 15:00</div>
            
            <button type="button" class="btn btn-primary" id="verifyCodeBtn" onclick="verifyCode()">
                <span id="verifyCodeText">確認</span>
                <span class="loading" id="verifyCodeLoading">
                    <span class="spinner"></span>
                    確認中...
                </span>
            </button>
            
            <div style="margin-top: 16px;">
                <span class="resend-link disabled" id="resendLink" onclick="resendCode()">コードを再送信</span>
            </div>
        </div>
        
        <!-- ③ パスワード設定（新規登録完了） -->
        <div class="form-section <?= $mode === 'set_password' ? 'active' : '' ?>" id="setPasswordSection">
            <p class="form-subtitle">🎉 認証完了！<br>パスワードを設定してください</p>
            
            <div class="form-group" id="setPasswordEmailWrap" style="display: none;">
                <label class="form-label">
                    <span class="icon">📧</span>
                    メールアドレス（任意）
                </label>
                <input type="email" id="setPasswordEmail" class="form-input" placeholder="example@email.com" autocomplete="email">
                <div class="form-hint" style="font-size: 12px; color: #666; margin-top: 4px;">登録するとメール・携帯のどちらでもログインできます</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <span class="icon">🔒</span>
                    パスワード
                </label>
                <input type="password" id="newPassword" class="form-input" placeholder="8文字以上" autocomplete="new-password">
                <ul class="password-requirements">
                    <li>8文字以上</li>
                </ul>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <span class="icon">🔒</span>
                    パスワード（確認）
                </label>
                <input type="password" id="confirmPassword" class="form-input" placeholder="もう一度入力" autocomplete="new-password">
            </div>
            
            <button type="button" class="btn btn-primary" id="setPasswordBtn" onclick="setPassword()">
                <span id="setPasswordText">登録を完了する</span>
                <span class="loading" id="setPasswordLoading">
                    <span class="spinner"></span>
                    設定中...
                </span>
            </button>
        </div>
        
    </div>
    
    <script>
        // 状態管理（メール or 携帯でコード送信時に設定）
        let currentEmail = '';
        let currentPhone = '';
        let otpToken = null;
        let timerInterval = null;
        let resendCooldown = 0;
        
        // セクション切り替え
        function showSection(sectionId) {
            document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
            document.getElementById(sectionId + 'Section').classList.add('active');
            clearAlert();
        }
        
        // アラート表示（XSS対策: textContentで表示）
        function showAlert(message, type = 'error') {
            const alertArea = document.getElementById('alertArea');
            const div = document.createElement('div');
            div.className = 'alert alert-' + type;
            div.textContent = message;
            alertArea.innerHTML = '';
            alertArea.appendChild(div);
        }
        
        function clearAlert() {
            document.getElementById('alertArea').innerHTML = '';
        }
        
        // ローディング表示
        function setLoading(btnId, loading) {
            const btn = document.getElementById(btnId);
            const text = document.getElementById(btnId.replace('Btn', 'Text'));
            const loadingEl = document.getElementById(btnId.replace('Btn', 'Loading'));
            
            btn.disabled = loading;
            if (text) text.classList.toggle('hidden', loading);
            if (loadingEl) loadingEl.classList.toggle('show', loading);
        }
        
        // ログインページから認証コード送信（メール or 携帯）
        async function sendVerificationCodeFromLogin() {
            const raw = document.getElementById('loginEmail').value.trim();
            if (!raw) {
                showAlert('メールアドレスまたは携帯電話番号を入力してください');
                return;
            }
            if (raw.includes('@')) {
                await sendVerificationCode({ email: raw });
                return;
            }
            const phone = raw.replace(/\D/g, '');
            if (phone.length >= 10) {
                await sendVerificationCode({ phone: phone });
                return;
            }
            showAlert('有効なメールアドレスまたは10桁以上の携帯電話番号を入力してください');
        }

        // 認証コード送信（email または phone を渡す）
        async function sendVerificationCode(payload) {
            const hasEmail = payload.email && payload.email.includes('@');
            const hasPhone = payload.phone && payload.phone.length >= 10;
            if (!hasEmail && !hasPhone) {
                if (currentEmail) payload = { email: currentEmail };
                else if (currentPhone) payload = { phone: currentPhone };
                else {
                    showAlert('メールアドレスまたは携帯電話番号を入力してください');
                    return;
                }
            }

            setLoading('sendCodeBtn', true);
            clearAlert();

            try {
                const body = { action: 'send_code' };
                if (payload.email) body.email = payload.email;
                if (payload.phone) body.phone = payload.phone;

                const response = await fetch('api/auth_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                const data = await response.json();

                if (data.success) {
                    if (payload.phone) {
                        currentPhone = payload.phone;
                        currentEmail = '';
                        document.getElementById('otpEmail').textContent = payload.phone.slice(0, 4) + '****' + payload.phone.slice(-4);
                    } else {
                        currentEmail = payload.email;
                        currentPhone = '';
                        document.getElementById('otpEmail').textContent = payload.email;
                    }
                    showSection('otp');
                    startTimer(data.expires_in || 900);
                    startResendCooldown(60);
                    document.querySelector('.otp-input').focus();
                } else {
                    showAlert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                showAlert('通信エラーが発生しました');
            } finally {
                setLoading('sendCodeBtn', false);
            }
        }
        
        // 認証コード検証
        async function verifyCode() {
            const inputs = document.querySelectorAll('.otp-input');
            const code = Array.from(inputs).map(i => i.value).join('');
            
            if (code.length !== 4) {
                showAlert('4桁のコードを入力してください');
                return;
            }
            
            setLoading('verifyCodeBtn', true);
            clearAlert();
            
            try {
                const body = { action: 'verify_code', code };
                if (currentPhone) body.phone = currentPhone; else body.email = currentEmail;
                const response = await fetch('api/auth_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                const data = await response.json();

                if (data.success) {
                    if (data.action === 'login') {
                        window.location.href = data.redirect || 'chat.php';
                    } else if (data.action === 'set_password') {
                        otpToken = data.token;
                        if (data.phone) { currentPhone = data.phone; currentEmail = ''; }
                        else { currentEmail = data.email || ''; currentPhone = ''; }
                        showSection('setPassword');
                        var emailWrap = document.getElementById('setPasswordEmailWrap');
                        if (emailWrap) emailWrap.style.display = currentPhone ? 'block' : 'none';
                        var emailInput = document.getElementById('setPasswordEmail');
                        if (emailInput) emailInput.value = '';
                    }
                } else {
                    showAlert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                showAlert('通信エラーが発生しました');
            } finally {
                setLoading('verifyCodeBtn', false);
            }
        }
        
        // パスワード設定
        async function setPassword() {
            const password = document.getElementById('newPassword').value;
            const passwordConfirm = document.getElementById('confirmPassword').value;
            
            if (password.length < 8) {
                showAlert('パスワードは8文字以上で設定してください');
                return;
            }
            
            if (password !== passwordConfirm) {
                showAlert('パスワードが一致しません');
                return;
            }
            
            setLoading('setPasswordBtn', true);
            clearAlert();
            
            try {
                const body = { action: 'set_password', token: otpToken, password, password_confirm: passwordConfirm };
                if (currentPhone) {
                    body.phone = currentPhone;
                    var emailEl = document.getElementById('setPasswordEmail');
                    if (emailEl && emailEl.value.trim()) body.email = emailEl.value.trim();
                } else {
                    body.email = currentEmail;
                }
                const response = await fetch('api/auth_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = data.redirect || 'chat.php';
                } else {
                    showAlert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                showAlert('通信エラーが発生しました');
            } finally {
                setLoading('setPasswordBtn', false);
            }
        }
        
        // OTPタイマー
        function startTimer(seconds) {
            clearInterval(timerInterval);
            let remaining = seconds;
            
            const timerEl = document.getElementById('otpTimer');
            
            function updateTimer() {
                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                timerEl.textContent = `有効期限: ${mins}:${secs.toString().padStart(2, '0')}`;
                
                if (remaining <= 0) {
                    clearInterval(timerInterval);
                    timerEl.textContent = 'コードの有効期限が切れました';
                    timerEl.classList.add('expired');
                }
                remaining--;
            }
            
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
        }
        
        // 再送信クールダウン
        function startResendCooldown(seconds) {
            resendCooldown = seconds;
            const link = document.getElementById('resendLink');
            
            function updateCooldown() {
                if (resendCooldown > 0) {
                    link.textContent = `再送信まで ${resendCooldown}秒`;
                    link.classList.add('disabled');
                    resendCooldown--;
                    setTimeout(updateCooldown, 1000);
                } else {
                    link.textContent = 'コードを再送信';
                    link.classList.remove('disabled');
                }
            }
            
            updateCooldown();
        }
        
        // コード再送信
        function resendCode() {
            if (resendCooldown > 0) return;
            // 入力フィールドをクリア
            document.querySelectorAll('.otp-input').forEach(input => {
                input.value = '';
            });
            // 最初のフィールドにフォーカス
            const firstInput = document.querySelector('.otp-input');
            if (firstInput) firstInput.focus();
            // コードを再送信（メール or 携帯）
            if (currentPhone) sendVerificationCode({ phone: currentPhone });
            else if (currentEmail) sendVerificationCode({ email: currentEmail });
        }
        
        // 全角数字を半角に変換するヘルパー
        function toHalfWidth(str) {
            return str.replace(/[０-９]/g, s => String.fromCharCode(s.charCodeAt(0) - 0xFEE0));
        }
        
        // OTP入力のキーボード操作
        document.querySelectorAll('.otp-input').forEach((input, index, inputs) => {
            input.addEventListener('input', function(e) {
                // 全角→半角変換してから数字以外を除去
                const value = toHalfWidth(e.target.value).replace(/\D/g, '');
                e.target.value = value;
                
                if (value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                
                // 全入力完了時に自動検証
                const allFilled = Array.from(inputs).every(i => i.value);
                if (allFilled) {
                    verifyCode();
                }
            });
            
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });
            
            // ペースト対応
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                const digits = toHalfWidth(pastedData).replace(/\D/g, '').split('').slice(0, 4);
                
                digits.forEach((digit, i) => {
                    if (inputs[i]) inputs[i].value = digit;
                });
                
                if (digits.length === 4) {
                    verifyCode();
                }
            });
        });
        
    </script>
    <script src="assets/js/pwa-install.js"></script>
</body>
</html>
