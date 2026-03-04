<?php
/**
 * 今日の話題（本日のニューストピックス・興味トピックレポート）ヘルパー
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md
 *
 * - 朝: RSS からニュース取得・キャッシュ・年代別本文組み立て・保存
 * - 夜: 興味トピックレポート（cron/ai_today_topics_evening.php で 16〜20 時配信）
 */

if (!defined('CRON_MODE') && php_sapi_name() !== 'cli') {
    require_once dirname(__DIR__) . '/config/ai_config.php';
}

/** 本日のニューストピックス配信時の question 識別子 */
const TODAY_TOPICS_QUESTION_MORNING = '（本日のニューストピックス）';

/** 興味トピックレポート（夜）配信時の question 識別子 */
const TODAY_TOPICS_QUESTION_EVENING = '（興味トピックレポート）';

/** 朝のニュース動画ブロックを answer 内で識別するマーカー（フロントで JSON 分割に使用） */
const TODAY_TOPICS_MORNING_VIDEO_BLOCK_MARKER = '（朝のニュース動画）';

/** 登録ユーザーがこの人数を超えたら夜の個別配信を有料対象に切り替える（計画書 4.1, 12.6） */
const TODAY_TOPICS_PAID_SWITCH_THRESHOLD = 200;

/** キャッシュ有効期限（当日 24 時まで。秒） */
const TODAY_TOPICS_CACHE_TTL = 86400;

/**
 * 当日分のニュース・トレンドキャッシュファイルパス
 */
function getTodayTopicsCachePath(): string {
    $base = dirname(__DIR__);
    $dir = $base . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/today_topics_' . date('Ymd') . '.json';
}

/**
 * リンク先が NHK（ONE / news.web.nhk 等）かどうか。登録必須のため引用対象外。
 */
function isLinkFromNhK(string $link): bool {
    if ($link === '') return false;
    $lower = mb_strtolower($link);
    return (str_contains($lower, 'nhk') || str_contains($lower, 'news.web.nhk'));
}

/**
 * 取得元は登録不要で読めるウェブニュースに限定。NHKは使用・引用しない。
 * 各カテゴリで「日本のニュース2件＋海外のニュース2件」を複数サイトから取得。
 *
 * @return array{by_category: array<string, array>, trend: array, fetched_at: string}
 */
