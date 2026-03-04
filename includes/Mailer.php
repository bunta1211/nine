<?php
/**
 * メール送信クラス
 * - config/mail.php で MAIL_DRIVER が php のときは PHP mail()、smtp のときは SMTP（AWS SES 対応）
 */
class Mailer {
    private $fromEmail;
    private $fromName;

    public function __construct() {
        $configPath = __DIR__ . '/../config/mail.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
        $this->fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@social9.jp';
        $this->fromName  = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : 'Social9';
    }

    /**
     * メール送信
     * @param string $to 送信先
     * @param string $subject 件名
     * @param string $body 本文（HTML またはテキスト）
     * @param bool $isHtml true のとき HTML メール
     * @return bool 送信成功時 true
     */
    public function send($to, $subject, $body, $isHtml = true) {
        $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'php';
        $useSmtp = ($driver === 'smtp' && defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '');

        if ($useSmtp) {
            return $this->sendViaSmtp($to, $subject, $body, $isHtml);
        }
        return $this->sendViaPhpMail($to, $subject, $body, $isHtml);
    }

    /**
     * PHP mail() で送信
     */
    private function sendViaPhpMail($to, $subject, $body, $isHtml) {
        $headers = [
            'From' => "{$this->fromName} <{$this->fromEmail}>",
            'Reply-To' => $this->fromEmail,
            'MIME-Version' => '1.0',
            'X-Mailer' => 'PHP/' . phpversion()
        ];
        if ($isHtml) {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        } else {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
        }
        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "{$key}: {$value}\r\n";
        }
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail($to, $subjectEnc, $body, $headerString);
        if (!$ok) {
            error_log('Mailer: PHP mail() failed. On EC2 use config/mail.local.php with MAIL_DRIVER=smtp and AWS SES. See DOCS/AWS_SES_MAIL_SETUP.md');
        }
        return $ok;
    }

    /**
     * SMTP（AWS SES 含む）で送信
     * PHPMailer があれば利用、なければ includes/SmtpSender.php で送信。
     */
    private function sendViaSmtp($to, $subject, $body, $isHtml) {
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendViaPhpMailer($to, $subject, $body, $isHtml);
        }
        $fallback = __DIR__ . '/SmtpSender.php';
        if (is_file($fallback)) {
            require_once $fallback;
            $sender = new SmtpSender();
            return $sender->send($to, $subject, $body, $isHtml);
        }
        error_log('Mailer: SMTP driver set but neither PHPMailer nor SmtpSender available.');
        return false;
    }

    private function sendViaPhpMailer($to, $subject, $body, $isHtml) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = MAIL_SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_SMTP_USER;
            $mail->Password   = MAIL_SMTP_PASS;
            $mail->SMTPSecure = (MAIL_SMTP_ENCRYPTION === 'ssl') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)MAIL_SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML((bool)$isHtml);
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 認証コードメール送信
     */
    public function sendVerificationCode($to, $code, $isNewUser = true) {
        $subject = $isNewUser
            ? 'Social9 新規登録 - 認証コード'
            : 'Social9 ログイン - 認証コード';
        $body = $this->getVerificationEmailTemplate($code, $isNewUser);
        return $this->send($to, $subject, $body);
    }

    /**
     * 認証コードメールのテンプレート
     */
    private function getVerificationEmailTemplate($code, $isNewUser) {
        $title = $isNewUser ? '新規登録' : 'ログイン';
        $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { color: #6b8e23; font-size: 28px; margin: 0; }
        .content { background: #f9f9f9; border-radius: 12px; padding: 30px; text-align: center; }
        .code { font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #6b8e23;
                background: white; padding: 20px 40px; border-radius: 8px; display: inline-block;
                margin: 20px 0; border: 2px dashed #6b8e23; }
        .note { font-size: 14px; color: #666; margin-top: 20px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo"><h1>Social9</h1></div>
        <div class="content">
            <h2>{$title}の認証コード</h2>
            <p>以下の認証コードを入力してください。</p>
            <div class="code">{$codeEsc}</div>
            <p class="note">※ このコードは15分間有効です。<br>※ このメールに心当たりがない場合は無視してください。</p>
        </div>
        <div class="footer"><p>© Social9</p></div>
    </div>
</body>
</html>
HTML;
    }
}
