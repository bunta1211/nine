<?php
/**
 * 今日の話題（本日のニューストピックス）を指定ユーザーに手動送信（テスト・確認用）
 *
 * 使い方:
 *   php send_today_topics_to_user.php [user_id] [--force]
 *
 * - user_id: 送信先ユーザーID（省略時は 6 = KEN）
 * - --force: 本日すでに受信済みでも再送する
 *
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md
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
require_once $basePath . '/includes/today_topics_youtube_helper.php';
$geminiPath = $basePath . '/includes/gemini_helper.php';
if (file_exists($geminiPath)) {
    require_once $geminiPath;
}

$pdo = getDB();
$userId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 6;
$force = in_array('--force', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Send today's topics to user_id={$userId}" . ($force ? ' (force)' : '') . "\n";

try {
    if (!function_exists('isGeminiAvailable') || !isGeminiAvailable()) {
        echo "Gemini not available. Greeting may be empty; topics body will still be sent.\n";
    }

    if (!$force && hasUserReceivedTodayTopicsMorning($pdo, $userId)) {
        echo "User {$userId} has already received today's topics. Use --force to send again.\n";
        exit(1);
    }

    $topicData = getTodayTopicsCacheOrFetch();
    $ageGroup = getTodayTopicsAgeGroup($pdo, $userId);
    $body = buildMorningTopicsBody($topicData, $ageGroup);
    $includeHint = shouldIncludeImprovementHint($pdo, $userId);
    $greeting = generateProactiveMessage($pdo, $userId, $includeHint);

    // 朝のニュースは動画形式を優先。動画が1件以上取れれば動画本文、取れなければ従来のRSS本文にフォールバック
    $videoData = getTodayTopicsVideosCacheOrFetch();
    $videos = $videoData['videos'] ?? [];
    if (!empty($videos)) {
        $fullMessage = buildMorningTopicsVideoBody($videos, $greeting ?: '');
    } else {
        $fullMessage = $greeting ? $greeting . "\n\n" . $body : $body;
    }

    $saved = saveTodayTopicsMorningMessage($pdo, $userId, $fullMessage);
    if ($saved) {
        echo "OK: Today's topics sent to user {$userId}.\n";
        exit(0);
    }
    echo "Error: Save failed for user {$userId}.\n";
    exit(1);
} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
    error_log("send_today_topics_to_user: " . $e->getMessage());
    exit(1);
}
