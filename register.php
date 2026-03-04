<?php
/**
 * Social9 新規登録画面
 * 仕様書: 45_オンボーディング.md
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth/Auth.php';

// 既にログイン済みの場合はリダイレクト
if (isLoggedIn()) {
    header('Location: chat.php');
    exit;
}

$pdo = getDB();
$auth = new Auth($pdo);

$error = '';
$success = '';
$show_verify_notice = false;
$form_data = [
    'email' => '',
    'phone' => '',
    'display_name' => '',
    'birth_date' => '',
    'prefecture' => '',
    'city' => ''
];

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

// 登録処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $phone = preg_replace('/\D/', '', trim($_POST['phone'] ?? '')); // 数字のみ
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $display_name = trim($_POST['display_name'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '';
    $prefecture = $_POST['prefecture'] ?? '';
    $city = trim($_POST['city'] ?? '');
    $agree_terms = isset($_POST['agree_terms']);
    $agree_privacy = isset($_POST['agree_privacy']);
    
    // フォームデータを保持（表示用は元の入力）
    $form_data = [
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'display_name' => $display_name,
        'birth_date' => $birth_date,
        'prefecture' => $prefecture,
        'city' => $city
    ];
    
    $register_by = $_POST['register_by'] ?? 'email';
    if ($register_by === 'phone') {
        $error = '';
    } elseif (empty($email) || empty($password) || empty($display_name)) {
        $error = '必須項目を入力してください。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '有効なメールアドレスを入力してください。';
    } elseif (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください。';
    } elseif ($password !== $password_confirm) {
        $error = 'パスワードが一致しません。';
    } elseif (strlen($display_name) > 50) {
        $error = '表示名は50文字以内で入力してください。';
    } elseif (!empty($phone) && strlen($phone) < 10) {
        $error = '携帯電話番号は10桁以上で入力してください。';
    } elseif (!empty($phone) && strlen($phone) > 15) {
        $error = '携帯電話番号は15桁以内で入力してください。';
    } elseif (!$agree_terms || !$agree_privacy) {
        $error = '利用規約とプライバシーポリシーに同意してください。';
    } else {
        try {
            $result = $auth->register($email, $password, $display_name, $birth_date, $prefecture, $city, $phone ?: null);

            if (!$result['success']) {
                $error = $result['message'];
            } else {
                $user_id = $result['user_id'];
                $is_minor = $result['is_minor'];

                $stmt = $pdo->prepare("
                    INSERT INTO consent_logs (user_id, consent_type, version, ip_address, user_agent)
                    VALUES (?, 'terms', '1.0', ?, ?), (?, 'privacy', '1.0', ?, ?)
                ");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $stmt->execute([$user_id, $ip, $ua, $user_id, $ip, $ua]);

                $verifyResult = $auth->sendEmailVerification($user_id);
                $show_verify_notice = true;
                $success = "登録が完了しました！\n\n" . $email . " に認証メールを送信しました。\n\n【開発用リンク】\n" . ($verifyResult['link'] ?? '');
            }
        } catch (PDOException $e) {
            error_log('Registration error: ' . $e->getMessage());
            $error = 'システムエラーが発生しました。しばらく経ってからお試しください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規登録 - Social9</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= file_exists(__DIR__.'/assets/css/mobile.css') ? filemtime(__DIR__.'/assets/css/mobile.css') : '1' ?>">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #f5f7f0 0%, #e8f0e0 100%);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            font-size: 36px;
            color: var(--primary);
        }
        
        .logo h1 span {
            color: var(--investor-gold);
        }
        
        .register-box {
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            padding: 40px;
            width: 100%;
            max-width: 480px;
        }
        
        .register-box h2 {
            font-size: 22px;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .register-box .subtitle {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 30px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }
        
        .checkbox-group input {
            margin-top: 4px;
        }
        
        .checkbox-group label {
            font-size: 13px;
            color: var(--text-light);
        }
        
        .checkbox-group a {
            color: var(--primary);
        }
        
        .divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border);
        }
        
        .divider span {
            position: relative;
            background: white;
            padding: 0 16px;
            color: var(--text-muted);
            font-size: 13px;
        }
        
        .login-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .login-link a {
            color: var(--primary);
            font-weight: 500;
        }
        
        .minor-notice {
            background: var(--warning-bg);
            border: 1px solid #ffe0a0;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #8b6914;
            margin-bottom: 20px;
        }
        
        .success-box {
            text-align: center;
            padding: 40px;
        }
        
        .success-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .success-message {
            white-space: pre-wrap;
            word-break: break-all;
            text-align: left;
            background: var(--bg);
            padding: 16px;
            border-radius: 8px;
            font-size: 13px;
            margin: 20px 0;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
        .register-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color, #e0e0e0);
        }
        .register-tab {
            flex: 1;
            padding: 10px 16px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            color: var(--text-light, #666);
        }
        .register-tab.active {
            color: var(--primary);
            font-weight: 600;
            border-bottom: 2px solid var(--primary);
            margin-bottom: -1px;
        }
        .register-by-phone .form-subtitle {
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--text);
        }
    </style>
</head>
<body>
    <div class="logo">
        <h1>☆ Social<span>9</span></h1>
    </div>
    
    <div class="register-box">
        <?php if ($show_verify_notice): ?>
            <div class="success-box">
                <div class="success-icon">📧</div>
                <h2>メールを確認してください</h2>
                <div class="success-message"><?= h($success) ?></div>
                <a href="index.php" class="btn btn-primary btn-lg btn-block">ログイン画面へ</a>
            </div>
        <?php else: ?>
            <h2>新規登録</h2>
            <p class="subtitle">Social9へようこそ！アカウントを作成しましょう。</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <div class="minor-notice" style="background: #e8f4fd; border-color: #90cdf4; color: #2b6cb0;">
                ℹ️ 18歳未満の方で保護者機能を利用する場合は、生年月日を入力してください。<br>
                登録後に保護者との紐付けが可能になります。
            </div>

            <div class="register-tabs" role="tablist">
                <button type="button" class="register-tab active" data-tab="email" role="tab" aria-selected="true">メールで登録</button>
                <button type="button" class="register-tab" data-tab="phone" role="tab" aria-selected="false">携帯電話で登録</button>
            </div>
            
            <form method="POST" action="" id="registerFormEmail">
                <input type="hidden" name="register_by" value="email">
                <div class="form-group">
                    <label class="form-label required">メールアドレス</label>
                    <input type="email" name="email" class="form-input" 
                           value="<?= h($form_data['email']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">携帯電話（任意）</label>
                    <input type="tel" name="phone" class="form-input" 
                           value="<?= h($form_data['phone']) ?>" 
                           placeholder="09012345678" maxlength="15" autocomplete="tel">
                    <div class="form-hint">メールと携帯の両方を登録すると、どちらでもログインできます。ハイフンなしで入力</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">パスワード</label>
                    <input type="password" name="password" class="form-input" 
                           minlength="8" required>
                    <div class="form-hint">8文字以上で入力してください</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">パスワード（確認）</label>
                    <input type="password" name="password_confirm" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">表示名（ニックネーム）</label>
                    <input type="text" name="display_name" class="form-input" 
                           value="<?= h($form_data['display_name']) ?>" 
                           maxlength="50" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">生年月日（任意）</label>
                    <input type="date" name="birth_date" class="form-input" 
                           value="<?= h($form_data['birth_date']) ?>">
                    <div class="form-hint">保護者機能を利用する場合に必要です</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">都道府県</label>
                        <select name="prefecture" class="form-select">
                            <option value="">選択してください</option>
                            <?php foreach ($prefectures as $pref): ?>
                                <option value="<?= $pref ?>" <?= $form_data['prefecture'] === $pref ? 'selected' : '' ?>>
                                    <?= $pref ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">市区町村</label>
                        <input type="text" name="city" class="form-input" 
                               value="<?= h($form_data['city']) ?>">
                    </div>
                </div>
                
                <div class="divider"><span>規約への同意</span></div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="agree_terms" id="agree_terms" required>
                    <label for="agree_terms">
                        <a href="terms.php" target="_blank">利用規約</a>に同意します（必須）
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="agree_privacy" id="agree_privacy" required>
                    <label for="agree_privacy">
                        <a href="privacy.php" target="_blank">プライバシーポリシー</a>に同意します（必須）
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                    アカウントを作成
                </button>
            </form>

            <div id="registerByPhone" class="register-by-phone" style="display: none;">
                <p class="subtitle">携帯番号にSMSで認証番号を送ります。</p>
                <div id="phoneStep1">
                    <div class="form-group">
                        <label class="form-label required">携帯電話番号</label>
                        <input type="tel" id="regPhone" class="form-input" placeholder="09012345678" maxlength="15" autocomplete="tel">
                        <div class="form-hint">ハイフンなしで入力（10〜15桁）</div>
                    </div>
                    <button type="button" class="btn btn-primary btn-block" id="btnSendRegCode">認証番号を送信</button>
                </div>
                <div id="phoneStep2" style="display: none;">
                    <p class="form-subtitle"><span id="regPhoneDisplay"></span> に送った4桁のコードを入力</p>
                    <div class="form-group">
                        <input type="text" id="regCode" class="form-input" maxlength="4" inputmode="numeric" placeholder="0000">
                    </div>
                    <button type="button" class="btn btn-primary btn-block" id="btnVerifyRegCode">確認</button>
                </div>
                <div id="phoneStep3" style="display: none;">
                    <p class="form-subtitle">パスワードを設定してください</p>
                    <div class="form-group">
                        <label class="form-label required">パスワード（8文字以上）</label>
                        <input type="password" id="regPassword" class="form-input" minlength="8">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">パスワード（確認）</label>
                        <input type="password" id="regPasswordConfirm" class="form-input">
                    </div>
                    <button type="button" class="btn btn-primary btn-block" id="btnSetPassword">登録を完了</button>
                </div>
                <div id="phoneError" class="alert alert-error mt-2" style="display: none;"></div>
            </div>
            
            <div class="login-link">
                すでにアカウントをお持ちの方は <a href="index.php">ログイン</a>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        © <?= date('Y') ?> 株式会社Social9. All rights reserved.
    </div>
    <script src="assets/js/register-phone.js"></script>
</body>
</html>
