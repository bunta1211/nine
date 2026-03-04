<?php
/**
 * 専門AI振り分けルーター
 * 
 * ユーザー発話の意図を分類し、適切な専門AIに振り分ける。
 * 計画書 2.2（2）方針A（自動振り分け）を基本とする。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/gemini_helper.php';

/**
 * 専門AIタイプの定数
 */
class SpecialistType {
    const WORK       = 'work';
    const PEOPLE     = 'people';
    const FINANCE    = 'finance';
    const COMPLIANCE = 'compliance';
    const MENTALCARE = 'mentalcare';
    const EDUCATION  = 'education';
    const CUSTOMER   = 'customer';
    const SECRETARY  = 'secretary'; // あなたの秘書がそのまま処理

    const ALL_TYPES = [
        self::WORK, self::PEOPLE, self::FINANCE,
        self::COMPLIANCE, self::MENTALCARE,
        self::EDUCATION, self::CUSTOMER
    ];

    const LABELS_JA = [
        'work'       => '業務内容統括AI',
        'people'     => '人財AI',
        'finance'    => '会計統括AI',
        'compliance' => 'コンプライアンスAI',
        'mentalcare' => 'メンタルケアAI',
        'education'  => '社内教育型AI',
        'customer'   => '顧客管理AI',
        'secretary'  => 'あなたの秘書',
    ];
}

/**
 * ユーザー発話の意図を分類し、振り分け先の専門AIタイプを返す
 *
 * @param string $userMessage ユーザーの発話
 * @param int $organizationId 組織ID
 * @return array ['specialist_type' => string, 'intent' => string, 'confidence' => float]
 */
function classifyIntent($userMessage, $organizationId) {
    $keywordResult = classifyByKeywords($userMessage);
    if ($keywordResult['confidence'] >= 0.8) {
        return $keywordResult;
    }

    $defaults = getSpecialistDefaults();
    $allKeywords = [];
    foreach ($defaults as $row) {
        $type = $row['specialist_type'];
        $kw = json_decode($row['intent_keywords'] ?? '[]', true) ?: [];
        $allKeywords[$type] = $kw;
    }

    $llmResult = classifyByLLM($userMessage, $allKeywords);
    if ($llmResult) {
        return $llmResult;
    }

    return $keywordResult['confidence'] > 0
        ? $keywordResult
        : ['specialist_type' => SpecialistType::SECRETARY, 'intent' => 'general', 'confidence' => 0.5];
}

/**
 * キーワードベースの簡易分類
 */
function classifyByKeywords($message) {
    $rules = [
        SpecialistType::WORK => [
            'keywords' => ['マニュアル','手順','やり方','注意事項','過去のやり方','集合知','業務内容','作業手順','失敗','ノウハウ'],
            'intent'   => 'work_knowledge',
        ],
        SpecialistType::PEOPLE => [
            'keywords' => ['リマインダー','出張','業務報告','進捗','タスク依頼','誰が詳しい','負荷','スケジュール','担当','人事'],
            'intent'   => 'people_management',
        ],
        SpecialistType::FINANCE => [
            'keywords' => ['経費','請求','予算','予実','稟議','金額','支払い','見積もり','契約','会計'],
            'intent'   => 'finance_management',
        ],
        SpecialistType::COMPLIANCE => [
            'keywords' => ['ポリシー','ルール','規定','倫理','コンプライアンス','違反','規則'],
            'intent'   => 'compliance_check',
        ],
        SpecialistType::MENTALCARE => [
            'keywords' => ['疲れ','ストレス','休暇','休息','体調','メンタル','働きすぎ','残業','長時間'],
            'intent'   => 'mental_care',
        ],
        SpecialistType::EDUCATION => [
            'keywords' => ['オンボーディング','研修','入社','新人','教育','資格','トレーニング'],
            'intent'   => 'education_support',
        ],
        SpecialistType::CUSTOMER => [
            'keywords' => ['顧客','取引先','商談','クレーム','問い合わせ','お客様','パートナー','窓口'],
            'intent'   => 'customer_management',
        ],
    ];

    $bestType = SpecialistType::SECRETARY;
    $bestScore = 0;
    $bestIntent = 'general';
    $msgLower = mb_strtolower($message);

    foreach ($rules as $type => $rule) {
        $score = 0;
        foreach ($rule['keywords'] as $kw) {
            if (mb_strpos($msgLower, mb_strtolower($kw)) !== false) {
                $score++;
            }
        }
        $confidence = count($rule['keywords']) > 0 ? $score / count($rule['keywords']) : 0;
        if ($score > 0 && ($score > $bestScore || ($score === $bestScore && $confidence > 0))) {
            $bestType = $type;
            $bestScore = $score;
            $bestIntent = $rule['intent'];
        }
    }

    $totalKeywords = 0;
    foreach ($rules as $rule) {
        $totalKeywords += count($rule['keywords']);
    }
    $normalizedConf = $totalKeywords > 0 ? min(1.0, $bestScore / 3) : 0;

    return [
        'specialist_type' => $bestType,
        'intent'          => $bestIntent,
        'confidence'      => round($normalizedConf, 2),
    ];
}

/**
 * LLMベースの意図分類（キーワードで確信が低い場合のフォールバック）
 */
