<?php
/**
 * Chatwork 事務局ログ インポートスクリプト
 * 
 * 使い方:
 *   CLI: php admin/import_chatwork_messages.php --token=XXX --room_id=123456 --conversation_id=130
 *   Web: admin/import_chatwork_messages.php?token=XXX&room_id=123456&conversation_id=130
 * 
 * 事前準備:
 *   1. database/migration_chatwork_import.sql を実行（external_id, source カラム追加）
 *   2. Chatwork API トークンを取得
 *   3. 事務局ルームの room_id を確認（URLの rid の値）
 *   4. Social9 の会話ID（事務局 = c= の値）を確認
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// 管理者チェック（Web実行時）
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    requireOrgAdmin();
    header('Content-Type: text/html; charset=UTF-8');
}

$pdo = getDB();

// パラメータ取得
$token = $isCli
    ? (getopt('', ['token::'])['token'] ?? $_SERVER['argv'][1] ?? '')
    : trim($_GET['token'] ?? $_POST['token'] ?? '');
$roomId = $isCli
    ? (int)(getopt('', ['room_id::'])['room_id'] ?? $_SERVER['argv'][2] ?? 0)
    : (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
$conversationId = $isCli
    ? (int)(getopt('', ['conversation_id::'])['conversation_id'] ?? $_SERVER['argv'][3] ?? 0)
    : (int)($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);

// 簡易CLI解析（--token=xxx 形式）
if ($isCli && empty($token)) {
    foreach ($_SERVER['argv'] as $arg) {
        if (preg_match('/^--token=(.+)$/', $arg, $m)) $token = $m[1];
        if (preg_match('/^--room_id=(\d+)$/', $arg, $m)) $roomId = (int)$m[1];
        if (preg_match('/^--conversation_id=(\d+)$/', $arg, $m)) $conversationId = (int)$m[1];
    }
}

function output($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

if (empty($token) || !$roomId || !$conversationId) {
    output('Usage: --token=CHATWORK_API_TOKEN --room_id=CHATWORK_ROOM_ID --conversation_id=SOCIAL9_CONVERSATION_ID', $isCli);
    if (!$isCli) {
        echo '<p>またはフォームから入力してください。</p>';
        echo '<form method="post">';
        echo 'APIトークン: <input type="password" name="token" required><br>';
        echo 'Chatwork ルームID: <input type="number" name="room_id" placeholder="123456789" required><br>';
        echo 'Social9 会話ID: <input type="number" name="conversation_id" placeholder="130" required><br>';
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

// ユーザーマッピング（display_name → user_id）
$stmt = $pdo->query("SELECT id, display_name FROM users");
$userMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = trim(preg_replace('/\s*\([^)]*\)/', '', $row['display_name'])); // 括弧内除去
    $userMap[$row['id']] = $row['display_name'];
    $userMap[strtolower($name)] = $row['id'];
    $userMap[strtolower($row['display_name'])] = $row['id'];
}

// デフォルト送信者（マッピングできない場合）
$stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('system_admin','org_admin','admin') OR display_name = 'Ken' LIMIT 1");
$stmt->execute();
$fallbackUser = $stmt->fetch(PDO::FETCH_ASSOC);
$fallbackUserId = $fallbackUser['id'] ?? 1;

// external_id カラムの有無を確認
$hasExternalId = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'external_id'");
    $hasExternalId = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    // カラムなし
}

$hasSource = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'source'");
    $hasSource = $stmt->rowCount() > 0;
} catch (PDOException $e) {
}

// メッセージ型カラム名を確認
$msgTypeCol = 'message_type';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'content_type'");
        $msgTypeCol = $stmt->rowCount() > 0 ? 'content_type' : null;
    }
} catch (PDOException $e) {
    $msgTypeCol = null;
}

// Chatwork API からメッセージ取得（1リクエスト最大100件・API仕様上それ以上は取得不可）
// force=1: 最新100件を取得。force=0だと「未取得分」のみで空になる場合あり
output("Chatwork API からメッセージを取得中...", $isCli);

$url = "https://api.chatwork.com/v2/rooms/{$roomId}/messages?force=1";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-ChatworkToken: ' . $token,
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    output("API エラー (HTTP {$httpCode}): " . substr($response, 0, 300), $isCli);
    exit(1);
}

$allMessages = json_decode($response, true);
if (!is_array($allMessages)) {
    output("API レスポンスの解析に失敗しました。", $isCli);
    exit(1);
}

output("  取得: " . count($allMessages) . " 件（※API仕様上、最大100件まで）", $isCli);

// 古い順にソート（送信日時）
usort($allMessages, function ($a, $b) {
    $t1 = $a['send_time'] ?? 0;
    $t2 = $b['send_time'] ?? 0;
    return $t1 - $t2;
});

output("合計 " . count($allMessages) . " 件のメッセージを取得しました。", $isCli);
output("Social9 へインポート中...", $isCli);

$inserted = 0;
$skipped = 0;
$replyMap = []; // Chatwork message_id => Social9 message_id

$insertCols = ['conversation_id', 'sender_id', 'content', 'created_at'];
if ($msgTypeCol) $insertCols[] = $msgTypeCol;
if ($hasExternalId) $insertCols[] = 'external_id';
if ($hasSource) $insertCols[] = 'source';

foreach ($allMessages as $m) {
    $body = $m['body'] ?? '';
    $accountId = $m['account']['account_id'] ?? 0;
    $accountName = trim($m['account']['name'] ?? '');
    $ExtMsgId = (string)($m['message_id'] ?? '');

    if ($hasExternalId) {
        $stmt = $pdo->prepare("SELECT id FROM messages WHERE conversation_id = ? AND external_id = ?");
        $stmt->execute([$conversationId, $ExtMsgId]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }
    }

    $senderId = $fallbackUserId;
    if (!empty($accountName)) {
        $key = strtolower(preg_replace('/\s*\([^)]*\)/', '', $accountName));
        if (isset($userMap[$key])) {
            $senderId = is_numeric($userMap[$key]) ? $userMap[$key] : $userMap[$key];
        }
    }

    $sendTime = $m['send_time'] ?? time();
    $createdAt = date('Y-m-d H:i:s', $sendTime);

    $replyToId = null;
    if (!empty($m['reply_to_message_id']) && isset($replyMap[$m['reply_to_message_id']])) {
        $replyToId = $replyMap[$m['reply_to_message_id']];
    }

    $params = [$conversationId, $senderId, $body, $createdAt];
    $placeholders = ['?', '?', '?', '?'];

    if ($msgTypeCol) {
        $params[] = 'text';
        $placeholders[] = '?';
    }
    if ($hasExternalId) {
        $params[] = $ExtMsgId;
        $placeholders[] = '?';
    }
    if ($hasSource) {
        $params[] = 'chatwork';
        $placeholders[] = '?';
    }

    if ($replyToId !== null) {
        $params[] = $replyToId;
        $placeholders[] = '?';
        $insertColsWithReply = array_merge($insertCols, ['reply_to_id']);
    } else {
        $insertColsWithReply = $insertCols;
    }

    $sql = "INSERT INTO messages (" . implode(', ', $insertColsWithReply) . ") VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $newId = $pdo->lastInsertId();
        if ($ExtMsgId && $newId) {
            $replyMap[$ExtMsgId] = $newId;
        }
        $inserted++;
    } catch (PDOException $e) {
        output("  挿入エラー (message_id={$ExtMsgId}): " . $e->getMessage(), $isCli);
    }
}

$pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

output("完了: インポート {$inserted} 件, スキップ {$skipped} 件", $isCli);
output("事務局（{$conv['name']}）のチャット画面でログを確認してください。", $isCli);
