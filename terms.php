<?php
/**
 * 利用規約・プライバシーポリシー表示ページ
 * フッター・登録画面からリンク。詳細は設定画面のモーダルも参照。
 */
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/lang.php';
$currentLang = getCurrentLanguage();
$pageTitle = $currentLang === 'en' ? 'Terms of Service & Privacy Policy' : ($currentLang === 'zh' ? '利用条款与隐私政策' : '利用規約・プライバシーポリシー');
?>
<!DOCTYPE html>
<html lang="<?= $currentLang === 'en' ? 'en' : ($currentLang === 'zh' ? 'zh' : 'ja') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?></title>
    <style>
        body { font-family: sans-serif; max-width: 720px; margin: 0 auto; padding: 24px; line-height: 1.7; color: #333; }
        h1 { font-size: 1.5rem; margin-bottom: 16px; }
        h2 { font-size: 1.2rem; margin-top: 24px; margin-bottom: 12px; }
        p { margin: 12px 0; }
        a { color: #6b8e23; }
        .back { margin-bottom: 24px; }
    </style>
</head>
<body>
    <p class="back"><a href="index.php">← <?= $currentLang === 'en' ? 'Back to login' : ($currentLang === 'zh' ? '返回登录' : 'ログインに戻る') ?></a></p>
    <h1><?= $pageTitle ?></h1>
    <p><?= $currentLang === 'en' ? 'This service is an experimental service developed with AI. Please use it in accordance with the terms of use. Full text is also available in Settings after login.' : ($currentLang === 'zh' ? '本服务为AI参与开发的试验性服务，请遵守利用条款使用。登录后也可在设定中查看全文。' : '本サービスはAIを用いて開発された試験的サービスです。利用規約に同意の上ご利用ください。全文はログイン後の設定画面からもご確認いただけます。') ?></p>
    <h2 id="terms"><?= $currentLang === 'en' ? 'Terms of Service' : ($currentLang === 'zh' ? '利用条款' : '利用規約') ?></h2>
    <p><?= $currentLang === 'en' ? 'The service is provided as an experimental, potentially incomplete service. Users use it at their own risk. We do not guarantee accuracy or completeness and are not liable for any damages arising from use.' : ($currentLang === 'zh' ? '本服务以试验性、可能未完成的形式提供。用户需自行承担使用风险。我们不保证准确性或完整性，对使用产生的任何损害不承担责任。' : '本サービスは試験的・未完成の可能性があるサービスとして提供されます。ユーザーは自己責任でご利用ください。正確性・完全性を保証せず、利用に起因する損害について一切責任を負いません。') ?></p>
    <h2 id="privacy"><?= $currentLang === 'en' ? 'Privacy Policy' : ($currentLang === 'zh' ? '隐私政策' : 'プライバシーポリシー') ?></h2>
    <p><?= $currentLang === 'en' ? 'We handle your personal information appropriately in accordance with our privacy policy. For details, please check the full text in Settings after login.' : ($currentLang === 'zh' ? '我们按照隐私政策妥善处理您的个人信息。详情请于登录后在设定中查看全文。' : '個人情報はプライバシーポリシーに従い適切に取り扱います。詳細はログイン後の設定画面で全文をご確認ください。') ?></p>
</body>
</html>
