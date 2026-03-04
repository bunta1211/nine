<?php
/**
 * 共有フォルダ S3ヘルパー
 *
 * S3操作・容量管理・署名付きURL生成
 */

require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function getS3Client(): ?S3Client {
    static $client = null;
    if ($client) return $client;

    $key    = STORAGE_S3_KEY;
    $secret = STORAGE_S3_SECRET;
    if ($key === '' || $secret === '') {
        if (getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI') || getenv('AWS_EXECUTION_ENV')) {
            $client = new S3Client([
                'region'  => STORAGE_S3_REGION,
                'version' => 'latest',
            ]);
            return $client;
        }
        return null;
    }

    $client = new S3Client([
        'region'      => STORAGE_S3_REGION,
        'version'     => 'latest',
        'credentials' => ['key' => $key, 'secret' => $secret],
    ]);
    return $client;
}

/**
 * アップロード用の署名付きURLを生成
 */
function createPresignedUploadUrl(string $s3Key, string $contentType, int $expiry = 0): ?string {
    $s3 = getS3Client();
    if (!$s3) return null;
    if ($expiry <= 0) $expiry = STORAGE_PRESIGN_UPLOAD_EXPIRY;

    try {
        $cmd = $s3->getCommand('PutObject', [
            'Bucket'      => STORAGE_S3_BUCKET,
            'Key'         => $s3Key,
            'ContentType' => $contentType,
        ]);
        $req = $s3->createPresignedRequest($cmd, "+{$expiry} seconds");
        return (string) $req->getUri();
    } catch (AwsException $e) {
        error_log('[Storage] Presigned upload URL error: ' . $e->getMessage());
        return null;
    }
}

/**
 * ダウンロード/プレビュー用の署名付きURLを生成
 */
function createPresignedDownloadUrl(string $s3Key, int $expiry = 0, ?string $downloadName = null): ?string {
    $s3 = getS3Client();
    if (!$s3) return null;
    if ($expiry <= 0) $expiry = STORAGE_PRESIGN_DOWNLOAD_EXPIRY;

    try {
        $params = [
            'Bucket' => STORAGE_S3_BUCKET,
            'Key'    => $s3Key,
        ];
        if ($downloadName) {
            $params['ResponseContentDisposition'] = 'attachment; filename="' . rawurlencode($downloadName) . '"';
        }
        $cmd = $s3->getCommand('GetObject', $params);
        $req = $s3->createPresignedRequest($cmd, "+{$expiry} seconds");
        return (string) $req->getUri();
    } catch (AwsException $e) {
        error_log('[Storage] Presigned download URL error: ' . $e->getMessage());
        return null;
    }
}

/**
 * S3からオブジェクトを削除
 */
function deleteFromS3(string $s3Key): bool {
    $s3 = getS3Client();
    if (!$s3) return false;

    try {
        $s3->deleteObject([
            'Bucket' => STORAGE_S3_BUCKET,
            'Key'    => $s3Key,
        ]);
        return true;
    } catch (AwsException $e) {
        error_log('[Storage] Delete error: ' . $e->getMessage());
        return false;
    }
}

/**
 * S3から複数オブジェクトを一括削除
 */
function deleteMultipleFromS3(array $s3Keys): bool {
    if (empty($s3Keys)) return true;
    $s3 = getS3Client();
    if (!$s3) return false;

    try {
        $objects = array_map(fn($k) => ['Key' => $k], $s3Keys);
        $s3->deleteObjects([
            'Bucket' => STORAGE_S3_BUCKET,
            'Delete' => ['Objects' => $objects],
        ]);
        return true;
    } catch (AwsException $e) {
        error_log('[Storage] Bulk delete error: ' . $e->getMessage());
        return false;
    }
}

/**
 * S3キーを生成
 */
function generateS3Key(string $entityType, int $entityId, int $folderId, string $filename): string {
    $uuid = bin2hex(random_bytes(8));
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return "storage/{$entityType}/{$entityId}/{$folderId}/{$uuid}_{$safe}";
}

/**
 * エンティティ（組織/個人）のストレージ使用量を取得
 */
