/**
 * 朝の「本日のニューストピックス」を 1ユーザー1プロセスで配信するラッパー
 *
 * 対象: (1) 固定ユーザー（KEN, Yusei, Naomi 等・TODAY_TOPICS_MORNING_FIXED_USER_IDS）
 *       (2) 過去1週間アクティブで朝7時希望のユーザー（TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK が true のとき）
 * 毎朝 7 時のみ実行。
 *
 * 使い方: cron で 7時に実行
 *   0 7 * * * php /var/www/html/cron/run_today_topics_morning_per_user.php >> /var/www/html/logs/cron_proactive.log 2>&1
 *
 * 計画書: DOCS/TODAY_TOPICS_ONE_USER_PER_PROCESS_PLAN.md
 * 配信対象: DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md
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
if ($currentHour !== 7) {
    echo "[" . date('Y-m-d H:i:s') . "] Not 7:00. Exit.\n";
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

    $userIds = [];

    // (1) 固定ユーザー（KEN, Yusei, Naomi 等）
    if (defined('TODAY_TOPICS_MORNING_FIXED_USER_IDS') && TODAY_TOPICS_MORNING_FIXED_USER_IDS !== '') {
        $fixed = json_decode(TODAY_TOPICS_MORNING_FIXED_USER_IDS, true);
        if (is_array($fixed)) {
            $userIds = array_merge($userIds, array_map('intval', $fixed));
        }
    }

    // (2) 過去1週間アクティブで朝7時希望のユーザー
    if (defined('TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK') && TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK) {
        $stmt = $pdo->prepare("
            SELECT uas.user_id
            FROM user_ai_settings uas
            JOIN users u ON u.id = uas.user_id
            WHERE uas.today_topics_morning_enabled = 1
              AND COALESCE(uas.today_topics_morning_hour, 7) = 7
              AND u.status = 'active'
              AND (u.last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY))
            ORDER BY (uas.user_id = 6) DESC, uas.user_id ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $userIds[] = (int)$r['user_id'];
        }
    }

    $userIds = array_values(array_unique(array_filter($userIds)));

    if (empty($userIds)) {
        echo "[" . date('Y-m-d H:i:s') . "] No users for 7:00. Done.\n";
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Morning topics: " . count($userIds) . " user(s) at 7:00 (1 process per user)\n";

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
