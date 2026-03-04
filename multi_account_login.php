<?php
/**
 * 複数アカウント同時ログイン用ガイド
 * KENとシステム管理者を別タブで同時にログインして会話テストする方法
 */
require_once __DIR__ . '/config/session.php';
$currentLang = 'ja';

// ベースURLを取得（サブディレクトリ対応）
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptName);
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
$basePath = rtrim($basePath, '/');

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalhost = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
$isSocial9 = (strpos($host, 'social9') !== false);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>複数アカウント同時ログイン - Social9</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Noto Sans JP', 'Hiragino Sans', sans-serif;
            padding: 24px;
            max-width: 640px;
            margin: 0 auto;
            background: #f8fafc;
            line-height: 1.7;
        }
        h1 { font-size: 1.5rem; margin-bottom: 20px; color: #1e293b; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .card h2 { font-size: 1.1rem; margin-bottom: 12px; color: #334155; }
        .card p { margin-bottom: 12px; color: #475569; font-size: 14px; }
        .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            text-align: center;
            border: none;
            cursor: pointer;
        }
        .btn-ken { background: #3b82f6; color: white; }
        .btn-admin { background: #64748b; color: white; }
        .btn:hover { opacity: 0.9; }
        .method { margin-bottom: 8px; padding-left: 1em; }
        .method-num { font-weight: bold; color: #64748b; }
        .note { font-size: 12px; color: #94a3b8; margin-top: 8px; }
        a { color: #3b82f6; }
    </style>
</head>
<body>
<h1>複数アカウント同時ログイン</h1>
<p style="margin-bottom: 20px; color: #64748b;">KENとシステム管理者で同時にログインし、会話テストを行う方法です。</p>

<div class="card">
    <h2>おすすめ：別ブラウザ or シークレットモード</h2>
    <p>同一ブラウザの複数タブはセッションを共有するため、一方でログインすると他方が上書きされます。</p>
    <div class="method">
        <span class="method-num">方法1</span> <strong>別のブラウザ</strong><br>
        Chrome で KEN、Firefox（または Edge）で システム管理者
    </div>
    <div class="method">
        <span class="method-num">方法2</span> <strong>シークレット/プライベートウィンドウ</strong><br>
        通常ウィンドウで KEN、シークレットウィンドウ（Ctrl+Shift+N）で システム管理者
    </div>
    <div class="btn-group">
        <a href="<?= htmlspecialchars($basePath) ?>/index.php" class="btn btn-ken" target="_blank">ログイン画面を新しいタブで開く</a>
    </div>
</div>

<div class="card">
    <h2>本番環境（social9.jp）の場合：サブドメイン利用</h2>
    <p>ken.social9.jp と sys.social9.jp を設定すると、それぞれ別セッションで同時ログインできます。</p>
    <?php if ($isSocial9 && !$isLocalhost): ?>
    <div class="btn-group">
        <a href="https://ken.social9.jp<?= htmlspecialchars($basePath) ?>/" class="btn btn-ken" target="_blank">KEN用（ken.social9.jp）</a>
        <a href="https://sys.social9.jp<?= htmlspecialchars($basePath) ?>/" class="btn btn-admin" target="_blank">システム管理者用（sys.social9.jp）</a>
    </div>
    <p class="note">※ DNS で ken.social9.jp / sys.social9.jp をサーバーに振り向ける必要があります</p>
    <?php else: ?>
    <p class="note">サブドメインは本番環境（social9.jp）で利用可能です。</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>テスト用ログイン情報</h2>
    <table style="width:100%; border-collapse: collapse; font-size: 14px;">
        <tr style="background:#f1f5f9;">
            <th style="padding:8px; text-align:left;">表示名</th>
            <th style="padding:8px; text-align:left;">メール</th>
            <th style="padding:8px; text-align:left;">パスワード</th>
        </tr>
        <tr>
            <td style="padding:8px;">KEN（奈良健太郎）</td>
            <td style="padding:8px;">narakenn1211@gmail.com</td>
            <td style="padding:8px;">cloverkids456</td>
        </tr>
        <tr style="background:#f8fafc;">
            <td style="padding:8px;">システム管理者</td>
            <td style="padding:8px;">admin@social9.jp</td>
            <td style="padding:8px;">cloverkids345</td>
        </tr>
    </table>
</div>

<p style="margin-top: 16px;"><a href="<?= htmlspecialchars($basePath) ?>/index.php">← ログイン画面に戻る</a></p>
</body>
</html>
