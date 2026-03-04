<?php
/**
 * Social9内のExcel/WordファイルをAI指示で編集するAPI
 * POST: file_id, instruction
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/api-helpers.php';
require_once __DIR__ . '/../includes/document_edit_helper.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('POSTでリクエストしてください', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$file_id = (int)($input['file_id'] ?? 0);
$instruction = trim($input['instruction'] ?? '');

if ($file_id <= 0 || $instruction === '') {
    errorResponse('file_id と instruction は必須です');
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$fileRow = getEditableFile($pdo, $user_id, $file_id);
if (!$fileRow) {
    errorResponse('ファイルが見つからないか、編集権限がありません', 404);
}

$absolutePath = getEditableFilePath($fileRow);
if ($absolutePath === null) {
    errorResponse('ファイルにアクセスできません', 404);
}

$ext = strtolower(pathinfo($fileRow['original_name'], PATHINFO_EXTENSION));
$mime = $fileRow['mime_type'] ?? '';

// Excel
if (in_array($ext, ['xlsx', 'xls'], true) || strpos($mime, 'spreadsheet') !== false) {
    if (!isPhpSpreadsheetAvailable()) {
        errorResponse('Excel編集には phpoffice/phpspreadsheet のインストールが必要です。composer require phpoffice/phpspreadsheet --ignore-platform-reqs', 503);
    }
    $currentRows = documentEditReadExcel($absolutePath);
    if ($currentRows === null) {
        errorResponse('Excelファイルの読み取りに失敗しました');
    }
    $updates = geminiParseExcelEditInstruction($instruction, $currentRows);
    if (!$updates) {
        errorResponse('指示を解釈できませんでした。もう少し具体的に指定してください');
    }
    $ok = documentEditWriteExcel($absolutePath, $updates);
    if (!$ok) {
        errorResponse('Excelの更新に失敗しました', 500);
    }
    successResponse([
        'message' => 'Excelファイルを更新しました',
        'file_id' => $file_id,
    ]);
}

// Word
if (in_array($ext, ['docx'], true) || strpos($mime, 'wordprocessing') !== false || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
    if (!isPhpWordAvailable()) {
        errorResponse('Word編集には phpoffice/phpword のインストールが必要です。composer require phpoffice/phpword', 503);
    }
    $currentText = documentEditReadWordText($absolutePath);
    if ($currentText === null) {
        errorResponse('Wordファイルの読み取りに失敗しました');
    }
    $replacements = geminiParseWordEditInstruction($instruction, $currentText);
    if (!$replacements) {
        errorResponse('指示を解釈できませんでした。もう少し具体的に指定してください');
    }
    $ok = documentEditWriteWordReplace($absolutePath, $replacements);
    if (!$ok) {
        errorResponse('Wordの更新に失敗しました', 500);
    }
    successResponse([
        'message' => 'Wordファイルを更新しました',
        'file_id' => $file_id,
    ]);
}

errorResponse('このファイル形式は編集に対応していません。Excel(.xlsx)またはWord(.docx)を指定してください', 400);
