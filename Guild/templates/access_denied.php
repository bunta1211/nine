<?php
/**
 * アクセス拒否ページ
 */
require_once __DIR__ . '/../includes/common.php';
?>
<!DOCTYPE html>
<html lang="<?= h(getCurrentLanguage()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アクセス拒否 - <?= __('app_name') ?></title>
    <link rel="stylesheet" href="<?= asset('css/common.css') ?>">
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: var(--spacing-lg);
        }
        .error-content {
            max-width: 400px;
        }
        .error-icon {
            font-size: 4rem;
            margin-bottom: var(--spacing-lg);
        }
        .error-title {
            font-size: var(--font-size-2xl);
            margin-bottom: var(--spacing-md);
        }
        .error-message {
            color: var(--color-text-secondary);
            margin-bottom: var(--spacing-xl);
        }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-content">
            <div class="error-icon">🚫</div>
            <h1 class="error-title">アクセス拒否</h1>
            <p class="error-message">このページにアクセスする権限がありません。</p>
            <a href="home.php" class="btn btn-primary">ホームに戻る</a>
        </div>
    </div>
</body>
</html>
