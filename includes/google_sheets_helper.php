<?php
/**
 * Googleスプレッドシート連携ヘルパー
 * 必要なライブラリ: google/apiclient (composer)
 */

require_once __DIR__ . '/../config/google_sheets.php';

/**
 * ユーザーのスプレッドシート連携アカウントを取得
 */
function getGoogleSheetsAccount(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM google_sheets_accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 認証済みGoogleクライアントを取得（トークン更新含む）
 */
function getGoogleSheetsClient(array $account, PDO $pdo = null): ?\Google\Client {
    if (!isGoogleSheetsEnabled()) {
        return null;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return null;
    }
    require_once $autoload;
    if (!class_exists('Google\Client')) {
        return null;
    }

    $client = new \Google\Client();
    $client->setClientId(GOOGLE_SHEETS_CLIENT_ID);
    $client->setClientSecret(GOOGLE_SHEETS_CLIENT_SECRET);
    $client->setRedirectUri(getGoogleSheetsRedirectUri());
    $client->addScope(\Google\Service\Sheets::SPREADSHEETS);
    $client->setAccessType('offline');

    $token = ['refresh_token' => $account['refresh_token']];
    if (!empty($account['access_token'])) {
        $decoded = is_string($account['access_token']) ? json_decode($account['access_token'], true) : $account['access_token'];
        if (is_array($decoded)) {
            $token = array_merge($decoded, $token);
        } else {
            $token['access_token'] = $account['access_token'];
        }
    }
    if (empty($token['created'])) {
        $token['created'] = time() - 3600;
    }
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($account['refresh_token']);
        if (isset($newToken['error'])) {
            error_log('Google Sheets token refresh error: ' . ($newToken['error_description'] ?? $newToken['error']));
            return null;
        }
        $client->setAccessToken($newToken);
        if ($pdo) {
            $expiresAt = isset($newToken['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$newToken['expires_in']) : null;
            $stmt = $pdo->prepare("UPDATE google_sheets_accounts SET access_token = ?, token_expires_at = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($newToken), $expiresAt, $account['id']]);
        }
    }

    return $client;
}

/**
 * Sheets API サービスを取得
 */
function getSheetsService(\Google\Client $client): \Google\Service\Sheets {
    return new \Google\Service\Sheets($client);
}

/**
 * スプレッドシートの範囲を読み取り
 * @return array|null 2次元配列（行の配列）または null
 */
function sheetsReadRange(\Google\Service\Sheets $service, string $spreadsheetId, string $range): ?array {
    try {
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $rows = $response->getValues();
        return $rows !== null ? $rows : [];
    } catch (Exception $e) {
        error_log('Sheets read error: ' . $e->getMessage());
        return null;
    }
}

/**
 * スプレッドシートの範囲に書き込み
 * @param array $values 2次元配列（行の配列）。例: [['A1','B1'],['A2','B2']]
 */
function sheetsUpdateRange(\Google\Service\Sheets $service, string $spreadsheetId, string $range, array $values): bool {
    try {
        $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
        $service->spreadsheets_values->update(
            $spreadsheetId,
            $range,
            $body,
            ['valueInputOption' => 'USER_ENTERED']
        );
        return true;
    } catch (Exception $e) {
        error_log('Sheets update error: ' . $e->getMessage());
        return false;
    }
}

/**
 * スプレッドシートのメタデータ取得（シート名一覧など）
 */
function sheetsGetMetadata(\Google\Service\Sheets $service, string $spreadsheetId): ?array {
    try {
        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheets = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $prop = $sheet->getProperties();
            $sheets[] = [
                'sheet_id' => $prop->getSheetId(),
                'title' => $prop->getTitle(),
            ];
        }
        return [
            'title' => $spreadsheet->getProperties()->getTitle(),
            'sheets' => $sheets,
        ];
    } catch (Exception $e) {
        error_log('Sheets metadata error: ' . $e->getMessage());
        return null;
    }
}
