<?php
/**
 * 組織招待メール送信
 * 招待先メールアドレスに「パスワード設定して承諾」リンクを送る
 */

/**
 * 組織招待メールを送信する
 *
 * @param string $to_email 送信先メールアドレス
 * @param string $organization_name 組織名
 * @param string $accept_url 承諾・パスワード設定ページのURL（トークン付き）
 * @return bool 送信成功時 true
 */
function sendOrgInviteMail($to_email, $organization_name, $accept_url) {
    $to_email = trim($to_email);
    if ($to_email === '') {
        return false;
    }
    $org_name_esc = htmlspecialchars(mb_substr($organization_name, 0, 100), ENT_QUOTES, 'UTF-8');
    $accept_url_esc = htmlspecialchars($accept_url, ENT_QUOTES, 'UTF-8');

    $subject = '【Social9】' . $organization_name . ' から招待されています';
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo h1 { color: #6b8e23; font-size: 24px; margin: 0; }
        .content { background: #f9f9f9; border-radius: 12px; padding: 24px; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: linear-gradient(135deg, #10b981, #059669); color: #fff !important; text-decoration: none; border-radius: 8px; font-weight: 500; }
        .note { font-size: 13px; color: #666; margin-top: 20px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo"><h1>Social9</h1></div>
        <div class="content">
            <p><strong>{$org_name_esc}</strong> から招待されています。</p>
            <p>パスワードを設定して承諾すると、組織に所属し、チャットをご利用いただけます。</p>
            <p><a href="{$accept_url_esc}" class="btn">パスワードを設定して承諾する</a></p>
            <p class="note">※ リンクの有効期限は24時間です。<br>※ 心当たりがない場合はこのメールを無視してください。</p>
        </div>
        <div class="footer">
            <p>© Social9</p>
        </div>
    </div>
</body>
</html>
HTML;

    try {
        require_once __DIR__ . '/Mailer.php';
        $mailer = new Mailer();
        return $mailer->send($to_email, $subject, $html, true);
    } catch (Exception $e) {
        error_log('Org invite mail error: ' . $e->getMessage());
        return false;
    }
}
