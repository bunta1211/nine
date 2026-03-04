<?php
/**
 * 金庫 API（unlock=ログインパスワードで開錠 / list / get / create / update / delete）
 * unlock 以外は X-Vault-Token ヘッダが有効である必要がある
 */
require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$input = getJsonInput() ?: $_POST ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// ログインパスワードで金庫を開く（トークン不要）
if ($action === 'unlock') {
    $password = $input['password'] ?? '';
    if ($password === '') {
        errorResponse('パスワードを入力してください。', 400);
    }
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
        errorResponse('パスワードが正しくありません。', 400);
    }
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    $pdo->prepare("INSERT INTO vault_sessions (user_id, token, expires_at) VALUES (?, ?, ?)")->execute([$user_id, $token, $expiresAt]);
    successResponse(['vault_token' => $token, 'expires_at' => $expiresAt, 'expires_in' => 300]);
    exit;
}

/** 金庫トークンを検証し、user_id を返す。無効なら null */
function getVaultUserId(PDO $pdo): ?int {
    $token = $_SERVER['HTTP_X_VAULT_TOKEN'] ?? '';
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT user_id FROM vault_sessions WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['user_id'] : null;
}

$vault_user_id = getVaultUserId($pdo);
if ($vault_user_id === null || $vault_user_id !== $user_id) {
    errorResponse('金庫がロックされています。ログインパスワードで開いてください。', 401);
}

require_once __DIR__ . '/../includes/VaultCrypto.php';

try {
    switch ($action) {
        case 'list': {
            $type = isset($_GET['type']) ? $_GET['type'] : null;
            $sql = "SELECT id, type, title, file_name, file_size, created_at, updated_at FROM vault_items WHERE user_id = ?";
            $params = [$user_id];
            if (in_array($type, ['password', 'note', 'file'], true)) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }
            $sql .= " ORDER BY updated_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            successResponse(['items' => $items]);
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) {
                errorResponse('ID を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id, type, title, encrypted_payload, encryption_iv, file_name, file_size, created_at, updated_at FROM vault_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                errorResponse('項目が見つかりません', 404);
            }
            $payload = VaultCrypto::decrypt($row['encrypted_payload'], $row['encryption_iv'], $user_id);
            unset($row['encrypted_payload'], $row['encryption_iv']);
            $row['payload'] = $payload;
            successResponse(['item' => $row]);
        }

        case 'create': {
            $type = $input['type'] ?? '';
            $title = trim($input['title'] ?? '');
            $payload = $input['payload'] ?? '';
            if (!in_array($type, ['password', 'note', 'file'], true)) {
                errorResponse('種別は password / note / file のいずれかです');
            }
            if ($title === '') {
                errorResponse('タイトルを入力してください');
            }
            $file_name = isset($input['file_name']) ? trim($input['file_name']) : null;
            $file_size = isset($input['file_size']) ? (int)$input['file_size'] : null;
            $enc = VaultCrypto::encrypt((string)$payload, $user_id);
            $stmt = $pdo->prepare("
                INSERT INTO vault_items (user_id, type, title, encrypted_payload, encryption_iv, file_name, file_size)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $type,
                $title,
                $enc['cipher'],
                $enc['iv'],
                $file_name,
                $file_size,
            ]);
            successResponse(['id' => (int)$pdo->lastInsertId()], '追加しました');
        }

        case 'update': {
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                errorResponse('ID を指定してください');
            }
            $stmt = $pdo->prepare("SELECT id FROM vault_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            if (!$stmt->fetch()) {
                errorResponse('項目が見つかりません', 404);
            }
            $title = isset($input['title']) ? trim($input['title']) : null;
            $payload = isset($input['payload']) ? $input['payload'] : null;
            if ($title !== null || $payload !== null) {
                if ($payload !== null) {
                    $enc = VaultCrypto::encrypt((string)$payload, $user_id);
                    $pdo->prepare("UPDATE vault_items SET title = COALESCE(?, title), encrypted_payload = ?, encryption_iv = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                        ->execute([$title !== '' ? $title : null, $enc['cipher'], $enc['iv'], $id, $user_id]);
                } else {
                    $pdo->prepare("UPDATE vault_items SET title = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                        ->execute([$title !== '' ? $title : null, $id, $user_id]);
                }
            }
            successResponse([], '更新しました');
        }

        case 'delete': {
            $id = (int)($input['id'] ?? $input['memo_id'] ?? 0);
            if (!$id) {
                errorResponse('ID を指定してください');
            }
            $stmt = $pdo->prepare("DELETE FROM vault_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            if ($stmt->rowCount() === 0) {
                errorResponse('項目が見つかりません', 404);
            }
            successResponse([], '削除しました');
        }

        default:
            errorResponse('無効なアクションです');
    }
} catch (RuntimeException $e) {
    error_log('Vault API: ' . $e->getMessage());
    errorResponse($e->getMessage(), 400);
}
