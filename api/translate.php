<?php
/**
 * 翻訳API
 * 
 * 機能:
 * - ChatGPT API (GPT-4o) による高品質翻訳
 * - 3日以内のメッセージは自動翻訳
 * - 月額3万円の予算制限付き
 * - 予算超過時は手動翻訳モードに切替
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? 'translate';

// 例外時も必ずJSONで返す（フロントの "Unexpected end of JSON input" を防ぐ）
try {

// サポートする言語
$supported_languages = [
    'ja' => '日本語',
    'en' => 'English',
    'zh' => '中文',
    'zh-CN' => '中文（简体）',
    'zh-TW' => '中文（繁體）',
    'ko' => '한국어',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'pt' => 'Português',
    'it' => 'Italiano',
    'ru' => 'Русский',
    'ar' => 'العربية',
    'hi' => 'हिन्दी',
    'th' => 'ไทย',
    'vi' => 'Tiếng Việt'
];

switch ($action) {
    case 'translate':
        // テキストを翻訳
        $text = trim($input['text'] ?? '');
        $source_lang = $input['source_lang'] ?? 'auto';
        $target_lang = $input['target_lang'] ?? 'ja';
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : null;
        $is_auto = isset($input['is_auto']) && $input['is_auto'];
        
        if (empty($text)) {
            errorResponse('翻訳するテキストを入力してください');
        }
        
        // 画像のみのメッセージは翻訳せずそのまま返す（APIがエラーを返すのを防ぐ）
        if (isImageOnlyContent($text)) {
            successResponse([
                'translated_text' => $text,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'cached' => false,
                'provider' => 'skip_image'
            ]);
        }
        
        if (mb_strlen($text) > 5000) {
            errorResponse('テキストが長すぎます（5000文字以内）');
        }
        
        if ($target_lang !== 'auto' && !isset($supported_languages[$target_lang])) {
            errorResponse('サポートされていない言語です');
        }
        
        // 自動翻訳の場合、予算チェック
        if ($is_auto) {
            $budgetStatus = checkTranslationBudget($pdo);
            if (!$budgetStatus['allowed']) {
                successResponse([
                    'translated_text' => null,
                    'budget_exceeded' => true,
                    'message' => '月間予算に達したため、手動翻訳をご利用ください'
                ]);
            }
        }
        
        // メッセージ翻訳キャッシュをチェック（テーブルが無くても翻訳は続行）
        $cached = null;
        if ($message_id) {
            try {
                $stmt = $pdo->prepare("
                    SELECT translated_text FROM message_translations
                    WHERE message_id = ? AND target_lang = ?
                ");
                $stmt->execute([$message_id, $target_lang]);
                $cached = $stmt->fetch();
                if ($cached) {
                    successResponse([
                        'translated_text' => $cached['translated_text'],
                        'source_lang' => $source_lang,
                        'target_lang' => $target_lang,
                        'cached' => true,
                        'provider' => 'cache'
                    ]);
                }
            } catch (Throwable $e) {
                error_log('translate.php message_translations: ' . $e->getMessage());
            }
        }
        
        // 通常キャッシュをチェック
        $cache_key = md5($text . $source_lang . $target_lang);
        if (!$cached) {
            try {
                $stmt = $pdo->prepare("
                    SELECT translated_text FROM translation_cache
                    WHERE cache_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                $stmt->execute([$cache_key]);
                $cached = $stmt->fetch();
                if ($cached) {
                    if ($message_id) {
                        saveMessageTranslation($pdo, $message_id, $target_lang, $cached['translated_text'], 'cache');
                    }
                    successResponse([
                        'translated_text' => $cached['translated_text'],
                        'source_lang' => $source_lang,
                        'target_lang' => $target_lang,
                        'cached' => true,
                        'provider' => 'cache'
                    ]);
                }
            } catch (Throwable $e) {
                error_log('translate.php translation_cache read: ' . $e->getMessage());
            }
        }
        
        // 翻訳を実行
        $provider = defined('TRANSLATION_PROVIDER') ? TRANSLATION_PROVIDER : 'openai';
        
        if ($provider === 'openai' && defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            $result = translateWithOpenAI($text, $source_lang, $target_lang);
        } else {
            // フォールバック: Google翻訳
            $result = [
                'translated_text' => translateWithGoogle($text, $source_lang, $target_lang),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'provider' => 'google'
            ];
        }
        
        if ($result['translated_text'] === false) {
            errorResponse('翻訳に失敗しました。しばらくしてから再度お試しください。');
        }
        
        // キャッシュに保存（テーブルが無くても翻訳結果は返す）
        try {
            $pdo->prepare("
                INSERT INTO translation_cache (cache_key, original_text, translated_text, source_lang, target_lang)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), created_at = NOW()
            ")->execute([$cache_key, $text, $result['translated_text'], $source_lang, $target_lang]);
        } catch (Throwable $e) {
            error_log('translate.php translation_cache write: ' . $e->getMessage());
        }
        
        // メッセージ翻訳キャッシュに保存
        if ($message_id) {
            saveMessageTranslation($pdo, $message_id, $target_lang, $result['translated_text'], $result['provider']);
        }
        
        // 使用量を記録
        $cost = recordTranslationUsage(
            $pdo, 
            $user_id, 
            mb_strlen($text),
            $result['input_tokens'] ?? 0,
            $result['output_tokens'] ?? 0,
            $source_lang, 
            $target_lang, 
            $result['provider'],
            $message_id
        );
        
        successResponse([
            'translated_text' => $result['translated_text'],
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'cached' => false,
            'provider' => $result['provider'],
            'cost_usd' => $cost
        ]);
        break;
        
    case 'budget_status':
        // 予算状況を取得（例外時も200で返し500を防ぐ）
        try {
            $status = checkTranslationBudget($pdo);
            successResponse($status);
        } catch (Throwable $e) {
            error_log('translate.php budget_status: ' . $e->getMessage());
            successResponse([
                'allowed' => true,
                'current_cost_usd' => 0,
                'current_cost_jpy' => 0,
                'budget_jpy' => 30000,
                'remaining_jpy' => 30000,
                'usage_percent' => 0,
                'auto_translation_enabled' => true,
                'auto_translation_days' => 3,
                'error' => 'Budget check unavailable'
            ]);
        }
        break;
        
    case 'auto_translate_messages':
        // 複数メッセージを一括自動翻訳（1リクエストあたり上限あり＝体感遅延を防ぐ）
        $message_ids = $input['message_ids'] ?? [];
        $target_lang = $input['target_lang'] ?? 'ja';
        
        if (empty($message_ids) || !is_array($message_ids)) {
            errorResponse('メッセージIDが必要です');
        }
        
        $max_per_request = defined('AUTO_TRANSLATE_BATCH_SIZE') ? (int)AUTO_TRANSLATE_BATCH_SIZE : 20;
        $max_per_request = max(5, min(50, $max_per_request));
        $message_ids = array_slice(array_values($message_ids), 0, $max_per_request);
        
        // 予算チェック
        $budgetStatus = checkTranslationBudget($pdo);
        if (!$budgetStatus['allowed']) {
            successResponse([
                'translations' => [],
                'budget_exceeded' => true
            ]);
        }
        
        $translations = [];
        
        foreach ($message_ids as $msg_id) {
            $msg_id = (int)$msg_id;
            
            // キャッシュ確認（テーブルが無くても続行）
            $cached = null;
            try {
                $stmt = $pdo->prepare("
                    SELECT translated_text FROM message_translations
                    WHERE message_id = ? AND target_lang = ?
                ");
                $stmt->execute([$msg_id, $target_lang]);
                $cached = $stmt->fetch();
            } catch (Throwable $e) {
                error_log('translate.php auto_translate message_translations: ' . $e->getMessage());
            }
            if ($cached) {
                $translations[$msg_id] = $cached['translated_text'];
                continue;
            }
            
            try {
                // メッセージ内容を取得（source_lang は本番で未追加の可能性があるため SELECT しない）
                $stmt = $pdo->prepare("
                    SELECT m.content
                    FROM messages m
                    INNER JOIN conversation_members cm ON m.conversation_id = cm.conversation_id
                    WHERE m.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
                ");
                $stmt->execute([$msg_id, $user_id]);
                $message = $stmt->fetch();
                
                if (!$message || empty($message['content'])) {
                    continue;
                }
                
                if (isImageOnlyContent($message['content'])) {
                    $translations[$msg_id] = $message['content'];
                    continue;
                }
                
                $msgLang = detectLanguage($message['content']);
                if (normalizeLanguage($msgLang) === normalizeLanguage($target_lang)) {
                    $translations[$msg_id] = $message['content'];
                    continue;
                }
                
                $provider = defined('TRANSLATION_PROVIDER') ? TRANSLATION_PROVIDER : 'openai';
                if ($provider === 'openai' && defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
                    $result = translateWithOpenAI($message['content'], 'auto', $target_lang);
                } else {
                    $result = [
                        'translated_text' => translateWithGoogle($message['content'], 'auto', $target_lang),
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'provider' => 'google'
                    ];
                }
                
                if ($result['translated_text']) {
                    $translations[$msg_id] = $result['translated_text'];
                    saveMessageTranslation($pdo, $msg_id, $target_lang, $result['translated_text'], $result['provider']);
                    recordTranslationUsage(
                        $pdo,
                        $user_id,
                        mb_strlen($message['content']),
                        $result['input_tokens'] ?? 0,
                        $result['output_tokens'] ?? 0,
                        $msgLang,
                        $target_lang,
                        $result['provider'],
                        $msg_id
                    );
                }
            } catch (Throwable $e) {
                error_log('translate.php auto_translate message ' . $msg_id . ': ' . $e->getMessage());
            }
            
            $budgetStatus = checkTranslationBudget($pdo);
            if (!$budgetStatus['allowed']) {
                break;
            }
        }
        
        successResponse([
            'translations' => $translations,
            'budget_exceeded' => !$budgetStatus['allowed']
        ]);
        break;
        
    case 'detect':
        // 言語を検出
        $text = trim($input['text'] ?? '');
        
        if (empty($text)) {
            errorResponse('テキストを入力してください');
        }
        
        $detected = detectLanguage($text);
        
        successResponse([
            'detected_lang' => $detected,
            'language_name' => $supported_languages[$detected] ?? '不明'
        ]);
        break;
        
    case 'languages':
        // サポート言語一覧
        successResponse(['languages' => $supported_languages]);
        break;
        
    default:
        errorResponse('不明なアクションです');
}

} catch (Throwable $e) {
    error_log('translate.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '翻訳処理でエラーが発生しました',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * OpenAI GPT-4o で翻訳
 */