function fetchTodayTopicsFromRss(): array {
    $out = [
        'by_category' => [
            '政治' => [],
            '経済' => [],
            '国際' => [],
            'スポーツ' => [],
            'IT・科学' => [],
            '社会' => [],
        ],
        'trend' => [],
        'fetched_at' => date('Y-m-d H:i:s'),
    ];

    // region: 'ja' = 日本、'overseas' = 海外。各カテゴリで ja 2件・overseas 2件まで。
    $limitPerRegion = 2;
    $countByCategoryRegion = [];
    foreach (array_keys($out['by_category']) as $c) {
        $countByCategoryRegion[$c] = ['ja' => 0, 'overseas' => 0];
    }

    $sources = [
        // 政治: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/domestic.xml', 'category' => '政治', 'region' => 'ja'],
        ['url' => 'https://mainichi.jp/rss/etc/mainichi-flash.rss', 'category' => '政治', 'region' => 'ja'],
        // 政治: 海外
        ['url' => 'http://feeds.cnn.co.jp/rss/cnn/cnn.rdf', 'category' => '政治', 'region' => 'overseas'],
        // 経済: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/business.xml', 'category' => '経済', 'region' => 'ja'],
        // 経済: 海外（ロイター日本語）
        ['url' => 'https://assets.wor.jp/rss/rdf/reuters/top.rdf', 'category' => '経済', 'region' => 'overseas'],
        // 国際: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/world.xml', 'category' => '国際', 'region' => 'ja'],
        // 国際: 海外
        ['url' => 'http://feeds.cnn.co.jp/rss/cnn/cnn.rdf', 'category' => '国際', 'region' => 'overseas'],
        ['url' => 'https://assets.wor.jp/rss/rdf/reuters/top.rdf', 'category' => '国際', 'region' => 'overseas'],
        // スポーツ: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/sports.xml', 'category' => 'スポーツ', 'region' => 'ja'],
        ['url' => 'https://mainichi.jp/rss/etc/mainichi-sports.rss', 'category' => 'スポーツ', 'region' => 'ja'],
        // スポーツ: 海外（ロイターにスポーツ含む）
        ['url' => 'https://assets.wor.jp/rss/rdf/reuters/top.rdf', 'category' => 'スポーツ', 'region' => 'overseas'],
        // IT・科学: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/it.xml', 'category' => 'IT・科学', 'region' => 'ja'],
        ['url' => 'https://news.yahoo.co.jp/rss/topics/science.xml', 'category' => 'IT・科学', 'region' => 'ja'],
        // IT・科学: 海外（CNN・ロイターにテック含む）
        ['url' => 'http://feeds.cnn.co.jp/rss/cnn/cnn.rdf', 'category' => 'IT・科学', 'region' => 'overseas'],
        ['url' => 'https://assets.wor.jp/rss/rdf/reuters/top.rdf', 'category' => 'IT・科学', 'region' => 'overseas'],
        // 社会: 日本
        ['url' => 'https://news.yahoo.co.jp/rss/topics/local.xml', 'category' => '社会', 'region' => 'ja'],
        ['url' => 'https://mainichi.jp/rss/etc/mainichi-flash.rss', 'category' => '社会', 'region' => 'ja'],
        // 社会: 海外
        ['url' => 'http://feeds.cnn.co.jp/rss/cnn/cnn.rdf', 'category' => '社会', 'region' => 'overseas'],
    ];

    foreach ($sources as $src) {
        $useCategory = $src['category'] ?? '社会';
        $region = $src['region'] ?? 'ja';
        if (!isset($out['by_category'][$useCategory]) || !isset($countByCategoryRegion[$useCategory][$region])) {
            continue;
        }
        if ($countByCategoryRegion[$useCategory][$region] >= $limitPerRegion) {
            continue;
        }

        $xml = @file_get_contents($src['url']);
        if (!$xml) continue;

        $doc = @simplexml_load_string($xml);
        if (!$doc) continue;

        $items = isset($doc->channel->item) ? $doc->channel->item : (isset($doc->item) ? $doc->item : null);
        if ($items === null) continue;

        $added = 0;
        $maxFromThisSource = $limitPerRegion - $countByCategoryRegion[$useCategory][$region];

        foreach ($items as $item) {
            if ($added >= $maxFromThisSource) break;
            $title = trim((string)$item->title);
            $link = trim((string)$item->link);
            if ($title === '') continue;
            if (isLinkFromNhK($link)) continue;

            $desc = trim((string)($item->description ?? $item->children('content', true)->encoded ?? ''));
            if (mb_strlen($desc) > 120) {
                $desc = mb_substr($desc, 0, 120) . '…';
            }

            $out['by_category'][$useCategory][] = [
                'title' => $title,
                'link' => $link,
                'description' => $desc,
            ];
            $countByCategoryRegion[$useCategory][$region]++;
            $added++;
        }
    }

    return $out;
}

/**
 * キャッシュを読む。無いか期限切れなら取得してキャッシュに書き、返す
 *
 * @return array{by_category: array, trend: array, fetched_at: string}
 */
function getTodayTopicsCacheOrFetch(): array {
    $path = getTodayTopicsCachePath();
    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $now = time();

    if (file_exists($path) && ($now - filemtime($path)) < TODAY_TOPICS_CACHE_TTL) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $dec = json_decode($raw, true);
            if (is_array($dec) && isset($dec['by_category'])) {
                return $dec;
            }
        }
    }

    $data = fetchTodayTopicsFromRss();
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

/**
 * ユーザーの年代帯を取得（10代〜70代）
 * users.birth_date から算出。未設定は null（年代別なし）
 *
 * @return string|null '10s'|'20s'|'30s'|'40s'|'50s'|'60s'|'70s'|null
 */
