<?php
/**
 * 絵文字使用頻度の記録・取得（AI秘書の絵文字学習）
 * メッセージ送信時に recordEmojiUsage、AI応答時に getTopEmojis を利用する
 */

/**
 * テキストから絵文字を抽出（Unicode 絵文字ブロック）
 * @param string $text
 * @return string[] 重複あり（出現回数分）
 */
function extractEmojisFromText($text) {
    if (!is_string($text) || $text === '') return [];
    $pattern = '/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}]/u';
    if (!preg_match_all($pattern, $text, $m)) return [];
    return $m[0];
}

/**
 * ユーザーが送信したテキスト内の絵文字を集計して保存
 * @param PDO $pdo
 * @param int $user_id
 * @param string $content メッセージ本文
 */
function recordEmojiUsage(PDO $pdo, $user_id, $content) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;
    $emojis = extractEmojisFromText($content);
    if (empty($emojis)) return;
    $counts = array_count_values($emojis);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_emoji_usage (user_id, emoji_char, cnt, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt), updated_at = NOW()
        ");
        foreach ($counts as $char => $n) {
            $char = mb_substr($char, 0, 20);
            if ($char !== '' && $n > 0) $stmt->execute([$user_id, $char, (int)$n]);
        }
    } catch (Throwable $e) {
        error_log('recordEmojiUsage: ' . $e->getMessage());
    }
}

/**
 * ユーザーがよく使う絵文字を取得（上位 N 件）
 * @param PDO $pdo
 * @param int $user_id
 * @param int $limit
 * @return string[] 絵文字の配列
 */
function getTopEmojis(PDO $pdo, $user_id, $limit = 10) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT emoji_char FROM user_emoji_usage
            WHERE user_id = ?
            ORDER BY cnt DESC
            LIMIT " . (int) $limit
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('getTopEmojis: ' . $e->getMessage());
        return [];
    }
}
