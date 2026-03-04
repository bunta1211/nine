<?php
/**
 * Wish抽出API
 * チャットメッセージから願望・希望・依頼を自動抽出
 * 
 * アクション:
 * - extract: メッセージからWishを抽出
 * - patterns: パターン一覧取得
 * - add_pattern: パターン追加（管理者用）
 * - update_pattern: パターン更新（管理者用）
 * - delete_pattern: パターン削除（管理者用）
 * - test: パターンテスト
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/ai_wish_extractor.php';

header('Content-Type: application/json; charset=utf-8');

// データベース接続
$pdo = getDB();

// セッション確認
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '認証が必要です']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? '';

/**
 * 成功レスポンス（Wish API専用形式）
 */
function wishSuccessResponse($data = [], $message = '') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラーレスポンス（Wish API専用形式）
 */
function wishErrorResponse($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * メッセージからWishを抽出
 */
function extractWishes($pdo, $message, $user_id, $message_id = null, $auto_save = true) {
    $extracted = [];
    
    // 長文判定（100文字以上）- 長文の場合はAI分析を優先
    $isLongText = mb_strlen($message) >= 100;
    
    if ($isLongText && AI_WISH_EXTRACTION_ENABLED && isAIExtractionAvailable()) {
        // 長文の場合はAI分析で意味を理解して要約
        $aiResult = extractWishWithAI($message, $_SESSION['language'] ?? 'ja', true);
        
        if ($aiResult && $aiResult['has_wish'] && !empty($aiResult['wishes'])) {
            // AIが抽出した最初のWishのみを使用
            $aiWish = $aiResult['wishes'][0];
            $wish = [
                'wish' => $aiWish['text'],
                'category' => $aiWish['category'] ?? 'other',
                'category_label' => getCategoryLabel($aiWish['category'] ?? 'other'),
                'original_text' => mb_substr($message, 0, 100) . (mb_strlen($message) > 100 ? '...' : ''),
                'pattern_id' => null,
                'confidence' => $aiWish['confidence'] ?? 0.8,
                'source' => 'ai_summarized'
            ];
            
            $extracted[] = $wish;
            
            if ($auto_save) {
                saveWishAsTask($pdo, $user_id, $wish, $message_id, 'ai_summarized');
            }
        }
        
        return $extracted;
    }
    
    // 短文の場合はパターンマッチング
    // アクティブなパターンを優先度順に取得
    $stmt = $pdo->query("
        SELECT * FROM wish_patterns 
        WHERE is_active = 1 
        ORDER BY priority DESC
    ");
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($patterns)) {
        return $extracted;
    }
    
    // メッセージを行ごとに分割して解析（UTF-8対応）
    $lines = preg_split('/[。！!？?\n]+/u', $message);
    
    // 1メッセージにつき最大1つのWishのみ抽出
    $wishFound = false;
    
    foreach ($lines as $line) {
        // 既にWishが見つかっている場合はスキップ
        if ($wishFound) {
            break;
        }
        
        $line = trim($line);
        if (empty($line) || mb_strlen($line) < 3) {
            continue;
        }
        
        foreach ($patterns as $pattern) {
            $regex = '/' . $pattern['pattern'] . '/u';
            
            if (preg_match($regex, $line, $matches)) {
                $groupNum = (int)$pattern['extract_group'];
                
                // extract_group=0かつexample_outputがある場合は、学習された変換を使用
                if ($groupNum === 0 && !empty($pattern['example_output'])) {
                    $wishText = $pattern['example_output'];
                } else {
                    $wishText = $matches[$groupNum] ?? $matches[0];
                    
                    // 接尾辞を除去
                    if (!empty($pattern['suffix_remove'])) {
                        $suffixes = explode(',', $pattern['suffix_remove']);
                        foreach ($suffixes as $suffix) {
                            $wishText = rtrim($wishText, trim($suffix));
                        }
                    }
                }
                
                $wishText = trim($wishText);
                
                // 短すぎるものは除外
                if (mb_strlen($wishText) < 2) {
                    continue;
                }
                
                // 重複チェック
                $isDuplicate = false;
                foreach ($extracted as $e) {
                    if ($e['wish'] === $wishText) {
                        $isDuplicate = true;
                        break;
                    }
                }
                
                if (!$isDuplicate) {
                    $wish = [
                        'wish' => $wishText,
                        'category' => $pattern['category'],
                        'category_label' => $pattern['category_label'],
                        'original_text' => $line,
                        'pattern_id' => (int)$pattern['id'],
                        'confidence' => 0.80 // ルールベースは固定値
                    ];
                    
                    $extracted[] = $wish;
                    $wishFound = true;
                    
                    // 自動保存が有効なら即座にtasksテーブルに追加
                    if ($auto_save) {
                        saveWishAsTask($pdo, $user_id, $wish, $message_id);
                    }
                    
                    // 1メッセージにつき1つのWishのみ
                    break 2; // 両方のループを抜ける
                }
                
                // 1行につき1パターンのみマッチ
                break;
            }
        }
    }
    
    // ハイブリッド抽出: パターンで抽出できなかった場合にAI抽出を試みる
    if (empty($extracted) && AI_WISH_EXTRACTION_ENABLED && AI_EXTRACTION_FALLBACK_ONLY) {
        $aiResult = extractWishWithAI($message, $_SESSION['language'] ?? 'ja');
        
        if ($aiResult && $aiResult['has_wish'] && !empty($aiResult['wishes'])) {
            foreach ($aiResult['wishes'] as $aiWish) {
                $wish = [
                    'wish' => $aiWish['text'],
                    'category' => $aiWish['category'] ?? 'other',
                    'category_label' => getCategoryLabel($aiWish['category'] ?? 'other'),
                    'original_text' => $message,
                    'pattern_id' => null, // AIによる抽出
                    'confidence' => $aiWish['confidence'] ?? 0.7,
                    'source' => 'ai' // AI抽出であることを示す
                ];
                
                $extracted[] = $wish;
                
                // 自動保存
                if ($auto_save) {
                    saveWishAsTask($pdo, $user_id, $wish, $message_id, 'ai_extracted');
                }
            }
        }
    }
    
    return $extracted;
}

/**
 * カテゴリのラベルを取得
 */
function getCategoryLabel($category) {
    $labels = [
        'desire' => '願望',
        'request' => '依頼',
        'need' => '必要',
        'plan' => '予定',
        'purchase' => '購入',
        'learn' => '学習',
        'improve' => '改善',
        'social' => '社交',
        'travel' => '旅行',
        'problem' => '問題',
        'other' => 'その他'
    ];
    return $labels[$category] ?? 'その他';
}

/**
 * 抽出したWishをtasksテーブルに保存
 */
function saveWishAsTask($pdo, $user_id, $wish, $message_id = null, $source = 'ai_extracted') {
    // 同じ内容のWishが最近（24時間以内）追加されていないかチェック
    $stmt = $pdo->prepare("
        SELECT id FROM tasks 
        WHERE created_by = ? 
        AND title = ? 
        AND source = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$user_id, $wish['wish'], $source]);
    
    if ($stmt->fetch()) {
        return null; // 重複スキップ
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO tasks (
            created_by, title, description, status, priority,
            source, source_message_id, original_text, confidence, category,
            created_at
        ) VALUES (
            ?, ?, ?, 'pending', 1,
            ?, ?, ?, ?, ?,
            NOW()
        )
    ");
    
    $description = "自動抽出: 「{$wish['original_text']}」";
    
    $stmt->execute([
        $user_id,
        $wish['wish'],
        $description,
        $source,
        $message_id,
        $wish['original_text'],
        $wish['confidence'],
        $wish['category']
    ]);
    
    return $pdo->lastInsertId();
}

/**
 * 管理者権限チェック
 */
function requireAdmin($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !in_array($user['role'], ['admin', 'support'])) {
        wishErrorResponse('管理者権限が必要です', 403);
    }
}

