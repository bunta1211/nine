<?php
/**
 * AIクローン（あなたの秘書）判断材料 API
 *
 * 判断材料フォルダ・フォルダ内アイテムの CRUD。共有フォルダ（storage）と同じ形式でユーザー単位で管理。
 */

header('Content-Type: application/json; charset=utf-8');

define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/api-helpers.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user_id = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ---------- フォルダ ----------
        case 'list_folders': {
            $parent_id = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int) $_GET['parent_id'] : null;
            $sql = "SELECT id, user_id, parent_id, name, sort_order, created_at, updated_at
                    FROM user_ai_judgment_folders WHERE user_id = ?";
            if ($parent_id === null) {
                $sql .= " AND parent_id IS NULL";
                $stmt = $pdo->prepare($sql . " ORDER BY sort_order, id");
                $stmt->execute([$user_id]);
            } else {
                $sql .= " AND parent_id = ?";
                $stmt = $pdo->prepare($sql . " ORDER BY sort_order, id");
                $stmt->execute([$user_id, $parent_id]);
            }
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($folders as &$f) {
                $f['id'] = (int) $f['id'];
                $f['user_id'] = (int) $f['user_id'];
                $f['parent_id'] = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
                $f['sort_order'] = (int) $f['sort_order'];
            }
            successResponse(['folders' => $folders]);
        }

        case 'create_folder': {
            $parent_id = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
            $name = trim($input['name'] ?? '');
            if ($name === '') {
                errorResponse('フォルダ名を入力してください');
            }
            if ($parent_id !== null) {
                $stmt = $pdo->prepare("SELECT id FROM user_ai_judgment_folders WHERE id = ? AND user_id = ?");
                $stmt->execute([$parent_id, $user_id]);
                if (!$stmt->fetch()) {
                    errorResponse('親フォルダが存在しないか、権限がありません');
                }
            }
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM user_ai_judgment_folders WHERE user_id = ? AND " . ($parent_id === null ? "parent_id IS NULL" : "parent_id = ?"));
            if ($parent_id === null) {
                $stmt->execute([$user_id]);
            } else {
                $stmt->execute([$user_id, $parent_id]);
            }
            $next = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO user_ai_judgment_folders (user_id, parent_id, name, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $parent_id, $name, $next]);
            successResponse(['folder_id' => (int) $pdo->lastInsertId()]);
        }

        case 'rename_folder': {
            $folder_id = (int) ($input['folder_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            if (!$folder_id || $name === '') {
                errorResponse('フォルダIDと名前を指定してください');
            }
            $stmt = $pdo->prepare("UPDATE user_ai_judgment_folders SET name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $folder_id, $user_id]);
            if ($stmt->rowCount() === 0) {
                errorResponse('フォルダが存在しないか、権限がありません');
            }
            successResponse();
        }

        case 'delete_folder': {
            $folder_id = (int) ($input['folder_id'] ?? 0);
            if (!$folder_id) {
                errorResponse('フォルダIDを指定してください');
            }
            $stmt = $pdo->prepare("DELETE FROM user_ai_judgment_folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$folder_id, $user_id]);
            if ($stmt->rowCount() === 0) {
                errorResponse('フォルダが存在しないか、権限がありません');
            }
            successResponse();
        }

        case 'reorder_folders': {
            $orders = $input['orders'] ?? [];
            if (!is_array($orders)) {
                errorResponse('orders は配列で指定してください');
            }
            $pdo->beginTransaction();
            try {
                foreach ($orders as $i => $item) {
                    $fid = (int) ($item['id'] ?? $item['folder_id'] ?? 0);
                    if (!$fid) continue;
                    $stmt = $pdo->prepare("UPDATE user_ai_judgment_folders SET sort_order = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$i, $fid, $user_id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            successResponse();
        }

        // ---------- フォルダ内アイテム ----------
        case 'list_items': {
            $folder_id = (int) ($_GET['folder_id'] ?? 0);
            if (!$folder_id) {
                errorResponse('folder_id を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id, folder_id, user_id, title, content, file_path, sort_order, created_at, updated_at
                    FROM user_ai_judgment_items WHERE folder_id = ? AND user_id = ? ORDER BY sort_order, id");
            $stmt->execute([$folder_id, $user_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['id'] = (int) $it['id'];
                $it['folder_id'] = (int) $it['folder_id'];
                $it['user_id'] = (int) $it['user_id'];
                $it['sort_order'] = (int) $it['sort_order'];
            }
            successResponse(['items' => $items]);
        }

        case 'create_item': {
            $folder_id = (int) ($input['folder_id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $content = $input['content'] ?? '';
            $file_path = isset($input['file_path']) ? trim($input['file_path']) : null;
            if (!$folder_id) {
                errorResponse('folder_id を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id FROM user_ai_judgment_folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$folder_id, $user_id]);
            if (!$stmt->fetch()) {
                errorResponse('フォルダが存在しないか、権限がありません');
            }
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM user_ai_judgment_items WHERE folder_id = ?");
            $stmt->execute([$folder_id]);
            $next = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO user_ai_judgment_items (folder_id, user_id, title, content, file_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$folder_id, $user_id, $title, $content, $file_path, $next]);
            successResponse(['item_id' => (int) $pdo->lastInsertId()]);
        }

        case 'update_item': {
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$item_id) {
                errorResponse('item_id を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id FROM user_ai_judgment_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$item_id, $user_id]);
            if (!$stmt->fetch()) {
                errorResponse('アイテムが存在しないか、権限がありません');
            }
            $updates = [];
            $params = [];
            if (array_key_exists('title', $input)) {
                $updates[] = 'title = ?';
                $params[] = trim($input['title'] ?? '');
            }
            if (array_key_exists('content', $input)) {
                $updates[] = 'content = ?';
                $params[] = $input['content'];
            }
            if (array_key_exists('file_path', $input)) {
                $updates[] = 'file_path = ?';
                $params[] = $input['file_path'] === null || $input['file_path'] === '' ? null : trim($input['file_path']);
            }
            if (empty($updates)) {
                successResponse();
            }
            $params[] = $item_id;
            $params[] = $user_id;
            $sql = "UPDATE user_ai_judgment_items SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ? AND user_id = ?";
            $pdo->prepare($sql)->execute($params);
            successResponse();
        }

        case 'delete_item': {
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$item_id) {
                errorResponse('item_id を指定してください');
            }
            $stmt = $pdo->prepare("DELETE FROM user_ai_judgment_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$item_id, $user_id]);
            if ($stmt->rowCount() === 0) {
                errorResponse('アイテムが存在しないか、権限がありません');
            }
            successResponse();
        }

        case 'reorder_items': {
            $folder_id = (int) ($input['folder_id'] ?? 0);
            $orders = $input['orders'] ?? [];
            if (!$folder_id || !is_array($orders)) {
                errorResponse('folder_id と orders 配列を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id FROM user_ai_judgment_folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$folder_id, $user_id]);
            if (!$stmt->fetch()) {
                errorResponse('フォルダが存在しないか、権限がありません');
            }
            $pdo->beginTransaction();
            try {
                foreach ($orders as $i => $item) {
                    $iid = (int) ($item['id'] ?? $item['item_id'] ?? 0);
                    if (!$iid) continue;
                    $stmt = $pdo->prepare("UPDATE user_ai_judgment_items SET sort_order = ? WHERE id = ? AND user_id = ? AND folder_id = ?");
                    $stmt->execute([$i, $iid, $user_id, $folder_id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            successResponse();
        }

        default:
            errorResponse('不明な action です', 400);
    }
} catch (PDOException $e) {
    error_log('ai-judgment API: ' . $e->getMessage());
    errorResponse('データベースエラーが発生しました', 500);
}
