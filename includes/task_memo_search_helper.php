<?php
/**
 * タスク・メモ検索ヘルパー
 * あなたの秘書がキーワード検索によりタスク・メモを報告する機能をサポート
 */

/**
 * テーブルに指定カラムが存在するか確認（deleted_at 等の後方互換用）
 */
function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . preg_replace('/[^a-z0-9_]/i', '', $table) . "` LIKE ?");
            $stmt->execute([$column]);
            // rowCount()はSELECTで環境により動作しないため、fetchで判定
            $cache[$key] = $stmt->fetch() !== false;
        } catch (Exception $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/**
 * タスク・メモ検索の利用制限をチェック
 * @param PDO $pdo
 * @param int $user_id
 * @return array ['allowed' => bool, 'count' => int] テーブル未存在時は allowed=true
 */
function checkTaskMemoSearchLimit(PDO $pdo, int $user_id): array {
    $limit = defined('AI_TASK_MEMO_SEARCH_DAILY_LIMIT') ? (int)AI_TASK_MEMO_SEARCH_DAILY_LIMIT : 20;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM ai_usage_logs
            WHERE user_id = ? AND feature = 'task_memo_search' AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['cnt'] ?? 0);
        return ['allowed' => $count < $limit, 'count' => $count];
    } catch (Exception $e) {
        return ['allowed' => true, 'count' => 0];
    }
}

/**
 * タスク・メモ検索の利用を記録
 * config/ai_config.php の AI_USAGE_LOGGING_ENABLED が false の場合は記録しない
 * テーブルが無い場合は自動作成してから記録する
 */
function recordTaskMemoSearchUsage(PDO $pdo, int $user_id): void {
    if (defined('AI_USAGE_LOGGING_ENABLED') && !AI_USAGE_LOGGING_ENABLED) {
        return;
    }
    $insert = function () use ($pdo, $user_id) {
        $stmt = $pdo->prepare("
            INSERT INTO ai_usage_logs (user_id, provider, feature, input_chars, output_chars, created_at)
            VALUES (?, 'internal', 'task_memo_search', 0, 0, NOW())
        ");
        $stmt->execute([$user_id]);
    };
    try {
        $insert();
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, "doesn't exist") !== false || (method_exists($e, 'getCode') && $e->getCode() === '42S02')) {
            if (function_exists('ensureAiUsageLogsTable')) {
                ensureAiUsageLogsTable($pdo);
            } else {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS ai_usage_logs (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id INT UNSIGNED NOT NULL,
                            provider VARCHAR(20) DEFAULT 'gemini',
                            feature VARCHAR(50),
                            input_chars INT UNSIGNED DEFAULT 0,
                            output_chars INT UNSIGNED DEFAULT 0,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_user (user_id),
                            INDEX idx_created (created_at),
                            INDEX idx_feature (feature)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                } catch (Exception $e2) {
                    error_log("Task memo search ensure table: " . $e2->getMessage());
                    return;
                }
            }
            try {
                $insert();
            } catch (Exception $e2) {
                error_log("Task memo search usage log retry: " . $e2->getMessage());
            }
        } else {
            error_log("Task memo search usage log error: " . $msg);
        }
    }
}

/**
 * ユーザーの質問から検索パラメータを抽出
 * 「2025年度の怪我をまとめて報告して」→ ['keyword' => '怪我', 'year' => 2025]
 *
 * @param string $question ユーザーの質問
 * @return array|null 検索対象なら ['keyword'=>string, 'year'=>int|null], 対象外なら null
 */