// アクション処理
switch ($action) {
    case 'extract':
        // メッセージからWishを抽出
        $message = trim($input['message'] ?? '');
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : null;
        $auto_save = $input['auto_save'] ?? true;
        
        if (empty($message)) {
            wishErrorResponse('メッセージが必要です');
        }
        
        $extracted = extractWishes($pdo, $message, $user_id, $message_id, $auto_save);
        
        wishSuccessResponse([
            'extracted' => $extracted,
            'count' => count($extracted)
        ]);
        break;
        
    case 'patterns':
        // パターン一覧を取得
        $stmt = $pdo->query("
            SELECT * FROM wish_patterns 
            ORDER BY priority DESC, id ASC
        ");
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 数値型をキャスト
        foreach ($patterns as &$p) {
            $p['id'] = (int)$p['id'];
            $p['is_active'] = (int)$p['is_active'];
            $p['priority'] = (int)$p['priority'];
            $p['extract_group'] = (int)$p['extract_group'];
        }
        
        wishSuccessResponse(['patterns' => $patterns]);
        break;
        
    case 'add_pattern':
        // パターン追加（管理者用）
        requireAdmin($pdo, $user_id);
        
        $pattern = trim($input['pattern'] ?? '');
        $category = $input['category'] ?? 'other';
        $category_label = trim($input['category_label'] ?? '');
        $description = trim($input['description'] ?? '');
        $example_input = trim($input['example_input'] ?? '');
        $example_output = trim($input['example_output'] ?? '');
        $priority = (int)($input['priority'] ?? 50);
        
        if (empty($pattern)) {
            wishErrorResponse('パターンが必要です');
        }
        
        // 正規表現の妥当性チェック
        if (@preg_match('/' . $pattern . '/u', '') === false) {
            wishErrorResponse('無効な正規表現パターンです');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO wish_patterns (
                pattern, category, category_label, description,
                example_input, example_output, priority
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $pattern, $category, $category_label, $description,
            $example_input, $example_output, $priority
        ]);
        
        wishSuccessResponse(['pattern_id' => (int)$pdo->lastInsertId()], 'パターンを追加しました');
        break;
        
    case 'update_pattern':
        // パターン更新（管理者用）
        requireAdmin($pdo, $user_id);
        
        $pattern_id = (int)($input['pattern_id'] ?? 0);
        if (!$pattern_id) {
            wishErrorResponse('パターンIDが必要です');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($input['pattern'])) {
            if (@preg_match('/' . $input['pattern'] . '/u', '') === false) {
                wishErrorResponse('無効な正規表現パターンです');
            }
            $updates[] = "pattern = ?";
            $params[] = $input['pattern'];
        }
        if (isset($input['category'])) {
            $updates[] = "category = ?";
            $params[] = $input['category'];
        }
        if (isset($input['category_label'])) {
            $updates[] = "category_label = ?";
            $params[] = $input['category_label'];
        }
        if (isset($input['description'])) {
            $updates[] = "description = ?";
            $params[] = $input['description'];
        }
        if (isset($input['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = (int)$input['is_active'];
        }
        if (isset($input['priority'])) {
            $updates[] = "priority = ?";
            $params[] = (int)$input['priority'];
        }
        
        if (empty($updates)) {
            wishErrorResponse('更新する項目がありません');
        }
        
        $params[] = $pattern_id;
        $sql = "UPDATE wish_patterns SET " . implode(', ', $updates) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
        
        wishSuccessResponse([], 'パターンを更新しました');
        break;
        
    case 'delete_pattern':
        // パターン削除（管理者用）
        requireAdmin($pdo, $user_id);
        
        $pattern_id = (int)($input['pattern_id'] ?? 0);
        if (!$pattern_id) {
            wishErrorResponse('パターンIDが必要です');
        }
        
        $pdo->prepare("DELETE FROM wish_patterns WHERE id = ?")->execute([$pattern_id]);
        
        wishSuccessResponse([], 'パターンを削除しました');
        break;
        
    case 'test':
        // パターンテスト（保存せずに抽出結果のみ返す）
        $message = trim($input['message'] ?? '');
        
        if (empty($message)) {
            wishErrorResponse('テストメッセージが必要です');
        }
        
        $extracted = extractWishes($pdo, $message, $user_id, null, false);
        
        wishSuccessResponse([
            'extracted' => $extracted,
            'count' => count($extracted)
        ], 'テスト完了');
        break;
    
    case 'manual_add':
        // 手動Wish登録（パターンマッチしなかった場合にユーザーが直接登録）
        $original_text = trim($input['original_text'] ?? '');
        $wish_text = trim($input['wish_text'] ?? '');
        $message_id = isset($input['message_id']) ? (int)$input['message_id'] : null;
        $category = $input['category'] ?? 'other';
        $assigned_to = isset($input['assigned_to']) ? (int)$input['assigned_to'] : null;
        
        if (empty($original_text) || empty($wish_text)) {
            wishErrorResponse('元のメッセージとWishテキストが必要です');
        }
        
        // 割り当て先が指定されている場合は「依頼」カテゴリに変更
        if ($assigned_to) {
            $category = 'request';
        }
        
        // created_byを決定（自分か割り当て先か）
        $created_by = $assigned_to ? $assigned_to : $user_id;
        
        // 同じ内容のWishが最近追加されていないかチェック
        $stmt = $pdo->prepare("
            SELECT id FROM tasks 
            WHERE created_by = ? 
            AND title = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$created_by, $wish_text]);
        
        if ($stmt->fetch()) {
            wishErrorResponse('同じWishが最近登録されています');
        }
        
        // Wishをtasksテーブルに保存
        // priority: 0=低, 1=中, 2=高
        $stmt = $pdo->prepare("
            INSERT INTO tasks (
                created_by, assigned_to, title, description, status, priority,
                source, source_message_id, original_text, confidence, category,
                created_at
            ) VALUES (
                ?, ?, ?, ?, 'pending', 1,
                'manual', ?, ?, 1.00, ?,
                NOW()
            )
        ");
        
        // 割り当て先がある場合は誰からの依頼かを記録
        if ($assigned_to) {
            $description = "依頼元: ユーザーID {$user_id} / メッセージ: 「{$original_text}」";
        } else {
            $description = "手動登録: 「{$original_text}」";
        }
        
        $stmt->execute([
            $created_by,
            $assigned_to ? $user_id : null, // assigned_toには依頼元を入れる（逆の意味で使用）
            $wish_text,
            $description,
            $message_id,
            $original_text,
            $category
        ]);
        
        $task_id = (int)$pdo->lastInsertId();
        
        // パターン提案として保存
        saveSuggestion($pdo, $user_id, $original_text, $wish_text, $category);
        
        // 自動パターン学習: wish_patternsテーブルに追加
        autoLearnPattern($pdo, $original_text, $wish_text, $category);
        
        $response_msg = $assigned_to ? 'Wishを相手に割り当てました' : 'Wishを登録しました';
        wishSuccessResponse(['task_id' => $task_id, 'assigned_to' => $assigned_to], $response_msg);
        break;
    
    case 'suggestions':
        // パターン提案一覧（管理者用）
        requireAdmin($pdo, $user_id);
        
        $status = $_GET['status'] ?? 'pending';
        $limit = (int)($_GET['limit'] ?? 50);
        
        // 同じWishの提案をグループ化して表示
        $stmt = $pdo->prepare("
            SELECT 
                extracted_wish,
                suggested_category,
                COUNT(*) as suggestion_count,
                COUNT(DISTINCT user_id) as unique_users,
                MAX(original_text) as sample_text,
                MAX(id) as latest_id,
                MAX(created_at) as last_suggested
            FROM wish_pattern_suggestions
            WHERE status = ?
            GROUP BY extracted_wish, suggested_category
            ORDER BY suggestion_count DESC, last_suggested DESC
            LIMIT ?
        ");
        $stmt->execute([$status, $limit]);
        $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($suggestions as &$s) {
            $s['suggestion_count'] = (int)$s['suggestion_count'];
            $s['unique_users'] = (int)$s['unique_users'];
            $s['latest_id'] = (int)$s['latest_id'];
        }
        
        wishSuccessResponse(['suggestions' => $suggestions]);
        break;
    
    case 'approve_suggestion':
        // 提案を承認してパターン化（管理者用）
        requireAdmin($pdo, $user_id);
        
        $extracted_wish = trim($input['extracted_wish'] ?? '');
        $pattern = trim($input['pattern'] ?? '');
        $category = $input['category'] ?? 'other';
        $category_label = trim($input['category_label'] ?? '');
        
        if (empty($extracted_wish)) {
            wishErrorResponse('Wish文字列が必要です');
        }
        
        // パターンが指定されていない場合は自動生成
        if (empty($pattern)) {
            $pattern = generatePattern($extracted_wish);
        }
        
        // 正規表現の妥当性チェック
        if (@preg_match('/' . $pattern . '/u', '') === false) {
            wishErrorResponse('無効な正規表現パターンです');
        }
        
        // パターンを追加
        $stmt = $pdo->prepare("
            INSERT INTO wish_patterns (
                pattern, category, category_label, description,
                example_input, example_output, priority
            ) VALUES (?, ?, ?, ?, ?, ?, 50)
        ");
        $stmt->execute([
            $pattern,
            $category,
            $category_label ?: getDefaultCategoryLabel($category),
            'ユーザー提案から自動生成',
            $extracted_wish,
            $extracted_wish
        ]);
        
        $pattern_id = (int)$pdo->lastInsertId();
        
        // 提案を承認済みに更新
        $stmt = $pdo->prepare("
            UPDATE wish_pattern_suggestions 
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE extracted_wish = ? AND status = 'pending'
        ");
        $stmt->execute([$user_id, $extracted_wish]);
        
        wishSuccessResponse([
            'pattern_id' => $pattern_id,
            'pattern' => $pattern
        ], 'パターンを追加しました');
        break;
    
    case 'reject_suggestion':
        // 提案を却下（管理者用）
        requireAdmin($pdo, $user_id);
        
        $extracted_wish = trim($input['extracted_wish'] ?? '');
        
        if (empty($extracted_wish)) {
            wishErrorResponse('Wish文字列が必要です');
        }
        
        $stmt = $pdo->prepare("
            UPDATE wish_pattern_suggestions 
            SET status = 'rejected', approved_by = ?, approved_at = NOW()
            WHERE extracted_wish = ? AND status = 'pending'
        ");
        $stmt->execute([$user_id, $extracted_wish]);
        
        wishSuccessResponse([], '提案を却下しました');
        break;
    
    case 'popular_suggestions':
        // 人気の提案（自動パターン化の候補）
        $min_count = (int)($_GET['min_count'] ?? 3);
        $min_users = (int)($_GET['min_users'] ?? 2);
        
        $stmt = $pdo->prepare("
            SELECT 
                extracted_wish,
                suggested_category,
                COUNT(*) as suggestion_count,
                COUNT(DISTINCT user_id) as unique_users
            FROM wish_pattern_suggestions
            WHERE status = 'pending'
            GROUP BY extracted_wish, suggested_category
            HAVING suggestion_count >= ? AND unique_users >= ?
            ORDER BY suggestion_count DESC
            LIMIT 20
        ");
        $stmt->execute([$min_count, $min_users]);
        $popular = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($popular as &$p) {
            $p['suggestion_count'] = (int)$p['suggestion_count'];
            $p['unique_users'] = (int)$p['unique_users'];
        }
        
        wishSuccessResponse(['popular' => $popular]);
        break;
        
    default:
        wishErrorResponse('無効なアクションです');
}

/**
 * パターン提案を保存
 */
function saveSuggestion($pdo, $user_id, $original_text, $extracted_wish, $category) {
    // 同じユーザーが同じWishを提案済みかチェック
    $stmt = $pdo->prepare("
        SELECT id FROM wish_pattern_suggestions
        WHERE user_id = ? AND extracted_wish = ?
    ");
    $stmt->execute([$user_id, $extracted_wish]);
    
    if ($stmt->fetch()) {
        return; // 既に提案済み
    }
    
    // 簡易パターンを自動生成
    $suggested_pattern = generatePattern($extracted_wish);
    
    $stmt = $pdo->prepare("
        INSERT INTO wish_pattern_suggestions (
            original_text, extracted_wish, suggested_pattern, suggested_category, user_id
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $original_text,
        $extracted_wish,
        $suggested_pattern,
        $category,
        $user_id
    ]);
}

/**
 * Wish文字列から正規表現パターンを自動生成
 */
function generatePattern($wish_text) {
    // 日本語パターン
    $japanesePatterns = [
        '/(.+?)(?:したい|したいです|したいなぁ|したいな)$/u' => '(.+したい)',
        '/(.+?)(?:に行きたい|へ行きたい)$/u' => '(.+に行きたい|.+へ行きたい)',
        '/(.+?)(?:を見たい|見たい|を観たい|観たい)$/u' => '(.+を見たい|.+見たい|.+を観たい|.+観たい)',
        '/(.+?)(?:を食べたい|食べたい)$/u' => '(.+を食べたい|.+食べたい)',
        '/(.+?)(?:が欲しい|欲しい|がほしい|ほしい)$/u' => '(.+が欲しい|.+欲しい|.+がほしい|.+ほしい)',
        '/(.+?)(?:を買いたい|買いたい)$/u' => '(.+を買いたい|.+買いたい)',
    ];
    
    // 英語パターン
    $englishPatterns = [
        '/^(?:I |i )?want to (.+)$/i' => '(?:I |i )?want to (.+)',
        "/^(?:I |i )?(?:would like|'d like) to (.+)$/i" => "(?:I |i )?(?:would like|'d like) to (.+)",
        '/^(?:I |i )?wish to (.+)$/i' => '(?:I |i )?wish to (.+)',
        '/^(?:I |i )?hope to (.+)$/i' => '(?:I |i )?hope to (.+)',
        '/^(?:I |i )?need to (.+)$/i' => '(?:I |i )?need to (.+)',
        '/^(?:I |i )?(?:have to|gotta) (.+)$/i' => '(?:I |i )?(?:have to|gotta|got to) (.+)',
        '/^(?:can|could|would) you (.+?)(?:\?)?$/i' => '(?:can|could|would) you (?:please )?(.+?)(?:\?)?$',
        '/^please (.+?)(?:\?)?$/i' => '(?:please |Please )(.+?)(?:\?)?$',
    ];
    
    // 中国語パターン
    $chinesePatterns = [
        '/^(?:我)?想(?:要)?(.+)$/u' => '(?:我)?想(?:要)?(.+)',
        '/^(?:我)?希望(.+)$/u' => '(?:我)?希望(.+)',
        '/^(?:我)?需要(.+)$/u' => '(?:我)?需要(.+)',
        '/^(?:我)?打算(.+)$/u' => '(?:我)?打算(.+)',
        '/^(?:我)?计划(.+)$/u' => '(?:我)?计划(.+)',
        '/^请(?:你)?(.+)$/u' => '请(?:你)?(.+)',
        '/^(?:我)?想买(.+)$/u' => '(?:我)?想买(.+)',
        '/^(?:我)?想去(.+)$/u' => '(?:我)?想去(.+)',
        '/^(?:我)?想(?:看|观看)(.+)$/u' => '(?:我)?想(?:看|观看)(.+)',
        '/^(?:我)?想吃(.+)$/u' => '(?:我)?想吃(.+)',
    ];
    
    // 全パターンをチェック
    $allPatterns = array_merge($japanesePatterns, $englishPatterns, $chinesePatterns);
    
    foreach ($allPatterns as $regex => $template) {
        if (preg_match($regex, $wish_text)) {
            return $template;
        }
    }
    
    // マッチしない場合は汎用パターン
    return '(' . preg_quote($wish_text, '/') . ')';
}

/**
 * カテゴリのデフォルトラベル
 */
function getDefaultCategoryLabel($category) {
    $labels = [
        'request' => '依頼',
        'desire' => '願望',
        'want' => '欲しい',
        'travel' => '旅行',
        'purchase' => '購入',
        'work' => 'やること',
        'other' => 'その他'
    ];
    return $labels[$category] ?? 'その他';
}

/**
 * 手動Wishから自動的にパターンを学習してwish_patternsに追加
 */
function autoLearnPattern($pdo, $original_text, $wish_text, $category) {
    // 元のメッセージからパターンを生成（意味的変換の学習）
    // 「水道が壊れた」→「水道を修理したい」のような変換を学習
    
    // 元のメッセージをパターン化（句読点を除去してエスケープ）
    $cleanOriginal = preg_replace('/[。、！？!?,.\s]+$/u', '', trim($original_text));
    $escapedOriginal = preg_quote($cleanOriginal, '/');
    $pattern = '(' . $escapedOriginal . ')';
    
    // 同じパターンが既に存在するかチェック
    $stmt = $pdo->prepare("SELECT id FROM wish_patterns WHERE pattern = ?");
    $stmt->execute([$pattern]);
    
    if ($stmt->fetch()) {
        return; // 既に存在
    }
    
    // カテゴリラベルを取得
    $category_label = getDefaultCategoryLabel($category);
    
    // wish_patternsに追加（優先度は低め: 25、extract_group=0で全体を返す）
    // 注意: このパターンはextract_group=0で、example_outputに変換後のテキストを保存
    $stmt = $pdo->prepare("
        INSERT INTO wish_patterns (
            pattern, category, category_label, 
            description, example_input, example_output,
            is_active, priority, extract_group
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            1, 25, 0
        )
    ");
    
    $description = "自動学習: 「{$cleanOriginal}」→「{$wish_text}」";
    
    try {
        $stmt->execute([
            $pattern,
            $category,
            $category_label,
            $description,
            $original_text,
            $wish_text  // ここに変換後のWishテキストを保存
        ]);
    } catch (PDOException $e) {
        // 重複エラー等は無視
        error_log("Auto learn pattern error: " . $e->getMessage());
    }
}






