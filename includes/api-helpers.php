<?php
/**
 * API共通ヘルパー関数
 * 
 * レスポンス形式、エラーハンドリング、セキュリティチェック等を統一
 */

// successResponse と errorResponse は config/database.php で定義済み
// 以下は config/database.php が読み込まれていない場合のフォールバック

if (!function_exists('successResponse')) {
    /**
     * 成功レスポンスを返す
     * @param array $data レスポンスデータ
     * @param string|null $message 成功メッセージ
     */
    function successResponse($data = [], $message = null) {
        if (ob_get_level()) {
            ob_clean();
        }
        $response = ['success' => true];
        if ($message) {
            $response['message'] = $message;
        }
        $response = array_merge($response, $data);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('errorResponse')) {
    /**
     * エラーレスポンスを返す
     * @param string $message エラーメッセージ
     * @param int $statusCode HTTPステータスコード
     * @param array $extra 追加データ
     */
    function errorResponse($message, $statusCode = 400, $extra = []) {
        if (ob_get_level()) {
            ob_clean();
        }
        http_response_code($statusCode);
        $response = array_merge([
            'success' => false,
            'message' => $message
        ], $extra);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * リクエストのJSONボディを取得
 * @return array
 */
if (!function_exists('getJsonInput')) {
    function getJsonInput() {
        $raw = $GLOBALS['_API_RAW_INPUT'] ?? file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * 必須パラメータチェック
 * @param array $input 入力データ
 * @param array $required 必須パラメータ名の配列
 * @return array 不足しているパラメータの配列
 */
function getMissingParams($input, $required) {
    $missing = [];
    foreach ($required as $param) {
        if (!isset($input[$param]) || (is_string($input[$param]) && trim($input[$param]) === '')) {
            $missing[] = $param;
        }
    }
    return $missing;
}

/**
 * 必須パラメータがなければエラー
 * @param array $input 入力データ
 * @param array $required 必須パラメータ名の配列
 */
function requireParams($input, $required) {
    $missing = getMissingParams($input, $required);
    if (!empty($missing)) {
        errorResponse('必須パラメータがありません: ' . implode(', ', $missing));
    }
}

/**
 * セッションから現在の組織IDを取得
 * @return int|null
 */
function getCurrentOrgId() {
    return isset($_SESSION['current_org_id']) ? (int)$_SESSION['current_org_id'] : null;
}

/**
 * 組織IDが必要な場合のチェック
 * @return int
 */
function requireCurrentOrg() {
    $orgId = getCurrentOrgId();
    if (!$orgId) {
        errorResponse('組織が選択されていません');
    }
    return $orgId;
}

/**
 * メソッドチェック
 * @param string|array $allowed 許可するHTTPメソッド
 */
function requireMethod($allowed) {
    if (is_string($allowed)) {
        $allowed = [$allowed];
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed)) {
        errorResponse('Method not allowed', 405);
    }
}

/**
 * ページネーションパラメータを取得
 * @param int $defaultPerPage デフォルトの1ページあたり件数
 * @param int $maxPerPage 最大件数
 * @return array ['page' => int, 'per_page' => int, 'offset' => int]
 */
function getPaginationParams($defaultPerPage = 20, $maxPerPage = 100) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min($maxPerPage, max(1, (int)($_GET['per_page'] ?? $defaultPerPage)));
    $offset = ($page - 1) * $perPage;
    
    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => $offset
    ];
}

/**
 * ページネーションレスポンスを作成
 * @param int $total 総件数
 * @param int $page 現在のページ
 * @param int $perPage 1ページあたり件数
 * @return array
 */
function buildPaginationResponse($total, $page, $perPage) {
    return [
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $total > 0 ? ceil($total / $perPage) : 1
    ];
}

/**
 * ソートパラメータを取得
 * @param array $allowedColumns 許可するカラム名
 * @param string $defaultColumn デフォルトカラム
 * @param string $defaultDir デフォルトソート方向
 * @return array ['column' => string, 'direction' => string]
 */
function getSortParams($allowedColumns, $defaultColumn = 'created_at', $defaultDir = 'DESC') {
    $column = $_GET['sort'] ?? $defaultColumn;
    $direction = strtoupper($_GET['dir'] ?? $defaultDir);
    
    if (!in_array($column, $allowedColumns)) {
        $column = $defaultColumn;
    }
    if (!in_array($direction, ['ASC', 'DESC'])) {
        $direction = 'DESC';
    }
    
    return [
        'column' => $column,
        'direction' => $direction
    ];
}

/**
 * 検索パラメータを取得
 * @return string
 */
function getSearchParam() {
    return trim($_GET['search'] ?? '');
}

/**
 * アクションパラメータを取得
 * @return string
 */
function getActionParam() {
    return $_GET['action'] ?? '';
}

/**
 * 数値配列をintにキャスト
 * @param array $items
 * @param array $columns キャストするカラム名
 * @return array
 */
function castIntColumns(&$items, $columns) {
    foreach ($items as &$item) {
        foreach ($columns as $col) {
            if (isset($item[$col])) {
                $item[$col] = (int)$item[$col];
            }
        }
    }
    return $items;
}

/**
 * SQLインジェクション対策済みのカラム名リストを作成
 * @param array $columns カラム名の配列
 * @param string $alias テーブルエイリアス
 * @return string
 */
function buildSelectColumns($columns, $alias = '') {
    $prefix = $alias ? "$alias." : '';
    return implode(', ', array_map(function($col) use ($prefix) {
        return $prefix . $col;
    }, $columns));
}

/**
 * セキュリティ: XSS対策
 * @param mixed $data
 * @return mixed
 */
function sanitizeOutput($data) {
    if (is_array($data)) {
        return array_map('sanitizeOutput', $data);
    }
    if (is_string($data)) {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

/**
 * CSRFトークンチェック
 * @param string $token
 * @return bool
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRFトークン生成
 * @return string
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

