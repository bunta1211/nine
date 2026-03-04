<?php
/**
 * 朝の「本日のニューストピックス」を 1ユーザー1プロセスで配信するラッパー
 *
 * 対象 user_id 一覧を取得し、各ユーザーごとに send_today_topics_to_user.php を
 * 別プロセスで実行する。プロセス終了ごとにメモリが解放されるため、メモリ負荷を抑えられる。
 *
 * 使い方: cron で 6時・7時に実行
 *   0 6,7 * * * php /var/www/html/cron/run_today_topics_morning_per_user.php >> /var/www/html/logs/cron_proactive.log 2>&1
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

$currentHour = (int)date('G');
if ($currentHour !== 6 && $currentHour !== 7) {
    echo "[" . date('Y-m-d H:i:s') . "] Not 6 or 7. Exit.\n";
    exit(0);
}

try {
    $pdo = getDB();
    try {
        $pdo->query("SELECT today_topics_morning_enabled, today_topics_morning_hour FROM user_ai_settings LIMIT 0");
    } catch (Throwable $e) {
        echo "Today topics columns not found. Exit.\n";
        exit(0);
    }

    $stmt = $pdo->prepare("
        SELECT uas.user_id
        FROM user_ai_settings uas
        JOIN users u ON u.id = uas.user_id
        WHERE uas.today_topics_morning_enabled = 1
          AND COALESCE(uas.today_topics_morning_hour, 7) = ?
        ORDER BY (uas.user_id = 6) DESC, uas.user_id ASC
    ");
    /* user_id 6 (Ken) を先頭に配信し、送られるニュース内容を確認しやすくする */
    $stmt->execute([$currentHour]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $userIds = array_map(function ($r) { return (int)$r['user_id']; }, $rows);

    // 今日の話題の配信対象を限定（KEN のみなど）。DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md
    if (defined('TODAY_TOPICS_LIMIT_USER_IDS') && TODAY_TOPICS_LIMIT_USER_IDS !== '') {
        $limitIds = json_decode(TODAY_TOPICS_LIMIT_USER_IDS, true);
        if (is_array($limitIds) && !empty($limitIds)) {
            $limitIds = array_map('intval', $limitIds);
            $userIds = array_values(array_intersect($userIds, $limitIds));
        }
    }

    if (empty($userIds)) {
        echo "[" . date('Y-m-d H:i:s') . "] No users for hour {$currentHour}. Done.\n";
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Morning topics: " . count($userIds) . " user(s) for hour {$currentHour} (1 process per user)\n";

    $script = $basePath . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'send_today_topics_to_user.php';
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $sent = 0;

    foreach ($userIds as $userId) {
        $cmd = sprintf('%s %s %d', $phpBin, escapeshellarg($script), $userId);
        $out = [];
        $ret = -1;
        @exec($cmd . ' 2>&1', $out, $ret);
        if ($ret === 0) {
            $sent++;
            echo "  User {$userId}: sent.\n";
        } else {
            echo "  User {$userId}: skip or failed (exit {$ret}).\n";
        }
        usleep(300000);
    }

    echo "Done. Sent to {$sent} user(s).\n";
} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
    error_log("run_today_topics_morning_per_user: " . $e->getMessage());
    exit(1);
}
