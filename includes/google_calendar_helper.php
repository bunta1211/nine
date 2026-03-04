<?php
/**
 * Googleカレンダー連携ヘルパー
 *
 * 必要なライブラリ: google/apiclient
 * インストール: composer require google/apiclient
 */

require_once __DIR__ . '/../config/google_calendar.php';

// ComposerオートローダーがGoogle系クラスを解決しない環境向けの事前読み込み
$vendorPsrLog = __DIR__ . '/../vendor/psr/log/src';
if (is_dir($vendorPsrLog) && !interface_exists('Psr\Log\LoggerInterface', false)) {
    foreach (['LoggerInterface.php', 'LogLevel.php', 'LoggerTrait.php', 'AbstractLogger.php', 'NullLogger.php', 'LoggerAwareInterface.php', 'LoggerAwareTrait.php', 'InvalidArgumentException.php'] as $f) {
        $p = $vendorPsrLog . '/' . $f;
        if (file_exists($p)) {
            require_once $p;
        }
    }
}
// OAuth2フローに必要なファイルのみ（Cache/CredentialSource/Credentials等は除外）
$vendorAuth = __DIR__ . '/../vendor/google/auth/src';
if (is_dir($vendorAuth) && !class_exists('Google\Auth\OAuth2', false)) {
    $exclude = ['/Cache/', '/CredentialSource/', '/Credentials/', '/ExecutableHandler/', '/Middleware/'];
    $authFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendorAuth, RecursiveDirectoryIterator::SKIP_DOTS));
    $paths = [];
    foreach ($authFiles as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $path = str_replace('\\', '/', $f->getPathname());
            $skip = false;
            foreach ($exclude as $e) {
                if (strpos($path, $e) !== false) { $skip = true; break; }
            }
            if (!$skip) {
                $paths[] = $path;
            }
        }
    }
    sort($paths);
    $first = [];
    $rest = [];
    foreach ($paths as $p) {
        $base = basename($p, '.php');
        if (substr($base, -9) === 'Interface' || substr($base, -5) === 'Trait') {
            $first[] = $p;
        } else {
            $rest[] = $p;
        }
    }
    foreach (array_merge($first, $rest) as $p) {
        require_once $p;
    }
}
if (!class_exists('Google\Service\Calendar', false)) {
    $apiClientBase = __DIR__ . '/../vendor/google/apiclient/src';
    $calendarServicesBase = __DIR__ . '/../vendor/google/apiclient-services/src';
    
    // 基底クラスを順番に読み込み
    foreach (['Exception.php', 'Model.php', 'Collection.php', 'Service.php'] as $baseFile) {
        $path = $apiClientBase . '/' . $baseFile;
        if (file_exists($path)) {
            require_once $path;
        }
    }
    // Service 配下のクラス
    foreach (['Exception.php', 'Resource.php'] as $svcFile) {
        $path = $apiClientBase . '/Service/' . $svcFile;
        if (file_exists($path)) {
            require_once $path;
        }
    }
    
    // Calendar Resource クラス（Events, Acl等）を先に読み込み（依存関係の順序）
    $calendarResourceDir = $calendarServicesBase . '/Calendar/Resource';
    if (is_dir($calendarResourceDir)) {
        // glob の代わりに scandir を使用（より確実）
        $files = @scandir($calendarResourceDir);
        if ($files) {
            foreach ($files as $f) {
                if (substr($f, -4) === '.php') {
                    require_once $calendarResourceDir . '/' . $f;
                }
            }
        }
    }
    
    // Calendar モデルクラス（Event, EventDateTime等）を読み込み
    $calendarModelsDir = $calendarServicesBase . '/Calendar';
    if (is_dir($calendarModelsDir)) {
        $files = @scandir($calendarModelsDir);
        if ($files) {
            foreach ($files as $f) {
                if (substr($f, -4) === '.php' && $f !== 'Resource') {
                    require_once $calendarModelsDir . '/' . $f;
                }
            }
        }
    }
    
    // Calendar サービス本体（最後に読み込み）
    $calendarService = $calendarServicesBase . '/Calendar.php';
    if (file_exists($calendarService)) {
        require_once $calendarService;
    }
}

/**
 * Google API Client が利用可能か
 */
