<?php
/**
 * シンプルなログ出力関数
 * 
 * 使い方:
 *   require_once __DIR__ . '/logger.php';
 *   writeLog('ユーザーがログインしました');
 *   logError('データベース接続エラー');
 *   logAudit(1, 'login', 'IPアドレス: 192.168.1.1');
 */

define('LOG_DIR', __DIR__ . '/../logs/');

/**
 * ログを出力
 * 
 * @param string $message メッセージ
 * @param string $level レベル（info/warning/error）
 * @param string $type ログ種別（app/error/audit）
 * @return bool 書き込み成功したかどうか
 */
function writeLog($message, $level = 'info', $type = 'app') {
    try {
        $date = date('Y-m-d');
        $time = date('Y-m-d H:i:s');
        $file = LOG_DIR . "{$type}_{$date}.log";
        
        // ログディレクトリがなければ作成
        if (!is_dir(LOG_DIR)) {
            mkdir(LOG_DIR, 0755, true);
        }
        
        // メッセージをサニタイズ
        $message = preg_replace('/[\r\n]+/', ' ', $message);
        
        $logLine = "[{$time}] [{$level}] {$message}" . PHP_EOL;
        return file_put_contents($file, $logLine, FILE_APPEND | LOCK_EX) !== false;
    } catch (Exception $e) {
        // ログ書き込み自体のエラーは無視
        return false;
    }
}

/**
 * 情報ログ
 * 
 * @param string $message メッセージ
 */
function logInfo($message) {
    writeLog($message, 'info', 'app');
}

/**
 * 警告ログ
 * 
 * @param string $message メッセージ
 */
function logWarning($message) {
    writeLog($message, 'warning', 'app');
}

/**
 * エラーログ
 * 
 * @param string $message メッセージ
 */
function logError($message) {
    writeLog($message, 'error', 'error');
}

/**
 * 監査ログ（誰が何をしたか）
 * 
 * @param int $userId ユーザーID
 * @param string $action 操作内容
 * @param string $details 詳細情報
 */
function logAudit($userId, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $message = "user_id={$userId} action={$action} ip={$ip} {$details}";
    writeLog($message, 'info', 'audit');
}

/**
 * 例外をログに記録
 * 
 * @param Exception $e 例外オブジェクト
 * @param string $context コンテキスト情報
 */
function logException($e, $context = '') {
    $message = sprintf(
        '%s Exception: %s in %s:%d | %s',
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        str_replace("\n", " ", $e->getTraceAsString())
    );
    logError($message);
}

/**
 * デバッグログ（開発環境のみ）
 * 
 * @param string $message メッセージ
 * @param mixed $data デバッグデータ
 */
function logDebug($message, $data = null) {
    // 本番環境では無効化する場合はここでreturn
    // if (getenv('APP_ENV') === 'production') return;
    
    if ($data !== null) {
        $message .= ' | Data: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    writeLog($message, 'debug', 'app');
}

/**
 * ログファイルの一覧を取得（管理画面用）
 * 
 * @param int $days 何日分取得するか
 * @return array ログファイル情報の配列
 */
function getLogFiles($days = 7) {
    $files = [];
    
    if (!is_dir(LOG_DIR)) {
        return $files;
    }
    
    $today = new DateTime();
    
    for ($i = 0; $i < $days; $i++) {
        $date = $today->format('Y-m-d');
        
        foreach (['app', 'error', 'audit'] as $type) {
            $path = LOG_DIR . "{$type}_{$date}.log";
            if (file_exists($path)) {
                $files[] = [
                    'type' => $type,
                    'date' => $date,
                    'path' => $path,
                    'size' => filesize($path),
                    'lines' => count(file($path))
                ];
            }
        }
        
        $today->modify('-1 day');
    }
    
    return $files;
}

/**
 * ログファイルの内容を取得（最新N行）
 * 
 * @param string $type ログ種別
 * @param string $date 日付（Y-m-d形式）
 * @param int $lines 取得する行数
 * @return array ログ行の配列
 */
function getLogContent($type, $date, $lines = 100) {
    $path = LOG_DIR . "{$type}_{$date}.log";
    
    if (!file_exists($path)) {
        return [];
    }
    
    $allLines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // 最新N行を返す
    return array_slice($allLines, -$lines);
}




