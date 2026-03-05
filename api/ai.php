<?php
/**
 * AI秘書API
 * 
 * Gemini無料枠を使用したAIアシスタント機能
 * ユーザーごとにパーソナライズされた秘書として動作
 */

// 出力バッファ開始（BOMや警告の混入を防ぐ）
if (!ob_get_level()) {
    ob_start();
}

// 最初にエラーハンドリングを設定
error_reporting(0);
ini_set('display_errors', '0');

// カスタムエラーハンドラーでエラーをJSONで返す
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("AI API Error: [$errno] $errstr in $errfile on line $errline");
    return true; // エラーを出力しない
});

// JSONヘッダーを最初に送信
header('Content-Type: application/json; charset=utf-8');

define('IS_API', true);

// JSON出力用：バッファをクリアしてから出力（例外時用）
$sendJsonError = function ($data) {
    if (ob_get_level()) @ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../config/ai_config.php';
    require_once __DIR__ . '/../config/google_calendar.php';
    require_once __DIR__ . '/../includes/api-helpers.php';
    // ai_file_reader は ask で file_path があるときだけ読み込む（本番で require 失敗時に API 全体を落とさないため）
    $loadOptional = function ($path) {
        if (file_exists($path)) {
            try {
                require_once $path;
            } catch (Throwable $e) {
                error_log("AI API optional include error [{$path}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            }
        }
    };
    $base = __DIR__ . '/../includes';
    $loadOptional($base . '/google_calendar_helper.php');
    $loadOptional($base . '/improvement_context_helper.php');
    $loadOptional($base . '/ai_specialist_router.php');
    $loadOptional($base . '/ai_user_profiler.php');
    $loadOptional($base . '/ai_safety_reporter.php');
} catch (Throwable $e) {
    error_log("AI API config error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $sendJsonError(['success' => false, 'message' => '設定の読み込みに失敗しました']);
}

// 未捕捉例外・致命的エラー時にJSONで返す（200で返しサーバーの500エラーページ上書きを回避）
set_exception_handler(function ($e) use ($sendJsonError) {
    $msg = $e->getMessage();
    error_log('AI API Exception: ' . $msg . ' in ' . $e->getFile() . ':' . $e->getLine());
    $hint = null;
    if (!(defined('APP_DEBUG') && APP_DEBUG)) {
        if (stripos($msg, 'Unknown column') !== false || stripos($msg, 'SQLSTATE') !== false) {
            $hint = 'データベースのカラム不足の可能性があります。マイグレーションの実行を確認してください。';
        } elseif (stripos($msg, 'vendor') !== false || stripos($msg, 'autoload') !== false || stripos($msg, 'getallheaders') !== false) {
            $hint = 'サーバーで composer install を実行してください。';
        } elseif (stripos($msg, 'GEMINI') !== false || stripos($msg, 'ai_config') !== false) {
            $hint = 'config/ai_config.local.php で GEMINI_API_KEY が設定されているか確認してください。';
        } elseif (stripos($msg, 'file_exists') !== false || stripos($msg, 'failed to open') !== false) {
            $hint = '必要なファイル・ディレクトリがサーバーに存在するか確認してください。';
        }
    }
    $payload = [
        'success' => false,
        'message' => (defined('APP_DEBUG') && APP_DEBUG) ? $msg : 'サーバーエラーが発生しました',
        'error_type' => 'exception'
    ];
    if ($hint !== null) {
        $payload['hint'] = $hint;
    }
    $sendJsonError($payload);
});
register_shutdown_function(function () use ($sendJsonError) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('AI API Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $sendJsonError([
            'success' => false,
            'message' => (defined('APP_DEBUG') && APP_DEBUG) ? $err['message'] : 'サーバーエラーが発生しました',
            'error_type' => 'fatal'
        ]);
    }
});

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// get_settings / history は軽量なので、重いモジュール読み込み前に早期リターン
if ($action === 'get_settings') {
    $settings = [
        'name' => 'あなたの秘書',
        'character_type' => null,
        'custom_instructions' => '',
        'character_selected' => false,
        'user_profile' => '',
        'character_types' => [],
        'personality' => null,
        'deliberation_max_seconds' => 180,
        'proactive_message_enabled' => 1,
        'proactive_message_hour' => 18
    ];
    $todayTopicsDefaults = [
        'today_topics_morning_enabled' => 1,
        'today_topics_evening_enabled' => 1,
        'today_topics_morning_hour' => 7,
        'today_topics_oshi' => '',
        'today_topics_paid_plan' => 0
    ];
    $settings = array_merge($settings, $todayTopicsDefaults);
    $settings['conversation_memory_summary'] = null;
    $settings['clone_training_language'] = 'ja';
    $settings['clone_auto_reply_enabled'] = 0;
    $settings['reply_suggestion_total'] = 0;
    $settings['reply_suggestion_sent'] = 0;
    $settings['reply_suggestion_correction_rate'] = null;
    try {
        $cols = 'secretary_name, character_type, custom_instructions';
        $extraCols = ['personality_json', 'deliberation_max_seconds', 'proactive_message_enabled', 'proactive_message_hour',
            'today_topics_morning_enabled', 'today_topics_evening_enabled', 'today_topics_morning_hour', 'today_topics_paid_plan',
            'conversation_memory_summary', 'clone_training_language', 'clone_auto_reply_enabled'];
        foreach ($extraCols as $ec) {
            try {
                $pdo->query("SELECT $ec FROM user_ai_settings LIMIT 0");
                $cols .= ", $ec";
            } catch (Throwable $ignore) {}
        }
        $stmt = $pdo->prepare("SELECT $cols FROM user_ai_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        if ($result) {
            if (!empty($result['secretary_name'])) $settings['name'] = $result['secretary_name'];
            if (!empty($result['character_type'])) {
                $settings['character_type'] = $result['character_type'];
                $settings['character_selected'] = true;
            }
            if (!empty($result['custom_instructions'])) $settings['custom_instructions'] = $result['custom_instructions'];
            if (isset($result['personality_json']) && $result['personality_json'] !== null) {
                $decoded = json_decode($result['personality_json'], true);
                if (is_array($decoded)) $settings['personality'] = $decoded;
            }
            if (isset($result['deliberation_max_seconds'])) $settings['deliberation_max_seconds'] = (int)$result['deliberation_max_seconds'];
            if (isset($result['proactive_message_enabled'])) $settings['proactive_message_enabled'] = (int)$result['proactive_message_enabled'];
            if (isset($result['proactive_message_hour'])) $settings['proactive_message_hour'] = (int)$result['proactive_message_hour'];
            if (isset($result['today_topics_morning_enabled'])) $settings['today_topics_morning_enabled'] = (int)$result['today_topics_morning_enabled'];
            if (isset($result['today_topics_evening_enabled'])) $settings['today_topics_evening_enabled'] = (int)$result['today_topics_evening_enabled'];
            if (isset($result['today_topics_morning_hour'])) $settings['today_topics_morning_hour'] = (int)$result['today_topics_morning_hour'];
            if (isset($result['today_topics_paid_plan'])) $settings['today_topics_paid_plan'] = (int)$result['today_topics_paid_plan'];
            if (isset($result['conversation_memory_summary']) && $result['conversation_memory_summary'] !== null) {
                $settings['conversation_memory_summary'] = $result['conversation_memory_summary'];
            }
            if (isset($result['clone_training_language']) && $result['clone_training_language'] !== null && $result['clone_training_language'] !== '') {
                $settings['clone_training_language'] = $result['clone_training_language'];
            }
            if (isset($result['clone_auto_reply_enabled'])) $settings['clone_auto_reply_enabled'] = (int)$result['clone_auto_reply_enabled'];
        }
        // 返信提案統計（修正率算出用）
        try {
            $stmtStats = $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN final_content IS NOT NULL AND final_content != '' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN final_content IS NOT NULL AND final_content != '' AND (suggested_content != final_content OR suggested_content IS NULL) THEN 1 ELSE 0 END) AS corrected
                FROM user_ai_reply_suggestions WHERE user_id = ?
            ");
            $stmtStats->execute([$user_id]);
            $rowStats = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($rowStats) {
                $settings['reply_suggestion_total'] = (int)$rowStats['total'];
                $settings['reply_suggestion_sent'] = (int)$rowStats['sent'];
                $sent = (int)$rowStats['sent'];
                $corrected = (int)$rowStats['corrected'];
                if ($sent > 0) {
                    $settings['reply_suggestion_correction_rate'] = round($corrected / $sent * 100, 1);
                }
            }
        } catch (Throwable $ignore) {
        }
        // 推し（今日の話題 計画書 3.7）：user_topic_interests の interest_type='oshi' を1件取得
        try {
            $stmtOshi = $pdo->prepare("SELECT value FROM user_topic_interests WHERE user_id = ? AND interest_type = 'oshi' ORDER BY id DESC LIMIT 1");
            $stmtOshi->execute([$user_id]);
            $rowOshi = $stmtOshi->fetch(PDO::FETCH_ASSOC);
            $settings['today_topics_oshi'] = $rowOshi && !empty($rowOshi['value']) ? $rowOshi['value'] : '';
        } catch (Throwable $ignore) {
            $settings['today_topics_oshi'] = '';
        }
    } catch (Throwable $e) {
        error_log("get_settings error: " . $e->getMessage());
    }
    if (defined('AI_CHARACTER_TYPES')) {
        foreach (AI_CHARACTER_TYPES as $key => $type) {
            $settings['character_types'][$key] = [
                'name' => $type['name'],
                'emoji' => $type['emoji'],
                'image' => $type['image'] ?? '',
                'description' => $type['description']
            ];
        }
    }
    successResponse($settings);
    exit;
}
if ($action === 'history') {
    try {
        $limit = min(max((int)($_GET['limit'] ?? 20), 1), 50);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $stmt = $pdo->prepare("
            SELECT id, user_id, question, answer, answered_by, created_at
            FROM ai_conversations
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([$user_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        successResponse(['conversations' => $conversations]);
    } catch (Throwable $e) {
        error_log("AI history error: " . $e->getMessage());
        successResponse(['conversations' => []]);
    }
    exit;
}

// 今日の話題：ニュース「詳細を見る」クリック記録（計画書 PLAN_TODAYS_TOPICS 3.4）
if ($action === 'today_topic_click') {
    $topic_id = trim((string)($input['topic_id'] ?? ''));
    $external_url = trim((string)($input['external_url'] ?? ''));
    $source = trim((string)($input['source'] ?? ''));
    $category_or_keywords = trim((string)($input['category_or_keywords'] ?? ''));
    if ($topic_id === '' && $external_url === '') {
        errorResponse('topic_id または external_url が必要です', 400);
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO today_topic_clicks (user_id, topic_id, external_url, source, category_or_keywords, clicked_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $topic_id !== '' ? $topic_id : null,
            $external_url !== '' ? mb_substr($external_url, 0, 2048) : null,
            $source !== '' ? mb_substr($source, 0, 100) : null,
            $category_or_keywords !== '' ? mb_substr($category_or_keywords, 0, 500) : null
        ]);
        successResponse(['recorded' => true]);
    } catch (Throwable $e) {
        error_log("today_topic_click error: " . $e->getMessage());
        errorResponse('クリックの記録に失敗しました', 500);
    }
    exit;
}

// 以下は ask 等の重い処理用：places / task_memo / gemini / deliberation を読み込み
require_once __DIR__ . '/../includes/places_helper.php';
require_once __DIR__ . '/../includes/task_memo_search_helper.php';
require_once __DIR__ . '/../includes/deliberation_helper.php';
require_once __DIR__ . '/../includes/today_topics_helper.php';
$geminiHelperPath = __DIR__ . '/../includes/gemini_helper.php';
if (file_exists($geminiHelperPath)) {
    try {
        require_once $geminiHelperPath;
    } catch (Throwable $e) {
        error_log("Gemini helper load: " . $e->getMessage());
    }
}

try {
switch ($action) {
    case 'ask':
        // 質問を送信
        $question = trim($input['question'] ?? '');
        // 本日のニューストピックスへの返信なら興味・希望を抽出して user_topic_interests に保存（計画書 3.6）
        if ($question !== '' && function_exists('isLastConversationTodayTopics') && isLastConversationTodayTopics($pdo, $user_id)) {
            if (function_exists('extractAndSaveTopicInterestsFromReply')) {
                extractAndSaveTopicInterestsFromReply($pdo, $user_id, $question);
            }
        }
        $voiceContext = trim((string)($input['voice_context'] ?? ''));
        if ($voiceContext !== '') {
            $question = "【直前の音声指示】\n" . $voiceContext . "\n\n【今回の指示】\n" . $question;
        }
        // 絵文字学習：ユーザーがよく使う絵文字を取得し、応答で適宜使うよう促す
        if (file_exists(__DIR__ . '/../includes/emoji_usage_helper.php')) {
            require_once __DIR__ . '/../includes/emoji_usage_helper.php';
            if (function_exists('getTopEmojis')) {
                $topEmojis = getTopEmojis($pdo, $user_id, 10);
                if (!empty($topEmojis)) {
                    $question .= "\n\n【ユーザーがよく使う絵文字】\n" . implode(' ', $topEmojis) . "\n上記の絵文字を適宜使うと親しみやすいです。";
                }
            }
        }
        $language = $input['language'] ?? 'ja';
        $useGemini = $input['use_gemini'] ?? true; // デフォルトでGemini使用
        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;
        $imagePath = trim($input['image_path'] ?? '');
        $filePath = trim($input['file_path'] ?? $input['path'] ?? $input['data']['file_path'] ?? $input['data']['path'] ?? '');
        $fileName = trim($input['file_name'] ?? $input['data']['file_name'] ?? '');

        // ファイル添付時のみ ai_file_reader を読み込む（起動時 require で本番が落ちるのを防ぐ）
        if (!empty($filePath) && !function_exists('extractFileText')) {
            $readerPath = __DIR__ . '/../includes/ai_file_reader.php';
            if (file_exists($readerPath)) {
                try {
                    require_once $readerPath;
                } catch (Throwable $e) {
                    error_log("AI API ai_file_reader load error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                }
            }
        }

        $fileContext = '';
        if (!empty($filePath) && function_exists('extractFileText') && function_exists('isImageFile')) {
            if (isImageFile($filePath)) {
                $imagePath = $filePath;
                $fileContext = "\n\n【添付画像】画像を添付しています。画像に写っている文字や内容を読み取って質問に答えてください。";
            } else {
                $result = extractFileText($filePath);
                $isScanPdfFallback = ($result['success'] && strpos($result['text'] ?? '', 'スキャン画像のPDF') !== false);
                if ($result['success'] && !empty($result['text']) && !$isScanPdfFallback) {
                    $label = $fileName ?: basename($filePath);
                    $fileContext = "\n\n【添付ファイル: {$label}（{$result['type']}）】\n" . $result['text'];
                } elseif (!$result['success'] && !empty($result['error'])) {
                    $label = $fileName ?: basename($filePath);
                    $fileContext = "\n\n【添付ファイル: {$label}】\n（読み取り失敗: {$result['error']}）";
                } else {
                    $label = $fileName ?: basename($filePath);
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    if ($ext === 'pdf') {
                        $imagePath = $filePath;
                        $fileContext = "\n\n【添付PDF（スキャン画像の可能性）】テキスト抽出できなかったため、PDFを画像として送信しています。PDFの内容を読み取って分析し、質問に答えてください。";
                    } else {
                        $fileContext = "\n\n【添付ファイル: {$label}】\n（内容のテキスト抽出結果が空でした。）";
                    }
                }
            }
        } elseif (!empty($filePath) && !function_exists('extractFileText')) {
            $label = $fileName ?: basename($filePath);
            $fileContext = "\n\n【添付ファイル: {$label}】\n（サーバーでファイル読み取り機能を利用できませんでした。しばらくしてから再度お試しください。）";
        }
        // ファイル添付を依頼する文面なのに file_path が届いていない場合の案内
        if (empty($filePath) && preg_match('/このファイルの内容を(確認|読み取って)|ファイルの内容を(確認|読んで)/u', $question)) {
            $fileContext = "\n\n【システム】添付ファイルのパスがサーバーに届いていません。ページを再読み込み（F5）して、もう一度ファイルを添付して送信してください。";
        }

        if (!empty($fileContext)) {
            $question = $question . $fileContext;
        }

        if (empty($question) && empty($imagePath)) {
            errorResponse('質問を入力するか、ファイルを添付してください');
        }
        
        $questionLimit = empty($fileContext) ? 2000 : 35000;
        if (mb_strlen($question) > $questionLimit) {
            errorResponse('質問が長すぎます');
        }

        // --- 熟慮モード判定 ---
        $deliberationMode = !empty($input['deliberation_mode']);
        $deliberationKeywords = ['熟慮モードで', 'よく考えて', '調べてから答えて', 'じっくり調べて', '詳しく調査して'];
        if (!$deliberationMode) {
            foreach ($deliberationKeywords as $dk) {
                if (mb_strpos($question, $dk) !== false) {
                    $deliberationMode = true;
                    break;
                }
            }
        }

        if ($deliberationMode) {
            // 性格設定の依頼かどうかを先に判定（熟慮モード内で性格設定を実行）
            $personalityTarget = null;
            $pIntentPatterns = [
                '/(.+?)のような性格/u', '/(.+?)みたいな性格/u', '/(.+?)っぽい性格/u',
                '/(.+?)風の性格/u', '/性格を(.+?)にして/u', '/性格を(.+?)みたいに/u',
                '/(.+?)のように話して/u', '/(.+?)みたいに話して/u',
            ];
            foreach ($pIntentPatterns as $pat) {
                if (preg_match($pat, $question, $pMatch)) {
                    $personalityTarget = trim($pMatch[1]);
                    break;
                }
            }

            $delibMaxSec = 180;
            try {
                $stmtD = $pdo->prepare("SELECT deliberation_max_seconds FROM user_ai_settings WHERE user_id = ?");
                $stmtD->execute([$user_id]);
                $rowD = $stmtD->fetch();
                if ($rowD && $rowD['deliberation_max_seconds']) {
                    $delibMaxSec = max(60, min(1800, (int)$rowD['deliberation_max_seconds']));
                }
            } catch (Throwable $ignore) {}

            $delibSessionId = 'delib_' . $user_id . '_' . bin2hex(random_bytes(8));
            $delibPurpose = ($personalityTarget && mb_strlen($personalityTarget) <= 100) ? 'personality' : null;

            $delibResult = runDeliberation(
                $delibPurpose ? "「{$personalityTarget}」のような性格・話し方・態度を持つAIアシスタントの性格設定を作成してください。" : $question,
                $delibSessionId,
                $delibMaxSec,
                $delibPurpose
            );

            // 性格自動設定の場合 → DBに保存
            if ($delibPurpose === 'personality' && !empty($delibResult['personality']) && is_array($delibResult['personality'])) {
                $allowedKeys = ['pronoun', 'tone', 'character', 'expertise', 'behavior', 'avoid', 'other'];
                $sanitized = [];
                foreach ($allowedKeys as $ak) {
                    $sanitized[$ak] = isset($delibResult['personality'][$ak]) ? mb_substr(trim($delibResult['personality'][$ak]), 0, 500) : '';
                }
                $pJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE);
                $labels = [
                    'pronoun' => '一人称・呼び方', 'tone' => '話し方・口調',
                    'character' => '性格・態度', 'expertise' => '得意分野・知識',
                    'behavior' => '行動スタイル', 'avoid' => '禁止事項・注意点',
                    'other' => 'その他の指示'
                ];
                $combined = '';
                foreach ($sanitized as $k => $v) {
                    if ($v !== '') $combined .= ($labels[$k] ?? $k) . ': ' . $v . "\n";
                }
                $combined = trim($combined);
                try {
                    $stmtP = $pdo->prepare("
                        INSERT INTO user_ai_settings (user_id, personality_json, custom_instructions, updated_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE personality_json = ?, custom_instructions = ?, updated_at = NOW()
                    ");
                    $stmtP->execute([$user_id, $pJson, $combined, $pJson, $combined]);
                } catch (Throwable $e) {
                    error_log("Personality auto-set save error: " . $e->getMessage());
                }
                $answer = "「{$personalityTarget}」のような性格を設定しました！\n\n設定内容：\n{$combined}\n\nこれからの会話に反映されます。性格設定画面から調整もできます。";
                $answered_by = 'gemini_personality_setup';
            } else {
                $answer = $delibResult['success'] ? $delibResult['answer'] : '調査中にエラーが発生しました。通常モードで再度お試しください。';
                $answered_by = 'gemini_deliberation';
            }

            try {
                $stmtIns = $pdo->prepare("INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmtIns->execute([$user_id, $question, $answer, $answered_by, $language ?? 'ja']);
                $conversationId = $pdo->lastInsertId();
            } catch (Throwable $e) {
                error_log("Deliberation conversation save error: " . $e->getMessage());
                $conversationId = null;
            }

            successResponse([
                'answer' => $answer,
                'answered_by' => $answered_by,
                'conversation_id' => $conversationId ? (int)$conversationId : null,
                'deliberation_session_id' => $delibSessionId,
                'timed_out' => !empty($delibResult['timed_out']),
                'personality_applied' => ($delibPurpose === 'personality' && !empty($delibResult['personality']))
            ]);
            break;
        }

        // --- 「誰々のような性格を設定して」意図判定（非熟慮モード時） ---
        $personalityIntentPatterns = [
            '/(.+?)のような性格/u',
            '/(.+?)みたいな性格/u',
            '/(.+?)っぽい性格/u',
            '/(.+?)風の性格/u',
            '/性格を(.+?)にして/u',
            '/性格を(.+?)みたいに/u',
            '/(.+?)のように話して/u',
            '/(.+?)みたいに話して/u',
        ];
        $personalityTarget = null;
        foreach ($personalityIntentPatterns as $pat) {
            if (preg_match($pat, $question, $pMatch)) {
                $personalityTarget = trim($pMatch[1]);
                break;
            }
        }
        if ($personalityTarget && mb_strlen($personalityTarget) <= 100) {
            $delibMaxSec = 120;
            try {
                $stmtD2 = $pdo->prepare("SELECT deliberation_max_seconds FROM user_ai_settings WHERE user_id = ?");
                $stmtD2->execute([$user_id]);
                $rowD2 = $stmtD2->fetch();
                if ($rowD2 && $rowD2['deliberation_max_seconds']) {
                    $delibMaxSec = max(60, min(1800, (int)$rowD2['deliberation_max_seconds']));
                }
            } catch (Throwable $ignore) {}

            $pSessionId = 'pers_' . $user_id . '_' . bin2hex(random_bytes(8));
            $pQuestion = "「{$personalityTarget}」のような性格・話し方・態度を持つAIアシスタントの性格設定を作成してください。";

            $pResult = runDeliberation($pQuestion, $pSessionId, $delibMaxSec, 'personality');

            if (!empty($pResult['personality']) && is_array($pResult['personality'])) {
                $allowedKeys = ['pronoun', 'tone', 'character', 'expertise', 'behavior', 'avoid', 'other'];
                $sanitized = [];
                foreach ($allowedKeys as $ak) {
                    $sanitized[$ak] = isset($pResult['personality'][$ak]) ? mb_substr(trim($pResult['personality'][$ak]), 0, 500) : '';
                }
                $pJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE);
                $labels = [
                    'pronoun' => '一人称・呼び方', 'tone' => '話し方・口調',
                    'character' => '性格・態度', 'expertise' => '得意分野・知識',
                    'behavior' => '行動スタイル', 'avoid' => '禁止事項・注意点',
                    'other' => 'その他の指示'
                ];
                $combined = '';
                foreach ($sanitized as $k => $v) {
                    if ($v !== '') $combined .= ($labels[$k] ?? $k) . ': ' . $v . "\n";
                }
                $combined = trim($combined);
                try {
                    $stmtP = $pdo->prepare("
                        INSERT INTO user_ai_settings (user_id, personality_json, custom_instructions, updated_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE personality_json = ?, custom_instructions = ?, updated_at = NOW()
                    ");
                    $stmtP->execute([$user_id, $pJson, $combined, $pJson, $combined]);
                } catch (Throwable $e) {
                    error_log("Personality auto-set save error: " . $e->getMessage());
                }

                $answer = "「{$personalityTarget}」のような性格を設定しました！\n\n設定内容：\n{$combined}\n\nこれからの会話に反映されます。性格設定画面から調整もできます。";
                $answered_by = 'gemini_personality_setup';
                try {
                    $stmtC = $pdo->prepare("INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmtC->execute([$user_id, $question, $answer, $answered_by, $language ?? 'ja']);
                    $conversationId = $pdo->lastInsertId();
                } catch (Throwable $e) {
                    error_log("Personality conversation save error: " . $e->getMessage());
                    $conversationId = null;
                }
                successResponse([
                    'answer' => $answer,
                    'answered_by' => $answered_by,
                    'conversation_id' => $conversationId ? (int)$conversationId : null,
                    'personality_applied' => true,
                    'personality' => $sanitized
                ]);
                break;
            }
        }

        // 位置情報ベースの検索が必要かチェック
        $locationKeywords = ['ランチ', 'レストラン', 'カフェ', '居酒屋', 'ラーメン', '食事', 'お店', '近く', 'この辺', '周辺', 'ディナー', '朝食', 'モーニング'];
        $needsLocationSearch = false;
        $placeSearchInfo = '';
        $foundPlaces = []; // 店舗情報を保持
        
        foreach ($locationKeywords as $keyword) {
            if (mb_strpos($question, $keyword) !== false) {
                $needsLocationSearch = true;
                break;
            }
        }
        
        // 位置情報があり、検索が必要な場合はPlaces APIで検索
        if ($needsLocationSearch && $latitude && $longitude) {
            $searchQuery = $question; // 質問をそのまま検索クエリに
            $placesResult = searchNearbyPlaces((float)$latitude, (float)$longitude, $searchQuery, 1500);
            
            if ($placesResult['success'] && !empty($placesResult['places'])) {
                $foundPlaces = $placesResult['places']; // 店舗情報を保存
                $placeSearchInfo = "\n\n【ユーザーの現在地周辺の検索結果】\n";
                $placeSearchInfo .= formatPlacesForAI($foundPlaces);
                $placeSearchInfo .= "\n上記の実際のお店を紹介してください。一般的な提案ではなく、検索結果のお店を具体的に案内してください。";
            }
        }
        
        // タスク・メモキーワード検索（まとめて報告、〇〇を検索 等）
        $taskMemoSearchInfo = '';
        $taskMemoSearchResult = null;
        $searchParams = null;
        $searchLimitExceeded = false;
        $sessionKey = 'ai_last_search_' . $user_id;

        if (function_exists('extractTaskMemoSearchParams') && function_exists('searchTasksAndMemos')) {
            $searchParams = extractTaskMemoSearchParams($question);

            // フォローアップ質問時：前回の検索結果をセッションから復元（10分以内）
            if (!$searchParams && function_exists('isFollowUpQuestion') && isFollowUpQuestion($question)) {
                $lastSearch = $_SESSION[$sessionKey] ?? null;
                if ($lastSearch && isset($lastSearch['summary']) && isset($lastSearch['ts']) && (time() - $lastSearch['ts']) < 600) {
                    $taskMemoSearchInfo = defined('AI_TASK_MEMO_SEARCH_INSTRUCTIONS')
                        ? str_replace('{task_memo_search_results}', $lastSearch['summary'], AI_TASK_MEMO_SEARCH_INSTRUCTIONS)
                        : "\n\n【前回の検索結果（フォローアップ用）】\n" . $lastSearch['summary'] . "\n上記の内容を深く分析し、ユーザーが求める答えを抽出して詳しく回答してください。";
                }
            }

            if ($searchParams && empty($taskMemoSearchInfo)) {
                $searchLimitExceeded = false;
                if (function_exists('checkTaskMemoSearchLimit')) {
                    $limitCheck = checkTaskMemoSearchLimit($pdo, $user_id);
                    if (!$limitCheck['allowed']) {
                        $searchLimitExceeded = true;
                        $answer = defined('AI_TASK_MEMO_SEARCH_LIMIT_MESSAGE') ? AI_TASK_MEMO_SEARCH_LIMIT_MESSAGE : 'すみません、これ以上は有料プランになるんです、ごめんなさい。しかもまだ有料プランは準備中です💦';
                        $answered_by = 'fallback';
                    }
                }
                if (!$searchLimitExceeded) {
                    try {
                        $taskMemoSearchResult = searchTasksAndMemos($pdo, $user_id, $searchParams['keyword'], $searchParams['year'] ?? null, 30);
                        if ($taskMemoSearchResult && function_exists('recordTaskMemoSearchUsage')) {
                            recordTaskMemoSearchUsage($pdo, $user_id);
                        }
                        if ($taskMemoSearchResult) {
                            $_SESSION[$sessionKey] = ['summary' => $taskMemoSearchResult['summary'], 'keyword' => $searchParams['keyword'], 'ts' => time()];
                            $taskMemoSearchInfo = defined('AI_TASK_MEMO_SEARCH_INSTRUCTIONS')
                                ? str_replace('{task_memo_search_results}', $taskMemoSearchResult['summary'], AI_TASK_MEMO_SEARCH_INSTRUCTIONS)
                                : "\n\n【タスク・メモ検索結果】\n" . $taskMemoSearchResult['summary'] . "\n" . (defined('AI_SEARCH_DEEP_ANALYSIS_INSTRUCTIONS') ? AI_SEARCH_DEEP_ANALYSIS_INSTRUCTIONS : '上記を元に報告してください。');
                        }
                    } catch (Exception $e) {
                        error_log("Task/memo search error: " . $e->getMessage());
                    }
                }
            }
        }

        // メッセージコンテキスト検索（自然な質問でもメッセージやPDF内容を参照）
        $messageContextInfo = '';
        if (empty($taskMemoSearchInfo) && !$searchLimitExceeded && function_exists('extractTopicKeyword') && function_exists('searchMessagesForContext')) {
            $topicKeyword = extractTopicKeyword($question);
            if ($topicKeyword) {
                try {
                    $contextResult = searchMessagesForContext($pdo, $user_id, $topicKeyword, 10);
                    if (!empty($contextResult['messages'])) {
                        $_SESSION[$sessionKey] = ['summary' => $contextResult['summary'], 'keyword' => $topicKeyword, 'ts' => time()];
                        $analysisNote = defined('AI_SEARCH_DEEP_ANALYSIS_INSTRUCTIONS') ? AI_SEARCH_DEEP_ANALYSIS_INSTRUCTIONS : '上記のメッセージ内容を深く分析し、ユーザーが求める答えを抽出して詳しく回答してください。';
                        $messageContextInfo = "\n\n【メッセージ検索結果】\n" . $contextResult['summary'] . "\n\n" . $analysisNote;
                    }
                } catch (Exception $e) {
                    error_log("Message context search error: " . $e->getMessage());
                }
            }
        }
        
        if (!$searchLimitExceeded) {
            $answer = null;
            $answered_by = 'gemini';
        }
        
        // Geminiを使用（検索制限超過時はスキップ）。例外時はフォールバックで応答し会話を保存する
        $geminiAvailable = function_exists('isGeminiAvailable') && isGeminiAvailable();
        if (!$searchLimitExceeded && $useGemini && $geminiAvailable) {
            try {
            // ユーザーのAI設定を取得
            $secretaryName = 'あなたの秘書';
            $characterType = 'female_20s';
            $customInstructions = '';
            $userProfile = '';
            
            try {
                // まずカラムの存在を確認せずに基本的なカラムだけ取得
                $settingsStmt = $pdo->prepare("SELECT secretary_name, character_type, custom_instructions FROM user_ai_settings WHERE user_id = ?");
                $settingsStmt->execute([$user_id]);
                $userSettings = $settingsStmt->fetch();
                if ($userSettings) {
                    if (!empty($userSettings['secretary_name'])) {
                        $secretaryName = $userSettings['secretary_name'];
                    }
                    if (!empty($userSettings['character_type'])) {
                        $ct = $userSettings['character_type'];
                        // DBに不正な値が入っている場合のフォールバック（未定義キーで例外を防ぐ）
                        if (defined('AI_CHARACTER_TYPES') && isset(AI_CHARACTER_TYPES[$ct])) {
                            $characterType = $ct;
                        }
                    }
                    if (!empty($userSettings['custom_instructions'])) {
                        $customInstructions = "\n\nユーザーからの追加指示:\n" . $userSettings['custom_instructions'];
                    }
                }
                
                // user_profileは後から追加するカラムなので今は取得しない
            } catch (Exception $e) {
                // テーブルがない場合はデフォルト値を使用
            }
            
            // ユーザーの基本情報も取得
            try {
                $userStmt = $pdo->prepare("SELECT display_name, full_name, bio FROM users WHERE id = ?");
                $userStmt->execute([$user_id]);
                $userData = $userStmt->fetch();
                if ($userData) {
                    $userName = $userData['display_name'] ?: $userData['full_name'] ?: '';
                    if ($userName) {
                        $userProfile .= "\nユーザーの名前: " . $userName;
                    }
                    if (!empty($userData['bio'])) {
                        $userProfile .= "\nユーザーの自己紹介: " . $userData['bio'];
                    }
                }
            } catch (Exception $e) {
                // エラーは無視
            }
            
            // ユーザーの記憶情報を取得
            $userMemories = '';
            try {
                $memStmt = $pdo->prepare("SELECT category, content, created_at FROM ai_user_memories WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
                $memStmt->execute([$user_id]);
                $memories = $memStmt->fetchAll();
                if (!empty($memories)) {
                    $memoryLines = [];
                    foreach ($memories as $mem) {
                        $memoryLines[] = "- [{$mem['category']}] {$mem['content']}";
                    }
                    $userMemories = implode("\n", $memoryLines);
                } else {
                    $userMemories = "（まだ記憶している情報はありません）";
                }
            } catch (Exception $e) {
                $userMemories = "（記憶機能は準備中です）";
            }

            // AIクローン育成: 判断材料・会話記憶を取得してプロンプトに注入するブロックを用意
            $judgmentMaterialsBlock = '';
            $conversationMemoryBlock = '';
            try {
                $uid = (int) $user_id;
                $foldersStmt = $pdo->prepare("SELECT id FROM user_ai_judgment_folders WHERE user_id = ? ORDER BY sort_order, id");
                $foldersStmt->execute([$uid]);
                $folderIds = $foldersStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($folderIds)) {
                    $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
                    $itemsStmt = $pdo->prepare("SELECT folder_id, title, content FROM user_ai_judgment_items WHERE folder_id IN ($placeholders) AND user_id = ? ORDER BY folder_id, sort_order, id");
                    $itemsStmt->execute(array_merge($folderIds, [$uid]));
                    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                    $lines = [];
                    $currentFolderId = null;
                    foreach ($items as $it) {
                        $text = trim($it['title'] ?? '');
                        if (trim((string)($it['content'] ?? '')) !== '') {
                            $text .= "\n" . trim($it['content']);
                        }
                        if ($text !== '') {
                            $lines[] = $text;
                        }
                    }
                    if (!empty($lines)) {
                        $judgmentMaterialsBlock = "\n\n【判断材料】\n以下の情報を判断・回答の参考にしてください。\n" . implode("\n\n---\n\n", $lines);
                    }
                }
                $memStmt = $pdo->prepare("SELECT conversation_memory_summary FROM user_ai_settings WHERE user_id = ?");
                $memStmt->execute([$uid]);
                $row = $memStmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty(trim((string)($row['conversation_memory_summary'] ?? '')))) {
                    $conversationMemoryBlock = "\n\n【話し方・会話記憶】\n" . trim($row['conversation_memory_summary']);
                }
            } catch (Throwable $e) {
                error_log('AI judgment/memory block: ' . $e->getMessage());
            }
            
            // キャラクタータイプに応じたシステムプロンプトを生成（'prompt'キー未定義時はスキップ）
            $systemPrompt = null;
            if (defined('AI_CHARACTER_TYPES') && isset(AI_CHARACTER_TYPES[$characterType]) && isset(AI_CHARACTER_TYPES[$characterType]['prompt'])) {
                $promptTemplate = AI_CHARACTER_TYPES[$characterType]['prompt'];
                
                // 記憶指示を追加
                $memoryInstructions = '';
                if (defined('AI_MEMORY_INSTRUCTIONS')) {
                    $memoryInstructions = str_replace(
                        '{user_memories}',
                        $userMemories,
                        AI_MEMORY_INSTRUCTIONS
                    );
                }
                
                // リマインダー指示を追加
                $reminderInstructions = '';
                if (defined('AI_REMINDER_INSTRUCTIONS')) {
                    $reminderInstructions = str_replace(
                        '{current_datetime}',
                        date('Y年m月d日 H時i分（Y-m-d H:i）'),
                        AI_REMINDER_INSTRUCTIONS
                    );
                }

                // Googleカレンダー指示を追加
                $calendarInstructions = '';
                if (defined('AI_CALENDAR_INSTRUCTIONS') && function_exists('getCalendarAccountsForPrompt')) {
                    try {
                        $calAccounts = getCalendarAccountsForPrompt($pdo, $user_id);
                        $list = [];
                        foreach ($calAccounts as $c) {
                            $list[] = $c['display_name'] . ($c['is_default'] ? '（デフォルト）' : '');
                        }
                        $calendarListStr = !empty($list) ? implode(', ', $list) : '（まだ連携されていません）';
                        $calendarInstructions = str_replace(
                            ['{calendar_accounts_list}', '{current_datetime}'],
                            [$calendarListStr, date('Y年m月d日 H時i分（Y-m-d H:i）')],
                            AI_CALENDAR_INSTRUCTIONS
                        );
                    } catch (Exception $e) {
                        $calendarInstructions = '';
                    }
                }

                // Googleスプレッドシート編集指示を追加（連携済みの場合のみ）
                $sheetsInstructions = '';
                if (defined('AI_SHEETS_INSTRUCTIONS')) {
                    try {
                        if (file_exists(__DIR__ . '/../includes/google_sheets_helper.php')) {
                            require_once __DIR__ . '/../config/google_sheets.php';
                            require_once __DIR__ . '/../includes/google_sheets_helper.php';
                            if (function_exists('getGoogleSheetsAccount') && function_exists('isGoogleSheetsEnabled')) {
                                $sheetsAccount = getGoogleSheetsAccount($pdo, $user_id);
                                if ($sheetsAccount && isGoogleSheetsEnabled()) {
                                    $sheetsInstructions = AI_SHEETS_INSTRUCTIONS;
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("AI sheets instructions load: " . $e->getMessage());
                    }
                }

                // Social9内Excel/Word編集指示を追加
                $documentInstructions = defined('AI_DOCUMENT_INSTRUCTIONS') ? AI_DOCUMENT_INSTRUCTIONS : '';

                // 位置情報とお店検索結果を処理
                $locationInstructions = '';
                if (defined('AI_LOCATION_INSTRUCTIONS')) {
                    $locationInfo = '';
                    $searchResults = '';
                    
                    // 位置情報
                    if (!empty($input['location'])) {
                        $lat = floatval($input['location']['lat'] ?? 0);
                        $lng = floatval($input['location']['lng'] ?? 0);
                        if ($lat && $lng) {
                            $locationInfo = "ユーザーの現在地: 緯度 {$lat}, 経度 {$lng}";
                        }
                    }
                    
                    // お店検索結果
                    if (!empty($input['places']) && is_array($input['places'])) {
                        $placeLines = ["【検索で見つかったお店】"];
                        foreach ($input['places'] as $i => $place) {
                            $line = ($i + 1) . ". " . ($place['name'] ?? '不明');
                            if (!empty($place['rating'])) {
                                $line .= " (評価: " . $place['rating'] . "/5";
                                if (!empty($place['reviews'])) {
                                    $line .= ", " . $place['reviews'] . "件のレビュー";
                                }
                                $line .= ")";
                            }
                            if (!empty($place['price_level'])) {
                                $line .= " [" . $place['price_level'] . "]";
                            }
                            if (!empty($place['address'])) {
                                $line .= "\n   住所: " . $place['address'];
                            }
                            if (!empty($place['maps_url'])) {
                                $line .= "\n   地図: " . $place['maps_url'];
                            }
                            $placeLines[] = $line;
                        }
                        $searchResults = implode("\n", $placeLines);
                    }
                    
                    if ($locationInfo || $searchResults) {
                        $locationInstructions = str_replace(
                            ['{location_info}', '{search_results}'],
                            [$locationInfo, $searchResults],
                            AI_LOCATION_INSTRUCTIONS
                        );
                    }
                }
                
                $social9Knowledge = defined('AI_SOCIAL9_KNOWLEDGE') ? AI_SOCIAL9_KNOWLEDGE : '';
                $imageInstructions = !empty($imagePath) ? "\n\n【画像・PDFについて】ユーザーが画像またはPDFを添付しています。内容を確認し、それに基づいて回答してください。PDFの場合は文書内容を読み取り、要約や質問に答えてください。" : '';
                $improvementHearingInstructions = defined('AI_IMPROVEMENT_HEARING_INSTRUCTIONS') ? AI_IMPROVEMENT_HEARING_INSTRUCTIONS : '';
                // ユーザー性格プロファイルに基づく応答スタイル調整
                $profileAddition = '';
                try {
                    if (function_exists('buildProfilePromptAddition')) {
                        $profileAddition = buildProfilePromptAddition($user_id);
                    }
                } catch (Throwable $e) {
                    error_log('AI profile prompt error: ' . $e->getMessage());
                }

                // 専門AI振り分け指示（組織所属時）
                $specialistInstructions = '';
                try {
                    $currentOrgId = null;
                    if (function_exists('getCurrentOrgId')) {
                        $currentOrgId = getCurrentOrgId();
                    }
                    if (!$currentOrgId) {
                        $orgStmt = $pdo->prepare("
                            SELECT organization_id FROM organization_members
                            WHERE user_id = ? AND left_at IS NULL
                            ORDER BY accepted_at DESC LIMIT 1
                        ");
                        $orgStmt->execute([$user_id]);
                        $orgRow = $orgStmt->fetch();
                        if ($orgRow) $currentOrgId = (int)$orgRow['organization_id'];
                    }
                    if ($currentOrgId && class_exists('SpecialistType')) {
                        $specialistInstructions = "\n\n【専門AI連携】\nあなたはユーザーの秘書として、必要に応じて組織の専門AIに情報を割り振ります。"
                            . "\n以下の専門AIが利用可能です:";
                        foreach (SpecialistType::LABELS_JA as $type => $label) {
                            if ($type !== 'secretary') {
                                $specialistInstructions .= "\n- {$label}";
                            }
                        }
                        $specialistInstructions .= "\nユーザーの質問が専門分野に該当する場合、内部で専門AIの知識を参照して回答してください。";
                    }
                } catch (Throwable $e) {
                    error_log('AI specialist instructions error: ' . $e->getMessage());
                }

                $customBlock = $customInstructions . $userProfile . $profileAddition . $judgmentMaterialsBlock . $conversationMemoryBlock . $specialistInstructions . $locationInstructions . $placeSearchInfo . $taskMemoSearchInfo . $messageContextInfo . $calendarInstructions . $sheetsInstructions . $documentInstructions . $imageInstructions . $improvementHearingInstructions;
                $systemPrompt = str_replace(
                    ['{name}', '{social9_knowledge}', '{custom_instructions}', '{memory_instructions}', '{reminder_instructions}'],
                    [$secretaryName, $social9Knowledge, $customBlock, $memoryInstructions, $reminderInstructions],
                    $promptTemplate
                );
            }
            
            // 過去の会話履歴を取得（直近3件）。テーブル未作成等は空で続行
            $recentConversations = [];
            try {
                $historyStmt = $pdo->prepare("
                    SELECT question, answer FROM ai_conversations 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 3
                ");
                $historyStmt->execute([$user_id]);
                $recentConversations = $historyStmt->fetchAll();
            } catch (Throwable $e) {
                error_log("AI conversation history: " . $e->getMessage());
            }
            
            // 会話履歴を構築
            $conversationHistory = [];
            foreach (array_reverse($recentConversations) as $conv) {
                $conversationHistory[] = ['role' => 'user', 'content' => $conv['question']];
                $conversationHistory[] = ['role' => 'assistant', 'content' => $conv['answer']];
            }
            
            // 専門AI振り分け: 組織所属時に意図を分類し、該当する専門AIのナレッジを参照
            $specialistContext = '';
            try {
                if (isset($currentOrgId) && $currentOrgId && function_exists('classifyIntent')) {
                    $intentResult = classifyIntent($question, $currentOrgId);
                    if ($intentResult['specialist_type'] !== 'secretary' && $intentResult['confidence'] >= 0.5) {
                        $specType = $intentResult['specialist_type'];
                        $memories = function_exists('searchOrgMemories')
                            ? searchOrgMemories($currentOrgId, $specType, $question, 3)
                            : [];
                        if (!empty($memories)) {
                            $specLabel = SpecialistType::LABELS_JA[$specType] ?? $specType;
                            $specialistContext = "\n\n【{$specLabel}の参考情報】\n";
                            foreach ($memories as $mem) {
                                $specialistContext .= "- {$mem['title']}: {$mem['content']}\n";
                            }
                            $systemPrompt .= $specialistContext;
                        }
                        if (function_exists('logSpecialistCall')) {
                            logSpecialistCall($currentOrgId, $user_id, $specType, $question, ['success' => true, 'response' => '(routed)']);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('AI specialist routing error: ' . $e->getMessage());
            }

            // Gemini APIを呼び出し（キャラクター設定付き、画像対応）
            if (!function_exists('geminiChat')) {
                $geminiResult = ['success' => false, 'error' => 'Gemini helper not loaded'];
            } else {
                $geminiResult = geminiChat($question, $conversationHistory, $systemPrompt, !empty($imagePath) ? $imagePath : null);
            }
            
            if ($geminiResult['success']) {
                $answer = $geminiResult['response'];
                $answered_by = 'gemini';
            } else {
                // Geminiが失敗した場合
                error_log("Gemini API failed: " . ($geminiResult['error'] ?? 'Unknown error'));
                if ($taskMemoSearchResult) {
                    $total = $taskMemoSearchResult['total'];
                    $kw = $taskMemoSearchResult['keyword'] ?? '';
                    $answer = $total > 0
                        ? "{$total}件の「{$kw}」がありました。\n\n" . $taskMemoSearchResult['summary']
                        : "「{$kw}」に一致するタスク・メモは見つかりませんでした。";
                } else {
                    $answer = getDefaultResponse($language, $question);
                }
                $answered_by = 'fallback';
                if (!$taskMemoSearchResult && strpos($geminiResult['error'] ?? '', 'リクエストが集中') !== false) {
                    $answer = $geminiResult['error'];
                }
            }
            } catch (Throwable $e) {
                error_log("AI ask Gemini block: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                $answer = getDefaultResponse($language, $question);
                $answered_by = 'fallback';
            }
        }
        
        // 3. それでも回答がない場合（Geminiが利用できない場合も含む）
        if (!$answer) {
            if ($taskMemoSearchResult ?? null) {
                $total = $taskMemoSearchResult['total'];
                $kw = $taskMemoSearchResult['keyword'] ?? '';
                $answer = $total > 0
                    ? "{$total}件の「{$kw}」がありました。\n\n" . $taskMemoSearchResult['summary']
                    : "「{$kw}」に一致するタスク・メモは見つかりませんでした。";
            } else {
                $answer = getDefaultResponse($language, $question);
            }
            $answered_by = 'fallback';
        }
        
        // 会話を保存（answered_byはENUM('ai', 'admin')なので'ai'に統一）
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language)
                VALUES (?, ?, ?, 'ai', ?)
            ");
            $stmt->execute([$user_id, $question, $answer, $language]);
            $conversation_id = $pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("AI conversation save error: " . $e->getMessage());
            $conversation_id = 0;
        }
        
        // 使用量を記録（Gemini成功・フォールバック問わず、応答を返したら1回としてカウント）
        if (function_exists('logGeminiUsage') && !empty($answer)) {
            logGeminiUsage($pdo, $user_id, 'chat', mb_strlen($question), mb_strlen($answer));
        }

        // ユーザープロファイル更新（会話から性格・行動パターンを自動学習）
        try {
            if (function_exists('updateProfileFromConversation')) {
                updateProfileFromConversation($user_id, $question, $answer);
            }
        } catch (Throwable $e) {
            error_log('AI profile update error: ' . $e->getMessage());
        }

        // 安全チェック（社会通念違反・生命の危機・いじめ等の自動通報）
        try {
            if (function_exists('checkAndReport')) {
                $orgIdForReport = $currentOrgId ?? null;
                $convHistoryForReport = [];
                foreach (($conversationHistory ?? []) as $ch) {
                    $convHistoryForReport[] = [
                        'role'    => $ch['role'] ?? 'user',
                        'content' => $ch['content'] ?? '',
                    ];
                }
                checkAndReport($user_id, $question, $orgIdForReport, $conversation_id, $convHistoryForReport);
            }
        } catch (Throwable $e) {
            error_log('AI safety check error: ' . $e->getMessage());
        }

        // IMPROVEMENT_CONFIRMEDタグをサーバー側で検出・除去（タグ部分のみ除去、前後テキストは保持）
        $improvementConfirmed = false;
        if (strpos($answer, 'IMPROVEMENT_CONFIRMED') !== false) {
            $improvementConfirmed = true;
            $answer = preg_replace('/\s*[\[［【]?\s*IMPROVEMENT_CONFIRMED\s*[\]］】]?\s*/u', '', $answer);
            $answer = trim($answer);
            if ($answer === '') {
                $answer = '改善提案として記録しますね。';
            }
        }

        $response = [
            'conversation_id' => $conversation_id,
            'answer' => $answer,
            'answered_by' => $answered_by,
            'ai_enabled' => function_exists('isGeminiAvailable') && isGeminiAvailable(),
            'improvement_confirmed' => $improvementConfirmed
        ];
        
        // 店舗情報があれば追加
        if (!empty($foundPlaces)) {
            $response['places'] = $foundPlaces;
        }
        
        successResponse($response);
        break;
    
    case 'interpret_send_to_group':
        // ユーザー文章をAIで解釈し「〇〇グループに△△を送信」の意図なら group_name / content / conversation_id を返す
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') {
            successResponse(['detected' => false, 'group_name' => null, 'content' => null, 'conversation_id' => null]);
            break;
        }
        // 参加会話をDBから取得（送信先は必ずこの一覧に限定）。c.name のみ使用（スキーマで必須）
        $convList = [];
        $nameToId = [];
        try {
            $stmt = $pdo->prepare("
                SELECT c.id, c.name
                FROM conversations c
                INNER JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.left_at IS NULL
                WHERE cm.user_id = ?
            ");
            $stmt->execute([$user_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$row['id'];
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') {
                    $convList[] = $name;
                    $nameToId[$name] = $id;
                }
            }
        } catch (Throwable $e) {
            error_log("interpret_send_to_group conv list: " . $e->getMessage());
        }
        $frontendNames = $input['group_names'] ?? [];
        if (is_array($frontendNames)) {
            foreach (array_map('trim', $frontendNames) as $n) {
                if ($n !== '' && !in_array($n, $convList, true)) {
                    $convList[] = $n;
                }
            }
        }
        if (empty($convList)) {
            successResponse(['detected' => false, 'group_name' => null, 'content' => null, 'conversation_id' => null]);
            break;
        }
        $groupsList = implode('、', array_slice($convList, 0, 80));
        $systemPrompt = 'あなたはユーザーの発言を分類するだけのアシスタントです。
ユーザーが「特定のグループ・会話にメッセージを送ってほしい」と言っているかどうかだけを判定します。

【ルール】
- 以下のグループ名のいずれかへの「メッセージを送信して」という意図があれば、detected を true にし、group_name は必ずリストに含まれる名前をそのまま1つ選び、content には実際に送る本文のみを入れる。
- 挨拶（おはようございます、こんにちは等）・依頼の言い換え（〜というメッセージを送って、〜を送信して、〜って送って等）はすべて content に反映する。
- 意図が不明または送信依頼でない場合は detected を false にする。
- 返答は必ず以下のJSONのみ1行で出力する。説明や改行は一切つけない。
  detected が true のとき: {"detected":true,"group_name":"リストの名前そのまま","content":"送る本文"}
  detected が false のとき: {"detected":false}';
        $userPrompt = "【グループ名のリスト】\n" . $groupsList . "\n\n【ユーザーの発言】\n" . $message . "\n\n上記の発言から、送信意図と送信先・本文を判定し、JSONのみ返答してください。";
        $interpreted = ['detected' => false, 'group_name' => null, 'content' => null, 'conversation_id' => null];
        if (function_exists('geminiChat') && function_exists('isGeminiAvailable') && isGeminiAvailable()) {
            $geminiResult = geminiChat($userPrompt, [], $systemPrompt, null);
            if (!empty($geminiResult['success']) && !empty($geminiResult['response'])) {
                $raw = trim($geminiResult['response']);
                $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw);
                $start = mb_strpos($raw, '{');
                if ($start !== false) {
                    $end = mb_strrpos($raw, '}');
                    if ($end !== false && $end >= $start) {
                        $raw = mb_substr($raw, $start, $end - $start + 1);
                    }
                }
                $decoded = @json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded['detected']) && !empty($decoded['group_name']) && trim((string)($decoded['content'] ?? '')) !== '') {
                    $g = trim((string)$decoded['group_name']);
                    $c = trim((string)$decoded['content']);
                    $convId = null;
                    if (isset($nameToId[$g])) {
                        $convId = $nameToId[$g];
                    } else {
                        foreach ($nameToId as $listName => $id) {
                            if ($g === $listName || mb_strpos($listName, $g) !== false || mb_strpos($g, $listName) !== false) {
                                $convId = $id;
                                $g = $listName;
                                break;
                            }
                        }
                    }
                    // 解釈できた場合は group_name / content を返す。conversation_id は解決できれば付与（未解決時はフロントで getConversationIdByName にフォールバック）
                    $interpreted = ['detected' => true, 'group_name' => $g, 'content' => $c, 'conversation_id' => $convId];
                }
            }
        }
        successResponse($interpreted);
        break;
    
    case 'execute_voice_command':
        // 常時起動で「実行」と言ったときの全文を高度なLLMで解釈し、意図に応じたアクションを返す
        $fullTranscript = trim((string)($input['full_transcript'] ?? ''));
        if ($fullTranscript === '') {
            successResponse(['detected' => false, 'action' => null]);
            break;
        }
        $convList = [];
        $nameToId = [];
        try {
            $stmt = $pdo->prepare("
                SELECT c.id, c.name
                FROM conversations c
                INNER JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.left_at IS NULL
                WHERE cm.user_id = ?
            ");
            $stmt->execute([$user_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$row['id'];
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') {
                    $convList[] = $name;
                    $nameToId[$name] = $id;
                }
            }
        } catch (Throwable $e) {
            error_log("execute_voice_command conv list: " . $e->getMessage());
        }
        $frontendNames = $input['group_names'] ?? [];
        if (is_array($frontendNames)) {
            foreach (array_map('trim', $frontendNames) as $n) {
                if ($n !== '' && !in_array($n, $convList, true)) {
                    $convList[] = $n;
                }
            }
        }
        $groupsList = empty($convList) ? '（なし）' : implode('、', array_slice($convList, 0, 80));
        $systemPrompt = 'あなたはユーザーの音声発言を解釈し、必要に応じて文章を整えてから行動に移すアシスタントです。
ユーザーが「実行」「お願いします」等と言う直前までの発言全文から、ユーザーが何を求めているかを判定し、以下のいずれか1つだけをJSONで返してください。

【アクション種類】
1. 特定のグループ・会話にメッセージを送りたい → {"action":"send_to_group","group_name":"リストの名前そのまま1つ","content":"送信する本文（下記の「文章の編集」に従う）","to_recipient_names":["宛先1","宛先2"]}
   - 宛先がある場合（〇〇宛、〇〇さんへ、なおみさん宛、なおちゃん宛 等）は必ず to_recipient_names にユーザーが言った呼び方のまま配列で入れる（例: ["なおみさん"]）。「宛」「宛て」「へ」が付いていたら必ず含める。宛先が無い場合のみ省略または空配列。
2. メモを残したい（メモして、記録して等） → {"action":"add_memo","content":"メモ本文"}
3. タスクを追加したい（タスクに追加、やること等） → {"action":"add_task","title":"タスク名","description":"詳細（省略可）"}
4. 上記以外（秘書への質問・依頼・雑談） → {"action":"chat","message":"秘書に伝えるメッセージ全文"}

【文章の編集（send_to_group の content のみ）】
- content には、ユーザーの発言をそのまま書くのではなく、あなたが「どんな文章をどのグループに送るか」を考えたうえで**編集した送信用の一文**を入れる。
- 挨拶・敬語・宛名（〇〇さん宛など）を適宜含め、そのグループチャットにそのまま投稿して自然な形にする。
- 例: ユーザーが「今から事務局に行くって事務局に送っといてなおちゃん宛実行」→ content は「なおちゃん、今から事務局に向かいます。」のように整える。

【ルール】
- group_name は必ず【グループ名リスト】に含まれる名前を1つだけそのまま使う。推測で別名を返さない。
- 意図が不明な場合や該当なし → {"action":null} または {"detected":false}
- 返答はJSONのみ1行。説明・改行・マークダウンは一切つけない。';
        $userPrompt = "【グループ名リスト】\n" . $groupsList . "\n\n【ユーザーの発言全文】\n" . $fullTranscript . "\n\n上記からユーザーの意図を判定し、JSONのみ返答してください。";
        $result = ['detected' => false, 'action' => null];
        if (function_exists('geminiChat') && function_exists('isGeminiAvailable') && isGeminiAvailable()) {
            $geminiResult = geminiChat($userPrompt, [], $systemPrompt, null);
            if (!empty($geminiResult['success']) && !empty($geminiResult['response'])) {
                $raw = trim($geminiResult['response']);
                $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw);
                $start = mb_strpos($raw, '{');
                if ($start !== false) {
                    $end = mb_strrpos($raw, '}');
                    if ($end !== false && $end >= $start) {
                        $raw = mb_substr($raw, $start, $end - $start + 1);
                    }
                }
                $decoded = @json_decode($raw, true);
                if (is_array($decoded)) {
                    $act = $decoded['action'] ?? null;
                    if ($act === 'send_to_group') {
                        $g = trim((string)($decoded['group_name'] ?? ''));
                        $c = trim((string)($decoded['content'] ?? ''));
                        if ($g !== '' && $c !== '') {
                            $convId = null;
                            if (isset($nameToId[$g])) {
                                $convId = $nameToId[$g];
                            } else {
                                foreach ($nameToId as $listName => $id) {
                                    if ($g === $listName || mb_strpos($listName, $g) !== false || mb_strpos($g, $listName) !== false) {
                                        $convId = $id;
                                        $g = $listName;
                                        break;
                                    }
                                }
                            }
                            $mentionIds = [];
                            if ($convId && $convId > 0) {
                                $members = [];
                                try {
                                    $stmtM = $pdo->prepare("
                                        SELECT u.id, COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) AS display_name
                                        FROM conversation_members cm
                                        INNER JOIN users u ON u.id = cm.user_id
                                        WHERE cm.conversation_id = ? AND cm.left_at IS NULL
                                    ");
                                    $stmtM->execute([$convId]);
                                    while ($rowM = $stmtM->fetch(PDO::FETCH_ASSOC)) {
                                        $members[] = ['id' => (int)$rowM['id'], 'display_name' => trim((string)($rowM['display_name'] ?? ''))];
                                    }
                                } catch (Throwable $e) {
                                    error_log("execute_voice_command members: " . $e->getMessage());
                                }
                                $toNames = $decoded['to_recipient_names'] ?? null;
                                if (is_array($toNames) && !empty($toNames) && !empty($members)) {
                                    $memberNames = array_map(function ($m) { return $m['display_name']; }, $members);
                                    $memberListStr = implode('、', array_slice($memberNames, 0, 50));
                                    $userSaidStr = implode('、', array_map('trim', $toNames));
                                    $resolvePrompt = 'ユーザーが「宛先」として言った名前を、以下のグループメンバー名のいずれかに対応させてください。返答はJSONのみ1行。{"to_display_names":["リストの名前そのまま"]} 複数該当する場合は配列に複数。該当が無い場合は空配列。説明は不要。';
                                    $resolveUser = "【ユーザーが言った宛先】\n" . $userSaidStr . "\n\n【このグループのメンバー表示名（この中から選ぶ）】\n" . $memberListStr;
                                    $resolvedNames = [];
                                    if (function_exists('geminiChat') && function_exists('isGeminiAvailable') && isGeminiAvailable()) {
                                        $res = geminiChat($resolveUser, [], $resolvePrompt, null);
                                        if (!empty($res['success']) && !empty($res['response'])) {
                                            $raw = trim($res['response']);
                                            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw);
                                            $start = mb_strpos($raw, '{');
                                            if ($start !== false) {
                                                $end = mb_strrpos($raw, '}');
                                                if ($end !== false && $end >= $start) {
                                                    $raw = mb_substr($raw, $start, $end - $start + 1);
                                                }
                                            }
                                            $resDec = @json_decode($raw, true);
                                            if (is_array($resDec) && isset($resDec['to_display_names']) && is_array($resDec['to_display_names'])) {
                                                $resolvedNames = array_map('trim', $resDec['to_display_names']);
                                            }
                                        }
                                    }
                                    if (empty($resolvedNames)) {
                                        $normalize = function ($s) {
                                            $s = trim($s);
                                            $s = preg_replace('/さん$|ちゃん$|くん$|様$/u', '', $s);
                                            return $s;
                                        };
                                        foreach ($toNames as $name) {
                                            $name = trim((string)$name);
                                            if ($name === '') continue;
                                            $nameNorm = $normalize($name);
                                            foreach ($members as $m) {
                                                if ((int)$m['id'] === (int)$user_id) continue;
                                                $dn = $m['display_name'];
                                                $dnNorm = $normalize($dn);
                                                if ($dn === $name || $dnNorm === $nameNorm
                                                    || mb_strpos($dn, $name) !== false || mb_strpos($name, $dn) !== false
                                                    || mb_strpos($dnNorm, $nameNorm) !== false || mb_strpos($nameNorm, $dnNorm) !== false) {
                                                    $mentionIds[] = (int)$m['id'];
                                                    break;
                                                }
                                            }
                                        }
                                    } else {
                                        foreach ($resolvedNames as $resName) {
                                            if ($resName === '') continue;
                                            foreach ($members as $m) {
                                                if ((int)$m['id'] === (int)$user_id) continue;
                                                if ($m['display_name'] === $resName) {
                                                    $mentionIds[] = (int)$m['id'];
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    $mentionIds = array_values(array_unique($mentionIds));
                                }
                            }
                            $result = ['detected' => true, 'action' => 'send_to_group', 'group_name' => $g, 'content' => $c, 'conversation_id' => $convId, 'mention_ids' => $mentionIds];
                        }
                    } elseif ($act === 'add_memo') {
                        $c = trim((string)($decoded['content'] ?? ''));
                        if ($c !== '') {
                            $result = ['detected' => true, 'action' => 'add_memo', 'content' => $c];
                        }
                    } elseif ($act === 'add_task') {
                        $title = trim((string)($decoded['title'] ?? ''));
                        $desc = trim((string)($decoded['description'] ?? $title));
                        if ($title !== '') {
                            $result = ['detected' => true, 'action' => 'add_task', 'title' => $title, 'description' => $desc];
                        }
                    } elseif ($act === 'chat') {
                        $msg = trim((string)($decoded['message'] ?? ''));
                        if ($msg !== '') {
                            $result = ['detected' => true, 'action' => 'chat', 'message' => $msg];
                        }
                    }
                }
            }
        }
        successResponse($result);
        break;
        
    case 'clear_history':
        // 会話履歴をクリア
        $stmt = $pdo->prepare("DELETE FROM ai_conversations WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $deletedCount = $stmt->rowCount();
        
        successResponse(['deleted_count' => $deletedCount], '会話履歴をクリアしました');
        break;
        
    case 'save_secretary_name':
        // 秘書の名前を保存
        $name = trim($input['name'] ?? '');
        
        if (empty($name)) {
            errorResponse('名前を入力してください');
        }
        
        if (mb_strlen($name) > 20) {
            errorResponse('名前は20文字以内で入力してください');
        }
        
        // ユーザー設定に保存（ai_secretary_nameカラムがあれば使用、なければuser_settingsテーブル）
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings (user_id, secretary_name, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE secretary_name = ?, updated_at = NOW()
            ");
            $stmt->execute([$user_id, $name, $name]);
        } catch (Exception $e) {
            error_log("AI secretary name save error: " . $e->getMessage());
            errorResponse('名前の保存に失敗しました。しばらくしてから再度お試しください。');
        }
        
        successResponse(['name' => $name], '名前を保存しました');
        break;
        
    case 'get_secretary_name':
        // 秘書の名前を取得
        $name = 'あなたの秘書'; // デフォルト
        
        try {
            $stmt = $pdo->prepare("SELECT secretary_name FROM user_ai_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            if ($result && !empty($result['secretary_name'])) {
                $name = $result['secretary_name'];
            }
        } catch (Exception $e) {
            // テーブルがない場合はデフォルト名を使用
        }
        
        successResponse(['name' => $name]);
        break;
        
    case 'save_character_type':
        // キャラクタータイプを保存
        $type = $input['type'] ?? '';
        
        $validTypes = ['female_20s', 'male_20s'];
        if (!in_array($type, $validTypes)) {
            errorResponse('無効なキャラクタータイプです');
        }
        
        try {
            // まず基本カラムのみで試行
            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings (user_id, character_type, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE character_type = ?, updated_at = NOW()
            ");
            $stmt->execute([$user_id, $type, $type]);
            
            // character_selectedカラムがあれば更新
            try {
                $pdo->prepare("UPDATE user_ai_settings SET character_selected = 1 WHERE user_id = ?")->execute([$user_id]);
            } catch (Exception $e) {
                // カラムがない場合は無視
            }
        } catch (Exception $e) {
            error_log("AI character type save error: " . $e->getMessage());
            errorResponse('保存に失敗しました');
        }
        
        successResponse(['type' => $type, 'character_selected' => true], 'キャラクタータイプを保存しました');
        break;
        
    case 'save_custom_instructions':
        $instructions = trim($input['instructions'] ?? '');
        
        if (mb_strlen($instructions) > 2000) {
            errorResponse('指示は2000文字以内で入力してください');
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings (user_id, custom_instructions, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE custom_instructions = ?, updated_at = NOW()
            ");
            $stmt->execute([$user_id, $instructions, $instructions]);
        } catch (Exception $e) {
            error_log("AI custom instructions save error: " . $e->getMessage());
            errorResponse('保存に失敗しました');
        }
        
        successResponse(['instructions' => $instructions], '設定を保存しました');
        break;

    case 'update_clone_settings':
        $lang = $input['clone_training_language'] ?? null;
        $autoReply = isset($input['clone_auto_reply_enabled']) ? ((int)$input['clone_auto_reply_enabled'] ? 1 : 0) : null;
        if ($lang === null && $autoReply === null) {
            errorResponse('clone_training_language または clone_auto_reply_enabled を指定してください');
        }
        $allowedLang = ['ja', 'en', 'zh'];
        try {
            $setClauses = ['updated_at = NOW()'];
            $params = [];
            if ($lang !== null) {
                $lang = in_array($lang, $allowedLang, true) ? $lang : 'ja';
                $setClauses[] = 'clone_training_language = ?';
                $params[] = $lang;
            }
            if ($autoReply !== null) {
                $setClauses[] = 'clone_auto_reply_enabled = ?';
                $params[] = $autoReply;
            }
            if (count($params) === 0) {
                successResponse();
                break;
            }
            $params[] = $user_id;
            $sql = "UPDATE user_ai_settings SET " . implode(', ', $setClauses) . " WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                $insLang = $lang !== null ? $lang : 'ja';
                $insAuto = $autoReply !== null ? $autoReply : 0;
                $pdo->prepare("INSERT INTO user_ai_settings (user_id, clone_training_language, clone_auto_reply_enabled, updated_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$user_id, $insLang, $insAuto]);
            }
        } catch (Throwable $e) {
            error_log("update_clone_settings error: " . $e->getMessage());
            errorResponse('保存に失敗しました');
        }
        successResponse([], '設定を保存しました');
        break;

    case 'save_personality':
        $personality = $input['personality'] ?? null;
        if (!is_array($personality)) {
            errorResponse('性格データが不正です');
        }

        $allowedKeys = ['pronoun', 'tone', 'character', 'expertise', 'behavior', 'avoid', 'other'];
        $sanitized = [];
        foreach ($allowedKeys as $key) {
            $val = trim($personality[$key] ?? '');
            if (mb_strlen($val) > 500) {
                errorResponse("「{$key}」は500文字以内で入力してください");
            }
            $sanitized[$key] = $val;
        }

        $personalityJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE);

        $labels = [
            'pronoun' => '一人称・呼び方',
            'tone' => '話し方・口調',
            'character' => '性格・態度',
            'expertise' => '得意分野・知識',
            'behavior' => '行動スタイル',
            'avoid' => '禁止事項・注意点',
            'other' => 'その他の指示'
        ];
        $combined = '';
        foreach ($sanitized as $k => $v) {
            if ($v !== '') {
                $combined .= ($labels[$k] ?? $k) . ': ' . $v . "\n";
            }
        }
        $combined = trim($combined);
        if (mb_strlen($combined) > 3500) {
            $combined = mb_substr($combined, 0, 3500);
        }

        $deliberationMax = isset($input['deliberation_max_seconds']) ? max(60, min(1800, (int)$input['deliberation_max_seconds'])) : null;
        $proactiveEnabled = isset($input['proactive_message_enabled']) ? ((int)$input['proactive_message_enabled'] ? 1 : 0) : null;
        $proactiveHour = isset($input['proactive_message_hour']) ? max(0, min(23, (int)$input['proactive_message_hour'])) : null;
        $todayMorningEnabled = isset($input['today_topics_morning_enabled']) ? ((int)$input['today_topics_morning_enabled'] ? 1 : 0) : null;
        $todayEveningEnabled = isset($input['today_topics_evening_enabled']) ? ((int)$input['today_topics_evening_enabled'] ? 1 : 0) : null;
        $todayMorningHour = isset($input['today_topics_morning_hour']) ? max(6, min(7, (int)$input['today_topics_morning_hour'])) : null;
        $todayPaidPlan = isset($input['today_topics_paid_plan']) ? ((int)$input['today_topics_paid_plan'] ? 1 : 0) : null;

        try {
            $setClauses = ['custom_instructions = ?', 'personality_json = ?', 'updated_at = NOW()'];
            $params = [$combined, $personalityJson];

            if ($deliberationMax !== null) {
                $setClauses[] = 'deliberation_max_seconds = ?';
                $params[] = $deliberationMax;
            }
            if ($proactiveEnabled !== null) {
                $setClauses[] = 'proactive_message_enabled = ?';
                $params[] = $proactiveEnabled;
            }
            if ($proactiveHour !== null) {
                $setClauses[] = 'proactive_message_hour = ?';
                $params[] = $proactiveHour;
            }
            if ($todayMorningEnabled !== null) {
                $setClauses[] = 'today_topics_morning_enabled = ?';
                $params[] = $todayMorningEnabled;
            }
            if ($todayEveningEnabled !== null) {
                $setClauses[] = 'today_topics_evening_enabled = ?';
                $params[] = $todayEveningEnabled;
            }
            if ($todayMorningHour !== null) {
                $setClauses[] = 'today_topics_morning_hour = ?';
                $params[] = $todayMorningHour;
            }
            if ($todayPaidPlan !== null) {
                $setClauses[] = 'today_topics_paid_plan = ?';
                $params[] = $todayPaidPlan;
            }

            $setStr = implode(', ', $setClauses);

            $insertCols = 'user_id, custom_instructions, personality_json';
            $insertVals = '?, ?, ?';
            $insertParams = [$user_id, $combined, $personalityJson];

            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings ({$insertCols}, updated_at) VALUES ({$insertVals}, NOW())
                ON DUPLICATE KEY UPDATE {$setStr}
            ");
            $stmt->execute(array_merge($insertParams, $params));

            // 推し（今日の話題 計画書 3.7）：today_topics_oshi / today_topics_oshi_name があれば user_topic_interests を更新
            $oshiValue = null;
            if (array_key_exists('today_topics_oshi', $input)) {
                $oshiValue = trim((string)($input['today_topics_oshi'] ?? ''));
            } elseif (array_key_exists('today_topics_oshi_name', $input)) {
                $oshiValue = trim((string)($input['today_topics_oshi_name'] ?? ''));
            }
            if ($oshiValue !== null) {
                try {
                    $del = $pdo->prepare("DELETE FROM user_topic_interests WHERE user_id = ? AND interest_type = 'oshi'");
                    $del->execute([$user_id]);
                    if ($oshiValue !== '') {
                        $ins = $pdo->prepare("INSERT INTO user_topic_interests (user_id, interest_type, value, created_at) VALUES (?, 'oshi', ?, NOW())");
                        $ins->execute([$user_id, $oshiValue]);
                    }
                } catch (Throwable $e) {
                    error_log("today_topics oshi save: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("AI personality save error: " . $e->getMessage());
            errorResponse('性格設定の保存に失敗しました');
        }

        successResponse([
            'personality' => $sanitized,
            'custom_instructions' => $combined
        ], '性格設定を保存しました');
        break;
        
    case 'save_user_profile':
        // ユーザープロファイルを保存（秘書が記憶する個人情報）
        $profile = trim($input['profile'] ?? '');
        
        if (mb_strlen($profile) > 5000) {
            errorResponse('プロファイルは5000文字以内で入力してください');
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings (user_id, user_profile, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE user_profile = ?, updated_at = NOW()
            ");
            $stmt->execute([$user_id, $profile, $profile]);
        } catch (Exception $e) {
            error_log("AI user profile save error: " . $e->getMessage());
            errorResponse('保存に失敗しました');
        }
        
        successResponse(['profile' => $profile], 'プロファイルを保存しました');
        break;
        
    case 'update_user_profile':
        // ユーザープロファイルに情報を追加（会話から学習した内容を追記）
        $newInfo = trim($input['info'] ?? '');
        
        if (empty($newInfo)) {
            errorResponse('追加する情報がありません');
        }
        
        try {
            // 既存のプロファイルを取得
            $stmt = $pdo->prepare("SELECT user_profile FROM user_ai_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            
            $currentProfile = $result ? ($result['user_profile'] ?? '') : '';
            $updatedProfile = $currentProfile . "\n" . date('Y-m-d') . ": " . $newInfo;
            
            // 5000文字を超えたら古い情報から削除
            if (mb_strlen($updatedProfile) > 5000) {
                $updatedProfile = mb_substr($updatedProfile, -4500);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO user_ai_settings (user_id, user_profile, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE user_profile = ?, updated_at = NOW()
            ");
            $stmt->execute([$user_id, $updatedProfile, $updatedProfile]);
        } catch (Exception $e) {
            error_log("AI user profile update error: " . $e->getMessage());
            errorResponse('保存に失敗しました');
        }
        
        successResponse([], '情報を記憶しました');
        break;
        
    case 'feedback':
        // フィードバックを送信
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $is_helpful = $input['is_helpful'] ?? null;
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 自分の会話か確認
        $stmt = $pdo->prepare("SELECT id FROM ai_conversations WHERE id = ? AND user_id = ?");
        $stmt->execute([$conversation_id, $user_id]);
        if (!$stmt->fetch()) {
            errorResponse('会話が見つかりません', 404);
        }
        
        $pdo->prepare("
            UPDATE ai_conversations SET is_helpful = ?, feedback_at = NOW()
            WHERE id = ?
        ")->execute([$is_helpful ? 1 : 0, $conversation_id]);
        
        successResponse([], 'フィードバックを送信しました');
        break;
        
    case 'debug':
        // デバッグ用：Geminiの状態を確認
        $debug = [
            'gemini_helper_exists' => file_exists(__DIR__ . '/../includes/gemini_helper.php'),
            'function_isGeminiAvailable' => function_exists('isGeminiAvailable'),
            'function_geminiChat' => function_exists('geminiChat'),
            'gemini_api_key_defined' => defined('GEMINI_API_KEY'),
            'gemini_api_key_set' => defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY),
            'gemini_api_key_length' => defined('GEMINI_API_KEY') ? strlen(GEMINI_API_KEY) : 0,
            'gemini_api_key_prefix' => defined('GEMINI_API_KEY') ? substr(GEMINI_API_KEY, 0, 10) . '...' : '',
            'ai_config_local_exists' => file_exists(__DIR__ . '/../config/ai_config.local.php'),
            'isGeminiAvailable_result' => function_exists('isGeminiAvailable') ? isGeminiAvailable() : false,
            'curl_version' => function_exists('curl_version') ? curl_version()['version'] : 'not available',
        ];
        
        // テスト呼び出し
        if (function_exists('geminiChat')) {
            $testResult = geminiChat('こんにちは', [], null);
            $debug['test_call'] = [
                'success' => $testResult['success'],
                'error' => $testResult['error'] ?? null,
                'response_preview' => isset($testResult['response']) ? mb_substr($testResult['response'], 0, 100) : null
            ];
        }
        
        successResponse($debug);
        break;
        
    // =====================================================
    // リマインダー機能
    // =====================================================
    
    case 'create_reminder':
        // リマインダーを作成（POST/GET両対応）
        $title = trim($input['title'] ?? $_GET['title'] ?? '');
        $description = trim($input['description'] ?? $_GET['description'] ?? '');
        $remind_at = $input['remind_at'] ?? $_GET['remind_at'] ?? '';
        $remind_type = $input['remind_type'] ?? $_GET['remind_type'] ?? 'once';
        
        if (empty($title)) {
            errorResponse('リマインダーのタイトルを入力してください');
        }
        
        if (empty($remind_at)) {
            errorResponse('通知日時を指定してください');
        }
        
        // 日時をパース
        $remindDateTime = strtotime($remind_at);
        if ($remindDateTime === false) {
            errorResponse('日時の形式が正しくありません');
        }
        
        // 過去の日時はエラー
        if ($remindDateTime < time()) {
            errorResponse('過去の日時は指定できません');
        }
        
        $validTypes = ['once', 'daily', 'weekly', 'monthly', 'yearly'];
        if (!in_array($remind_type, $validTypes)) {
            $remind_type = 'once';
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_reminders (user_id, title, description, remind_at, remind_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $title,
                $description,
                date('Y-m-d H:i:s', $remindDateTime),
                $remind_type
            ]);
            $reminder_id = $pdo->lastInsertId();
            
            successResponse([
                'reminder_id' => (int)$reminder_id,
                'title' => $title,
                'remind_at' => date('Y-m-d H:i:s', $remindDateTime),
                'remind_type' => $remind_type
            ], 'リマインダーを設定しました');
        } catch (Exception $e) {
            error_log("Reminder create error: " . $e->getMessage());
            errorResponse('リマインダーの作成に失敗しました');
        }
        break;
        
    case 'get_reminders':
        // リマインダー一覧を取得
        $includeCompleted = ($input['include_completed'] ?? $_GET['include_completed'] ?? '0') === '1';
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        
        try {
            if ($includeCompleted) {
                $stmt = $pdo->prepare("
                    SELECT * FROM ai_reminders
                    WHERE user_id = ?
                    ORDER BY remind_at ASC
                    LIMIT ?
                ");
                $stmt->execute([$user_id, $limit]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM ai_reminders
                    WHERE user_id = ? AND is_active = 1
                    ORDER BY remind_at ASC
                    LIMIT ?
                ");
                $stmt->execute([$user_id, $limit]);
            }
            $reminders = $stmt->fetchAll();
            
            // 数値型を整数にキャスト
            foreach ($reminders as &$r) {
                $r['id'] = (int)$r['id'];
                $r['user_id'] = (int)$r['user_id'];
                $r['is_notified'] = (int)$r['is_notified'];
                $r['is_active'] = (int)$r['is_active'];
            }
            
            successResponse(['reminders' => $reminders]);
        } catch (Exception $e) {
            error_log("Reminder get error: " . $e->getMessage());
            successResponse(['reminders' => []]);
        }
        break;
        
    case 'delete_reminder':
        // リマインダーを削除
        $reminder_id = (int)($input['reminder_id'] ?? 0);
        
        if (!$reminder_id) {
            errorResponse('リマインダーIDが必要です');
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM ai_reminders WHERE id = ? AND user_id = ?");
            $stmt->execute([$reminder_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                successResponse([], 'リマインダーを削除しました');
            } else {
                errorResponse('リマインダーが見つかりません', 404);
            }
        } catch (Exception $e) {
            error_log("Reminder delete error: " . $e->getMessage());
            errorResponse('削除に失敗しました');
        }
        break;
        
    case 'get_pending_notifications':
        // 未読のリマインダー通知を取得（チャット画面用）
        try {
            $stmt = $pdo->prepare("
                SELECT r.*, l.notified_at
                FROM ai_reminders r
                LEFT JOIN ai_reminder_logs l ON r.id = l.reminder_id AND l.status = 'sent'
                WHERE r.user_id = ? 
                AND r.is_active = 1 
                AND r.remind_at <= NOW()
                AND (r.is_notified = 0 OR l.status = 'sent')
                ORDER BY r.remind_at DESC
                LIMIT 10
            ");
            $stmt->execute([$user_id]);
            $notifications = $stmt->fetchAll();
            
            successResponse(['notifications' => $notifications]);
        } catch (Throwable $e) {
            error_log("get_pending_notifications: " . $e->getMessage());
            successResponse(['notifications' => []]);
        }
        break;
        
    case 'mark_reminder_read':
        // リマインダー通知を既読にする
        $reminder_id = (int)($input['reminder_id'] ?? 0);
        
        if (!$reminder_id) {
            errorResponse('リマインダーIDが必要です');
        }
        
        try {
            // 繰り返しなしの場合は非アクティブに
            $stmt = $pdo->prepare("
                UPDATE ai_reminders 
                SET is_notified = 1, is_active = CASE WHEN remind_type = 'once' THEN 0 ELSE 1 END
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$reminder_id, $user_id]);
            
            // ログを既読に更新
            $pdo->prepare("
                UPDATE ai_reminder_logs SET status = 'read' WHERE reminder_id = ? AND user_id = ?
            ")->execute([$reminder_id, $user_id]);
            
            successResponse([], '既読にしました');
        } catch (Exception $e) {
            errorResponse('更新に失敗しました');
        }
        break;
        
    case 'snooze_reminder':
        // リマインダーをスヌーズ（指定分後に再通知）
        $reminder_id = (int)($input['reminder_id'] ?? 0);
        $snooze_minutes = (int)($input['minutes'] ?? 5);
        
        if (!$reminder_id) {
            errorResponse('リマインダーIDが必要です');
        }
        
        // スヌーズ時間を制限（1〜60分）
        $snooze_minutes = max(1, min(60, $snooze_minutes));
        
        try {
            $newRemindAt = date('Y-m-d H:i:s', strtotime("+{$snooze_minutes} minutes"));
            
            $stmt = $pdo->prepare("
                UPDATE ai_reminders 
                SET remind_at = ?, is_notified = 0, is_active = 1, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$newRemindAt, $reminder_id, $user_id]);
            
            successResponse([
                'reminder_id' => $reminder_id,
                'new_remind_at' => $newRemindAt
            ], "{$snooze_minutes}分後に再通知します");
        } catch (Exception $e) {
            errorResponse('スヌーズに失敗しました');
        }
        break;
        
    // =====================================================
    // 記憶機能
    // =====================================================
    
    case 'save_memory':
        // ユーザーの記憶を保存
        $content = trim($input['content'] ?? $_GET['content'] ?? '');
        $category = trim($input['category'] ?? $_GET['category'] ?? 'general');
        
        if (empty($content)) {
            errorResponse('記憶する内容がありません');
        }
        
        // カテゴリを自動判定
        if ($category === 'general') {
            if (preg_match('/(息子|娘|妻|夫|子供|親|母|父|兄|弟|姉|妹|家族)/u', $content)) {
                $category = 'family';
            } elseif (preg_match('/(猫|犬|ペット|うさぎ|鳥|ハムスター)/u', $content)) {
                $category = 'pet';
            } elseif (preg_match('/(誕生日|記念日|結婚|anniversary)/ui', $content)) {
                $category = 'anniversary';
            } elseif (preg_match('/(仕事|会社|職業|勤務)/u', $content)) {
                $category = 'work';
            } elseif (preg_match('/(好き|嫌い|趣味|hobby)/ui', $content)) {
                $category = 'preference';
            }
        }
        
        try {
            // 重複チェック（同じ内容は保存しない）
            $checkStmt = $pdo->prepare("SELECT id FROM ai_user_memories WHERE user_id = ? AND content = ?");
            $checkStmt->execute([$user_id, $content]);
            if ($checkStmt->fetch()) {
                successResponse(['already_exists' => true], '既に記憶しています');
                break;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO ai_user_memories (user_id, category, content)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $category, $content]);
            $memory_id = $pdo->lastInsertId();
            
            successResponse([
                'memory_id' => (int)$memory_id,
                'category' => $category,
                'content' => $content
            ], '記憶しました');
        } catch (Exception $e) {
            error_log("Memory save error: " . $e->getMessage());
            errorResponse('記憶の保存に失敗しました');
        }
        break;
        
    case 'get_memories':
        // ユーザーの記憶一覧を取得
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM ai_user_memories
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$user_id]);
            $memories = $stmt->fetchAll();
            
            successResponse(['memories' => $memories]);
        } catch (Exception $e) {
            successResponse(['memories' => []]);
        }
        break;
        
    case 'delete_memory':
        // 記憶を削除
        $memory_id = (int)($input['memory_id'] ?? $_GET['memory_id'] ?? 0);
        
        if (!$memory_id) {
            errorResponse('記憶IDが必要です');
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM ai_user_memories WHERE id = ? AND user_id = ?");
            $stmt->execute([$memory_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                successResponse([], '記憶を削除しました');
            } else {
                errorResponse('記憶が見つかりません', 404);
            }
        } catch (Exception $e) {
            errorResponse('削除に失敗しました');
        }
        break;

    case 'refine_minutes':
        // 音声文字起こしを議事録として整文（2分以上の音声用）
        $transcript = trim((string)($input['transcript'] ?? ''));
        if ($transcript === '') {
            errorResponse('文字起こしテキストを送信してください');
        }
        if (mb_strlen($transcript) > 50000) {
            errorResponse('文字数が多すぎます（50000文字以内）');
        }
        $systemPrompt = 'あなたは議事録の整文担当です。ユーザーから渡される発言の文字起こしを、誤字・言い淀み・重複を除き、読みやすい議事録形式（箇条書きまたは段落）に整えてください。話し言葉は書き言葉に直し、内容を変えずに簡潔にまとめてください。出力は整文したテキストのみを返し、説明や前置きは不要です。';
        if (!function_exists('geminiChat')) {
            errorResponse('AI機能が利用できません');
        }
        $result = geminiChat($transcript, [], $systemPrompt, null);
        if (!$result['success']) {
            errorResponse($result['error'] ?? '整文に失敗しました');
        }
        $refined = trim((string)$result['response']);
        if ($refined === '') {
            $refined = $transcript;
        }
        successResponse(['refined_text' => $refined]);
        break;

    case 'extract_improvement_report':
        // 改善提案の記録（ユーザーが肯定した後の確認済み内容を構造化してDB保存）。A+B+CコンテキストをGeminiに渡す
        $userMessage = trim((string)($input['user_message'] ?? $input['user_message'] ?? ''));
        $aiReply = trim((string)($input['ai_reply'] ?? $input['ai_reply'] ?? ''));
        if ($userMessage === '') {
            errorResponse('user_message を送信してください');
        }
        if (mb_strlen($userMessage) > 10000 || mb_strlen($aiReply) > 10000) {
            errorResponse('内容が長すぎます');
        }
        if (!function_exists('geminiChat')) {
            errorResponse('AI機能が利用できません');
        }
        $projectContext = '';
        if (function_exists('getImprovementContextForGemini')) {
            $projectContext = getImprovementContextForGemini(35000);
        }
        $systemPrompt = 'あなたは開発者向けの改善提案を整理し、具体的な改善計画を立案するアシスタントです。'
            . 'ユーザーとAI秘書の会話（聞き取り・確認のやり取り）から、以下のJSON形式のみを出力してください。'
            . '聞き取りで確認された内容を中心に、正確かつ具体的に抽出してください。説明や前置きは不要です。'
            . "\n" . '{"title":"短いタイトル（例: リアクションがリロードで消える）",'
            . '"problem_summary":"問題の内容要約（現在の状態を含む）",'
            . '"ui_location":"問題の場所（上パネル/左パネル/中央パネル/右パネル、携帯の場合はそれに沿った表現。不明なら空文字）",'
            . '"suspected_location":"想定される原因・ファイル名・処理名",'
            . '"suggested_fix":"改善計画：望ましい状態と、それを実現するための具体的な修正方針をステップで記載",'
            . '"related_files":"関連しそうなファイルをカンマ区切り（例: api/messages.php, includes/chat/scripts.php）"}';
        $userPrompt = ($projectContext !== '' ? $projectContext . "\n\n---\n\n" : '')
            . "【ユーザー発言】\n" . $userMessage . "\n\n【AI秘書の確認内容】\n" . ($aiReply !== '' ? $aiReply : '（なし：ユーザー発言のみから抽出してください）');
        $result = geminiChat($userPrompt, [], $systemPrompt, null);
        if (!$result['success']) {
            errorResponse($result['error'] ?? '改善提案の抽出に失敗しました');
        }
        $raw = trim((string)$result['response']);
        if ($raw === '') {
            errorResponse('改善提案の抽出結果が空でした');
        }
        // コードブロック```json ... ```を除去
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/```/', '', $cleaned);
        $cleaned = trim($cleaned);
        $json = null;
        if (preg_match('/\{[\s\S]*\}/', $cleaned, $m)) {
            $json = @json_decode($m[0], true);
        }
        if (!is_array($json)) {
            error_log('extract_improvement_report: Gemini raw response: ' . mb_substr($raw, 0, 500));
            errorResponse('改善提案の形式が不正です。再度お試しください。');
        }
        $title = isset($json['title']) ? trim((string)$json['title']) : '改善提案';
        if ($title === '') {
            $title = mb_substr($userMessage, 0, 80);
        }
        $problem_summary = isset($json['problem_summary']) ? trim((string)$json['problem_summary']) : $userMessage;
        $ui_location = isset($json['ui_location']) ? trim((string)$json['ui_location']) : null;
        $suspected_location = isset($json['suspected_location']) ? trim((string)$json['suspected_location']) : null;
        $suggested_fix = isset($json['suggested_fix']) ? trim((string)$json['suggested_fix']) : null;
        $related_files = isset($json['related_files']) ? trim((string)$json['related_files']) : null;
        if (mb_strlen($related_files) > 500) {
            $related_files = mb_substr($related_files, 0, 500);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO improvement_reports (user_id, title, problem_summary, ui_location, suspected_location, suggested_fix, related_files, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'ai_chat')");
            $stmt->execute([$user_id, $title, $problem_summary, $ui_location, $suspected_location, $suggested_fix, $related_files]);
            $reportId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                errorResponse('improvement_reports テーブルが存在しません。database/improvement_reports.sql を実行してください。');
            }
            throw $e;
        }
        successResponse(['success' => true, 'report_id' => $reportId, 'message' => '改善提案を記録しました']);
        break;

    // ============================================================
    // AIクローン: 返信提案 / 教材記録 / 会話記憶自動分析
    // ============================================================

    case 'suggest_reply': {
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $message_id = (int)($input['message_id'] ?? 0);
        if (!$conversation_id || !$message_id) {
            errorResponse('conversation_id と message_id を指定してください');
        }
        if (!function_exists('geminiChat') || !isGeminiAvailable()) {
            errorResponse('AI機能が利用できません');
        }

        $uid = (int)$user_id;

        $contextStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(m.extracted_text), ''), m.content) AS content, m.sender_id, u.display_name
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ? AND m.deleted_at IS NULL
            ORDER BY m.created_at DESC LIMIT 15");
        $contextStmt->execute([$conversation_id]);
        $contextMsgs = array_reverse($contextStmt->fetchAll(PDO::FETCH_ASSOC));

        $targetStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(m.extracted_text), ''), m.content) AS content, u.display_name AS sender_name
            FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?");
        $targetStmt->execute([$message_id]);
        $targetMsg = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetMsg) {
            errorResponse('対象メッセージが見つかりません');
        }

        $langStmt = $pdo->prepare("SELECT clone_training_language FROM user_ai_settings WHERE user_id = ?");
        $langStmt->execute([$uid]);
        $langRow = $langStmt->fetch(PDO::FETCH_ASSOC);
        $lang = $langRow['clone_training_language'] ?? 'ja';
        $langLabel = $lang === 'en' ? 'English' : ($lang === 'zh' ? '中文' : '日本語');

        $jmBlock = '';
        try {
            $fStmt = $pdo->prepare("SELECT id FROM user_ai_judgment_folders WHERE user_id = ?");
            $fStmt->execute([$uid]);
            $fids = $fStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($fids)) {
                $ph = implode(',', array_fill(0, count($fids), '?'));
                $iStmt = $pdo->prepare("SELECT title, content FROM user_ai_judgment_items WHERE folder_id IN ($ph) AND user_id = ? ORDER BY sort_order LIMIT 30");
                $iStmt->execute(array_merge($fids, [$uid]));
                $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
                $lines = [];
                foreach ($items as $it) {
                    $t = trim($it['title'] ?? '');
                    $c = trim($it['content'] ?? '');
                    if ($t || $c) $lines[] = ($t ? "### {$t}\n" : '') . $c;
                }
                if ($lines) $jmBlock = "\n\n【判断材料】\n" . implode("\n---\n", $lines);
            }
        } catch (Throwable $e) { error_log('suggest_reply jm: ' . $e->getMessage()); }

        $cmBlock = '';
        try {
            $cmStmt = $pdo->prepare("SELECT conversation_memory_summary FROM user_ai_settings WHERE user_id = ?");
            $cmStmt->execute([$uid]);
            $cmRow = $cmStmt->fetch(PDO::FETCH_ASSOC);
            if ($cmRow && !empty(trim((string)($cmRow['conversation_memory_summary'] ?? '')))) {
                $cmBlock = "\n\n【話し方・会話記憶】\n" . trim($cmRow['conversation_memory_summary']);
            }
        } catch (Throwable $e) {}

        $specBlock = '';
        try {
            $orgStmt = $pdo->prepare("SELECT c.organization_id FROM conversations c WHERE c.id = ?");
            $orgStmt->execute([$conversation_id]);
            $orgId = $orgStmt->fetchColumn();
            if ($orgId && function_exists('classifyIntent') && function_exists('searchOrgMemories')) {
                $intent = classifyIntent($targetMsg['content'], (int)$orgId);
                if ($intent['specialist_type'] !== 'secretary' && $intent['confidence'] >= 0.5) {
                    $mems = searchOrgMemories((int)$orgId, $intent['specialist_type'], $targetMsg['content'], 3);
                    if (!empty($mems)) {
                        $specLines = [];
                        foreach ($mems as $m) $specLines[] = "- {$m['title']}: {$m['content']}";
                        $specBlock = "\n\n【専門AIの参考情報】\n" . implode("\n", $specLines);
                    }
                }
            }
        } catch (Throwable $e) { error_log('suggest_reply spec: ' . $e->getMessage()); }

        $chatContext = '';
        foreach ($contextMsgs as $cm) {
            $chatContext .= $cm['display_name'] . ': ' . mb_substr($cm['content'], 0, 300) . "\n";
        }

        $myNameStmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $myNameStmt->execute([$uid]);
        $myName = $myNameStmt->fetchColumn() ?: 'ユーザー';

        $sysPrompt = "あなたは「{$myName}」のAIクローンです。{$myName}本人の口調・考え方で返信を作成してください。"
            . "\n回答は{$langLabel}で書いてください。返信本文のみを出力してください。"
            . $jmBlock . $cmBlock . $specBlock;

        $userPrompt = "【会話の流れ】\n{$chatContext}\n【返信が必要なメッセージ】\n{$targetMsg['sender_name']}: {$targetMsg['content']}\n\n上記に対する{$myName}としての返信を作成してください。";

        $result = geminiChat($userPrompt, [], $sysPrompt, null);
        if (!$result['success']) {
            errorResponse($result['error'] ?? '返信提案の生成に失敗しました');
        }
        $suggested = trim($result['response']);

        try {
            $ins = $pdo->prepare("INSERT INTO user_ai_reply_suggestions (user_id, conversation_id, message_id, suggested_content) VALUES (?, ?, ?, ?)");
            $ins->execute([$uid, $conversation_id, $message_id, $suggested]);
            $suggestionId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('suggest_reply insert: ' . $e->getMessage());
            $suggestionId = 0;
        }

        successResponse(['suggested_content' => $suggested, 'suggestion_id' => $suggestionId]);
        break;
    }

    case 'record_reply_correction': {
        $suggestion_id = (int)($input['suggestion_id'] ?? 0);
        $final_content = trim((string)($input['final_content'] ?? ''));
        if (!$suggestion_id) {
            errorResponse('suggestion_id を指定してください');
        }
        $uid = (int)$user_id;

        $stmt = $pdo->prepare("UPDATE user_ai_reply_suggestions SET final_content = ?, sent_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$final_content, $suggestion_id, $uid]);
        if ($stmt->rowCount() === 0) {
            errorResponse('提案が見つかりません');
        }

        $statsStmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN final_content IS NOT NULL AND final_content != suggested_content THEN 1 ELSE 0 END) AS modified
            FROM user_ai_reply_suggestions WHERE user_id = ? AND sent_at IS NOT NULL");
        $statsStmt->execute([$uid]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)$stats['total'];
        $modified = (int)$stats['modified'];
        $modificationRate = $total > 0 ? round($modified / $total * 100, 1) : 100;

        successResponse([
            'total_sent' => $total,
            'modified_count' => $modified,
            'modification_rate' => $modificationRate,
            'auto_reply_eligible' => ($total >= 20 && $modificationRate <= 20)
        ]);
        break;
    }

    case 'get_reply_stats': {
        $uid = (int)$user_id;
        try {
            $statsStmt = $pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN final_content IS NOT NULL AND final_content != suggested_content THEN 1 ELSE 0 END) AS modified
                FROM user_ai_reply_suggestions WHERE user_id = ? AND sent_at IS NOT NULL");
            $statsStmt->execute([$uid]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)$stats['total'];
            $modified = (int)$stats['modified'];
            $rate = $total > 0 ? round($modified / $total * 100, 1) : 100;
        } catch (Throwable $e) {
            $total = 0; $modified = 0; $rate = 100;
        }
        successResponse(['total_sent' => $total, 'modified_count' => $modified, 'modification_rate' => $rate, 'auto_reply_eligible' => ($total >= 20 && $rate <= 20)]);
        break;
    }

    case 'analyze_conversation_memory': {
        if (!function_exists('geminiChat') || !isGeminiAvailable()) {
            errorResponse('AI機能が利用できません');
        }
        $uid = (int)$user_id;

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
                $sample .= "返信（ユーザー修正後）: " . mb_substr($r['final_content'], 0, 150) . "\n\n";
            }
        }

        if (mb_strlen($sample) < 50) {
            errorResponse('分析に十分なデータがありません。もう少し秘書と会話してください。');
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
            errorResponse($result['error'] ?? '会話記憶の分析に失敗しました');
        }

        $raw = trim($result['response']);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $parsed = @json_decode($raw, true);
        if (!is_array($parsed)) {
            error_log('analyze_conversation_memory parse error: ' . mb_substr($raw, 0, 500));
            $summaryText = $raw;
        } else {
            $summaryText = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $pdo->prepare("INSERT INTO user_ai_settings (user_id, conversation_memory_summary, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE conversation_memory_summary = ?, updated_at = NOW()")
            ->execute([$uid, $summaryText, $summaryText]);

        successResponse(['conversation_memory_summary' => $summaryText]);
        break;
    }

    case 'save_clone_settings': {
        $uid = (int)$user_id;
        $updates = [];
        $params = [];
        if (isset($input['clone_training_language'])) {
            $lang = in_array($input['clone_training_language'], ['ja', 'en', 'zh']) ? $input['clone_training_language'] : 'ja';
            $updates[] = 'clone_training_language = ?';
            $params[] = $lang;
        }
        if (isset($input['clone_auto_reply_enabled'])) {
            $updates[] = 'clone_auto_reply_enabled = ?';
            $params[] = (int)$input['clone_auto_reply_enabled'] ? 1 : 0;
        }
        if (empty($updates)) {
            errorResponse('変更する設定を指定してください');
        }
        $params[] = $uid;
        $pdo->prepare("UPDATE user_ai_settings SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE user_id = ?")->execute($params);
        successResponse([], '設定を保存しました');
        break;
    }

    default:
        errorResponse('不明なアクションです');
}

} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    $errFile = $e->getFile();
    $errLine = $e->getLine();
    error_log("AI API fatal: {$errMsg} in {$errFile}:{$errLine}");
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200); // 500だとサーバーがHTMLで上書きする環境があるため
    }
    $userMessage = 'サーバーエラーが発生しました。しばらくしてからお試しください。';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $userMessage = $errMsg . ' (' . basename($errFile) . ':' . $errLine . ')';
    }
    $sendJsonError(['success' => false, 'message' => $userMessage]);
}


/**
 * デフォルト応答（AIが利用できない場合のフォールバック）
 */
function getDefaultResponse($language, $question = '') {
    // 基本的なパターンマッチングで応答
    $questionLower = mb_strtolower($question);
    
    // 挨拶
    if (preg_match('/(こんにちは|こんばんは|おはよう|はじめまして|よろしく)/u', $questionLower)) {
        return "こんにちは！何かお手伝いできることはありますか？😊";
    }
    
    // 感謝
    if (preg_match('/(ありがとう|サンキュー|助かり)/u', $questionLower)) {
        return "どういたしまして！また何かあればお気軽にどうぞ。";
    }
    
    // 自己紹介
    if (preg_match('/(あなたは誰|自己紹介|名前は)/u', $questionLower)) {
        return "私はあなたの秘書です！Social9での活動をサポートします。使い方や機能について、何でも聞いてくださいね。";
    }
    
    // 使い方
    if (preg_match('/(使い方|やり方|方法|どうやって|どうすれば)/u', $questionLower)) {
        return "具体的にどのような操作についてお知りになりたいですか？\n\n例えば：\n・メッセージの送り方\n・グループの作り方\n・通話の仕方\n・設定の変更方法\n\nなど、お気軽にお聞きください！";
    }
    
    // Social9について
    if (preg_match('/(social9|ソーシャルナイン)/ui', $questionLower)) {
        return "Social9は、安全で使いやすいコミュニケーションアプリです！\n\n【主な機能】\n・チャット（個人・グループ）\n・音声通話・ビデオ通話\n・画像・ファイル共有\n・自動翻訳（多言語対応）\n・タスク・メモ\n\n「〇〇のやり方」「〇〇ってどうするの」など、具体的に聞いてくださいね！";
    }
    
    // メッセージ・画像送信
    if (preg_match('/(メッセージ|メール|画像|写真|ファイル).*(送|やり方|方法)/u', $questionLower) || preg_match('/(送信|送り方|添付)/u', $questionLower)) {
        return "【メッセージ送信】入力欄に文字を入力して送信ボタン（➤）またはEnterキーで送れます。\n\n【画像】⊕ボタンからカメラ・ギャラリーを選べます（最大10MB）。\n【ファイル】⊕ボタンからPDFやWord等を添付できます（最大100MB）。\n\n他に知りたいことはありますか？";
    }
    
    // 通話
    if (preg_match('/(通話|電話|ビデオ).*(やり方|方法|始め|開始)/u', $questionLower) || preg_match('/(通話|電話)って/u', $questionLower)) {
        return "【通話の仕方】\n・音声通話：📞ボタンをタップ\n・ビデオ通話：📹ボタンをタップ\n\nDMなら相手に着信が届き、グループなら参加したい人が参加できます。最大50人まで同時通話可能です。";
    }
    
    // 通知
    if (preg_match('/(通知|着信音|プッシュ).*(オフ|オン|設定|鳴らない)/u', $questionLower)) {
        return "【通知の設定】\n1. 設定画面を開く\n2. 「通知」の項目で着信音・プッシュ通知をON/OFF\n\n携帯で「Social9の通知オン」が出たら「有効にする」をタップしてください。ブラウザのサイト設定で通知を許可する必要があります。";
    }
    
    // グループ
    if (preg_match('/(グループ|チャット).*(作|作成|新規)/u', $questionLower)) {
        return "【グループの作り方】\n1. 左サイドバーの「➕新しい会話」をタップ\n2. グループ名・説明を入力\n3. メンバーを追加\n4. 作成完了！\n\n管理者はメンバー招待・ピン留めができます。";
    }
    
    // 翻訳
    if (preg_match('/(翻訳|多言語|言語設定)/u', $questionLower)) {
        return "【翻訳機能】\n・メッセージの🌐ボタンで手動翻訳\n・3日以内のメッセージは自動翻訳される場合あり\n・表示言語は設定画面で変更可能（日本語/英語/中国語など）";
    }
    
    // デザイン
    if (preg_match('/(デザイン|テーマ|背景|見た目).*(変|変更)/u', $questionLower)) {
        return "【デザイン変更】\n「デザイン」ボタンでテーマ・背景・フォントサイズを変更できます。ライト/ダークモード、アクセントカラー、富士山などの背景画像が選べます。";
    }
    
    // タスク・メモ検索
    if (preg_match('/(タスク|メモ).*(まとめて|検索|報告|一覧)/u', $questionLower) || preg_match('/(まとめて|検索|報告).*(タスク|メモ)/u', $questionLower)) {
        return "【タスク・メモの検索】\nキーワードを指定すると、タスクとメモを検索してまとめます。\n\n例：\n・「2025年度の怪我をまとめて報告して」\n・「会議のタスクを検索して」\n・「〇〇の一覧を教えて」\n\nキーワードを含めてお試しください！";
    }
    
    // デフォルト
    $responses = [
        'ja' => "ご質問ありがとうございます！\n\n申し訳ありませんが、その質問への回答は現在準備中です。\n\n以下のことについてはお答えできます：\n・Social9の使い方\n・機能の説明\n・設定方法\n\n別の質問があればお気軽にどうぞ！",
        'en' => "Thank you for your question!\n\nI'm still learning, but I can help with:\n- How to use Social9\n- Feature explanations\n- Settings help\n\nFeel free to ask anything!",
        'zh-CN' => "感谢您的提问！\n\n我可以帮助您了解：\n- Social9的使用方法\n- 功能说明\n- 设置帮助\n\n请随时提问！"
    ];
    
    return $responses[$language] ?? $responses['ja'];
}