function isGoogleCalendarClientAvailable() {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return false;
    }
    require_once $autoload;
    if (class_exists('Google\Client')) {
        return true;
    }
    // ComposerオートローダーがGoogle\Clientを解決しない環境向けのフォールバック
    $clientFile = __DIR__ . '/../vendor/google/apiclient/src/Client.php';
    if (file_exists($clientFile)) {
        require_once $clientFile;
    }
    return class_exists('Google\Client');
}

/**
 * 認証済みのGoogleクライアントを取得
 *
 * @param array $account google_calendar_accounts の1レコード
 * @param PDO $pdo
 * @return array ['client' => \Google\Client|null, 'error' => string|null] 失敗時は client が null で error に理由
 */
function getGoogleCalendarClient($account, $pdo = null) {
    if (!isGoogleCalendarClientAvailable()) {
        return ['client' => null, 'error' => 'Googleカレンダー用のライブラリが利用できません'];
    }
    if (!isGoogleCalendarEnabled()) {
        return ['client' => null, 'error' => 'Googleカレンダー連携が設定されていません（管理者設定を確認してください）'];
    }

    $client = new \Google\Client();
    $client->setClientId(GOOGLE_CALENDAR_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CALENDAR_CLIENT_SECRET);
    $client->setRedirectUri(getGoogleCalendarRedirectUri());
    $client->addScope(\Google\Service\Calendar::CALENDAR);
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    $refreshToken = trim($account['refresh_token'] ?? '');
    if ($refreshToken === '') {
        return ['client' => null, 'error' => 'カレンダーの認証が切れています。設定の「グーグルアカウントと連携」で一度切断し、再度「カレンダーを追加」で接続し直してください。'];
    }

    $token = ['refresh_token' => $refreshToken];
    if (!empty($account['access_token'])) {
        $decoded = is_string($account['access_token']) ? json_decode($account['access_token'], true) : $account['access_token'];
        if (is_array($decoded)) {
            $token = array_merge($decoded, $token);
        } else {
            $token['access_token'] = $account['access_token'];
        }
    }
    if (empty($token['created'])) {
        $token['created'] = time() - 3600;
    }
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        try {
            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable $e) {
            error_log('Google Calendar token refresh exception: ' . $e->getMessage());
            return ['client' => null, 'error' => 'トークンの更新に失敗しました。設定の「グーグルアカウントと連携」で一度切断し、再度「カレンダーを追加」で接続し直してください。'];
        }
        if (isset($newToken['error'])) {
            $desc = $newToken['error_description'] ?? $newToken['error'];
            error_log('Google Calendar token refresh error: ' . $desc);
            return ['client' => null, 'error' => 'カレンダーの認証が無効です。設定の「グーグルアカウントと連携」で一度切断し、再度「カレンダーを追加」で接続し直してください。'];
        }
        $client->setAccessToken($newToken);

        // トークン更新をDBに保存
        if ($pdo) {
            $expiresAt = null;
            if (isset($newToken['expires_in'])) {
                $expiresAt = date('Y-m-d H:i:s', time() + (int)$newToken['expires_in']);
            }
            $stmt = $pdo->prepare("
                UPDATE google_calendar_accounts 
                SET access_token = ?, token_expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                json_encode($newToken),
                $expiresAt,
                $account['id']
            ]);
        }
    }

    return ['client' => $client, 'error' => null];
}

/**
 * リダイレクトURIを取得
 */
function getGoogleCalendarRedirectUri() {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (empty($base) && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($path !== '/' && $path !== '\\') {
            $base .= rtrim($path, '/');
        }
    }
    return $base . '/api/google-calendar-callback.php';
}

/**
 * カレンダー名から比較用のコア部分を抽出（名前変更・言い換えに強くするため）
 * 例: "Kenカレンダー"→"ken", "Ken予定"→"ken", "仕事用"→"仕事用"
 *
 * @param string $name
 * @return string
 */
function getCalendarNameCore($name) {
    $s = mb_strtolower(trim($name ?? ''), 'UTF-8');
    $s = preg_replace('/\s*[（(]\s*デフォルト\s*[)）]\s*$/u', '', $s);
    $s = preg_replace('/(カレンダー|予定|のカレンダー|の予定)$/u', '', $s);
    return trim($s);
}