function extractTaskMemoSearchParams(string $question): ?array {
    $q = trim($question);
    if (mb_strlen($q) < 5) return null;

    $triggers = ['まとめて報告', 'をまとめて', '検索して', '検索で', 'を検索', '一覧', '件の', '何件', '報告して', 'まとめてお願い', '調べて', '教えて', '探して', 'あった', '見つけて', 'ありますか'];
    $hasTrigger = false;
    foreach ($triggers as $t) {
        if (mb_strpos($q, $t) !== false) {
            $hasTrigger = true;
            break;
        }
    }
    if (!$hasTrigger) return null;

    $year = null;
    if (preg_match('/(\d{4})年度/u', $q, $m)) {
        $year = (int)$m[1];
    } elseif (preg_match('/(\d{4})年/u', $q, $m)) {
        $year = (int)$m[1];
    }

    $keyword = null;
    $patterns = [
        // 「カッコ」付きキーワード（最優先）
        '/「([^」]{1,30})」/u',
        '/『([^』]{1,30})』/u',
        // 「〇〇で△△を検索/探して」パターン（メッセージで、チャットで、グループで等を除去）
        '/(?:メッセージ|チャット|グループ|会話)で(\d{4}年度?の)?(.+?)を(?:検索|探)/u',
        // 「〇〇についてまとめて/教えて」パターン（自然な質問）
        '/(\d{4}年度?の)?(.+?)について(?:まとめて|教えて|報告|説明)/u',
        // 基本パターン
        '/(\d{4}年度?の)?(.+?)をまとめて/u',
        '/(\d{4}年度?の)?(.+?)について報告/u',
        '/(\d{4}年度?の)?(.+?)を検索/u',
        '/(\d{4}年度?の)?(.+?)を探して/u',
        '/(\d{4}年度?の)?(.+?)を見つけて/u',
        '/(\d{4}年度?の)?(.+?)の一覧/u',
        '/(\d{4}年度?の)?(.+?)が何件/u',
        '/(\d{4}年度?の)?(.+?)を教えて/u',
        '/(\d{4}年度?の)?(.+?)があった/u',
        '/(\d{4}年度?の)?(.+?)を調べて/u',
        '/(\d{4}年度?の)?(.+?)ありますか/u',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $q, $m)) {
            $k = isset($m[2]) ? trim($m[2]) : trim($m[1]);
            $k = preg_replace('/^(の|について|を|が)$/u', '', $k);
            if (mb_strlen($k) >= 1 && mb_strlen($k) <= 30) {
                $keyword = $k;
                break;
            }
        }
    }
    if (!$keyword) {
        $rest = preg_replace('/\d{4}年度?/u', '', $q);
        $rest = preg_replace('/まとめて|報告して|検索して|検索で|一覧|何件|件の|教えて|調べて|探して|見つけて|ありますか|あった|メッセージで|チャットで|グループで/u', '', $rest);
        $rest = preg_replace('/[、。！？\s]+/u', '', $rest);
        if (mb_strlen($rest) >= 2 && mb_strlen($rest) <= 30) {
            $keyword = $rest;
        }
    }
    if (!$keyword) return null;

    // フォローアップ質問は新規検索しない（「15件の検索をまとめて」「検索結果を教えて」等）
    if (isFollowUpQuestion($q)) {
        return null;
    }

    // 検索用にコアキーワードを正規化（「生活説明会について」→「生活説明会」）
    $keyword = normalizeSearchKeyword($keyword);

    return ['keyword' => $keyword, 'year' => $year];
}

/**
 * 直後のフォローアップ質問か判定（前回の検索結果についての質問）
 * 例：「15件の検索をまとめて」「検索結果を分析して」「詳しく教えて」
 */
function isFollowUpQuestion(string $question): bool {
    $q = trim($question);
    $followUpPatterns = [
        '/\d+件の検索を?/',         // 「15件の検索をまとめて」「15件の検索まとめて」
        '/検索結果を?(まとめて|教えて|分析して|詳しく)/',
        '/検索結果の?(内容|詳細)/',
        '/さっきの検索/',
        '/先ほどの検索/',
        '/先ほど(の|で)見つかった/',
        '/それら?を?(まとめて|分析して|教えて)/',
        '/見つかった(もの|情報|内容)を?/',
        '/その内容を?(まとめて|教えて|分析して)/',
        '/上記を?(まとめて|分析して|教えて)/',
    ];
    foreach ($followUpPatterns as $pat) {
        if (preg_match($pat, $q)) {
            return true;
        }
    }
    return false;
}

/**
 * 検索キーワードを正規化（接尾辞を除去してコアキーワードに）
 * 「生活説明会について」→「生活説明会」など、LIKE検索でヒットしやすくする
 */
function normalizeSearchKeyword(string $keyword): string {
    $k = trim($keyword);
    $suffixes = ['について', 'のこと', 'に関して', 'に関する', 'についての', 'を', 'の', 'が'];
    foreach ($suffixes as $s) {
        if (mb_strlen($k) > mb_strlen($s) && mb_substr($k, -mb_strlen($s)) === $s) {
            $k = mb_substr($k, 0, -mb_strlen($s));
            break; // 1回だけ除去
        }
    }
    return trim($k) ?: $keyword;
}

/**
 * タスク・メモをキーワードで検索
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $keyword 検索キーワード
 * @param int|null $year 年でフィルタ（nullなら全期間）
 * @param int $limit 最大件数
 * @return array ['tasks'=>array, 'memos'=>array, 'total'=>int, 'summary'=>string]
 */