function getTodayTopicsAgeGroup(PDO $pdo, int $userId): ?string {
    try {
        $stmt = $pdo->prepare("SELECT birth_date FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['birth_date'])) return null;

        $birth = new DateTimeImmutable($row['birth_date']);
        $today = new DateTimeImmutable('today');
        $age = $today->diff($birth)->y;

        if ($age < 20) return '10s';
        if ($age < 30) return '20s';
        if ($age < 40) return '30s';
        if ($age < 50) return '40s';
        if ($age < 60) return '50s';
        if ($age < 70) return '60s';
        return '70s';
    } catch (Throwable $e) {
        error_log("today_topics age_group: " . $e->getMessage());
        return null;
    }
}

/**
 * ニュースデータから「本日のニューストピックス」本文を組み立て
 * 年代帯に応じてトレンド割合・要約長さを調整（計画書 8 年代別）
 *
 * @param array $data getTodayTopicsCacheOrFetch() の戻り値
 * @param string|null $ageGroup '10s'|'20s'|...|'70s'|null
 * @return string 本文（見出し＋分野別＋案内文）
 */
function buildMorningTopicsBody(array $data, ?string $ageGroup = null): string {
    $lines = [];
    $lines[] = "## 本日のニューストピックス";
    $lines[] = "";

    $byCategory = $data['by_category'] ?? [];
    $trendMore = in_array($ageGroup, ['10s', '20s'], true); // 若年はトレンド多め

    foreach ($byCategory as $category => $items) {
        if (empty($items)) continue;
        $lines[] = "**【{$category}】**";
        $n = ($ageGroup === '60s' || $ageGroup === '70s') ? 2 : 4; // シニアは2件、それ以外は日本2+海外2の4件
        $slice = array_slice($items, 0, $n);
        foreach ($slice as $i => $item) {
            $title = $item['title'] ?? '';
            $link = $item['link'] ?? '';
            if ($title === '') continue;
            // 題名のみ表示し、URLは題名クリックで開く形式 [題名](URL)。アドレス行は出さない
            if ($link !== '') {
                $lines[] = "・[{$title}]({$link})";
            } else {
                $lines[] = "・{$title}";
            }
        }
    }

    $lines[] = "興味のある分野や話題があれば、次回以降で反映させていくので希望をお知らせください。";
    return implode("\n", $lines);
}

/**
 * 朝のニュース動画用本文を組み立て（挨拶 + 動画リスト JSON）
 * フロントは answer を「（朝のニュース動画）」で分割し、2 番目を JSON として解析する
 *
 * @param array $videoList getTodayTopicsVideosCacheOrFetch()['videos'] 形式。各要素は id, title, channelTitle, publishedAt, thumbnail
 * @param string $greeting 挨拶文（generateProactiveMessage の戻り値など）
 * @return string 挨拶 + マーカー + JSON
 */
function buildMorningTopicsVideoBody(array $videoList, string $greeting): string {
    $marker = defined('TODAY_TOPICS_MORNING_VIDEO_BLOCK_MARKER') ? TODAY_TOPICS_MORNING_VIDEO_BLOCK_MARKER : '（朝のニュース動画）';
    $json = json_encode($videoList, JSON_UNESCAPED_UNICODE);
    return $greeting . "\n\n" . $marker . "\n" . $json;
}

/**
 * 本日のニューストピックス（挨拶＋本文）を ai_conversations に保存
 * 自動話しかけに統合した 1 通として保存する
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $fullMessage 挨拶文 + 本日のニューストピックス本文
 * @return bool
 */
function saveTodayTopicsMorningMessage(PDO $pdo, int $userId, string $fullMessage): bool {
    try {
        $hasIsProactive = false;
        try {
            $pdo->query("SELECT is_proactive FROM ai_conversations LIMIT 0");
            $hasIsProactive = true;
        } catch (Throwable $ignore) {}

        if ($hasIsProactive) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, is_proactive, created_at)
                VALUES (?, ?, ?, 'ai', 'ja', 1, NOW())
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at)
                VALUES (?, ?, ?, 'ai', 'ja', NOW())
            ");
        }
        $stmt->execute([$userId, TODAY_TOPICS_QUESTION_MORNING, $fullMessage]);
        return true;
    } catch (Throwable $e) {
        error_log("today_topics save error for user {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * 指定ユーザーが本日すでに「本日のニューストピックス」を受け取っているか
 */
function hasUserReceivedTodayTopicsMorning(PDO $pdo, int $userId): bool {
    $today = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM ai_conversations
            WHERE user_id = ? AND question = ?
              AND DATE(created_at) = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, TODAY_TOPICS_QUESTION_MORNING, $today]);
        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        error_log("today_topics hasReceived check: " . $e->getMessage());
        return true; // エラー時は重複送信を避ける
    }
}

