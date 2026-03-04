<?php
/**
 * お店検索API（Google Places API連携）
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/api-helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'search':
        // お店を検索
        $lat = floatval($_GET['lat'] ?? 0);
        $lng = floatval($_GET['lng'] ?? 0);
        $query = trim($_GET['query'] ?? 'レストラン');
        $radius = intval($_GET['radius'] ?? 1000); // デフォルト1km
        
        if ($lat == 0 || $lng == 0) {
            errorResponse('位置情報が必要です');
        }
        
        $results = searchNearbyPlaces($lat, $lng, $query, $radius);
        successResponse(['places' => $results]);
        break;
        
    case 'geocode':
        // 住所から座標を取得
        $address = trim($_GET['address'] ?? '');
        if (empty($address)) {
            errorResponse('住所が必要です');
        }
        
        $coords = geocodeAddress($address);
        if ($coords) {
            successResponse($coords);
        } else {
            errorResponse('住所が見つかりませんでした');
        }
        break;
        
    default:
        errorResponse('不明なアクションです');
}

/**
 * 近くのお店を検索
 */
function searchNearbyPlaces($lat, $lng, $query, $radius = 1000) {
    $apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
    
    // APIキーがない場合はダミーデータを返す
    if (empty($apiKey)) {
        return getDefaultPlaceSuggestions($query);
    }
    
    // Google Places API (New) - Nearby Search
    $url = 'https://places.googleapis.com/v1/places:searchNearby';
    
    $requestBody = [
        'includedTypes' => getPlaceTypes($query),
        'maxResultCount' => 5,
        'locationRestriction' => [
            'circle' => [
                'center' => [
                    'latitude' => $lat,
                    'longitude' => $lng
                ],
                'radius' => $radius
            ]
        ],
        'languageCode' => 'ja'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.priceLevel,places.types,places.googleMapsUri'
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Places API error: HTTP $httpCode - $response");
        return getDefaultPlaceSuggestions($query);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['places']) || empty($data['places'])) {
        return getDefaultPlaceSuggestions($query);
    }
    
    $results = [];
    foreach ($data['places'] as $place) {
        $results[] = [
            'name' => $place['displayName']['text'] ?? '不明',
            'address' => $place['formattedAddress'] ?? '',
            'rating' => $place['rating'] ?? null,
            'reviews' => $place['userRatingCount'] ?? 0,
            'price_level' => getPriceLevelText($place['priceLevel'] ?? null),
            'maps_url' => $place['googleMapsUri'] ?? ''
        ];
    }
    
    return $results;
}

/**
 * 検索クエリからPlace Typesを取得
 */
function getPlaceTypes($query) {
    $query = mb_strtolower($query);
    
    if (strpos($query, 'ラーメン') !== false) {
        return ['ramen_restaurant'];
    }
    if (strpos($query, '寿司') !== false || strpos($query, 'すし') !== false) {
        return ['sushi_restaurant'];
    }
    if (strpos($query, 'カフェ') !== false || strpos($query, 'コーヒー') !== false) {
        return ['cafe', 'coffee_shop'];
    }
    if (strpos($query, 'ランチ') !== false || strpos($query, '昼食') !== false) {
        return ['restaurant'];
    }
    if (strpos($query, '居酒屋') !== false) {
        return ['izakaya'];
    }
    if (strpos($query, 'ファミレス') !== false) {
        return ['family_restaurant'];
    }
    if (strpos($query, 'パン') !== false || strpos($query, 'ベーカリー') !== false) {
        return ['bakery'];
    }
    if (strpos($query, '美容') !== false || strpos($query, 'ヘア') !== false) {
        return ['beauty_salon', 'hair_salon'];
    }
    if (strpos($query, '病院') !== false || strpos($query, 'クリニック') !== false) {
        return ['hospital', 'doctor'];
    }
    if (strpos($query, '薬局') !== false || strpos($query, 'ドラッグ') !== false) {
        return ['pharmacy', 'drugstore'];
    }
    if (strpos($query, 'コンビニ') !== false) {
        return ['convenience_store'];
    }
    if (strpos($query, 'スーパー') !== false) {
        return ['supermarket'];
    }
    
    // デフォルト
    return ['restaurant'];
}

/**
 * 価格レベルをテキストに変換
 */
function getPriceLevelText($level) {
    switch ($level) {
        case 'PRICE_LEVEL_FREE': return '無料';
        case 'PRICE_LEVEL_INEXPENSIVE': return '¥';
        case 'PRICE_LEVEL_MODERATE': return '¥¥';
        case 'PRICE_LEVEL_EXPENSIVE': return '¥¥¥';
        case 'PRICE_LEVEL_VERY_EXPENSIVE': return '¥¥¥¥';
        default: return null;
    }
}

/**
 * APIキーがない場合のデフォルト提案
 */
function getDefaultPlaceSuggestions($query) {
    return [
        [
            'name' => '（位置情報検索には Google Places API キーが必要です）',
            'address' => '',
            'rating' => null,
            'reviews' => 0,
            'price_level' => null,
            'maps_url' => ''
        ]
    ];
}

/**
 * 住所から座標を取得（Geocoding）
 */
function geocodeAddress($address) {
    $apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
    
    if (empty($apiKey)) {
        return null;
    }
    
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address' => $address,
        'key' => $apiKey,
        'language' => 'ja'
    ]);
    
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    
    if ($data['status'] === 'OK' && !empty($data['results'])) {
        $location = $data['results'][0]['geometry']['location'];
        return [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'formatted_address' => $data['results'][0]['formatted_address']
        ];
    }
    
    return null;
}
