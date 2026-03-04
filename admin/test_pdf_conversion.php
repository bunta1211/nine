<?php
/**
 * PDF変換の動作確認用スクリプト
 * ブラウザで admin/test_pdf_conversion.php にアクセスして確認
 * ※ログイン不要（診断用）
 */
require_once __DIR__ . '/../config/app.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
// vendor に TCPDF が無い場合は includes/tcpdf フォールバックを読み込む
if (!class_exists('TCPDF')) {
    $tcpdfPath = __DIR__ . '/../includes/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;
    }
}
require_once __DIR__ . '/../includes/pdf_helper.php';

header('Content-Type: text/html; charset=utf-8');

echo '<h1>PDF変換 動作確認</h1>';

$checks = [];
$checks['vendor/autoload'] = file_exists(__DIR__ . '/../vendor/autoload.php');
$checks['TCPDF class'] = class_exists('TCPDF');
$checks['uploads/messages dir'] = is_dir(__DIR__ . '/../uploads/messages/');
$checks['uploads/messages writable'] = $checks['uploads/messages dir'] && is_writable(__DIR__ . '/../uploads/messages/');

echo '<h2>環境チェック</h2><ul>';
foreach ($checks as $name => $ok) {
    echo '<li>' . htmlspecialchars($name) . ': ' . ($ok ? '✅ OK' : '❌ NG') . '</li>';
}
echo '</ul>';

if (!$checks['TCPDF class']) {
    echo '<h3>TCPDF 読み込み失敗の原因</h3><ul>';
    $vendorTcpdf = __DIR__ . '/../vendor/tecnickcom/tcpdf';
    $tcpdfPhp = $vendorTcpdf . '/tcpdf.php';
    $includesTcpdf = __DIR__ . '/../includes/tcpdf/tcpdf.php';
    echo '<li>vendor/tecnickcom/tcpdf: ' . (is_dir($vendorTcpdf) ? '✅ 存在' : '❌ フォルダなし') . '</li>';
    echo '<li>vendor/tecnickcom/tcpdf/tcpdf.php: ' . (file_exists($tcpdfPhp) ? '✅ 存在' : '❌ ファイルなし') . '</li>';
    echo '<li>includes/tcpdf/tcpdf.php（手動配置）: ' . (file_exists($includesTcpdf) ? '✅ 存在' : '❌ なし') . '</li>';
    if (file_exists(__DIR__ . '/../vendor/composer/autoload_classmap.php')) {
        $classmap = include __DIR__ . '/../vendor/composer/autoload_classmap.php';
        echo '<li>autoload_classmap に TCPDF: ' . (isset($classmap['TCPDF']) ? '✅ 登録済み' : '❌ 未登録') . '</li>';
    }
    echo '</ul>';
    echo '<p><strong>対応方法:</strong></p>';
    echo '<ol>';
    echo '<li>サーバーで <code>composer install</code> を実行</li>';
    echo '<li>または、ローカルで <code>xcopy /E /I vendor\\tecnickcom\\tcpdf includes\\tcpdf</code> を実行し、<code>includes/tcpdf</code> フォルダをサーバーにアップロード</li>';
    echo '</ol>';
    echo '<p>詳しくは <a href="../DOCS/TCPDF_SETUP.md">DOCS/TCPDF_SETUP.md</a> を参照</p>';
    exit;
}

$testText = str_repeat('これは1000文字以上のテスト文字列です。', 40);
echo '<p>テスト文字数: ' . mb_strlen($testText) . '</p>';

$uploadDir = __DIR__ . '/../uploads/messages/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

echo '<h2>変換テスト</h2>';
$result = textToPdf($testText, $uploadDir, 'テスト長文');
if ($result && !empty($result['path'])) {
    echo '<p>✅ 変換成功: ' . htmlspecialchars($result['path']) . '</p>';
    echo '<p><a href="../' . htmlspecialchars($result['path']) . '" target="_blank">PDFを開く</a></p>';
} else {
    echo '<p>❌ 変換失敗</p>';
    echo '<p>PHPエラーログを確認してください。</p>';
}
