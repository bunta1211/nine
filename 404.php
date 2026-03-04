<?php
/**
 * 404 Not Found ページ
 * 存在しないURLアクセス時に表示
 */
http_response_code(404);

// ベースパス（サブディレクトリ配置対応）
$basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ページが見つかりません - Social9</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', 'Yu Gothic UI', 'Meiryo', sans-serif;
            background: linear-gradient(135deg, #4a6741 0%, #2d4228 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: rgba(255, 255, 255, 0.95);
            padding: 50px 40px;
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { color: #333; font-size: 24px; margin-bottom: 15px; }
        p { color: #666; line-height: 1.6; margin-bottom: 25px; }
        .links { display: flex; flex-direction: column; gap: 12px; }
        .links a {
            display: block;
            padding: 14px 24px;
            background: #4a6741;
            color: #fff !important;
            text-decoration: none;
            border-radius: 10px;
            font-weight: bold;
            transition: background 0.2s;
        }
        .links a:hover { background: #3d5736; }
        .links a.secondary {
            background: #e8e8e8;
            color: #333 !important;
        }
        .links a.secondary:hover { background: #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔍</div>
        <h1>ページが見つかりません</h1>
        <p>お探しのページは存在しないか、移動した可能性があります。<br>URLをご確認いただくか、以下のリンクからお進みください。</p>
        <div class="links">
            <a href="<?= htmlspecialchars($basePath) ?>/index.php">ログイン・トップへ</a>
            <a href="<?= htmlspecialchars($basePath) ?>/chat.php" class="secondary">チャットへ（ログイン済みの場合）</a>
        </div>
    </div>
</body>
</html>
