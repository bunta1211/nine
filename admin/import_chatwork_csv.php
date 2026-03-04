<?php
/**
 * Chatwork CSV ログ インポートスクリプト
 * 
 * エンタープライズプランのCSVエクスポート、または手動で作成したCSVをインポートします。
 * 100件を超える履歴を一括で取り込む場合に使用してください。
 * 
 * 使い方:
 *   CLI: php admin/import_chatwork_csv.php --file=chatwork_export.csv --conversation_id=130 --room_filter=事務局
 *   Web: admin/import_chatwork_csv.php でフォームからアップロード
 * 
 * CSV形式（いずれかのカラム名に対応）:
 *   - ルーム: room_name, room_id, ルーム名
 *   - 送信者: sender_name, account_name, name, 送信者
 *   - 本文: body, content, message, 本文
 *   - 日時: send_time, created_at, timestamp, 日時
 *   - メッセージID（重複チェック用）: message_id, id
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    requireOrgAdmin();
    header('Content-Type: text/html; charset=UTF-8');
}

$pdo = getDB();

// パラメータ取得
$csvPath = null;
$conversationId = $isCli ? 0 : (int)($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
$roomFilter = $isCli ? '' : trim($_GET['room_filter'] ?? $_POST['room_filter'] ?? '');

if ($isCli) {
    foreach ($_SERVER['argv'] as $arg) {
        if (preg_match('/^--file=(.+)$/', $arg, $m)) $csvPath = trim($m[1], '"\'');
        if (preg_match('/^--conversation_id=(\d+)$/', $arg, $m)) $conversationId = (int)$m[1];
        if (preg_match('/^--room_filter=(.+)$/', $arg, $m)) $roomFilter = trim($m[1], '"\'');
    }
}

function output($msg, $isCli) {
    if ($isCli) echo $msg . "\n";
    else echo htmlspecialchars($msg) . "<br>\n";
}

// Web: ファイルアップロード
if (!$isCli && !empty($_FILES['csv_file']['tmp_name'])) {
    $csvPath = $_FILES['csv_file']['tmp_name'];
    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $roomFilter = trim($_POST['room_filter'] ?? '');
}

if (empty($csvPath) || !file_exists($csvPath) || !$conversationId) {
    output('Usage: --file=chatwork_export.csv --conversation_id=130 [--room_filter=事務局]', $isCli);
    if (!$isCli) {
        echo '<p>またはフォームからCSVをアップロードしてください。</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo 'CSVファイル: <input type="file" name="csv_file" accept=".csv" required><br>';
        echo 'Social9 会話ID: <input type="number" name="conversation_id" value="130" required><br>';
        echo 'ルーム名フィルタ（空=全件）: <input type="text" name="room_filter" placeholder="事務局 Office"><br>';
        echo '<button type="submit">インポート実行</button></form>';
    }
    exit(1);
}

// 会話の存在確認
$stmt = $pdo->prepare("SELECT id, name FROM conversations WHERE id = ?");
$stmt->execute([$conversationId]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$conv) {
    output("エラー: 会話ID {$conversationId} が見つかりません。", $isCli);
    exit(1);
}

// ユーザーマッピング
$stmt = $pdo->query("SELECT id, display_name FROM users");
$userMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = trim(preg_replace('/\s*\([^)]*\)/', '', $row['display_name']));
    $userMap[strtolower($name)] = $row['id'];
    $userMap[strtolower($row['display_name'])] = $row['id'];
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('system_admin','org_admin','admin') OR display_name = 'Ken' LIMIT 1");
$stmt->execute();
$fallbackUserId = $stmt->fetchColumn() ?: 1;

// カラムの有無確認
$hasExternalId = (bool)$pdo->query("SHOW COLUMNS FROM messages LIKE 'external_id'")->rowCount();
$hasSource = (bool)$pdo->query("SHOW COLUMNS FROM messages LIKE 'source'")->rowCount();
$msgTypeCol = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'")->rowCount() > 0 ? 'message_type' : ($pdo->query("SHOW COLUMNS FROM messages LIKE 'content_type'")->rowCount() > 0 ? 'content_type' : null);

// CSV読み込み
$handle = fopen($csvPath, 'r');
if (!$handle) {
    output("エラー: CSVファイルを開けません。", $isCli);
    exit(1);
}
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

$header = fgetcsv($handle);
$header = array_map('trim', $header);

// カラム名の正規化マッピング
$colMap = [
    'room' => null,
    'sender' => null,
    'body' => null,
    'time' => null,
    'id' => null,
];
$roomAliases = ['room_name', 'room_id', 'ルーム名', 'room', 'group'];
$senderAliases = ['sender_name', 'account_name', 'name', '送信者', 'account', 'display_name'];
$bodyAliases = ['body', 'content', 'message', '本文', 'メッセージ'];
$timeAliases = ['send_time', 'created_at', 'timestamp', '日時', '送信日時', 'time', 'date'];
$idAliases = ['message_id', 'id', 'メッセージID'];

foreach ($header as $i => $h) {
    $hl = strtolower($h);
    if ($colMap['room'] === null && (in_array($hl, array_map('strtolower', $roomAliases)) || strpos($hl, 'room') !== false)) $colMap['room'] = $i;
    if ($colMap['sender'] === null && (in_array($hl, array_map('strtolower', $senderAliases)) || strpos($hl, 'sender') !== false || strpos($hl, 'name') !== false)) $colMap['sender'] = $i;
    if ($colMap['body'] === null && (in_array($hl, array_map('strtolower', $bodyAliases)) || strpos($hl, 'body') !== false || strpos($hl, 'content') !== false || strpos($hl, 'message') !== false)) $colMap['body'] = $i;
    if ($colMap['time'] === null && (in_array($hl, array_map('strtolower', $timeAliases)) || strpos($hl, 'time') !== false || strpos($hl, 'date') !== false)) $colMap['time'] = $i;
    if ($colMap['id'] === null && (in_array($hl, array_map('strtolower', $idAliases)) || ($hl === 'id' && $colMap['body'] !== $i))) $colMap['id'] = $i;
}

if ($colMap['body'] === null) {
    output("エラー: CSVに本文カラム（body, content, message 等）が見つかりません。ヘッダー: " . implode(', ', $header), $isCli);
    exit(1);
}

output("CSVを読み込み中...", $isCli);

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < count($header)) continue;
    $roomName = $colMap['room'] !== null ? trim($row[$colMap['room']] ?? '') : '';
    if (!empty($roomFilter) && stripos($roomName, $roomFilter) === false) continue;
    $rows[] = $row;
}
fclose($handle);

output("該当 " . count($rows) . " 件をインポートします。", $isCli);

$inserted = 0;
$skipped = 0;

foreach ($rows as $row) {
    $body = trim($row[$colMap['body']] ?? '');
    if (empty($body)) continue;

    $senderName = $colMap['sender'] !== null ? trim($row[$colMap['sender']] ?? '') : '';
    $extId = $colMap['id'] !== null ? trim($row[$colMap['id']] ?? '') : '';

    if ($hasExternalId && $extId) {
        $stmt = $pdo->prepare("SELECT id FROM messages WHERE conversation_id = ? AND external_id = ?");
        $stmt->execute([$conversationId, $extId]);
        if ($stmt->fetch()) { $skipped++; continue; }
    }

    $senderId = $fallbackUserId;
    if (!empty($senderName)) {
        $key = strtolower(preg_replace('/\s*\([^)]*\)/', '', $senderName));
        if (isset($userMap[$key])) $senderId = $userMap[$key];
    }

    $timeStr = $colMap['time'] !== null ? trim($row[$colMap['time']] ?? '') : date('Y-m-d H:i:s');
    $createdAt = $timeStr;
    if (preg_match('/^\d{10}$/', $timeStr)) {
        $createdAt = date('Y-m-d H:i:s', (int)$timeStr);
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $timeStr)) {
        $ts = strtotime($timeStr);
        $createdAt = $ts ? date('Y-m-d H:i:s', $ts) : $timeStr;
    }

    $insertCols = ['conversation_id', 'sender_id', 'content', 'created_at'];
    $params = [$conversationId, $senderId, $body, $createdAt];
    $placeholders = ['?', '?', '?', '?'];

    if ($msgTypeCol) { $insertCols[] = $msgTypeCol; $params[] = 'text'; $placeholders[] = '?'; }
    if ($hasExternalId && $extId) { $insertCols[] = 'external_id'; $params[] = $extId; $placeholders[] = '?'; }
    if ($hasSource) { $insertCols[] = 'source'; $params[] = 'chatwork'; $placeholders[] = '?'; }

    try {
        $pdo->prepare("INSERT INTO messages (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")")->execute($params);
        $inserted++;
    } catch (PDOException $e) {
        output("  挿入エラー: " . $e->getMessage(), $isCli);
    }
}

$pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

output("完了: インポート {$inserted} 件, スキップ {$skipped} 件", $isCli);
output("事務局（{$conv['name']}）のチャット画面でログを確認してください。", $isCli);
