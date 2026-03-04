<?php
/**
 * Social9内のExcel/WordファイルをAI指示で編集するヘルパー
 * 要: phpoffice/phpspreadsheet, phpoffice/phpword (composer suggest)
 */

/**
 * PhpSpreadsheet が利用可能か
 */
function isPhpSpreadsheetAvailable(): bool {
    return class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);
}

/**
 * PHPWord が利用可能か
 */
function isPhpWordAvailable(): bool {
    return class_exists(\PhpOffice\PhpWord\IOFactory::class);
}

/**
 * ユーザーがアップロードしたファイル1件を取得（編集権限チェック）
 */
function getEditableFile(PDO $pdo, int $user_id, int $file_id): ?array {
    $stmt = $pdo->prepare("
        SELECT id, original_name, file_path, mime_type 
        FROM files 
        WHERE id = ? AND uploader_id = ?
    ");
    $stmt->execute([$file_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * ファイルの絶対パスを取得（存在・読み取り可能チェック）
 */
function getEditableFilePath(array $fileRow): ?string {
    $rel = $fileRow['file_path'] ?? '';
    if ($rel === '') {
        return null;
    }
    $rel = str_replace('\\', '/', trim($rel));
    $rel = preg_replace('#^uploads/#', '', $rel); // DBに "uploads/..." で保存されている場合
    $base = defined('UPLOAD_DIR') ? rtrim(str_replace('\\', '/', UPLOAD_DIR), '/') : '';
    if ($base === '') {
        $base = rtrim(str_replace('\\', '/', __DIR__ . '/../uploads'), '/');
    }
    $path = $base . '/' . ltrim($rel, '/');
    $real = realpath($path);
    if ($real === false || !is_readable($real) || !is_file($real)) {
        return null;
    }
    return $real;
}

/**
 * Excel(.xlsx)を読み取り、先頭シートを2次元配列で返す
 */
function documentEditReadExcel(string $absolutePath): ?array {
    if (!isPhpSpreadsheetAvailable()) {
        return null;
    }
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        return $sheet->toArray();
    } catch (Throwable $e) {
        error_log('Document edit read Excel: ' . $e->getMessage());
        return null;
    }
}

/**
 * Excel(.xlsx)に範囲で値を書き込み保存
 * @param string $absolutePath 上書きするファイルの絶対パス
 * @param array $updates [['range' => 'A1:B2', 'values' => [['a','b'],['c','d']]], ...]
 */
function documentEditWriteExcel(string $absolutePath, array $updates): bool {
    if (!isPhpSpreadsheetAvailable()) {
        return false;
    }
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($updates as $u) {
            $range = $u['range'] ?? '';
            $values = $u['values'] ?? [];
            if ($range === '' || !is_array($values)) {
                continue;
            }
            // fromArray は開始セル（例: A1）を取る。A1:B2 の形式なら A1 を抽出
            $startCell = preg_match('/^([A-Z]+\d+)/i', $range, $m) ? $m[1] : $range;
            $sheet->fromArray($values, null, $startCell);
        }
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($absolutePath);
        return true;
    } catch (Throwable $e) {
        error_log('Document edit write Excel: ' . $e->getMessage());
        return false;
    }
}

/**
 * Word(.docx)をプレーンテキストで読み取り（要約用）
 */
function documentEditReadWordText(string $absolutePath): ?string {
    if (!isPhpWordAvailable()) {
        return null;
    }
    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($absolutePath);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= getElementText($element) . "\n";
            }
        }
        return trim($text);
    } catch (Throwable $e) {
        error_log('Document edit read Word: ' . $e->getMessage());
        return null;
    }
}

function getElementText($element): string {
    if (method_exists($element, 'getText')) {
        return $element->getText();
    }
    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
        $t = '';
        foreach ($element->getElements() as $child) {
            $t .= getElementText($child);
        }
        return $t;
    }
    return '';
}

/**
 * Word(.docx)でテキストを置換して保存
 * @param array $replacements [['search' => '旧', 'replace' => '新'], ...]
 */
function documentEditWriteWordReplace(string $absolutePath, array $replacements): bool {
    if (!isPhpWordAvailable()) {
        return false;
    }
    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($absolutePath);
        foreach ($replacements as $r) {
            $search = $r['search'] ?? '';
            $replace = $r['replace'] ?? '';
            if ($search !== '') {
                // PHPWord はドキュメント全体の置換を直接サポートしていないため、セクション内を走査して置換
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        replaceInElement($element, $search, $replace);
                    }
                }
            }
        }
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($absolutePath);
        return true;
    } catch (Throwable $e) {
        error_log('Document edit write Word: ' . $e->getMessage());
        return false;
    }
}

function replaceInElement($element, string $search, string $replace): void {
    if (method_exists($element, 'setText')) {
        $text = $element->getText();
        if (strpos($text, $search) !== false) {
            $element->setText(str_replace($search, $replace, $text));
        }
        return;
    }
    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
        foreach ($element->getElements() as $child) {
            replaceInElement($child, $search, $replace);
        }
    }
}