/**
 * 表示名またはIDでカレンダーアカウントを取得
 * 名前の定期変更にも対応（完全一致→コア一致→部分一致→単一/デフォルト）
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $target 表示名 または 'default'
 * @return array|null
 */
function getCalendarAccountByTarget($pdo, $userId, $target) {
    $target = trim($target ?? '');
    // 「〇〇（デフォルト）」や「〇〇 (デフォルト)」のサフィックスを除去
    $target = trim(preg_replace('/\s*[（(]\s*デフォルト\s*[)）]\s*$/u', '', $target));
    if (empty($target) || $target === 'default') {
        $stmt = $pdo->prepare("
            SELECT * FROM google_calendar_accounts 
            WHERE user_id = ? AND is_default = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM google_calendar_accounts 
        WHERE user_id = ?
        ORDER BY is_default DESC, id ASC
    ");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($accounts)) {
        return null;
    }

    // 1. 表示名・メールで完全一致
    foreach ($accounts as $acc) {
        $dn = trim($acc['display_name'] ?? '');
        $em = trim($acc['google_email'] ?? '');
        if ($target === $dn || $target === $em) {
            return $acc;
        }
    }

    // 2. コア部分で一致（名前変更・言い換え対応: Kenカレンダー↔Ken予定 等）
    $targetCore = getCalendarNameCore($target);
    if ($targetCore !== '') {
        $matches = [];
        foreach ($accounts as $acc) {
            $dn = trim($acc['display_name'] ?? '');
            if (getCalendarNameCore($dn) === $targetCore) {
                $matches[] = $acc;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            return $matches[0]; // 複数該当時はデフォルト優先の先頭
        }
    }

    // 3. 部分一致（表示名にtargetが含まれる、またはtargetに表示名が含まれる）
    $matches = [];
    foreach ($accounts as $acc) {
        $dn = trim($acc['display_name'] ?? '');
        if ($dn !== '' && (mb_strpos($dn, $target) !== false || mb_strpos($target, $dn) !== false)) {
            $matches[] = $acc;
        }
    }
    if (count($matches) === 1) {
        return $matches[0];
    }
    if (count($matches) > 1) {
        return $matches[0];
    }

    // 4. 単一カレンダーの場合はそれを使用
    if (count($accounts) === 1) {
        return $accounts[0];
    }

    // 5. 複数カレンダーでマッチしない場合、デフォルトを使用（旧名称のAI出力等への対応）
    foreach ($accounts as $acc) {
        if (($acc['is_default'] ?? 0) == 1 || ($acc['is_default'] ?? '') === '1') {
            return $acc;
        }
    }
    return $accounts[0];
}

/**
 * ユーザーのカレンダー一覧を取得（秘書プロンプト用）
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function getCalendarAccountsForPrompt($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT display_name, google_email, is_default 
        FROM google_calendar_accounts 
        WHERE user_id = ?
        ORDER BY is_default DESC, display_name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 日付解決：西暦なしの場合のルール
 * 未来日付→今年、過去日付→来年
 *
 * @param string $dateStr "MM-DD" または "M月D日" 等
 * @param int|null $refTime 基準時刻（省略時は現在）
 * @return int 年
 */
function resolveYearForDate($dateStr, $refTime = null) {
    $refTime = $refTime ?? time();
    $thisYear = (int)date('Y', $refTime);

    // "4月2日" -> 4, 2
    if (preg_match('/(\d{1,2})月(\d{1,2})日/', $dateStr, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $dateStr, $m)) {
        return (int)$m[1]; // 西暦あり
    } elseif (preg_match('/(\d{2})-(\d{2})/', $dateStr, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } else {
        return $thisYear;
    }

    $tsThisYear = strtotime("{$thisYear}-{$month}-{$day}");
    return ($tsThisYear >= $refTime) ? $thisYear : $thisYear + 1;
}

/**
 * 日時文字列をISO形式に正規化
 * 例: "2026-12-01 14:00" -> "2026-12-01T14:00:00+09:00"
 *
 * @param string $dt "YYYY-MM-DD HH:MM" または "YYYY-MM-DDTHH:MM"
 * @param string $timezone
 * @return string
 */
function normalizeDateTimeForCalendar($dt, $timezone = 'Asia/Tokyo') {
    $dt = str_replace('T', ' ', $dt);
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone($timezone));
    return $d->format('Y-m-d\TH:i:sP');
}

/**
 * イベントを作成
 *
 * @param PDO $pdo
 * @param array $account
 * @param string $startIso 開始日時 ISO8601
 * @param string $endIso 終了日時 ISO8601
 * @param string $title
 * @param string $description
 * @return array ['success' => bool, 'event_id' => string|null, 'error' => string|null]
 */
function createCalendarEvent($pdo, $account, $startIso, $endIso, $title, $description = '') {
    $result = getGoogleCalendarClient($account, $pdo);
    if ($result['client'] === null) {
        return ['success' => false, 'event_id' => null, 'error' => $result['error'] ?? 'カレンダーに接続できません'];
    }
    $client = $result['client'];

    try {
        $service = new \Google\Service\Calendar($client);
        $event = new \Google\Service\Calendar\Event([
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $startIso,
                'timeZone' => 'Asia/Tokyo',
            ],
            'end' => [
                'dateTime' => $endIso,
                'timeZone' => 'Asia/Tokyo',
            ],
        ]);
        $created = $service->events->insert('primary', $event);
        return [
            'success' => true,
            'event_id' => $created->getId(),
            'error' => null,
        ];
    } catch (Exception $e) {
        error_log('Google Calendar create error: ' . $e->getMessage());
        return [
            'success' => false,
            'event_id' => null,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * イベントを更新
 *
 * @param PDO $pdo
 * @param array $account
 * @param string $eventId
 * @param string $startIso
 * @param string $endIso
 * @param string $title
 * @param string $description
 * @return array ['success' => bool, 'error' => string|null]
 */
function updateCalendarEvent($pdo, $account, $eventId, $startIso, $endIso, $title, $description = '') {
    $result = getGoogleCalendarClient($account, $pdo);
    if ($result['client'] === null) {
        return ['success' => false, 'error' => $result['error'] ?? 'カレンダーに接続できません'];
    }
    $client = $result['client'];

    try {
        $service = new \Google\Service\Calendar($client);
        $event = $service->events->get('primary', $eventId);
        $event->setSummary($title);
        $event->setDescription($description);
        $event->setStart(new \Google\Service\Calendar\EventDateTime([
            'dateTime' => $startIso,
            'timeZone' => 'Asia/Tokyo',
        ]));
        $event->setEnd(new \Google\Service\Calendar\EventDateTime([
            'dateTime' => $endIso,
            'timeZone' => 'Asia/Tokyo',
        ]));
        $service->events->update('primary', $eventId, $event);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        error_log('Google Calendar update error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * イベントを削除
 *
 * @param PDO $pdo
 * @param array $account
 * @param string $eventId
 * @return array ['success' => bool, 'error' => string|null]
 */
function deleteCalendarEvent($pdo, $account, $eventId) {
    $result = getGoogleCalendarClient($account, $pdo);
    if ($result['client'] === null) {
        return ['success' => false, 'error' => $result['error'] ?? 'カレンダーに接続できません'];
    }
    $client = $result['client'];

    try {
        $service = new \Google\Service\Calendar($client);
        $service->events->delete('primary', $eventId);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        error_log('Google Calendar delete error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 日付・タイトルでイベントを検索してIDを取得
 *
 * @param PDO $pdo
 * @param array $account
 * @param string $date Y-m-d
 * @param string $title タイトル（部分一致）
 * @return string|null event_id
 */
function findCalendarEventByDateAndTitle($pdo, $account, $date, $title) {
    $result = getGoogleCalendarClient($account, $pdo);
    if ($result['client'] === null) {
        return null;
    }
    $client = $result['client'];

    try {
        $service = new \Google\Service\Calendar($client);
        $timeMin = $date . 'T00:00:00+09:00';
        $timeMax = $date . 'T23:59:59+09:00';
        $events = $service->events->listEvents('primary', [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => true,
        ]);
        foreach ($events->getItems() as $event) {
            if (mb_strpos($event->getSummary() ?? '', $title) !== false ||
                mb_strpos($title, $event->getSummary() ?? '') !== false) {
                return $event->getId();
            }
        }
    } catch (Exception $e) {
        error_log('Google Calendar find error: ' . $e->getMessage());
    }
    return null;
}
