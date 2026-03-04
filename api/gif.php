<?php
/**
 * GIF検索API
 * GIPHY APIを使用してGIFを検索
 */

// 最小限の初期化（依存なし）
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

// GETリクエストのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// テストモード
if (isset($_GET['test'])) {
    echo json_encode([
        'status' => 'ok',
        'curl_available' => function_exists('curl_init'),
        'php_version' => PHP_VERSION,
        'openssl' => extension_loaded('openssl')
    ]);
    exit;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 24;

if (empty($query)) {
    echo json_encode(['results' => [], 'error' => 'Query required']);
    exit;
}

/**
 * HTTPリクエストを実行
 */
function httpGet($url) {
    $response = null;
    $httpCode = 0;
    
    // cURLを試す
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $response = null;
        }
    }
    
    // file_get_contentsを試す
    if ($response === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n",
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
    }
    
    return $response;
}

// GIPHY API (公開ベータキー)
$giphyApiKey = 'dc6zaTOxFJmzC';
$url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
    'api_key' => $giphyApiKey,
    'q' => $query,
    'limit' => $limit,
    'rating' => 'g',
    'lang' => 'ja'
]);

$response = httpGet($url);
$results = [];
$source = 'giphy';

// GIPHYのレスポンスを解析
if ($response) {
    $data = json_decode($response, true);
    
    if ($data && isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $gif) {
            if (!isset($gif['images'])) continue;
            
            $tiny = $gif['images']['fixed_height_small']['url'] 
                ?? $gif['images']['fixed_width_small']['url'] 
                ?? $gif['images']['preview_gif']['url'] 
                ?? '';
            
            $full = $gif['images']['fixed_height']['url'] 
                ?? $gif['images']['original']['url'] 
                ?? $gif['images']['downsized']['url'] 
                ?? '';
            
            if (empty($tiny) && empty($full)) continue;
            if (empty($tiny)) $tiny = $full;
            if (empty($full)) $full = $tiny;
            
            $results[] = [
                'id' => $gif['id'] ?? '',
                'title' => $gif['title'] ?? '',
                'tiny' => $tiny,
                'full' => $full
            ];
        }
    }
}

// 結果がない場合、プリセットGIFを返す（フォールバック）
if (empty($results)) {
    // 人気のGIPHYスタンプをプリセット
    $presetGifs = [
        ['id' => '1', 'title' => 'thumbs up', 'tiny' => 'https://media.giphy.com/media/3o7abKhOpu0NwenH3O/200.gif', 'full' => 'https://media.giphy.com/media/3o7abKhOpu0NwenH3O/giphy.gif'],
        ['id' => '2', 'title' => 'happy', 'tiny' => 'https://media.giphy.com/media/l0MYt5jPR6QX5pnqM/200.gif', 'full' => 'https://media.giphy.com/media/l0MYt5jPR6QX5pnqM/giphy.gif'],
        ['id' => '3', 'title' => 'love', 'tiny' => 'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/200.gif', 'full' => 'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif'],
        ['id' => '4', 'title' => 'laugh', 'tiny' => 'https://media.giphy.com/media/10JhviFuU2gWD6/200.gif', 'full' => 'https://media.giphy.com/media/10JhviFuU2gWD6/giphy.gif'],
        ['id' => '5', 'title' => 'wow', 'tiny' => 'https://media.giphy.com/media/5VKbvrjxpVJCM/200.gif', 'full' => 'https://media.giphy.com/media/5VKbvrjxpVJCM/giphy.gif'],
        ['id' => '6', 'title' => 'clap', 'tiny' => 'https://media.giphy.com/media/l3q2XhfQ8oCkm1Ts4/200.gif', 'full' => 'https://media.giphy.com/media/l3q2XhfQ8oCkm1Ts4/giphy.gif'],
        ['id' => '7', 'title' => 'sad', 'tiny' => 'https://media.giphy.com/media/OPU6wzx8JrHna/200.gif', 'full' => 'https://media.giphy.com/media/OPU6wzx8JrHna/giphy.gif'],
        ['id' => '8', 'title' => 'party', 'tiny' => 'https://media.giphy.com/media/l0MYJnJQ4EiYLxvQ4/200.gif', 'full' => 'https://media.giphy.com/media/l0MYJnJQ4EiYLxvQ4/giphy.gif'],
        ['id' => '9', 'title' => 'ok', 'tiny' => 'https://media.giphy.com/media/111ebonMs90YLu/200.gif', 'full' => 'https://media.giphy.com/media/111ebonMs90YLu/giphy.gif'],
        ['id' => '10', 'title' => 'thanks', 'tiny' => 'https://media.giphy.com/media/3oEdva9BUHPIs2SkGk/200.gif', 'full' => 'https://media.giphy.com/media/3oEdva9BUHPIs2SkGk/giphy.gif'],
        ['id' => '11', 'title' => 'fire', 'tiny' => 'https://media.giphy.com/media/l41lUJ1YoZB1lHVPG/200.gif', 'full' => 'https://media.giphy.com/media/l41lUJ1YoZB1lHVPG/giphy.gif'],
        ['id' => '12', 'title' => 'cool', 'tiny' => 'https://media.giphy.com/media/62PP2yEIAZF6g/200.gif', 'full' => 'https://media.giphy.com/media/62PP2yEIAZF6g/giphy.gif'],
    ];
    
    $results = $presetGifs;
    $source = 'preset';
}

echo json_encode([
    'results' => $results,
    'source' => $source,
    'count' => count($results),
    'query' => $query
]);
