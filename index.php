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
require_once __DIR__ . '/includes/access_logger.php';

log_page_access('/index.php');

// 既にログイン済みの場合はチャットへリダイレクト（絶対URLで移転後も確実）
// 携帯版ではグループチャット一覧がトップページの役割のため、常に chat.php（会話未指定）へ
if (isLoggedIn()) {
    $base = getBaseUrl();
    $chatUrl = ($base !== '' ? $base . '/chat.php' : 'chat.php');
    header('Location: ' . $chatUrl);
    exit;
}

$currentLang = getCurrentLanguage();

// ログイン画面での言語切替（GET lang=ja|en|zh でセッションに保存してリダイレクト）
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ja', 'en', 'zh'], true)) {
    setLanguage($_GET['lang']);
    $base = getBaseUrl();
    $url = ($base !== '' ? $base . '/index.php' : 'index.php');
    header('Location: ' . $url);
    exit;
}

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
<html lang="<?= $currentLang === 'en' ? 'en' : ($currentLang === 'zh' ? 'zh' : 'ja') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?> - <?= __('login') ?></title>
    <meta name="description" content="<?= $currentLang === 'en' ? 'Social9: Free social app for business, family, community, clubs, and hobbies. Group chat, AI secretary, tasks, file sharing, voice and video calls. Japanese, English, Chinese.' : ($currentLang === 'zh' ? 'Social9：面向公司、家庭、社区、社团与兴趣小组的免费社交应用。群聊、AI秘书、任务、文件共享、音视频通话。日英中多语言。' : 'Social9は町内会・部活・サークル・趣味の会から家族の連絡・会社の業務報告まで無料で使えるソーシャルアプリ。グループチャット、AI秘書、タスク・メモ、共有フォルダ、音声・ビデオ通話。日英中対応。') ?>">
    <meta name="keywords" content="<?= $currentLang === 'en' ? 'free chat app, group chat, business communication, family chat, community app, AI secretary, task management, file sharing, video call, Japanese English Chinese' : ($currentLang === 'zh' ? '免费聊天应用,群聊,商务沟通,家庭联络,社区应用,AI秘书,任务管理,文件共享,视频通话,日英中' : '無料チャットアプリ,グループチャット,業務連絡,家族チャット,町内会,部活 連絡,サークル 連絡,AI秘書,タスク管理,ファイル共有,ビデオ通話,日本語 英語 中国語') ?>">
    
    <?php $pwa_icon_v = file_exists(__DIR__.'/assets/icons/icon-192x192.png') ? filemtime(__DIR__.'/assets/icons/icon-192x192.png') : '1'; ?>
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#4a6741">
    <meta name="application-name" content="<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?>">
    
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?>">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=<?= $pwa_icon_v ?>">
    
    <link rel="icon" type="image/svg+xml" href="assets/icons/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png?v=<?= $pwa_icon_v ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png?v=<?= $pwa_icon_v ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/pwa-install.css">
    <!-- 上パネル共有ルール: header.css → panel-panels-unified.css → login-landing.css -->
    <link rel="stylesheet" href="assets/css/layout/header.css">
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css">
    <link rel="stylesheet" href="assets/css/login-landing.css">
</head>
<body class="page-login">
    <?php include __DIR__ . '/includes/login_topbar.php'; ?>
    <div class="main-container" id="loginMainContainer">
        <div class="left-panel" id="loginFormPanel">
            <div class="login-panel-form" id="login-form">
        <div class="logo">
            <?php if (file_exists(__DIR__ . '/assets/images/logo-socialnine.png')): ?>
                <img src="assets/images/logo-socialnine.png" alt="Social Nine">
            <?php else: ?>
                <span class="logo-text" aria-hidden="true"><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?></span>
            <?php endif; ?>
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
        </div>
        <!-- 中央パネル：キャッチ・主な機能・用途・使い方 -->
        <div class="center-panel">
            <?php include __DIR__ . '/includes/login_landing_center.php'; ?>
            <footer class="login-landing-footer" role="contentinfo">
                <p><a href="terms.php"><?= __('terms_of_service') ?></a> · <a href="terms.php#privacy"><?= __('privacy_policy') ?></a></p>
                <p>© Social9</p>
                <p><?= $currentLang === 'en' ? 'This service is in trial operation. Please see the terms of use.' : ($currentLang === 'zh' ? '本服务处于试运行阶段，请参阅利用规约。' : '本サービスは試験運用の段階です。利用規約をご確認の上ご利用ください。') ?></p>
                <p><?= $currentLang === 'en' ? 'Recommended: Chrome, Safari or other browsers.' : ($currentLang === 'zh' ? '推荐使用 Chrome、Safari 等浏览器。' : '推奨: Chrome、Safari 等のブラウザでご利用ください。') ?></p>
            </footer>
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