/**
 * 直前に配信したメッセージが「本日のニューストピックス」かどうか（計画書 3.6 トリガー判定）
 */
function isLastConversationTodayTopics(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT question FROM ai_conversations
            WHERE user_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && isset($row['question']) && trim($row['question']) === TODAY_TOPICS_QUESTION_MORNING;
    } catch (Throwable $e) {
        error_log("today_topics isLastConversationTodayTopics: " . $e->getMessage());
        return false;
    }
}

/**
 * ニューストピックスへの返信から興味・希望を抽出し user_topic_interests に保存（計画書 3.6）
 */
function extractAndSaveTopicInterestsFromReply(PDO $pdo, int $userId, string $userMessage): void {
    $userMessage = trim($userMessage);
    if ($userMessage === '') return;
    if (!function_exists('geminiChat') || !function_exists('isGeminiAvailable') || !isGeminiAvailable()) return;

    $systemPrompt = 'ユーザーは「本日のニューストピックス」への返信で、興味のある分野や話題を伝えています。このメッセージから、ニュースでフォローしたい「分野（category）」または「キーワード（keyword）」を最大3つ抽出してください。分野の例: 政治, 経済, 国際, スポーツ, IT・科学, 社会。応答は次のJSON形式のみ1行で出力。抽出できない場合は []。[{"type":"category","value":"経済"},{"type":"keyword","value":"〇〇"}]';
    $res = geminiChat($userMessage, [], $systemPrompt, null);
    if (!($res['success'] ?? false) || empty($res['response'])) return;
    $text = trim($res['response']);
    if ($text === '[]' || $text === '') return;
    if (preg_match('/\[.*\]/s', $text, $m)) {
        $arr = json_decode($m[0], true);
        if (!is_array($arr)) return;
        foreach ($arr as $item) {
            if (!is_array($item) || empty($item['type']) || empty($item['value'])) continue;
            $type = (string)$item['type'];
            if ($type !== 'category' && $type !== 'keyword') $type = 'keyword';
            $value = mb_substr(trim((string)$item['value']), 0, 255);
            if ($value === '') continue;
            try {
                $stmt = $pdo->prepare("INSERT INTO user_topic_interests (user_id, interest_type, value, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$userId, $type, $value]);
            } catch (Throwable $e) {
                error_log("today_topics user_topic_interests insert: " . $e->getMessage());
            }
        }
    }
}

// ---------- 夜の興味トピックレポート（計画書 4） ----------

/**
 * 登録ユーザー総数を取得（200名超切り替え判定用）
 */
function getTotalRegisteredUserCount(PDO $pdo): int {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM users");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        error_log("today_topics getTotalRegisteredUserCount: " . $e->getMessage());
        return 0;
    }
}

/**
 * 200名超で有料モードか（夜の個別配信は加入者のみ、非加入者は一斉配信）
 */
function isTodayTopicsPaidModeEnabled(PDO $pdo): bool {
    return getTotalRegisteredUserCount($pdo) > TODAY_TOPICS_PAID_SWITCH_THRESHOLD;
}

/**
 * ユーザーが月額ニュース配信プランに加入しているか（user_ai_settings.today_topics_paid_plan）
 */
