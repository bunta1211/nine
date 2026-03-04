<?php
/**
 * AI秘書用ファイル読み取りライブラリ
 *
 * アップロードされたファイルからテキスト内容を抽出し、
 * AIのコンテキストとして渡せる形にする。
 */

const AI_FILE_MAX_TEXT_LENGTH = 30000;

const AI_FILE_BLOCKED_EXTENSIONS = [
    'exe', 'msi', 'bat', 'cmd', 'ps1', 'vbs', 'wsf', 'scr',
    'com', 'pif', 'cpl', 'hta', 'inf', 'reg', 'dll', 'sys',
    'sh', 'bash', 'cgi', 'php', 'phtml', 'phar',
];

const AI_FILE_TEXT_EXTENSIONS = [
    'txt', 'md', 'csv', 'tsv', 'json', 'xml', 'html', 'htm',
    'css', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'h', 'hpp',
    'rb', 'go', 'rs', 'swift', 'kt', 'sql', 'yaml', 'yml',
    'toml', 'ini', 'cfg', 'conf', 'log', 'env', 'gitignore',
    'dockerfile', 'makefile', 'readme', 'license', 'changelog',
];

const AI_FILE_IMAGE_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
];

/**
 * ファイルがAI秘書で受付可能か判定
 */
function isAiFileAllowed(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return !in_array($ext, AI_FILE_BLOCKED_EXTENSIONS);
}

/**
 * ファイルが画像かどうか
 */
function isImageFile(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, AI_FILE_IMAGE_EXTENSIONS);
}

/**
 * ファイルからテキスト内容を抽出
 *
 * @param string $filePath サーバー上の絶対パスまたは相対パス
 * @return array ['success' => bool, 'text' => string, 'type' => string, 'error' => string|null]
 */
function extractFileText(string $filePath): array {
    $filePath = trim($filePath);
    if ($filePath === '') {
        return ['success' => false, 'text' => '', 'type' => 'unknown', 'error' => 'ファイルパスが空です'];
    }

    $bases = [
        rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'),
    ];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        if (!in_array($docRoot, $bases)) {
            $bases[] = $docRoot;
        }
    }
    if (defined('UPLOAD_DIR') && is_string(UPLOAD_DIR)) {
        $uploadParent = rtrim(str_replace('\\', '/', dirname(UPLOAD_DIR)), '/');
        if (!in_array($uploadParent, $bases)) {
            $bases[] = $uploadParent;
        }
    }
    $cwd = getcwd();
    if ($cwd !== false && $cwd !== '') {
        $cwdBase = rtrim(str_replace('\\', '/', $cwd), '/');
        if (!in_array($cwdBase, $bases)) {
            $bases[] = $cwdBase;
        }
    }

    $absPath = null;
    if ($filePath[0] === '/' || preg_match('#^[A-Za-z]:#', $filePath)) {
        if (file_exists($filePath) && is_readable($filePath)) {
            $absPath = $filePath;
        }
        if (!$absPath && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
            $relative = ltrim(preg_replace('#^[A-Za-z]:#', '', $filePath), '/');
            $candidate = $docRoot . '/' . $relative;
            if (file_exists($candidate) && is_readable($candidate)) {
                $absPath = $candidate;
            }
        }
    }
    if (!$absPath) {
        $relative = ltrim($filePath, '/');
        foreach ($bases as $base) {
            $candidate = $base . '/' . $relative;
            if (file_exists($candidate) && is_readable($candidate)) {
                $absPath = $candidate;
                break;
            }
        }
    }
    // uploads/ で始まる相対パスは UPLOAD_DIR 直下としても解決（本番の DOCUMENT_ROOT 差に対応）
    if (!$absPath && defined('UPLOAD_DIR') && preg_match('#^uploads/#', $filePath)) {
        $uploadBase = rtrim(str_replace('\\', '/', UPLOAD_DIR), '/');
        $candidate = $uploadBase . '/' . preg_replace('#^uploads/#', '', $filePath);
        if (file_exists($candidate) && is_readable($candidate)) {
            $absPath = $candidate;
        }
    }
    if (!$absPath) {
        error_log('[AI file reader] File not found: ' . $filePath . ' tried bases: ' . implode(', ', $bases));
        return ['success' => false, 'text' => '', 'type' => 'unknown', 'error' => 'ファイルが見つかりません'];
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $fileSize = filesize($absPath);

    if ($fileSize > 20 * 1024 * 1024) {
        return ['success' => false, 'text' => '', 'type' => $ext, 'error' => 'ファイルが大きすぎます（20MB以下）'];
    }

    if (in_array($ext, AI_FILE_TEXT_EXTENSIONS) || isPlainText($absPath)) {
        return readTextFile($absPath, $ext);
    }

    if ($ext === 'pdf') {
        return readPdfFile($absPath);
    }

    if (in_array($ext, ['docx'])) {
        return readDocxFile($absPath);
    }

    if (in_array($ext, ['xlsx', 'xls'])) {
        return readXlsxFile($absPath);
    }

    if (in_array($ext, ['pptx'])) {
        return readPptxFile($absPath);
    }

    if (in_array($ext, AI_FILE_IMAGE_EXTENSIONS)) {
        return ['success' => true, 'text' => '', 'type' => 'image', 'error' => null];
    }

    return ['success' => false, 'text' => '', 'type' => $ext, 'error' => 'このファイル形式のテキスト抽出には対応していません'];
}