function translateWithOpenAI($text, $source, $target) {
    $apiKey = OPENAI_API_KEY;
    $model = defined('TRANSLATION_OPENAI_MODEL') ? TRANSLATION_OPENAI_MODEL : 'gpt-4o';
    
    $langNames = [
        'ja' => 'Japanese',
        'en' => 'English',
        'zh' => 'Chinese (Simplified)',
        'zh-CN' => 'Chinese (Simplified)',
        'zh-TW' => 'Chinese (Traditional)',
        'ko' => 'Korean',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'auto' => 'auto-detect'
    ];
    
    $targetLangName = $langNames[$target] ?? $target;
    $sourceLangName = $source === 'auto' ? '' : " from {$langNames[$source]}";
    
    $systemPrompt = "You are a professional translator. Translate the following text{$sourceLangName} to {$targetLangName}. " .
                    "Maintain the original meaning, tone, and nuance. " .
                    "Only output the translation, no explanations or notes.";
    
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $text]
        ],
        'temperature' => 0.3,
        'max_tokens' => 2000
    ];
    
    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("OpenAI API error: HTTP $httpCode - $response");
            return ['translated_text' => false, 'provider' => 'openai'];
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            error_log("OpenAI API unexpected response: $response");
            return ['translated_text' => false, 'provider' => 'openai'];
        }
        
        $translatedText = trim($result['choices'][0]['message']['content']);
        $inputTokens = $result['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $result['usage']['completion_tokens'] ?? 0;
        
        return [
            'translated_text' => $translatedText,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'provider' => 'openai'
        ];
        
    } catch (Exception $e) {
        error_log('OpenAI translation error: ' . $e->getMessage());
        return ['translated_text' => false, 'provider' => 'openai'];
    }
}