function isUserOnTodayTopicsPaidPlan(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare("SELECT today_topics_paid_plan FROM user_ai_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['today_topics_paid_plan']) && (int)$row['today_topics_paid_plan'] === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 前日・前々日の両方でクリックが 0 件か（2 日連続未クリックなら true = 配信しない）
 */
function hasUserTwoConsecutiveDaysWithoutClick(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN DATE(clicked_at) = CURDATE() - INTERVAL 1 DAY THEN 1 ELSE 0 END) AS cnt_d1,
                SUM(CASE WHEN DATE(clicked_at) = CURDATE() - INTERVAL 2 DAY THEN 1 ELSE 0 END) AS cnt_d2
            FROM today_topic_clicks
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $d1 = (int)($row['cnt_d1'] ?? 0);
        $d2 = (int)($row['cnt_d2'] ?? 0);
        return ($d1 === 0 && $d2 === 0);
    } catch (Throwable $e) {
        error_log("today_topics hasUserTwoConsecutiveDaysWithoutClick: " . $e->getMessage());
        return true;
    }
}

/**
 * 本日すでに夜の興味レポートを受け取っているか
 */
function hasUserReceivedEveningReportToday(PDO $pdo, int $userId): bool {
    $today = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM ai_conversations
            WHERE user_id = ? AND question = ? AND DATE(created_at) = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, TODAY_TOPICS_QUESTION_EVENING, $today]);
        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        error_log("today_topics hasUserReceivedEveningReportToday: " . $e->getMessage());
        return true;
    }
}

/**
 * 夜の興味レポート「個別配信」対象ユーザーID一覧
 * - 200名以下（お試し）: 登録7日以内、2日連続未クリックは除外、本日未送信
 * - 200名超（有料モード）: 月額プラン加入者のみ、2日連続未クリックは除外、本日未送信
 * $slotMod: 16〜20 のスロット用。user_id % 5 が $slotMod のユーザーだけ返す（負荷分散）
 * $totalCount: null の場合は内部で取得
 */
function getEveningReportTargetUserIds(PDO $pdo, int $slotMod, ?int $totalCount = null): array {
    if ($totalCount === null) {
        $totalCount = getTotalRegisteredUserCount($pdo);
    }
    $paidMode = $totalCount > TODAY_TOPICS_PAID_SWITCH_THRESHOLD;

    try {
        if ($paidMode) {
            // 有料モード: today_topics_paid_plan = 1 のユーザーのみ
            $stmt = $pdo->prepare("
                SELECT u.id
                FROM users u
                JOIN user_ai_settings uas ON uas.user_id = u.id
                WHERE COALESCE(uas.today_topics_evening_enabled, 1) = 1
                  AND COALESCE(uas.today_topics_paid_plan, 0) = 1
                  AND (u.id % 5) = ?
                ORDER BY u.id
            ");
        } else {
            // お試し: 登録7日以内
            $stmt = $pdo->prepare("
                SELECT u.id
                FROM users u
                JOIN user_ai_settings uas ON uas.user_id = u.id
                WHERE COALESCE(uas.today_topics_evening_enabled, 1) = 1
                  AND (u.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))
                  AND (u.id % 5) = ?
                ORDER BY u.id
            ");
        }
        $stmt->execute([$slotMod % 5]);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            if (!hasUserTwoConsecutiveDaysWithoutClick($pdo, $id) && !hasUserReceivedEveningReportToday($pdo, $id)) {
                $ids[] = $id;
            }
        }
        if (defined('TODAY_TOPICS_LIMIT_USER_IDS') && TODAY_TOPICS_LIMIT_USER_IDS !== '') {
            $limitIds = json_decode(TODAY_TOPICS_LIMIT_USER_IDS, true);
            if (is_array($limitIds) && !empty($limitIds)) {
                $limitIds = array_map('intval', $limitIds);
                $ids = array_values(array_intersect($ids, $limitIds));
            }
        }
        return $ids;
    } catch (Throwable $e) {
        error_log("today_topics getEveningReportTargetUserIds: " . $e->getMessage());
        return [];
    }
}

/**
 * 夜の興味レポート「一斉配信」対象ユーザーID一覧（個別配信でない人に同じ1通を配る）
 * 夜ON・本日未受信・( user_id % 5 ) = slotMod のうち、$individualIds に含まれないユーザー
 */