function isPlainText(string $path): bool {
    $sample = file_get_contents($path, false, null, 0, 8192);
    if ($sample === false) return false;
    $nonPrintable = preg_match_all('/[\x00-\x08\x0E-\x1F]/', $sample);
    return $nonPrintable < strlen($sample) * 0.05;
}

function readTextFile(string $path, string $ext): array {
    $content = file_get_contents($path);
    if ($content === false) {
        return ['success' => false, 'text' => '', 'type' => $ext, 'error' => 'ファイルを読み込めません'];
    }

    $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'EUC-JP', 'ISO-8859-1', 'ASCII'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    if (mb_strlen($content) > AI_FILE_MAX_TEXT_LENGTH) {
        $content = mb_substr($content, 0, AI_FILE_MAX_TEXT_LENGTH) . "\n\n...（以降省略。ファイルが長いため最初の" . AI_FILE_MAX_TEXT_LENGTH . "文字のみ表示）";
    }

    $typeLabel = $ext === 'csv' ? 'CSV' : ($ext === 'json' ? 'JSON' : ($ext === 'md' ? 'Markdown' : 'テキスト'));
    return ['success' => true, 'text' => $content, 'type' => $typeLabel, 'error' => null];
}

/**
 * PDFから抽出した文字列から、PDF演算子・数値のみの行を除去し読めるテキストだけを返す
 */
function filterPdfExtractedText(string $text): string {
    $lines = preg_split('/\r\n|\r|\n/u', $text);
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (mb_strlen($line) < 2) continue;
        if (preg_match('/^[\d\s\.\-+\*\/\(\)\[\]]+$/u', $line)) continue;
        if (preg_match('/^[FGT]\d*[+-]\d+/u', $line) && !preg_match('/[\p{L}\p{N}]{3,}/u', $line)) continue;
        if (preg_match('/^[\x00-\x1F\x7F]+$/u', $line)) continue;
        $letterCount = preg_match_all('/[\p{L}\p{N}]/u', $line);
        if ($letterCount < 2) continue;
        $replacementCount = preg_match_all('/\x{FFFD}/u', $line);
        if ($replacementCount > 0 && $replacementCount * 2 >= mb_strlen($line)) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}

function readPdfFile(string $path): array {
    $content = file_get_contents($path);
    if ($content === false) {
        return ['success' => false, 'text' => '', 'type' => 'PDF', 'error' => 'PDFを読み込めません'];
    }

    $text = '';

    if (preg_match_all('/stream\s+(.*?)\s+endstream/s', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded !== false) {
                if (preg_match_all('/\((.*?)\)/s', $decoded, $textMatches)) {
                    $text .= implode('', $textMatches[1]) . "\n";
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tjMatches)) {
                    foreach ($tjMatches[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/', $tj, $tjText)) {
                            $text .= implode('', $tjText[1]);
                        }
                    }
                    $text .= "\n";
                }
            }
        }
    }

    if (trim($text) === '' && strlen($content) > 0) {
        $rawText = '';
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $content, $parenMatches)) {
            foreach ($parenMatches[1] as $s) {
                $s = str_replace(['\\r', '\\n', '\\t', '\\(', '\\)', '\\\\'], ["\r", "\n", "\t", '(', ')', '\\'], $s);
                if (trim($s) !== '' && preg_match('/[\x20-\x7E\x80-\xFF]/', $s)) {
                    $rawText .= $s . "\n";
                }
            }
        }
        if (trim($rawText) === '' && preg_match_all('/<([0-9A-Fa-f]+)>/s', $content, $hexMatches)) {
            foreach ($hexMatches[1] as $hex) {
                if (strlen($hex) >= 2 && strlen($hex) % 2 === 0) {
                    $bin = hex2bin($hex);
                    if ($bin !== false && strlen($bin) >= 2) {
                        $dec = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
                        if ($dec === false || !preg_match('/[\x20-\x7E\x80-\xFF]{2,}/u', $dec)) {
                            $dec = @mb_convert_encoding($bin, 'UTF-8', 'UTF-8');
                        }
                        if ($dec !== false && mb_strlen($dec) > 1 && preg_match('/[\p{L}\p{N}\s\.\,\-\:\;]{2,}/u', $dec)) {
                            $rawText .= $dec . "\n";
                        }
                    }
                }
            }
        }
        if (trim($rawText) !== '') {
            $text = $rawText;
        }
    }

    $text = filterPdfExtractedText($text);
    $text = trim($text);
    if (!empty($text)) {
        $text = filterPdfExtractedText($text);
    }
    if (empty($text)) {
        // 自前抽出が空の場合、pdf_helper（smalot/pdfparser）で再試行
        $helperPath = __DIR__ . '/pdf_helper.php';
        if (file_exists($helperPath) && !function_exists('extractPdfTextFromPath')) {
            require_once $helperPath;
        }
        if (function_exists('extractPdfTextFromPath')) {
            $fallbackText = extractPdfTextFromPath($path);
            if (!empty(trim($fallbackText ?? ''))) {
                $text = filterPdfExtractedText(trim($fallbackText));
                $text = trim($text);
            }
        }
    }
    if (empty($text)) {
        return ['success' => true, 'text' => '（PDFからテキストを抽出できませんでした。スキャン画像のPDFの可能性があります）', 'type' => 'PDF', 'error' => null];
    }

    if (mb_strlen($text) > AI_FILE_MAX_TEXT_LENGTH) {
        $text = mb_substr($text, 0, AI_FILE_MAX_TEXT_LENGTH) . "\n\n...（以降省略）";
    }

    return ['success' => true, 'text' => $text, 'type' => 'PDF', 'error' => null];
}

