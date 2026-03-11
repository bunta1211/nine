<?php
/**
 * 2人用DM（グループチャット）の作成・取得
 * アドレス帳承認時や「DM」開始時に、お互いのチャット一覧に表示される会話を確保する。
 * 依存: config/database.php, config/app.php
 */

/**
 * 2人のユーザー間のDM用グループチャットを取得または作成する
 * 既に同じ2人だけのグループがあればそのIDを返し、なければ新規作成する。
 *
 * @param PDO $pdo
 * @param int $user_id_a 1人目のユーザーID
 * @param int $user_id_b 2人目のユーザーID
 * @return array{conversation_id: int, is_new: bool}|null 失敗時は null
 */
function create_or_get_dm_between(PDO $pdo, $user_id_a, $user_id_b) {
    $user_id_a = (int) $user_id_a;
    $user_id_b = (int) $user_id_b;
    if ($user_id_a < 1 || $user_id_b < 1 || $user_id_a === $user_id_b) {
        return null;
    }

    // 既存の2人チャットを検索
    $stmt = $pdo->prepare("
        SELECT c.id
        FROM conversations c
        INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ? AND cm1.left_at IS NULL
        INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ? AND cm2.left_at IS NULL
        WHERE c.type = 'group'
        AND (SELECT COUNT(*) FROM conversation_members cm3 WHERE cm3.conversation_id = c.id AND cm3.left_at IS NULL) = 2
        LIMIT 1
    ");
    $stmt->execute([$user_id_a, $user_id_b]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return [
            'conversation_id' => (int) $existing['id'],
            'is_new' => false,
        ];
    }

    // チャット名用に相手の表示名を取得（user_id_b の名前をチャット名にする）
    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
    $stmt->execute([$user_id_b]);
    $other = $stmt->fetch(PDO::FETCH_ASSOC);
    $groupName = $other && trim($other['display_name'] ?? '') !== '' ? trim($other['display_name']) : 'チャット';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO conversations (type, name, created_by, created_at, updated_at)
            VALUES ('group', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$groupName, $user_id_a]);
        $conversation_id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
            VALUES (?, ?, 'admin', NOW())
        ");
        $stmt->execute([$conversation_id, $user_id_a]);
        $stmt->execute([$conversation_id, $user_id_b]);

        // システムメッセージ（誰がチャットを開始したか）
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $stmt->execute([$user_id_a]);
        $starter = $stmt->fetch(PDO::FETCH_ASSOC);
        $starterName = $starter && trim($starter['display_name'] ?? '') !== '' ? trim($starter['display_name']) : 'ユーザー';
        $systemMessage = $starterName . ' さんがチャットを開始しました';
        $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
            VALUES (?, ?, ?, 'system', NOW())
        ")->execute([$conversation_id, $user_id_a, $systemMessage]);

        $pdo->commit();
        return [
            'conversation_id' => $conversation_id,
            'is_new' => true,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return null;
    }
}
