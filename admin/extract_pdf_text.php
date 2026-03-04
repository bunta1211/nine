<?php
/**
 * PDFテキスト抽出マイグレーション（自動カラム追加対応）
 * 
 * パラメータ:
 *   ?force=1  - 既に抽出済みのものも再抽出
 *   ?clear=1  - 文字化けデータをクリア
 * 
 * 使用方法: ブラウザからアクセス（管理者のみ）
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/pdf_helper.php';

// 管理者チェック（developer, admin, system_admin, super_admin, org_admin）
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin', 'org_admin'])) {
    die('管理者権限が必要です');
}

header('Content-Type: text/html; charset=utf-8');

$forceMode = isset($_GET['force']);
$clearMode = isset($_GET['clear']);

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PDF テキスト抽出</title></head><body>';
echo '<style>
body{font-family:sans-serif;max-width:950px;margin:20px auto;padding:20px;background:#f9fafb;}
h1{color:#1e40af;} h2{color:#374151;margin-top:24px;}
.box{background:white;border-radius:8px;padding:16px 20px;margin:12px 0;box-shadow:0 1px 3px rgba(0,0,0,.1);}
.success{color:#059669;} .error{color:#dc2626;} .warn{color:#d97706;} .info{color:#2563eb;}
table{border-collapse:collapse;width:100%;margin:12px 0;} 
th,td{border:1px solid #e5e7eb;padding:8px 12px;text-align:left;font-size:13px;}
th{background:#f3f4f6;} tr:nth-child(even){background:#fafafa;}
code{background:#e5e7eb;padding:2px 6px;border-radius:4px;font-size:12px;}
.btn{display:inline-block;padding:10px 20px;background:#2563eb;color:white;border-radius:8px;text-decoration:none;font-weight:500;margin:8px 4px;}
.btn:hover{background:#1d4ed8;} .btn-warn{background:#d97706;} .btn-warn:hover{background:#b45309;}
.btn-danger{background:#dc2626;} .btn-danger:hover{background:#b91c1c;}
.nav{margin:16px 0;padding:12px;background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);}
</style>';

$pdo = getDB();

echo '<h1>📄 PDFテキスト抽出</h1>';

// ナビゲーション
echo '<div class="nav">';
echo '<a class="btn" href="extract_pdf_text.php">通常実行</a>';
echo '<a class="btn btn-warn" href="extract_pdf_text.php?force=1">強制再抽出（全件）</a>';
echo '<a class="btn btn-danger" href="extract_pdf_text.php?clear=1">文字化けデータをクリア</a>';
echo '</div>';

// ========== ステップ1: カラムが存在するか確認・自動追加 ==========
$columnExists = false;
try {
    $pdo->query("SELECT extracted_text FROM messages LIMIT 0");
    $columnExists = true;
    echo '<div class="box"><span class="success">✅</span> <code>extracted_text</code> カラムは既に存在します。</div>';
} catch (PDOException $e) {
    echo '<div class="box"><span class="warn">⚠️</span> <code>extracted_text</code> カラムが見つかりません。自動追加します...</div>';
    try {
        $pdo->exec("ALTER TABLE messages ADD COLUMN extracted_text MEDIUMTEXT DEFAULT NULL AFTER content");
        echo '<div class="box"><span class="success">✅</span> <code>extracted_text</code> カラムを追加しました。</div>';
        $columnExists = true;
        try {
            $pdo->exec("ALTER TABLE messages ADD FULLTEXT INDEX ft_extracted_text (extracted_text)");
            echo '<div class="box"><span class="success">✅</span> FULLTEXTインデックスを追加しました。</div>';
        } catch (PDOException $e2) {
            echo '<div class="box"><span class="warn">⚠️</span> FULLTEXTインデックスの追加をスキップ: ' . htmlspecialchars($e2->getMessage()) . '</div>';
        }
    } catch (PDOException $e2) {
        echo '<div class="box"><span class="error">❌</span> カラム追加に失敗: ' . htmlspecialchars($e2->getMessage()) . '</div>';
        echo '</body></html>';
        exit;
    }
}

if (!$columnExists) { echo '</body></html>'; exit; }

// ========== クリアモード: 文字化けデータを消去 ==========
if ($clearMode) {
    $cleared = $pdo->exec("UPDATE messages SET extracted_text = NULL WHERE extracted_text IS NOT NULL AND deleted_at IS NULL");
    echo '<div class="box"><span class="success">✅</span> <strong>' . (int)$cleared . '件</strong>のextracted_textをクリアしました。<br>';
    echo '再抽出するには <a class="btn" href="extract_pdf_text.php">通常実行</a> または <a class="btn btn-warn" href="extract_pdf_text.php?force=1">強制再抽出</a> をクリックしてください。</div>';
    echo '</body></html>';
    exit;
}

// ========== ステップ2: smalot/pdfparser の状態確認 ==========
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
$hasPdfParser = class_exists('Smalot\PdfParser\Parser');
if ($hasPdfParser) {
    echo '<div class="box"><span class="success">✅</span> <code>smalot/pdfparser</code> が利用可能です（高精度抽出モード）。</div>';
} else {
    echo '<div class="box"><span class="error">❌ 重要:</span> <code>smalot/pdfparser</code> が未インストールです。<br>';
    echo '日本語PDFのテキスト抽出には <strong>smalot/pdfparser</strong> が必須です。<br><br>';
    echo '<strong>インストール方法（どちらか一つ）:</strong><br>';
    echo '1. サーバーでSSHアクセスできる場合: <code>cd ' . htmlspecialchars(dirname(__DIR__)) . ' && composer require smalot/pdfparser</code><br>';
    echo '2. ローカルPCの <code>vendor/</code> フォルダをサーバーにアップロード<br><br>';
    echo '<span class="warn">⚠️ ライブラリなしでは日本語テキストが文字化けします。先にインストールしてから再実行してください。</span>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

// ========== ステップ3: 対象メッセージを検索 ==========
if ($forceMode) {
    // 強制モード: PDFパスを含む全メッセージ
    $stmt = $pdo->prepare("
        SELECT id, content 
        FROM messages 
        WHERE content LIKE '%uploads/messages/%.pdf%'
        AND deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 500
    ");
} else {
    // 通常モード: 未抽出のみ
    $stmt = $pdo->prepare("
        SELECT id, content 
        FROM messages 
        WHERE content LIKE '%uploads/messages/%.pdf%'
        AND (extracted_text IS NULL OR extracted_text = '')
        AND deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 200
    ");
}
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$doneStmt = $pdo->query("SELECT COUNT(*) FROM messages WHERE extracted_text IS NOT NULL AND extracted_text != '' AND deleted_at IS NULL");
$doneCount = (int)$doneStmt->fetchColumn();

echo '<div class="box">';
echo '<strong>モード:</strong> ' . ($forceMode ? '🔄 強制再抽出（全件上書き）' : '📥 通常（未抽出のみ）') . '<br>';
echo '<strong>対象メッセージ:</strong> ' . count($messages) . '件<br>';
echo '<strong>抽出済み:</strong> ' . $doneCount . '件';
echo '</div>';

if (empty($messages)) {
    echo '<div class="box"><span class="info">ℹ️</span> 抽出対象のPDFメッセージはありません。</div>';
    echo '</body></html>';
    exit;
}

// ========== ステップ4: テキスト抽出実行 ==========
echo '<h2>抽出結果</h2>';

$successCount = 0;
$errorCount = 0;
$skipCount = 0;

echo '<table>';
echo '<tr><th>ID</th><th>ファイル</th><th>結果</th><th>抽出テキスト（先頭120文字）</th></tr>';

foreach ($messages as $msg) {
    $content = $msg['content'];
    
    if (!preg_match('/(uploads\/messages\/[^\s\n]+\.pdf)/i', $content, $m)) {
        $skipCount++;
        echo '<tr><td>' . (int)$msg['id'] . '</td><td>-</td><td class="warn">パス抽出不可</td><td>-</td></tr>';
        continue;
    }
    
    $pdfPath = $m[1];
    $absolutePath = __DIR__ . '/../' . $pdfPath;
    
    if (!file_exists($absolutePath)) {
        $skipCount++;
        echo '<tr><td>' . (int)$msg['id'] . '</td><td>' . htmlspecialchars(basename($pdfPath)) . '</td><td class="error">ファイル不在</td><td>-</td></tr>';
        continue;
    }
    
    $extractedText = extractPdfText($content);
    
    if (!empty($extractedText)) {
        // 日本語が含まれているか簡易チェック（文字化け検出）
        $hasJapanese = preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $extractedText);
        $hasAscii = preg_match('/[a-zA-Z0-9]/', $extractedText);
        $quality = ($hasJapanese || $hasAscii) ? 'good' : 'suspect';
        
        $update = $pdo->prepare("UPDATE messages SET extracted_text = ? WHERE id = ?");
        $update->execute([$extractedText, (int)$msg['id']]);
        $successCount++;
        $preview = htmlspecialchars(mb_substr(preg_replace('/[\r\n]+/', ' ', $extractedText), 0, 120));
        $qualityIcon = $quality === 'good' ? '✅' : '⚠️';
        echo '<tr><td>' . (int)$msg['id'] . '</td><td>' . htmlspecialchars(basename($pdfPath)) . '</td><td class="success">' . $qualityIcon . ' 成功 (' . number_format(mb_strlen($extractedText)) . '文字)</td><td>' . $preview . '</td></tr>';
    } else {
        $errorCount++;
        echo '<tr><td>' . (int)$msg['id'] . '</td><td>' . htmlspecialchars(basename($pdfPath)) . '</td><td class="error">❌ 抽出失敗</td><td>-</td></tr>';
    }
    
    unset($extractedText);
}

echo '</table>';

echo '<h2>結果サマリー</h2>';
echo '<div class="box">';
echo '<span class="success">✅ 成功: ' . $successCount . '件</span><br>';
if ($errorCount > 0) echo '<span class="error">❌ 失敗: ' . $errorCount . '件</span><br>';
if ($skipCount > 0) echo '<span class="warn">⏭️ スキップ: ' . $skipCount . '件</span><br>';
echo '</div>';

if ($successCount > 0) {
    echo '<div class="box"><span class="success">🎉</span> テキスト抽出が完了しました。検索バーやAI秘書でPDF内のテキストが検索できるようになりました。</div>';
}

echo '</body></html>';
