<?php
/**
 * PDF変換・テキスト抽出ヘルパー
 * - テキスト→PDF変換（1000文字以上の長文）
 * - PDF→テキスト抽出（検索用）
 */

if (!function_exists('textToPdf')) {
    /**
     * テキストをPDFファイルに変換して保存
     * @param string $text 変換するテキスト（UTF-8）
     * @param string $outputDir 出力ディレクトリ（絶対パス）
     * @param string $defaultFilename デフォルトファイル名（拡張子なし）
     * @return array|null ['path' => 'uploads/messages/xxx.pdf', 'filename' => 'xxx.pdf'] または失敗時null
     */
    function textToPdf(string $text, string $outputDir, string $defaultFilename = '長文'): ?array {
        // 極端に長いテキストはメモリ不足でfatalになるため変換しない（約15万文字まで）
        $maxChars = 150000;
        if (mb_strlen($text) > $maxChars) {
            error_log('[PDF] textToPdf skipped: text length ' . mb_strlen($text) . ' exceeds max ' . $maxChars);
            return null;
        }
        if (!class_exists('TCPDF')) {
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
            if (!class_exists('TCPDF') && file_exists(__DIR__ . '/tcpdf/tcpdf.php')) {
                require_once __DIR__ . '/tcpdf/tcpdf.php';
            }
            if (!class_exists('TCPDF')) {
                error_log('[PDF] TCPDF not found. composer install を実行するか、vendor/tecnickcom/tcpdf を includes/tcpdf にコピーしてください');
                return null;
            }
        }
        
        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Social9');
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->SetMargins(15, 15, 15);
            $pdf->setFontSubsetting(true);
            $pdf->AddPage();
            
            // 日本語対応フォント（kozminproregular は日本語明朝、dejavusans は汎用 Unicode）
            try {
                $pdf->SetFont('kozminproregular', '', 10);
            } catch (Exception $e) {
                try {
                    $pdf->SetFont('dejavusans', '', 10);
                } catch (Exception $e2) {
                    $pdf->SetFont('helvetica', '', 10);
                }
            }
            
            $pdf->Write(0, $text, '', 0, 'L', true);
            $pdf->LastPage();
            
            $safeName = preg_replace('/[^\p{L}\p{N}\-_]/u', '_', $defaultFilename);
            $safeName = mb_substr($safeName, 0, 200);
            if ($safeName === '') $safeName = '長文';
            $filename = uniqid('msg_') . '_' . time() . '.pdf';
            $filepath = $outputDir . $filename;
            
            $pdf->Output($filepath, 'F');
            
            return [
                'path' => 'uploads/messages/' . $filename,
                'filename' => $filename
            ];
        } catch (Exception $e) {
            error_log('[PDF] textToPdf error: ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * PDFの絶対パスからテキストを抽出（smalot → フォールバック）。AI秘書のファイル読み取りから利用
 * @param string $absolutePath PDFの絶対パス
 * @return string|null 抽出テキスト（最大50000文字）、失敗時null
 */
function extractPdfTextFromPath(string $absolutePath): ?string {
    if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
        return null;
    }
    if (!class_exists('Smalot\PdfParser\Parser')) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
    }
    if (class_exists('Smalot\PdfParser\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();
            if (!empty(trim($text ?? ''))) {
                return mb_substr(trim($text), 0, 50000);
            }
        } catch (Exception $e) {
            error_log('[PDF Extract] smalot parseFile error: ' . $e->getMessage());
        }
    }
    $text = extractPdfTextFallback($absolutePath);
    return $text ? mb_substr(trim($text), 0, 50000) : null;
}

/**
 * メッセージ内容からPDFファイルパスを抽出し、PDFのテキストを取得
 * @param string $content メッセージ内容（パスを含む）
 * @return string|null 抽出されたテキスト（最大50000文字）
 */
function extractPdfText(string $content): ?string {
    // PDFファイルパスを抽出
    if (!preg_match('/(uploads\/messages\/[^\s\n]+\.pdf)/i', $content, $m)) {
        return null;
    }
    $relativePath = $m[1];
    $absolutePath = __DIR__ . '/../' . $relativePath;
    
    if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
        error_log('[PDF Extract] File not found: ' . $absolutePath);
        return null;
    }
    
    // smalot/pdfparser が利用可能か確認
    if (!class_exists('Smalot\PdfParser\Parser')) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
    }
    
    if (class_exists('Smalot\PdfParser\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();
            if (!empty($text)) {
                // 最大50000文字に制限
                return mb_substr(trim($text), 0, 50000);
            }
        } catch (Exception $e) {
            error_log('[PDF Extract] smalot/pdfparser error: ' . $e->getMessage());
        }
    }
    
    // フォールバック: 簡易的なPDFテキスト抽出（ストリーム解析）
    $text = extractPdfTextFallback($absolutePath);
    if (!empty($text)) {
        return mb_substr(trim($text), 0, 50000);
    }
    
    return null;
}

/**
 * 簡易PDFテキスト抽出（ライブラリ不要のフォールバック）
 * TCPDF等で生成されたテキストベースPDFに対応
 */
function extractPdfTextFallback(string $filepath): ?string {
    $content = file_get_contents($filepath);
    if ($content === false) return null;
    
    $text = '';
    
    // ストリームからテキストを抽出
    // BT ... ET ブロック（テキストオブジェクト）を解析
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
        foreach ($matches[1] as $block) {
            // Tj オペレータ（テキスト表示）
            if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tjMatches)) {
                foreach ($tjMatches[1] as $t) {
                    $decoded = decodeOctalPdfString($t);
                    $text .= $decoded;
                }
            }
            // TJ オペレータ（テキスト配列）
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrayMatches)) {
                foreach ($tjArrayMatches[1] as $arr) {
                    if (preg_match_all('/\(([^)]*)\)/s', $arr, $arrText)) {
                        foreach ($arrText[1] as $t) {
                            $decoded = decodeOctalPdfString($t);
                            $text .= $decoded;
                        }
                    }
                }
            }
        }
    }
    
    // 圧縮ストリームからテキスト抽出を試みる
    if (empty($text) && preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded !== false) {
                // デコードされたストリームからテキストを抽出
                if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $decoded, $tjMatches)) {
                    foreach ($tjMatches[1] as $t) {
                        $text .= decodeOctalPdfString($t);
                    }
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tjArrayMatches)) {
                    foreach ($tjArrayMatches[1] as $arr) {
                        if (preg_match_all('/\(([^)]*)\)/s', $arr, $arrText)) {
                            foreach ($arrText[1] as $t) {
                                $text .= decodeOctalPdfString($t);
                            }
                        }
                    }
                }
            }
        }
    }
    
    // テキストが十分に取れた場合のみ返す
    $cleaned = preg_replace('/\s+/', ' ', trim($text));
    return strlen($cleaned) > 5 ? $cleaned : null;
}

/**
 * PDFのオクタルエスケープをデコード
 */
function decodeOctalPdfString(string $str): string {
    // オクタルエスケープ (\nnn) をデコード
    $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
        return chr(octdec($m[1]));
    }, $str);
    // 標準エスケープ
    $str = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], ["\n", "\r", "\t", "\\", "(", ")"], $str);
    // UTF-16BEをUTF-8に変換
    if (substr($str, 0, 2) === "\xFE\xFF") {
        $str = mb_convert_encoding(substr($str, 2), 'UTF-8', 'UTF-16BE');
    }
    return $str;
}
