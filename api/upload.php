<?php
/**
 * ファイルアップロードAPI
 * 仕様書: 12_ファイル共有.md
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// ユーザー情報を取得（組織かどうか）
$stmt = $pdo->prepare("SELECT role, organization_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$is_org = !empty($user['organization_id']);
$max_file_size = $is_org ? MAX_FILE_SIZE_ORGANIZATION : MAX_FILE_SIZE_GENERAL;
$max_total_storage = $is_org ? (10 * 1024 * 1024 * 1024) : (1 * 1024 * 1024 * 1024);

// 現在の使用量を取得（filesテーブルがない場合は0）
$current_usage = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) as total FROM files WHERE uploaded_by = ?");
    $stmt->execute([$user_id]);
    $current_usage = (int)$stmt->fetch()['total'];
} catch (PDOException $e) {
    // filesテーブルが存在しない場合は無視
    error_log("files table may not exist: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // 使用量を取得
    successResponse([
        'current_usage' => $current_usage,
        'max_storage' => $max_total_storage,
        'max_file_size' => $max_file_size,
        'is_organization' => $is_org
    ]);
}

if ($method !== 'POST') {
    errorResponse('POSTメソッドのみ対応しています', 405);
}

// ファイルがアップロードされているか確認
if (!isset($_FILES['file'])) {
    $content_len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $hint = ($content_len > 10 * 1024 * 1024)
        ? 'ファイルが大きすぎます（10MB以下にしてください）。サーバー設定によりこれ以上は受け付けられません。'
        : 'ファイルが送信されませんでした。';
    errorResponse($hint, 400);
}
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $error_messages = [
        UPLOAD_ERR_INI_SIZE => 'ファイルが大きすぎます（10MB以下にしてください）',
        UPLOAD_ERR_FORM_SIZE => 'ファイルが大きすぎます',
        UPLOAD_ERR_PARTIAL => 'ファイルが部分的にしかアップロードされませんでした。再度お試しください。',
        UPLOAD_ERR_NO_FILE => 'ファイルがアップロードされませんでした',
        UPLOAD_ERR_NO_TMP_DIR => 'サーバーエラー（一時ディレクトリ不足）',
        UPLOAD_ERR_CANT_WRITE => 'サーバーエラー（書き込み失敗）',
        UPLOAD_ERR_EXTENSION => 'サーバーエラー（拡張機能）'
    ];
    $error_code = $_FILES['file']['error'];
    errorResponse($error_messages[$error_code] ?? 'アップロードエラー', 400);
}

$file = $_FILES['file'];
$original_name = $file['name'];
$file_size = $file['size'];
$tmp_path = $file['tmp_name'];
$mime_type = @mime_content_type($tmp_path) ?: ($file['type'] ?? '');
$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
// HEIC/heif: mime_content_typeが未対応の場合のフォールバック
if (empty($mime_type) && in_array($extension, ['heic', 'heif'])) {
    $mime_type = $extension === 'heif' ? 'image/heif' : 'image/heic';
}

// ファイルサイズチェック
if ($file_size > $max_file_size) {
    $limit_mb = $max_file_size / 1024 / 1024;
    errorResponse("ファイルサイズが制限({$limit_mb}MB)を超えています");
}

// 総容量チェック
if ($current_usage + $file_size > $max_total_storage) {
    $limit_gb = $max_total_storage / 1024 / 1024 / 1024;
    errorResponse("ストレージ容量({$limit_gb}GB)を超えています。不要なファイルを削除するか、容量追加をご検討ください。");
}

// 危険なファイル拡張子をチェック
$dangerous_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bash', 'cgi'];
if (in_array($extension, $dangerous_extensions)) {
    errorResponse('このファイル形式はアップロードできません');
}

// 許可する拡張子（PDF・Office等はブラウザが application/octet-stream を送ることがあるため拡張子でも許可）
$allowed_extensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
    'mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'zip', 'rar', '7z',
    'mp3', 'wav', 'ogg', 'm4a', 'webm'
];

// MIMEタイプ許可リスト（PDF・Officeは複数的なMIMEに対応）
$allowed_types = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
    'application/pdf',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation',
    'text/plain', 'text/csv',
    'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
    'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm', 'audio/mp4', 'audio/x-m4a',
    'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'
];

$mime_allowed = in_array($mime_type, $allowed_types);
$ext_allowed = in_array($extension, $allowed_extensions);
// 拡張子が許可リストにあれば許可（ブラウザが octet-stream を送る場合に対応）。そうでなければ MIME で判定
if (!$ext_allowed && !$mime_allowed) {
    errorResponse('このファイル形式はアップロードできません: ' . $extension . ' (' . $mime_type . ')');
}
// application/octet-stream の場合は拡張子が許可リストにある場合のみ許可（任意のバイナリを防ぐ）
if ($mime_type === 'application/octet-stream' && !$ext_allowed) {
    errorResponse('このファイル形式はアップロードできません: ' . $extension);
}

// ファイル種別を判定
$file_type = 'file';
if (strpos($mime_type, 'image/') === 0) {
    $file_type = 'image';
} elseif (strpos($mime_type, 'audio/') === 0) {
    $file_type = 'audio';
} elseif (strpos($mime_type, 'video/') === 0) {
    $file_type = 'video';
}

// 保存先ディレクトリ（uploads/ と uploads/Y/m/ を確保）
$base_upload = rtrim(UPLOAD_DIR, '/\\');
if (!is_dir($base_upload)) {
    if (!@mkdir($base_upload, 0755, true)) {
        error_log("Failed to create upload base directory: $base_upload");
        errorResponse('アップロード用フォルダを作成できませんでした。サーバー管理者に権限を確認してください。');
    }
}
$upload_dir = $base_upload . '/' . date('Y/m/');
if (!is_dir($upload_dir)) {
    if (!@mkdir($upload_dir, 0755, true)) {
        error_log("Failed to create upload directory: $upload_dir");
        errorResponse('アップロードディレクトリの作成に失敗しました。サーバー管理者に権限を確認してください。');
    }
}

// ディレクトリが書き込み可能か確認
if (!is_writable($upload_dir)) {
    error_log("Upload directory is not writable: $upload_dir");
    errorResponse('アップロードディレクトリに書き込み権限がありません。サーバー管理者に uploads/ の権限を確認してください。');
}

// ユニークなファイル名を生成
$new_filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
$file_path = $upload_dir . $new_filename;
$relative_path = 'uploads/' . date('Y/m/') . $new_filename;

// ファイルを移動
if (!move_uploaded_file($tmp_path, $file_path)) {
    error_log("move_uploaded_file failed. tmp=$tmp_path dest=$file_path writable=" . (is_writable($upload_dir) ? 'y' : 'n'));
    errorResponse('ファイルの保存に失敗しました。uploads/ の書き込み権限を確認するか、サーバー管理者に問い合わせてください。');
}

// サムネイル生成（画像の場合）
$thumbnail_path = null;
if ($file_type === 'image') {
    $thumbnail_path = createThumbnail($file_path, $upload_dir, $new_filename);
}

// データベースに登録
try {
    $stmt = $pdo->prepare("
        INSERT INTO files (
            uploaded_by, original_name, stored_name, file_path, thumbnail_path,
            file_size, mime_type, file_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user_id,
        $original_name,
        $new_filename,
        $relative_path,
        $thumbnail_path,
        $file_size,
        $mime_type,
        $file_type
    ]);
    $file_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    error_log("Database error during file upload: " . $e->getMessage());
    // filesテーブルがない場合でもファイルパスを返す
    successResponse([
        'file_id' => 0,
        'file_path' => $relative_path,
        'path' => $relative_path,
        'thumbnail_path' => $thumbnail_path,
        'file_type' => $file_type,
        'file_size' => $file_size,
        'original_name' => $original_name
    ]);
    exit;
}

successResponse([
    'file_id' => $file_id,
    'file_path' => $relative_path,
    'path' => $relative_path,  // 互換性のため両方返す
    'thumbnail_path' => $thumbnail_path,
    'file_type' => $file_type,
    'file_size' => $file_size,
    'original_name' => $original_name
]);

/**
 * サムネイル生成
 */
