<?php
/**
 * AI API 最小診断
 * どの段階で失敗するか切り分け用
 * 使い方: https://social9.jp/api/ai-ping.php にアクセス
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

$steps = ['start' => 'ok'];
$failed = null;

// Step 1: session
try {
    require_once __DIR__ . '/../config/session.php';
    $steps['session'] = 'ok';
} catch (Throwable $e) {
    $steps['session'] = 'error: ' . $e->getMessage();
    $failed = 'session';
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $steps['database'] = 'ok';
    } catch (Throwable $e) {
        $steps['database'] = 'error: ' . $e->getMessage();
        $failed = 'database';
    }
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../config/app.php';
        $steps['app'] = 'ok';
    } catch (Throwable $e) {
        $steps['app'] = 'error: ' . $e->getMessage();
        $failed = 'app';
    }
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../config/ai_config.php';
        $steps['ai_config'] = 'ok';
    } catch (Throwable $e) {
        $steps['ai_config'] = 'error: ' . $e->getMessage();
        $failed = 'ai_config';
    }
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../includes/api-helpers.php';
        $steps['api_helpers'] = 'ok';
    } catch (Throwable $e) {
        $steps['api_helpers'] = 'error: ' . $e->getMessage();
        $failed = 'api_helpers';
    }
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../includes/places_helper.php';
        $steps['places_helper'] = 'ok';
    } catch (Throwable $e) {
        $steps['places_helper'] = 'error: ' . $e->getMessage();
        $failed = 'places_helper';
    }
}

if (!$failed) {
    try {
        require_once __DIR__ . '/../includes/task_memo_search_helper.php';
        $steps['task_memo_search_helper'] = 'ok';
    } catch (Throwable $e) {
        $steps['task_memo_search_helper'] = 'error: ' . $e->getMessage();
        $failed = 'task_memo_search_helper';
    }
}

if (!$failed) {
    $geminiPath = __DIR__ . '/../includes/gemini_helper.php';
    if (file_exists($geminiPath)) {
        try {
            require_once $geminiPath;
            $steps['gemini_helper'] = 'ok';
            $steps['gemini_available'] = function_exists('isGeminiAvailable') && isGeminiAvailable() ? 'yes' : 'no (API key not set)';
        } catch (Throwable $e) {
            $steps['gemini_helper'] = 'error: ' . $e->getMessage();
            $failed = 'gemini_helper';
        }
    } else {
        $steps['gemini_helper'] = 'skipped (file not found)';
    }
}

if (!$failed) {
    $steps['is_logged_in'] = isLoggedIn() ? 'yes' : 'no';
}

if (!$failed && isLoggedIn()) {
    try {
        $pdo = getDB();
        $steps['getdb'] = 'ok';
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->query("SELECT 1 FROM user_ai_settings LIMIT 1");
        $steps['user_ai_settings_table'] = $stmt ? 'exists' : 'error';
    } catch (Throwable $e) {
        $steps['getdb_or_table'] = 'error: ' . $e->getMessage();
    }
}

// ai.php の get_settings と同じ処理をシミュレート
if (!$failed && isLoggedIn()) {
    try {
        $pdo = getDB();
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT secretary_name, character_type, custom_instructions FROM user_ai_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        $steps['get_settings_query'] = 'ok';
    } catch (Throwable $e) {
        $steps['get_settings_query'] = 'error: ' . $e->getMessage();
        $failed = 'get_settings_query';
    }
}

echo json_encode([
    'success' => $failed === null,
    'failed_at' => $failed,
    'steps' => $steps
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