function readDocxFile(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['success' => false, 'text' => '', 'type' => 'DOCX', 'error' => 'DOCXファイルを開けません'];
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xmlContent === false) {
        return ['success' => false, 'text' => '', 'type' => 'DOCX', 'error' => 'DOCXの内容を読み取れません'];
    }

    $text = strip_tags(str_replace(['<w:p ', '<w:p>', '</w:p>'], ["\n<w:p ", "\n", "\n"], $xmlContent));
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/\n\s+/', "\n", $text);
    $text = trim($text);

    if (mb_strlen($text) > AI_FILE_MAX_TEXT_LENGTH) {
        $text = mb_substr($text, 0, AI_FILE_MAX_TEXT_LENGTH) . "\n\n...（以降省略）";
    }

    return ['success' => true, 'text' => $text, 'type' => 'DOCX', 'error' => null];
}

function readXlsxFile(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['success' => false, 'text' => '', 'type' => 'XLSX', 'error' => 'XLSXファイルを開けません'];
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $xml = @simplexml_load_string($ssXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string)$si->t ?: implode('', array_map(function($r) { return (string)$r->t; }, iterator_to_array($si->r ?? [])));
            }
        }
    }

    $text = '';
    $sheetIndex = 1;
    while (($sheetXml = $zip->getFromName("xl/worksheets/sheet{$sheetIndex}.xml")) !== false) {
        $xml = @simplexml_load_string($sheetXml);
        if (!$xml) { $sheetIndex++; continue; }

        if ($sheetIndex > 1) $text .= "\n--- Sheet {$sheetIndex} ---\n";

        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $val = (string)$cell->v;
                $type = (string)($cell['t'] ?? '');
                if ($type === 's' && isset($sharedStrings[(int)$val])) {
                    $val = $sharedStrings[(int)$val];
                }
                $cells[] = $val;
            }
            $text .= implode("\t", $cells) . "\n";
        }

        $sheetIndex++;
        if ($sheetIndex > 10) break;
    }
    $zip->close();

    $text = trim($text);
    if (empty($text)) {
        return ['success' => true, 'text' => '（XLSXからデータを抽出できませんでした）', 'type' => 'XLSX', 'error' => null];
    }
    if (mb_strlen($text) > AI_FILE_MAX_TEXT_LENGTH) {
        $text = mb_substr($text, 0, AI_FILE_MAX_TEXT_LENGTH) . "\n\n...（以降省略）";
    }

    return ['success' => true, 'text' => $text, 'type' => 'XLSX', 'error' => null];
}

function readPptxFile(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['success' => false, 'text' => '', 'type' => 'PPTX', 'error' => 'PPTXファイルを開けません'];
    }

    $text = '';
    $slideIndex = 1;
    while (($slideXml = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== false) {
        $content = strip_tags(str_replace(['<a:p>', '</a:p>'], ["\n", "\n"], $slideXml));
        $content = preg_replace('/\s+/', ' ', $content);
        $text .= "--- スライド {$slideIndex} ---\n" . trim($content) . "\n\n";
        $slideIndex++;
        if ($slideIndex > 50) break;
    }
    $zip->close();

    $text = trim($text);
    if (empty($text)) {
        return ['success' => true, 'text' => '（PPTXからテキストを抽出できませんでした）', 'type' => 'PPTX', 'error' => null];
    }
    if (mb_strlen($text) > AI_FILE_MAX_TEXT_LENGTH) {
        $text = mb_substr($text, 0, AI_FILE_MAX_TEXT_LENGTH) . "\n\n...（以降省略）";
    }

    return ['success' => true, 'text' => $text, 'type' => 'PPTX', 'error' => null];
}
