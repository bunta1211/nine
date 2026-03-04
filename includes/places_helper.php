<?php
/**
 * Google Places API ヘルパー
 * 位置情報ベースのレストラン検索機能
 */

/**
 * 近くのレストランを検索
 * @param float $lat 緯度
 * @param float $lng 経度
 * @param string $query 検索キーワード（例: ランチ, カフェ）
 * @param int $radius 検索半径（メートル）
 * @return array 検索結果
 */
function searchNearbyPlaces($lat, $lng, $query = 'レストラン', $radius = 1000) {
    if (!defined('GOOGLE_PLACES_API_KEY') || empty(GOOGLE_PLACES_API_KEY)) {
        return ['success' => false, 'error' => 'Places APIキーが設定されていません'];
    }
    
    $apiKey = GOOGLE_PLACES_API_KEY;
    
    // Text Search APIを使用
    $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
    $params = [
        'query' => $query,
        'location' => "{$lat},{$lng}",
        'radius' => $radius,
        'language' => 'ja',
        'key' => $apiKey
    ];
    
    $fullUrl = $url . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'API接続エラー: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200 || !isset($data['status'])) {
        return ['success' => false, 'error' => 'APIエラー'];
    }
    
    if ($data['status'] !== 'OK' && $data['status'] !== 'ZERO_RESULTS') {
        return ['success' => false, 'error' => 'Places API: ' . $data['status']];
    }
    
    // 結果を整形
    $places = [];
    $results = $data['results'] ?? [];
    
    foreach (array_slice($results, 0, 5) as $place) {
        // 写真URLを生成
        $photoUrl = null;
        if (!empty($place['photos'][0]['photo_reference'])) {
            $photoUrl = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photo_reference=' 
                . $place['photos'][0]['photo_reference'] . '&key=' . $apiKey;
        }
        
        // Googleマップリンクを生成
        $placeId = $place['place_id'] ?? '';
        $mapUrl = $placeId ? "https://www.google.com/maps/place/?q=place_id:{$placeId}" : null;
        
        // 価格レベルを文字に変換
        $priceText = '';
        if (isset($place['price_level'])) {
            $priceText = str_repeat('¥', $place['price_level'] + 1);
        }
        
        $places[] = [
            'name' => $place['name'] ?? '',
            'address' => $place['formatted_address'] ?? '',
            'rating' => $place['rating'] ?? null,
            'user_ratings_total' => (int)($place['user_ratings_total'] ?? 0),
            'price_level' => $place['price_level'] ?? null,
            'price_text' => $priceText,
            'open_now' => $place['opening_hours']['open_now'] ?? null,
            'photo_url' => $photoUrl,
            'map_url' => $mapUrl,
            'place_id' => $placeId,
            'types' => $place['types'] ?? []
        ];
    }
    
    return ['success' => true, 'places' => $places];
}

/**
 * 検索結果をテキスト形式にフォーマット
 */
function formatPlacesForAI($places) {
    if (empty($places)) {
        return "近くにお店が見つかりませんでした。";
    }
    
    $text = "【検索結果】\n";
    foreach ($places as $i => $place) {
        $num = $i + 1;
        $text .= "{$num}. {$place['name']}";
        
        if ($place['rating']) {
            $text .= " (評価: {$place['rating']}";
            if ($place['user_ratings_total']) {
                $text .= "/{$place['user_ratings_total']}件";
            }
            $text .= ")";
        }
        
        if ($place['address']) {
            $text .= "\n   住所: {$place['address']}";
        }
        
        if ($place['open_now'] !== null) {
            $text .= $place['open_now'] ? " [営業中]" : " [営業時間外]";
        }
        
        $text .= "\n";
    }
    
    return $text;
}