function getEveningReportBulkTargetUserIds(PDO $pdo, int $slotMod, array $individualIds): array {
    if (empty($individualIds)) {
        $inPlaceholder = '0';
        $params = [$slotMod % 5];
    } else {
        $inPlaceholder = implode(',', array_map('intval', $individualIds));
        $params = [$slotMod % 5];
    }
    try {
        $sql = "
            SELECT u.id
            FROM users u
            JOIN user_ai_settings uas ON uas.user_id = u.id
            WHERE COALESCE(uas.today_topics_evening_enabled, 1) = 1
              AND (u.id % 5) = ?
              AND NOT EXISTS (
                SELECT 1 FROM ai_conversations c
                WHERE c.user_id = u.id AND c.question = ?
                  AND DATE(c.created_at) = CURDATE()
              )
        ";
        $params[] = TODAY_TOPICS_QUESTION_EVENING;
        if (!empty($individualIds)) {
            $sql .= " AND u.id NOT IN ({$inPlaceholder})";
        }
        $sql .= " ORDER BY u.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int)$row['id'];
        }
        if (defined('TODAY_TOPICS_LIMIT_USER_IDS') && TODAY_TOPICS_LIMIT_USER_IDS !== '') {
            $limitIds = json_decode(TODAY_TOPICS_LIMIT_USER_IDS, true);
            if (is_array($limitIds) && !empty($limitIds)) {
                $limitIds = array_map('intval', $limitIds);
                $ids = array_values(array_intersect($ids, $limitIds));
            }
        }
        return $ids;
    } catch (Throwable $e) {
        error_log("today_topics getEveningReportBulkTargetUserIds: " . $e->getMessage());
        return [];
    }
}

/**
 * 夜の一斉配信用キャッシュファイルパス（1日1通・当日有効）
 */
function getEveningBulkCachePath(): string {
    $base = dirname(__DIR__);
    $dir = $base . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/evening_bulk_' . date('Ymd') . '.json';
}

/**
 * 夜の一斉配信本文を取得（キャッシュがあれば返し、なければ1回だけGeminiで生成してキャッシュ）
 */