function createThumbnail($source_path, $upload_dir, $filename) {
    $thumb_dir = $upload_dir . 'thumbs/';
    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }
    
    $thumb_path = $thumb_dir . 'thumb_' . $filename;
    $relative_thumb_path = 'uploads/' . date('Y/m/') . 'thumbs/thumb_' . $filename;
    
    try {
        list($width, $height, $type) = getimagesize($source_path);
        
        $max_dim = 200;
        if ($width > $height) {
            $new_width = $max_dim;
            $new_height = (int)($height * ($max_dim / $width));
        } else {
            $new_height = $max_dim;
            $new_width = (int)($width * ($max_dim / $height));
        }
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($source_path);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($source_path);
                break;
            default:
                return null;
        }
        
        $thumb = imagecreatetruecolor($new_width, $new_height);
        
        // 透過処理
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $new_width, $new_height, $transparent);
        }
        
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        // 保存
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($thumb, $thumb_path, 80);
                break;
            case IMAGETYPE_PNG:
                imagepng($thumb, $thumb_path);
                break;
            case IMAGETYPE_GIF:
                imagegif($thumb, $thumb_path);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($thumb, $thumb_path, 80);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($thumb);
        
        return $relative_thumb_path;
    } catch (Exception $e) {
        error_log('Thumbnail creation failed: ' . $e->getMessage());
        return null;
    }
}








