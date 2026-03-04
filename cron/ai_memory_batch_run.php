<?php
/**
 * グループチャット自動記憶バッチ — cronエントリポイント
 * 
 * 定期実行でグループチャットの未処理メッセージを分析し、
 * 専門AIの記憶ストアに蓄積する。
 * 
 * cron設定例（1時間ごと）:
 *   0 * * * * php /var/www/html/cron/ai_memory_batch_run.php >> /var/log/ai_memory_batch.log 2>&1
 */

define('IS_CRON', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/ai_memory_batch.php';

$startTime = microtime(true);
$maxPerConv = 50;

echo date('[Y-m-d H:i:s]') . " AI記憶バッチ開始\n";

try {
    $results = runAllOrgMemoryBatch($maxPerConv);

    $totalProcessed = 0;
    $totalCreated = 0;
    foreach ($results as $orgId => $r) {
        if ($r['processed'] > 0 || $r['created'] > 0) {
            echo "  org={$orgId}: {$r['processed']}件処理, {$r['created']}件記憶作成\n";
        }
        $totalProcessed += $r['processed'];
        $totalCreated += $r['created'];
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo date('[Y-m-d H:i:s]') . " 完了: {$totalProcessed}件処理, {$totalCreated}件記憶作成 ({$elapsed}秒)\n";
} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . " エラー: " . $e->getMessage() . "\n";
    error_log('AI memory batch error: ' . $e->getMessage());
    exit(1);
}
