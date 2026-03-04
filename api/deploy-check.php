<?php
/**
 * デプロイ確認用（DB・api-bootstrap に依存しない）
 *
 * health.php?action=deploy で 500 が出る場合に利用。
 * このファイルは api-bootstrap を読まないため、DB 接続エラー時でも
 * base_dir と topbar の反映有無を確認できる。
 *
 * 本番確認後は .htaccess でアクセス制限を推奨。
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$isAdmin = false;
$bootstrapError = null;

try {
    if (!file_exists(__DIR__ . '/../config/app.php')) {
        throw new Exception('config/app.php not found');
    }
    require_once __DIR__ . '/../config/app.php';
    if (file_exists(__DIR__ . '/../config/session.php')) {
        require_once __DIR__ . '/../config/session.php';
        if (function_exists('start_session_once')) {
            start_session_once();
        }
        $role = $_SESSION['role'] ?? 'user';
        $isAdmin = in_array($role, ['developer', 'admin', 'system_admin', 'super_admin']);
    }
} catch (Throwable $e) {
    $bootstrapError = $e->getMessage();
}

$apiDir = __DIR__;
$base = dirname($apiDir);
$topbar = $base . '/includes/chat/topbar.php';
$topbarContent = file_exists($topbar) ? file_get_contents($topbar) : '';
$hasTestBadge = (strpos($topbarContent, 'topbar-deploy-test') !== false || strpos($topbarContent, 'テスト') !== false);
$topbarPreview = null;
if ($topbarContent !== '') {
    foreach (preg_split('/\r?\n/', $topbarContent) as $line) {
        if (strpos($line, 'top-center') !== false || strpos($line, 'テスト') !== false) {
            $topbarPreview = trim($line);
            break;
        }
    }
}

$vaultConfigured = defined('VAULT_MASTER_KEY') && VAULT_MASTER_KEY !== '';
$vaultLocalPath = $base . '/config/vault.local.php';
$response = [
    'deploy_verify' => 'deploy-check-' . date('YmdHis'),
    'message' => 'このサーバーが参照しているプロジェクトルートを base_dir で確認し、FTP等のアップロード先がそのパス（またはその配下）と一致しているか確認してください。',
    'http_host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'base_dir' => $base,
    'base_dir_realpath' => realpath($base) ?: null,
    'vault_configured' => $vaultConfigured,
    'vault_file_exists' => file_exists($vaultLocalPath),
    'vault_file_readable' => file_exists($vaultLocalPath) && is_readable($vaultLocalPath),
    'vault_file_path' => $vaultLocalPath,
    'topbar_has_test_badge' => $hasTestBadge,
    'topbar_preview_line' => $topbarPreview,
    'topbar_mtime' => file_exists($topbar) ? date('Y-m-d H:i:s', filemtime($topbar)) : null,
    'is_admin' => $isAdmin,
];

if ($bootstrapError !== null) {
    $response['bootstrap_error'] = $bootstrapError;
    $response['note'] = 'DB/セッションでエラーが出たため管理者チェックはスキップしています。base_dir と topbar_has_test_badge は参照できます。';
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