function searchTasksAndMemos(PDO $pdo, int $user_id, string $keyword, ?int $year = null, int $limit = 30): array {
    $kw = '%' . $keyword . '%';
    $tasks = [];
    $memos = [];
    $params = [$user_id, $user_id, $kw, $kw];

    $tasksHaveDeletedAt = tableHasColumn($pdo, 'tasks', 'deleted_at');
    $memosHaveDeletedAt = tableHasColumn($pdo, 'memos', 'deleted_at');
    $hasTypeCol = tableHasColumn($pdo, 'tasks', 'type');

    $taskCols = "t.id, t.title, t.description, t.due_date, t.status, t.created_at, t.conversation_id";
    if ($tasksHaveDeletedAt) {
        $taskCols .= ", t.deleted_at";
    }
    $taskSql = "
        SELECT {$taskCols}
        FROM tasks t
        WHERE (t.created_by = ? OR t.assigned_to = ?)
        AND (t.title LIKE ? OR t.description LIKE ?)
    ";
    if ($hasTypeCol) {
        $taskSql .= " AND (t.type = 'task' OR t.type IS NULL)";
    }
    if ($year) {
        $taskSql .= " AND YEAR(t.created_at) = ?";
        $params[] = $year;
    }
    $taskSql .= " ORDER BY t.created_at DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($taskSql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($hasTypeCol) {
        $memoCols = "m.id, m.title, m.content, m.created_at, m.conversation_id";
        if ($tasksHaveDeletedAt) {
            $memoCols .= ", m.deleted_at";
        }
        $memoParams = [$user_id, "%{$keyword}%", "%{$keyword}%"];
        $memoSql = "
            SELECT {$memoCols}
            FROM tasks m
            WHERE m.created_by = ? AND m.type = 'memo'
            AND (m.title LIKE ? OR m.content LIKE ?)
        ";
        if ($tasksHaveDeletedAt) {
            $memoSql .= " AND m.deleted_at IS NULL";
        }
        if ($year) {
            $memoSql .= " AND YEAR(m.created_at) = ?";
            $memoParams[] = $year;
        }
        $memoSql .= " ORDER BY m.created_at DESC LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($memoSql);
        $stmt->execute($memoParams);
        $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $memosHaveDeletedAt = $tasksHaveDeletedAt;
    } else {
        $memoCols = "m.id, m.title, m.content, m.created_at, m.conversation_id";
        if ($memosHaveDeletedAt) {
            $memoCols .= ", m.deleted_at";
        }
        $memoParams = [$user_id, "%{$keyword}%", "%{$keyword}%"];
        $memoSql = "
            SELECT {$memoCols}
            FROM memos m
            WHERE m.created_by = ?
            AND (m.title LIKE ? OR m.content LIKE ?)
        ";
        if ($year) {
            $memoSql .= " AND YEAR(m.created_at) = ?";
            $memoParams[] = $year;
        }
        $memoSql .= " ORDER BY m.created_at DESC LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($memoSql);
        $stmt->execute($memoParams);
        $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // メッセージ検索（content + extracted_text、extracted_textは常に試行）
    $messages = [];
    try {
        $convStmt = $pdo->prepare("
            SELECT conversation_id FROM conversation_members 
            WHERE user_id = ? AND left_at IS NULL
        ");
        $convStmt->execute([$user_id]);
        $userConvIds = $convStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userConvIds)) {
            $placeholders = implode(',', array_fill(0, count($userConvIds), '?'));
            $msgSql = "
                SELECT m.id, m.content, m.extracted_text, m.created_at, m.conversation_id,
                       u.display_name as sender_name, c.name as conversation_name
                FROM messages m
                INNER JOIN users u ON m.sender_id = u.id
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE m.conversation_id IN ({$placeholders})
                AND m.deleted_at IS NULL
                AND (m.content LIKE ? OR m.extracted_text LIKE ?)
            ";
            $msgParams = array_merge($userConvIds, ["%{$keyword}%", "%{$keyword}%"]);
            if ($year) {
                $msgSql .= " AND YEAR(m.created_at) = ?";
                $msgParams[] = $year;
            }
            $msgSql .= " ORDER BY m.created_at DESC LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($msgSql);
            $stmt->execute($msgParams);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // extracted_textカラムがない場合はcontentのみで再試行
        if (strpos($e->getMessage(), 'extracted_text') !== false && !empty($userConvIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($userConvIds), '?'));
                $msgSql = "
                    SELECT m.id, m.content, m.created_at, m.conversation_id,
                           u.display_name as sender_name, c.name as conversation_name
                    FROM messages m
                    INNER JOIN users u ON m.sender_id = u.id
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    WHERE m.conversation_id IN ({$placeholders})
                    AND m.deleted_at IS NULL
                    AND m.content LIKE ?
                ";
                $msgParams = array_merge($userConvIds, ["%{$keyword}%"]);
                if ($year) {
                    $msgSql .= " AND YEAR(m.created_at) = ?";
                    $msgParams[] = $year;
                }
                $msgSql .= " ORDER BY m.created_at DESC LIMIT " . (int)$limit;
                $stmt = $pdo->prepare($msgSql);
                $stmt->execute($msgParams);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                error_log('[searchTasksAndMemos] messages search fallback error: ' . $e2->getMessage());
            }
        } else {
            error_log('[searchTasksAndMemos] messages search error: ' . $e->getMessage());
        }
    }

    $total = count($tasks) + count($memos) + count($messages);
    $summary = formatTaskMemoSearchResultsForAI($tasks, $memos, $keyword, $year, $tasksHaveDeletedAt, $memosHaveDeletedAt, $messages);

    return [
        'tasks' => $tasks,
        'memos' => $memos,
        'messages' => $messages,
        'total' => $total,
        'summary' => $summary,
        'keyword' => $keyword,
    ];
}

