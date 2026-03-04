<?php
/**
 * AI安全通報機能
 * 
 * 社会通念上看過しえない事象・生命の危機・いじめ等を検知し、
 * 運営責任者（KEN）に自動通報する。
 * 計画書 セクション2「運営への自動通報」、6.1 に基づく。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini_helper.php';
require_once __DIR__ . '/ai_user_profiler.php';

/**
 * ユーザーの発話を安全性チェックし、必要なら自動通報
 *
 * @param int $userId
 * @param string $userMessage ユーザー発話
 * @param int|null $organizationId
 * @param int|null $conversationId AI会話ID
 * @param array $conversationHistory 前後の文脈
 * @return array|null 通報が生成された場合はレポート情報、不要なら null
 */
function checkAndReport($userId, $userMessage, $organizationId = null, $conversationId = null, $conversationHistory = []) {
    $keywordCheck = checkSafetyKeywords($userMessage);
    if (!$keywordCheck['needs_review']) {
        return null;
    }

    if (!isGeminiAvailable()) {
        if ($keywordCheck['severity'] === 'critical') {
            return createSafetyReport(
                $userId, $organizationId, $conversationId,
                $keywordCheck['type'], $keywordCheck['severity'],
                'キーワード検知: ' . $keywordCheck['reason'],
                $userMessage, $conversationHistory,
                'キーワードベース検知（LLM未使用）'
            );
        }
        return null;
    }

    $contextText = '';
    foreach ($conversationHistory as $msg) {
        $role = $msg['role'] === 'user' ? 'ユーザー' : '秘書';
        $contextText .= "{$role}: {$msg['content']}\n";
    }
    $contextText .= "ユーザー: {$userMessage}\n";

    $analyzePrompt = <<<PROMPT
以下の会話を分析し、次のいずれかに該当するか判定してください:
1. social_norm: 社会通念上看過しえない事象（公序良俗違反、重大な不正・違法行為の示唆、差別的発言等）
2. life_danger: 生命の危機があると判断される事象（自傷・他害・事故等の重大な危害のおそれ）
3. bullying: いじめと判断される事象（特定個人への執拗な攻撃・排除・誹謗中傷・無視等）

会話:
{$contextText}

以下のJSON形式で回答してください（JSONのみ）:
{
  "is_reportable": true/false,
  "report_type": "social_norm" または "life_danger" または "bullying" または "none",
  "severity": "low" または "medium" または "high" または "critical",
  "reasoning": "判断理由の詳細な説明",
  "summary": "事象の要約（運営が状況を即座に把握できる程度に具体的に）"
}
PROMPT;

    $result = geminiChat($analyzePrompt, [], "あなたは安全性判定システムです。JSONのみを返してください。偽陽性を避けつつ、本当に危険な事象は見逃さないでください。");
    if (!$result['success']) {
        return null;
    }

    $responseText = trim($result['response']);
    $responseText = preg_replace('/^```json\s*/i', '', $responseText);
    $responseText = preg_replace('/\s*```$/', '', $responseText);
    $parsed = json_decode($responseText, true);

    if (!$parsed || !($parsed['is_reportable'] ?? false)) {
        return null;
    }

    $validTypes = ['social_norm', 'life_danger', 'bullying'];
    $reportType = in_array($parsed['report_type'] ?? '', $validTypes)
        ? $parsed['report_type']
        : 'other';

    return createSafetyReport(
        $userId, $organizationId, $conversationId,
        $reportType,
        $parsed['severity'] ?? 'medium',
        $parsed['summary'] ?? '自動検知',
        $contextText,
        $conversationHistory,
        $parsed['reasoning'] ?? ''
    );
}

/**
 * 通報レコードを生成・保存
 */
