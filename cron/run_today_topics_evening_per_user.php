<?php
/**
 * 夜の興味トピックレポートを 1ユーザー1プロセスで配信するラッパー
 *
 * 個別配信対象を順に send_evening_report_to_user.php で実行し、
 * 最後に一斉配信（共通1通）を対象ユーザーに保存する。
 *
 * 使い方: cron で 16〜20時に実行
 *   0 16,17,18,19,20 * * * php /var/www/html/cron/run_today_topics_evening_per_user.php >> /var/www/html/logs/cron_evening_topics.log 2>&1
 *
 * 計画書: DOCS/TODAY_TOPICS_ONE_USER_PER_PROCESS_PLAN.md
 */

if (php_sapi_name() !== 'cli' && !defined('CRON_MODE')) {
    die('CLI only');
}
define('CRON_MODE', true);

$basePath = dirname(__DIR__);
require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/config/ai_config.php';
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

try {
    $pdo = getDB();
    $slotMod = $currentHour % 5;
    $totalCount = getTotalRegisteredUserCount($pdo);
    $paidMode = $totalCount > TODAY_TOPICS_PAID_SWITCH_THRESHOLD;
    echo "[" . date('Y-m-d H:i:s') . "] Evening topics (1 process per user), hour={$currentHour}, paid_mode=" . ($paidMode ? 'yes' : 'no') . "\n";

    $individualIds = getEveningReportTargetUserIds($pdo, $slotMod, $totalCount);
    $processed = 0;

    $script = $basePath . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'send_evening_report_to_user.php';
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';

    foreach ($individualIds as $userId) {
        $cmd = sprintf('%s %s %d', $phpBin, escapeshellarg($script), $userId);
        $out = [];
        $ret = -1;
        @exec($cmd . ' 2>&1', $out, $ret);
        if ($ret === 0) {
            $processed++;
            echo "  User {$userId}: individual sent.\n";
        } else {
            echo "  User {$userId}: skip or failed (exit {$ret}).\n";
        }
        usleep(300000);
    }

    $bulkContent = getEveningBulkContentOrGenerate($pdo);
    if ($bulkContent !== null && $bulkContent !== '') {
        $bulkIds = getEveningReportBulkTargetUserIds($pdo, $slotMod, $individualIds);
        $bulkCount = count($bulkIds);
        foreach ($bulkIds as $uid) {
            if (saveEveningInterestReportMessage($pdo, $uid, $bulkContent)) {
                $processed++;
            }
            usleep(100000);
        }
        echo "Bulk sent to {$bulkCount} user(s).\n";
    }

    echo "Processed {$processed} user(s). Done.\n";
} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
    error_log("run_today_topics_evening_per_user: " . $e->getMessage());
    exit(1);
}