/**
 * テキストからキーワードの全出現箇所を抽出し、各出現の前後コンテキストを返す
 *
 * @param string $text 検索対象テキスト
 * @param string $keyword キーワード
 * @param int $contextChars 各出現の前後に取る文字数
 * @param int $maxOccurrences 最大抽出件数
 * @return array 各出現の前後コンテキスト文字列の配列
 */
function extractAllKeywordOccurrencesWithContext(string $text, string $keyword, int $contextChars = 150, int $maxOccurrences = 20): array {
    if ($keyword === '' || mb_strlen($text) < mb_strlen($keyword)) {
        return [];
    }
    $results = [];
    $offset = 0;
    $len = mb_strlen($keyword);
    while ($offset < mb_strlen($text) && count($results) < $maxOccurrences) {
        $pos = mb_strpos($text, $keyword, $offset);
        if ($pos === false) {
            break;
        }
        $start = max(0, $pos - $contextChars);
        $end = min(mb_strlen($text), $pos + $len + $contextChars);
        $snippet = ($start > 0 ? '...' : '') . mb_substr($text, $start, $end - $start) . ($end < mb_strlen($text) ? '...' : '');
        $results[] = $snippet;
        $offset = $pos + $len;
    }
    return $results;
}

/**
 * 検索結果をAI用のテキストに整形
 */
