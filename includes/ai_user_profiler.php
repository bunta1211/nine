<?php
/**
 * ユーザー性格・行動分析プロファイラー
 * 
 * あなたの秘書がユーザー好みに自動成長するための
 * 性格・コミュニケーション傾向・行動パターンの分析・蓄積。
 * 計画書 セクション2「ユーザー性格・行動分析と自動適応」に基づく。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini_helper.php';

/**
 * ユーザープロファイルを取得（なければ初期化）
 */
function getUserAiProfile($userId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM user_ai_profile WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        $stmt = $pdo->prepare("
            INSERT INTO user_ai_profile (user_id, personality_traits, communication_style,
                preferred_topics, avoided_expressions, behavior_patterns, interaction_stats)
            VALUES (?, '{}', '{}', '[]', '[]', '{}', '{}')
        ");
        $stmt->execute([$userId]);
        return getUserAiProfile($userId);
    }

    $profile['personality_traits'] = json_decode($profile['personality_traits'] ?? '{}', true) ?: [];
    $profile['communication_style'] = json_decode($profile['communication_style'] ?? '{}', true) ?: [];
    $profile['preferred_topics'] = json_decode($profile['preferred_topics'] ?? '[]', true) ?: [];
    $profile['avoided_expressions'] = json_decode($profile['avoided_expressions'] ?? '[]', true) ?: [];
    $profile['behavior_patterns'] = json_decode($profile['behavior_patterns'] ?? '{}', true) ?: [];
    $profile['interaction_stats'] = json_decode($profile['interaction_stats'] ?? '{}', true) ?: [];

    return $profile;
}

/**
 * 会話に基づいてプロファイルを増分更新
 *
 * @param int $userId
 * @param string $userMessage ユーザーの発話
 * @param string $aiResponse AIの応答
 * @param bool|null $wasHelpful フィードバック（null=未評価）
 */
