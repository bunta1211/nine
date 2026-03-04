<?php
/**
 * PWA manifest を出力（アイコンURLにキャッシュバスター付与）
 * アイコン差し替え後に「ホームに追加」で新しいロゴが反映されるようにする
 */
$manifestPath = __DIR__ . '/manifest.json';
$iconPath = __DIR__ . '/assets/icons/icon-192x192.png';
$iconVersion = file_exists($iconPath) ? (string)filemtime($iconPath) : '1';

$json = file_get_contents($manifestPath);
$data = json_decode($json, true);
if (!$data) {
    header('Content-Type: application/json');
    echo $json;
    exit;
}

foreach ($data['icons'] ?? [] as $i => $icon) {
    if (isset($data['icons'][$i]['src']) && strpos($data['icons'][$i]['src'], '?') === false) {
        $data['icons'][$i]['src'] .= '?v=' . $iconVersion;
    }
}
foreach ($data['shortcuts'] ?? [] as $s => $shortcut) {
    foreach ($shortcut['icons'] ?? [] as $i => $icon) {
        if (isset($data['shortcuts'][$s]['icons'][$i]['src']) && strpos($data['shortcuts'][$s]['icons'][$i]['src'], '?') === false) {
            $data['shortcuts'][$s]['icons'][$i]['src'] .= '?v=' . $iconVersion;
        }
    }
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo json_encode($data, JSON_UNESCAPED_UNICODE);
