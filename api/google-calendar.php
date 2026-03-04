<?php
/**
 * Googleカレンダー API
 * アカウント管理・イベント作成
 */
if (!ob_get_level()) {
    ob_start();
}
define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_calendar.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/google_calendar_helper.php';

@ini_set('display_errors', '0');
$jsonOut = function ($data) {
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isLoggedIn()) {
    http_response_code(401);
    $jsonOut(['success' => false, 'message' => 'ログインが必要です']);
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        try {
            $stmt = $pdo->prepare("
                SELECT id, display_name, google_email, is_default, created_at
                FROM google_calendar_accounts
                WHERE user_id = ?
                ORDER BY is_default DESC, display_name ASC
            ");
            $stmt->execute([$user_id]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($accounts as &$a) {
                $a['id'] = (int)$a['id'];
                $a['is_default'] = (int)$a['is_default'];
            }
            $jsonOut(['success' => true, 'accounts' => $accounts]);
        } catch (Exception $e) {
            error_log('Google Calendar list error: ' . $e->getMessage());
            $jsonOut(['success' => false, 'message' => '取得に失敗しました']);
        }
        break;

    case 'update_name':
        $id = (int)($input['id'] ?? 0);
        $display_name = trim($input['display_name'] ?? '');
        if (!$id || empty($display_name)) {
            $jsonOut(['success' => false, 'message' => 'パラメータが不足しています']);
        }
        if (mb_strlen($display_name) > 50) {
            $jsonOut(['success' => false, 'message' => '名前は50文字以内で入力してください']);
        }
        try {
            $stmt = $pdo->prepare("UPDATE google_calendar_accounts SET display_name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$display_name, $id, $user_id]);
            if ($stmt->rowCount()) {
                $jsonOut(['success' => true, 'message' => '名前を更新しました']);
            } else {
                $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません']);
            }
        } catch (Exception $e) {
            error_log('Google Calendar update_name error: ' . $e->getMessage());
            $jsonOut(['success' => false, 'message' => '更新に失敗しました']);
        }
        break;

    case 'set_default':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            $jsonOut(['success' => false, 'message' => 'パラメータが不足しています']);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE google_calendar_accounts SET is_default = 0 WHERE user_id = ?")->execute([$user_id]);
            $stmt = $pdo->prepare("UPDATE google_calendar_accounts SET is_default = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            $pdo->commit();
            if ($stmt->rowCount()) {
                $jsonOut(['success' => true, 'message' => 'デフォルトに設定しました']);
            } else {
                $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません']);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Google Calendar set_default error: ' . $e->getMessage());
            $jsonOut(['success' => false, 'message' => '設定に失敗しました']);
        }
        break;

    case 'disconnect':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            $jsonOut(['success' => false, 'message' => 'パラメータが不足しています']);
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM google_calendar_accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            if ($stmt->rowCount()) {
                $stmt2 = $pdo->prepare("SELECT id FROM google_calendar_accounts WHERE user_id = ? AND is_default = 1 LIMIT 1");
                $stmt2->execute([$user_id]);
                if (!$stmt2->fetch()) {
                    $stmt3 = $pdo->prepare("SELECT id FROM google_calendar_accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                    $stmt3->execute([$user_id]);
                    $first = $stmt3->fetch();
                    if ($first) {
                        $pdo->prepare("UPDATE google_calendar_accounts SET is_default = 1 WHERE id = ?")->execute([$first['id']]);
                    }
                }
                $jsonOut(['success' => true, 'message' => '連携を解除しました']);
            } else {
                $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません']);
            }
        } catch (Exception $e) {
            error_log('Google Calendar disconnect error: ' . $e->getMessage());
            $jsonOut(['success' => false, 'message' => '解除に失敗しました']);
        }
        break;

    case 'create_event':
        try {
            if (!isGoogleCalendarEnabled() || !isGoogleCalendarClientAvailable()) {
                $jsonOut(['success' => false, 'message' => 'Googleカレンダー連携が設定されていません']);
            }
            $calendar_target = trim($input['calendar_target'] ?? 'default');
            $start_datetime = trim($input['start_datetime'] ?? '');
            $end_datetime = trim($input['end_datetime'] ?? '');
            $title = trim($input['title'] ?? '');
            $description = trim($input['description'] ?? '');

            if (empty($title)) {
                $jsonOut(['success' => false, 'message' => 'タイトルを入力してください']);
            }
            if (empty($start_datetime) || empty($end_datetime)) {
                $jsonOut(['success' => false, 'message' => '開始・終了日時を指定してください']);
            }

            $account = getCalendarAccountByTarget($pdo, $user_id, $calendar_target);
            if (!$account) {
                $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません。設定画面で連携してください。']);
            }

            $startIso = normalizeDateTimeForCalendar($start_datetime);
            $endIso = normalizeDateTimeForCalendar($end_datetime);

            $result = createCalendarEvent($pdo, $account, $startIso, $endIso, $title, $description);
            if ($result['success']) {
                $jsonOut([
                    'success' => true,
                    'message' => 'カレンダーに追加しました',
                    'event_id' => $result['event_id'],
                ]);
            } else {
                $errorMsg = $result['error'] ?? '追加に失敗しました';
                error_log("Google Calendar create_event failed for user {$user_id}: {$errorMsg}");
                $jsonOut([
                    'success' => false,
                    'message' => $errorMsg,
                    'error_detail' => $errorMsg,
                ]);
            }
        } catch (Throwable $e) {
            $errMsg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log('Google Calendar create_event error: ' . $errMsg);
            $payload = [
                'success' => false,
                'message' => '予定の追加に失敗しました。しばらくしてから再度お試しください。',
                'error_detail' => $e->getMessage(), // ErrorCollectorでサーバー側の原因を収集
            ];
            $jsonOut($payload);
        }
        break;

    case 'update_event':
        if (!isGoogleCalendarEnabled() || !isGoogleCalendarClientAvailable()) {
            $jsonOut(['success' => false, 'message' => 'Googleカレンダー連携が設定されていません']);
        }
        $calendar_target = trim($input['calendar_target'] ?? 'default');
        $event_date = trim($input['event_date'] ?? '');
        $old_title = trim($input['old_title'] ?? '');
        $new_start = trim($input['new_start'] ?? '');
        $new_end = trim($input['new_end'] ?? '');
        $new_title = trim($input['new_title'] ?? $old_title);

        if (empty($event_date) || empty($old_title) || empty($new_start) || empty($new_end)) {
            $jsonOut(['success' => false, 'message' => 'パラメータが不足しています']);
        }

        $account = getCalendarAccountByTarget($pdo, $user_id, $calendar_target);
        if (!$account) {
            $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません']);
        }

        $eventId = findCalendarEventByDateAndTitle($pdo, $account, $event_date, $old_title);
        if (!$eventId) {
            $jsonOut(['success' => false, 'message' => '該当する予定が見つかりませんでした']);
        }

        $startIso = normalizeDateTimeForCalendar($new_start);
        $endIso = normalizeDateTimeForCalendar($new_end);
        $result = updateCalendarEvent($pdo, $account, $eventId, $startIso, $endIso, $new_title, '');
        if ($result['success']) {
            $jsonOut(['success' => true, 'message' => '予定を更新しました']);
        } else {
            $errorMsg = $result['error'] ?? '更新に失敗しました';
            error_log("Google Calendar update_event failed for user {$user_id}: {$errorMsg}");
            $jsonOut(['success' => false, 'message' => $errorMsg, 'error_detail' => $errorMsg]);
        }
        break;

    case 'delete_event':
        if (!isGoogleCalendarEnabled() || !isGoogleCalendarClientAvailable()) {
            $jsonOut(['success' => false, 'message' => 'Googleカレンダー連携が設定されていません']);
        }
        $calendar_target = trim($input['calendar_target'] ?? 'default');
        $event_date = trim($input['event_date'] ?? '');
        $title = trim($input['title'] ?? '');

        if (empty($event_date) || empty($title)) {
            $jsonOut(['success' => false, 'message' => '日付とタイトルを指定してください']);
        }

        $account = getCalendarAccountByTarget($pdo, $user_id, $calendar_target);
        if (!$account) {
            $jsonOut(['success' => false, 'message' => 'カレンダーが見つかりません']);
        }

        $eventId = findCalendarEventByDateAndTitle($pdo, $account, $event_date, $title);
        if (!$eventId) {
            $jsonOut(['success' => false, 'message' => '該当する予定が見つかりませんでした']);
        }

        $result = deleteCalendarEvent($pdo, $account, $eventId);
        if ($result['success']) {
            $jsonOut(['success' => true, 'message' => '予定を削除しました']);
        } else {
            $errorMsg = $result['error'] ?? '削除に失敗しました';
            error_log("Google Calendar delete_event failed for user {$user_id}: {$errorMsg}");
            $jsonOut(['success' => false, 'message' => $errorMsg, 'error_detail' => $errorMsg]);
        }
        break;

    default:
        $jsonOut(['success' => false, 'message' => '不明なアクションです']);
}
