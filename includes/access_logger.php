<?php
/**
 * アクセスログ（管理ダッシュボード用）
 * 本日のアクセス・検索経由・離脱率の集計に利用。
 * 前提: config/database.php と config/app.php が読み込まれていること。
 */

/**
 * 自ドメインのホスト名を返す（検索経由・リファラー分析用）
 * @return string|null
 */
function access_log_own_host() {
    if (defined('APP_URL') && APP_URL !== '') {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        if ($host !== false && $host !== null) {
            return strtolower($host);
        }
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $h = explode(':', $_SERVER['HTTP_HOST'])[0];
        return strtolower($h);
    }
    return null;
}

/**
 * 検索エンジンのリファラーホスト一覧（小文字）
 * @return string[]
 */
function access_log_search_hosts() {
    return [
        'www.google.co.jp', 'google.co.jp', 'www.google.com', 'google.com',
        'www.bing.com', 'bing.com',
        'search.yahoo.co.jp', 'yahoo.co.jp', 'www.yahoo.co.jp',
        'duckduckgo.com', 'www.duckduckgo.com',
        'ecosia.org', 'www.ecosia.org',
        'baidu.com', 'www.baidu.com',
        'yandex.com', 'yandex.ru', 'www.yandex.ru',
    ];
}

/**
 * 現在の訪問者キー（同一訪問者の識別用）
 * セッションがある場合は session_id、なければ IP+User-Agent のハッシュ。
 * 戻り値は必ず64文字以内（access_log.visitor_key の VARCHAR(64) に合わせる）。
 * @return string
 */
function access_log_visitor_key() {
    if (function_exists('session_id')) {
        $sid = session_id();
        if ($sid !== '' && $sid !== null) {
            $key = 's:' . $sid;
            return strlen($key) > 64 ? substr($key, 0, 64) : $key;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $hash = hash('sha256', $ip . "\n" . $ua);
    return 'a:' . substr($hash, 0, 62);
}

/**
 * リクエストの Referer ホストを取得
 * @return string|null
 */
function access_log_referer_host() {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') {
        return null;
    }
    $host = parse_url($ref, PHP_URL_HOST);
    if ($host === false || $host === null) {
        return null;
    }
    return strtolower($host);
}

/**
 * access_log テーブルが存在するかどうか（管理画面の案内用）
 * @param PDO $pdo
 * @return bool
 */
function access_log_table_exists(PDO $pdo) {
    try {
        $pdo->query("SELECT 1 FROM access_log LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * アクセスを1件記録する（access_log テーブルに挿入）
 * テーブルが存在しない場合は何もしない。
 * @param string $path パス例: '/index.php', '/chat.php'
 */
function log_page_access($path) {
    static $logged = [];
    $key = $path . ':' . access_log_visitor_key();
    if (isset($logged[$key])) {
        return;
    }
    $logged[$key] = true;

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO access_log (visitor_key, path, referer_host, ip_address) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }
        $visitor_key = access_log_visitor_key();
        $referer_host = access_log_referer_host();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->execute([$visitor_key, $path, $referer_host, $ip]);
    } catch (PDOException $e) {
        // テーブル未作成・接続エラー時は静かに無視
    }
}

/**
 * 本日のアクセス・検索経由・離脱率を取得（管理ダッシュボード用）
 * 「本日」は PHP のデフォルトタイムゾーン（Asia/Tokyo）で判定する。
 * DB の created_at が UTC の場合は、その範囲を PHP で計算して比較する（CONVERT_TZ 非依存）。
 * @param PDO $pdo
 * @return array{ today_access: int, search_referral: int, bounce_rate: float|null }
 */
function get_access_stats_today(PDO $pdo) {
    $search_hosts = access_log_search_hosts();
    $placeholders = implode(',', array_fill(0, count($search_hosts), '?'));

    $today_access = 0;
    $search_referral = 0;
    $bounce_rate = null;

    $tz_app = new DateTimeZone(date_default_timezone_get());
    $tz_storage = defined('DB_STORAGE_TIMEZONE') ? DB_STORAGE_TIMEZONE : 'UTC';
    $tz_storage_obj = new DateTimeZone($tz_storage);
    $today_start = (new DateTime('today', $tz_app))->setTimezone($tz_storage_obj)->format('Y-m-d H:i:s');
    $today_end = (new DateTime('tomorrow', $tz_app))->modify('-1 second')->setTimezone($tz_storage_obj)->format('Y-m-d H:i:s');

    try {
        // 本日のアクセス: 今日のユニーク訪問者数（アプリタイムゾーンの「今日」で集計）
        $sql_today = "
            SELECT COUNT(DISTINCT visitor_key) AS cnt
            FROM access_log
            WHERE created_at >= ? AND created_at <= ?
        ";
        $stmt = $pdo->prepare($sql_today);
        $stmt->execute([$today_start, $today_end]);
        $today_access = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // 検索経由: 本日のユニーク訪問者のうち、リファラーが検索エンジンのもの
        $sql_search = "
            SELECT COUNT(DISTINCT visitor_key) AS cnt
            FROM access_log
            WHERE created_at >= ? AND created_at <= ?
              AND referer_host IN ($placeholders)
        ";
        $params_search = array_merge([$today_start, $today_end], $search_hosts);
        $stmt = $pdo->prepare($sql_search);
        $stmt->execute($params_search);
        $search_referral = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // 離脱率: 本日1ページのみの訪問者数 / 本日の総ユニーク訪問者
        $sql_bounce = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) AS bounced
            FROM (
                SELECT visitor_key, COUNT(*) AS cnt
                FROM access_log
                WHERE created_at >= ? AND created_at <= ?
                GROUP BY visitor_key
            ) t
        ";
        $stmt = $pdo->prepare($sql_bounce);
        $stmt->execute([$today_start, $today_end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) $row['total'];
        $bounced = (int) $row['bounced'];
        if ($total > 0) {
            $bounce_rate = round(100.0 * $bounced / $total, 1);
        }
    } catch (PDOException $e) {
        // テーブル未作成時
    } catch (Exception $e) {
        // タイムゾーン等の例外
    }

    return [
        'today_access' => $today_access,
        'search_referral' => $search_referral,
        'bounce_rate' => $bounce_rate,
    ];
}
