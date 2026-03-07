<?php
/**
 * 通話で困ったとき（繋がらない場合のヘルプ）
 * call.php の原因表示からリンク。CALL_VERIFICATION_AND_TROUBLESHOOTING の要約を表示。
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/design_loader.php';
require_once __DIR__ . '/../includes/lang.php';

// ログイン不要で表示（通話画面から新しいタブで開くため）
$pdo = null;
$designSettings = [];
if (isLoggedIn()) {
    $pdo = getDB();
    $designSettings = getDesignSettings($pdo, $_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通話で困ったとき | Social9</title>
    <?= generateFontLinks() ?>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; padding: 24px; max-width: 720px; margin: 0 auto; line-height: 1.6; }
        h1 { font-size: 1.5rem; margin-bottom: 16px; }
        h2 { font-size: 1.15rem; margin-top: 24px; margin-bottom: 8px; }
        ul { margin: 8px 0; padding-left: 24px; }
        li { margin-bottom: 4px; }
        .help-note { background: #f0f9ff; border-left: 4px solid #0ea5e9; padding: 12px 16px; margin: 16px 0; font-size: 0.95rem; }
        .help-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 16px 0; font-size: 0.95rem; }
        .back-link { display: inline-block; margin-top: 24px; color: var(--primary, #6366f1); }
    </style>
    <?= !empty($designSettings) ? generateDesignCSS($designSettings) : '' ?>
</head>
<body>
    <h1>通話で困ったとき</h1>
    <p>ビデオ通話・音声通話が「接続中」のまま繋がらない場合に確認することをまとめています。</p>

    <h2>1. まず確認すること</h2>
    <ul>
        <li><strong>ネットワーク</strong>：インターネットに接続されていますか？別の回線（例：スマホのテザリング）で試してみてください。</li>
        <li><strong>ブラウザ</strong>：Chrome / Edge / Firefox の最新版を使っていますか？シークレットウィンドウで試すと、拡張機能の影響を除けます。</li>
        <li><strong>URL</strong>：通話ページのアドレスが <code>https://</code> で始まっているか確認してください。</li>
    </ul>

    <h2>2. コンソールにエラーが出ている場合</h2>
    <p>ブラウザの開発者ツール（F12）の「Console」に表示されるメッセージで原因の目安が分かることがあります。</p>
    <ul>
        <li><strong>chrome-extension://invalid/ の net::ERR_FAILED</strong>：通話の原因ではありません。ブラウザや Jitsi 側の表示なので無視して大丈夫です。</li>
        <li><strong>meet.jit.si や external_api.js の読み込み失敗</strong>：ネットワークやファイアウォールでブロックされている可能性があります。別ネットで試すか、管理者にご相談ください。</li>
        <li><strong>speaker-selection / RECORDING OFF SOUND などの警告</strong>：多くの場合は通話そのものは繋がります。そのまましばらく待つか、もう一度通話をやり直してみてください。</li>
    </ul>

    <div class="help-note">
        <strong>会議がまだ開始されていない場合</strong><br>
        利用している環境によっては、通話が「モデレーター待ち」の状態になることがあります。発信者の画面で Jitsi 内に「私はホストです」や「Start meeting」のようなボタンが出ている場合は、<strong>発信者</strong>がそれを押してから、相手が「ミーティングに参加」を押すと繋がることがあります。それでも繋がらない場合は、一度通話を終了してからかけ直してみてください。
    </div>

    <h2>3. それでも繋がらない場合</h2>
    <ul>
        <li>通話を終了し、しばらく待ってからかけ直す。</li>
        <li>相手側もブラウザを更新したうえで、もう一度「出る」から試す。</li>
        <li>問題が続く場合は、運営・サポートにお問い合わせください。その際「どのブラウザか」「コンソールや画面に表示されたメッセージ」があると原因の特定がしやすくなります。</li>
    </ul>

    <div class="help-warn">
        確実に繋がるようにするには、サービス側で自前の通話サーバー（Jitsi）を導入し、会議が自動で開始される設定にすることが推奨されています。手順の概要は DOCS 内の <strong>JITSI_SELFHOST_QUICKSTART.md</strong> を参照するか、運営にお問い合わせください。
    </div>

    <a href="../chat.php" class="back-link">← チャットに戻る</a>
</body>
</html>
