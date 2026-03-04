<?php
/**
 * AI秘書API 診断スクリプト
 * api/ai.php の500エラー原因を特定するため
 * 使い方: ログイン後、https://social9.jp/api/ai-diagnostic.php にアクセス
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

$result = ['success' => true, 'steps' => []];

try {
    require_once __DIR__ . '/../config/session.php';
    $result['steps'][] = ['step' => 'session', 'status' => 'ok'];
} catch (Throwable $e) {
    $result['success'] = false;
    $result['steps'][] = ['step' => 'session', 'status' => 'error', 'message' => $e->getMessage()];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
if (empty($_SESSION['user_id'])) {
    $result['steps'][] = ['step' => 'login', 'status' => 'error', 'message' => 'ログインが必要です'];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$result['steps'][] = ['step' => 'login', 'status' => 'ok', 'user_id' => $_SESSION['user_id']];

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/app.php';
    $result['steps'][] = ['step' => 'config', 'status' => 'ok'];
} catch (Throwable $e) {
    $result['success'] = false;
    $result['steps'][] = ['step' => 'config', 'status' => 'error', 'message' => $e->getMessage()];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../config/ai_config.php';
    $result['steps'][] = ['step' => 'ai_config', 'status' => 'ok'];
} catch (Throwable $e) {
    $result['success'] = false;
    $result['steps'][] = ['step' => 'ai_config', 'status' => 'error', 'message' => $e->getMessage()];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/api-helpers.php';
    require_once __DIR__ . '/../includes/places_helper.php';
    require_once __DIR__ . '/../includes/task_memo_search_helper.php';
    $result['steps'][] = ['step' => 'includes', 'status' => 'ok'];
} catch (Throwable $e) {
    $result['success'] = false;
    $result['steps'][] = ['step' => 'includes', 'status' => 'error', 'message' => $e->getMessage()];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$tables = ['ai_conversations', 'user_ai_settings', 'ai_usage_logs', 'ai_reminders', 'ai_reminder_logs'];
$tableStatus = [];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
        $tableStatus[$t] = 'exists';
    } catch (Throwable $e) {
        $tableStatus[$t] = 'missing';
    }
}
$result['tables'] = $tableStatus;
$missing = array_filter($tableStatus, fn($v) => $v === 'missing');
if (!empty($missing)) {
    $result['recommendation'] = '以下のマイグレーションを実行してください: ' . implode(', ', array_keys($missing));
    $result['migrations'] = [
        'ai_conversations, user_ai_settings, ai_usage_logs' => 'database/migration_ai_tables_update.sql',
        'ai_reminders, ai_reminder_logs' => 'database/migration_ai_reminders.sql'
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
