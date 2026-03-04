<?php
/**
 * グループチャット自動検証・分類・記憶バッチ
 * 
 * 定期実行（cron等）で、組織のグループチャットから情報を抽出し、
 * 各専門AIの記憶ストアに構造化して保存する。
 * 計画書 2.4 に基づく。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini_helper.php';
require_once __DIR__ . '/ai_specialist_router.php';

/**
 * 指定組織のグループチャットを処理
 */
function processOrgChatMemories($organizationId, $maxMessages = 100) {
    $pdo = getDB();

    $convStmt = $pdo->prepare("
        SELECT id FROM conversations
        WHERE organization_id = ? AND type != 'dm'
    ");
    $convStmt->execute([$organizationId]);
    $conversations = $convStmt->fetchAll(PDO::FETCH_COLUMN);

    $totalProcessed = 0;
    $totalCreated = 0;

    foreach ($conversations as $convId) {
        $result = processConversationMessages($organizationId, $convId, $maxMessages);
        $totalProcessed += $result['processed'];
        $totalCreated += $result['created'];
    }

    return ['processed' => $totalProcessed, 'created' => $totalCreated];
}

/**
 * 特定会話の未処理メッセージを処理
 */
function processConversationMessages($organizationId, $conversationId, $maxMessages = 100) {
    $pdo = getDB();

    $logStmt = $pdo->prepare("
        SELECT last_processed_message_id FROM org_ai_memory_batch_log
        WHERE organization_id = ? AND conversation_id = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $logStmt->execute([$organizationId, $conversationId]);
    $lastLog = $logStmt->fetch(PDO::FETCH_ASSOC);
    $lastMsgId = $lastLog ? (int)$lastLog['last_processed_message_id'] : 0;

    $msgStmt = $pdo->prepare("
        SELECT m.id, m.user_id, m.content, m.created_at, u.display_name, u.username
        FROM messages m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE m.conversation_id = ?
          AND m.id > ?
          AND m.content IS NOT NULL
          AND m.content != ''
          AND m.is_deleted = 0
        ORDER BY m.id ASC
        LIMIT ?
    ");
    $msgStmt->execute([$conversationId, $lastMsgId, $maxMessages]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($messages)) {
        return ['processed' => 0, 'created' => 0];
    }

    $batchStmt = $pdo->prepare("
        INSERT INTO org_ai_memory_batch_log
            (organization_id, conversation_id, status, started_at, created_at)
        VALUES (?, ?, 'processing', NOW(), NOW())
    ");
    $batchStmt->execute([$organizationId, $conversationId]);
    $batchId = (int)$pdo->lastInsertId();

    $created = 0;
    $lastProcessedId = $lastMsgId;

    $chunks = array_chunk($messages, 10);
    foreach ($chunks as $chunk) {
        $extracted = extractAndClassifyChunk($chunk);
        foreach ($extracted as $item) {
            if (saveExtractedMemory($organizationId, $item, $conversationId)) {
                $created++;
            }
        }
        $lastProcessedId = end($chunk)['id'];
    }

    $updateStmt = $pdo->prepare("
        UPDATE org_ai_memory_batch_log SET
            last_processed_message_id = ?,
            messages_processed = ?,
            memories_created = ?,
            status = 'completed',
            completed_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$lastProcessedId, count($messages), $created, $batchId]);

    return ['processed' => count($messages), 'created' => $created];
}

/**
 * メッセージチャンクを分類・抽出
 */
function extractAndClassifyChunk($messages) {
    if (!isGeminiAvailable()) {
        return extractByRules($messages);
    }

    $chatText = '';
    foreach ($messages as $msg) {
        $name = $msg['display_name'] ?? $msg['username'] ?? 'unknown';
        $chatText .= "[{$msg['created_at']}] {$name}: {$msg['content']}\n";
    }

    $prompt = <<<PROMPT
以下のグループチャットの会話から、組織のナレッジとして記憶すべき情報を抽出してください。

会話:
{$chatText}

以下のカテゴリに分類して、JSON配列で返してください:
- work: 業務手順・やり方・注意事項・マニュアル的な内容
- people: 担当者・締切・出張・タスク・進捗に関する情報
- finance: 経費・請求・予算・金額に関する情報
- compliance: ルール・ポリシーに関する情報
- education: 研修・教育・オンボーディングに関する情報
- customer: 顧客・取引先・商談に関する情報

雑談やノイズは除外してください。記憶すべき情報がない場合は空配列[]を返してください。

JSON配列のみ返してください:
[
  {
    "specialist_type": "カテゴリ",
    "title": "簡潔なタイトル",
    "content": "構造化された情報（元の文章を含めて具体的に）",
    "tags": ["タグ1", "タグ2"],
    "source_message_ids": [メッセージID配列]
  }
]
PROMPT;

    $result = geminiChat($prompt, [], "あなたは情報抽出・分類システムです。JSONのみを返してください。");
    if (!$result['success']) {
        return extractByRules($messages);
    }

    $responseText = trim($result['response']);
    $responseText = preg_replace('/^```json\s*/i', '', $responseText);
    $responseText = preg_replace('/\s*```$/', '', $responseText);
    $parsed = json_decode($responseText, true);

    if (!is_array($parsed)) {
        return extractByRules($messages);
    }

    $validTypes = SpecialistType::ALL_TYPES;
    $filtered = [];
    foreach ($parsed as $item) {
        if (isset($item['specialist_type']) && in_array($item['specialist_type'], $validTypes)) {
            $filtered[] = $item;
        }
    }

    return $filtered;
}

/**
 * ルールベースの簡易抽出（LLM不可時のフォールバック）
 */
function extractByRules($messages) {
    $extracted = [];
    foreach ($messages as $msg) {
        $content = $msg['content'];
        if (mb_strlen($content) < 20) continue;

        if (preg_match('/手順|やり方|マニュアル|注意|方法/u', $content)) {
            $extracted[] = [
                'specialist_type'    => 'work',
                'title'              => mb_substr($content, 0, 50),
                'content'            => $content,
                'tags'               => ['auto_extracted'],
                'source_message_ids' => [$msg['id']],
            ];
        } elseif (preg_match('/顧客|取引先|商談|クライアント/u', $content)) {
            $extracted[] = [
                'specialist_type'    => 'customer',
                'title'              => mb_substr($content, 0, 50),
                'content'            => $content,
                'tags'               => ['auto_extracted'],
                'source_message_ids' => [$msg['id']],
            ];
        } elseif (preg_match('/経費|請求|予算|金額|見積/u', $content)) {
            $extracted[] = [
                'specialist_type'    => 'finance',
                'title'              => mb_substr($content, 0, 50),
                'content'            => $content,
                'tags'               => ['auto_extracted'],
                'source_message_ids' => [$msg['id']],
            ];
        }
    }
    return $extracted;
}

/**
 * 抽出された記憶を保存
 */
function saveExtractedMemory($organizationId, $item, $conversationId) {
    $pdo = getDB();

    $title = $item['title'] ?? '';
    $content = $item['content'] ?? '';
    if (empty($content)) return false;

    $sourceMessageId = null;
    if (!empty($item['source_message_ids']) && is_array($item['source_message_ids'])) {
        $sourceMessageId = (int)$item['source_message_ids'][0];
    }

    $stmt = $pdo->prepare("
        INSERT INTO org_ai_memories
            (organization_id, specialist_type, title, content, content_type,
             tags, source_conversation_id, source_message_id, source_type, status, created_at)
        VALUES (?, ?, ?, ?, 'text', ?, ?, ?, 'auto_batch', 'active', NOW())
    ");
    $stmt->execute([
        $organizationId,
        $item['specialist_type'],
        $title,
        $content,
        json_encode($item['tags'] ?? [], JSON_UNESCAPED_UNICODE),
        $conversationId,
        $sourceMessageId,
    ]);

    return true;
}

/**
 * 全組織のバッチ処理を実行（cron エントリポイント）
 */
function runAllOrgMemoryBatch($maxMessagesPerConv = 50) {
    $pdo = getDB();
    $orgStmt = $pdo->query("SELECT id FROM organizations");
    $orgs = $orgStmt->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($orgs as $orgId) {
        $results[$orgId] = processOrgChatMemories($orgId, $maxMessagesPerConv);
    }
    return $results;
}
