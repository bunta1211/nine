<?php
/**
 * デプロイ後すぐに「本日のニューストピックス」を KEN (user_id=6) に配信するテスト
 *
 * 使い方（本番サーバーでデプロイ後に1回実行）:
 *   php run_today_topics_test_once.php
 *
 * ニュースを取得して KEN に1通送信する。本日すでに受信済みでも --force で再送する。
 * 計画書: DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

$basePath = dirname(__DIR__);
$script = $basePath . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'send_today_topics_to_user.php';
$phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';

$userId = 6; // KEN
$cmd = sprintf('%s %s %d --force', $phpBin, escapeshellarg($script), $userId);

echo "[" . date('Y-m-d H:i:s') . "] Running deploy-time news delivery test for user_id={$userId}\n";

passthru($cmd, $exitCode);
exit($exitCode);