function classifyByLLM($message, $allKeywords) {
    if (!isGeminiAvailable()) {
        return null;
    }

    $typeDescriptions = [];
    foreach (SpecialistType::ALL_TYPES as $type) {
        $label = SpecialistType::LABELS_JA[$type];
        $kw = isset($allKeywords[$type]) ? implode(', ', array_slice($allKeywords[$type], 0, 10)) : '';
        $typeDescriptions[] = "- {$type}: {$label}" . ($kw ? "（キーワード例: {$kw}）" : '');
    }
    $typeList = implode("\n", $typeDescriptions);

    $classifyPrompt = <<<PROMPT
以下のユーザー発話を分析し、最も適切な専門AIタイプを1つ選んでください。

専門AIタイプ一覧:
{$typeList}
- secretary: あなたの秘書（上記のどれにも該当しない一般的な会話・雑談）

ユーザー発話:
「{$message}」

以下のJSON形式で回答してください（JSONのみ、説明不要）:
{"specialist_type": "タイプ名", "intent": "意図の簡潔な説明", "confidence": 0.0-1.0}
PROMPT;

    $result = geminiChat($classifyPrompt, [], "あなたは意図分類システムです。JSONのみを返してください。");
    if (!$result['success']) {
        return null;
    }

    $responseText = trim($result['response']);
    $responseText = preg_replace('/^```json\s*/i', '', $responseText);
    $responseText = preg_replace('/\s*```$/', '', $responseText);

    $parsed = json_decode($responseText, true);
    if (!$parsed || !isset($parsed['specialist_type'])) {
        return null;
    }

    $validTypes = array_merge(SpecialistType::ALL_TYPES, [SpecialistType::SECRETARY]);
    if (!in_array($parsed['specialist_type'], $validTypes)) {
        $parsed['specialist_type'] = SpecialistType::SECRETARY;
    }

    return [
        'specialist_type' => $parsed['specialist_type'],
        'intent'          => $parsed['intent'] ?? 'classified',
        'confidence'      => (float)($parsed['confidence'] ?? 0.7),
    ];
}

/**
 * 専門AIのデフォルト設定を取得
 */
function getSpecialistDefaults() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM ai_specialist_defaults ORDER BY specialist_type");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 組織の専門AI設定を取得
 */
function getOrgSpecialist($organizationId, $specialistType) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM org_ai_specialists
        WHERE organization_id = ? AND specialist_type = ? AND is_enabled = 1
    ");
    $stmt->execute([$organizationId, $specialistType]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 専門AIのシステムプロンプトを取得（組織カスタム > デフォルト）
 */
function getSpecialistPrompt($organizationId, $specialistType) {
    $orgSpec = getOrgSpecialist($organizationId, $specialistType);
    if ($orgSpec && !empty($orgSpec['system_prompt'])) {
        return $orgSpec['system_prompt'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT default_prompt FROM ai_specialist_defaults WHERE specialist_type = ?");
    $stmt->execute([$specialistType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['default_prompt'] : null;
}

/**
 * 専門AIを呼び出して応答を取得
 */
function callSpecialist($organizationId, $userId, $specialistType, $userMessage, $conversationHistory = []) {
    $prompt = getSpecialistPrompt($organizationId, $specialistType);
    if (!$prompt) {
        $label = SpecialistType::LABELS_JA[$specialistType] ?? $specialistType;
        $prompt = "あなたは組織の{$label}です。専門分野に関する質問に日本語で丁寧に回答してください。";
    }

    $memories = searchOrgMemories($organizationId, $specialistType, $userMessage, 5);
    if (!empty($memories)) {
        $memoryContext = "\n\n【参考情報（組織ナレッジ）】\n";
        foreach ($memories as $mem) {
            $memoryContext .= "- {$mem['title']}: {$mem['content']}\n";
        }
        $prompt .= $memoryContext;
    }

    $result = geminiChat($userMessage, $conversationHistory, $prompt);

    logSpecialistCall($organizationId, $userId, $specialistType, $userMessage, $result);

    return $result;
}

/**
 * 組織の記憶ストアを検索
 */
function searchOrgMemories($organizationId, $specialistType, $query, $limit = 5) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, title, content, tags, created_at
        FROM org_ai_memories
        WHERE organization_id = ?
          AND specialist_type = ?
          AND status = 'active'
          AND MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE)
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$organizationId, $specialistType, $query, $limit]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        $keywords = mb_substr($query, 0, 100);
        $stmt = $pdo->prepare("
            SELECT id, title, content, tags, created_at
            FROM org_ai_memories
            WHERE organization_id = ?
              AND specialist_type = ?
              AND status = 'active'
              AND (title LIKE ? OR content LIKE ?)
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $like = "%{$keywords}%";
        $stmt->execute([$organizationId, $specialistType, $like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return $results;
}

/**
 * 専門AI呼び出しをログに記録
 */
function logSpecialistCall($organizationId, $userId, $specialistType, $query, $result) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO org_ai_specialist_logs
                (organization_id, user_id, specialist_type, query_summary, response_summary, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $querySummary = mb_substr($query, 0, 500);
        $responseSummary = $result['success'] ? mb_substr($result['response'] ?? '', 0, 500) : 'ERROR';
        $stmt->execute([$organizationId, $userId, $specialistType, $querySummary, $responseSummary]);
    } catch (Exception $e) {
        error_log('specialist log error: ' . $e->getMessage());
    }
}

/**
 * 組織に専門AI一式をプロビジョニング（新規組織作成時）
 */
function provisionSpecialistsForOrg($organizationId) {
    $pdo = getDB();
    foreach (SpecialistType::ALL_TYPES as $type) {
        $label = SpecialistType::LABELS_JA[$type];
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO org_ai_specialists (organization_id, specialist_type, display_name, is_enabled)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$organizationId, $type, $label]);
    }
}
