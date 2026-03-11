<?php
/**
 * アクセスログ（管理ダッシュボード用）
 * 本日のアクセス（同ドメイン除く）・検索経由・離脱率の集計に利用。
 * 前提: config/database.php と config/app.php が読み込まれていること。
 */

/**
 * 自ドメインのホスト名を返す（同ドメイン除外用）
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
 * セッションがある場合は session_id、なければ IP+User-Agent のハッシュ
 * @return string
 */
function access_log_visitor_key() {
    if (function_exists('session_id')) {
        $sid = session_id();
        if ($sid !== '' && $sid !== null) {
            return 's:' . $sid;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return 'a:' . hash('sha256', $ip . "\n" . $ua);
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
 * @param PDO $pdo
 * @return array{ today_access: int, search_referral: int, bounce_rate: float|null }
 */
function get_access_stats_today(PDO $pdo) {
    $own = access_log_own_host();
    $search_hosts = access_log_search_hosts();
    $placeholders = implode(',', array_fill(0, count($search_hosts), '?'));

    // 自ドメイン除外条件（APP_URL/HTTP_HOST が無い場合は全件対象）
    $refererCondition = '1=1';
    $refererParam = [];
    if ($own !== null && $own !== '') {
        $refererCondition = '(referer_host IS NULL OR referer_host != ?)';
        $refererParam = [$own];
    }

    $today_access = 0;
    $search_referral = 0;
    $bounce_rate = null;

    try {
        // 本日のアクセス（同ドメイン除く）: 今日のユニーク訪問者で、リファラーが自ドメインでないもの
        $sql_today = "
            SELECT COUNT(DISTINCT visitor_key) AS cnt
            FROM access_log
            WHERE DATE(created_at) = CURDATE()
              AND $refererCondition
        ";
        $stmt = $pdo->prepare($sql_today);
        $stmt->execute($refererParam);
        $today_access = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // 検索経由: 本日のユニーク訪問者のうち、リファラーが検索エンジンのもの
        $params_search = array_merge($refererParam, $search_hosts);
        $sql_search = "
            SELECT COUNT(DISTINCT visitor_key) AS cnt
            FROM access_log
            WHERE DATE(created_at) = CURDATE()
              AND $refererCondition
              AND referer_host IN ($placeholders)
        ";
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
                WHERE DATE(created_at) = CURDATE()
                  AND $refererCondition
                GROUP BY visitor_key
            ) t
        ";
        $stmt = $pdo->prepare($sql_bounce);
        $stmt->execute($refererParam);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) $row['total'];
        $bounced = (int) $row['bounced'];
        if ($total > 0) {
            $bounce_rate = round(100.0 * $bounced / $total, 1);
        }
    } catch (PDOException $e) {
        // テーブル未作成時
    }

    return [
        'today_access' => $today_access,
        'search_referral' => $search_referral,
        'bounce_rate' => $bounce_rate,
    ];
}
