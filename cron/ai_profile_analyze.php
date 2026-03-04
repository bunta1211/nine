<?php
/**
 * ユーザー性格分析バッチ — cronエントリポイント
 * 
 * 一定期間ごとにユーザーの会話履歴を深く分析し、
 * user_ai_profileのpersonality_traitsを更新する。
 * 
 * cron設定例（毎日深夜3時）:
 *   0 3 * * * php /var/www/html/cron/ai_profile_analyze.php >> /var/log/ai_profile.log 2>&1
 */

define('IS_CRON', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_user_profiler.php';

$startTime = microtime(true);
echo date('[Y-m-d H:i:s]') . " ユーザー性格分析バッチ開始\n";

try {
    $pdo = getDB();

    $stmt = $pdo->query("
        SELECT p.user_id, p.last_analyzed_at,
               (SELECT COUNT(*) FROM ai_conversations c WHERE c.user_id = p.user_id) AS conv_count
        FROM user_ai_profile p
        WHERE p.last_analyzed_at IS NULL
           OR p.last_analyzed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY p.last_analyzed_at ASC
        LIMIT 50
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $analyzed = 0;
    foreach ($users as $u) {
        if ((int)$u['conv_count'] < 5) continue;

        $result = deepAnalyzePersonality((int)$u['user_id']);
        if ($result) {
            $analyzed++;
            echo "  user={$u['user_id']}: 分析完了\n";
        }
        usleep(500000);
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo date('[Y-m-d H:i:s]') . " 完了: {$analyzed}名分析 ({$elapsed}秒)\n";
} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . " エラー: " . $e->getMessage() . "\n";
    error_log('AI profile analyze error: ' . $e->getMessage());
    exit(1);
}
