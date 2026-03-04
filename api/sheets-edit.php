<?php
/**
 * Googleスプレッドシート編集API（AI指示で改変）
 * POST: spreadsheet_id, instruction, sheet_name(任意)
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_sheets.php';
require_once __DIR__ . '/../includes/api-helpers.php';
require_once __DIR__ . '/../includes/google_sheets_helper.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('POSTでリクエストしてください', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$spreadsheet_id = trim($input['spreadsheet_id'] ?? '');
$instruction = trim($input['instruction'] ?? '');
$sheet_name = trim($input['sheet_name'] ?? '');

if ($spreadsheet_id === '' || $instruction === '') {
    errorResponse('spreadsheet_id と instruction は必須です');
}

if (!isGoogleSheetsEnabled()) {
    errorResponse('Googleスプレッドシート連携が有効ではありません', 503);
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$account = getGoogleSheetsAccount($pdo, $user_id);
if (!$account) {
    errorResponse('Googleスプレッドシートに未連携です。設定から連携してください', 403);
}

$client = getGoogleSheetsClient($account, $pdo);
if (!$client) {
    errorResponse('スプレッドシートの認証に失敗しました', 503);
}

$service = getSheetsService($client);

// シート名未指定ならメタデータから先頭シート
if ($sheet_name === '') {
    $meta = sheetsGetMetadata($service, $spreadsheet_id);
    if (!$meta || empty($meta['sheets'])) {
        errorResponse('スプレッドシートを取得できませんでした', 404);
    }
    $sheet_name = $meta['sheets'][0]['title'];
}

$rangeForRead = $sheet_name . '!A1:Z100';
$currentRows = sheetsReadRange($service, $spreadsheet_id, $rangeForRead);
if ($currentRows === null) {
    $currentRows = [];
}

$parsed = geminiParseSheetEditInstruction($instruction, $currentRows, $sheet_name);
if (!$parsed) {
    errorResponse('指示を解釈できませんでした。もう少し具体的に指定してください');
}

$ok = sheetsUpdateRange($service, $spreadsheet_id, $parsed['range'], $parsed['values']);
if (!$ok) {
    errorResponse('スプレッドシートの更新に失敗しました', 500);
}

successResponse([
    'updated_range' => $parsed['range'],
    'message' => 'スプレッドシートを更新しました',
]);
