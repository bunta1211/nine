<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アクセス権限がありません - Social9</title>
    <style>
        :root {
            --primary: #2d5016;
            --primary-light: #4a7c23;
            --bg: #f5f5f0;
            --text: #333;
            --warning: #d32f2f;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', 'Hiragino Sans', 'Meiryo', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: white;
            padding: 60px 50px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
        }
        
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: var(--warning);
            font-size: 24px;
            margin-bottom: 20px;
        }
        
        p {
            color: var(--text);
            line-height: 1.8;
            margin-bottom: 30px;
        }
        
        .btn {
            display: inline-block;
            padding: 14px 40px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: var(--primary-light);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⚠️</div>
        <h1>アクセス権限がありません</h1>
        <p>
            このページは「特定投資家専用入り口」から<br>
            ログインした方のみアクセス可能です。<br><br>
            お手数ですが、ログイン画面に戻り、<br>
            「特定投資家の皆様へ」からログインし直してください。
        </p>
        <a href="index.php" class="btn">ログイン画面に戻る</a>
    </div>
</body>
</html>








