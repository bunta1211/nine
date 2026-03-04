<?php
/**
 * サーバー判別・デプロイ先確認用
 *
 * ・このURLを開いている「サーバー」で、どのディレクトリのファイルが読まれているか確認できます。
 * ・FTP等のアップロード先が「base_dir」と一致しているか確認してください。
 *
 * 本番確認後は削除またはアクセス制限を推奨
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$apiDir = __DIR__;
$base = dirname($apiDir);
$designConfig = $base . '/includes/design_config.php';
$designLoader = $base . '/includes/design_loader.php';
$topbar = $base . '/includes/chat/topbar.php';
$chatMainCss = $base . '/assets/css/chat-main.css';

$topbarContent = file_exists($topbar) ? file_get_contents($topbar) : '';
$hasTestBadge = (strpos($topbarContent, 'topbar-deploy-test') !== false || strpos($topbarContent, 'テスト') !== false);

// どのパスをPHPが実際に参照しているか（アップロード先の照合用）
$topbarRealpath = file_exists($topbar) ? realpath($topbar) : null;
$chatMainCssRealpath = file_exists($chatMainCss) ? realpath($chatMainCss) : null;

// topbarの「テスト」付近の1行だけ抜粋（中身が反映されているか確認用）
$topbarPreview = null;
if ($topbarContent !== '') {
    foreach (preg_split('/\r?\n/', $topbarContent) as $line) {
        if (strpos($line, 'top-center') !== false || strpos($line, 'テスト') !== false) {
            $topbarPreview = trim($line);
            break;
        }
    }
}

$response = [
    'deploy_verify' => 'サーバー反映確認-' . date('YmdHis'),
    'message' => 'このURLのサーバーで使われているプロジェクトルートを「base_dir」で確認し、FTP等のアップロード先がそのパス（またはその配下）と一致しているか確認してください。',
    'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
    'http_host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'base_dir' => $base,
    'base_dir_realpath' => realpath($base) ?: null,
    'topbar_path' => $topbar,
    'topbar_realpath' => $topbarRealpath,
    'topbar_has_test_badge' => $hasTestBadge,
    'topbar_preview_line' => $topbarPreview,
    'topbar_mtime' => file_exists($topbar) ? date('Y-m-d H:i:s', filemtime($topbar)) : null,
    'chat_main_css_realpath' => $chatMainCssRealpath,
    'chat_main_css_mtime' => file_exists($chatMainCss) ? date('Y-m-d H:i:s', filemtime($chatMainCss)) : null,
    'design_config_mtime' => file_exists($designConfig) ? date('Y-m-d H:i:s', filemtime($designConfig)) : null,
    'design_loader_mtime' => file_exists($designLoader) ? date('Y-m-d H:i:s', filemtime($designLoader)) : null,
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
