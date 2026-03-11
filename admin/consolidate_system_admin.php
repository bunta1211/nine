<?php
/**
 * システム管理者アカウント統合スクリプト（1回限り実行）
 *
 * admin@social9.jp の役割を saitanibunta@social9.jp（才谷文太）に統合する。
 * - 表示名「システム管理者」、本名「才谷文太」、パスワード cloverkids456
 * - 統合後は admin@social9.jp を無効化（status=deleted）
 *
 * 実行: ブラウザで /admin/consolidate_system_admin.php にアクセス（システム管理者でログイン済み）
 * または: php -f admin/consolidate_system_admin.php（CLI）
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

if (php_sapi_name() !== 'cli') {
    session_start();
    require_once __DIR__ . '/../includes/auth.php';
    if (!isLoggedIn() || !isOrgAdminUser()) {
        header('Location: ../index.php');
        exit;
    }
}

$pdo = getDB();

const TARGET_EMAIL = 'saitanibunta@social9.jp';
const ADMIN_EMAIL = 'admin@social9.jp';
const DISPLAY_NAME = 'システム管理者';
const FULL_NAME = '才谷文太';
const NEW_PASSWORD = 'cloverkids456';

$messages = [];
$errors = [];

try {
    $adminId = null;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status != 'deleted'");
    $stmt->execute([ADMIN_EMAIL]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $adminId = (int) $row['id'];
    }

    $targetId = null;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([TARGET_EMAIL]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $targetId = (int) $row['id'];
    }

    if ($targetId === null) {
        $hash = password_hash(NEW_PASSWORD, PASSWORD_DEFAULT);
        $chkFullName = $pdo->query("SHOW COLUMNS FROM users LIKE 'full_name'");
        $hasFullName = $chkFullName && $chkFullName->rowCount() > 0;
        if ($hasFullName) {
            $pdo->prepare("
                INSERT INTO users (email, password_hash, display_name, full_name, role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'system_admin', 'active', NOW(), NOW())
            ")->execute([TARGET_EMAIL, $hash, DISPLAY_NAME, FULL_NAME]);
        } else {
            $pdo->prepare("
                INSERT INTO users (email, password_hash, display_name, role, status, created_at, updated_at)
                VALUES (?, ?, ?, 'system_admin', 'active', NOW(), NOW())
            ")->execute([TARGET_EMAIL, $hash, DISPLAY_NAME]);
        }
        $targetId = (int) $pdo->lastInsertId();
        $messages[] = "ユーザー " . TARGET_EMAIL . " を新規作成しました（ID: {$targetId}）。";
    } else {
        $hash = password_hash(NEW_PASSWORD, PASSWORD_DEFAULT);
        $chkFullName = $pdo->query("SHOW COLUMNS FROM users LIKE 'full_name'");
        $hasFullName = $chkFullName && $chkFullName->rowCount() > 0;
        if ($hasFullName) {
            $pdo->prepare("
                UPDATE users SET password_hash = ?, display_name = ?, full_name = ?, role = 'system_admin', status = 'active', updated_at = NOW()
                WHERE id = ?
            ")->execute([$hash, DISPLAY_NAME, FULL_NAME, $targetId]);
        } else {
            $pdo->prepare("
                UPDATE users SET password_hash = ?, display_name = ?, role = 'system_admin', status = 'active', updated_at = NOW()
                WHERE id = ?
            ")->execute([$hash, DISPLAY_NAME, $targetId]);
        }
        $messages[] = "ユーザー " . TARGET_EMAIL . "（ID: {$targetId}）をシステム管理者に更新しました。";
    }

    if ($adminId !== null && $adminId !== $targetId) {
        $tables = [
            'organization_members' => 'user_id',
            'conversation_members' => 'user_id',
        ];
        foreach ($tables as $table => $col) {
            try {
                $chk = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
                if (!$chk || $chk->rowCount() === 0) continue;
                $pk = $table === 'organization_members' ? 'organization_id' : 'conversation_id';
                $otherCol = $table === 'organization_members' ? 'organization_id' : 'conversation_id';
                $stmt = $pdo->prepare("SELECT {$otherCol} FROM {$table} WHERE {$col} = ?");
                $stmt->execute([$adminId]);
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $otherId) {
                    $dup = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$otherCol} = ? AND {$col} = ?");
                    $dup->execute([$otherId, $targetId]);
                    if ($dup->fetch()) {
                        $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ? AND {$otherCol} = ?")->execute([$adminId, $otherId]);
                    } else {
                        $pdo->prepare("UPDATE {$table} SET {$col} = ? WHERE {$col} = ? AND {$otherCol} = ?")->execute([$targetId, $adminId, $otherId]);
                    }
                }
                $messages[] = "{$table} の参照を admin から統合アカウントに移行しました。";
            } catch (PDOException $e) {
                $errors[] = "{$table}: " . $e->getMessage();
            }
        }
        $pdo->prepare("UPDATE users SET status = 'deleted', email = CONCAT('_deleted_', id, '_', email), updated_at = NOW() WHERE id = ?")->execute([$adminId]);
        $messages[] = "admin@social9.jp（ID: {$adminId}）を無効化しました。";
    }

} catch (PDOException $e) {
    $errors[] = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    foreach ($messages as $m) echo "OK: {$m}\n";
    foreach ($errors as $e) echo "ERROR: {$e}\n";
    exit(empty($errors) ? 0 : 1);
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>システム管理者アカウント統合</title></head>
<body>
<h1>システム管理者アカウント統合</h1>
<?php foreach ($messages as $m): ?>
<p style="color:green;"><?= htmlspecialchars($m) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<p style="color:red;"><?= htmlspecialchars($e) ?></p>
<?php endforeach; ?>
<p>統合後ログイン: <strong><?= htmlspecialchars(TARGET_EMAIL) ?></strong> / パスワード: <strong><?= htmlspecialchars(NEW_PASSWORD) ?></strong><br>
表示名: <?= htmlspecialchars(DISPLAY_NAME) ?>、本名: <?= htmlspecialchars(FULL_NAME) ?></p>
<p><a href="../index.php">ログイン画面へ</a> | <a href="index.php">ダッシュボードへ</a></p>
</body>
</html>
