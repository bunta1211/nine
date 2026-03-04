<?php
/**
 * Gemini API ヘルパー
 * Google Gemini（無料枠）を使用したAI機能
 */

require_once __DIR__ . '/../config/ai_config.php';

/**
 * Gemini APIが利用可能かチェック
 */
function isGeminiAvailable() {
    return defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY);
}

/**
 * Gemini APIでチャット応答を生成
 * 
 * @param string $userMessage ユーザーのメッセージ
 * @param array $conversationHistory 会話履歴（オプション）
 * @param string|null $systemPrompt システムプロンプト（オプション）
 * @param string|null $imagePath 画像またはPDFのパス（オプション。スキャンPDF・写真も可。例: uploads/2025/02/xxx.png）
 * @return array ['success' => bool, 'response' => string, 'error' => string|null]
 */
function geminiChat($userMessage, $conversationHistory = [], $systemPrompt = null, $imagePath = null) {
    if (!isGeminiAvailable()) {
        return [
            'success' => false,
            'response' => null,
            'error' => 'Gemini APIキーが設定されていません'
        ];
    }
    
    // デフォルトのシステムプロンプト
    if ($systemPrompt === null) {
        $systemPrompt = "あなたはSocial9というコミュニケーションアプリのアシスタントです。
ユーザーからの質問に対して、親切で分かりやすく回答してください。
以下のような質問に対応できます：
- アプリの使い方（メッセージ送信、グループ作成、通話など）
- 設定の変更方法
- トラブルシューティング
- 一般的な質問

回答は日本語で、簡潔かつ丁寧に行ってください。
技術的すぎる表現は避け、初心者にも分かりやすい言葉を使ってください。";
    }
    
    // Gemini API用のコンテンツを構築
    $contents = [];
    
    // システムプロンプトを最初のユーザーメッセージとして追加
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $systemPrompt]]
    ];
    $contents[] = [
        'role' => 'model',
        'parts' => [['text' => 'はい、Social9のアシスタントとしてお手伝いします。ご質問をお聞かせください。']]
    ];
    
    // 会話履歴を追加
    foreach ($conversationHistory as $msg) {
        $role = $msg['role'] === 'user' ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => $msg['content']]]
        ];
    }
    
    // 現在のメッセージを追加（画像がある場合は画像＋テキスト）
    $currentParts = [];
    if (!empty($imagePath)) {
        $imagePath = trim($imagePath);
        $candidates = [];
        if ($imagePath[0] === '/' || preg_match('#^[A-Za-z]:#', $imagePath)) {
            $candidates[] = $imagePath;
        } else {
            $base = rtrim(str_replace('\\', '/', __DIR__ . '/../'), '/');
            $candidates[] = $base . '/' . ltrim($imagePath, '/');
            if (defined('UPLOAD_DIR') && is_string(UPLOAD_DIR)) {
                $uploadBase = rtrim(str_replace('\\', '/', dirname(UPLOAD_DIR)), '/');
                $candidates[] = $uploadBase . '/' . ltrim($imagePath, '/');
                if (preg_match('#^uploads/#', $imagePath)) {
                    $uploadDir = rtrim(str_replace('\\', '/', UPLOAD_DIR), '/');
                    $candidates[] = $uploadDir . '/' . preg_replace('#^uploads/#', '', $imagePath);
                }
            }
            if (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
                $candidates[] = $docRoot . '/' . ltrim($imagePath, '/');
            }
        }
        $fullPath = null;
        foreach ($candidates as $p) {
            $resolved = realpath($p);
            if ($resolved && file_exists($resolved) && is_readable($resolved)) {
                $fullPath = $resolved;
                break;
            }
        }
        if ($fullPath) {
            $mime = @mime_content_type($fullPath);
            if ($mime && (strpos($mime, 'image/') === 0 || $mime === 'application/pdf')) {
                // 画像またはPDFをそのまま送信（GeminiはPDFを解釈可能）
            } else {
                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                if ($ext === 'pdf') {
                    $mime = 'application/pdf';
                } else {
                    $mime = 'image/png';
                }
            }
            $base64 = base64_encode(file_get_contents($fullPath));
            $currentParts[] = [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => $base64
                ]
            ];
        } else {
            error_log('Gemini image not found: ' . $imagePath . ' tried: ' . implode(', ', $candidates));
        }
    }
    $textPart = trim($userMessage) !== '' ? $userMessage : 'この画像について説明してください。';
    $currentParts[] = ['text' => $textPart];
    $contents[] = [
        'role' => 'user',
        'parts' => $currentParts
    ];
    
    // Gemini APIモデル（無料枠で使用可能）
    $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash';
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;
    
    $requestBody = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 1024,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ]
    ];
    
    // cURL でリクエスト
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Gemini API cURL error: " . $curlError);
        return [
            'success' => false,
            'response' => null,
            'error' => '通信エラーが発生しました'
        ];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errorMessage = $data['error']['message'] ?? 'APIエラー';
        error_log("Gemini API error ({$httpCode}): " . $errorMessage . " | Response: " . substr($response, 0, 500));
        
        // レート制限の場合
        if ($httpCode === 429) {
            return [
                'success' => false,
                'response' => null,
                'error' => '現在リクエストが集中しています。少し待ってからお試しください。'
            ];
        }
        
        // APIキーエラーの場合
        if ($httpCode === 400 || $httpCode === 401 || $httpCode === 403) {
            return [
                'success' => false,
                'response' => null,
                'error' => 'APIキーエラー: ' . $errorMessage
            ];
        }
        
        return [
            'success' => false,
            'response' => null,
            'error' => 'AIサービスに接続できませんでした (HTTP ' . $httpCode . '): ' . $errorMessage
        ];
    }
    
    // 応答を抽出
    $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if (!$responseText) {
        // セーフティフィルターでブロックされた可能性
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'SAFETY') {
            return [
                'success' => false,
                'response' => null,
                'error' => '回答を生成できませんでした。別の質問をお試しください。'
            ];
        }
        
        return [
            'success' => false,
            'response' => null,
            'error' => '応答を取得できませんでした'
        ];
    }
    
    return [
        'success' => true,
        'response' => $responseText,
        'error' => null
    ];
}

