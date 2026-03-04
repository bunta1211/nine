<?php
/**
 * タスク管理API
 * タスク作成・依頼・更新・削除を処理
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

// プッシュ通知ヘルパーを安全に読み込み
$pushHelperPath = __DIR__ . '/../includes/push_helper.php';
if (file_exists($pushHelperPath)) {
    try {
        require_once $pushHelperPath;
    } catch (Exception $e) {
        error_log('Push helper load error: ' . $e->getMessage());
    }
}

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// deleted_at カラムの存在確認（論理削除対応、1リクエスト内でキャッシュ）
function tasksHasDeletedAtColumn(PDO $pdo): bool {
    static $cached = null;
    if ($cached === null) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
            $cached = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {
            $cached = false;
        }
    }
    return $cached;
}

function tasksHasTypeColumn(PDO $pdo): bool {
    static $cached = null;
    if ($cached === null) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'");
            $cached = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {
            $cached = false;
        }
    }
    return $cached;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // タスク一覧を取得
        $conversation_id = (int)($_GET['conversation_id'] ?? 0);
        $status = $_GET['status'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        $my_tasks_only = !empty($_GET['my_tasks_only']) || $_GET['my_tasks_only'] === '1';
        $type_filter = $_GET['type'] ?? '';
        
        // 会話指定時: その会話のメンバーなら全タスクを表示（グループ内の他メンバー作成タスクも含む）
        $isConvMember = false;
        if ($conversation_id) {
            $chk = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
            $chk->execute([$conversation_id, $user_id]);
            $isConvMember = (bool)$chk->fetch();
        }
        
        $sql = "
            SELECT 
                t.*,
                u.display_name as requester_name,
                u.id as requester_id,
                au.display_name as worker_name,
                au.id as worker_id
            FROM tasks t
            INNER JOIN users u ON t.created_by = u.id
            LEFT JOIN users au ON t.assigned_to = au.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($conversation_id && $isConvMember) {
            // 会話メンバー: その会話に紐づく全タスクを表示（グループ内の他メンバー作成タスクも含む）
            $sql .= " AND t.conversation_id = ?";
            $params[] = $conversation_id;
        } elseif ($conversation_id && !$isConvMember) {
            // 非メンバーで会話指定あり → 空を返す（アクセス権なし）
            $sql .= " AND 1=0";
        } elseif ($my_tasks_only) {
            // ホップアップ用: 自分のタスクのみ（自分が担当 or 自分で作成して担当者なし/自分）
            $sql .= " AND (t.assigned_to = ? OR (t.created_by = ? AND (t.assigned_to IS NULL OR t.assigned_to = ?)))";
            $params[] = $user_id;
            $params[] = $user_id;
            $params[] = $user_id;
        } else {
            // 会話指定なし: 自分のタスク＋依頼したタスク（作成者・担当者・共有）
            $sql .= " AND (t.created_by = ? OR t.assigned_to = ? OR t.is_shared = 1)";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        
        if (tasksHasDeletedAtColumn($pdo)) {
            $sql .= " AND t.deleted_at IS NULL";
        }
        
        if (tasksHasTypeColumn($pdo)) {
            if ($type_filter === 'memo') {
                $sql .= " AND t.type = 'memo'";
            } elseif ($type_filter === 'task' || $type_filter === '') {
                $sql .= " AND (t.type = 'task' OR t.type IS NULL)";
            }
        }
        
        if ($type_filter !== 'memo') {
            $sql .= " AND t.status != 'completed'";
            if ($status && in_array($status, ['pending', 'in_progress', 'cancelled'])) {
                $sql .= " AND t.status = ?";
                $params[] = $status;
            }
        }
        
        if ($type_filter === 'memo') {
            $sql .= " ORDER BY t.is_pinned DESC, t.updated_at DESC LIMIT ? OFFSET ?";
        } else {
            $sql .= " ORDER BY t.due_date ASC, t.priority DESC, t.created_at DESC LIMIT ? OFFSET ?";
        }
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
        // 各タスクに役割フラグを追加
        foreach ($tasks as &$task) {
            $task['is_requester'] = ((int)$task['created_by'] === $user_id) ? 1 : 0;
            $task['is_worker'] = ((int)$task['assigned_to'] === $user_id) ? 1 : 0;
            $task['created_by'] = (int)$task['created_by'];
            $task['assigned_to'] = $task['assigned_to'] ? (int)$task['assigned_to'] : null;
            $task['id'] = (int)$task['id'];
            $task['priority'] = (int)($task['priority'] ?? 0);
            $task['is_pinned'] = (int)($task['is_pinned'] ?? 0);
            $task['type'] = $task['type'] ?? 'task';
            // 旧カラム名との互換性
            $task['creator_name'] = $task['requester_name'];
            $task['assignee_name'] = $task['worker_name'];
        }
        unset($task);
        
        successResponse(['tasks' => $tasks]);
        break;
        
    case 'get':
        // タスク詳細を取得
        $task_id = (int)($_GET['id'] ?? 0);
        
        if (!$task_id) {
            errorResponse('タスクIDが必要です');
        }
        
        $getSql = "
            SELECT 
                t.*,
                COALESCE(t.source, 'manual') as source,
                m.conversation_id as message_conversation_id
            FROM tasks t
            LEFT JOIN messages m ON t.source_message_id = m.id
            WHERE t.id = ? AND (t.created_by = ? OR t.assigned_to = ?)
        ";
        if (tasksHasDeletedAtColumn($pdo)) {
            $getSql .= " AND t.deleted_at IS NULL";
        }
        $stmt = $pdo->prepare($getSql);
        $stmt->execute([$task_id, $user_id, $user_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$task) {
            errorResponse('タスクが見つかりません', 404);
        }
        
        // 数値型をキャスト
        $task['id'] = (int)$task['id'];
        $task['priority'] = isset($task['priority']) ? (int)$task['priority'] : 1;
        $task['source_message_id'] = $task['source_message_id'] ? (int)$task['source_message_id'] : null;
        $task['message_conversation_id'] = $task['message_conversation_id'] ? (int)$task['message_conversation_id'] : null;
        
        successResponse(['task' => $task]);
        break;
        
    case 'create':
        $item_type = $input['type'] ?? 'task';
        
        if ($item_type === 'memo') {
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
            
            $stmt = $pdo->prepare("
                INSERT INTO tasks (type, title, content, color, created_by, conversation_id, message_id, is_pinned, status, priority, created_at, updated_at)
                VALUES ('memo', ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())
            ");
            $stmt->execute([$title, $content, $color, $user_id, $conversation_id, $message_id, $is_pinned]);
            $memo_id = (int)$pdo->lastInsertId();
            
            successResponse(['memo_id' => $memo_id, 'task_id' => $memo_id], 'メモを作成しました');
            break;
        }
        
        // タスクを作成（assignee_ids: 複数人へ依頼可、チャットからのみ）
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $due_date = $input['due_date'] ?? null;
        $priority = (int)($input['priority'] ?? 0);
        $assigned_to = $input['assigned_to'] ?? null;
        $assignee_ids = $input['assignee_ids'] ?? null;
        $conversation_id = $input['conversation_id'] ?? null;
        $is_shared = $input['is_shared'] ?? false;
        $post_to_chat = $input['post_to_chat'] ?? true;
        
        // assignee_ids がある場合は配列に統一
        if (is_array($assignee_ids)) {
            $assignee_ids = array_filter(array_map('intval', $assignee_ids));
        } elseif ($assignee_ids !== null && $assignee_ids !== '') {
            $assignee_ids = [(int)$assignee_ids];
        } else {
            $assignee_ids = [];
        }
        if ($assigned_to !== null && $assigned_to !== '' && empty($assignee_ids)) {
            $assignee_ids = [(int)$assigned_to];
        }
        
        if (empty($title) && empty($description)) {
            errorResponse('タスク内容を入力してください');
        }
        
        if (empty($title)) {
            $title = mb_substr($description, 0, 100);
            if (mb_strlen($description) > 100) {
                $title .= '...';
            }
        }
        
        if (mb_strlen($title) > 200) {
            errorResponse('タイトルは200文字以内にしてください');
        }
        
        $stmtUser = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmtUser->execute([$user_id]);
        $requesterName = $stmtUser->fetchColumn() ?: 'ユーザー';
        
        // 担当者名一覧を取得
        $assigneeNames = [];
        foreach ($assignee_ids as $aid) {
            $stmtA = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmtA->execute([$aid]);
            $assigneeNames[$aid] = $stmtA->fetchColumn() ?: 'ユーザー';
        }
        $assigneeName = count($assigneeNames) === 1 ? reset($assigneeNames) : null;
        
        $hasConvCol = false;
        $hasNotifMsgCol = false;
        $hasTaskIdCol = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'conversation_id'");
            $hasConvCol = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'notification_message_id'");
            $hasNotifMsgCol = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'task_id'");
            $hasTaskIdCol = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        
        $convIdForInsert = ($conversation_id !== null && $conversation_id !== '') ? (int)$conversation_id : null;
        
        // 複数人依頼時は1人に1タスク作成
        $targetAssignees = !empty($assignee_ids) ? $assignee_ids : [null];
        $createdTaskIds = [];
        
        try {
            foreach ($targetAssignees as $singleAssignee) {
                if ($hasConvCol && $convIdForInsert) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tasks (title, description, due_date, priority, status, created_by, assigned_to, conversation_id, created_at)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$title, $description, $due_date, max(0, min(3, $priority)), $user_id, $singleAssignee, $convIdForInsert]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO tasks (title, description, due_date, priority, status, created_by, assigned_to, created_at)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                    ");
                    $stmt->execute([$title, $description, $due_date, max(0, min(3, $priority)), $user_id, $singleAssignee]);
                }
                $createdTaskIds[] = (int)$pdo->lastInsertId();
                
                if ($singleAssignee && $singleAssignee != $user_id) {
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id, type, title, content, related_type, related_id) VALUES (?, 'system', ?, ?, 'task', ?)")
                            ->execute([$singleAssignee, 'タスクが依頼されました', $requesterName . 'さんからタスク「' . mb_substr($title, 0, 30) . '」が依頼されました', $pdo->lastInsertId()]);
                        if (function_exists('sendPushToUser')) {
                            sendPushToUser($pdo, (int)$singleAssignee, [
                                'title' => '📋 タスクが依頼されました',
                                'body' => $requesterName . 'さんから「' . mb_substr($title, 0, 30) . '」',
                                'icon' => '/assets/icons/icon-192x192.png',
                                'tag' => 'task-' . $pdo->lastInsertId(),
                                'data' => ['type' => 'task_assigned', 'task_id' => (int)$pdo->lastInsertId(), 'url' => '/tasks.php']
                            ]);
                        }
                    } catch (Exception $e) {
                        error_log('Task notification error: ' . $e->getMessage());
                    }
                }
            }
            
            $task_id = $createdTaskIds[0];
            
            if ($post_to_chat && $convIdForInsert) {
                $workerNamesStr = !empty($assigneeNames) ? implode('、', $assigneeNames) : '（未定）';
                $taskMsgContent = "📋 **タスク依頼**\n**依頼者**: {$requesterName}\n**担当者**: {$workerNamesStr}\n**内容**: {$title}";
                if ($due_date) {
                    $taskMsgContent .= "\n**期限**: " . date('Y年m月d日', strtotime($due_date));
                }
                
                if ($hasTaskIdCol) {
                    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content, message_type, task_id, created_at) VALUES (?, ?, ?, 'system', ?, NOW())")
                        ->execute([$convIdForInsert, $user_id, $taskMsgContent, $task_id]);
                } else {
                    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at) VALUES (?, ?, ?, 'system', NOW())")
                        ->execute([$convIdForInsert, $user_id, $taskMsgContent]);
                }
                $notification_message_id = (int)$pdo->lastInsertId();
                
                if ($hasNotifMsgCol && $notification_message_id) {
                    $pdo->prepare("UPDATE tasks SET notification_message_id = ? WHERE id = ?")->execute([$notification_message_id, $task_id]);
                }
                
                $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$convIdForInsert]);
            } else {
                $notification_message_id = null;
            }
            
        } catch (PDOException $e) {
            errorResponse('データベースエラー: ' . $e->getMessage());
        }
        
        successResponse([
            'task_id' => $task_id,
            'task_ids' => $createdTaskIds,
            'notification_message_id' => $notification_message_id ?? null,
            'requester_name' => $requesterName,
            'assignee_name' => $assigneeName,
            'assignee_names' => $assigneeNames
        ], count($createdTaskIds) > 1 ? count($createdTaskIds) . '件のタスクを作成しました' : 'タスクを作成しました');
        break;
        
    case 'update':
        // タスクを更新
        $task_id = (int)($input['task_id'] ?? 0);
        
        if (!$task_id) {
            errorResponse('タスクIDが必要です');
        }
        
        // 権限確認
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND (created_by = ? OR assigned_to = ?)");
        $stmt->execute([$task_id, $user_id, $user_id]);
        $task = $stmt->fetch();
        
        if (!$task) {
            errorResponse('タスクが見つかりません', 404);
        }
        
        $updates = [];
        $params = [];
        
        if (isset($input['title'])) {
            $updates[] = 'title = ?';
            $params[] = trim($input['title']);
        }
        if (isset($input['description'])) {
            $desc = trim($input['description']);
            $updates[] = 'description = ?';
            $params[] = $desc;
            // タイトルも同時に更新（descriptionからタイトルを生成）
            if (!isset($input['title']) && !empty($desc)) {
                $updates[] = 'title = ?';
                $newTitle = mb_substr($desc, 0, 100);
                if (mb_strlen($desc) > 100) {
                    $newTitle .= '...';
                }
                $params[] = $newTitle;
            }
        }
        if (isset($input['due_date'])) {
            $updates[] = 'due_date = ?';
            $params[] = $input['due_date'];
        }
        if (isset($input['priority'])) {
            $updates[] = 'priority = ?';
            $params[] = max(0, min(3, (int)$input['priority']));
        }
        if (isset($input['status'])) {
            $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
            if (in_array($input['status'], $valid_statuses)) {
                $updates[] = 'status = ?';
                $params[] = $input['status'];
                
                if ($input['status'] === 'completed') {
                    $updates[] = 'completed_at = NOW()';
                }
            }
        }
        if (isset($input['assigned_to'])) {
            $updates[] = 'assigned_to = ?';
            $params[] = $input['assigned_to'];
        }
        if (isset($input['conversation_id'])) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'conversation_id'");
                if ($chk && $chk->rowCount() > 0) {
                    $updates[] = 'conversation_id = ?';
                    $params[] = $input['conversation_id'];
                }
            } catch (Exception $e) {}
        }
        if (isset($input['content'])) {
            $updates[] = 'content = ?';
            $params[] = trim($input['content']);
        }
        if (isset($input['color'])) {
            $updates[] = 'color = ?';
            $params[] = $input['color'];
        }
        if (isset($input['is_pinned'])) {
            $updates[] = 'is_pinned = ?';
            $params[] = !empty($input['is_pinned']) ? 1 : 0;
        }
        
        if (empty($updates)) {
            errorResponse('更新する項目がありません');
        }
        
        $updates[] = 'updated_at = NOW()';
        $params[] = $task_id;
        
        $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
        
        $iseMemo = ($task['type'] ?? 'task') === 'memo';
        successResponse([], $iseMemo ? 'メモを更新しました' : 'タスクを更新しました');
        break;
        
    case 'toggle':
        // タスク完了状態を切り替え
        try {
            $task_id = (int)($input['task_id'] ?? 0);
            
            if (!$task_id) {
                errorResponse('タスクIDが必要です');
            }
            
            // 権限確認（conversation_idも取得）
            $toggleSql = "SELECT status, created_by, assigned_to, title";
            // conversation_idカラムの存在確認
            $hasConvCol = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'conversation_id'");
                $hasConvCol = $chk && $chk->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasConvCol) {
                $toggleSql .= ", conversation_id";
            }
            $toggleSql .= " FROM tasks WHERE id = ? AND (created_by = ? OR assigned_to = ?)";
            
            $stmt = $pdo->prepare($toggleSql);
            $stmt->execute([$task_id, $user_id, $user_id]);
            $task = $stmt->fetch();
            
            if (!$task) {
                errorResponse('タスクが見つかりません', 404);
            }
            
            // 依頼したタスクは担当者のみ完了／未完了にできる
            $assigned_to_toggle = $task['assigned_to'] ? (int)$task['assigned_to'] : null;
            $created_by_toggle = (int)$task['created_by'];
            $can_toggle = ($assigned_to_toggle === $user_id) || ($created_by_toggle === $user_id && ($assigned_to_toggle === null || $assigned_to_toggle === $user_id));
            if (!$can_toggle) {
                errorResponse('このタスクは担当者が完了する必要があります。依頼したタスクは相手が完了した時点で完了になります。');
            }
            
            $new_status = $task['status'] === 'completed' ? 'pending' : 'completed';
            $pdo->prepare("
                UPDATE tasks SET status = ?, completed_at = ?
                WHERE id = ?
            ")->execute([
                $new_status,
                $new_status === 'completed' ? date('Y-m-d H:i:s') : null,
                $task_id
            ]);
            
            // チャットにステータス変更を投稿
            $conversation_id = $hasConvCol ? ($task['conversation_id'] ?? null) : null;
            if ($conversation_id && $new_status === 'completed') {
                // 完了者の名前を取得
                $stmtUser = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
                $stmtUser->execute([$user_id]);
                $completerName = $stmtUser->fetchColumn() ?: 'ユーザー';
                
                $statusMsgContent = "✅ **タスク完了**\n";
                $statusMsgContent .= "**完了者**: {$completerName}\n";
                $statusMsgContent .= "**内容**: " . mb_substr($task['title'], 0, 50);
                
                // task_idカラムの存在確認
                $hasTaskIdCol = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'task_id'");
                    $hasTaskIdCol = $chk && $chk->rowCount() > 0;
                } catch (Exception $e) {}
                
                if ($hasTaskIdCol) {
                    $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, message_type, task_id, created_at)
                        VALUES (?, ?, ?, 'system', ?, NOW())
                    ")->execute([$conversation_id, $user_id, $statusMsgContent, $task_id]);
                } else {
                    $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
                        VALUES (?, ?, ?, 'system', NOW())
                    ")->execute([$conversation_id, $user_id, $statusMsgContent]);
                }
                
                // 会話の更新日時を更新
                $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")
                    ->execute([$conversation_id]);
            }
            
            // 完了時に依頼者へ通知（担当者が完了した場合）
            $notified = false;
            if ($new_status === 'completed' && 
                (int)$task['assigned_to'] === $user_id && 
                (int)$task['created_by'] !== $user_id) {
                
                $stmtUser = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
                $stmtUser->execute([$user_id]);
                $assigneeName = $stmtUser->fetchColumn() ?: 'ユーザー';
                
                $notificationTitle = '✅ タスクが完了しました';
                $notificationContent = $assigneeName . 'さんがタスク「' . mb_substr($task['title'], 0, 30) . '」を完了しました';
                
                // 通知を作成
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                        VALUES (?, 'system', ?, ?, 'task', ?)
                    ")->execute([
                        (int)$task['created_by'],
                        $notificationTitle,
                        $notificationContent,
                        $task_id
                    ]);
                } catch (PDOException $e) {
                    error_log('Toggle notification error: ' . $e->getMessage());
                }
                
                // プッシュ通知を送信
                try {
                    if (function_exists('sendPushToUser')) {
                        sendPushToUser($pdo, (int)$task['created_by'], [
                            'title' => $notificationTitle,
                            'body' => $notificationContent,
                            'icon' => '/assets/icons/icon-192x192.png',
                            'badge' => '/assets/icons/icon-72x72.png',
                            'tag' => 'task-' . $task_id,
                            'renotify' => true,
                            'data' => [
                                'type' => 'task_completed',
                                'task_id' => $task_id,
                                'url' => '/tasks.php'
                            ]
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('Toggle push error: ' . $e->getMessage());
                }
                
                $notified = true;
            }
        
            successResponse(['status' => $new_status, 'notified' => $notified]);
        } catch (Exception $e) {
            error_log('Toggle task error: ' . $e->getMessage());
            errorResponse('エラーが発生しました: ' . $e->getMessage());
        }
        break;
        
    case 'complete':
        // タスクを完了にする（明示的な完了ボタン用）
        try {
            $task_id = (int)($input['task_id'] ?? 0);
            
            if (!$task_id) {
                errorResponse('タスクIDが必要です');
            }
            
            // 権限確認（conversation_idも取得）
            $completeSql = "SELECT status, created_by, assigned_to, title";
            $hasConvColComplete = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'conversation_id'");
                $hasConvColComplete = $chk && $chk->rowCount() > 0;
            } catch (Exception $e) {}
            if ($hasConvColComplete) {
                $completeSql .= ", conversation_id";
            }
            $completeSql .= " FROM tasks WHERE id = ? AND (created_by = ? OR assigned_to = ?)";
            
            $stmt = $pdo->prepare($completeSql);
            $stmt->execute([$task_id, $user_id, $user_id]);
            $task = $stmt->fetch();
            
            if (!$task) {
                errorResponse('タスクが見つかりません', 404);
            }
            
            if ($task['status'] === 'completed') {
                errorResponse('既に完了しています');
            }
            
            // 依頼したタスクは担当者が完了するまで完了にできない（自分が担当のときだけ完了可能）
            $assigned_to = $task['assigned_to'] ? (int)$task['assigned_to'] : null;
            $created_by = (int)$task['created_by'];
            $can_complete = ($assigned_to === $user_id) || ($created_by === $user_id && ($assigned_to === null || $assigned_to === $user_id));
            if (!$can_complete) {
                errorResponse('このタスクは担当者が完了する必要があります。依頼したタスクは相手が完了した時点で完了になります。');
            }
            
            $pdo->prepare("
                UPDATE tasks SET status = 'completed', completed_at = NOW()
                WHERE id = ?
            ")->execute([$task_id]);
            
            // チャットに完了メッセージを投稿
            $conversation_id_complete = $hasConvColComplete ? ($task['conversation_id'] ?? null) : null;
            if ($conversation_id_complete) {
                $stmtUser = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
                $stmtUser->execute([$user_id]);
                $completerName = $stmtUser->fetchColumn() ?: 'ユーザー';
                
                $statusMsgContent = "✅ **タスク完了**\n";
                $statusMsgContent .= "**完了者**: {$completerName}\n";
                $statusMsgContent .= "**内容**: " . mb_substr($task['title'], 0, 50);
                
                $hasTaskIdColComplete = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'task_id'");
                    $hasTaskIdColComplete = $chk && $chk->rowCount() > 0;
                } catch (Exception $e) {}
                
                if ($hasTaskIdColComplete) {
                    $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, message_type, task_id, created_at)
                        VALUES (?, ?, ?, 'system', ?, NOW())
                    ")->execute([$conversation_id_complete, $user_id, $statusMsgContent, $task_id]);
                } else {
                    $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
                        VALUES (?, ?, ?, 'system', NOW())
                    ")->execute([$conversation_id_complete, $user_id, $statusMsgContent]);
                }
                
                $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")
                    ->execute([$conversation_id_complete]);
            }
            
            // 依頼者へ通知（自分以外が依頼者の場合）
            $notified = false;
            if ((int)$task['created_by'] !== $user_id) {
                $stmtUser = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
                $stmtUser->execute([$user_id]);
                $assigneeName = $stmtUser->fetchColumn() ?: 'ユーザー';
                
                $notificationTitle = '✅ タスクが完了しました';
                $notificationContent = $assigneeName . 'さんがタスク「' . mb_substr($task['title'], 0, 30) . '」を完了しました';
                
                // データベース通知を作成
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                        VALUES (?, 'system', ?, ?, 'task', ?)
                    ")->execute([
                        (int)$task['created_by'],
                        $notificationTitle,
                        $notificationContent,
                        $task_id
                    ]);
                } catch (PDOException $e) {
                    // 通知エラーは無視（タスク完了は成功）
                    error_log('Task notification error: ' . $e->getMessage());
                }
                
                // プッシュ通知を送信
                try {
                    if (function_exists('sendPushToUser')) {
                        sendPushToUser($pdo, (int)$task['created_by'], [
                            'title' => $notificationTitle,
                            'body' => $notificationContent,
                            'icon' => '/assets/icons/icon-192x192.png',
                            'badge' => '/assets/icons/icon-72x72.png',
                            'tag' => 'task-' . $task_id,
                            'renotify' => true,
                            'data' => [
                                'type' => 'task_completed',
                                'task_id' => $task_id,
                                'url' => '/tasks.php'
                            ]
                        ]);
                    }
                } catch (Exception $e) {
                    // プッシュ通知エラーは無視
                    error_log('Push notification error: ' . $e->getMessage());
                }
                
                $notified = true;
            }
            
            successResponse(['status' => 'completed', 'notified' => $notified], 'タスクを完了しました');
        } catch (PDOException $e) {
            error_log('Task complete error: ' . $e->getMessage());
            errorResponse('タスクの完了に失敗しました: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('Task complete error: ' . $e->getMessage());
            errorResponse('エラーが発生しました: ' . $e->getMessage());
        }
        break;
        
    case 'reopen':
        // タスクを未完了に戻す（完了と同様、担当者のみ実行可能）
        try {
            $task_id = (int)($input['task_id'] ?? 0);
            
            if (!$task_id) {
                errorResponse('タスクIDが必要です');
            }
            
            $stmt = $pdo->prepare("SELECT status, created_by, assigned_to FROM tasks WHERE id = ? AND (created_by = ? OR assigned_to = ?)");
            $stmt->execute([$task_id, $user_id, $user_id]);
            $task = $stmt->fetch();
            
            if (!$task) {
                errorResponse('タスクが見つかりません', 404);
            }
            
            $assigned_to_reopen = $task['assigned_to'] ? (int)$task['assigned_to'] : null;
            $created_by_reopen = (int)$task['created_by'];
            $can_reopen = ($assigned_to_reopen === $user_id) || ($created_by_reopen === $user_id && ($assigned_to_reopen === null || $assigned_to_reopen === $user_id));
            if (!$can_reopen) {
                errorResponse('このタスクの未完了への戻しは担当者のみ行えます。');
            }
            
            $pdo->prepare("
                UPDATE tasks SET status = 'pending', completed_at = NULL
                WHERE id = ?
            ")->execute([$task_id]);
            
            successResponse(['status' => 'pending'], 'タスクを未完了に戻しました');
        } catch (Exception $e) {
            error_log('Task reopen error: ' . $e->getMessage());
            errorResponse('エラーが発生しました: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        // タスクを削除（論理削除: 秘書の検索では削除後も記憶として参照可能）
        $task_id = (int)($input['task_id'] ?? 0);
        
        if (!$task_id) {
            errorResponse('タスクIDが必要です');
        }
        
        // 依頼者（作成者）または担当者のみ削除可能
        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND (created_by = ? OR assigned_to = ?)");
        $stmt->execute([$task_id, $user_id, $user_id]);
        if (!$stmt->fetch()) {
            errorResponse('このタスクを削除する権限がありません', 403);
        }
        
        try {
            $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = ?")->execute([$task_id]);
        } catch (PDOException $e) {
            // deleted_at カラムがなければ物理削除（マイグレーション未実行時）
            $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$task_id]);
        }
        
        successResponse([], 'タスクを削除しました');
        break;
    
    case 'pin':
        $task_id = (int)($input['task_id'] ?? 0);
        $is_pinned = !empty($input['is_pinned']) ? 1 : 0;
        
        if (!$task_id) {
            errorResponse('IDが必要です');
        }
        
        $stmt = $pdo->prepare("SELECT id, type FROM tasks WHERE id = ? AND created_by = ?");
        $stmt->execute([$task_id, $user_id]);
        $pinTask = $stmt->fetch();
        if (!$pinTask) {
            errorResponse('見つかりません', 404);
        }
        
        $pdo->prepare("UPDATE tasks SET is_pinned = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$is_pinned, $task_id]);
        
        successResponse([]);
        break;
    
    case 'count':
        $my_tasks_only_count = !empty($_GET['my_tasks_only']) || ($_GET['my_tasks_only'] ?? '') === '1';
        $count_type = $_GET['type'] ?? '';
        
        $countSql = "SELECT COUNT(*) as count FROM tasks WHERE 1=1";
        $countParams = [];
        
        if ($count_type === 'memo') {
            $countSql .= " AND created_by = ?";
            $countParams[] = $user_id;
        } elseif ($my_tasks_only_count) {
            $countSql .= " AND (assigned_to = ? OR (created_by = ? AND (assigned_to IS NULL OR assigned_to = ?)))";
            $countParams = [$user_id, $user_id, $user_id];
        } else {
            $countSql .= " AND (created_by = ? OR assigned_to = ?)";
            $countParams = [$user_id, $user_id];
        }
        
        if (tasksHasTypeColumn($pdo)) {
            if ($count_type === 'memo') {
                $countSql .= " AND type = 'memo'";
            } elseif ($count_type === 'task' || $count_type === '') {
                $countSql .= " AND (type = 'task' OR type IS NULL)";
                $countSql .= " AND status IN ('pending', 'in_progress')";
            }
        } else {
            $countSql .= " AND status IN ('pending', 'in_progress')";
        }
        
        if (tasksHasDeletedAtColumn($pdo)) {
            $countSql .= " AND deleted_at IS NULL";
        }
        
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        successResponse(['count' => (int)$result['count']]);
        break;
        
    default:
        errorResponse('不明なアクションです');
}