function formatTaskMemoSearchResultsForAI(array $tasks, array $memos, string $keyword, ?int $year, bool $tasksHaveDeletedAt = false, bool $memosHaveDeletedAt = false, array $messages = []): string {
    $lines = [];
    $lines[] = "【キーワード「{$keyword}」で検索した結果】";
    if ($year) {
        $lines[] = "期間: {$year}年度";
    }
    $msgCount = count($messages);
    $totalOccurrences = 0;
    foreach ($messages as $m) {
        if (!empty($m['extracted_text'])) {
            $totalOccurrences += substr_count($m['extracted_text'], $keyword);
        }
    }
    $occNote = $totalOccurrences > 0 ? "（PDF/長文内のキーワード出現: {$totalOccurrences}箇所）" : "";
    $lines[] = "タスク: " . count($tasks) . "件、メモ: " . count($memos) . "件、メッセージ: {$msgCount}件{$occNote}、合計: " . (count($tasks) + count($memos) + $msgCount) . "件";
    $lines[] = "";

    if (!empty($tasks)) {
        $lines[] = "■ タスク一覧:";
        foreach (array_slice($tasks, 0, 15) as $i => $t) {
            $title = mb_substr($t['title'] ?? '', 0, 50);
            $desc = mb_substr($t['description'] ?? '', 0, 80);
            $date = $t['created_at'] ? date('Y/m/d', strtotime($t['created_at'])) : '-';
            $status = $t['status'] ?? 'pending';
            $del = ($tasksHaveDeletedAt && !empty($t['deleted_at'])) ? " (削除済み)" : "";
            $link = (!empty($t['conversation_id'])) ? " [チャットを開く](chat.php?c=" . (int)$t['conversation_id'] . ")" : "";
            $lines[] = ($i + 1) . ". [{$date}] {$title}" . ($desc ? " - {$desc}" : "") . " (状態: {$status}){$del}{$link}";
        }
        if (count($tasks) > 15) {
            $lines[] = "... 他 " . (count($tasks) - 15) . "件";
        }
        $lines[] = "";
    }

    if (!empty($memos)) {
        $lines[] = "■ メモ一覧:";
        foreach (array_slice($memos, 0, 15) as $i => $m) {
            $title = mb_substr($m['title'] ?? '', 0, 50);
            $content = mb_substr($m['content'] ?? '', 0, 80);
            $date = $m['created_at'] ? date('Y/m/d', strtotime($m['created_at'])) : '-';
            $del = ($memosHaveDeletedAt && !empty($m['deleted_at'])) ? " (削除済み)" : "";
            $link = (!empty($m['conversation_id'])) ? " [チャットを開く](chat.php?c=" . (int)$m['conversation_id'] . ")" : "";
            $lines[] = ($i + 1) . ". [{$date}] {$title}" . ($content ? " - {$content}" : "") . "{$del}{$link}";
        }
        if (count($memos) > 15) {
            $lines[] = "... 他 " . (count($memos) - 15) . "件";
        }
    }

    if (!empty($messages)) {
        $lines[] = "";
        $lines[] = "■ メッセージ一覧（PDF/長文含む）:";
        foreach (array_slice($messages, 0, 10) as $i => $msg) {
            $date = $msg['created_at'] ? date('Y/m/d', strtotime($msg['created_at'])) : '-';
            $sender = mb_substr($msg['sender_name'] ?? '', 0, 20);
            $conv = mb_substr($msg['conversation_name'] ?? '', 0, 20);
            $link = (!empty($msg['conversation_id'])) ? " [チャットを開く](chat.php?c=" . (int)$msg['conversation_id'] . ")" : "";
            if (!empty($msg['extracted_text'])) {
                // キーワードの全出現箇所を抽出（各出現の前後150文字、最大20箇所）
                $occurrences = extractAllKeywordOccurrencesWithContext($msg['extracted_text'], $keyword, 150, 20);
                if (!empty($occurrences)) {
                    $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}（キーワード「{$keyword}」が" . count($occurrences) . "箇所）{$link}";
                    foreach ($occurrences as $j => $oc) {
                        $oc = preg_replace('/[\r\n]+/', ' ', $oc);
                        $lines[] = "    【" . ($j + 1) . "/" . count($occurrences) . "】 " . $oc;
                    }
                } else {
                    $preview = preg_replace('/[\r\n]+/', ' ', mb_substr($msg['extracted_text'], 0, 200));
                    $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}: {$preview}{$link}";
                }
            } else {
                $preview = preg_replace('/[\r\n]+/', ' ', mb_substr($msg['content'] ?? '', 0, 120));
                $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}: {$preview}{$link}";
            }
        }
        if (count($messages) > 10) {
            $lines[] = "... 他 " . (count($messages) - 10) . "件";
        }
    }

    return implode("\n", $lines);
}

/**
 * 質問文からトピックキーワードを抽出（自然な質問用）
 * 明示的な検索コマンドでなくても、質問の主題を抽出してメッセージ検索に使う
 *
 * @param string $question ユーザーの質問
 * @return string|null 抽出されたトピックキーワード
 */
function extractTopicKeyword(string $question): ?string {
    $q = trim($question);
    if (mb_strlen($q) < 4) return null;

    // 既にextractTaskMemoSearchParamsで処理済みのパターンは除外
    // ここでは「〇〇について教えて」「〇〇はどうなっている」等の自然な質問に対応
    $topicPatterns = [
        // 「〇〇について」パターン
        '/(.{2,20})について(?:教えて|まとめて|どう|知りたい|聞きたい|報告|説明)/u',
        // 「〇〇の〇〇」パターン（運営、状況、経緯など）
        '/(.{2,15})の(?:運営|状況|経緯|詳細|内容|概要|結果|記録|履歴|情報)/u',
        // 「〇〇はどう」パターン
        '/(.{2,20})は(?:どう(?:なって|でした|ですか)|いつ)/u',
        // 「〇〇に関する」「〇〇に関して」
        '/(.{2,20})に関(?:する|して)/u',
        // 「〇〇のこと」
        '/(.{2,15})のこと(?:を|について|教えて|知りたい)/u',
        // 「〇〇って何」「〇〇とは」
        '/(.{2,15})(?:って何|とは)/u',
    ];

    foreach ($topicPatterns as $pat) {
        if (preg_match($pat, $q, $m)) {
            $topic = trim($m[1]);
            // 不要な接頭語を除去
            $topic = preg_replace('/^(?:最近の|今の|この|その|あの|過去の)/u', '', $topic);
            $topic = trim($topic);
            if (mb_strlen($topic) >= 2 && mb_strlen($topic) <= 20) {
                return normalizeSearchKeyword($topic);
            }
        }
    }

    return null;
}