function updateProfileFromConversation($userId, $userMessage, $aiResponse, $wasHelpful = null) {
    $profile = getUserAiProfile($userId);

    $stats = $profile['interaction_stats'];
    $stats['total_conversations'] = ($stats['total_conversations'] ?? 0) + 1;
    $stats['avg_message_length'] = calculateRunningAvg(
        $stats['avg_message_length'] ?? 0,
        mb_strlen($userMessage),
        $stats['total_conversations']
    );

    $hour = (int)date('G');
    $hourKey = "hour_{$hour}";
    $stats['activity_hours'][$hourKey] = ($stats['activity_hours'][$hourKey] ?? 0) + 1;

    if ($wasHelpful !== null) {
        $stats['helpful_count'] = ($stats['helpful_count'] ?? 0) + ($wasHelpful ? 1 : 0);
        $stats['feedback_count'] = ($stats['feedback_count'] ?? 0) + 1;
    }

    $topics = $profile['preferred_topics'];
    $extracted = extractTopicHints($userMessage);
    foreach ($extracted as $topic) {
        if (!in_array($topic, $topics)) {
            $topics[] = $topic;
            if (count($topics) > 50) {
                array_shift($topics);
            }
        }
    }

    $commStyle = $profile['communication_style'];
    $msgLen = mb_strlen($userMessage);
    if ($msgLen < 30) {
        $commStyle['brevity_preference'] = min(1.0, ($commStyle['brevity_preference'] ?? 0.5) + 0.02);
    } elseif ($msgLen > 150) {
        $commStyle['brevity_preference'] = max(0.0, ($commStyle['brevity_preference'] ?? 0.5) - 0.02);
    }

    if (preg_match('/です|ます|ございます|いただ/u', $userMessage)) {
        $commStyle['formality'] = min(1.0, ($commStyle['formality'] ?? 0.5) + 0.02);
    } elseif (preg_match('/だよ|じゃん|って|だな|やん/u', $userMessage)) {
        $commStyle['formality'] = max(0.0, ($commStyle['formality'] ?? 0.5) - 0.02);
    }

    $behaviors = $profile['behavior_patterns'];
    if ($hour >= 5 && $hour < 10) {
        $behaviors['morning_active'] = ($behaviors['morning_active'] ?? 0) + 1;
    } elseif ($hour >= 22 || $hour < 5) {
        $behaviors['night_active'] = ($behaviors['night_active'] ?? 0) + 1;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE user_ai_profile SET
            communication_style = ?,
            preferred_topics = ?,
            behavior_patterns = ?,
            interaction_stats = ?,
            profile_version = profile_version + 1,
            last_analyzed_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([
        json_encode($commStyle, JSON_UNESCAPED_UNICODE),
        json_encode($topics, JSON_UNESCAPED_UNICODE),
        json_encode($behaviors, JSON_UNESCAPED_UNICODE),
        json_encode($stats, JSON_UNESCAPED_UNICODE),
        $userId,
    ]);
}

/**
 * 定期的な深い性格分析（バッチまたは一定回数ごと）
 */
function deepAnalyzePersonality($userId) {
    if (!isGeminiAvailable()) {
        return false;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT question, answer FROM ai_conversations
        WHERE user_id = ? ORDER BY created_at DESC LIMIT 30
    ");
    $stmt->execute([$userId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($conversations) < 5) {
        return false;
    }

    $convSummary = '';
    foreach (array_reverse($conversations) as $conv) {
        $q = mb_substr($conv['question'], 0, 200);
        $convSummary .= "ユーザー: {$q}\n";
    }

    $analyzePrompt = <<<PROMPT
以下はユーザーとAI秘書の会話履歴（ユーザー側の発話のみ抜粋）です。
このユーザーの性格特性を分析し、以下のJSON形式で回答してください。

会話履歴:
{$convSummary}

JSONのみ回答してください:
{
  "personality_traits": {
    "decision_style": "cautious または decisive",
    "thinking_style": "data_driven または intuitive",
    "communication_preference": "detailed または concise",
    "emotional_tone": "warm または neutral または professional",
    "proactivity": "proactive または reactive"
  },
  "summary": "このユーザーの性格を1-2文で要約"
}
PROMPT;

    $result = geminiChat($analyzePrompt, [], "あなたは心理分析の専門家です。JSONのみを返してください。");
    if (!$result['success']) {
        return false;
    }

    $responseText = trim($result['response']);
    $responseText = preg_replace('/^```json\s*/i', '', $responseText);
    $responseText = preg_replace('/\s*```$/', '', $responseText);
    $parsed = json_decode($responseText, true);

    if (!$parsed || !isset($parsed['personality_traits'])) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE user_ai_profile SET
            personality_traits = ?,
            profile_version = profile_version + 1,
            last_analyzed_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([
        json_encode($parsed['personality_traits'], JSON_UNESCAPED_UNICODE),
        $userId,
    ]);

    return true;
}

/**
 * プロファイルに基づいて秘書のシステムプロンプトを補強
 */
function buildProfilePromptAddition($userId) {
    $profile = getUserAiProfile($userId);
    $additions = [];

    $comm = $profile['communication_style'];
    $formality = $comm['formality'] ?? 0.5;
    if ($formality > 0.7) {
        $additions[] = 'ユーザーは丁寧な表現を好みます。敬語を基本としてください。';
    } elseif ($formality < 0.3) {
        $additions[] = 'ユーザーはカジュアルな表現を好みます。砕けた口調で構いません。';
    }

    $brevity = $comm['brevity_preference'] ?? 0.5;
    if ($brevity > 0.7) {
        $additions[] = 'ユーザーは端的な回答を好みます。要点を簡潔に伝えてください。';
    } elseif ($brevity < 0.3) {
        $additions[] = 'ユーザーは詳細な説明を好みます。背景や理由も含めて丁寧に回答してください。';
    }

    $traits = $profile['personality_traits'];
    if (!empty($traits['decision_style'])) {
        if ($traits['decision_style'] === 'cautious') {
            $additions[] = 'ユーザーは慎重に判断するタイプです。選択肢やリスクも提示してください。';
        } else {
            $additions[] = 'ユーザーは即断タイプです。結論を先に伝えてください。';
        }
    }

    $topics = $profile['preferred_topics'];
    if (!empty($topics)) {
        $topTopics = array_slice($topics, -5);
        $additions[] = '最近の関心トピック: ' . implode(', ', $topTopics);
    }

    if (empty($additions)) {
        return '';
    }

    return "\n\n【ユーザーの傾向（自動学習済み）】\n" . implode("\n", $additions);
}

/**
 * ユーザーの性格分析スナップショットを取得（通報用）
 */
function getPersonalitySnapshot($userId) {
    $profile = getUserAiProfile($userId);
    return [
        'personality_traits'    => $profile['personality_traits'],
        'communication_style'   => $profile['communication_style'],
        'behavior_patterns'     => $profile['behavior_patterns'],
        'interaction_stats'     => $profile['interaction_stats'],
        'profile_version'       => (int)$profile['profile_version'],
        'last_analyzed_at'      => $profile['last_analyzed_at'],
    ];
}

/**
 * 発話からトピックヒントを抽出（簡易版）
 */
function extractTopicHints($message) {
    $topicPatterns = [
        'スケジュール' => '/予定|スケジュール|カレンダー|会議/u',
        'タスク'       => '/タスク|作業|やること|TODO/u',
        '報告'         => '/報告|レポート|進捗|状況/u',
        '経費'         => '/経費|請求|支払|予算|コスト/u',
        '顧客'         => '/顧客|お客様|取引先|商談|クライアント/u',
        '教育'         => '/研修|教育|トレーニング|学習|新人/u',
        '健康'         => '/体調|健康|疲れ|ストレス|休暇/u',
    ];

    $found = [];
    foreach ($topicPatterns as $topic => $pattern) {
        if (preg_match($pattern, $message)) {
            $found[] = $topic;
        }
    }
    return $found;
}

/**
 * 移動平均の計算
 */
function calculateRunningAvg($currentAvg, $newValue, $totalCount) {
    if ($totalCount <= 1) return $newValue;
    return $currentAvg + ($newValue - $currentAvg) / $totalCount;
}
