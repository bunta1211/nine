<?php
/**
 * PWA／携帯アプリ登録用アイコン生成
 *
 * 【推奨】独自ロゴ画像を使う場合:
 * 1. ロゴ画像を assets/icons/logo-source.png に配置する（PNG または JPG、正方形推奨）
 * 2. ブラウザでこのファイルにアクセスする（例: https://example.com/assets/icons/generate-icons.php）
 * 3. 各サイズのアイコンが生成され、ホーム画面追加時にこのロゴが使われます
 *
 * logo-source.png が無い場合は、緑背景＋白い「９」のプログラム描画で生成します。
 *
 * 生成されるアイコン: 72, 96, 128, 144, 152, 192, 384, 512, apple-touch 180, favicon 32
 */

if (!function_exists('imagecreatetruecolor')) {
    echo "<h2>エラー: GDライブラリが有効ではありません</h2>";
    echo "<p>php.iniで extension=gd を有効にしてください。</p>";
    exit;
}

$dir = __DIR__;
$sourcePath = null;
foreach (['logo-source.png', 'logo-source.jpg', 'logo-source.jpeg', 'social9-logo.png', 'social9-logo.jpg'] as $name) {
    if (file_exists($dir . '/' . $name)) {
        $sourcePath = $dir . '/' . $name;
        break;
    }
}

$allSizes = [72, 96, 128, 144, 152, 192, 384, 512];
$appleSize = 180;
$faviconSize = 32;

if ($sourcePath !== null) {
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $src = ($ext === 'png') ? @imagecreatefrompng($sourcePath) : @imagecreatefromjpeg($sourcePath);
    if (!$src) {
        echo "<p>ソース画像を開けませんでした: " . htmlspecialchars(basename($sourcePath)) . "</p>";
        $sourcePath = null;
    }
}

if ($sourcePath !== null && isset($src)) {
    // 添付ロゴから各サイズをリサイズして生成
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        echo "<p>ソース画像のサイズが不正です。</p>";
        $sourcePath = null;
    }
}

if ($sourcePath !== null && isset($src)) {
    $resizeAndSave = function ($size, $filename) use ($src, $srcW, $srcH, $dir) {
        $out = imagecreatetruecolor($size, $size);
        if (!$out) return false;
        imagecopyresampled($out, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);
        $path = $dir . '/' . $filename;
        $ok = imagepng($out, $path);
        imagedestroy($out);
        return $ok;
    };

    $saved = 0;
    foreach ($allSizes as $size) {
        if ($resizeAndSave($size, "icon-{$size}x{$size}.png")) {
            echo "Generated: icon-{$size}x{$size}.png<br>";
            $saved++;
        } else {
            echo "<span style='color:red'>失敗: icon-{$size}x{$size}.png（書き込み権限を確認してください）</span><br>";
        }
    }
    if ($resizeAndSave($appleSize, 'apple-touch-icon.png')) {
        echo "Generated: apple-touch-icon.png<br>";
        $saved++;
    } else {
        echo "<span style='color:red'>失敗: apple-touch-icon.png</span><br>";
    }
    if ($resizeAndSave($faviconSize, 'favicon-32x32.png')) {
        echo "Generated: favicon-32x32.png<br>";
        $faviconRoot = $dir . '/../../favicon.ico';
        @copy($dir . '/favicon-32x32.png', $faviconRoot);
        echo "Generated: favicon.ico (root)<br>";
        $saved++;
    } else {
        echo "<span style='color:red'>失敗: favicon-32x32.png</span><br>";
    }
    imagedestroy($src);

    $checkPath = $dir . '/icon-192x192.png';
    $written = file_exists($checkPath);
    $mtime = $written ? date('Y-m-d H:i:s', filemtime($checkPath)) : '-';
    $size = $written ? (string)filesize($checkPath) . ' bytes' : '-';
    echo "<br><strong>アイコン生成" . ($saved > 0 ? "完了" : "（書き込みに失敗している可能性あり）") . "（ロゴ画像からリサイズ）</strong>";
    echo "<br>確認: icon-192x192.png … 更新日時=" . $mtime . ", サイズ=" . $size;
    if (!$written || $saved === 0) {
        echo "<br><strong style='color:red'>ブラウザから実行すると書き込み権限で失敗することがあります。SSHで以下を実行してください:</strong>";
        echo "<br><code>cd /var/www/html && php assets/icons/generate-icons.php</code>";
    }
    echo "<br><br><a href='../../chat.php'>チャットに戻る</a>";
    exit;
}

