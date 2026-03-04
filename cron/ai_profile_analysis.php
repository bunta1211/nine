<?php
/**
 * ユーザー性格分析バッチ — cron エントリポイント
 * 
 * 定期実行（例: 日次）で、一定回数以上会話したユーザーの
 * 性格プロファイルを深く分析・更新する。
 * 
 * 計画書 セクション2「ユーザー性格・行動分析と自動適応」に基づく。
 * 
 * cron 設定例:
 *   0 3 * * * php /var/www/html/cron/ai_profile_analysis.php >> /var/log/ai_profile_analysis.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_CRON')) {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] ユーザー性格分析バッチ開始\n";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
require_once __DIR__ . '/../includes/ai_user_profiler.php';

try {
    $pdo = getDB();

    // 10回以上会話があり、最終分析から7日以上経過 or 未分析のユーザーを対象
    $stmt = $pdo->query("
        SELECT ac.user_id, COUNT(*) AS conv_count
        FROM ai_conversations ac
        LEFT JOIN user_ai_profile uap ON uap.user_id = ac.user_id
        WHERE (uap.last_analyzed_at IS NULL OR uap.last_analyzed_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
        GROUP BY ac.user_id
        HAVING conv_count >= 10
        ORDER BY conv_count DESC
        LIMIT 50
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $analyzed = 0;
    foreach ($users as $u) {
        $uid = (int)$u['user_id'];
        echo "  ユーザー {$uid} ({$u['conv_count']}件の会話)... ";
        $result = deepAnalyzePersonality($uid);
        if ($result) {
            echo "分析完了\n";
            $analyzed++;
        } else {
            echo "スキップ\n";
        }
        usleep(500000); // API制限対策
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[" . date('Y-m-d H:i:s') . "] 完了: {$analyzed}/{$stmt->rowCount()}人を分析 ({$elapsed}秒)\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
