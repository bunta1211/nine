<?php
/**
 * 今日の話題 夜の興味トピックレポート配信 cron
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md セクション 4
 *
 * 16〜20 時に実行。
 * - 200名以下: お試し（登録7日以内）で2日連続未クリックでないユーザーに個別配信、それ以外は一斉配信。
 * - 200名超: 月額プラン加入者のみ個別配信、非加入者は一斉配信。
 * crontab 例: 0 16,17,18,19,20 * * * php /var/www/html/cron/ai_today_topics_evening.php >> /var/www/html/logs/cron_evening_topics.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !defined('CRON_MODE')) {
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

$currentHour = (int)date('G');
if ($currentHour < 16 || $currentHour > 20) {
    echo "[" . date('Y-m-d H:i:s') . "] Not in 16-20h. Exit.\n";
    exit(0);
}

if (!function_exists('isGeminiAvailable') || !isGeminiAvailable()) {
    echo "[" . date('Y-m-d H:i:s') . "] Gemini not available. Exit.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Evening topics job started (hour={$currentHour})\n";

try {
    $slotMod = $currentHour % 5;
    $totalCount = getTotalRegisteredUserCount($pdo);
    $paidMode = $totalCount > TODAY_TOPICS_PAID_SWITCH_THRESHOLD;
    echo "Total users: {$totalCount}, paid mode: " . ($paidMode ? 'yes' : 'no') . "\n";

    $individualIds = getEveningReportTargetUserIds($pdo, $slotMod, $totalCount);
    $processed = 0;

    foreach ($individualIds as $userId) {
        $content = generateEveningInterestReportContent($pdo, $userId);
        if ($content !== null && $content !== '') {
            if (saveEveningInterestReportMessage($pdo, $userId, $content)) {
                $processed++;
                echo "  User {$userId}: individual sent.\n";
            } else {
                echo "  User {$userId}: save failed.\n";
            }
        } else {
            echo "  User {$userId}: generate failed.\n";
        }
        unset($content);
        usleep(500000);
    }

    $bulkContent = getEveningBulkContentOrGenerate($pdo);
    if ($bulkContent !== null && $bulkContent !== '') {
        $bulkIds = getEveningReportBulkTargetUserIds($pdo, $slotMod, $individualIds);
        $bulkCount = count($bulkIds);
        foreach ($bulkIds as $userId) {
            if (saveEveningInterestReportMessage($pdo, $userId, $bulkContent)) {
                $processed++;
            }
            usleep(100000);
        }
        unset($bulkContent, $bulkIds);
        echo "Bulk sent to {$bulkCount} user(s).\n";
    }

    echo "Processed {$processed} user(s). Done.\n";
} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
    error_log("ai_today_topics_evening: " . $e->getMessage());
    exit(1);
}
