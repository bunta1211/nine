<?php
/**
 * AI秘書 自動話しかけ ヘルパー
 * 毎日1回、ユーザーが興味を持ちそうな話題でAI秘書から話しかける
 */

require_once __DIR__ . '/../config/ai_config.php';

/**
 * ユーザーのコンテキスト（直近メッセージ・タスク・メモ・記憶）を収集
 */
function collectUserContext(PDO $pdo, int $userId, int $limit = 20): array {
    $context = [];

    try {
        $stmt = $pdo->prepare("
            SELECT m.content, c.name AS conv_title
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
            WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ORDER BY m.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $context[] = '[' . ($r['conv_title'] ?? 'チャット') . '] ' . mb_substr($r['content'], 0, 100);
        }
    } catch (Throwable $e) {
        error_log("proactive context messages: " . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("
            SELECT title, status FROM tasks
            WHERE assigned_to = ? AND status != 'completed'
            ORDER BY due_date ASC LIMIT 5
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $context[] = '[タスク] ' . $t['title'] . ' (' . $t['status'] . ')';
        }
    } catch (Throwable $ignore) {}

    try {
        $hasTypeCol = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'");
            $hasTypeCol = $chk && $chk->rowCount() > 0;
        } catch (Throwable $e) {}

        if ($hasTypeCol) {
            $stmt = $pdo->prepare("
                SELECT content FROM tasks
                WHERE created_by = ? AND type = 'memo' AND deleted_at IS NULL
                ORDER BY updated_at DESC LIMIT 5
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT content FROM memos
                WHERE created_by = ? ORDER BY updated_at DESC LIMIT 5
            ");
        }
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $context[] = '[メモ] ' . mb_substr($m['content'] ?? '', 0, 80);
        }
    } catch (Throwable $ignore) {}

    try {
        $stmt = $pdo->prepare("
            SELECT memory_key, memory_value FROM ai_user_memories
            WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mem) {
            $context[] = '[記憶] ' . $mem['memory_key'] . ': ' . mb_substr($mem['memory_value'], 0, 60);
        }
    } catch (Throwable $ignore) {}

    return $context;
}

/**
 * 改善希望の案内を含めるべきかチェック（3日に1回）
 */
function shouldIncludeImprovementHint(PDO $pdo, int $userId): bool {
    $dayOfYear = (int)date('z');
    if ($dayOfYear % 3 !== 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id FROM ai_conversations
            WHERE user_id = ? AND is_proactive = 1
              AND answer LIKE '%改善希望%'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            return false;
        }
    } catch (Throwable $ignore) {}

    return true;
}

/**
 * AI秘書が話しかける文を生成
 * @param bool $includeImprovementHint 改善希望の案内を含めるか
 */
function generateProactiveMessage(PDO $pdo, int $userId, bool $includeImprovementHint = false): ?string {
    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
        return null;
    }

    $contextLines = collectUserContext($pdo, $userId);

    $userName = 'ユーザー';
    try {
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['display_name'])) {
            $userName = $row['display_name'];
        }
    } catch (Throwable $ignore) {}

    $personality = '';
    try {
        $stmt = $pdo->prepare("SELECT custom_instructions, personality_json FROM user_ai_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['custom_instructions'])) {
            $personality = $row['custom_instructions'];
        }
    } catch (Throwable $ignore) {}

    $contextStr = !empty($contextLines) ? implode("\n", $contextLines) : '（情報なし）';

    $systemPrompt = "あなたはSocial9の秘書AIです。ユーザー「{$userName}」に対して、1日1回の自動的な挨拶メッセージを生成します。\n"
        . "以下のルールを守ってください：\n"
        . "- 短く自然な1〜3文（100文字程度）で話しかける\n"
        . "- ユーザーの最近の活動や興味に関連する話題を選ぶ\n"
        . "- 押しつけがましくならない\n"
        . "- 具体的な質問や提案を1つ含める\n";

    if ($includeImprovementHint) {
        $systemPrompt .= "- 今回は通常の挨拶に加えて、最後にさりげなく改善希望の案内を1文添えてください\n"
            . "- 案内の例: 「Social9の使い方で気になる点や改善希望があれば、いつでも「改善希望」というキーワードを入れて私にお知らせくださいね。」\n"
            . "- 自然な流れで添える（唐突にならないように）\n";
    }

    if ($personality) {
        $systemPrompt .= "\n秘書の性格設定:\n{$personality}\n";
    }

    $userPrompt = "以下はユーザーの最近の活動情報です:\n{$contextStr}\n\nこの情報をもとに、ユーザーが興味を持ちそうな話題で自然に話しかけるメッセージを1つだけ生成してください。挨拶文のみ出力し、説明は不要です。";

    $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $systemPrompt]]],
            ['role' => 'model', 'parts' => [['text' => 'はい、挨拶メッセージを生成します。']]],
            ['role' => 'user', 'parts' => [['text' => $userPrompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.9,
            'maxOutputTokens' => 256
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$text) return null;

    $text = trim($text);

    if ($includeImprovementHint && mb_strpos($text, '改善') === false) {
        $text .= "\n\nSocial9の使い方で気になる点や改善希望があれば、いつでも「改善希望」というキーワードを入れて私にお知らせくださいね😊";
    }

    return $text;
}

/**
 * 自動話しかけメッセージをDBに記録
 */
function saveProactiveMessage(PDO $pdo, int $userId, string $message): bool {
    try {
        $hasIsProactive = false;
        try {
            $pdo->query("SELECT is_proactive FROM ai_conversations LIMIT 0");
            $hasIsProactive = true;
        } catch (Throwable $ignore) {}

        if ($hasIsProactive) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, is_proactive, created_at)
                VALUES (?, '（自動挨拶）', ?, 'gemini_proactive', 'ja', 1, NOW())
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at)
                VALUES (?, '（自動挨拶）', ?, 'gemini_proactive', 'ja', NOW())
            ");
        }
        $stmt->execute([$userId, $message]);
        return true;
    } catch (Throwable $e) {
        error_log("proactive message save error for user {$userId}: " . $e->getMessage());
        return false;
    }
}
