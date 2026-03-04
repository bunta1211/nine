<?php
/**
 * グループチャット自動記憶バッチ — cron エントリポイント
 * 
 * 定期実行（例: 毎時 or 日次）で、全組織のグループチャットから
 * 情報を抽出・分類し、専門AIの記憶ストアに保存する。
 * 
 * 計画書 2.4 に基づく。
 * 
 * cron 設定例:
 *   0 * * * * php /var/www/html/cron/ai_memory_batch.php >> /var/log/ai_memory_batch.log 2>&1
 */

// CLI実行のみ許可
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_CRON')) {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] AI記憶バッチ開始\n";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
require_once __DIR__ . '/../includes/ai_specialist_router.php';
require_once __DIR__ . '/../includes/ai_memory_batch.php';

try {
    $maxMessagesPerConv = 50;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $maxMessagesPerConv = (int)$argv[1];
    }

    $results = runAllOrgMemoryBatch($maxMessagesPerConv);

    $totalProcessed = 0;
    $totalCreated = 0;
    foreach ($results as $orgId => $r) {
        $totalProcessed += $r['processed'];
        $totalCreated += $r['created'];
        if ($r['processed'] > 0) {
            echo "  組織 {$orgId}: {$r['processed']}件処理 → {$r['created']}件記憶\n";
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[" . date('Y-m-d H:i:s') . "] 完了: {$totalProcessed}件処理, {$totalCreated}件記憶作成 ({$elapsed}秒)\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
