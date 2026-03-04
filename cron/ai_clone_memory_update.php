<?php
/**
 * AIクローン 会話記憶の全自動更新 cron
 *
 * 各ユーザーの直近会話・返信修正データを分析し、
 * user_ai_settings.conversation_memory_summary を自動更新する。
 *
 * 推奨: 1日1回（深夜）
 *   crontab: 0 3 * * * php /path/to/cron/ai_clone_memory_update.php
 *   Windows: タスクスケジューラで同等の設定
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('CRON_MODE', true);

$basePath = dirname(__DIR__);
require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/config/ai_config.php';
$geminiPath = $basePath . '/includes/gemini_helper.php';
if (file_exists($geminiPath)) {
    require_once $geminiPath;
}

$pdo = getDB();

echo "[" . date('Y-m-d H:i:s') . "] === AI Clone memory update start ===\n";

if (!function_exists('geminiChat') || !function_exists('isGeminiAvailable') || !isGeminiAvailable()) {
    echo "Gemini is not available. Exiting.\n";
    exit(1);
}

$minConversations = 10;

try {
    $usersStmt = $pdo->prepare("SELECT DISTINCT ac.user_id
        FROM ai_conversations ac
        WHERE ac.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY ac.user_id
        HAVING COUNT(*) >= ?");
    $usersStmt->execute([$minConversations]);
    $userIds = $usersStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    echo "Error fetching users: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Target users: " . count($userIds) . "\n";

$updated = 0;
$failed = 0;

foreach ($userIds as $uid) {
    $uid = (int)$uid;
    echo "  Processing user_id={$uid} ... ";

    try {
        $convStmt = $pdo->prepare("SELECT question, answer FROM ai_conversations WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $convStmt->execute([$uid]);
        $convs = array_reverse($convStmt->fetchAll(PDO::FETCH_ASSOC));

        $replyStmt = $pdo->prepare("SELECT suggested_content, final_content FROM user_ai_reply_suggestions WHERE user_id = ? AND sent_at IS NOT NULL ORDER BY created_at DESC LIMIT 30");
        $replyStmt->execute([$uid]);
        $replies = array_reverse($replyStmt->fetchAll(PDO::FETCH_ASSOC));

        $sample = '';
        foreach ($convs as $c) {
            $sample .= "ユーザー: " . mb_substr($c['question'], 0, 200) . "\n";
            $sample .= "秘書: " . mb_substr($c['answer'] ?? '', 0, 200) . "\n\n";
        }
        foreach ($replies as $r) {
            if (!empty($r['final_content'])) {
                $sample .= "返信（AI提案）: " . mb_substr($r['suggested_content'], 0, 150) . "\n";
                $sample .= "返信（修正後）: " . mb_substr($r['final_content'], 0, 150) . "\n\n";
            }
        }

        if (mb_strlen($sample) < 50) {
            echo "skip (insufficient data)\n";
            continue;
        }

        $analyzePrompt = <<<PROMPT
以下はユーザーとAI秘書の会話履歴、およびグループチャットでの返信（AI提案→修正後）の記録です。
このユーザーの話し方・コミュニケーションスタイルの特徴を分析し、以下のJSON形式で回答してください。JSONのみ出力してください。

{
  "habits": ["口癖や決まり文句のリスト"],
  "emojis": ["よく使う絵文字・顔文字のリスト"],
  "tone": "話し方のトーン（例: 丁寧だがフレンドリー）",
  "first_person": "一人称（例: 私、僕、自分）",
  "sentence_endings": ["よく使う語尾パターン"],
  "per_person_style": ["相手別の話し方（例: 部下にはカジュアル、上司には敬語）"],
  "situation_phrases": ["状況別の言い回し（例: 依頼時は『お手数ですが』）"],
  "decision_style": "判断・意思決定の傾向（例: 慎重、即断、データ重視）",
  "summary": "全体の話し方の要約（2-3文）"
}

会話履歴:
{$sample}
PROMPT;

        $result = geminiChat($analyzePrompt, [], 'あなたはコミュニケーション分析の専門家です。JSONのみを返してください。', null);
        if (!$result['success']) {
            echo "FAIL (Gemini error)\n";
            $failed++;
            continue;
        }

        $raw = trim($result['response']);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $parsed = @json_decode($raw, true);
        $summaryText = is_array($parsed)
            ? json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $raw;

        $pdo->prepare("INSERT INTO user_ai_settings (user_id, conversation_memory_summary, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE conversation_memory_summary = ?, updated_at = NOW()")
            ->execute([$uid, $summaryText, $summaryText]);

        echo "OK\n";
        $updated++;

        usleep(500000);

    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Updated: {$updated}, Failed: {$failed}\n";
