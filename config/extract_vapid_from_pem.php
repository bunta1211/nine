<?php
/**
 * private_key.pem から VAPID キーを抽出
 * 使い方: php config/extract_vapid_from_pem.php
 */
$pemFile = __DIR__ . '/../private_key.pem';
if (!file_exists($pemFile)) {
    die("エラー: private_key.pem が見つかりません。プロジェクトルートに作成してください。\n");
}

$pem = file_get_contents($pemFile);
$key = openssl_pkey_get_private($pem);
if (!$key) {
    die("エラー: 秘密鍵の読み込みに失敗しました。\n");
}

$details = openssl_pkey_get_details($key);
if (!$details || $details['type'] !== OPENSSL_KEYTYPE_EC) {
    die("エラー: EC鍵ではありません。\n");
}

$ec = $details['ec'];

// 公開鍵: 65バイト (04 + x + y) を構築
if (isset($ec['key']) && strlen($ec['key']) === 65) {
    $pubRaw = $ec['key'];
} elseif (isset($ec['x']) && isset($ec['y'])) {
    $x = $ec['x'];
    $y = $ec['y'];
    if (strlen($x) < 32) $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
    if (strlen($y) < 32) $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
    $pubRaw = "\x04" . substr($x, -32) . substr($y, -32);
} else {
    die("エラー: 公開鍵を取得できません。\n");
}

if (strlen($pubRaw) !== 65) {
    die("エラー: 公開鍵の長さが不正です (" . strlen($pubRaw) . " bytes)。\n");
}

// 秘密鍵: 32バイト (d)
$d = $ec['d'] ?? '';
if (strlen($d) !== 32) {
    $d = str_pad($d, 32, "\x00", STR_PAD_LEFT);
}

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$publicKey = base64url($pubRaw);
$privateKey = base64url($d);

echo "\n=== VAPID キー（config/push.php に設定してください）===\n\n";
echo "VAPID_PUBLIC_KEY:  '" . $publicKey . "'\n\n";
echo "VAPID_PRIVATE_KEY: '" . $privateKey . "'\n\n";
echo "=== 以上 ===\n";
echo "\n※ private_key.pem は安全な場所に保管するか削除してください。\n";
