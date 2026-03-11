<?php
/**
 * Guild エントリーポイント
 * Social9のログイン状態を確認してリダイレクト
 */

// 本番で500を出さないため、例外を捕まえて setup へ誘導
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/session.php';

    // Social9にログイン済みならGuild側へ
    if (isGuildLoggedIn()) {
        // Guild用テーブルが未作成の場合はセットアップページへ
        try {
            $pdo = getDB();
            $pdo->query("SELECT 1 FROM guild_system_permissions LIMIT 1");
        } catch (PDOException $e) {
            header('Location: setup.php');
            exit;
        }
        header('Location: home.php');
        exit;
    }

    // 未ログインならSocial9のログインページへリダイレクト
    $social9Url = getSocial9Url();
    header('Location: ' . $social9Url . '/index.php');
    exit;
} catch (Throwable $e) {
    // ログ出力（本番のログ確認用）
    if (function_exists('error_log')) {
        error_log('[Guild index] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    header('Location: setup.php');
    exit;
}
