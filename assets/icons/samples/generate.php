<?php
/**
 * サンプルアイコン生成スクリプト
 * SVG形式で透明背景のアイコンを41種類生成
 */

$icons = [
    // 動物（5種）
    ['emoji' => '🐧', 'name' => 'penguin'],
    ['emoji' => '🦋', 'name' => 'butterfly'],
    ['emoji' => '🐬', 'name' => 'dolphin'],
    ['emoji' => '🦄', 'name' => 'unicorn'],
    ['emoji' => '🐝', 'name' => 'bee'],
    
    // 自然（3種）
    ['emoji' => '🌵', 'name' => 'cactus'],
    ['emoji' => '🍂', 'name' => 'leaf'],
    ['emoji' => '❄️', 'name' => 'snow'],
    
    // 食べ物（3種）
    ['emoji' => '🍕', 'name' => 'pizza'],
    ['emoji' => '🍩', 'name' => 'donut'],
    ['emoji' => '🍦', 'name' => 'icecream'],
    
    // 乗り物（3種）
    ['emoji' => '🚀', 'name' => 'rocket'],
    ['emoji' => '✈️', 'name' => 'airplane'],
    ['emoji' => '🚗', 'name' => 'car'],
    
    // その他（6種）
    ['emoji' => '🏠', 'name' => 'house'],
    ['emoji' => '🎁', 'name' => 'gift'],
    ['emoji' => '🔔', 'name' => 'bell'],
    ['emoji' => '🎯', 'name' => 'target'],
    ['emoji' => '🏆', 'name' => 'trophy'],
    ['emoji' => '👑', 'name' => 'crown'],
    
    // 仕事・ビジネス（15種）
    ['emoji' => '💼', 'name' => 'briefcase'],
    ['emoji' => '📊', 'name' => 'chart'],
    ['emoji' => '📈', 'name' => 'growth'],
    ['emoji' => '💻', 'name' => 'laptop'],
    ['emoji' => '📁', 'name' => 'folder'],
    ['emoji' => '📋', 'name' => 'clipboard'],
    ['emoji' => '✏️', 'name' => 'pencil'],
    ['emoji' => '📝', 'name' => 'memo'],
    ['emoji' => '📌', 'name' => 'pin'],
    ['emoji' => '⚙️', 'name' => 'gear'],
    ['emoji' => '🔑', 'name' => 'key'],
    ['emoji' => '📧', 'name' => 'email'],
    ['emoji' => '📞', 'name' => 'phone'],
    ['emoji' => '🏢', 'name' => 'building'],
    ['emoji' => '💡', 'name' => 'idea'],
    
    // 特別リクエスト（3種）
    ['emoji' => '♟️', 'name' => 'shogi'],
    ['emoji' => '🌍', 'name' => 'earth'],
    ['emoji' => '👤', 'name' => 'person'],
    
    // 追加（3種）
    ['emoji' => '📅', 'name' => 'calendar'],
    ['emoji' => '⏰', 'name' => 'clock'],
    ['emoji' => '🔒', 'name' => 'lock'],
];

// SVGテンプレート（透明背景・大きな絵文字）
function generateSvg($emoji) {
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
    <text x="100" y="100" text-anchor="middle" dominant-baseline="middle" font-size="160">{$emoji}</text>
</svg>
SVG;
    return $svg;
}

// ファイル生成
foreach ($icons as $icon) {
    $svg = generateSvg($icon['emoji']);
    file_put_contents(__DIR__ . '/' . $icon['name'] . '.svg', $svg);
}

echo "Generated " . count($icons) . " sample icons.\n";

// アイコンリストをJSONで保存
$iconList = array_map(function($icon) {
    return [
        'name' => $icon['name'],
        'file' => $icon['name'] . '.svg',
        'emoji' => $icon['emoji']
    ];
}, $icons);

file_put_contents(__DIR__ . '/icons.json', json_encode($iconList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Generated icons.json\n";
