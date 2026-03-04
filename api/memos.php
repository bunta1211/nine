<?php
/**
 * メモ管理API（deprecated ラッパー）
 * 新規コードでは api/tasks.php?type=memo を使用すること
 * 既存の呼び出し元との互換性のために残す
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $_GET['type'] = 'memo';
        require __DIR__ . '/tasks.php';
        exit;

    case 'get':
        $_GET['type'] = 'memo';
        require __DIR__ . '/tasks.php';
        exit;

    case 'create':
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $color = $input['color'] ?? '#ffffff';
        $conversation_id = isset($input['conversation_id']) ? (int)$input['conversation_id'] : null;
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : null;
        $is_pinned = !empty($input['is_pinned']) ? 1 : 0;

        if (empty($title) && empty($content)) {
            errorResponse('タイトルまたは内容が必要です');
        }
        if (empty($title)) {
            $title = mb_substr($content, 0, 50);
        }

        $hasType = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'");
            $hasType = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}

        if ($hasType) {
            $stmt = $pdo->prepare("
                INSERT INTO tasks (type, title, content, color, created_by, conversation_id, message_id, is_pinned, status, priority, created_at, updated_at)
                VALUES ('memo', ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())
            ");
            $stmt->execute([$title, $content, $color, $user_id, $conversation_id, $message_id, $is_pinned]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO memos (title, content, color, created_by, conversation_id, message_id, is_pinned, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$title, $content, $color, $user_id, $conversation_id, $message_id, $is_pinned]);
        }
        $memo_id = (int)$pdo->lastInsertId();

        successResponse(['memo_id' => $memo_id, 'task_id' => $memo_id], 'メモを作成しました');
        break;

    case 'update':
        $memo_id = (int)($input['memo_id'] ?? $input['task_id'] ?? 0);
        if (!$memo_id) {
            errorResponse('メモIDが必要です');
        }

        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND created_by = ? AND type = 'memo'");
        $stmt->execute([$memo_id, $user_id]);
        $found = $stmt->fetch();

        if (!$found) {
            $stmt2 = $pdo->prepare("SELECT id FROM memos WHERE id = ? AND created_by = ?");
            $stmt2->execute([$memo_id, $user_id]);
            if (!$stmt2->fetch()) {
                errorResponse('メモが見つかりません', 404);
            }
            $updates = [];
            $params = [];
            if (isset($input['title']))    { $updates[] = 'title = ?';     $params[] = trim($input['title']); }
            if (isset($input['content']))  { $updates[] = 'content = ?';   $params[] = trim($input['content']); }
            if (isset($input['color']))    { $updates[] = 'color = ?';     $params[] = $input['color']; }
            if (isset($input['is_pinned'])){ $updates[] = 'is_pinned = ?'; $params[] = !empty($input['is_pinned']) ? 1 : 0; }
            if (empty($updates)) { errorResponse('更新する項目がありません'); }
            $updates[] = 'updated_at = NOW()';
            $params[] = $memo_id;
            $pdo->prepare("UPDATE memos SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            successResponse([], 'メモを更新しました');
            break;
        }

        $updates = [];
        $params = [];
        if (isset($input['title']))    { $updates[] = 'title = ?';     $params[] = trim($input['title']); }
        if (isset($input['content']))  { $updates[] = 'content = ?';   $params[] = trim($input['content']); }
        if (isset($input['color']))    { $updates[] = 'color = ?';     $params[] = $input['color']; }
        if (isset($input['is_pinned'])){ $updates[] = 'is_pinned = ?'; $params[] = !empty($input['is_pinned']) ? 1 : 0; }
        if (empty($updates)) { errorResponse('更新する項目がありません'); }
        $updates[] = 'updated_at = NOW()';
        $params[] = $memo_id;
        $pdo->prepare("UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        successResponse([], 'メモを更新しました');
        break;

    case 'delete':
        $memo_id = (int)($input['memo_id'] ?? $input['task_id'] ?? 0);
        if (!$memo_id) {
            errorResponse('メモIDが必要です');
        }

        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND created_by = ? AND type = 'memo'");
        $stmt->execute([$memo_id, $user_id]);
        if ($stmt->fetch()) {
            try {
                $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = ?")->execute([$memo_id]);
            } catch (PDOException $e) {
                $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$memo_id]);
            }
            successResponse([], 'メモを削除しました');
            break;
        }

        $stmt2 = $pdo->prepare("SELECT id FROM memos WHERE id = ? AND created_by = ?");
        $stmt2->execute([$memo_id, $user_id]);
        if (!$stmt2->fetch()) {
            errorResponse('メモが見つかりません', 404);
        }
        try {
            $pdo->prepare("UPDATE memos SET deleted_at = NOW() WHERE id = ?")->execute([$memo_id]);
        } catch (PDOException $e) {
            $pdo->prepare("DELETE FROM memos WHERE id = ?")->execute([$memo_id]);
        }
        successResponse([], 'メモを削除しました');
        break;

    case 'pin':
        $memo_id = (int)($input['memo_id'] ?? $input['task_id'] ?? 0);
        $is_pinned = !empty($input['is_pinned']) ? 1 : 0;
        if (!$memo_id) {
            errorResponse('メモIDが必要です');
        }

        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND created_by = ? AND type = 'memo'");
        $stmt->execute([$memo_id, $user_id]);
        if ($stmt->fetch()) {
            $pdo->prepare("UPDATE tasks SET is_pinned = ?, updated_at = NOW() WHERE id = ?")->execute([$is_pinned, $memo_id]);
        } else {
            $pdo->prepare("UPDATE memos SET is_pinned = ?, updated_at = NOW() WHERE id = ? AND created_by = ?")->execute([$is_pinned, $memo_id, $user_id]);
        }
        successResponse([]);
        break;

    case 'count':
        $hasType = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'");
            $hasType = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}

        if ($hasType) {
            $countSql = "SELECT COUNT(*) as count FROM tasks WHERE created_by = ? AND type = 'memo'";
            $hasDeleted = false;
            try {
                $chk2 = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
                $hasDeleted = $chk2 && $chk2->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasDeleted) $countSql .= " AND deleted_at IS NULL";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute([$user_id]);
        } else {
            $countSql = "SELECT COUNT(*) as count FROM memos WHERE created_by = ?";
            $hasDeleted = false;
            try {
                $chk2 = $pdo->query("SHOW COLUMNS FROM memos LIKE 'deleted_at'");
                $hasDeleted = $chk2 && $chk2->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasDeleted) $countSql .= " AND deleted_at IS NULL";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute([$user_id]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        successResponse(['count' => (int)$result['count']]);
        break;

    default:
        errorResponse('不明なアクションです');
}
