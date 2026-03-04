<?php
/**
 * 友達申請通知メール送信
 * 相手のメールアドレスに「承諾する」リンク付きで案内を送る
 */

/**
 * 友達申請を受けた相手に通知メールを送信する
 *
 * @param PDO $pdo
 * @param int $requester_user_id 申請したユーザーID
 * @param int $recipient_user_id 申請を受けたユーザーID
 * @return bool 送信成功時 true（メール未設定・送信失敗時は false）
 */
function sendFriendRequestNotification($pdo, $requester_user_id, $recipient_user_id) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$recipient_user_id]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || empty(trim($recipient['email'] ?? ''))) {
        return false;
    }
    $to = trim($recipient['email']);

    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(display_name), ''), email) as display_name FROM users WHERE id = ?");
    $stmt->execute([$requester_user_id]);
    $requester = $stmt->fetch(PDO::FETCH_ASSOC);
    $requester_name = $requester['display_name'] ?? 'Social9ユーザー';
    $requester_name = mb_substr($requester_name, 0, 50);
    $requester_name_esc = htmlspecialchars($requester_name, ENT_QUOTES, 'UTF-8');

    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $accept_url = htmlspecialchars($base_url . '/settings.php?section=friends#requests', ENT_QUOTES, 'UTF-8');

    $subject = $requester_name . 'さんから友達申請が届いています';
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
            <p><strong>{$requester_name_esc}</strong>さんから友達申請が届いています。</p>
            <p>承諾するには、下のボタンからログインし、設定の「友だち」→「申請」タブで「受信した申請」から承諾してください。</p>
            <p><a href="{$accept_url}" class="btn">承諾する</a></p>
            <p class="note">※ このメールに心当たりがない場合は無視してください。</p>
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
        return $mailer->send($to, $subject, $html, true);
    } catch (Exception $e) {
        error_log('Friend request notification mail error: ' . $e->getMessage());
        return false;
    }
}