function getStorageUsage(PDO $pdo, string $entityType, int $entityId): int {
    if ($entityType === 'organization') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(sf.file_size), 0) AS total
            FROM storage_files sf
            JOIN storage_folders sfo ON sf.folder_id = sfo.id
            JOIN conversations c ON sfo.conversation_id = c.id
            WHERE c.organization_id = ?
              AND sf.status IN ('active','deleted')
        ");
        $stmt->execute([$entityId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(sf.file_size), 0) AS total
            FROM storage_files sf
            JOIN storage_folders sfo ON sf.folder_id = sfo.id
            JOIN conversations c ON sfo.conversation_id = c.id
            WHERE c.organization_id IS NULL
              AND c.created_by = ?
              AND sf.status IN ('active','deleted')
        ");
        $stmt->execute([$entityId]);
    }
    return (int) $stmt->fetchColumn();
}

/**
 * エンティティのストレージ契約情報を取得
 */
function getStorageSubscription(PDO $pdo, string $entityType, int $entityId): array {
    // 組織で容量無制限に設定されている場合は極大 quota と unlimited フラグを返す
    if ($entityType === 'organization' && defined('STORAGE_UNLIMITED_ORGANIZATION_IDS') && is_array(STORAGE_UNLIMITED_ORGANIZATION_IDS)) {
        $unlimitedIds = array_map('intval', STORAGE_UNLIMITED_ORGANIZATION_IDS);
        if (in_array((int) $entityId, $unlimitedIds, true)) {
            $quota = defined('STORAGE_UNLIMITED_QUOTA') ? STORAGE_UNLIMITED_QUOTA : PHP_INT_MAX;
            return [
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'plan_name'     => 'unlimited',
                'quota_bytes'   => $quota,
                'monthly_price' => 0,
                'status'        => 'active',
                'unlimited'     => true,
            ];
        }
    }

    $stmt = $pdo->prepare("
        SELECT ss.*, sp.name AS plan_name, sp.quota_bytes, sp.monthly_price
        FROM storage_subscriptions ss
        JOIN storage_plans sp ON ss.plan_id = sp.id
        WHERE ss.entity_type = ? AND ss.entity_id = ?
    ");
    $stmt->execute([$entityType, $entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'plan_name'     => 'free',
            'quota_bytes'   => STORAGE_FREE_QUOTA,
            'monthly_price' => 0,
            'status'        => 'active',
            'unlimited'     => false,
        ];
    }

    $row['quota_bytes']   = (int) $row['quota_bytes'];
    $row['monthly_price'] = (int) $row['monthly_price'];
    $row['unlimited']     = false;
    return $row;
}

/**
 * conversation_id からエンティティ（組織/個人）を判定
 */
function resolveStorageEntity(PDO $pdo, int $conversationId): array {
    $stmt = $pdo->prepare("SELECT organization_id, created_by FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) return ['type' => 'user', 'id' => 0];

    if (!empty($conv['organization_id'])) {
        return ['type' => 'organization', 'id' => (int) $conv['organization_id']];
    }
    return ['type' => 'user', 'id' => (int) $conv['created_by']];
}

/**
 * ファイル拡張子がブロック対象か判定
 */
function isBlockedExtension(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $blocked = explode(',', STORAGE_BLOCKED_EXTENSIONS);
    return in_array($ext, $blocked, true);
}

/**
 * 容量を人間が読みやすい形式に変換
 */
function formatBytes(int $bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen((string) $bytes) - 1) / 3);
    $factor = min($factor, count($units) - 1);
    return round($bytes / pow(1024, $factor), $precision) . ' ' . $units[$factor];
}

/**
 * ユーザーの操作権限をチェック（組織グループ用）
 */
function checkStoragePermission(PDO $pdo, int $conversationId, int $userId, string $action): bool {
    $entity = resolveStorageEntity($pdo, $conversationId);

    if ($entity['type'] === 'user') {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT om.role FROM organization_members om
        WHERE om.organization_id = ? AND om.user_id = ? AND om.left_at IS NULL
    ");
    $stmt->execute([$entity['id'], $userId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($member && in_array($member['role'], ['owner', 'admin'])) {
        return true;
    }

    $columnMap = [
        'create_folder' => 'can_create_folder',
        'delete_folder' => 'can_delete_folder',
        'upload'        => 'can_upload',
        'delete_file'   => 'can_delete_file',
    ];
    $col = $columnMap[$action] ?? null;
    if (!$col) return false;

    $stmt = $pdo->prepare("
        SELECT {$col} FROM storage_member_permissions
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    $perm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$perm) {
        return in_array($action, ['create_folder', 'upload']);
    }
    return (int) $perm[$col] === 1;
}
