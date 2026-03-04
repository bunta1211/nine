<?php
/**
 * 特定ユーザーのログイン可否を診断（管理者用）
 * 例: /admin/debug_user_login.php?email=clover.shibatakyoko@gmail.com&password=clover123
 */
session_start();
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '管理者ログインが必要です'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$email = trim($_GET['email'] ?? '');
$testPassword = $_GET['password'] ?? '';

header('Content-Type: application/json; charset=utf-8');

if ($email === '') {
    echo json_encode([
        'error' => 'email パラメータを指定してください',
        'example' => '?email=clover.shibatakyoko@gmail.com&password=clover123'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$pdo = getDB();

// メールでユーザー取得（status 条件なしで取得し、診断に使う）
$stmt = $pdo->prepare("
    SELECT id, email, display_name, full_name, status, 
           CASE WHEN password_hash IS NULL OR password_hash = '' THEN 0 ELSE 1 END as has_password,
           LEFT(password_hash, 7) as hash_prefix
    FROM users 
    WHERE email = ?
");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode([
        'found' => false,
        'email' => $email,
        'message' => 'このメールアドレスのユーザーは存在しません。',
        'suggestion' => 'メールアドレスのスペル・大文字小文字を確認してください。'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ログイン可否の判定に必要な情報を取得（password_hash は検証のみに使用しレスポンスには含めない）
$stmt2 = $pdo->prepare("SELECT id, status, password_hash FROM users WHERE id = ?");
$stmt2->execute([$user['id']]);
$row = $stmt2->fetch(PDO::FETCH_ASSOC);

$status = $row['status'] ?? null;
$passwordHash = $row['password_hash'] ?? '';
$isActive = ($status === 'active');
$hasPassword = ($passwordHash !== '' && $passwordHash !== null);

$passwordVerifyOk = false;
if ($hasPassword && $testPassword !== '') {
    $passwordVerifyOk = password_verify($testPassword, $passwordHash);
}

$reasons = [];
if (!$isActive) {
    $reasons[] = "status が 'active' ではありません（現在: " . ($status === null ? 'NULL' : $status) . "）。ログインは status='active' のユーザーのみ可能です。";
}
if (!$hasPassword) {
    $reasons[] = "パスワードが設定されていません（password_hash が空）。";
} elseif ($testPassword !== '' && !$passwordVerifyOk) {
    $reasons[] = "指定したパスワードが一致しません。DB のパスワードをリセットする必要があります。";
}

$canLogin = $isActive && $hasPassword && ($testPassword === '' || $passwordVerifyOk);

echo json_encode([
    'found' => true,
    'user_id' => (int)$user['id'],
    'email' => $user['email'],
    'display_name' => $user['display_name'],
    'full_name' => $user['full_name'] ?? null,
    'status' => $status,
    'has_password' => $hasPassword,
    'hash_prefix' => $user['hash_prefix'],
    'password_verify_tested' => $testPassword !== '',
    'password_verify_ok' => $testPassword !== '' ? $passwordVerifyOk : null,
    'can_login' => $canLogin,
    'reasons' => $reasons,
    'suggestions' => array_values(array_filter([
        !$isActive ? "UPDATE users SET status = 'active' WHERE id = " . (int)$user['id'] . ";" : null,
        (!$hasPassword || ($testPassword !== '' && !$passwordVerifyOk)) ? '管理画面のメンバー編集でパスワードを設定するか、admin/set_test_passwords.php に該当ユーザーを追加して実行してください。' : null,
    ])),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