/**
 * Gemini APIでテキストを翻訳
 * 
 * @param string $text 翻訳するテキスト
 * @param string $targetLang 翻訳先言語コード
 * @param string $sourceLang 翻訳元言語コード（オプション）
 * @return array ['success' => bool, 'translation' => string, 'error' => string|null]
 */
function geminiTranslate($text, $targetLang, $sourceLang = null) {
    if (!isGeminiAvailable()) {
        return [
            'success' => false,
            'translation' => null,
            'error' => 'Gemini APIキーが設定されていません'
        ];
    }
    
    $langNames = [
        'ja' => '日本語',
        'en' => '英語',
        'zh-CN' => '中国語（簡体字）',
        'zh-TW' => '中国語（繁体字）',
        'ko' => '韓国語',
        'es' => 'スペイン語',
        'fr' => 'フランス語',
        'de' => 'ドイツ語',
        'pt' => 'ポルトガル語',
        'vi' => 'ベトナム語',
        'th' => 'タイ語',
    ];
    
    $targetLangName = $langNames[$targetLang] ?? $targetLang;
    
    $prompt = "以下のテキストを{$targetLangName}に翻訳してください。翻訳結果のみを返してください。\n\n{$text}";
    
    $result = geminiChat($prompt, [], "あなたは優秀な翻訳者です。自然で正確な翻訳を提供してください。翻訳結果のみを返し、説明は不要です。");
    
    if ($result['success']) {
        return [
            'success' => true,
            'translation' => trim($result['response']),
            'error' => null
        ];
    }
    
    return [
        'success' => false,
        'translation' => null,
        'error' => $result['error']
    ];
}

/**
 * スプレッドシート編集指示を構造化データに変換（Gemini）
 * @param string $instruction ユーザーの指示（例: A1に売上を入れて）
 * @param array $currentRows 現在のシートの範囲データ（2次元配列）
 * @param string $sheetName シート名（例: Sheet1）
 * @return array|null ['range' => 'Sheet1!A1:B2', 'values' => [['a','b'],['c','d']]] または null
 */
function geminiParseSheetEditInstruction($instruction, array $currentRows, $sheetName = 'Sheet1') {
    if (!isGeminiAvailable()) {
        return null;
    }
    $context = "現在のシート内容（行データ）:\n" . json_encode($currentRows, JSON_UNESCAPED_UNICODE);
    $prompt = "ユーザー指示: {$instruction}\n\n上記の現在内容を踏まえ、指示どおりに編集するための「範囲」と「値」を決めてください。\n"
        . "応答は次のJSON形式のみを1つ返してください。説明や改行は不要。\n"
        . "{\"range\": \"シート名!開始セル:終了セル（例: Sheet1!A1:C2）\", \"values\": [[セル1, セル2, ...], [2行目...]]}\n"
        . "シート名は「{$sheetName}」を使用してください。値は文字列の2次元配列です。";
    $result = geminiChat($context . "\n\n" . $prompt, [], "あなたはスプレッドシートの編集指示をJSONに変換するアシスタントです。指定された形式のJSONのみを返します。");
    if (!$result['success'] || empty($result['response'])) {
        return null;
    }
    $text = trim($result['response']);
    // マークダウンコードブロックを除去
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $text = $m[0];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && !empty($decoded['range']) && isset($decoded['values']) && is_array($decoded['values'])) {
        return $decoded;
    }
    return null;
}

