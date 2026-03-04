<?php
/**
 * 夜の興味トピックレポートを指定ユーザーに 1件だけ送信（1ユーザー1プロセス用）
 *
 * 使い方:
 *   php send_evening_report_to_user.php [user_id]
 *
 * - user_id: 送信先ユーザーID（必須）
 *
 * 計画書: DOCS/TODAY_TOPICS_ONE_USER_PER_PROCESS_PLAN.md
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('CRON_MODE', true);

$basePath = dirname(__DIR__);
require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/config/ai_config.php';
require_once $basePath . '/includes/ai_proactive_helper.php';
require_once $basePath . '/includes/today_topics_helper.php';
$geminiPath = $basePath . '/includes/gemini_helper.php';
if (file_exists($geminiPath)) {
    require_once $geminiPath;
}

$userId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 0;
if ($userId <= 0) {
    echo "Usage: php send_evening_report_to_user.php <user_id>\n";
    exit(1);
}

$pdo = getDB();

if (hasUserReceivedEveningReportToday($pdo, $userId)) {
    echo "User {$userId}: already received today. Skip.\n";
    exit(0);
}

$content = generateEveningInterestReportContent($pdo, $userId);
if ($content !== null && $content !== '') {
    if (saveEveningInterestReportMessage($pdo, $userId, $content)) {
        echo "OK: Evening report sent to user {$userId}.\n";
        exit(0);
    }
}
echo "User {$userId}: generate or save failed.\n";
exit(1);