function createSafetyReport($userId, $orgId, $convId, $type, $severity, $summary, $rawContext, $history, $reasoning) {
    $pdo = getDB();

    $personalitySnapshot = getPersonalitySnapshot($userId);

    $socialContext = getUserSocialContext($userId, $orgId);

    $stmt = $pdo->prepare("
        INSERT INTO ai_safety_reports
            (user_id, organization_id, report_type, severity, summary,
             raw_context, source_conversation_id, user_social_context,
             user_personality_snapshot, ai_reasoning, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
    ");
    $stmt->execute([
        $userId,
        $orgId,
        $type,
        $severity,
        $summary,
        $rawContext,
        $convId,
        $socialContext,
        json_encode($personalitySnapshot, JSON_UNESCAPED_UNICODE),
        $reasoning,
    ]);

    $reportId = (int)$pdo->lastInsertId();

    return [
        'report_id' => $reportId,
        'type'      => $type,
        'severity'  => $severity,
        'summary'   => $summary,
    ];
}

/**
 * ユーザーの社会的文脈を取得
 */
function getUserSocialContext($userId, $orgId) {
    $pdo = getDB();

    $context = [];

    $stmt = $pdo->prepare("SELECT id, username, display_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $context['user_display_name'] = $user['display_name'] ?? $user['username'];
    }

    if ($orgId) {
        $stmt = $pdo->prepare("
            SELECT o.name AS org_name, o.type AS org_type, om.role
            FROM organizations o
            JOIN organization_members om ON om.organization_id = o.id AND om.user_id = ?
            WHERE o.id = ?
        ");
        $stmt->execute([$userId, $orgId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($org) {
            $context['organization_name'] = $org['org_name'];
            $context['organization_type'] = $org['org_type'];
            $context['role_in_org'] = $org['role'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT o.name, om.role
        FROM organization_members om
        JOIN organizations o ON o.id = om.organization_id
        WHERE om.user_id = ? AND om.left_at IS NULL
    ");
    $stmt->execute([$userId]);
    $allOrgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $context['all_organizations'] = $allOrgs;

    return json_encode($context, JSON_UNESCAPED_UNICODE);
}

/**
 * キーワードベースの安全性簡易チェック
 */
function checkSafetyKeywords($message) {
    $criticalPatterns = [
        'life_danger' => [
            'patterns' => ['/死にたい|殺す|自殺|命を絶つ|死のう|殺害|飛び降り/u'],
            'severity' => 'critical',
        ],
    ];
    $highPatterns = [
        'bullying' => [
            'patterns' => ['/いじめ|虐め|無視され|仲間外れ|パワハラ|セクハラ|ハラスメント/u'],
            'severity' => 'high',
        ],
        'social_norm' => [
            'patterns' => ['/不正|横領|違法|犯罪|薬物|脅迫|暴力|差別/u'],
            'severity' => 'high',
        ],
    ];

    foreach ($criticalPatterns as $type => $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $message)) {
                return [
                    'needs_review' => true,
                    'type'     => $type,
                    'severity' => $rule['severity'],
                    'reason'   => "キーワード検知: {$pattern}",
                ];
            }
        }
    }

    foreach ($highPatterns as $type => $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $message)) {
                return [
                    'needs_review' => true,
                    'type'     => $type,
                    'severity' => $rule['severity'],
                    'reason'   => "キーワード検知: {$pattern}",
                ];
            }
        }
    }

    return ['needs_review' => false, 'type' => null, 'severity' => null, 'reason' => null];
}

/**
 * 運営からの追加質問を登録
 */
function askSecretaryQuestion($reportId, $askedBy, $question) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO ai_safety_report_questions (report_id, asked_by, question, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$reportId, $askedBy, $question]);
    return (int)$pdo->lastInsertId();
}

/**
 * 秘書が追加質問に回答
 */
function answerSecretaryQuestion($questionId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT q.*, r.user_id, r.raw_context, r.summary
        FROM ai_safety_report_questions q
        JOIN ai_safety_reports r ON r.id = q.report_id
        WHERE q.id = ? AND q.answer IS NULL
    ");
    $stmt->execute([$questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;

    $stmtConv = $pdo->prepare("
        SELECT question, answer FROM ai_conversations
        WHERE user_id = ? ORDER BY created_at DESC LIMIT 20
    ");
    $stmtConv->execute([$row['user_id']]);
    $convHistory = $stmtConv->fetchAll(PDO::FETCH_ASSOC);

    $historyText = '';
    foreach (array_reverse($convHistory) as $c) {
        $historyText .= "ユーザー: " . mb_substr($c['question'], 0, 300) . "\n";
        $historyText .= "秘書: " . mb_substr($c['answer'] ?? '', 0, 300) . "\n";
    }

    $prompt = <<<PROMPT
あなたはこのユーザーの秘書AIです。運営から以下の質問がありました。
ユーザーとの会話履歴やコンテキストを踏まえて、事実に基づいて正確に回答してください。

【通報時の要約】
{$row['summary']}

【通報時の生コンテキスト】
{$row['raw_context']}

【最近の会話履歴】
{$historyText}

【運営からの質問】
{$row['question']}

事実に基づいて回答してください。推測の場合はその旨を明記してください。
PROMPT;

    $result = geminiChat($prompt, [], "あなたはユーザーの秘書AIです。運営からの質問に対し、ユーザーとのやり取りに基づいて正確に回答します。");
    if (!$result['success']) {
        return null;
    }

    $answer = $result['response'];
    $stmt = $pdo->prepare("UPDATE ai_safety_report_questions SET answer = ?, answered_at = NOW() WHERE id = ?");
    $stmt->execute([$answer, $questionId]);

    return $answer;
}

/**
 * 通報一覧を取得（運営責任者用）
 */
function getSafetyReports($status = null, $limit = 50, $offset = 0) {
    $pdo = getDB();
    $where = '';
    $params = [];
    if ($status) {
        $where = 'WHERE r.status = ?';
        $params[] = $status;
    }
    $params[] = (int)$limit;
    $params[] = (int)$offset;

    $stmt = $pdo->prepare("
        SELECT r.*, u.display_name AS user_display_name, u.username
        FROM ai_safety_reports r
        LEFT JOIN users u ON u.id = r.user_id
        {$where}
        ORDER BY
            CASE r.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
