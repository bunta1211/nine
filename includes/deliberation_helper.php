<?php
/**
 * 熟慮モード ヘルパー
 * Gemini の Google Search grounding を利用し、
 * 検索→推論→実行の各段階を逐次ログとして記録する
 */

require_once __DIR__ . '/../config/ai_config.php';

/**
 * 熟慮ステップをログファイルに書き出す
 */
function deliberationLog($sessionId, $phase, $message) {
    $logDir = sys_get_temp_dir() . '/social9_delib';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = json_encode([
        'phase'   => $phase,
        'message' => $message,
        'time'    => date('H:i:s')
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents("{$logDir}/{$sessionId}.jsonl", $entry, FILE_APPEND | LOCK_EX);
}

/**
 * 熟慮ログを読み取る
 */
function deliberationReadLog($sessionId, $afterLine = 0) {
    $path = sys_get_temp_dir() . "/social9_delib/{$sessionId}.jsonl";
    if (!file_exists($path)) return ['lines' => [], 'total' => 0];
    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total = count($all);
    $lines = [];
    for ($i = $afterLine; $i < $total; $i++) {
        $decoded = json_decode($all[$i], true);
        if ($decoded) $lines[] = $decoded;
    }
    return ['lines' => $lines, 'total' => $total];
}

/**
 * 熟慮セッションを完了としてマーク
 */
function deliberationComplete($sessionId, $result) {
    deliberationLog($sessionId, 'done', 'completed');
    $logDir = sys_get_temp_dir() . '/social9_delib';
    @file_put_contents("{$logDir}/{$sessionId}.result.json",
        json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 熟慮結果を読み取る
 */
function deliberationReadResult($sessionId) {
    $path = sys_get_temp_dir() . "/social9_delib/{$sessionId}.result.json";
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

/**
 * 熟慮セッションファイルを掃除
 */
function deliberationCleanup($sessionId) {
    $logDir = sys_get_temp_dir() . '/social9_delib';
    @unlink("{$logDir}/{$sessionId}.jsonl");
    @unlink("{$logDir}/{$sessionId}.result.json");
}

/**
 * Gemini で Google Search grounding 付きリクエスト
 */
function geminiWithSearch($prompt, $systemPrompt = null, $maxSeconds = 180) {
    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
        return ['success' => false, 'error' => 'Gemini APIキーが未設定です'];
    }

    $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

    $contents = [];
    if ($systemPrompt) {
        $contents[] = ['role' => 'user',  'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'はい、指示に従います。']]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

    $body = [
        'contents' => $contents,
        'tools'    => [['googleSearch' => new \stdClass()]],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 4096
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => min($maxSeconds + 30, 1830),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => "cURL error: {$curlErr}"];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !$data) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => $errMsg];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $groundingMeta = $data['candidates'][0]['groundingMetadata'] ?? null;
    $searchQueries = [];
    if ($groundingMeta && isset($groundingMeta['webSearchQueries'])) {
        $searchQueries = $groundingMeta['webSearchQueries'];
    }

    return [
        'success'        => true,
        'response'       => $text,
        'search_queries' => $searchQueries,
        'grounding'      => $groundingMeta
    ];
}

/**
 * 熟慮モード実行（メイン）
 *
 * @param string $question ユーザーの質問
 * @param string $sessionId セッションID
 * @param int $maxSeconds 最大秒数
 * @param string|null $purpose 目的（'personality' など）
 * @param array $context 追加コンテキスト
 * @return array 結果
 */
function runDeliberation($question, $sessionId, $maxSeconds = 180, $purpose = null, $context = []) {
    $startTime = time();
    $deadline  = $startTime + $maxSeconds;

    deliberationLog($sessionId, 'search', "「{$question}」について調査を開始します");

    $searchPrompt = "以下の質問について、最新のウェブ情報を検索して詳しく調べてください。\n\n質問: {$question}";
    if (!empty($context['extra_prompt'])) {
        $searchPrompt .= "\n\n追加情報: " . $context['extra_prompt'];
    }

    $systemPrompt = 'あなたは高度なリサーチアシスタントです。Google検索を活用して正確で最新の情報を収集し、整理された形で報告してください。';

    deliberationLog($sessionId, 'search', 'ウェブを検索しています...');

    $searchResult = geminiWithSearch($searchPrompt, $systemPrompt, min($maxSeconds, 120));

    if (!$searchResult['success']) {
        deliberationLog($sessionId, 'error', '検索に失敗: ' . ($searchResult['error'] ?? ''));
        deliberationComplete($sessionId, [
            'success' => false,
            'answer'  => '調査中にエラーが発生しました: ' . ($searchResult['error'] ?? '不明なエラー')
        ]);
        return ['success' => false, 'answer' => '調査中にエラーが発生しました。'];
    }

    if (!empty($searchResult['search_queries'])) {
        foreach ($searchResult['search_queries'] as $sq) {
            deliberationLog($sessionId, 'search', "検索キーワード: 「{$sq}」");
        }
    }

    deliberationLog($sessionId, 'think', '取得した情報を分析・整理しています...');

    if (time() >= $deadline) {
        deliberationLog($sessionId, 'think', '時間切れです。ここまでの結果で回答します。');
        deliberationComplete($sessionId, [
            'success' => true,
            'answer'  => $searchResult['response'],
            'timed_out' => true
        ]);
        return ['success' => true, 'answer' => $searchResult['response'], 'timed_out' => true];
    }

    if ($purpose === 'personality') {
        deliberationLog($sessionId, 'think', '性格設定として最適な項目を生成しています...');
        $personalityPrompt = "以下の調査結果をもとに、AIアシスタントの性格設定を生成してください。\n\n"
            . "調査結果:\n{$searchResult['response']}\n\n"
            . "以下のJSON形式で出力してください（各項目は日本語、各200文字以内）:\n"
            . '{"pronoun":"一人称と呼び方","tone":"話し方・口調","character":"性格・態度","expertise":"得意分野・知識","behavior":"行動スタイル","avoid":"禁止事項","other":"その他"}' . "\n"
            . "JSONのみ出力し、説明は不要です。";

        $personalityResult = geminiWithSearch($personalityPrompt, null, max($deadline - time(), 30));

        if ($personalityResult['success']) {
            $raw = trim($personalityResult['response']);
            $raw = preg_replace('/^```json\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                deliberationLog($sessionId, 'execute', '性格設定の生成が完了しました');
                deliberationComplete($sessionId, [
                    'success' => true,
                    'answer'  => $searchResult['response'],
                    'personality' => $parsed,
                    'purpose' => 'personality'
                ]);
                return ['success' => true, 'answer' => $searchResult['response'], 'personality' => $parsed];
            }
        }
    }

    deliberationLog($sessionId, 'think', '最終的な回答をまとめています...');

    $remainSec = max($deadline - time(), 30);
    $summaryPrompt = "以下の情報をもとに、ユーザーの質問に対する包括的な回答を作成してください。\n\n"
        . "ユーザーの質問: {$question}\n\n"
        . "調査結果:\n{$searchResult['response']}\n\n"
        . "回答は分かりやすく構造化してください。";

    $summaryResult = geminiWithSearch($summaryPrompt, null, $remainSec);
    $finalAnswer = $summaryResult['success'] ? $summaryResult['response'] : $searchResult['response'];

    deliberationLog($sessionId, 'execute', '回答の作成が完了しました');
    deliberationComplete($sessionId, [
        'success' => true,
        'answer'  => $finalAnswer
    ]);

    return ['success' => true, 'answer' => $finalAnswer];
}
