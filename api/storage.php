<?php
/**
 * 共有フォルダ API
 *
 * フォルダ/ファイルCRUD、共有管理、署名付きURLアップロード、ゴミ箱、権限管理、検索
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/storage_s3_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ログインが必要です']);
    exit;
}

$pdo = getDB();
$userId = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * DBの日時を東京時間の Y-m-d H:i:s で返す。
 * MySQLがUTCで保存している場合（AWS RDSなど）は UTC→Asia/Tokyo に変換する。
 * MySQLが既にJSTで保存している場合は config で STORAGE_DB_TIMEZONE を 'Asia/Tokyo' にすること。
 * @param string|null $datetimeStr
 * @return string
 */
function storageDatetimeToTokyo($datetimeStr) {
    if ($datetimeStr === null || $datetimeStr === '') {
        return '';
    }
    $tzFrom = defined('STORAGE_DB_TIMEZONE') ? STORAGE_DB_TIMEZONE : 'UTC';
    try {
        $dt = new DateTime($datetimeStr, new DateTimeZone($tzFrom));
        $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $datetimeStr;
    }
}

try {
    switch ($action) {

        // ================================================
        // フォルダ操作
        // ================================================

        case 'get_folders': {
            $convId   = (int) ($_GET['conversation_id'] ?? 0);
            $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int) $_GET['parent_id'] : null;

            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $sql = "SELECT sf.*, 
                       (SELECT COUNT(*) FROM storage_files WHERE folder_id = sf.id AND status = 'active') AS file_count,
                       (SELECT COUNT(*) FROM storage_folders WHERE parent_id = sf.id) AS subfolder_count,
                       (SELECT COUNT(*) FROM storage_folder_shares WHERE folder_id = sf.id) AS share_count,
                       u.display_name AS creator_name
                    FROM storage_folders sf
                    LEFT JOIN users u ON sf.created_by = u.id
                    WHERE sf.conversation_id = ?";

            if ($parentId === null) {
                $sql .= " AND sf.parent_id IS NULL";
                $stmt = $pdo->prepare($sql . " ORDER BY sf.name");
                $stmt->execute([$convId]);
            } else {
                $sql .= " AND sf.parent_id = ?";
                $stmt = $pdo->prepare($sql . " ORDER BY sf.name");
                $stmt->execute([$convId, $parentId]);
            }

            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($folders as &$f) {
                $f['id']              = (int) $f['id'];
                $f['conversation_id']  = (int) $f['conversation_id'];
                $f['parent_id']        = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
                $f['created_by']      = (int) $f['created_by'];
                $f['file_count']      = (int) $f['file_count'];
                $f['subfolder_count'] = (int) $f['subfolder_count'];
                $f['share_count']     = (int) $f['share_count'];
                if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']);
                if (!empty($f['updated_at'])) $f['updated_at'] = storageDatetimeToTokyo($f['updated_at']);
            }

            echo json_encode(['success' => true, 'folders' => $folders]);
            break;
        }

        case 'create_folder': {
            $convId   = (int) ($_POST['conversation_id'] ?? 0);
            $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int) $_POST['parent_id'] : null;
            $name     = trim($_POST['name'] ?? '');

            if (!$convId || !$name) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }
            if (!isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }
            if (!checkStoragePermission($pdo, $convId, $userId, 'create_folder')) {
                echo json_encode(['success' => false, 'message' => 'フォルダ作成の権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO storage_folders (conversation_id, parent_id, name, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$convId, $parentId, $name, $userId]);
            $folderId = (int) $pdo->lastInsertId();

            echo json_encode(['success' => true, 'folder_id' => $folderId]);
            break;
        }

        case 'rename_folder': {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');

            if (!$folderId || !$name) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $pdo->prepare("UPDATE storage_folders SET name = ? WHERE id = ?")->execute([$name, $folderId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete_folder': {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            if (!$folderId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }
            if (!checkStoragePermission($pdo, (int) $folder['conversation_id'], $userId, 'delete_folder')) {
                echo json_encode(['success' => false, 'message' => 'フォルダ削除の権限がありません']);
                exit;
            }

            $s3Keys = collectS3KeysInFolder($pdo, $folderId);
            $pdo->prepare("DELETE FROM storage_folders WHERE id = ?")->execute([$folderId]);
            if (!empty($s3Keys)) {
                deleteMultipleFromS3($s3Keys);
            }

            echo json_encode(['success' => true]);
            break;
        }

        case 'set_folder_password': {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $password = $_POST['password'] ?? '';

            if (!$folderId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            if ($password === '') {
                $pdo->prepare("UPDATE storage_folders SET password_hash = NULL WHERE id = ?")->execute([$folderId]);
                echo json_encode(['success' => true, 'message' => 'パスワードを解除しました']);
                exit;
            }

            if (strlen($password) < 4) {
                echo json_encode(['success' => false, 'message' => 'パスワードは4文字以上にしてください']);
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE storage_folders SET password_hash = ? WHERE id = ?")->execute([$hash, $folderId]);
            echo json_encode(['success' => true, 'message' => 'パスワードを設定しました']);
            break;
        }

        // ================================================
        // ファイル操作
        // ================================================

        case 'get_files': {
            $folderId = (int) ($_GET['folder_id'] ?? 0);
            if (!$folderId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.*, u.display_name AS uploader_name
                FROM storage_files sf
                LEFT JOIN users u ON sf.uploaded_by = u.id
                WHERE sf.folder_id = ? AND sf.status = 'active'
                ORDER BY sf.original_name
            ");
            $stmt->execute([$folderId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($files as &$f) {
                $f['id']          = (int) $f['id'];
                $f['folder_id']   = (int) $f['folder_id'];
                $f['file_size']   = (int) $f['file_size'];
                $f['uploaded_by'] = (int) $f['uploaded_by'];
                if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']);
            }

            echo json_encode(['success' => true, 'files' => $files]);
            break;
        }

        case 'get_all_files': {
            $convId = (int) ($_GET['conversation_id'] ?? 0);
            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sfi.*, u.display_name AS uploader_name, sfo.name AS folder_name
                FROM storage_files sfi
                JOIN storage_folders sfo ON sfi.folder_id = sfo.id
                LEFT JOIN users u ON sfi.uploaded_by = u.id
                WHERE sfo.conversation_id = ? AND sfi.status = 'active'
                ORDER BY sfi.created_at DESC
                LIMIT 200
            ");
            $stmt->execute([$convId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($files as &$f) {
                $f['id']          = (int) $f['id'];
                $f['folder_id']   = (int) $f['folder_id'];
                $f['file_size']   = (int) $f['file_size'];
                $f['uploaded_by'] = (int) $f['uploaded_by'];
                if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']);
            }

            echo json_encode(['success' => true, 'files' => $files]);
            break;
        }

        case 'request_upload': {
            $folderId    = (int) ($_POST['folder_id'] ?? 0);
            $filename    = trim($_POST['filename'] ?? '');
            $fileSize    = (int) ($_POST['file_size'] ?? 0);
            $contentType = trim($_POST['content_type'] ?? 'application/octet-stream');

            if (!$folderId || !$filename || $fileSize <= 0) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }
            if (!checkStoragePermission($pdo, (int) $folder['conversation_id'], $userId, 'upload')) {
                echo json_encode(['success' => false, 'message' => 'アップロードの権限がありません']);
                exit;
            }

            if (isBlockedExtension($filename)) {
                echo json_encode(['success' => false, 'message' => 'このファイル形式はアップロードできません']);
                exit;
            }
            if ($fileSize > STORAGE_MAX_FILE_SIZE) {
                echo json_encode(['success' => false, 'message' => 'ファイルサイズが上限（500MB）を超えています']);
                exit;
            }

            $entity = resolveStorageEntity($pdo, (int) $folder['conversation_id']);
            $usage  = getStorageUsage($pdo, $entity['type'], $entity['id']);
            $sub    = getStorageSubscription($pdo, $entity['type'], $entity['id']);

            if ($usage + $fileSize > $sub['quota_bytes']) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ストレージ容量が不足しています（' . formatBytes($usage) . ' / ' . formatBytes($sub['quota_bytes']) . '）'
                ]);
                exit;
            }

            $s3Key = generateS3Key($entity['type'], $entity['id'], $folderId, $filename);

            $stmt = $pdo->prepare("
                INSERT INTO storage_files (folder_id, original_name, s3_key, file_size, mime_type, uploaded_by, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$folderId, $filename, $s3Key, $fileSize, $contentType, $userId]);
            $fileId = (int) $pdo->lastInsertId();

            $uploadUrl = createPresignedUploadUrl($s3Key, $contentType);
            if (!$uploadUrl) {
                $pdo->prepare("DELETE FROM storage_files WHERE id = ?")->execute([$fileId]);
                echo json_encode(['success' => false, 'message' => 'アップロードURLの生成に失敗しました']);
                exit;
            }

            echo json_encode([
                'success'    => true,
                'file_id'    => $fileId,
                'upload_url' => $uploadUrl,
                's3_key'     => $s3Key,
            ]);
            break;
        }

        case 'confirm_upload': {
            $fileId = (int) ($_POST['file_id'] ?? 0);
            if (!$fileId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM storage_files WHERE id = ? AND uploaded_by = ? AND status = 'pending'");
            $stmt->execute([$fileId, $userId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file) {
                echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません']);
                exit;
            }

            $pdo->prepare("UPDATE storage_files SET status = 'active' WHERE id = ?")->execute([$fileId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete_file': {
            $fileId = (int) ($_POST['file_id'] ?? 0);
            if (!$fileId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.*, sfo.conversation_id
                FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                WHERE sf.id = ? AND sf.status = 'active'
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file) {
                echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません']);
                exit;
            }

            $convId = (int) $file['conversation_id'];
            if (!isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $canDelete = ((int) $file['uploaded_by'] === $userId)
                || checkStoragePermission($pdo, $convId, $userId, 'delete_file');
            if (!$canDelete) {
                echo json_encode(['success' => false, 'message' => 'ファイル削除の権限がありません']);
                exit;
            }

            $pdo->prepare("UPDATE storage_files SET status = 'deleted', deleted_at = NOW(), deleted_by = ? WHERE id = ?")
                ->execute([$userId, $fileId]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'download_file':
        case 'preview_file': {
            $fileId = (int) ($_GET['file_id'] ?? 0);
            if (!$fileId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.*, sfo.conversation_id
                FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                WHERE sf.id = ? AND sf.status = 'active'
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file) {
                echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません']);
                exit;
            }

            if (!isConversationMember($pdo, (int) $file['conversation_id'], $userId)
                && !isSharedFolderAccessible($pdo, (int) $file['folder_id'], $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $downloadName = ($action === 'download_file') ? $file['original_name'] : null;
            $url = createPresignedDownloadUrl($file['s3_key'], 0, $downloadName);
            if (!$url) {
                echo json_encode(['success' => false, 'message' => 'URLの生成に失敗しました']);
                exit;
            }

            echo json_encode(['success' => true, 'url' => $url, 'mime_type' => $file['mime_type'], 'original_name' => $file['original_name']]);
            break;
        }

        // ================================================
        // 共有管理
        // ================================================

        case 'get_shares': {
            $folderId = (int) ($_GET['folder_id'] ?? 0);
            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sfs.*, c.name AS conversation_name
                FROM storage_folder_shares sfs
                JOIN conversations c ON sfs.shared_with_conversation_id = c.id
                WHERE sfs.folder_id = ?
            ");
            $stmt->execute([$folderId]);
            $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($shares as &$s) {
                $s['id']        = (int) $s['id'];
                $s['folder_id'] = (int) $s['folder_id'];
                $s['shared_with_conversation_id'] = (int) $s['shared_with_conversation_id'];
                $s['shared_by'] = (int) $s['shared_by'];
            }

            echo json_encode(['success' => true, 'shares' => $shares]);
            break;
        }

        case 'share_folder': {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $targetConvId = (int) ($_POST['conversation_id'] ?? 0);
            $permission = ($_POST['permission'] ?? 'read') === 'readwrite' ? 'readwrite' : 'read';

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }
            if (!isConversationMember($pdo, $targetConvId, $userId)) {
                echo json_encode(['success' => false, 'message' => '共有先グループのメンバーではありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO storage_folder_shares (folder_id, shared_with_conversation_id, permission, shared_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission)
            ");
            $stmt->execute([$folderId, $targetConvId, $permission, $userId]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'unshare_folder': {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $targetConvId = (int) ($_POST['conversation_id'] ?? 0);

            $folder = getFolderIfAllowed($pdo, $folderId, $userId);
            if (!$folder) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $pdo->prepare("DELETE FROM storage_folder_shares WHERE folder_id = ? AND shared_with_conversation_id = ?")
                ->execute([$folderId, $targetConvId]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'get_shared_folders': {
            $convId = (int) ($_GET['conversation_id'] ?? 0);
            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.*, sfs.permission, c.name AS source_group_name,
                       (SELECT COUNT(*) FROM storage_files WHERE folder_id = sf.id AND status = 'active') AS file_count
                FROM storage_folder_shares sfs
                JOIN storage_folders sf ON sfs.folder_id = sf.id
                JOIN conversations c ON sf.conversation_id = c.id
                WHERE sfs.shared_with_conversation_id = ?
                ORDER BY sf.name
            ");
            $stmt->execute([$convId]);
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($folders as &$f) {
                $f['id']              = (int) $f['id'];
                $f['conversation_id'] = (int) $f['conversation_id'];
                $f['file_count']      = (int) $f['file_count'];
                if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']);
                if (!empty($f['updated_at'])) $f['updated_at'] = storageDatetimeToTokyo($f['updated_at']);
            }

            echo json_encode(['success' => true, 'shared_folders' => $folders]);
            break;
        }

        // ================================================
        // 容量・使用状況
        // ================================================

        case 'get_usage': {
            $convId = (int) ($_GET['conversation_id'] ?? 0);
            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $entity = resolveStorageEntity($pdo, $convId);
            $usage  = getStorageUsage($pdo, $entity['type'], $entity['id']);
            $sub    = getStorageSubscription($pdo, $entity['type'], $entity['id']);

            $unlimited = !empty($sub['unlimited']);
            $quota     = $sub['quota_bytes'];
            $percent   = $unlimited ? 0 : ($quota > 0 ? round($usage / $quota * 100, 1) : 0);
            $quotaDisplay = $unlimited ? '無制限' : formatBytes($quota);

            echo json_encode([
                'success'       => true,
                'used_bytes'    => $usage,
                'quota_bytes'   => $quota,
                'used_display'  => formatBytes($usage),
                'quota_display' => $quotaDisplay,
                'percent'       => $percent,
                'plan_name'     => $sub['plan_name'],
                'unlimited'     => $unlimited,
            ]);
            break;
        }

        // ================================================
        // ゴミ箱
        // ================================================

        case 'get_trash': {
            $convId = (int) ($_GET['conversation_id'] ?? 0);
            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.*, sfo.name AS folder_name, u.display_name AS deleted_by_name
                FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                LEFT JOIN users u ON sf.deleted_by = u.id
                WHERE sfo.conversation_id = ? AND sf.status = 'deleted'
                ORDER BY sf.deleted_at DESC
            ");
            $stmt->execute([$convId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $retentionDays = STORAGE_TRASH_RETENTION_DAYS;

            foreach ($files as &$f) {
                $f['id']          = (int) $f['id'];
                $f['file_size']   = (int) $f['file_size'];
                $f['uploaded_by'] = (int) $f['uploaded_by'];
                $deletedAt = new DateTime($f['deleted_at']);
                $now = new DateTime();
                $f['days_remaining'] = max(0, $retentionDays - $deletedAt->diff($now)->days);
                if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']);
                if (!empty($f['deleted_at'])) $f['deleted_at'] = storageDatetimeToTokyo($f['deleted_at']);
            }

            echo json_encode(['success' => true, 'files' => $files, 'retention_days' => $retentionDays]);
            break;
        }

        case 'restore_file': {
            $fileId = (int) ($_POST['file_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT sf.*, sfo.conversation_id FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                WHERE sf.id = ? AND sf.status = 'deleted'
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file || !isConversationMember($pdo, (int) $file['conversation_id'], $userId)) {
                echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません']);
                exit;
            }

            $pdo->prepare("UPDATE storage_files SET status = 'active', deleted_at = NULL, deleted_by = NULL WHERE id = ?")
                ->execute([$fileId]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'empty_trash': {
            $convId = (int) ($_POST['conversation_id'] ?? 0);
            if (!$convId || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => false, 'message' => 'アクセス権限がありません']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT sf.s3_key, sf.thumbnail_s3_key FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                WHERE sfo.conversation_id = ? AND sf.status = 'deleted'
            ");
            $stmt->execute([$convId]);
            $keys = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $keys[] = $row['s3_key'];
                if ($row['thumbnail_s3_key']) $keys[] = $row['thumbnail_s3_key'];
            }

            $pdo->prepare("
                DELETE sf FROM storage_files sf
                JOIN storage_folders sfo ON sf.folder_id = sfo.id
                WHERE sfo.conversation_id = ? AND sf.status = 'deleted'
            ")->execute([$convId]);

            if (!empty($keys)) deleteMultipleFromS3($keys);

            echo json_encode(['success' => true]);
            break;
        }

        // ================================================
        // 権限管理（組織グループのみ）
        // ================================================

        case 'get_permissions': {
            $convId = (int) ($_GET['conversation_id'] ?? 0);
            if (!$convId) {
                echo json_encode(['success' => false, 'message' => 'パラメータが不足しています']);
                exit;
            }

            $entity = resolveStorageEntity($pdo, $convId);
            if ($entity['type'] !== 'organization') {
                echo json_encode(['success' => false, 'message' => '個人グループでは権限管理は不要です']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT cm.user_id, u.display_name AS name, u.icon_path,
                       smp.can_create_folder, smp.can_delete_folder, smp.can_upload, smp.can_delete_file
                FROM conversation_members cm
                JOIN users u ON cm.user_id = u.id
                LEFT JOIN storage_member_permissions smp ON smp.conversation_id = cm.conversation_id AND smp.user_id = cm.user_id
                WHERE cm.conversation_id = ?
                ORDER BY u.display_name
            ");
            $stmt->execute([$convId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($members as &$m) {
                $m['user_id']           = (int) $m['user_id'];
                $m['can_create_folder'] = (int) ($m['can_create_folder'] ?? 1);
                $m['can_delete_folder'] = (int) ($m['can_delete_folder'] ?? 0);
                $m['can_upload']        = (int) ($m['can_upload'] ?? 1);
                $m['can_delete_file']   = (int) ($m['can_delete_file'] ?? 0);
            }

            echo json_encode(['success' => true, 'members' => $members]);
            break;
        }

        case 'update_permission': {
            $convId  = (int) ($_POST['conversation_id'] ?? 0);
            $targetUserId = (int) ($_POST['user_id'] ?? 0);

            $entity = resolveStorageEntity($pdo, $convId);
            if ($entity['type'] !== 'organization') {
                echo json_encode(['success' => false, 'message' => '個人グループでは権限管理は不要です']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT role FROM organization_members WHERE organization_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$entity['id'], $userId]);
            $myRole = $stmt->fetchColumn();
            if (!in_array($myRole, ['owner', 'admin'])) {
                echo json_encode(['success' => false, 'message' => '権限変更は組織管理者のみ可能です']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO storage_member_permissions (conversation_id, user_id, can_create_folder, can_delete_folder, can_upload, can_delete_file, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    can_create_folder = VALUES(can_create_folder),
                    can_delete_folder = VALUES(can_delete_folder),
                    can_upload = VALUES(can_upload),
                    can_delete_file = VALUES(can_delete_file),
                    updated_by = VALUES(updated_by)
            ");
            $stmt->execute([
                $convId,
                $targetUserId,
                (int) ($_POST['can_create_folder'] ?? 1),
                (int) ($_POST['can_delete_folder'] ?? 0),
                (int) ($_POST['can_upload'] ?? 1),
                (int) ($_POST['can_delete_file'] ?? 0),
                $userId,
            ]);

            echo json_encode(['success' => true]);
            break;
        }

        // ================================================
        // 検索
        // ================================================

        case 'search': {
            $convId  = (int) ($_GET['conversation_id'] ?? 0);
            $keyword = trim($_GET['keyword'] ?? '');

            if (!$convId || !$keyword || !isConversationMember($pdo, $convId, $userId)) {
                echo json_encode(['success' => true, 'folders' => [], 'files' => []]);
                exit;
            }

            $like = '%' . $keyword . '%';

            $stmt = $pdo->prepare("
                SELECT sf.*, 'folder' AS item_type FROM storage_folders sf
                WHERE sf.conversation_id = ? AND sf.name LIKE ?
                ORDER BY sf.name LIMIT 50
            ");
            $stmt->execute([$convId, $like]);
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT sfi.*, sfo.name AS folder_name, sfo.conversation_id, 'file' AS item_type
                FROM storage_files sfi
                JOIN storage_folders sfo ON sfi.folder_id = sfo.id
                WHERE sfo.conversation_id = ? AND sfi.original_name LIKE ? AND sfi.status = 'active'
                ORDER BY sfi.original_name LIMIT 50
            ");
            $stmt->execute([$convId, $like]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($folders as &$f) { $f['id'] = (int) $f['id']; if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']); if (!empty($f['updated_at'])) $f['updated_at'] = storageDatetimeToTokyo($f['updated_at']); }
            foreach ($files as &$f)   { $f['id'] = (int) $f['id']; $f['file_size'] = (int) $f['file_size']; if (!empty($f['created_at'])) $f['created_at'] = storageDatetimeToTokyo($f['created_at']); }

            echo json_encode(['success' => true, 'folders' => $folders, 'files' => $files]);
            break;
        }

        // ================================================
        // パンくずリスト用
        // ================================================

        case 'get_breadcrumbs': {
            $folderId = (int) ($_GET['folder_id'] ?? 0);
            $crumbs = [];
            $currentId = $folderId;

            while ($currentId) {
                $stmt = $pdo->prepare("SELECT id, parent_id, name, conversation_id FROM storage_folders WHERE id = ?");
                $stmt->execute([$currentId]);
                $f = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$f) break;
                array_unshift($crumbs, ['id' => (int) $f['id'], 'name' => $f['name']]);
                $currentId = $f['parent_id'] ? (int) $f['parent_id'] : 0;
            }

            echo json_encode(['success' => true, 'breadcrumbs' => $crumbs]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => '不明なアクションです']);
    }

} catch (Exception $e) {
    error_log('[Storage API] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}

// ================================================
// ヘルパー関数
// ================================================

function isConversationMember(PDO $pdo, int $convId, int $userId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$convId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function getFolderIfAllowed(PDO $pdo, int $folderId, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM storage_folders WHERE id = ?");
    $stmt->execute([$folderId]);
    $folder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$folder) return null;

    if (isConversationMember($pdo, (int) $folder['conversation_id'], $userId)) {
        return $folder;
    }
    if (isSharedFolderAccessible($pdo, $folderId, $userId)) {
        return $folder;
    }
    return null;
}

function isSharedFolderAccessible(PDO $pdo, int $folderId, int $userId): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM storage_folder_shares sfs
        JOIN conversation_members cm ON sfs.shared_with_conversation_id = cm.conversation_id
        WHERE sfs.folder_id = ? AND cm.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$folderId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function collectS3KeysInFolder(PDO $pdo, int $folderId): array {
    $keys = [];

    $stmt = $pdo->prepare("SELECT s3_key, thumbnail_s3_key FROM storage_files WHERE folder_id = ?");
    $stmt->execute([$folderId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $keys[] = $row['s3_key'];
        if ($row['thumbnail_s3_key']) $keys[] = $row['thumbnail_s3_key'];
    }

    $stmt = $pdo->prepare("SELECT id FROM storage_folders WHERE parent_id = ?");
    $stmt->execute([$folderId]);
    while ($sub = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $keys = array_merge($keys, collectS3KeysInFolder($pdo, (int) $sub['id']));
    }

    return $keys;
}