/**
 * Google翻訳（フォールバック用）
 */
function translateWithGoogle($text, $source, $target) {
    $langMap = [
        'zh' => 'zh-CN',
        'zh-CN' => 'zh-CN',
        'zh-TW' => 'zh-TW',
    ];
    
    if (isset($langMap[$target])) {
        $target = $langMap[$target];
    }
    
    $sl = ($source === 'auto') ? 'auto' : $source;
    if (isset($langMap[$sl])) {
        $sl = $langMap[$sl];
    }
    
    try {
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . 
               urlencode($sl) . '&tl=' . urlencode($target) . '&dt=t&q=' . urlencode($text);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0\r\nAccept: */*\r\n",
                'timeout' => 10
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return false;
        }
        
        $data = json_decode($response, true);
        
        if ($data && isset($data[0]) && is_array($data[0])) {
            $translated = '';
            foreach ($data[0] as $part) {
                if (is_array($part) && isset($part[0])) {
                    $translated .= $part[0];
                }
            }
            return empty($translated) ? $text : $translated;
        }
        
        return false;
    } catch (Exception $e) {
        error_log('Google translation error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 画像のみのメッセージかどうか（翻訳対象外）
 * 画像パスのみ・添付画像のみの場合は翻訳せずそのまま表示
 */
function isImageOnlyContent($text) {
    $text = trim($text);
    if ($text === '') {
        return true;
    }
    // 画像パス・添付絵文字を除去
    $stripped = preg_replace('/[\x{1F4F7}\x{1F3AC}\x{1F4C4}\x{1F4FD}\x{1F4CE}\s]*/u', '', $text);
    $stripped = preg_replace('/(?:uploads[\/\\\\]messages[\/\\\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:アップロード[\/\\\\]メッセージ[\/\\\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:(?:msg_|screenshot_|スクリーンショット_)[^\s\n]+\.(jpg|jpeg|png|gif|webp))/iu', '', $stripped);
    $stripped = preg_replace('/(?:https?:\/\/[^\s]+\.(jpg|jpeg|png|webp)(?:\?[^\s]*)?)/iu', '', $stripped);
    $stripped = preg_replace('/\s+/u', '', $stripped);
    return mb_strlen($stripped) < 3;
}

/**
 * 言語を検出
 */
function detectLanguage($text) {
    if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) {
        return 'ja';
    }
    if (preg_match('/[\x{4E00}-\x{9FAF}]/u', $text) && !preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) {
        return 'zh';
    }
    if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) {
        return 'ko';
    }
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return 'ar';
    }
    if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $text)) {
        return 'th';
    }
    return 'en';
}

