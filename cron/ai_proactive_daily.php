<?php
/**
 * AI秘書 毎日1回の自動話しかけ cronジョブ
 *
 * 設定方法:
 * crontab -e
 * 0 * * * * php /var/www/html/cron/ai_proactive_daily.php >> /var/www/html/logs/cron_proactive.log 2>&1
 *
 * 毎時実行し、各ユーザーの希望時刻に応じて:
 * - 6時・7時: 「本日のニューストピックス」有効ユーザーには挨拶＋ニューストピックスを1通で送信（今日の話題に統合）
 * - 上記以外の時刻: 従来どおり挨拶のみ（proactive_message_hour 一致ユーザー）
 *
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md
 */

if (php_sapi_name() !== 'cli' && !defined('CRON_MODE')) {
    die('This script must be run from command line');
}

define('CRON_MODE', true);

$basePath = dirname(__DIR__);

require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/config/ai_config.php';
require_once $basePath . '/includes/ai_proactive_helper.php';
require_once $basePath . '/includes/today_topics_helper.php';
require_once $basePath . '/includes/today_topics_youtube_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] AI proactive daily job started\n";

if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
    echo "GEMINI_API_KEY not set. Exiting.\n";
    exit(0);
}

$currentHour = (int)date('G'); // 0-23

try {
    $pdo = getDB();
    $hasProactiveCols = true;
    try {
        $pdo->query("SELECT proactive_message_enabled FROM user_ai_settings LIMIT 0");
    } catch (Throwable $e) {
        $hasProactiveCols = false;
        echo "proactive columns not found in user_ai_settings. Run migration first.\n";
        exit(0);
    }

    $hasTodayTopicsCols = false;
    try {
        $pdo->query("SELECT today_topics_morning_enabled, today_topics_morning_hour FROM user_ai_settings LIMIT 0");
        $hasTodayTopicsCols = true;
    } catch (Throwable $e) {
        // 今日の話題カラム未追加の場合は挨拶のみの従来ロジック
    }

    $hasIsProactive = true;
    try {
        $pdo->query("SELECT is_proactive FROM ai_conversations LIMIT 0");
    } catch (Throwable $e) {
        $hasIsProactive = false;
    }

    $today = date('Y-m-d');
    $processed = 0;

    // ----- 6時・7時: 本日のニューストピックス配信対象 -----
    if ($hasTodayTopicsCols && ($currentHour === 6 || $currentHour === 7)) {
        $stmtTopics = $pdo->prepare("
            SELECT uas.user_id
            FROM user_ai_settings uas
            JOIN users u ON u.id = uas.user_id
            WHERE uas.today_topics_morning_enabled = 1
              AND COALESCE(uas.today_topics_morning_hour, 7) = ?
            ORDER BY (uas.user_id = 6) DESC, uas.user_id ASC
        ");
        /* user_id 6 (Ken) を先頭にし、どのようなニュースが送られるか確認しやすくする */
        $stmtTopics->execute([$currentHour]);
        $topicUsers = $stmtTopics->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($topicUsers)) {
            $topicData = getTodayTopicsCacheOrFetch();
            $videoData = getTodayTopicsVideosCacheOrFetch();
            $videos = $videoData['videos'] ?? [];
            $useVideo = !empty($videos);
            echo "Today topics: " . count($topicUsers) . " user(s) for hour {$currentHour}" . ($useVideo ? " (video)" : " (RSS fallback)") . "\n";

            foreach ($topicUsers as $row) {
                $userId = (int)$row['user_id'];
                if (hasUserReceivedTodayTopicsMorning($pdo, $userId)) {
                    echo "  User {$userId}: already received today's topics. Skip.\n";
                    continue;
                }

                $includeHint = shouldIncludeImprovementHint($pdo, $userId);
                $greeting = generateProactiveMessage($pdo, $userId, $includeHint);
                if ($useVideo) {
                    $fullMessage = buildMorningTopicsVideoBody($videos, $greeting ?: '');
                } else {
                    $ageGroup = getTodayTopicsAgeGroup($pdo, $userId);
                    $body = buildMorningTopicsBody($topicData, $ageGroup);
                    $fullMessage = $greeting ? $greeting . "\n\n" . $body : $body;
                }

                $saved = saveTodayTopicsMorningMessage($pdo, $userId, $fullMessage);
                if ($saved) {
                    $processed++;
                    echo "  User {$userId}: today's topics sent.\n";
                } else {
                    echo "  User {$userId}: save failed.\n";
                }
                unset($greeting, $fullMessage);
                if (isset($body)) unset($body);
                usleep(500000);
            }
        }

        // ----- 同じ時刻で「挨拶のみ」のユーザー（今日の話題はOFF or 別時刻） -----
        $stmtProactiveOnly = $pdo->prepare("
            SELECT uas.user_id
            FROM user_ai_settings uas
            JOIN users u ON u.id = uas.user_id
            WHERE uas.proactive_message_enabled = 1
              AND uas.proactive_message_hour = ?
              AND (COALESCE(uas.today_topics_morning_enabled, 1) = 0 OR COALESCE(uas.today_topics_morning_hour, 7) != ?)
        ");
        $stmtProactiveOnly->execute([$currentHour, $currentHour]);
        $proactiveOnly = $stmtProactiveOnly->fetchAll(PDO::FETCH_ASSOC);

        foreach ($proactiveOnly as $c) {
            $userId = (int)$c['user_id'];
            if (hasUserReceivedTodayTopicsMorning($pdo, $userId)) {
                continue;
            }
            $already = false;
            try {
                if ($hasIsProactive) {
                    $chk = $pdo->prepare("SELECT id FROM ai_conversations WHERE user_id = ? AND is_proactive = 1 AND DATE(created_at) = ? LIMIT 1");
                    $chk->execute([$userId, $today]);
                    $already = $chk->fetch() !== false;
                } else {
                    $chk = $pdo->prepare("SELECT id FROM ai_conversations WHERE user_id = ? AND question = '（自動挨拶）' AND DATE(created_at) = ? LIMIT 1");
                    $chk->execute([$userId, $today]);
                    $already = $chk->fetch() !== false;
                }
            } catch (Throwable $ignore) {}
            if ($already) continue;

            $includeHint = shouldIncludeImprovementHint($pdo, $userId);
            $message = generateProactiveMessage($pdo, $userId, $includeHint);
            if ($message && saveProactiveMessage($pdo, $userId, $message)) {
                $processed++;
                echo "  User {$userId}: proactive only sent.\n";
            }
            unset($message);
            usleep(500000);
        }

        echo "Processed {$processed} user(s). Done.\n";
        exit(0);
    }

    // ----- それ以外の時刻: 従来どおり挨拶のみ -----
    $stmt = $pdo->prepare("
        SELECT uas.user_id, uas.proactive_message_hour
        FROM user_ai_settings uas
        JOIN users u ON u.id = uas.user_id
        WHERE uas.proactive_message_enabled = 1
          AND uas.proactive_message_hour = ?
    ");
    $stmt->execute([$currentHour]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        echo "No users scheduled for hour {$currentHour}. Done.\n";
        exit(0);
    }

    echo "Found " . count($candidates) . " candidate user(s) for hour {$currentHour}\n";

    foreach ($candidates as $c) {
        $userId = (int)$c['user_id'];

        try {
            if ($hasIsProactive) {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM ai_conversations
                    WHERE user_id = ? AND is_proactive = 1 AND DATE(created_at) = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$userId, $today]);
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM ai_conversations
                    WHERE user_id = ? AND answered_by = 'gemini_proactive' AND DATE(created_at) = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$userId, $today]);
            }

            if ($checkStmt->fetch()) {
                echo "  User {$userId}: already sent today. Skip.\n";
                continue;
            }
        } catch (Throwable $e) {
            error_log("proactive check error user {$userId}: " . $e->getMessage());
            continue;
        }

        $includeHint = shouldIncludeImprovementHint($pdo, $userId);
        echo "  User {$userId}: generating message..." . ($includeHint ? " (with improvement hint)" : "") . "\n";
        $message = generateProactiveMessage($pdo, $userId, $includeHint);

        if ($message) {
            $saved = saveProactiveMessage($pdo, $userId, $message);
            echo "  User {$userId}: " . ($saved ? "sent" : "save failed") . " - " . mb_substr($message, 0, 50) . "...\n";
            if ($saved) $processed++;
        } else {
            echo "  User {$userId}: generation failed\n";
        }
        unset($message);
        usleep(500000);
    }

    echo "Processed {$processed} user(s). Done.\n";

} catch (Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    error_log("ai_proactive_daily fatal: " . $e->getMessage());
    exit(1);
}
