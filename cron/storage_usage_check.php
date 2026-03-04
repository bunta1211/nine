<?php
/**
 * 共有フォルダ 容量監視 cronジョブ
 * 
 * 処理内容:
 * 1. 80%超過ユーザー/組織にAI通知
 * 2. 容量超過時: 3日ごとにリマインド通知
 * 3. ゴミ箱30日超過ファイルの自動削除
 * 4. pending状態の未完了アップロード(1時間超)のクリーンアップ
 * 
 * 設定方法:
 * crontab -e
 * 0 2 * * * php /var/www/html/cron/storage_usage_check.php >> /var/www/html/logs/cron_storage.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !defined('CRON_MODE')) {
    die('This script must be run from command line');
}

define('CRON_MODE', true);

$basePath = dirname(__DIR__);

require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/includes/storage_s3_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] Storage usage check started\n";

try {
    $pdo = getDB();

    // ================================================
    // 1. ゴミ箱30日超過ファイル自動削除
    // ================================================
    echo "--- Trash cleanup ---\n";
    $retDays = STORAGE_TRASH_RETENTION_DAYS;
    $stmt = $pdo->prepare("
        SELECT id, s3_key, thumbnail_s3_key FROM storage_files
        WHERE status = 'deleted' AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$retDays]);
    $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($expiredFiles)) {
        $ids = [];
        $s3Keys = [];
        foreach ($expiredFiles as $f) {
            $ids[] = (int) $f['id'];
            $s3Keys[] = $f['s3_key'];
            if ($f['thumbnail_s3_key']) $s3Keys[] = $f['thumbnail_s3_key'];
        }
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM storage_files WHERE id IN ({$in})");
        deleteMultipleFromS3($s3Keys);
        echo "  Deleted " . count($expiredFiles) . " expired trash file(s)\n";
    } else {
        echo "  No expired trash files\n";
    }

    // ================================================
    // 2. pending状態のクリーンアップ (1時間超)
    // ================================================
    echo "--- Pending cleanup ---\n";
    $stmt = $pdo->query("
        SELECT id, s3_key FROM storage_files
        WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $pendingFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($pendingFiles)) {
        $ids = [];
        $keys = [];
        foreach ($pendingFiles as $f) {
            $ids[] = (int) $f['id'];
            $keys[] = $f['s3_key'];
        }
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM storage_files WHERE id IN ({$in})");
        deleteMultipleFromS3($keys);
        echo "  Cleaned " . count($pendingFiles) . " pending upload(s)\n";
    } else {
        echo "  No stale pending uploads\n";
    }

    // ================================================
    // 3. 容量通知チェック
    // ================================================
    echo "--- Usage notifications ---\n";
    $entities = [];

    $orgStmt = $pdo->query("
        SELECT DISTINCT c.organization_id
        FROM storage_folders sf
        JOIN conversations c ON sf.conversation_id = c.id
        WHERE c.organization_id IS NOT NULL
    ");
    while ($row = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
        $entities[] = ['type' => 'organization', 'id' => (int) $row['organization_id']];
    }

    $userStmt = $pdo->query("
        SELECT DISTINCT c.created_by
        FROM storage_folders sf
        JOIN conversations c ON sf.conversation_id = c.id
        WHERE c.organization_id IS NULL
    ");
    while ($row = $userStmt->fetch(PDO::FETCH_ASSOC)) {
        $entities[] = ['type' => 'user', 'id' => (int) $row['created_by']];
    }

    $notified = 0;
    foreach ($entities as $entity) {
        // 容量無制限の組織は通知対象外
        if ($entity['type'] === 'organization' && defined('STORAGE_UNLIMITED_ORGANIZATION_IDS') && is_array(STORAGE_UNLIMITED_ORGANIZATION_IDS)) {
            $unlimitedIds = array_map('intval', STORAGE_UNLIMITED_ORGANIZATION_IDS);
            if (in_array($entity['id'], $unlimitedIds, true)) {
                continue;
            }
        }

        $usage = getStorageUsage($pdo, $entity['type'], $entity['id']);
        $sub   = getStorageSubscription($pdo, $entity['type'], $entity['id']);
        $quota = $sub['quota_bytes'];

        if ($quota <= 0) continue;
        $pct = $usage / $quota * 100;

        if ($pct >= 100) {
            if (shouldNotify($pdo, $entity, 'exceeded', 3)) {
                sendStorageNotification($pdo, $entity, 'exceeded', $usage, $quota);
                $notified++;
            }
        } elseif ($pct >= 90) {
            if (shouldNotify($pdo, $entity, '90_percent', 7)) {
                sendStorageNotification($pdo, $entity, '90_percent', $usage, $quota);
                $notified++;
            }
        } elseif ($pct >= 80) {
            if (shouldNotify($pdo, $entity, '80_percent', 14)) {
                sendStorageNotification($pdo, $entity, '80_percent', $usage, $quota);
                $notified++;
            }
        }
    }
    echo "  Sent {$notified} notification(s)\n";

    echo "[" . date('Y-m-d H:i:s') . "] Storage usage check completed\n";

} catch (Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    error_log("storage_usage_check fatal: " . $e->getMessage());
    exit(1);
}

// ============================================
// ヘルパー関数
// ============================================

function shouldNotify(PDO $pdo, array $entity, string $type, int $intervalDays): bool {
    $stmt = $pdo->prepare("
        SELECT notified_at FROM storage_usage_logs
        WHERE entity_type = ? AND entity_id = ? AND notification_type = ?
        ORDER BY notified_at DESC LIMIT 1
    ");
    $stmt->execute([$entity['type'], $entity['id'], $type]);
    $last = $stmt->fetchColumn();

    if (!$last) return true;
    $diff = (new DateTime())->diff(new DateTime($last))->days;
    return $diff >= $intervalDays;
}

function sendStorageNotification(PDO $pdo, array $entity, string $type, int $used, int $quota): void {
    $pdo->prepare("
        INSERT INTO storage_usage_logs (entity_type, entity_id, used_bytes, quota_bytes, notification_type)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$entity['type'], $entity['id'], $used, $quota, $type]);

    $usedStr  = formatBytes($used);
    $quotaStr = formatBytes($quota);
    $pct = round($used / $quota * 100, 1);

    $messages = [
        '80_percent'  => "共有フォルダの容量が{$pct}%（{$usedStr} / {$quotaStr}）に達しました。不要なファイルを整理することをお勧めします。",
        '90_percent'  => "⚠️ 共有フォルダの容量が{$pct}%（{$usedStr} / {$quotaStr}）に達しています。空き容量が少なくなっています。",
        'exceeded'    => "🚫 共有フォルダの容量を超過しています（{$usedStr} / {$quotaStr}）。新しいファイルのアップロードがブロックされています。不要なファイルを削除するか、プランのアップグレードをご検討ください。",
    ];
    $message = $messages[$type] ?? "共有フォルダの容量通知: {$usedStr} / {$quotaStr}";

    $targetUsers = getNotificationTargetUsers($pdo, $entity);
    foreach ($targetUsers as $uid) {
        insertAiNotification($pdo, (int) $uid, $message);
    }

    echo "  [{$entity['type']}:{$entity['id']}] {$type} ({$pct}%)\n";
}

function getNotificationTargetUsers(PDO $pdo, array $entity): array {
    if ($entity['type'] === 'organization') {
        $stmt = $pdo->prepare("
            SELECT user_id FROM organization_members
            WHERE organization_id = ? AND role IN ('owner','admin') AND left_at IS NULL
        ");
        $stmt->execute([$entity['id']]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $users ?: [];
    }
    return [$entity['id']];
}

function insertAiNotification(PDO $pdo, int $userId, string $message): void {
    try {
        $hasIsProactive = false;
        try {
            $pdo->query("SELECT is_proactive FROM ai_conversations LIMIT 0");
            $hasIsProactive = true;
        } catch (Throwable $ignore) {}

        if ($hasIsProactive) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, is_proactive, created_at)
                VALUES (?, '（ストレージ通知）', ?, 'system_storage', 'ja', 1, NOW())
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at)
                VALUES (?, '（ストレージ通知）', ?, 'system_storage', 'ja', NOW())
            ");
        }
        $stmt->execute([$userId, $message]);
    } catch (Throwable $e) {
        error_log("Storage notification insert error for user {$userId}: " . $e->getMessage());
    }
}