/**
 * 言語コードを正規化
 */
function normalizeLanguage($lang) {
    $map = [
        'zh-CN' => 'zh',
        'zh-TW' => 'zh',
        'zh-Hans' => 'zh',
        'zh-Hant' => 'zh'
    ];
    return $map[$lang] ?? $lang;
}

/**
 * メッセージ翻訳をキャッシュに保存
 */
function saveMessageTranslation($pdo, $messageId, $targetLang, $translatedText, $provider) {
    try {
        $pdo->prepare("
            INSERT INTO message_translations (message_id, target_lang, translated_text, api_provider)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), api_provider = VALUES(api_provider)
        ")->execute([$messageId, $targetLang, $translatedText, $provider]);
    } catch (Exception $e) {
        error_log('Failed to save message translation: ' . $e->getMessage());
    }
}

/**
 * 翻訳使用量を記録
 */
function recordTranslationUsage($pdo, $userId, $charCount, $inputTokens, $outputTokens, $sourceLang, $targetLang, $provider, $messageId = null) {
    $cost = 0;
    
    if ($provider === 'openai') {
        $inputCost = defined('OPENAI_INPUT_COST_PER_1M') ? OPENAI_INPUT_COST_PER_1M : 2.50;
        $outputCost = defined('OPENAI_OUTPUT_COST_PER_1M') ? OPENAI_OUTPUT_COST_PER_1M : 10.00;
        
        $cost = ($inputTokens / 1000000 * $inputCost) + ($outputTokens / 1000000 * $outputCost);
    }
    
    try {
        $pdo->prepare("
            INSERT INTO translation_usage 
            (user_id, character_count, token_count, source_lang, target_lang, api_provider, cost_usd, message_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId, 
            $charCount, 
            $inputTokens + $outputTokens, 
            $sourceLang, 
            $targetLang, 
            $provider, 
            $cost,
            $messageId
        ]);
    } catch (Exception $e) {
        error_log('Failed to record translation usage: ' . $e->getMessage());
    }
    
    return $cost;
}

/**
 * 翻訳予算をチェック
 */
function checkTranslationBudget($pdo) {
    $budgetJpy = defined('TRANSLATION_MONTHLY_BUDGET_JPY') ? TRANSLATION_MONTHLY_BUDGET_JPY : 30000;
    $usdToJpy = defined('USD_TO_JPY_RATE') ? USD_TO_JPY_RATE : 154;
    $budgetUsd = $budgetJpy / $usdToJpy;
    
    $currentCostUsd = 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cost_usd), 0) as total_cost
            FROM translation_usage
            WHERE api_provider = 'openai'
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $currentCostUsd = (float)$result['total_cost'];
    } catch (Throwable $e) {
        // テーブル・カラム未作成時も0として扱い500を防ぐ
        error_log('checkTranslationBudget: ' . $e->getMessage());
        $currentCostUsd = 0;
    }
    
    $currentCostJpy = $currentCostUsd * $usdToJpy;
    $remainingJpy = $budgetJpy - $currentCostJpy;
    $usagePercent = $budgetUsd > 0 ? ($currentCostUsd / $budgetUsd) * 100 : 0;
    
    return [
        'allowed' => $currentCostUsd < $budgetUsd,
        'current_cost_usd' => round($currentCostUsd, 4),
        'current_cost_jpy' => round($currentCostJpy, 0),
        'budget_jpy' => $budgetJpy,
        'remaining_jpy' => round(max(0, $remainingJpy), 0),
        'usage_percent' => round($usagePercent, 1),
        'auto_translation_enabled' => defined('AUTO_TRANSLATION_ENABLED') ? AUTO_TRANSLATION_ENABLED : true,
        'auto_translation_days' => defined('AUTO_TRANSLATION_DAYS') ? AUTO_TRANSLATION_DAYS : 3
    ];
}