function getEveningBulkContentOrGenerate(PDO $pdo): ?string {
    $path = getEveningBulkCachePath();
    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $now = time();
    if (file_exists($path) && ($now - filemtime($path)) < TODAY_TOPICS_CACHE_TTL) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $dec = json_decode($raw, true);
            if (is_array($dec) && !empty($dec['content'])) {
                return $dec['content'];
            }
        }
    }
    if (!function_exists('geminiChat') || !function_exists('isGeminiAvailable') || !isGeminiAvailable()) {
        return null;
    }
    $topicData = getTodayTopicsCacheOrFetch();
    $byCategory = $topicData['by_category'] ?? [];
    $flat = [];
    foreach ($byCategory as $cat => $items) {
        foreach ($items as $it) {
            $flat[] = $cat . ' - ' . ($it['title'] ?? '') . ' ' . ($it['link'] ?? '');
        }
    }
    $newsSummary = implode("\n", array_slice($flat, 0, 15));
    $prompt = "今日のニュース候補をもとに、夕方の「興味トピックレポート」を誰にでも通用する形で2〜3文でまとめてください。\n\n【今日のニュース候補】\n{$newsSummary}\n\n「こんな話題がありました」のような形で、見出しやリンクを1〜2本含めて短くまとめてください。";
    $res = geminiChat($prompt, [], 'あなたはニュースレポートを簡潔にまとめるアシスタントです。', null);
    if (!($res['success'] ?? false) || empty($res['response'])) {
        return null;
    }
    $content = trim($res['response']);
    @file_put_contents($path, json_encode(['content' => $content, 'generated_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
    return $content;
}

/**
 * 夜の興味レポート本文を生成（collectUserContext + user_topic_interests + 朝のRSSキャッシュを利用）
 * 有料モードかつ月額プラン加入かつ推し登録ありのときのみ「推し」ブロックを追加（計画書 3.7）
 */
function generateEveningInterestReportContent(PDO $pdo, int $userId): ?string {
    if (!function_exists('geminiChat') || !function_exists('isGeminiAvailable') || !isGeminiAvailable()) return null;
    if (!function_exists('collectUserContext')) return null;

    $contextLines = collectUserContext($pdo, $userId, 15);
    $interests = [];
    try {
        $stmt = $pdo->prepare("SELECT interest_type, value FROM user_topic_interests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $interests[] = ($r['interest_type'] ?? '') . ':' . ($r['value'] ?? '');
        }
    } catch (Throwable $e) {}

    $topicData = getTodayTopicsCacheOrFetch();
    $byCategory = $topicData['by_category'] ?? [];
    $flat = [];
    foreach ($byCategory as $cat => $items) {
        foreach ($items as $it) {
            $flat[] = $cat . ' - ' . ($it['title'] ?? '') . ' ' . ($it['link'] ?? '');
        }
    }
    $newsSummary = implode("\n", array_slice($flat, 0, 15));

    $userContextStr = implode("\n", array_slice($contextLines, 0, 10));
    $interestsStr = implode(', ', $interests);
    if ($interestsStr === '') $interestsStr = '（未登録）';

    $prompt = "以下のユーザー情報と興味・希望、および今日のニュース候補をもとに、夕方の「興味トピックレポート」を2〜3文で作成してください。\n\n【ユーザーコンテキスト】\n{$userContextStr}\n\n【登録されている興味・希望】\n{$interestsStr}\n\n【今日のニュース候補】\n{$newsSummary}\n\n「〇〇に興味がありそうなので、こんな話題がありました」のような形で、具体的な見出しやリンクを1〜2本含めて短くまとめてください。";

    // 有料モードかつ月額プラン加入かつ推し登録ありのときのみ推しブロックを追加（計画書 3.7）
    $oshiBlock = '';
    if (isTodayTopicsPaidModeEnabled($pdo) && isUserOnTodayTopicsPaidPlan($pdo, $userId)) {
        try {
            $stmtOshi = $pdo->prepare("SELECT value FROM user_topic_interests WHERE user_id = ? AND interest_type = 'oshi' ORDER BY id DESC LIMIT 1");
            $stmtOshi->execute([$userId]);
            $rowOshi = $stmtOshi->fetch(PDO::FETCH_ASSOC);
            if ($rowOshi && !empty(trim($rowOshi['value'] ?? ''))) {
                $oshiName = trim($rowOshi['value']);
                $oshiBlock = "\n\n続けて、推し（{$oshiName}）に関する最新の動きやファンの反応を1〜2文で追加してください。";
            }
        } catch (Throwable $e) {}
    }
    if ($oshiBlock !== '') {
        $prompt .= $oshiBlock;
    }

    $res = geminiChat($prompt, [], 'あなたはニュースレポートを簡潔にまとめるアシスタントです。', null);
    if (!($res['success'] ?? false) || empty($res['response'])) return null;
    return trim($res['response']);
}

/**
 * 夜の興味レポートを ai_conversations に保存
 */
function saveEveningInterestReportMessage(PDO $pdo, int $userId, string $content): bool {
    try {
        $hasIsProactive = false;
        try {
            $pdo->query("SELECT is_proactive FROM ai_conversations LIMIT 0");
            $hasIsProactive = true;
        } catch (Throwable $ignore) {}
        if ($hasIsProactive) {
            $stmt = $pdo->prepare("INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, is_proactive, created_at) VALUES (?, ?, ?, 'ai', 'ja', 1, NOW())");
        } else {
            $stmt = $pdo->prepare("INSERT INTO ai_conversations (user_id, question, answer, answered_by, language, created_at) VALUES (?, ?, ?, 'ai', 'ja', NOW())");
        }
        $stmt->execute([$userId, TODAY_TOPICS_QUESTION_EVENING, $content]);
        return true;
    } catch (Throwable $e) {
        error_log("today_topics saveEveningReport error user {$userId}: " . $e->getMessage());
        return false;
    }
}