// ========== フォールバック: プログラム描画「９」（logo-source が無い場合） ==========
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$bgColor = [45, 74, 45];   // #2d4a2d
$white = [255, 255, 255];

// TTFフォントがあれば「９」を描画、なければ "9" を imagestring で
$fontPath = null;
foreach (['C:/Windows/Fonts/meiryo.ttc', 'C:/Windows/Fonts/msgothic.ttc', 'C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'] as $p) {
    if (file_exists($p)) {
        $fontPath = $p;
        break;
    }
}
$useTtf = $fontPath && function_exists('imagettftext');
$char = $useTtf ? '９' : '9';

$drawNine = function ($img, $size, $whiteColor) use ($fontPath, $useTtf, $char) {
    $cx = $size / 2;
    $cy = $size * 0.52;
    if ($useTtf && $fontPath) {
        $fontSize = (int)max(12, $size * 0.52);
        $angle = 0;
        $bbox = @imagettfbbox($fontSize, $angle, $fontPath, $char);
        if ($bbox !== false && isset($bbox[0], $bbox[1], $bbox[4], $bbox[5])) {
            $w = abs($bbox[4] - $bbox[0]);
            $baselineY = $cy - ($bbox[1] + $bbox[5]) / 2;
            $x = (int)($cx - $w / 2);
            $y = (int)round($baselineY);
            imagettftext($img, $fontSize, $angle, $x, $y, $whiteColor, $fontPath, $char);
        }
    } else {
        $fontId = 5;
        $fw = imagefontwidth($fontId);
        $fh = imagefontheight($fontId);
        $textW = $fw * strlen($char);
        $x = (int)($cx - $textW / 2);
        $y = (int)($cy - $fh / 2);
        imagestring($img, $fontId, $x, $y, $char, $whiteColor);
    }
};

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
    $whiteColor = imagecolorallocate($img, $white[0], $white[1], $white[2]);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);
    $drawNine($img, $size, $whiteColor);
    imagepng($img, $dir . "/icon-{$size}x{$size}.png");
    imagedestroy($img);
    echo "Generated: icon-{$size}x{$size}.png<br>";
}

$appleSize = 180;
$img = imagecreatetruecolor($appleSize, $appleSize);
$bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
$whiteColor = imagecolorallocate($img, $white[0], $white[1], $white[2]);
imagefilledrectangle($img, 0, 0, $appleSize, $appleSize, $bg);
$drawNine($img, $appleSize, $whiteColor);
imagepng($img, $dir . "/apple-touch-icon.png");
imagedestroy($img);
echo "Generated: apple-touch-icon.png<br>";

$faviconSize = 32;
$img = imagecreatetruecolor($faviconSize, $faviconSize);
$bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
$whiteColor = imagecolorallocate($img, $white[0], $white[1], $white[2]);
imagefilledrectangle($img, 0, 0, $faviconSize, $faviconSize, $bg);
$drawNine($img, $faviconSize, $whiteColor);
imagepng($img, $dir . "/favicon-32x32.png");
$faviconPath = $dir . "/../../favicon.ico";
@copy($dir . "/favicon-32x32.png", $faviconPath);
imagedestroy($img);
echo "Generated: favicon-32x32.png<br>";
echo "Generated: favicon.ico (root)<br>";

echo "<br><strong>アイコン生成完了（「９」プログラム描画）</strong>";
echo "<br><p>独自ロゴを使う場合は <strong>assets/icons/logo-source.png</strong> に画像を配置してから再実行してください。</p>";
echo "<br><a href='../../chat.php'>チャットに戻る</a>";