/**
 * メッセージのみをキーワードで検索（AI秘書のコンテキスト用）
 * タスク・メモの検索カウント制限とは独立して動作
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $keyword
 * @param int $limit
 * @return array ['messages' => array, 'summary' => string]
 */
function searchMessagesForContext(PDO $pdo, int $user_id, string $keyword, int $limit = 15): array {
    $messages = [];
    $userConvIds = [];

    try {
        $convStmt = $pdo->prepare("
            SELECT conversation_id FROM conversation_members 
            WHERE user_id = ? AND left_at IS NULL
        ");
        $convStmt->execute([$user_id]);
        $userConvIds = $convStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($userConvIds)) {
            $placeholders = implode(',', array_fill(0, count($userConvIds), '?'));
            $sql = "
                SELECT m.id, m.content, m.extracted_text, m.created_at, m.conversation_id,
                       u.display_name as sender_name, c.name as conversation_name
                FROM messages m
                INNER JOIN users u ON m.sender_id = u.id
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE m.conversation_id IN ({$placeholders})
                AND m.deleted_at IS NULL
                AND (m.content LIKE ? OR m.extracted_text LIKE ?)
                ORDER BY m.created_at DESC LIMIT " . (int)$limit;
            $params = array_merge($userConvIds, ["%{$keyword}%", "%{$keyword}%"]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'extracted_text') !== false && !empty($userConvIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($userConvIds), '?'));
                $sql = "
                    SELECT m.id, m.content, m.created_at, m.conversation_id,
                           u.display_name as sender_name, c.name as conversation_name
                    FROM messages m
                    INNER JOIN users u ON m.sender_id = u.id
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    WHERE m.conversation_id IN ({$placeholders})
                    AND m.deleted_at IS NULL
                    AND m.content LIKE ?
                    ORDER BY m.created_at DESC LIMIT " . (int)$limit;
                $params = array_merge($userConvIds, ["%{$keyword}%"]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                error_log('[searchMessagesForContext] fallback error: ' . $e2->getMessage());
            }
        } else {
            error_log('[searchMessagesForContext] error: ' . $e->getMessage());
        }
    }

    // コンテキスト用サマリーを生成（キーワード全出現箇所を抽出）
    $summary = '';
    if (!empty($messages)) {
        $lines = [];
        $lines[] = "【メッセージ検索結果: 「{$keyword}」に関連する " . count($messages) . "件のメッセージ】";
        foreach ($messages as $i => $msg) {
            if ($i >= 10) {
                $lines[] = "... 他 " . (count($messages) - 10) . "件";
                break;
            }
            $date = date('Y/m/d', strtotime($msg['created_at']));
            $sender = $msg['sender_name'] ?? '不明';
            $conv = $msg['conversation_name'] ?? '';

            if (!empty($msg['extracted_text'])) {
                $occurrences = extractAllKeywordOccurrencesWithContext($msg['extracted_text'], $keyword, 150, 20);
                if (!empty($occurrences)) {
                    $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}（キーワード「{$keyword}」が" . count($occurrences) . "箇所）";
                    foreach ($occurrences as $j => $oc) {
                        $oc = preg_replace('/[\r\n]+/', ' ', $oc);
                        $lines[] = "    【" . ($j + 1) . "/" . count($occurrences) . "】 " . $oc;
                    }
                } else {
                    $text = preg_replace('/[\r\n]+/', ' ', mb_substr($msg['extracted_text'], 0, 200));
                    $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}: {$text}";
                }
            } else {
                $text = preg_replace('/[\r\n]+/', ' ', mb_substr($msg['content'], 0, 150));
                $lines[] = ($i + 1) . ". [{$date}] {$sender}@{$conv}: {$text}";
            }
        }
        $summary = implode("\n", $lines);
    }

    return ['messages' => $messages, 'summary' => $summary, 'keyword' => $keyword];
}