/**
 * Excel編集指示を構造化データに変換（Gemini）
 * @return array|null [['range' => 'A1:B2', 'values' => [...]], ...]
 */
function geminiParseExcelEditInstruction($instruction, array $currentRows) {
    if (!isGeminiAvailable()) {
        return null;
    }
    $context = "現在のシート内容（行データ）:\n" . json_encode($currentRows, JSON_UNESCAPED_UNICODE);
    $prompt = "ユーザー指示: {$instruction}\n\n上記の現在内容を踏まえ、指示どおりに編集するための「範囲」と「値」のリストを決めてください。\n"
        . "応答は次のJSON形式のみを1つ返してください。\n"
        . "{\"updates\": [{\"range\": \"A1:C2\", \"values\": [[\"a\",\"b\",\"c\"],[\"d\",\"e\",\"f\"]]}]}\n"
        . "range は Excel 形式（例: A1, B2:C5）。values は文字列の2次元配列。";
    $result = geminiChat($context . "\n\n" . $prompt, [], "あなたはExcelの編集指示をJSONに変換するアシスタントです。指定された形式のJSONのみを返します。");
    if (!$result['success'] || empty($result['response'])) {
        return null;
    }
    $text = trim($result['response']);
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $text = $m[0];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && !empty($decoded['updates']) && is_array($decoded['updates'])) {
        return $decoded['updates'];
    }
    return null;
}

/**
 * Word編集指示を置換リストに変換（Gemini）
 * @return array|null [['search' => '旧', 'replace' => '新'], ...]
 */
function geminiParseWordEditInstruction($instruction, string $currentText) {
    if (!isGeminiAvailable()) {
        return null;
    }
    $context = "現在の文書テキスト（抜粋）:\n" . mb_substr($currentText, 0, 4000);
    $prompt = "ユーザー指示: {$instruction}\n\n上記の現在内容を踏まえ、指示どおりに編集するための「検索文字列」と「置換文字列」のリストを決めてください。\n"
        . "応答は次のJSON形式のみを1つ返してください。\n"
        . "{\"replacements\": [{\"search\": \"検索する文字列\", \"replace\": \"置換後の文字列\"}]}\n";
    $result = geminiChat($context . "\n\n" . $prompt, [], "あなたはWord文書の編集指示をJSONに変換するアシスタントです。指定された形式のJSONのみを返します。");
    if (!$result['success'] || empty($result['response'])) {
        return null;
    }
    $text = trim($result['response']);
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $text = $m[0];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && isset($decoded['replacements']) && is_array($decoded['replacements'])) {
        return $decoded['replacements'];
    }
    return null;
}

/**
 * ai_usage_logs テーブルが存在しなければ作成する（使用量可視化のため）
 */
function ensureAiUsageLogsTable(PDO $pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_usage_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(20) DEFAULT 'gemini',
                feature VARCHAR(50),
                input_chars INT UNSIGNED DEFAULT 0,
                output_chars INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                INDEX idx_feature (feature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("ensureAiUsageLogsTable: " . $e->getMessage());
    }
}

/**
 * Gemini APIの使用状況をログに記録
 * config/ai_config.php の AI_USAGE_LOGGING_ENABLED が false の場合は記録しない
 * テーブルが無い場合は自動作成してから記録する
 */
function logGeminiUsage($pdo, $userId, $feature, $inputLength, $outputLength) {
    if (defined('AI_USAGE_LOGGING_ENABLED') && !AI_USAGE_LOGGING_ENABLED) {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_usage_logs (user_id, provider, feature, input_chars, output_chars, created_at)
            VALUES (?, 'gemini', ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $feature, (int)$inputLength, (int)$outputLength]);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, "doesn't exist") !== false || (method_exists($e, 'getCode') && $e->getCode() === '42S02')) {
            ensureAiUsageLogsTable($pdo);
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ai_usage_logs (user_id, provider, feature, input_chars, output_chars, created_at)
                    VALUES (?, 'gemini', ?, ?, ?, NOW())
                ");
                $stmt->execute([$userId, $feature, (int)$inputLength, (int)$outputLength]);
            } catch (Exception $e2) {
                error_log("AI usage log retry error: " . $e2->getMessage());
            }
        } else {
            error_log("AI usage log error: " . $msg);
        }
    }
}
