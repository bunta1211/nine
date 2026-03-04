<?php
/**
 * 認証クラス
 * シンプルな認証管理
 */

class Auth {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * ユーザー登録（メール必須、または電話のみ）
     * @param string|null $email メールアドレス（電話のみの場合は null）
     * @param string $password パスワード
     * @param string $display_name 表示名
     * @param string|null $birth_date 生年月日（任意）
     * @param string|null $prefecture 都道府県
     * @param string|null $city 市区町村
     * @param string|null $phone 携帯電話番号（電話のみ登録の場合は必須・数字のみ）
     */
    public function register($email, $password, $display_name, $birth_date = null, $prefecture = null, $city = null, $phone = null) {
        $phone = !empty($phone) ? preg_replace('/\D/', '', $phone) : null;
        $email = $email !== null && $email !== '' ? trim($email) : null;

        if (empty($email) && empty($phone)) {
            return ['success' => false, 'message' => 'メールアドレスまたは携帯電話番号のどちらかが必要です'];
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => '有効なメールアドレスを入力してください'];
        }
        if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 15)) {
            return ['success' => false, 'message' => '携帯電話番号は10〜15桁で入力してください'];
        }

        if (!empty($email)) {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'このメールアドレスは既に登録されています'];
            }
        }
        if (!empty($phone)) {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'この携帯電話番号は既に登録されています'];
            }
        }

        $is_minor = 0;
        if (!empty($birth_date)) {
            $is_minor = $this->isMinor($birth_date) ? 1 : 0;
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (
                email, password_hash, phone, display_name, birth_date,
                is_minor, prefecture, city, auth_level, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $email ?: null,
            $password_hash,
            $phone ?: null,
            $display_name,
            !empty($birth_date) ? $birth_date : null,
            $is_minor,
            $prefecture,
            $city
        ]);

        $user_id = $this->pdo->lastInsertId();
        $this->initializePrivacySettings($user_id);

        return [
            'success' => true,
            'user_id' => $user_id,
            'is_minor' => $is_minor
        ];
    }
    
    /**
     * プライバシー設定を初期化（デフォルト: 検索可能＝携帯番号・名前で検索ヒットする）
     */
    private function initializePrivacySettings($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_privacy_settings (user_id, exclude_from_search, created_at, updated_at)
                VALUES (?, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $stmt->execute([$user_id]);
        } catch (PDOException $e) {
            // テーブルが存在しない場合は無視
            error_log('Privacy settings init error: ' . $e->getMessage());
        }
    }
    
    /**
     * メール認証トークン送信
     */
    public function sendEmailVerification($user_id) {
        $token = bin2hex(random_bytes(32));
        
        // ユーザーのトークンを更新
        $stmt = $this->pdo->prepare("
            UPDATE users SET 
                email_verification_token = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$token, $user_id]);
        
        $base_url = (function_exists('getBaseUrl') ? getBaseUrl() : null) ?: (defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost/nine');
        $link = $base_url . '/verify_email.php?token=' . $token;
        
        // 本番環境ではメールを送信
        // mail($email, 'Social9 メール認証', "以下のリンクをクリックして認証を完了してください:\n$link");
        
        return [
            'success' => true,
            'token' => $token,
            'link' => $link,
            'message' => '認証メールを送信しました'
        ];
    }
    
    /**
     * メール認証を実行
     */
    public function verifyEmail($token) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM users 
            WHERE email_verification_token = ? AND email_verified_at IS NULL
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => '無効または期限切れのリンクです'];
        }
        
        // 認証完了
        $this->pdo->prepare("
            UPDATE users SET 
                email_verified_at = NOW(),
                auth_level = GREATEST(auth_level, 1),
                email_verification_token = NULL
            WHERE id = ?
        ")->execute([$user['id']]);
        
        return ['success' => true, 'user_id' => $user['id']];
    }
    
    /**
     * パスワードリセットリンク送信
     */
    public function sendPasswordReset($email) {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // セキュリティのため、ユーザーが存在しなくても成功を返す
            return ['success' => true];
        }
        
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1時間
        
        $this->pdo->prepare("
            UPDATE users SET 
                password_reset_token = ?,
                password_reset_expires = ?
            WHERE id = ?
        ")->execute([$token, $expires, $user['id']]);
        
        $base_url = (function_exists('getBaseUrl') ? getBaseUrl() : null) ?: (defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost/nine');
        return [
            'success' => true,
            'token' => $token,
            'link' => $base_url . '/reset_password.php?token=' . $token
        ];
    }
    
    /**
     * パスワードリセット実行
     */
    public function resetPassword($token, $new_password) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM users 
            WHERE password_reset_token = ? AND password_reset_expires > NOW()
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => '無効または期限切れのリンクです'];
        }
        
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $this->pdo->prepare("
            UPDATE users SET 
                password_hash = ?,
                password_reset_token = NULL,
                password_reset_expires = NULL
            WHERE id = ?
        ")->execute([$password_hash, $user['id']]);
        
        return ['success' => true];
    }
    
    /**
     * ログイン
     */
    public function login($email, $password) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'メールアドレスまたはパスワードが正しくありません'];
        }
        
        // セッション設定（chat.php 等と揃える）
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['full_name'] = $user['full_name'] ?? $user['display_name'];
        $_SESSION['avatar'] = $user['avatar_path'] ?? null;
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['auth_level'] = (int)($user['auth_level'] ?? 1);
        $_SESSION['is_minor'] = (bool)($user['is_minor'] ?? false);
        $_SESSION['is_org_admin'] = in_array($user['role'] ?? '', ['developer', 'system_admin', 'org_admin', 'admin']) ? 1 : 0;
        $_SESSION['organization_id'] = (int)($user['organization_id'] ?? 1);
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        session_regenerate_id(true);
        
        // オンライン状態を更新
        $this->pdo->prepare("
            UPDATE users SET online_status = 'online', last_seen = NOW() WHERE id = ?
        ")->execute([$user['id']]);
        
        return [
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'display_name' => $user['display_name'],
                'role' => $user['role'] ?? 'user'
            ]
        ];
    }
    
    /**
     * ログアウト
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            try {
                $this->pdo->prepare("
                    UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = ?
                ")->execute([$_SESSION['user_id']]);
            } catch (PDOException $e) {
                // エラーは無視
            }
        }
        
        session_destroy();
        return ['success' => true];
    }
    
    /**
     * 未成年かどうかを判定
     */
    private function isMinor($birth_date) {
        if (empty($birth_date)) return false;
        
        $birth = new DateTime($birth_date);
        $today = new DateTime();
        $age = $today->diff($birth)->y;
        
        return $age < 18;
    }
    
    /**
     * 認証レベルを取得
     */
    public function getAuthLevel($user_id) {
        $stmt = $this->pdo->prepare("SELECT auth_level FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        return $user ? (int)$user['auth_level'] : 0;
    }
    
    /**
     * 電話認証コードを送信
     */
    public function sendPhoneVerification($user_id, $phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // ユーザーに電話番号と一時コードを保存
        $this->pdo->prepare("
            UPDATE users SET 
                phone = ?,
                phone_verification_code = ?,
                phone_verification_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
            WHERE id = ?
        ")->execute([$phone, $code, $user_id]);
        
        // 本番環境ではSMSを送信
        // sendSMS($phone, "Social9認証コード: $code");
        
        return [
            'success' => true,
            'message' => 'SMSで認証コードを送信しました',
            'code' => defined('APP_DEBUG') && APP_DEBUG ? $code : null
        ];
    }
    
    /**
     * 電話認証を実行
     */
    public function verifyPhone($user_id, $code) {
        $stmt = $this->pdo->prepare("
            SELECT id, phone FROM users 
            WHERE id = ? AND phone_verification_code = ? AND phone_verification_expires > NOW()
        ");
        $stmt->execute([$user_id, $code]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'コードが無効または期限切れです'];
        }
        
        $this->pdo->prepare("
            UPDATE users SET 
                phone_verified_at = NOW(),
                auth_level = GREATEST(auth_level, 2),
                phone_verification_code = NULL,
                phone_verification_expires = NULL
            WHERE id = ?
        ")->execute([$user_id]);
        
        return ['success' => true];
    }
    
    /**
     * 機能が使用可能かチェック
     */
    public function canUseFeature($feature) {
        $auth_level = $_SESSION['auth_level'] ?? 0;
        
        // 機能別の必要認証レベル
        $requirements = [
            'basic_chat' => 1,       // メール認証
            'dm' => 1,               // メール認証
            'group_chat' => 1,       // メール認証
            'voice_call' => 2,       // 電話認証
            'video_call' => 2,       // 電話認証
            'matching_post' => 3,    // 本人確認
            'matching_offer' => 3,   // 本人確認
            'organization_create' => 3, // 本人確認
        ];
        
        $required_level = $requirements[$feature] ?? 1;
        
        return $auth_level >= $required_level;
    }
    
    /**
     * 現在のユーザー情報を取得
     */
    public function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
}
