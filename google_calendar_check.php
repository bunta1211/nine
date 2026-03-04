<?php
/**
 * Googleカレンダー設定の診断（確認後は必ず削除すること）
 */
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$results[] = 'PHP_VERSION: ' . PHP_VERSION;
$results[] = 'vendor/autoload: ' . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'EXISTS' : 'MISSING');
$results[] = 'vendor/google/apiclient: ' . (is_dir(__DIR__ . '/vendor/google/apiclient') ? 'EXISTS' : 'MISSING');
$results[] = 'Client.php: ' . (file_exists(__DIR__ . '/vendor/google/apiclient/src/Client.php') ? 'EXISTS' : 'MISSING');

// autoload実行時のエラーを捕捉
$prev = error_reporting(E_ALL);
$buf = '';
set_error_handler(function ($n, $msg, $f, $l) use (&$buf) { $buf .= "Line $l: $msg\n"; return false; });
try {
    require_once __DIR__ . '/vendor/autoload.php';
    $results[] = 'autoload_loaded: YES';
    $results[] = 'Google_Client_class: ' . (class_exists('Google\Client') ? 'YES' : 'NO');
    if (!class_exists('Google\Client')) {
        $results[] = '--- trying direct require ---';
        try {
            require_once __DIR__ . '/vendor/google/apiclient/src/Client.php';
            $results[] = 'direct_require: OK';
            $results[] = 'class_after_direct: ' . (class_exists('Google\Client') ? 'YES' : 'NO');
        } catch (Throwable $e2) {
            $results[] = 'direct_error: ' . $e2->getMessage();
        }
    } else {
        $test = new \Google\Client();
        $results[] = 'Google_Client_new: OK';
    }
} catch (Throwable $e) {
    $results[] = 'error: ' . $e->getMessage();
    $results[] = 'file: ' . $e->getFile() . ':' . $e->getLine();
}
restore_error_handler();
error_reporting($prev);
if ($buf) {
    $results[] = 'php_errors: ' . trim($buf);
}

require_once __DIR__ . '/config/google_calendar.php';
if (file_exists(__DIR__ . '/includes/google_calendar_helper.php')) {
    require_once __DIR__ . '/includes/google_calendar_helper.php';
}
$results[] = 'isGoogleCalendarClientAvailable: ' . (function_exists('isGoogleCalendarClientAvailable') && isGoogleCalendarClientAvailable() ? 'YES' : 'NO');

echo implode("\n", $results);
