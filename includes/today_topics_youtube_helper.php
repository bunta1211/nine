<?php
/**
 * 朝のニュース動画用 YouTube Data API v3 取得ヘルパー
 * 計画書: DOCS/PLAN_TODAYS_TOPICS.md / 朝のニュース動画化プラン
 *
 * - 指定プレイリスト・チャンネルから最新動画を取得
 * - キャッシュ: storage/cache/today_topics_videos_YYYYMMDD.json
 */

if (!defined('CRON_MODE') && php_sapi_name() !== 'cli') {
    require_once dirname(__DIR__) . '/config/ai_config.php';
}

/** 朝のニュース動画用プレイリストID（1件） */
const TODAY_TOPICS_YOUTUBE_PLAYLIST_ID = 'PLirT2ByBZWrPImeOExbnZHWzcQEvSMSn5';

/** 朝のニュース動画用チャンネルハンドル（@ なし・11件） */
const TODAY_TOPICS_YOUTUBE_CHANNEL_HANDLES = [
    'tbsnewsdig',
    'ANNnewsCH',
    'wellnessnews',
    'ytv_news',
    'rehacq',
    'pivot00',
    'kosodate.ouennzatsugaku',
    'zatsugaku-papa',
    'Numberblocks',
    'CoComelon',
    'SuperSimpleSongs',
];

/** キャッシュ有効期限（当日24時まで・秒） */
const TODAY_TOPICS_VIDEOS_CACHE_TTL = 86400;

/**
 * 当日分の動画キャッシュファイルパス
 */
function getTodayTopicsVideosCachePath(): string {
    $base = dirname(__DIR__);
    $dir = $base . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/today_topics_videos_' . date('Ymd') . '.json';
}

/**
 * YouTube Data API v3 でプレイリストの動画を取得
 *
 * @param string $apiKey YOUTUBE_DATA_API_KEY
 * @param string $playlistId プレイリストID
 * @param int $maxResults 最大件数
 * @return array<int, array{id: string, title: string, channelTitle: string, publishedAt: string, thumbnail: string}>
 */
function fetchYouTubePlaylistVideos(string $apiKey, string $playlistId, int $maxResults = 15): array {
    $url = 'https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query([
        'part' => 'snippet',
        'playlistId' => $playlistId,
        'maxResults' => $maxResults,
        'key' => $apiKey,
    ]);
    $json = @file_get_contents($url);
    if ($json === false) {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['items'])) {
        return [];
    }
    $out = [];
    foreach ($data['items'] as $item) {
        $snippet = $item['snippet'] ?? [];
        $vid = $snippet['resourceId']['videoId'] ?? null;
        if (!$vid) {
            continue;
        }
        $thumb = $snippet['thumbnails']['medium']['url'] ?? $snippet['thumbnails']['default']['url'] ?? '';
        $out[] = [
            'id' => $vid,
            'title' => (string)($snippet['title'] ?? ''),
            'channelTitle' => (string)($snippet['channelTitle'] ?? ''),
            'publishedAt' => (string)($snippet['publishedAt'] ?? ''),
            'thumbnail' => (string)$thumb,
        ];
    }
    return $out;
}

/**
 * チャンネルハンドルから uploads プレイリストID を取得
 *
 * @param string $apiKey
 * @param string $handle ハンドル（@ なし）
 * @return string|null
 */
function fetchYouTubeChannelUploadsPlaylistId(string $apiKey, string $handle): ?string {
    $url = 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
        'part' => 'contentDetails',
        'forHandle' => $handle,
        'key' => $apiKey,
    ]);
    $json = @file_get_contents($url);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    $items = $data['items'] ?? [];
    if (empty($items)) {
        return null;
    }
    $uploadId = $items[0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    return $uploadId ? (string)$uploadId : null;
}

/**
 * 指定プレイリスト・チャンネルから朝のニュース動画一覧を取得（API 直叩き）
 * 結果を publishedAt 降順でソートし、videoId で重複除去
 *
 * @return array{videos: array, fetched_at: string}
 */
function fetchMorningNewsVideosFromYouTube(): array {
    $apiKey = defined('YOUTUBE_DATA_API_KEY') ? (string)YOUTUBE_DATA_API_KEY : '';
    if ($apiKey === '') {
        return ['videos' => [], 'fetched_at' => date('Y-m-d H:i:s')];
    }

    $seen = [];
    $all = [];

    // 1) プレイリスト
    $playlistVideos = fetchYouTubePlaylistVideos($apiKey, TODAY_TOPICS_YOUTUBE_PLAYLIST_ID, 15);
    foreach ($playlistVideos as $v) {
        $id = $v['id'];
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $all[] = $v;
        }
    }

    // 2) 各チャンネルの直近アップロード
    foreach (TODAY_TOPICS_YOUTUBE_CHANNEL_HANDLES as $handle) {
        $uploadId = fetchYouTubeChannelUploadsPlaylistId($apiKey, $handle);
        if ($uploadId === null) {
            continue;
        }
        $channelVideos = fetchYouTubePlaylistVideos($apiKey, $uploadId, 5);
        foreach ($channelVideos as $v) {
            $id = $v['id'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $all[] = $v;
            }
        }
    }

    // publishedAt 降順
    usort($all, function ($a, $b) {
        return strcmp($b['publishedAt'] ?? '', $a['publishedAt'] ?? '');
    });

    return [
        'videos' => array_values($all),
        'fetched_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * 動画キャッシュを読む。無いか期限切れなら取得してキャッシュに書き、返す
 *
 * @return array{videos: array, fetched_at: string}
 */
function getTodayTopicsVideosCacheOrFetch(): array {
    $path = getTodayTopicsVideosCachePath();
    $now = time();

    if (file_exists($path) && ($now - filemtime($path)) < TODAY_TOPICS_VIDEOS_CACHE_TTL) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $dec = json_decode($raw, true);
            if (is_array($dec) && isset($dec['videos'])) {
                return $dec;
            }
        }
    }

    $data = fetchMorningNewsVideosFromYouTube();
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}
