<?php
/**
 * PDF検索の診断スクリプト
 * 
 * 上パネル検索でヒットするのにAI秘書でヒットしない原因を特定する
 * 使用方法: admin/debug_pdf_search.php?keyword=生活説明会
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin', 'org_admin'])) {
    die('管理者権限が必要です');
}

$keyword = trim($_GET['keyword'] ?? '生活説明会');
if (empty($keyword)) {
    die('keywordパラメータを指定してください。例: ?keyword=生活説明会');
}

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PDF検索診断</title></head><body>';
echo '<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;} pre{background:#2d2d2d;padding:12px;border-radius:6px;overflow-x:auto;} .ok{color:#4ec9b0;} .err{color:#f48771;} .warn{color:#dcdcaa;} h2{color:#569cd6;margin-top:24px;}</style>';

$pdo = getDB();
$user_id = (int)($_SESSION['user_id'] ?? 0);

echo "<h1>PDF検索診断: 「{$keyword}」</h1>";
echo "<p>ログインユーザーID: {$user_id}</p>";

// 1. extracted_textカラムの存在確認
echo '<h2>1. extracted_textカラム</h2>';
$hasCol = false;
try {
    $pdo->query("SELECT extracted_text FROM messages LIMIT 0");
    $hasCol = true;
    echo '<span class="ok">✅ カラム存在</span>';
} catch (PDOException $e) {
    echo '<span class="err">❌ カラムなし: ' . htmlspecialchars($e->getMessage()) . '</span>';
}

// 2. キーワードが含まれるメッセージ（全件・会話制限なし）
echo '<h2>2. メッセージ検索（会話制限なし）</h2>';
$stmt = $pdo->prepare("
    SELECT m.id, m.conversation_id, m.content, LEFT(COALESCE(m.extracted_text,''), 200) as ext_preview,
           c.name as conv_name
    FROM messages m
    INNER JOIN conversations c ON m.conversation_id = c.id
    WHERE m.deleted_at IS NULL
    AND (m.content LIKE ? OR m.extracted_text LIKE ?)
    ORDER BY m.created_at DESC LIMIT 10
");
$stmt->execute(["%{$keyword}%", "%{$keyword}%"]);
$allMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo '<p>該当件数: ' . count($allMatches) . '</p>';
if (!empty($allMatches)) {
    echo '<pre>';
    foreach ($allMatches as $r) {
        echo "ID:{$r['id']} conv:{$r['conversation_id']} ({$r['conv_name']})\n";
        echo "  content: " . htmlspecialchars(mb_substr($r['content'], 0, 80)) . "...\n";
        echo "  extracted: " . htmlspecialchars($r['ext_preview'] ?? '') . "\n\n";
    }
    echo '</pre>';
} else {
    echo '<span class="err">該当メッセージなし（extracted_textに未保存の可能性）</span>';
}

// 3. ユーザーが参加している会話ID
echo '<h2>3. ユーザー参加会話</h2>';
$convStmt = $pdo->prepare("SELECT conversation_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL");
$convStmt->execute([$user_id]);
$userConvIds = $convStmt->fetchAll(PDO::FETCH_COLUMN);
echo '<p>参加会話数: ' . count($userConvIds) . '</p>';
if (count($userConvIds) <= 20) {
    echo '<pre>' . implode(', ', $userConvIds) . '</pre>';
}

// 4. ヒットしたメッセージのconversation_idがユーザー参加会話に含まれるか
echo '<h2>4. 会話メンバーシップチェック</h2>';
if (!empty($allMatches)) {
    $hitConvIds = array_unique(array_column($allMatches, 'conversation_id'));
    $inMember = [];
    $notInMember = [];
    foreach ($hitConvIds as $cid) {
        if (in_array($cid, $userConvIds)) {
            $inMember[] = $cid;
        } else {
            $notInMember[] = $cid;
        }
    }
    if (!empty($notInMember)) {
        echo '<span class="err">❌ ヒットしたメッセージの会話(' . implode(',', $notInMember) . ')にユーザーが参加していません</span>';
    } else {
        echo '<span class="ok">✅ ヒットしたメッセージはすべてユーザー参加会話に含まれる</span>';
    }
}

// 5. searchTasksAndMemosと同様のクエリを直接実行
echo '<h2>5. searchTasksAndMemos同等クエリ</h2>';
if (!empty($userConvIds)) {
    $ph = implode(',', array_fill(0, count($userConvIds), '?'));
    $sql = "
        SELECT m.id, m.conversation_id, c.name as conv_name
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.id
        WHERE m.conversation_id IN ({$ph})
        AND m.deleted_at IS NULL
        AND (m.content LIKE ? OR m.extracted_text LIKE ?)
        ORDER BY m.created_at DESC LIMIT 10
    ";
    $params = array_merge($userConvIds, ["%{$keyword}%", "%{$keyword}%"]);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<p>結果件数: ' . count($results) . '</p>';
        if (!empty($results)) {
            echo '<pre>' . print_r($results, true) . '</pre>';
        } else {
            echo '<span class="warn">0件（キーワードまたは会話条件に問題あり）</span>';
        }
    } catch (PDOException $e) {
        echo '<span class="err">' . htmlspecialchars($e->getMessage()) . '</span>';
    }
} else {
    echo '<span class="err">ユーザーが参加している会話がありません</span>';
}

// 6. task_memo_search_helper の tableHasColumn 結果
echo '<h2>6. tableHasColumn 結果</h2>';
if (file_exists(__DIR__ . '/../includes/task_memo_search_helper.php')) {
    require_once __DIR__ . '/../includes/task_memo_search_helper.php';
    $hasExtracted = tableHasColumn($pdo, 'messages', 'extracted_text');
    echo $hasExtracted ? '<span class="ok">✅ true</span>' : '<span class="err">❌ false（→ contentのみ検索になり、PDFパスだけでヒットしない）</span>';
} else {
    echo '<span class="err">task_memo_search_helper.php が見つかりません</span>';
}

echo '</body></html>';
