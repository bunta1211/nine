<?php
/**
 * AI Wish抽出機能
 * パターンマッチングでは抽出できないWishをAIで分析
 * 
 * 対応API:
 * - OpenAI GPT-3.5-turbo (推奨: 安価で高精度)
 * - Google Gemini (無料枠あり)
 */

/**
 * AIを使用してメッセージからWishを抽出
 * 
 * @param string $message 分析対象のメッセージ
 * @param string $language 言語コード (ja, en, zh)
 * @param bool $forceAnalysis 強制的にAI分析を行うか（長文の場合など）
 * @return array|null 抽出されたWish情報、または null
 */
function extractWishWithAI($message, $language = 'ja', $forceAnalysis = false) {
    // APIキーの取得（defineされた値を優先）
    $openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : getenv('OPENAI_API_KEY');
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
    
    // 長文判定（100文字以上）
    $isLongText = mb_strlen($message) >= 100;
    
    // Gemini優先（無料枠があるため）、なければOpenAI
    if (!empty($geminiKey)) {
        return extractWithGemini($message, $geminiKey, $language, $isLongText);
    } elseif (!empty($openaiKey)) {
        return extractWithOpenAI($message, $openaiKey, $language, $isLongText);
    }
    
    // APIキーがない場合はnull
    return null;
}

/**
 * メッセージが長文かどうかを判定
 * @param string $message メッセージ
 * @return bool 長文の場合true
 */
function isLongMessage($message) {
    return mb_strlen($message) >= 100;
}

/**
 * OpenAI GPT-3.5-turboを使用してWishを抽出
 */
function extractWithOpenAI($message, $apiKey, $language, $isLongText = false) {
    $prompt = getExtractionPrompt($message, $language, $isLongText);
    
    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a wish/desire extraction assistant. Extract wishes, desires, requests, or tasks from the given message. Respond in JSON format only.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 500
    ];
    
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("OpenAI API error: HTTP $httpCode - $response");
        return null;
    }
    
    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        return null;
    }
    
    return parseAIResponse($result['choices'][0]['message']['content']);
}

/**
 * Google Gemini APIを使用してWishを抽出
 */
function extractWithGemini($message, $apiKey, $language, $isLongText = false) {
    $prompt = getExtractionPrompt($message, $language, $isLongText);
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 500
        ]
    ];
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Gemini API error: HTTP $httpCode - $response");
        return null;
    }
    
    $result = json_decode($response, true);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return null;
    }
    
    return parseAIResponse($result['candidates'][0]['content']['parts'][0]['text']);
}

/**
 * Wish抽出用プロンプトを生成
 * @param string $message メッセージ
 * @param string $language 言語コード
 * @param bool $isLongText 長文かどうか
 */
function getExtractionPrompt($message, $language, $isLongText = false) {
    $langName = [
        'ja' => 'Japanese',
        'en' => 'English',
        'zh' => 'Chinese'
    ][$language] ?? 'Japanese';
    
    // 長文の場合は要約型プロンプトを使用
    if ($isLongText) {
        return <<<PROMPT
You are an expert at understanding the true intentions and desires hidden in messages.

Analyze this message carefully and identify what the person TRULY wants or wishes for.
Focus on the underlying desire, not surface-level statements.

Message:
"{$message}"

Instructions:
1. Read the entire message to understand the context
2. Identify the ONE most important wish/desire/need the person has
3. Summarize it as a clear, actionable wish (15-30 characters in {$langName})
4. If this is a business report or routine communication with no real personal wish, return has_wish: false

Respond ONLY with this JSON format:
{
  "has_wish": true/false,
  "wishes": [
    {
      "text": "要約されたWish（{$langName}で15-30文字）",
      "category": "desire/request/need/plan/purchase/learn/improve/social/travel/problem/other",
      "confidence": 0.0-1.0,
      "reasoning": "Brief explanation of why this is the core wish"
    }
  ]
}

Examples of good wish extraction:
- "仕事が忙しくて家族との時間が取れない..." → "家族との時間を増やしたい"
- "最近健康診断の結果が悪くて..." → "健康を改善したい"
- "英語が話せたらもっと仕事の幅が広がるのに..." → "英語を習得したい"

Important:
- Extract ONLY 1 wish (the most important one)
- If it's just a status report with no wish, set has_wish to false
- The wish text should be concise and actionable
PROMPT;
    }
    
    // 短文の場合は従来のプロンプト
    return <<<PROMPT
Analyze the following message and extract any wishes, desires, requests, needs, or tasks.

Message: "{$message}"

If you find any wish/desire/request/need/task, respond with JSON in this exact format:
{
  "has_wish": true,
  "wishes": [
    {
      "text": "the extracted wish in {$langName}",
      "category": "one of: desire, request, need, plan, purchase, learn, improve, social, travel, problem, other",
      "confidence": 0.0-1.0
    }
  ]
}

If no wish/desire/request is found, respond with:
{
  "has_wish": false,
  "wishes": []
}

Important rules:
1. Extract the core wish/desire, not the entire message
2. Convert problems into wishes (e.g., "my computer is broken" → "fix/repair computer")
3. Only extract 1 wish (the most important one)
4. Only include wishes with confidence > 0.5
5. Respond with JSON only, no additional text
PROMPT;
}

/**
 * AIレスポンスをパース
 */
function parseAIResponse($responseText) {
    // JSONを抽出（テキストに囲まれている場合があるため）
    if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
        $json = $matches[0];
    } else {
        $json = $responseText;
    }
    
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['has_wish'])) {
        return null;
    }
    
    if (!$data['has_wish'] || empty($data['wishes'])) {
        return ['has_wish' => false, 'wishes' => []];
    }
    
    // 信頼度でフィルタリング
    $validWishes = array_filter($data['wishes'], function($w) {
        return isset($w['confidence']) && $w['confidence'] >= 0.5;
    });
    
    return [
        'has_wish' => !empty($validWishes),
        'wishes' => array_values($validWishes)
    ];
}

/**
 * AI APIが利用可能かチェック
 */
function isAIExtractionAvailable() {
    $openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : getenv('OPENAI_API_KEY');
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
    
    return !empty($openaiKey) || !empty($geminiKey);
}

/**
 * 利用中のAIサービス名を取得
 */
function getActiveAIService() {
    $openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : getenv('OPENAI_API_KEY');
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
    
    // Gemini優先
    if (!empty($geminiKey)) {
        return 'Google Gemini';
    } elseif (!empty($openaiKey)) {
        return 'OpenAI GPT-3.5-turbo';
    }
    
    return null;
}

