<?php
/**
 * メッセージAPI
 * 仕様書: 05_チャット機能.md
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

// messages.php 固有の追加読み込み
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (!class_exists('TCPDF')) {
    $tcpdfPath = __DIR__ . '/../includes/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;
    }
}
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/ai_wish_extractor.php';
require_once __DIR__ . '/../includes/push_helper.php';
require_once __DIR__ . '/../includes/pdf_helper.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

/** message_mentions に mention_type が無い環境で To 保存が失敗しないよう、カラムが無ければ追加する */
function ensureMessageMentionsMentionType(PDO $pdo) {
    static $done = false;
    if ($done) return;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
        if ($c && $c->rowCount() > 0) { $done = true; return; }
        $pdo->exec("ALTER TABLE message_mentions ADD COLUMN mention_type ENUM('text','to','to_all') DEFAULT 'text' AFTER mentioned_user_id");
    } catch (Exception $e) {
        // 権限不足や既存カラムなどで失敗する場合は無視
    }
    $done = true;
}

$method = $_SERVER['REQUEST_METHOD'];
// JSON または multipart の両方に対応（multipart 時は php://input が空のため $_POST を使用）
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'send':
        // メッセージを送信
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $content = trim($input['content'] ?? '');
        $message_type = $input['message_type'] ?? 'text';
        $reply_to_id = $input['reply_to_id'] ?? $input['reply_to'] ?? $input['reply_to_message_id'] ?? null;
        $reply_to_id = ($reply_to_id !== null && $reply_to_id !== '') ? (int)$reply_to_id : null;
        if ($reply_to_id !== null && $reply_to_id <= 0) {
            $reply_to_id = null;
        }
        // To機能一時削除（Phase B）: mention_ids は受け取らない
        $mention_ids = [];
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        if (empty($content) && $message_type === 'text') {
            errorResponse('メッセージを入力してください');
        }
        
        // 長文上限（Chatwork等からの大量ペーストでサーバーエラー・DBエラーを防ぐ）
        $contentLen = mb_strlen($content);
        $maxContentChars = 200000000; // 200M（2億）文字
        if ($message_type === 'text' && $contentLen > $maxContentChars) {
            errorResponse('メッセージは2億文字までです。長いログは分割して送信してください。', 400);
        }
        
        // 長文テキストモード: バイト長>65KB または 文字数>10万 の場合は PDF にせず、content=ラベル・extracted_text=全文で1件INSERT
        $longTextMode = false;
        $extractedText = null;
        if ($message_type === 'text' && $contentLen >= 1000) {
            $contentBytes = strlen($content);
            if ($contentBytes > 65000 || $contentLen > 100000) {
                $longTextMode = true;
                $fullContent = $content;
                $extractedText = $fullContent;
                $content = ''; // 組織名取得後にラベルを組み立てる
            }
        }
        
        // 1000文字以上の長文はPDFに変換（長文テキストモードでない場合のみ）。15万文字超は15万文字ごとに分割して複数PDF化する
        $pdfConverted = false;
        $pdfError = null;
        $contentParts = null;   // 分割時: 各PDFの content 文字列の配列
        $extractedParts = null; // 分割時: 各PDFの extracted_text の配列
        if ($message_type === 'text' && $contentLen >= 1000 && !$longTextMode) {
            $uploadDir = __DIR__ . '/../uploads/messages/';
            $maxPdfChars = 150000; // 1PDFあたりの上限
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $baseDisplayName = '長文_' . date('Y-m-d_Hi');
            if ($contentLen <= $maxPdfChars) {
                // 1件で収まる場合
                $extractedText = $content;
                try {
                    $result = textToPdf($content, $uploadDir, $baseDisplayName);
                    if ($result && !empty($result['path'])) {
                        $content = '📄 ' . $baseDisplayName . '.pdf' . "\n" . $result['path'];
                        $pdfConverted = true;
                    } else {
                        error_log('[PDF] textToPdf failed. content_len=' . $contentLen);
                        $extractedText = null;
                    }
                } catch (Throwable $e) {
                    error_log('[PDF] textToPdf exception: ' . $e->getMessage());
                    $extractedText = null;
                    if ($contentLen > 10000) {
                        errorResponse('長文の処理中にエラーが発生しました。15万文字以内に分割して送信してください。', 400);
                    }
                }
            } else {
                // 15万文字超: 15万文字ごとに分割してPDF化
                $chunks = [];
                for ($offset = 0; $offset < $contentLen; $offset += $maxPdfChars) {
                    $chunks[] = mb_substr($content, $offset, $maxPdfChars, 'UTF-8');
                }
                $contentParts = [];
                $extractedParts = [];
                try {
                    foreach ($chunks as $idx => $chunk) {
                        $oneBased = $idx + 1;
                        $displayName = $baseDisplayName . '_' . $oneBased . '.pdf';
                        $result = textToPdf($chunk, $uploadDir, pathinfo($displayName, PATHINFO_FILENAME));
                        if (!$result || empty($result['path'])) {
                            error_log('[PDF] textToPdf failed for chunk ' . $oneBased);
                            errorResponse('長文のPDF変換の一部に失敗しました。しばらく経ってから再送信してください。', 400);
                        }
                        $contentParts[] = '📄 ' . $displayName . "\n" . $result['path'];
                        $extractedParts[] = $chunk;
                    }
                    $pdfConverted = true;
                } catch (Throwable $e) {
                    error_log('[PDF] split PDF exception: ' . $e->getMessage());
                    errorResponse('長文の処理中にエラーが発生しました。しばらく経ってから再送信してください。', 400);
                }
            }
        }
        
        // DBの content は TEXT（約64KB）。PDF変換せず長文のままの場合は上限で弾く（長文テキストモードの場合はラベルにしているのでスキップ）
        $maxContentBytes = 65000; // TEXT の安全マージン
        if ($message_type === 'text' && !$pdfConverted && !$longTextMode && strlen($content) > $maxContentBytes) {
            errorResponse('文字数が多すぎます（長文はPDFに自動変換されますが、変換に失敗した場合は約6万5千文字以内に分割して送信してください）。');
        }
        
        // アップロードPDFからテキスト抽出（ユーザーがPDFファイルを添付した場合）
        if (!$extractedText && preg_match('/uploads\/messages\/[^\s\n]+\.pdf/i', $content)) {
            $extractedText = extractPdfText($content);
        }
        
        // 会話メンバーか確認（left_at が無い環境では conversation_id + user_id のみで判定）
        $sendCmLeftAt = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $sendCmLeftAt = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        $sendMemberSql = "SELECT c.*, cm.role FROM conversations c
            INNER JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE c.id = ? AND cm.user_id = ?" . ($sendCmLeftAt ? " AND cm.left_at IS NULL" : "");
        $stmt = $pdo->prepare($sendMemberSql);
        $stmt->execute([$conversation_id, $user_id]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            errorResponse('会話が見つかりません', 404);
        }
        
        // 長文テキストモード: 組織名入りラベルを組み立て（「検索・〇〇 AI学習用に保存されています」）
        if ($longTextMode && !empty($fullContent)) {
            $orgLabel = 'AI学習用';
            if (!empty($conversation['organization_id'])) {
                try {
                    $oStmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
                    $oStmt->execute([(int)$conversation['organization_id']]);
                    $org = $oStmt->fetch(PDO::FETCH_ASSOC);
                    if ($org && !empty(trim((string)($org['name'] ?? '')))) {
                        $orgLabel = trim($org['name']) . ' AI学習用';
                    }
                } catch (Exception $e) {}
            }
            $labelLen = min(500, mb_strlen($fullContent));
            $content = mb_substr($fullContent, 0, $labelLen, 'UTF-8') . "\n\n…（全文 " . number_format($contentLen) . " 文字は検索・" . $orgLabel . "に保存されています）";
            if (strlen($content) > 65000) {
                $content = mb_substr($content, 0, 30000, 'UTF-8') . "\n\n…（全文 " . number_format($contentLen) . " 文字は検索・" . $orgLabel . "に保存されています）";
            }
        }
        
        // TO選択からのメンション（後続の message_mentions 保存で使用）
        $toMentionAll = in_array('all', $mention_ids);
        $toMentionIds = array_filter($mention_ids, function($id) { return $id !== 'all'; });
        
        // TO選択がある場合、本文の先頭に [To:ID] 行を付与（表示が確実に残るようにする）
        $contentForTo = ($contentParts !== null) ? $contentParts[0] : $content;
        if (($toMentionAll || !empty($toMentionIds)) && !preg_match('/^\\s*\[To:/i', $contentForTo)) {
            $toLines = [];
            if ($toMentionAll) {
                $toLines[] = '[To:all]全員';
            } else {
                $idsForPrefix = array_values(array_filter(array_map('intval', $toMentionIds), function($id) { return $id > 0; }));
                if (!empty($idsForPrefix)) {
                    $placeholders = implode(',', array_fill(0, count($idsForPrefix), '?'));
                    $stmtNames = $pdo->prepare("SELECT id, COALESCE(NULLIF(TRIM(display_name), ''), email) as display_name FROM users WHERE id IN ($placeholders)");
                    $stmtNames->execute($idsForPrefix);
                    $namesById = [];
                    while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
                        $namesById[(int)$row['id']] = $row['display_name'] ?? '';
                    }
                    foreach ($idsForPrefix as $uid) {
                        $name = $namesById[$uid] ?? '';
                        $toLines[] = '[To:' . $uid . ']' . $name . 'さん';
                    }
                }
            }
            if (!empty($toLines)) {
                $prefix = implode("\n", $toLines) . "\n";
                if ($contentParts !== null) {
                    $contentParts[0] = $prefix . $contentParts[0];
                } else {
                    $content = $prefix . $content;
                }
            }
        }
        if ($contentParts !== null) {
            $content = $contentParts[0]; // メンション抽出・Phase C 用
        }
        
        // Phase C: 本文に [To:all] または [To:ID] があれば message_mentions に保存するためフラグを立てる
        if ($content !== '' && !$toMentionAll && empty($toMentionIds)) {
            if (preg_match('/\[To:all\]/i', $content)) {
                $toMentionAll = true;
                $toMentionIds = [];
            } elseif (preg_match_all('/\[To:(\d+)\]/', $content, $m)) {
                $toMentionIds = array_values(array_unique(array_map('intval', $m[1])));
            }
        }
        
        // メンションを抽出（テキスト内の@メンション）
        $mentionedUsers = extractMentions($content, $pdo);
        
        // メッセージ種別カラムを検出（content_type のみのテーブルでも挿入できるようにする）
        $sendTypeCol = null;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
            if ($c && $c->rowCount() > 0) {
                $sendTypeCol = 'message_type';
            } else {
                $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'content_type'");
                if ($c && $c->rowCount() > 0) $sendTypeCol = 'content_type';
            }
        } catch (Exception $e) {}
        // reply_to_id カラムの有無（無い環境ではINSERTから除外して送信は成功させる）
        $hasReplyToIdCol = false;
        try {
            $chkReply = $pdo->query("SHOW COLUMNS FROM messages LIKE 'reply_to_id'");
            $hasReplyToIdCol = $chkReply && $chkReply->rowCount() > 0;
        } catch (Exception $e) {}
        // 送信時 reply_to_id の有無をログ（引用が消える問題の調査用・不要なら削除可）
        if ($reply_to_id !== null && (int)$reply_to_id > 0) {
            error_log("[reply_quote] send: reply_to_id=" . (int)$reply_to_id . " hasReplyToIdCol=" . ($hasReplyToIdCol ? '1' : '0'));
        }
        // メッセージを挿入（種別・extracted_text はカラムがある場合のみ。失敗時は最小カラムで再試行）
        $message_id = null;
        $message_ids = []; // 分割送信時は複数
        $insertContent = $content;
        $insertExtracted = $extractedText;
        if ($contentParts !== null) {
            // 15万文字超の分割送信: 各チャンクを1件ずつINSERT
            foreach ($contentParts as $i => $partContent) {
                $partExtracted = $extractedParts[$i] ?? null;
                try {
                    if ($sendTypeCol && $hasReplyToIdCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO messages (conversation_id, sender_id, content, extracted_text, {$sendTypeCol}, reply_to_id, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$conversation_id, $user_id, $partContent, $partExtracted, $message_type, $reply_to_id]);
                    } elseif ($sendTypeCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO messages (conversation_id, sender_id, content, extracted_text, {$sendTypeCol}, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$conversation_id, $user_id, $partContent, $partExtracted, $message_type]);
                    } elseif ($hasReplyToIdCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO messages (conversation_id, sender_id, content, extracted_text, reply_to_id, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$conversation_id, $user_id, $partContent, $partExtracted, $reply_to_id]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO messages (conversation_id, sender_id, content, extracted_text, created_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$conversation_id, $user_id, $partContent, $partExtracted]);
                    }
                    $message_ids[] = (int)$pdo->lastInsertId();
                } catch (PDOException $e) {
                    $msg = $e->getMessage();
                    if (strpos($msg, 'extracted_text') !== false || ($sendTypeCol && strpos($msg, $sendTypeCol) !== false)) {
                        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)");
                        $stmt->execute([$conversation_id, $user_id, $partContent]);
                        $message_ids[] = (int)$pdo->lastInsertId();
                    } else {
                        throw $e;
                    }
                }
            }
            $message_id = end($message_ids);
        } else {
            try {
                if ($sendTypeCol && $hasReplyToIdCol) {
                    $stmt = $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, extracted_text, {$sendTypeCol}, reply_to_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$conversation_id, $user_id, $insertContent, $insertExtracted, $message_type, $reply_to_id]);
                } elseif ($sendTypeCol) {
                    $stmt = $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, extracted_text, {$sendTypeCol}, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$conversation_id, $user_id, $insertContent, $insertExtracted, $message_type]);
                } elseif ($hasReplyToIdCol) {
                    $stmt = $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, extracted_text, reply_to_id, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$conversation_id, $user_id, $insertContent, $insertExtracted, $reply_to_id]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO messages (conversation_id, sender_id, content, extracted_text, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$conversation_id, $user_id, $insertContent, $insertExtracted]);
                }
                $message_id = $pdo->lastInsertId();
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'extracted_text') !== false || ($sendTypeCol && strpos($msg, $sendTypeCol) !== false)) {
                    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)");
                    $stmt->execute([$conversation_id, $user_id, $insertContent]);
                    $message_id = $pdo->lastInsertId();
                    // 最小INSERTでは reply_to_id が入らないため、あとでフォールバックの UPDATE に任せる（またはここでUPDATE）
                    if ($message_id && $reply_to_id !== null && (int)$reply_to_id > 0) {
                        try {
                            $pdo->prepare("UPDATE messages SET reply_to_id = ? WHERE id = ?")->execute([(int)$reply_to_id, $message_id]);
                        } catch (Exception $ex) {}
                    }
                } else {
                    throw $e;
                }
            }
        }
        if ($message_id && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
            try { $pdo->commit(); } catch (Exception $e) {}
        }
        
        // 会話の最終更新を更新
        $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversation_id]);
        
        // 引用をリロード後も残すため、reply_to_id があれば必ず DB に保存する（INSERT に含まれていなくても UPDATE で上書き）
        if ($message_id && $reply_to_id !== null && (int)$reply_to_id > 0) {
            try {
                $mid = (int)$message_id;
                $rid = (int)$reply_to_id;
                $pdo->prepare("UPDATE messages SET reply_to_id = ? WHERE id = ?")->execute([$rid, $mid]);
            } catch (Exception $e) {
                // カラムが無い環境では UPDATE が失敗するが送信は成功させる
                error_log("[reply_quote] persist reply_to_id failed (column may be missing): " . $e->getMessage());
            }
        }
        
        // 絵文字学習：送信テキストから絵文字を集計（AI秘書の応答でよく使う絵文字を参照するため）
        if ($message_type === 'text') {
            $textForEmoji = $extractedText !== null ? $extractedText : $content;
            if ($textForEmoji !== '' && file_exists(__DIR__ . '/../includes/emoji_usage_helper.php')) {
                require_once __DIR__ . '/../includes/emoji_usage_helper.php';
                if (function_exists('recordEmojiUsage')) recordEmojiUsage($pdo, $user_id, $textForEmoji);
            }
            // 分割送信時は各チャンク分も記録
            if ($extractedParts !== null) {
                foreach ($extractedParts as $idx => $part) {
                    if ($idx === 0) continue; // 先頭は上で記録済み
                    if ($part !== '' && function_exists('recordEmojiUsage')) recordEmojiUsage($pdo, $user_id, $part);
                }
            }
        }
        
        // ※ 文章から自動でタスク（Wish）に登録する機能は削除済み（手動登録・api/wish_extractor.php の手動抽出は利用可能）
        
        // 送信者情報を取得
        $user_stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $sender = $user_stmt->fetch();
        
        // message_mentions に mention_type が無い環境では To 保存が失敗するため、カラムを追加してから保存する
        ensureMessageMentionsMentionType($pdo);
        $hasMentionTypeCol = false;
        try {
            $chkMt = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
            $hasMentionTypeCol = $chkMt && $chkMt->rowCount() > 0;
        } catch (Exception $e) {}
        // 分割送信時はメンション・To は先頭メッセージにのみ紐づける
        $mentionMessageId = ($contentParts !== null && !empty($message_ids)) ? $message_ids[0] : $message_id;
        
        // メンションを message_mentions テーブルに保存（@メンションから）
        if (!empty($mentionedUsers)) {
            $mmCols = $hasMentionTypeCol ? "message_id, mentioned_user_id, mention_type, created_at" : "message_id, mentioned_user_id, created_at";
            $mmVals = $hasMentionTypeCol ? "?, ?, 'text', NOW()" : "?, ?, NOW()";
            foreach ($mentionedUsers as $mentioned) {
                $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmCols}) VALUES ({$mmVals})")
                    ->execute([$mentionMessageId, $mentioned['id']]);
                
                // 通知
                if ($mentioned['id'] != $user_id) {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                        VALUES (?, 'mention', '@メンション', ?, 'message', ?)
                    ")->execute([
                        $mentioned['id'],
                        $sender['display_name'] . 'さんがあなたをメンションしました',
                        $mentionMessageId
                    ]);
                }
            }
        }
        
        // Phase C: 本文から [To:all] / [To:ID] を抽出して message_mentions に保存（mention_ids は使わない）
        $toMentionAll = (bool) preg_match('/\[To:all\]/i', $content);
        $toMentionIds = [];
        if (!$toMentionAll && preg_match_all('/\[To:(\d+)\]/', $content, $toMatches)) {
            $toMentionIds = array_values(array_unique(array_filter(array_map('intval', $toMatches[1]), function($id) { return $id > 0; })));
            // ログ貼り付け等で他サービス由来のIDが含まれる場合に備え、users に存在するIDのみに限定（通知の外部キーエラー防止）
            if (!empty($toMentionIds)) {
                $ph = implode(',', array_fill(0, count($toMentionIds), '?'));
                $stmtU = $pdo->prepare("SELECT id FROM users WHERE id IN ($ph)");
                $stmtU->execute($toMentionIds);
                $toMentionIds = array_map('intval', $stmtU->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        error_log('[TO_DEBUG] send msg_id=' . $mentionMessageId . ' toMentionAll=' . ($toMentionAll ? 'true' : 'false') . ' toMentionIds=' . json_encode($toMentionIds) . ' hasMentionTypeCol=' . ($hasMentionTypeCol ? 'true' : 'false') . ' content_first100=' . mb_substr($content, 0, 100));
        
        // TO（本文の [To:...]）を message_mentions に保存
        $mmColsTo = $hasMentionTypeCol ? "message_id, mentioned_user_id, mention_type, created_at" : "message_id, mentioned_user_id, created_at";
        if ($toMentionAll) {
            // 全員をメンション（グループメンバー全員）
            $cmLeftClause = $sendCmLeftAt ? " AND left_at IS NULL" : "";
            $stmt = $pdo->prepare("
                SELECT user_id FROM conversation_members 
                WHERE conversation_id = ? AND user_id != ?{$cmLeftClause}
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $allMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $mmValsAll = $hasMentionTypeCol ? "?, ?, 'to_all', NOW()" : "?, ?, NOW()";
            foreach ($allMembers as $memberId) {
                $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmColsTo}) VALUES ({$mmValsAll})")
                    ->execute([$mentionMessageId, $memberId]);
                
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                    VALUES (?, 'mention', '全員宛メッセージ', ?, 'message', ?)
                ")->execute([
                    $memberId,
                    $sender['display_name'] . 'さんが全員宛にメッセージを送信しました',
                    $mentionMessageId
                ]);
            }
        } elseif (!empty($toMentionIds)) {
            $mmValsTo = $hasMentionTypeCol ? "?, ?, 'to', NOW()" : "?, ?, NOW()";
            foreach ($toMentionIds as $mentionId) {
                $mentionId = (int)$mentionId;
                if ($mentionId === $user_id) continue; // 自分は除外
                
                $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmColsTo}) VALUES ({$mmValsTo})")
                    ->execute([$mentionMessageId, $mentionId]);
                
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                    VALUES (?, 'mention', 'あなた宛のメッセージ', ?, 'message', ?)
                ")->execute([
                    $mentionId,
                    $sender['display_name'] . 'さんがあなた宛にメッセージを送信しました',
                    $mentionMessageId
                ]);
            }
        }
        
        // 保存確認: message_mentions に実際に保存されたか検証
        try {
            $chkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM message_mentions WHERE message_id = ?");
            $chkStmt->execute([$mentionMessageId]);
            $mmCount = $chkStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            error_log('[TO_DEBUG] message_mentions saved count=' . $mmCount . ' for msg_id=' . $mentionMessageId);
        } catch (Exception $e) {
            error_log('[TO_DEBUG] message_mentions check failed: ' . $e->getMessage());
        }
        
        // プッシュ通知を送信（非同期で行うべきだが、シンプルのため同期）
        try {
            $isDM = ($conversation['type'] === 'dm');
            $conversationName = $conversation['name'] ?? '';
            
            // メンションされたユーザーIDを収集
            $pushTargetIds = [];
            if ($toMentionAll) {
                $pushTargetIds = $allMembers ?? [];
            } elseif (!empty($toMentionIds)) {
                $pushTargetIds = array_map('intval', $toMentionIds);
            } elseif (!empty($mentionedUsers)) {
                $pushTargetIds = array_column($mentionedUsers, 'id');
            }
            
            triggerMessagePushNotification(
                $pdo,
                $conversation_id,
                $user_id,
                $sender['display_name'],
                $content,
                $conversationName,
                $pushTargetIds,
                $isDM
            );
        } catch (Exception $e) {
            // プッシュ通知の失敗はメッセージ送信には影響させない
            error_log('Push notification error: ' . $e->getMessage());
        }
        
        // 送信したメッセージの完全な情報を取得して返す（返信情報も含む）
        // reply_to_id カラムが無い環境では JOIN しない SELECT を使う（カラム追加前に送信が失敗しないように）
        if ($hasReplyToIdCol) {
            $msgStmt = $pdo->prepare("
                SELECT 
                    m.id,
                    m.conversation_id,
                    m.sender_id,
                    m.content,
                    m.message_type,
                    m.reply_to_id,
                    m.created_at,
                    m.is_edited,
                    u.display_name AS sender_name,
                    u.avatar_path AS sender_avatar,
                    rm.content AS reply_to_content,
                    ru.display_name AS reply_to_sender_name
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN messages rm ON m.reply_to_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                WHERE m.id = ?
            ");
        } else {
            $msgStmt = $pdo->prepare("
                SELECT 
                    m.id,
                    m.conversation_id,
                    m.sender_id,
                    m.content,
                    m.message_type,
                    m.created_at,
                    m.is_edited,
                    u.display_name AS sender_name,
                    u.avatar_path AS sender_avatar
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.id = ?
            ");
        }
        $messagesForResponse = !empty($message_ids) ? $message_ids : [$message_id];
        $message = null;
        $allMessages = [];
        foreach ($messagesForResponse as $mid) {
            $msgStmt->execute([$mid]);
            $row = $msgStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['id'] = (int)$row['id'];
                $row['conversation_id'] = (int)$row['conversation_id'];
                $row['sender_id'] = (int)$row['sender_id'];
                $row['is_edited'] = (int)$row['is_edited'];
                $row['message_type'] = $row['message_type'] ?? 'text';
                // 返信情報は常にクライアント用に整える（カラム無し時は未設定なので null に揃える）
                $row['reply_to_id'] = isset($row['reply_to_id']) && $row['reply_to_id'] !== '' && (int)$row['reply_to_id'] > 0 ? (int)$row['reply_to_id'] : null;
                $row['reply_to_content'] = isset($row['reply_to_content']) ? $row['reply_to_content'] : null;
                $row['reply_to_sender_name'] = isset($row['reply_to_sender_name']) ? $row['reply_to_sender_name'] : null;
                if (!empty($row['created_at']) && function_exists('formatDatetimeForClient')) {
                    $row['created_at'] = formatDatetimeForClient($row['created_at']);
                }
                $row['is_mentioned_me'] = false;
                $row['mention_type'] = null;
                $row['to_member_ids'] = [];
                $row['to_member_ids_list'] = [];
                // To 情報は先頭メッセージのみ
                if ($mid === $mentionMessageId) {
                    if ($toMentionAll) {
                        $row['has_to_all'] = true;
                        $row['show_to_all_badge'] = true;
                    }
                    if (!empty($toMentionIds)) {
                        $row['to_member_ids'] = array_map('intval', $toMentionIds);
                        $row['show_to_badge'] = true;
                        $row['to_member_ids_list'] = array_values(array_unique(array_map('intval', $toMentionIds)));
                    }
                }
                $allMessages[] = $row;
                $message = $row;
            }
        }
        // 本番で INSERT に reply_to_id が含まれていない古いコードでもレスポンスで引用を返すため、
        // DB の reply_to_id が null のときはリクエストの reply_to_id で返信元を取得して補完する。
        // あわせて DB を UPDATE しておけばリロード後も引用が表示される。
        if ($message && (empty($message['reply_to_id']) || $message['reply_to_id'] === null) && !empty($reply_to_id) && (int)$reply_to_id > 0) {
            $reply_to_id_int = (int)$reply_to_id;
            try {
                $refStmt = $pdo->prepare("SELECT m.content, u.display_name FROM messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
                $refStmt->execute([$reply_to_id_int]);
                $ref = $refStmt->fetch(PDO::FETCH_ASSOC);
                if ($ref) {
                    $message['reply_to_id'] = $reply_to_id_int;
                    $message['reply_to_content'] = $ref['content'] ?? null;
                    $message['reply_to_sender_name'] = $ref['display_name'] ?? null;
                } else {
                    $message['reply_to_id'] = $reply_to_id_int;
                    $message['reply_to_content'] = null;
                    $message['reply_to_sender_name'] = null;
                }
                // リロード後も引用を表示するため、DB に reply_to_id を必ず保存する（参照元が削除されていてもIDだけ残す）
                $upd = $pdo->prepare("UPDATE messages SET reply_to_id = ? WHERE id = ?");
                $upd->execute([$reply_to_id_int, $message_id]);
            } catch (Exception $e) {
                error_log("[reply_quote] fallback or UPDATE failed: message_id=" . ($message_id ?? '') . " reply_to_id=" . ($reply_to_id_int ?? '') . " err=" . $e->getMessage());
            }
        }
        if (count($allMessages) > 1) {
            $response = [
                'message_id' => (int)$message_id,
                'message' => $message,
                'messages' => $allMessages
            ];
        } else {
            $response = [
                'message_id' => (int)$message_id,
                'message' => $message
            ];
        }
        if ($pdfConverted) {
            $response['pdf_converted'] = true;
        }
        if ($pdfError) {
            $response['pdf_converted'] = false;
            $response['pdf_error'] = $pdfError;
        }
        successResponse($response);
        break;
        
    case 'poll':
        // ポーリング用。last_id を after_id に合わせて get と同一処理
        $_GET['after_id'] = (int)($_GET['last_id'] ?? $_GET['after_id'] ?? 0);
        $_GET['action'] = 'get';
        // fall through
    case 'get':
        // リロード時に必ず最新を取得するためキャッシュさせない
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        // メッセージを取得
        $conversation_id = (int)($_GET['conversation_id'] ?? 0);
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $before_id = (int)($_GET['before_id'] ?? 0);
        $after_id = (int)($_GET['after_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 会話メンバーか確認（left_at が無い環境では conversation_id + user_id のみで判定）
        $hasCmLeftAt = false;
        $memberCheckSql = "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?";
        try {
            $chkLeft = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $hasCmLeftAt = $chkLeft && $chkLeft->rowCount() > 0;
            if ($hasCmLeftAt) {
                $memberCheckSql .= " AND left_at IS NULL";
            }
        } catch (Exception $e) {}
        $stmt = $pdo->prepare($memberCheckSql);
        $stmt->execute([$conversation_id, $user_id]);
        if (!$stmt->fetch()) {
            errorResponse('この会話にアクセスする権限がありません', 403);
        }
        
        try {
        $hasTaskId = false;
        $hasNotifMsgCol = false;
        $hasMessageType = false;
        $messageTypeColName = null;
        $hasIsEdited = true;
        $hasIsPinned = true;
        $hasDeletedAt = true;
        $hasIsDeleted = false;
        $hasReactionsTable = true;
        $hasReplyToId = true;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'task_id'");
            $hasTaskId = $chk && $chk->rowCount() > 0;
            $chk2 = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'notification_message_id'");
            $hasNotifMsgCol = $chk2 && $chk2->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
            $hasMessageType = $chk && $chk->rowCount() > 0;
            if ($hasMessageType) {
                $messageTypeColName = 'message_type';
            } else {
                $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'content_type'");
                $hasMessageType = $chk && $chk->rowCount() > 0;
                $messageTypeColName = $hasMessageType ? 'content_type' : null;
            }
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_edited'");
            $hasIsEdited = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_pinned'");
            $hasIsPinned = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
            $hasDeletedAt = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
            $hasIsDeleted = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW TABLES LIKE 'message_reactions'");
            $hasReactionsTable = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'reply_to_id'");
            $hasReplyToId = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        
        $taskIdCol = $hasTaskId ? ", m.task_id" : "";
        // task_idがなくてもnotification_message_idからタスクを逆引き可能（削除済みタスクは除外）
        $taskDelJoin = '';
        try {
            $chkDel = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
            if ($chkDel && $chkDel->rowCount() > 0) $taskDelJoin = ' AND t_msg.deleted_at IS NULL';
        } catch (Exception $e) {}
        $taskJoin = ($hasNotifMsgCol) ? "
            LEFT JOIN tasks t_msg ON t_msg.notification_message_id = m.id {$taskDelJoin}
            LEFT JOIN users creator ON t_msg.created_by = creator.id
            LEFT JOIN users worker ON t_msg.assigned_to = worker.id" : "";
        $taskCols = ($hasNotifMsgCol) ? ",
            t_msg.id as task_id_from_join,
            t_msg.created_by as task_requester_id,
            t_msg.assigned_to as task_worker_id,
            t_msg.title as task_title,
            t_msg.due_date as task_due_date,
            creator.display_name as task_requester_name,
            worker.display_name as task_worker_name" : "";
        $msgTypeCol = ($hasMessageType && $messageTypeColName) ? "m.{$messageTypeColName} AS message_type," : "'text' AS message_type,";
        $isEditedCol = $hasIsEdited ? "m.is_edited," : "0 AS is_edited,";
        $isPinnedCol = $hasIsPinned ? "m.is_pinned," : "0 AS is_pinned,";
        $deletedAtClause = ($hasDeletedAt ? " AND m.deleted_at IS NULL" : "") . ($hasIsDeleted ? " AND (m.is_deleted = 0 OR m.is_deleted IS NULL)" : "");
        $reactionsCol = $hasReactionsTable ? "(SELECT GROUP_CONCAT(DISTINCT reaction_type) FROM message_reactions WHERE message_id = m.id) as reactions" : "NULL as reactions";
        $replyToIdCol = $hasReplyToId ? "m.reply_to_id," : "NULL AS reply_to_id,";
        $replyToJoin = $hasReplyToId ? "
            LEFT JOIN messages rm ON m.reply_to_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id" : "
            LEFT JOIN messages rm ON 1=0
            LEFT JOIN users ru ON 1=0";
        $sql = "
            SELECT 
                m.id,
                m.conversation_id,
                m.sender_id,
                m.content,
                {$msgTypeCol}
                {$replyToIdCol}
                {$isEditedCol}
                {$isPinnedCol}
                m.created_at
                {$taskIdCol}
                {$taskCols},
                u.display_name as sender_name,
                u.avatar_path as sender_avatar,
                {$reactionsCol},
                rm.content AS reply_to_content,
                ru.display_name AS reply_to_sender_name
            FROM messages m
            INNER JOIN users u ON m.sender_id = u.id
            {$replyToJoin}
            {$taskJoin}
            WHERE m.conversation_id = ?{$deletedAtClause}
        ";
        $params = [$conversation_id];
        
        if ($before_id) {
            $sql .= " AND m.id < ?";
            $params[] = $before_id;
        } elseif ($after_id) {
            $sql .= " AND m.id > ?";
            $params[] = $after_id;
        }
        
        $sql .= " ORDER BY m.id DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        
        // メッセージIDを収集
        $messageIds = array_column($messages, 'id');
        
        // リアクション詳細を一括取得（message_reactions がある場合のみ）
        $reactionDetails = [];
        if (!empty($messageIds) && $hasReactionsTable) {
            try {
                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT mr.message_id, mr.reaction_type, mr.user_id,
                           COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) as display_name
                    FROM message_reactions mr
                    LEFT JOIN users u ON mr.user_id = u.id
                    WHERE mr.message_id IN ($placeholders)
                ");
                $stmt->execute($messageIds);
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $msgId = (int)$r['message_id'];
                    if (!isset($reactionDetails[$msgId])) {
                        $reactionDetails[$msgId] = [];
                    }
                    $type = $r['reaction_type'];
                    $uid = (int)$r['user_id'];
                    $name = $r['display_name'] ?? (string)$uid;
                    $key = null;
                    foreach ($reactionDetails[$msgId] as $i => $detail) {
                        if ($detail['type'] === $type) {
                            $key = $i;
                            break;
                        }
                    }
                    if ($key !== null) {
                        $reactionDetails[$msgId][$key]['count']++;
                        $reactionDetails[$msgId][$key]['users'][] = ['id' => $uid, 'name' => $name];
                        if ($uid == $user_id) {
                            $reactionDetails[$msgId][$key]['is_mine'] = 1;
                        }
                    } else {
                        $reactionDetails[$msgId][] = [
                            'type' => $type,
                            'reaction_type' => $type,
                            'count' => 1,
                            'users' => [['id' => $uid, 'name' => $name]],
                            'is_mine' => ($uid == $user_id) ? 1 : 0
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log('[messages.php get] message_reactions details failed: ' . $e->getMessage());
            }
        }
        
        // メンション情報を一括取得（message_mentions が無い環境ではスキップ。mention_type が無い環境でも取得する）
        $mentionDetails = [];
        if (!empty($messageIds)) {
            try {
                $chkMentions = $pdo->query("SHOW TABLES LIKE 'message_mentions'");
                if ($chkMentions && $chkMentions->rowCount() > 0) {
                    $chkMt = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
                    $hasMentionType = $chkMt && $chkMt->rowCount() > 0;
                    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                    $stmt = $pdo->prepare($hasMentionType
                        ? "SELECT message_id, mentioned_user_id, mention_type FROM message_mentions WHERE message_id IN ($placeholders)"
                        : "SELECT message_id, mentioned_user_id FROM message_mentions WHERE message_id IN ($placeholders)"
                    );
                    $stmt->execute($messageIds);
                    $mentions = $stmt->fetchAll();
                    foreach ($mentions as $m) {
                        $msgId = (int)$m['message_id'];
                        if (!isset($mentionDetails[$msgId])) {
                            $mentionDetails[$msgId] = [
                                'is_mentioned_me' => false,
                                'mention_type' => null,
                                'has_to_all' => false,
                                'to_member_ids' => []
                            ];
                        }
                        $mt = $hasMentionType ? ($m['mention_type'] ?? null) : 'to';
                        if ((int)$m['mentioned_user_id'] == $user_id) {
                            $mentionDetails[$msgId]['is_mentioned_me'] = true;
                            $mentionDetails[$msgId]['mention_type'] = $mt;
                        }
                        if ($mt === 'to_all') {
                            $mentionDetails[$msgId]['has_to_all'] = true;
                        }
                        if ($mt === 'to' || $mt === 'to_all') {
                            $mentionDetails[$msgId]['to_member_ids'][] = (int)$m['mentioned_user_id'];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('[messages.php get] message_mentions fetch failed: ' . $e->getMessage());
            }
        }
        
        // task_id付きメッセージのタスク詳細を取得（contentが空の場合のフォールバック用）
        $taskDetails = [];
        $taskIds = array_values(array_unique(array_filter(array_map(function($m) { return isset($m['task_id']) && $m['task_id'] ? (int)$m['task_id'] : null; }, $messages))));
        if (!empty($taskIds) && $hasTaskId) {
            $ph = implode(',', array_fill(0, count($taskIds), '?'));
            try {
                $taskDelClause = '';
                try {
                    $chkDelT = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
                    if ($chkDelT && $chkDelT->rowCount() > 0) $taskDelClause = ' AND t.deleted_at IS NULL';
                } catch (Exception $e) {}
                $stmtTask = $pdo->prepare("
                    SELECT t.id, t.title, t.due_date, t.status,
                        t.created_by as requester_id,
                        t.assigned_to as worker_id,
                        creator.display_name as requester_name,
                        worker.display_name as worker_name
                    FROM tasks t
                    LEFT JOIN users creator ON t.created_by = creator.id
                    LEFT JOIN users worker ON t.assigned_to = worker.id
                    WHERE t.id IN ($ph) {$taskDelClause}
                ");
                $stmtTask->execute($taskIds);
                foreach ($stmtTask->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $r = $t;
                    if (isset($r['requester_id'])) $r['requester_id'] = (int)$r['requester_id'];
                    if (isset($r['worker_id'])) $r['worker_id'] = (int)$r['worker_id'];
                    $taskDetails[(int)$t['id']] = $r;
                }
            } catch (Exception $e) {}
        }
        
        // 数値型を明示的にキャスト
        foreach ($messages as &$msg) {
            $msg['id'] = (int)$msg['id'];
            $msg['conversation_id'] = (int)$msg['conversation_id'];
            $msg['sender_id'] = (int)$msg['sender_id'];
            $msg['is_edited'] = (int)$msg['is_edited'];
            $msg['is_pinned'] = (int)$msg['is_pinned'];
            // 返信情報: 数値でない環境でもクライアント用に統一
            $msg['reply_to_id'] = isset($msg['reply_to_id']) && $msg['reply_to_id'] !== '' && (int)$msg['reply_to_id'] > 0 ? (int)$msg['reply_to_id'] : null;
            // タスク詳細: task_id別取得 または JOIN結果から構築
            $tid = isset($msg['task_id']) && $msg['task_id'] ? (int)$msg['task_id'] : (isset($msg['task_id_from_join']) && $msg['task_id_from_join'] ? (int)$msg['task_id_from_join'] : null);
            if ($tid) {
                $msg['task_id'] = $tid;
                $msg['task_detail'] = $taskDetails[$tid] ?? null;
                if (!$msg['task_detail'] && isset($msg['task_title'])) {
                    $msg['task_detail'] = [
                        'id' => $tid,
                        'title' => $msg['task_title'] ?? '',
                        'due_date' => $msg['task_due_date'] ?? null,
                        'requester_id' => isset($msg['task_requester_id']) ? (int)$msg['task_requester_id'] : null,
                        'worker_id' => isset($msg['task_worker_id']) ? (int)$msg['task_worker_id'] : null,
                        'requester_name' => $msg['task_requester_name'] ?? null,
                        'worker_name' => $msg['task_worker_name'] ?? null
                    ];
                }
                unset($msg['task_id_from_join'], $msg['task_title'], $msg['task_due_date'], $msg['task_requester_id'], $msg['task_requester_name'], $msg['task_worker_id'], $msg['task_worker_name']);
            }
            // 追加フォールバック1: task_idはあるがtask_detailが空の場合、直接タスクを取得
            if (empty($msg['task_detail']) && !empty($msg['task_id'])) {
                try {
                    $tid = (int)$msg['task_id'];
                    if ($tid > 0) {
                        $stmt = $pdo->prepare("
                            SELECT t.id, t.title, t.due_date, t.status,
                                t.created_by as requester_id,
                                t.assigned_to as worker_id,
                                creator.display_name as requester_name,
                                worker.display_name as worker_name
                            FROM tasks t
                            LEFT JOIN users creator ON t.created_by = creator.id
                            LEFT JOIN users worker ON t.assigned_to = worker.id
                            WHERE t.id = ? " . ($taskDelClause ?? '') . "
                        ");
                        $stmt->execute([$tid]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $msg['task_detail'] = [
                                'id' => (int)$row['id'],
                                'title' => $row['title'] ?? '',
                                'due_date' => $row['due_date'] ?? null,
                                'requester_id' => isset($row['requester_id']) ? (int)$row['requester_id'] : null,
                                'worker_id' => isset($row['worker_id']) ? (int)$row['worker_id'] : null,
                                'requester_name' => $row['requester_name'] ?? null,
                                'worker_name' => $row['worker_name'] ?? null
                            ];
                        }
                    }
                } catch (Exception $e) {}
            }
            // 追加フォールバック2: タスクメッセージでtask_detailがない場合、作成者と時刻でタスクを検索
            $content = $msg['content'] ?? '';
            $msgType = $msg['message_type'] ?? '';
            $isTaskMsg = $msgType === 'system' && (strpos($content, '📋') !== false || strpos($content, '✅') !== false || strpos($content, 'タスク') !== false);
            if ($isTaskMsg && empty($msg['task_detail'])) {
                try {
                    $senderId = (int)($msg['sender_id'] ?? 0);
                    $createdAt = $msg['created_at'] ?? '';
                    if ($senderId > 0 && $createdAt) {
                        $stmt = $pdo->prepare("
                            SELECT t.id, t.title, t.due_date, t.status,
                                t.created_by as requester_id,
                                t.assigned_to as worker_id,
                                creator.display_name as requester_name,
                                worker.display_name as worker_name
                            FROM tasks t
                            LEFT JOIN users creator ON t.created_by = creator.id
                            LEFT JOIN users worker ON t.assigned_to = worker.id
                            WHERE t.created_by = ?
                            AND t.created_at BETWEEN DATE_SUB(?, INTERVAL 10 SECOND) AND DATE_ADD(?, INTERVAL 10 SECOND)
                            " . ($taskDelClause ?? '') . "
                            ORDER BY t.id DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$senderId, $createdAt, $createdAt]);
                        $fallbackTask = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($fallbackTask) {
                            $msg['task_id'] = (int)$fallbackTask['id'];
                            $msg['task_detail'] = [
                                'id' => (int)$fallbackTask['id'],
                                'title' => $fallbackTask['title'] ?? '',
                                'due_date' => $fallbackTask['due_date'] ?? null,
                                'requester_id' => isset($fallbackTask['requester_id']) ? (int)$fallbackTask['requester_id'] : null,
                                'worker_id' => isset($fallbackTask['worker_id']) ? (int)$fallbackTask['worker_id'] : null,
                                'requester_name' => $fallbackTask['requester_name'] ?? null,
                                'worker_name' => $fallbackTask['worker_name'] ?? null
                            ];
                        }
                    }
                } catch (Exception $e) {}
            }
            // リアクション詳細を追加
            $msg['reaction_details'] = $reactionDetails[$msg['id']] ?? [];
            
            // メンション情報を追加（誰が送ったメッセージでも宛先を全員分表示するため常に付与）
            $msgMention = $mentionDetails[$msg['id']] ?? null;
            if ($msgMention) {
                $msg['is_mentioned_me'] = $msgMention['is_mentioned_me'];
                $msg['mention_type'] = $msgMention['mention_type'];
                // 送信者が自分の場合は「自分宛」ではなく宛先として表示
                if ((int)$msg['sender_id'] == $user_id) {
                    $msg['is_mentioned_me'] = false;
                }
                // 全メッセージで宛先バッジ用の情報を付与（自分宛以外・他者宛も表示するため）
                if ($msgMention['has_to_all']) {
                    $msg['show_to_all_badge'] = true;
                }
                if (!empty($msgMention['to_member_ids'])) {
                    $msg['show_to_badge'] = true;
                    $msg['to_member_ids_list'] = array_values(array_unique($msgMention['to_member_ids']));
                }
            } else {
                $msg['is_mentioned_me'] = false;
                $msg['mention_type'] = null;
            }
        }
        
        // 翻訳キャッシュを付与（一度翻訳した結果を更新後も残し、APIを無駄に呼ばない）
        $displayLang = null;
        if (function_exists('getCurrentLanguage')) {
            $displayLang = getCurrentLanguage();
        } else {
            try {
                $stmtLang = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(display_language), ''), 'ja') AS lang FROM users WHERE id = ?");
                $stmtLang->execute([$user_id]);
                $row = $stmtLang->fetch(PDO::FETCH_ASSOC);
                $displayLang = $row ? ($row['lang'] ?? 'ja') : 'ja';
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Unknown column') !== false && strpos($e->getMessage(), 'display_language') !== false) {
                    $displayLang = 'ja';
                } else {
                    throw $e;
                }
            }
        }
        $displayLang = $displayLang === 'ja' ? '' : trim($displayLang);
        if ($displayLang !== '' && !empty($messageIds)) {
            try {
                $ph = implode(',', array_fill(0, count($messageIds), '?'));
                $stmtTr = $pdo->prepare("
                    SELECT message_id, translated_text
                    FROM message_translations
                    WHERE message_id IN ($ph) AND target_lang = ?
                ");
                $paramsTr = array_merge($messageIds, [$displayLang]);
                $stmtTr->execute($paramsTr);
                $cachedMap = [];
                while ($row = $stmtTr->fetch(PDO::FETCH_ASSOC)) {
                    $cachedMap[(int)$row['message_id']] = $row['translated_text'];
                }
                foreach ($messages as &$m) {
                    $mid = (int)$m['id'];
                    if (isset($cachedMap[$mid])) {
                        $m['cached_translation'] = $cachedMap[$mid];
                    }
                }
                unset($m);
            } catch (Throwable $e) {
                error_log('messages.php get cached_translation: ' . $e->getMessage());
            }
        }
        
        // 削除済みタスクのメッセージを除外（タスク表示を削除した場合、ポーリングで再表示しない）
        $messages = array_values(array_filter($messages, function ($m) {
            if (($m['message_type'] ?? '') !== 'system') return true;
            $tid = $m['task_id'] ?? 0;
            if (!$tid) return true;
            return !empty($m['task_detail']);
        }));
        
        // 既読を更新（last_read_at と last_read_message_id の両方で永続化）
        try {
            $maxMsgWhere = "conversation_id = ?" . ($hasDeletedAt ? " AND deleted_at IS NULL" : "") . ($hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "");
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE {$maxMsgWhere}");
            $stmt->execute([(int)$conversation_id]);
            $max_msg_id = (int) $stmt->fetchColumn();
            $cmWhere = "conversation_id = ? AND user_id = ?" . ($hasCmLeftAt ? " AND left_at IS NULL" : "");
            $pdo->prepare("
                UPDATE conversation_members
                SET last_read_at = NOW(), last_read_message_id = ?
                WHERE {$cmWhere}
            ")->execute([$max_msg_id, (int)$conversation_id, (int)$user_id]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'last_read_message_id') === false && strpos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }
            $cmWhere = "conversation_id = ? AND user_id = ?" . ($hasCmLeftAt ? " AND left_at IS NULL" : "");
            $pdo->prepare("
                UPDATE conversation_members SET last_read_at = NOW()
                WHERE {$cmWhere}
            ")->execute([(int)$conversation_id, (int)$user_id]);
        }
        
        // クライアント用に日時をISO 8601で統一（現在時刻とずれない表示のため）
        if (function_exists('formatDatetimeForClient')) {
            foreach ($messages as &$m) {
                if (!empty($m['created_at'])) {
                    $m['created_at'] = formatDatetimeForClient($m['created_at']);
                }
            }
            unset($m);
        }
        
        successResponse([
            'messages' => array_reverse($messages),
            'has_more' => count($messages) == $limit
        ]);
        } catch (Throwable $e) {
            error_log('[messages.php get] conversation_id=' . (int)$conversation_id . ' error=' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('[messages.php get] trace=' . $e->getTraceAsString());
            $fallbackMessages = [];
            try {
                $fallbackLimit = min((int)($limit ?? 50), 100);
                $fbDeletedClause = '';
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
                    $fbHasDelAt = $chk && $chk->rowCount() > 0;
                    $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
                    $fbHasIsDel = $chk && $chk->rowCount() > 0;
                    $fbDeletedClause = ($fbHasDelAt ? ' AND m.deleted_at IS NULL' : '') . ($fbHasIsDel ? ' AND (m.is_deleted = 0 OR m.is_deleted IS NULL)' : '');
                } catch (Exception $e0) {}
                $fbHasReplyToId = false;
                try {
                    $chkReply = $pdo->query("SHOW COLUMNS FROM messages LIKE 'reply_to_id'");
                    $fbHasReplyToId = $chkReply && $chkReply->rowCount() > 0;
                } catch (Exception $e0) {}
                if ($fbHasReplyToId) {
                    $fallbackStmt = $pdo->prepare("
                        SELECT m.id, m.conversation_id, m.sender_id, m.content, m.reply_to_id, m.created_at,
                               u.display_name as sender_name,
                               rm.content AS reply_to_content, ru.display_name AS reply_to_sender_name
                        FROM messages m
                        INNER JOIN users u ON m.sender_id = u.id
                        LEFT JOIN messages rm ON m.reply_to_id = rm.id
                        LEFT JOIN users ru ON rm.sender_id = ru.id
                        WHERE m.conversation_id = ?{$fbDeletedClause}
                        ORDER BY m.id DESC
                        LIMIT ?
                    ");
                } else {
                    $fallbackStmt = $pdo->prepare("
                        SELECT m.id, m.conversation_id, m.sender_id, m.content, m.created_at, u.display_name as sender_name
                        FROM messages m
                        INNER JOIN users u ON m.sender_id = u.id
                        WHERE m.conversation_id = ?{$fbDeletedClause}
                        ORDER BY m.id DESC
                        LIMIT ?
                    ");
                }
                $fallbackStmt->execute([(int)$conversation_id, $fallbackLimit]);
                $rows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                $fbReactionDetails = [];
                if (!empty($rows)) {
                    try {
                        $chkReactions = $pdo->query("SHOW TABLES LIKE 'message_reactions'");
                        if ($chkReactions && $chkReactions->rowCount() > 0) {
                            $fbMsgIds = array_column($rows, 'id');
                            $placeholders = implode(',', array_fill(0, count($fbMsgIds), '?'));
                            $stmtR = $pdo->prepare("
                                SELECT mr.message_id, mr.reaction_type, mr.user_id,
                                       COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) as display_name
                                FROM message_reactions mr
                                LEFT JOIN users u ON mr.user_id = u.id
                                WHERE mr.message_id IN ($placeholders)
                            ");
                            $stmtR->execute($fbMsgIds);
                            foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $rowR) {
                                $msgId = (int)$rowR['message_id'];
                                if (!isset($fbReactionDetails[$msgId])) $fbReactionDetails[$msgId] = [];
                                $type = $rowR['reaction_type'] ?? '';
                                $uid = (int)$rowR['user_id'];
                                $name = $rowR['display_name'] ?? (string)$uid;
                                $key = null;
                                foreach ($fbReactionDetails[$msgId] as $i => $d) {
                                    if (($d['type'] ?? $d['reaction_type'] ?? '') === $type) { $key = $i; break; }
                                }
                                if ($key !== null) {
                                    $fbReactionDetails[$msgId][$key]['count']++;
                                    $fbReactionDetails[$msgId][$key]['users'][] = ['id' => $uid, 'name' => $name];
                                    if ($uid == $user_id) $fbReactionDetails[$msgId][$key]['is_mine'] = 1;
                                } else {
                                    $fbReactionDetails[$msgId][] = [
                                        'type' => $type, 'reaction_type' => $type, 'count' => 1,
                                        'users' => [['id' => $uid, 'name' => $name]],
                                        'is_mine' => ($uid == $user_id) ? 1 : 0
                                    ];
                                }
                            }
                        }
                    } catch (Exception $e3) {
                        error_log('[messages.php get] fallback reaction_details: ' . $e3->getMessage());
                    }
                }
                foreach ($rows as $r) {
                    $r['id'] = (int)$r['id'];
                    $r['conversation_id'] = (int)$r['conversation_id'];
                    $r['sender_id'] = (int)$r['sender_id'];
                    $r['message_type'] = 'text';
                    $r['is_edited'] = 0;
                    $r['reaction_details'] = $fbReactionDetails[$r['id']] ?? [];
                    $r['is_mentioned_me'] = false;
                    $r['mention_type'] = null;
                    $r['reply_to_id'] = (isset($r['reply_to_id']) && $r['reply_to_id'] !== '' && (int)$r['reply_to_id'] > 0) ? (int)$r['reply_to_id'] : null;
                    $r['reply_to_content'] = isset($r['reply_to_content']) ? $r['reply_to_content'] : null;
                    $r['reply_to_sender_name'] = isset($r['reply_to_sender_name']) ? $r['reply_to_sender_name'] : null;
                    if (!empty($r['created_at']) && function_exists('formatDatetimeForClient')) {
                        $r['created_at'] = formatDatetimeForClient($r['created_at']);
                    }
                    $fallbackMessages[] = $r;
                }
            } catch (Throwable $e2) {
                error_log('[messages.php get] fallback also failed: ' . $e2->getMessage());
            }
            successResponse([
                'messages' => array_reverse($fallbackMessages),
                'has_more' => count($fallbackMessages) >= min((int)($_GET['limit'] ?? 50), 100)
            ]);
        }
        break;
    
    case 'get_task_detail':
        // チャット表示用: 会話メンバーがタスク詳細を取得（カードの空表示対策）
        $task_id = (int)($_GET['task_id'] ?? 0);
        $conv_id = (int)($_GET['conversation_id'] ?? 0);
        if (!$task_id || !$conv_id) {
            errorResponse('task_id と conversation_id が必要です', 400);
        }
        $getTaskDetailCmLeftAt = false;
        $getTaskDetailMsgDeletedAt = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $getTaskDetailCmLeftAt = $c && $c->rowCount() > 0;
            $c2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
            $getTaskDetailMsgDeletedAt = $c2 && $c2->rowCount() > 0;
        } catch (Exception $e) {}
        $memberSql = "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?" . ($getTaskDetailCmLeftAt ? " AND left_at IS NULL" : "");
        $stmt = $pdo->prepare($memberSql);
        $stmt->execute([$conv_id, $user_id]);
        if (!$stmt->fetch()) {
            errorResponse('この会話にアクセスする権限がありません', 403);
        }
        $msgDelClause = $getTaskDetailMsgDeletedAt ? " AND m.deleted_at IS NULL" : "";
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, t.due_date, t.status,
                creator.display_name as requester_name,
                worker.display_name as worker_name
            FROM tasks t
            LEFT JOIN users creator ON t.created_by = creator.id
            LEFT JOIN users worker ON t.assigned_to = worker.id
            INNER JOIN messages m ON (m.task_id = t.id OR t.notification_message_id = m.id)
            WHERE t.id = ? AND m.conversation_id = ? {$msgDelClause}
        ");
        $stmt->execute([$task_id, $conv_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            errorResponse('タスクが見つかりません', 404);
        }
        successResponse([
            'task_detail' => [
                'id' => (int)$row['id'],
                'title' => $row['title'] ?? '',
                'due_date' => $row['due_date'] ?? null,
                'requester_name' => $row['requester_name'] ?? null,
                'worker_name' => $row['worker_name'] ?? null
            ]
        ]);
        break;
        
    case 'edit_display_name':
        // ファイル添付メッセージの表示名のみ編集
        try {
            $message_id = (int)($input['message_id'] ?? 0);
            $display_name = trim($input['display_name'] ?? '');
            
            if (!$message_id) {
                errorResponse('メッセージIDが必要です');
            }
            
            if ($display_name === '') {
                errorResponse('表示名を入力してください');
            }
            
            $display_name = mb_substr($display_name, 0, 200);
            
            $editDnHasDeletedAt = false;
            $editDnHasIsEdited = false;
            $editDnHasEditedAt = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
                $editDnHasDeletedAt = $c && $c->rowCount() > 0;
                $c2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_edited'");
                $editDnHasIsEdited = $c2 && $c2->rowCount() > 0;
                $c3 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'edited_at'");
                $editDnHasEditedAt = $c3 && $c3->rowCount() > 0;
            } catch (Exception $e) {}
            $selSql = "SELECT sender_id, content FROM messages WHERE id = ?" . ($editDnHasDeletedAt ? " AND deleted_at IS NULL" : "");
            $stmt = $pdo->prepare($selSql);
            $stmt->execute([$message_id]);
            $message = $stmt->fetch();
            
            if (!$message || $message['sender_id'] != $user_id) {
                errorResponse('このメッセージを編集する権限がありません', 403);
            }
            
            $content = $message['content'];
            // 改行を正規化（\r\n → \n）
            $content = preg_replace('/\r\n|\r/', "\n", $content);
            $path = null;
            // 形式1: 絵文字 表示名\nuploads/messages/xxx.pdf
            if (preg_match('/^([📄📷📬📝📊📽️📎🎵📦📃]\s*)[^\n\r]*[\r\n]+(uploads\/messages\/[^\s\n\r]+)\s*$/us', $content, $m)) {
                $path = $m[2];
                $newContent = $m[1] . $display_name . "\n" . $path;
            }
            // 形式2: uploads/messages/xxx.pdf のみ（単独行）
            elseif (preg_match('/^(uploads\/messages\/[^\s\n\r]+\.(pdf|docx?|xlsx?|pptx?))\s*$/m', $content, $m)) {
                $path = $m[1];
                $newContent = '📄 ' . $display_name . "\n" . $path;
            }
            // 形式3: 本文中にパスが含まれる（曖昧マッチ）
            elseif (preg_match('/(uploads\/messages\/[^\s\n\r]+\.(pdf|docx?|xlsx?|pptx?))/', $content, $m)) {
                $path = $m[1];
                $emoji = '';
                if (preg_match('/^([📄📷📬📝📊📽️📎🎵📦📃])\s*/u', $content, $em)) {
                    $emoji = $em[1] . ' ';
                } else {
                    $emoji = '📄 ';
                }
                $newContent = $emoji . $display_name . "\n" . $path;
            }
            if ($path) {
                $updSets = "content = ?";
                $updParams = [$newContent, $message_id];
                if ($editDnHasIsEdited) { $updSets .= ", is_edited = 1"; }
                if ($editDnHasEditedAt) { $updSets .= ", edited_at = NOW()"; }
                $pdo->prepare("UPDATE messages SET {$updSets} WHERE id = ?")->execute($updParams);
                successResponse(['content' => $newContent], '表示名を更新しました');
            } else {
                errorResponse('このメッセージはファイル添付ではありません');
            }
        } catch (Exception $e) {
            error_log('edit_display_name error: ' . $e->getMessage());
            errorResponse('表示名の更新に失敗しました');
        }
        break;
        
    case 'edit':
        // メッセージを編集
        try {
            $message_id = (int)($input['message_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            // To機能一時削除（Phase B）: mention_ids は受け取らない
            $mention_ids = null;
            
            if (!$message_id) {
                errorResponse('メッセージIDが必要です');
            }
            
            if (empty($content)) {
                errorResponse('メッセージを入力してください');
            }
            
            $editHasDeletedAt = false;
            $editHasIsEdited = false;
            $editHasEditedAt = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
                $editHasDeletedAt = $c && $c->rowCount() > 0;
                $c2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_edited'");
                $editHasIsEdited = $c2 && $c2->rowCount() > 0;
                $c3 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'edited_at'");
                $editHasEditedAt = $c3 && $c3->rowCount() > 0;
            } catch (Exception $e) {}
            $selSql = "SELECT sender_id, conversation_id FROM messages WHERE id = ?" . ($editHasDeletedAt ? " AND deleted_at IS NULL" : "");
            $stmt = $pdo->prepare($selSql);
            $stmt->execute([$message_id]);
            $message = $stmt->fetch();
            
            if (!$message || $message['sender_id'] != $user_id) {
                errorResponse('このメッセージを編集する権限がありません', 403);
            }
            
            $updSets = "content = ?";
            $updParams = [$content, $message_id];
            if ($editHasIsEdited) { $updSets .= ", is_edited = 1"; }
            if ($editHasEditedAt) { $updSets .= ", edited_at = NOW()"; }
            $pdo->prepare("UPDATE messages SET {$updSets} WHERE id = ?")->execute($updParams);
            
            ensureMessageMentionsMentionType($pdo);
            $hasMtCol = false;
            try {
                $chkMt = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
                $hasMtCol = $chkMt && $chkMt->rowCount() > 0;
            } catch (Exception $e) {}
            
            // Phase C: 編集時も本文から [To:all] / [To:ID] をパースして message_mentions を更新
            $toMentionAll = (bool) preg_match('/\[To:all\]/i', $content);
            $toMentionIds = [];
            if (!$toMentionAll && preg_match_all('/\[To:(\d+)\]/', $content, $m)) {
                $toMentionIds = array_values(array_unique(array_filter(array_map('intval', $m[1]), function($id) { return $id > 0; })));
                if (!empty($toMentionIds)) {
                    $ph = implode(',', array_fill(0, count($toMentionIds), '?'));
                    $stmtU = $pdo->prepare("SELECT id FROM users WHERE id IN ($ph)");
                    $stmtU->execute($toMentionIds);
                    $toMentionIds = array_map('intval', $stmtU->fetchAll(PDO::FETCH_COLUMN));
                }
            }
            $pdo->prepare("DELETE FROM message_mentions WHERE message_id = ?")->execute([$message_id]);
            $mmCols = $hasMtCol ? "message_id, mentioned_user_id, mention_type, created_at" : "message_id, mentioned_user_id, created_at";
            $mentionedUsers = extractMentions($content, $pdo);
            $mmValsText = $hasMtCol ? "?, ?, 'text', NOW()" : "?, ?, NOW()";
            foreach ($mentionedUsers as $mentioned) {
                $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmCols}) VALUES ({$mmValsText})")
                    ->execute([$message_id, $mentioned['id']]);
            }
            $senderRow = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $senderRow->execute([$user_id]);
            $sender = $senderRow->fetch(PDO::FETCH_ASSOC);
            $senderName = $sender['display_name'] ?? '';
            if ($toMentionAll) {
                $stmt = $pdo->prepare("
                    SELECT user_id FROM conversation_members 
                    WHERE conversation_id = ? AND user_id != ?
                ");
                try {
                    $c = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
                    if ($c && $c->rowCount() > 0) {
                        $stmt = $pdo->prepare("
                            SELECT user_id FROM conversation_members 
                            WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL
                        ");
                    }
                } catch (Exception $e) {}
                $stmt->execute([$message['conversation_id'], $user_id]);
                $allMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $mmValsAll = $hasMtCol ? "?, ?, 'to_all', NOW()" : "?, ?, NOW()";
                foreach ($allMembers as $memberId) {
                    $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmCols}) VALUES ({$mmValsAll})")
                        ->execute([$message_id, $memberId]);
                }
            } elseif (!empty($toMentionIds)) {
                $mmValsTo = $hasMtCol ? "?, ?, 'to', NOW()" : "?, ?, NOW()";
                foreach ($toMentionIds as $mentionId) {
                    $mentionId = (int)$mentionId;
                    if ($mentionId === $user_id) continue;
                    $pdo->prepare("INSERT IGNORE INTO message_mentions ({$mmCols}) VALUES ({$mmValsTo})")
                        ->execute([$message_id, $mentionId]);
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id)
                        VALUES (?, 'mention', 'あなた宛のメッセージ', ?, 'message', ?)
                    ")->execute([$mentionId, $senderName . 'さんがあなた宛にメッセージを送信しました', $message_id]);
                }
            }
            
            successResponse([], 'メッセージを編集しました');
        } catch (Exception $e) {
            error_log('Message edit error: ' . $e->getMessage());
            errorResponse('編集中にエラーが発生しました');
        }
        break;
        
    case 'delete':
        // メッセージを削除（deleted_at が無い環境では is_deleted = 1 で論理削除）
        $message_id = (int)($input['message_id'] ?? 0);
        
        if (!$message_id) {
            errorResponse('メッセージIDが必要です');
        }
        
        // 送信者か確認
        $stmt = $pdo->prepare("SELECT sender_id FROM messages WHERE id = ?");
        $stmt->execute([$message_id]);
        $message = $stmt->fetch();
        
        if (!$message || $message['sender_id'] != $user_id) {
            errorResponse('このメッセージを削除する権限がありません', 403);
        }
        
        $hasDeletedAt = false;
        $hasIsDeleted = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
            $hasDeletedAt = $chk && $chk->rowCount() > 0;
            $chk = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
            $hasIsDeleted = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        $deleted = false;
        if ($hasDeletedAt || $hasIsDeleted) {
            $sets = [];
            $params = [];
            if ($hasDeletedAt) { $sets[] = 'deleted_at = NOW()'; }
            if ($hasIsDeleted) { $sets[] = 'is_deleted = 1'; }
            $params[] = $message_id;
            try {
                $pdo->prepare("UPDATE messages SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $deleted = true;
            } catch (Exception $e) {
                error_log('[messages.php delete] UPDATE failed: ' . $e->getMessage());
            }
        }
        if (!$deleted) {
            error_log('[messages.php delete] No deleted_at or is_deleted column for message_id=' . $message_id);
            errorResponse('削除機能が利用できません（テーブル未対応）', 500);
        }
        
        try {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Exception $e) {}
        
        successResponse([], 'メッセージを削除しました');
        break;
        
    case 'pin':
        // メッセージをピン留め
        $message_id = (int)($input['message_id'] ?? 0);
        $is_pinned = $input['is_pinned'] ?? true;
        
        if (!$message_id) {
            errorResponse('メッセージIDが必要です');
        }
        
        // メッセージの会話を取得し、管理者か確認
        $pinCmLeftAt = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $pinCmLeftAt = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        $pinMemberCond = $pinCmLeftAt ? " AND cm.left_at IS NULL" : "";
        $stmt = $pdo->prepare("
            SELECT m.conversation_id, cm.role
            FROM messages m
            INNER JOIN conversation_members cm ON m.conversation_id = cm.conversation_id
            WHERE m.id = ? AND cm.user_id = ? {$pinMemberCond}
        ");
        $stmt->execute([$message_id, $user_id]);
        $result = $stmt->fetch();
        
        if (!$result || $result['role'] !== 'admin') {
            errorResponse('ピン留めには管理者権限が必要です', 403);
        }
        
        $pinHasIsPinned = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_pinned'");
            $pinHasIsPinned = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        if ($pinHasIsPinned) {
            $pdo->prepare("UPDATE messages SET is_pinned = ? WHERE id = ?")->execute([$is_pinned ? 1 : 0, $message_id]);
        }
        successResponse([], $is_pinned ? 'ピン留めしました' : 'ピン留めを解除しました');
        break;
        
    case 'add_reaction':
        // フロント (reactions.js / scripts.php) が送る action 名。react と同一処理へ
        $input['message_id'] = $input['message_id'] ?? 0;
        $input['reaction_type'] = $input['reaction'] ?? $input['reaction_type'] ?? '👍';
        // fall through to react
    case 'react':
        // リアクション（ナイス）を追加・トグル
        $message_id = (int)($input['message_id'] ?? 0);
        $reaction_type = $input['reaction_type'] ?? $input['reaction'] ?? '👍';
        
        if (!$message_id) {
            errorResponse('メッセージIDが必要です');
        }
        
        // message_reactions テーブルが無い環境ではエラーを返す（保存できないため）
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'message_reactions'");
            if (!$chk || $chk->rowCount() === 0) {
                error_log('[messages.php] message_reactions table not found');
                errorResponse('リアクション機能は現在利用できません', 503);
            }
        } catch (Exception $e) {
            error_log('[messages.php] message_reactions check failed: ' . $e->getMessage());
            errorResponse('リアクションの保存に失敗しました', 503);
        }
        
        $valid_reactions = ['👍', '❤️', '😊', '🎉', '😢', '😂', '🔥', '👏', '🙏', '🙇', '💪', '✨', '🤔', '👀', '💯', '🥰', '😮'];
        if (!in_array($reaction_type, $valid_reactions)) {
            errorResponse('無効なリアクションです');
        }
        
        try {
        // 既存のリアクションをチェック
        $stmt = $pdo->prepare("
            SELECT id, reaction_type FROM message_reactions 
            WHERE message_id = ? AND user_id = ?
        ");
        $stmt->execute([$message_id, $user_id]);
        $existing = $stmt->fetch();
        
        $action_taken = 'added';
        
        if ($existing) {
            if ($existing['reaction_type'] === $reaction_type) {
                // 同じリアクションなら削除（トグル）
                $pdo->prepare("DELETE FROM message_reactions WHERE id = ?")->execute([$existing['id']]);
                $action_taken = 'removed';
            } else {
                // 違うリアクションなら更新
                $pdo->prepare("
                    UPDATE message_reactions SET reaction_type = ? WHERE id = ?
                ")->execute([$reaction_type, $existing['id']]);
                $action_taken = 'changed';
            }
        } else {
            // 新規追加
            $pdo->prepare("
                INSERT INTO message_reactions (message_id, user_id, reaction_type)
                VALUES (?, ?, ?)
            ")->execute([$message_id, $user_id, $reaction_type]);
        }
        
        if (function_exists('error_log')) {
            error_log("[messages.php] Reaction saved: message_id={$message_id} user_id={$user_id} type={$reaction_type} action={$action_taken}");
        }
        
        // 更新後のリアクション一覧を取得（誰がしたか分かるように users 付き）
        $stmt = $pdo->prepare("
            SELECT mr.reaction_type, mr.user_id,
                   COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) as display_name
            FROM message_reactions mr
            LEFT JOIN users u ON mr.user_id = u.id
            WHERE mr.message_id = ?
        ");
        $stmt->execute([$message_id]);
        $rows = $stmt->fetchAll();
        $reactions = [];
        $my_reaction = null;
        foreach ($rows as $r) {
            $type = $r['reaction_type'];
            $uid = (int)$r['user_id'];
            $name = $r['display_name'] ?? (string)$uid;
            $found = false;
            foreach ($reactions as &$grp) {
                if ($grp['reaction_type'] === $type) {
                    $grp['count'] = (int)($grp['count'] ?? 0) + 1;
                    $grp['users'][] = ['id' => $uid, 'name' => $name];
                    if ($uid == $user_id) {
                        $grp['is_mine'] = 1;
                        $my_reaction = $type;
                    }
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reactions[] = [
                    'reaction_type' => $type,
                    'count' => 1,
                    'users' => [['id' => $uid, 'name' => $name]],
                    'is_mine' => ($uid == $user_id) ? 1 : 0
                ];
                if ($uid == $user_id) {
                    $my_reaction = $type;
                }
            }
        }
        
        successResponse([
            'action' => $action_taken,
            'reactions' => $reactions,
            'my_reaction' => $my_reaction
        ]);
        } catch (PDOException $e) {
            error_log('[messages.php] react DB error: ' . $e->getMessage());
            errorResponse('リアクションの保存に失敗しました', 500);
        }
        break;
        
    case 'remove_reaction':
        // フロント (reactions.js) が送る action 名。unreact と同一処理へ
        $input['message_id'] = $input['message_id'] ?? 0;
        // fall through to unreact
    case 'unreact':
        // リアクションを削除
        $message_id = (int)($input['message_id'] ?? 0);
        
        if (!$message_id) {
            errorResponse('メッセージIDが必要です');
        }
        
        $pdo->prepare("
            DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?
        ")->execute([$message_id, $user_id]);
        
        // 削除後のリアクション一覧を返す（フロントの updateMessageReactions 用）
        $reactions = [];
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'message_reactions'");
            if ($chk && $chk->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT mr.reaction_type, mr.user_id,
                           COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) as display_name
                    FROM message_reactions mr
                    LEFT JOIN users u ON mr.user_id = u.id
                    WHERE mr.message_id = ?
                ");
                $stmt->execute([$message_id]);
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $type = $r['reaction_type'];
                    $uid = (int)$r['user_id'];
                    $name = $r['display_name'] ?? (string)$uid;
                    $found = false;
                    foreach ($reactions as &$grp) {
                        if ($grp['reaction_type'] === $type) {
                            $grp['count'] = (int)($grp['count'] ?? 0) + 1;
                            $grp['users'][] = ['id' => $uid, 'name' => $name];
                            if ($uid == $user_id) {
                                $grp['is_mine'] = 1;
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $reactions[] = [
                            'reaction_type' => $type,
                            'count' => 1,
                            'users' => [['id' => $uid, 'name' => $name]],
                            'is_mine' => ($uid == $user_id) ? 1 : 0
                        ];
                    }
                }
            }
        } catch (Exception $e) {}
        
        successResponse(['reactions' => $reactions]);
        break;
        
    case 'search':
        // メッセージ・ユーザー・グループを検索
        $keyword = trim($_GET['keyword'] ?? '');
        $type = $_GET['type'] ?? 'all'; // all, users, messages, groups
        $conversation_id = (int)($_GET['conversation_id'] ?? 0);
        $sender_id = (int)($_GET['sender_id'] ?? 0);
        $file_type = $_GET['file_type'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        if (empty($keyword)) {
            errorResponse('検索キーワードを入力してください');
        }
        
        $results = [];
        
        // 現在のユーザーがシステム管理者かチェック（セッション未設定時はDBから取得）
        $current_role = $_SESSION['role'] ?? null;
        if ($current_role === null || $current_role === '') {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_role = $row['role'] ?? 'user';
            $_SESSION['role'] = $current_role;
        }
        $is_system_admin = in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin']);
        
        // ユーザー検索（15歳未満除外、メール・携帯電話も検索対象、is_friend/is_pending 付与）
        $ageCutoff = '';
        try {
            $chkBirth = $pdo->query("SHOW COLUMNS FROM users LIKE 'birth_date'");
            if ($chkBirth && $chkBirth->rowCount() > 0) {
                $ageCutoff = " AND (u.birth_date IS NULL OR u.birth_date <= DATE_SUB(CURDATE(), INTERVAL 15 YEAR))";
            }
        } catch (Exception $e) {}
        $phoneCond = '';
        $phonePat = null;
        $phoneDigits = preg_replace('/\D/', '', $keyword);
        try {
            $chkPhone = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'");
            if ($chkPhone && $chkPhone->rowCount() > 0 && $phoneDigits !== '') {
                $phonePat = '%' . $phoneDigits . '%';
                $phoneCond = " OR u.phone LIKE ?";
            }
        } catch (Exception $e) {}
        // テーブル・カラム存在チェック（本番で未適用マイグレーションがある場合に500を防ぐ）
        $hasCmLeftAt = false;
        $hasFriendships = false;
        $hasBlockedUsers = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $hasCmLeftAt = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        try {
            $t = $pdo->query("SHOW TABLES LIKE 'friendships'");
            $hasFriendships = $t && $t->rowCount() > 0;
        } catch (Exception $e) {}
        try {
            $t = $pdo->query("SHOW TABLES LIKE 'blocked_users'");
            $hasBlockedUsers = $t && $t->rowCount() > 0;
        } catch (Exception $e) {}
        $leftAtCond = $hasCmLeftAt ? ' AND cm2.left_at IS NULL' : '';
        $leftAtCondCm = $hasCmLeftAt ? ' AND cm.left_at IS NULL' : '';

        if ($type === 'all' || $type === 'users') {
            $userResults = [];
            try {
            if ($is_system_admin) {
                $searchPat = "%{$keyword}%";
                $params = [$user_id, $searchPat, $searchPat];
                if ($phonePat !== null) { $params[] = $phonePat; }
                $stmt = $pdo->prepare("
                    SELECT u.id, u.display_name as name, 'user' as result_type
                    FROM users u
                    WHERE u.id != ? AND u.status = 'active'
                    AND (u.display_name LIKE ? OR u.email LIKE ? $phoneCond)
                    $ageCutoff
                    ORDER BY u.display_name
                    LIMIT 10
                ");
                $stmt->execute($params);
                $userResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // allow_member_dm カラムの有無を確認
                $hasAllowDm = false;
                try {
                    $c = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'allow_member_dm'");
                    $hasAllowDm = $c && $c->rowCount() > 0;
                } catch (Exception $e) {}
                $dmCond1 = $hasAllowDm ? ' AND c2.allow_member_dm = 1' : '';
                $dmCond2 = $hasAllowDm ? ' AND c.allow_member_dm = 1' : '';

                $params = [$user_id, $user_id, "%{$keyword}%", "%{$keyword}%"];
                if ($phonePat !== null) { $params[] = $phonePat; }
                $params[] = $user_id; $params[] = "%{$keyword}%"; $params[] = "%{$keyword}%";
                if ($phonePat !== null) { $params[] = $phonePat; }
                $stmt = $pdo->prepare("
                    SELECT * FROM (
                        (
                            SELECT DISTINCT u.id, u.display_name as name, 'user' as result_type
                            FROM users u
                            INNER JOIN conversation_members cm ON u.id = cm.user_id
                            INNER JOIN conversations c ON cm.conversation_id = c.id
                            WHERE cm.conversation_id IN (
                                SELECT cm2.conversation_id 
                                FROM conversation_members cm2
                                INNER JOIN conversations c2 ON cm2.conversation_id = c2.id
                                WHERE cm2.user_id = ?
                                $leftAtCond
                                AND c2.type = 'group'
                                $dmCond1
                            )
                            $leftAtCondCm
                            $dmCond2
                            AND u.id != ?
                            AND u.status = 'active'
                            AND (u.display_name LIKE ? OR u.email LIKE ? $phoneCond)
                            $ageCutoff
                        )
                        UNION
                        (
                            SELECT u.id, u.display_name as name, 'user' as result_type
                            FROM users u
                            WHERE u.role = 'system_admin'
                            AND u.id != ?
                            AND u.status = 'active'
                            AND (u.display_name LIKE ? OR u.email LIKE ? $phoneCond)
                            $ageCutoff
                        )
                    ) AS combined
                    ORDER BY name
                    LIMIT 10
                ");
                $stmt->execute($params);
                $userResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            } catch (Exception $e) {
                error_log('Search users error: ' . $e->getMessage());
                $userResults = [];
            }
            // 携帯番号検索：検索拒否でない・表示名ありのユーザーを追加（友達申請用・上パネル検索で表示）
            if (!$is_system_admin && ($type === 'all' || $type === 'users') && $phonePat !== null && strlen($phoneDigits) >= 10) {
                $existingIds = array_column($userResults, 'id');
                $hasPrivacy = false;
                try {
                    $chk = $pdo->query("SHOW TABLES LIKE 'user_privacy_settings'");
                    $hasPrivacy = $chk && $chk->rowCount() > 0;
                } catch (Exception $e) {}
                $blockedCond = $hasBlockedUsers ? ' AND u.id NOT IN (SELECT blocked_user_id FROM blocked_users WHERE user_id = ?)' : '';
                $friendsCond = $hasFriendships ? ' AND u.id NOT IN (SELECT friend_id FROM friendships WHERE user_id = ? AND status = \'blocked\')' : '';
                $phoneParams = [$user_id, $phonePat];
                if ($hasBlockedUsers) { $phoneParams[] = $user_id; }
                if ($hasFriendships) { $phoneParams[] = $user_id; }
                try {
                    if ($hasPrivacy) {
                        $stmtPhone = $pdo->prepare("
                            SELECT u.id, u.display_name as name, 'user' as result_type
                            FROM users u
                            LEFT JOIN user_privacy_settings ups ON u.id = ups.user_id
                            WHERE u.id != ? AND u.status = 'active' AND u.phone LIKE ?
                            AND TRIM(COALESCE(u.display_name,'')) != ''
                            AND (ups.id IS NULL OR ups.exclude_from_search = 0)
                            $blockedCond
                            $friendsCond
                            ORDER BY u.display_name LIMIT 10
                        ");
                    } else {
                        $stmtPhone = $pdo->prepare("
                            SELECT u.id, u.display_name as name, 'user' as result_type
                            FROM users u
                            WHERE u.id != ? AND u.status = 'active' AND u.phone LIKE ?
                            AND TRIM(COALESCE(u.display_name,'')) != ''
                            $blockedCond
                            $friendsCond
                            ORDER BY u.display_name LIMIT 10
                        ");
                    }
                    $stmtPhone->execute($phoneParams);
                    $phoneUsers = $stmtPhone->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($phoneUsers as $pu) {
                        if (!in_array($pu['id'], $existingIds)) {
                            $userResults[] = $pu;
                            $existingIds[] = $pu['id'];
                        }
                    }
                } catch (Exception $e) {
                    error_log('Search phone users: ' . $e->getMessage());
                }
            }
            // 各ユーザーに is_friend, is_pending, sent_by_me, friendship_id を付与
            foreach ($userResults as &$ur) {
                if ($hasFriendships) {
                    $fid = (int)$ur['id'];
                    try {
                        $stmtF = $pdo->prepare("SELECT id, user_id, status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
                        $stmtF->execute([$user_id, $fid, $fid, $user_id]);
                        $fr = $stmtF->fetch(PDO::FETCH_ASSOC);
                        $ur['is_friend'] = (int)($fr && $fr['status'] === 'accepted');
                        $ur['is_pending'] = (int)($fr && $fr['status'] === 'pending');
                        $ur['sent_by_me'] = (int)($fr && $fr['status'] === 'pending' && (int)$fr['user_id'] === $user_id);
                        $ur['friendship_id'] = $fr ? (int)$fr['id'] : null;
                    } catch (Exception $e) {
                        $ur['is_friend'] = 0;
                        $ur['is_pending'] = 0;
                        $ur['sent_by_me'] = 0;
                        $ur['friendship_id'] = null;
                    }
                } else {
                    $ur['is_friend'] = 0;
                    $ur['is_pending'] = 0;
                    $ur['sent_by_me'] = 0;
                    $ur['friendship_id'] = null;
                }
            }
            unset($ur);
            $results = array_merge($results, $userResults);
        }
        
        // グループ検索
        if ($type === 'all' || $type === 'groups') {
            try {
            $searchPat = "%{$keyword}%";
            $leftAtSubq = $hasCmLeftAt ? ' AND cm2.left_at IS NULL' : '';
            $leftAtJoin = $hasCmLeftAt ? ' AND cm.left_at IS NULL' : '';
            if ($is_system_admin) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.name, c.description, 'group' as result_type,
                        EXISTS(SELECT 1 FROM conversation_members cm2 WHERE cm2.conversation_id = c.id AND cm2.user_id = ? $leftAtSubq) as is_member
                    FROM conversations c
                    WHERE c.type IN ('group', 'organization')
                    AND c.name LIKE ?
                    ORDER BY c.name
                    LIMIT 20
                ");
                $stmt->execute([$user_id, $searchPat]);
            } else {
                $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.description, 'group' as result_type, 1 as is_member
                FROM conversations c
                INNER JOIN conversation_members cm ON c.id = cm.conversation_id
                WHERE cm.user_id = ? $leftAtJoin
                AND c.type IN ('group', 'organization')
                AND c.name LIKE ?
                LIMIT 10
            ");
                $stmt->execute([$user_id, $searchPat]);
            }
            $groupResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($groupResults as $row) {
                $row['is_member'] = (int)($row['is_member'] ?? 1);
                $results[] = $row;
            }
            } catch (Exception $e) {
                error_log('Search groups error: ' . $e->getMessage());
            }
        }
        
        // メッセージ検索（extracted_textカラムも対象、未作成時はフォールバック）
        if ($type === 'all' || $type === 'messages') {
            try {
            $msgLimit = min($limit, 100);
            $msgLeftAtCond = $hasCmLeftAt ? ' AND cm.left_at IS NULL' : '';
            $hasDeletedAt = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
                $hasDeletedAt = $c && $c->rowCount() > 0;
            } catch (Exception $e) {}
            $deletedAtCond = $hasDeletedAt ? ' AND m.deleted_at IS NULL' : '';
            // message_type カラムの有無
            $hasMsgType = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
                $hasMsgType = $c && $c->rowCount() > 0;
            } catch (Exception $e) {}
            $searchMsgSql = function($includeExtracted) use ($user_id, $keyword, $conversation_id, $sender_id, $file_type, $msgLimit, $msgLeftAtCond, $deletedAtCond, $hasMsgType) {
                $where = $includeExtracted
                    ? "AND (m.content LIKE ? OR m.extracted_text LIKE ?)"
                    : "AND m.content LIKE ?";
                $sql = "
                    SELECT 
                        m.id,
                        m.content,
                        m.conversation_id,
                        u.display_name as sender_name,
                        c.name as conversation_name,
                        'message' as result_type
                    FROM messages m
                    INNER JOIN users u ON m.sender_id = u.id
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    INNER JOIN conversation_members cm ON c.id = cm.conversation_id
                    WHERE cm.user_id = ? $msgLeftAtCond
                    $deletedAtCond
                    {$where}
                ";
                $params = $includeExtracted
                    ? [$user_id, "%{$keyword}%", "%{$keyword}%"]
                    : [$user_id, "%{$keyword}%"];
                
                if ($conversation_id) { $sql .= " AND m.conversation_id = ?"; $params[] = $conversation_id; }
                if ($sender_id) { $sql .= " AND m.sender_id = ?"; $params[] = $sender_id; }
                if ($file_type && $hasMsgType) { $sql .= " AND m.message_type = ?"; $params[] = $file_type; }
                $sql .= " ORDER BY m.created_at DESC LIMIT " . (int)$msgLimit;
                return ['sql' => $sql, 'params' => $params];
            };
            
            try {
                $q = $searchMsgSql(true);
                $stmt = $pdo->prepare($q['sql']);
                $stmt->execute($q['params']);
            } catch (PDOException $e) {
                $q = $searchMsgSql(false);
                $stmt = $pdo->prepare($q['sql']);
                $stmt->execute($q['params']);
            }
            $results = array_merge($results, $stmt->fetchAll());
            } catch (Exception $e) {
                error_log('Search messages error: ' . $e->getMessage());
            }
        }
        
        successResponse(['results' => $results]);
        break;
        
    case 'mentions':
        // 自分がメンションされたメッセージ一覧
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $listMentionsHasDeletedAt = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'");
            $listMentionsHasDeletedAt = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.content,
                m.created_at,
                c.id as conversation_id,
                c.name as conversation_name,
                c.type as conversation_type,
                u.display_name as sender_name,
                u.avatar_path as sender_avatar
            FROM message_mentions mm
            INNER JOIN messages m ON m.id = mm.message_id
            INNER JOIN conversations c ON c.id = m.conversation_id
            INNER JOIN users u ON u.id = m.sender_id
            WHERE mm.mentioned_user_id = ?
            " . ($listMentionsHasDeletedAt ? " AND m.deleted_at IS NULL" : "") . "
            ORDER BY mm.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $limit, $offset]);
        $mentions = $stmt->fetchAll();
        
        // 数値型をキャスト・日時をクライアント用ISO 8601に
        foreach ($mentions as &$mention) {
            $mention['id'] = (int)$mention['id'];
            $mention['conversation_id'] = (int)$mention['conversation_id'];
            if (!empty($mention['created_at']) && function_exists('formatDatetimeForClient')) {
                $mention['created_at'] = formatDatetimeForClient($mention['created_at']);
            }
        }
        unset($mention);
        successResponse(['mentions' => $mentions]);
        break;
        
    case 'upload_image':
    case 'upload_file':
        // 画像・ファイルをアップロードしてメッセージとして送信
        $conversation_id = (int)($_POST['conversation_id'] ?? 0);
        
        if (!$conversation_id) {
            errorResponse('会話IDが必要です');
        }
        
        // 会話への参加確認（left_at が無い環境では conversation_id + user_id のみで判定）
        $uploadCmLeftAt = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM conversation_members LIKE 'left_at'");
            $uploadCmLeftAt = $chk && $chk->rowCount() > 0;
        } catch (Exception $e) {}
        $memberSql = "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?" . ($uploadCmLeftAt ? " AND left_at IS NULL" : "");
        $stmt = $pdo->prepare($memberSql);
        $stmt->execute([$conversation_id, $user_id]);
        if (!$stmt->fetch()) {
            errorResponse('この会話に参加していません');
        }
        
        // ファイルのアップロード処理
        if (!isset($_FILES['file'])) {
            // PHPのpost_max_size超過時は$_FILESが空になる。JSONで明示的に返す
            $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $hint = ($contentLen > 10 * 1024 * 1024) 
                ? 'ファイルが大きすぎます（10MB以下にしてください）。サーバー設定によりこれ以上は受け付けられません。' 
                : 'ファイルが送信されませんでした。';
            errorResponse($hint, 400);
        }
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errMsg = [
                UPLOAD_ERR_INI_SIZE => 'ファイルが大きすぎます（10MB以下にしてください）',
                UPLOAD_ERR_FORM_SIZE => 'ファイルが大きすぎます',
                UPLOAD_ERR_PARTIAL => 'ファイルが部分的にしか送信されませんでした。再度お試しください。',
                UPLOAD_ERR_NO_FILE => 'ファイルが選択されていません',
                UPLOAD_ERR_NO_TMP_DIR => 'サーバーエラー（一時ディレクトリ不足）',
                UPLOAD_ERR_CANT_WRITE => 'サーバーエラー（書き込み失敗）',
                UPLOAD_ERR_EXTENSION => 'サーバーエラー（拡張機能）'
            ];
            $msg = $errMsg[$_FILES['file']['error']] ?? 'ファイルのアップロードに失敗しました';
            errorResponse($msg, 400);
        }
        
        $file = $_FILES['file'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileTmp = $file['tmp_name'];
        $fileType = $file['type'];
        
        // ファイルサイズ制限（10MB）
        if ($fileSize > 10 * 1024 * 1024) {
            errorResponse('ファイルサイズは10MB以下にしてください');
        }
        
        // 拡張子を取得（拡張子チェックを優先）
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExts = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'heic', 'heif',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'pdf',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv', 'html', 'css', 'js', 'json', 'xml', 'md',
            'zip', 'rar', '7z', 'tar', 'gz',
            'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'
        ];
        
        // 拡張子が許可リストにあればOK
        if (in_array($ext, $allowedExts)) {
            // 許可
        } else {
            // 拡張子が許可されていない場合、MIMEタイプをチェック
            $allowedTypes = [
                // 画像（heic/heif: iOS標準形式）
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/x-icon',
                'image/heic', 'image/heif',
                // 動画
                'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska',
                // PDF
                'application/pdf',
                // Microsoft Office（様々なMIMEタイプに対応）
                'application/msword', // .doc
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/vnd.ms-excel', // .xls
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                'application/vnd.ms-powerpoint', // .ppt
                'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
                'application/octet-stream', // バイナリ（拡張子で判定）
                'application/x-ole-storage', // 古いOfficeファイル
                // テキスト・コード
                'text/plain', 'text/csv', 'text/html', 'text/css', 'text/javascript', 'text/markdown',
                'application/json', 'application/xml', 'text/xml',
                // 圧縮
                'application/zip', 'application/x-zip-compressed',
                'application/x-rar-compressed', 'application/x-7z-compressed',
                'application/gzip', 'application/x-tar',
                // 音声
                'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/flac', 'audio/aac', 'audio/x-m4a'
            ];
            
            if (!in_array($fileType, $allowedTypes)) {
                errorResponse('このファイル形式はサポートされていません: ' . $ext . ' (' . $fileType . ')');
            }
        }
        
        // アップロードディレクトリ（UPLOAD_DIR は config/app.php で定義）
        $baseUpload = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') : (__DIR__ . '/../uploads');
        $uploadDir = $baseUpload . '/messages/';
        if (!is_dir($baseUpload)) {
            if (!@mkdir($baseUpload, 0755, true)) {
                error_log('Upload base dir missing: ' . $baseUpload);
                errorResponse('アップロード用フォルダを作成できませんでした。サーバー管理者に権限を確認してください。');
            }
        }
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                error_log('Upload messages dir missing: ' . $uploadDir);
                errorResponse('メッセージ用アップロードフォルダを作成できませんでした。サーバー管理者に権限を確認してください。');
            }
        }
        if (!is_writable($uploadDir)) {
            error_log('Upload directory not writable: ' . $uploadDir);
            errorResponse('アップロードフォルダに書き込めません。サーバー管理者に uploads/messages/ の書き込み権限を確認してください。');
        }
        
        // ファイル名を生成
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $newFileName = uniqid('msg_') . '_' . time() . '.' . $ext;
        $uploadPath = $uploadDir . $newFileName;
        
        if (!move_uploaded_file($fileTmp, $uploadPath)) {
            error_log('move_uploaded_file failed. tmp=' . $fileTmp . ' dest=' . $uploadPath . ' exists=' . (file_exists($uploadDir) ? 'y' : 'n') . ' writable=' . (is_writable($uploadDir) ? 'y' : 'n'));
            errorResponse('ファイルの保存に失敗しました。uploads/messages/ の書き込み権限を確認するか、サーバー管理者に問い合わせてください。');
        }
        
        // ファイルパス
        $fileUrl = 'uploads/messages/' . $newFileName;
        
        // ファイルタイプに応じた絵文字を設定
        $emoji = '📎';
        if (strpos($fileType, 'image/') === 0) {
            $emoji = '📷';
        } elseif (strpos($fileType, 'video/') === 0) {
            $emoji = '🎬';
        } elseif (strpos($fileType, 'audio/') === 0) {
            $emoji = '🎵';
        } elseif ($fileType === 'application/pdf') {
            $emoji = '📄';
        } elseif (in_array($ext, ['doc', 'docx'])) {
            $emoji = '📝';
        } elseif (in_array($ext, ['xls', 'xlsx'])) {
            $emoji = '📊';
        } elseif (in_array($ext, ['ppt', 'pptx'])) {
            $emoji = '📽️';
        } elseif (in_array($ext, ['zip', 'rar', '7z'])) {
            $emoji = '📦';
        } elseif (in_array($ext, ['txt', 'csv', 'json', 'xml', 'html', 'css', 'js'])) {
            $emoji = '📃';
        }
        
        // 表示名（ユーザーが指定した場合）
        $displayName = trim($_POST['display_name'] ?? '');
        $displayName = mb_substr($displayName, 0, 200); // 長さ制限
        
        // 追加メッセージがあれば含める
        $additionalMessage = trim($_POST['message'] ?? '');
        if (!empty($additionalMessage)) {
            // メッセージ + 改行 + ファイル（表示名ありの場合は 絵文字 表示名\nパス）
            if (!empty($displayName)) {
                $content = $additionalMessage . "\n" . $emoji . ' ' . $displayName . "\n" . $fileUrl;
            } else {
                $content = $additionalMessage . "\n" . $emoji . ' ' . $fileUrl;
            }
        } else {
            if (!empty($displayName)) {
                $content = $emoji . ' ' . $displayName . "\n" . $fileUrl;
            } else {
                $content = $emoji . ' ' . $fileUrl;
            }
        }
        
        // To機能一時削除（Phase B）: mention_ids は受け取らない
        $mentionIds = [];
        
        // PDFファイルの場合、テキストを抽出（検索用）
        $extractedText = null;
        if ($ext === 'pdf' || $fileType === 'application/pdf') {
            try {
                $extractedText = extractPdfText($content);
            } catch (Exception $e) {
                error_log('[Upload] PDF text extraction error: ' . $e->getMessage());
            }
        }
        
        // メッセージ種別（画像は image、それ以外は file）。get で正しく返るよう明示する
        $messageType = (strpos($fileType, 'image/') === 0) ? 'image' : 'file';
        $hasContentType = false;
        $hasMessageTypeCol = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'content_type'");
            $hasContentType = $c && $c->rowCount() > 0;
            $c = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_type'");
            $hasMessageTypeCol = $c && $c->rowCount() > 0;
        } catch (Exception $e) {}
        $typeCol = '';
        $typePlace = '';
        if ($hasContentType) { $typeCol = 'content_type'; $typePlace = ', ?'; }
        elseif ($hasMessageTypeCol) { $typeCol = 'message_type'; $typePlace = ', ?'; }
        $typeParam = ($typeCol !== '') ? [$messageType] : [];
        // メッセージとして保存（extracted_text・種別付き）
        $message_id = null;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO messages (conversation_id, sender_id, content" . ($typeCol ? ", {$typeCol}" : "") . ", extracted_text)
                VALUES (?, ?, ?" . $typePlace . ", ?)
            ");
            $stmt->execute(array_merge([$conversation_id, $user_id, $content], $typeParam, [$extractedText]));
            $message_id = $pdo->lastInsertId();
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // extracted_text または type カラムがない場合は最小カラムで挿入
            if (strpos($msg, 'extracted_text') !== false || ($typeCol !== '' && strpos($msg, $typeCol) !== false)) {
                $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)");
                $stmt->execute([$conversation_id, $user_id, $content]);
                $message_id = $pdo->lastInsertId();
            } else {
                throw $e;
            }
        }
        if ($message_id && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
            try { $pdo->commit(); } catch (Exception $e) {}
        }
        
        // メンション情報を保存（失敗してもファイル送信は成功を返す）。Phase C: 本文から [To:all]/[To:ID] をパース
        $hasToAll = false;
        $toMemberIds = [];
        if (!empty($mentionIds)) {
            $toMemberIds = array_map('intval', array_filter($mentionIds, function ($id) { return $id !== 'all'; }));
            if (in_array('all', $mentionIds, true)) $hasToAll = true;
        } elseif ($content !== '' && $message_id) {
            if (preg_match('/\[To:all\]/i', $content)) {
                $hasToAll = true;
            } elseif (preg_match_all('/\[To:(\d+)\]/', $content, $m)) {
                $toMemberIds = array_values(array_unique(array_map('intval', $m[1])));
            }
        }
        if ($hasToAll || !empty($toMemberIds)) {
            try {
                $hasMentionType = false;
                $chkMt = $pdo->query("SHOW COLUMNS FROM message_mentions LIKE 'mention_type'");
                $hasMentionType = $chkMt && $chkMt->rowCount() > 0;
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'message_mentions'");
                if ($tableCheck && $tableCheck->fetch()) {
                    $insertCols = $hasMentionType
                        ? "message_id, mentioned_user_id, mention_type, created_at"
                        : "message_id, mentioned_user_id, created_at";
                    if ($hasToAll) {
                        $cmLeftClause = $uploadCmLeftAt ? " AND left_at IS NULL" : "";
                        $stmt = $pdo->prepare("
                            SELECT user_id FROM conversation_members
                            WHERE conversation_id = ? AND user_id != ?{$cmLeftClause}
                        ");
                        $stmt->execute([$conversation_id, $user_id]);
                        $insertVals = $hasMentionType ? "?, ?, 'to_all', NOW()" : "?, ?, NOW()";
                        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                            $pdo->prepare("INSERT IGNORE INTO message_mentions ({$insertCols}) VALUES ({$insertVals})")
                                ->execute([$message_id, $memberId]);
                        }
                    }
                    foreach ($toMemberIds as $mid) {
                        if ($mid <= 0) continue;
                        $insertVals = $hasMentionType ? "?, ?, 'to', NOW()" : "?, ?, NOW()";
                        $pdo->prepare("INSERT IGNORE INTO message_mentions ({$insertCols}) VALUES ({$insertVals})")
                            ->execute([$message_id, $mid]);
                    }
                }
            } catch (Exception $e) {
                error_log('[messages.php upload_file] mention save failed: ' . $e->getMessage());
            }
        }
        
        // 会話の最終メッセージを更新（エラーを無視）
        try {
            $stmt = $pdo->prepare("UPDATE conversations SET last_message = ? WHERE id = ?");
            $stmt->execute([$content, $conversation_id]);
        } catch (Exception $e) {
            // 無視
        }
        
        // クライアントで即時表示するため、送信メッセージと同形式の message オブジェクトを返す（失敗時は最小限のオブジェクトで成功を返す）
        $message = null;
        try {
            $msgStmt = $pdo->prepare("
                SELECT 
                    m.id,
                    m.conversation_id,
                    m.sender_id,
                    m.content,
                    m.reply_to_id,
                    m.created_at,
                    u.display_name AS sender_name,
                    u.avatar_path AS sender_avatar,
                    rm.content AS reply_to_content,
                    ru.display_name AS reply_to_sender_name
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN messages rm ON m.reply_to_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                WHERE m.id = ?
            ");
            $msgStmt->execute([$message_id]);
            $message = $msgStmt->fetch(PDO::FETCH_ASSOC);
            if ($message) {
                $message['id'] = (int)$message['id'];
                $message['conversation_id'] = (int)$message['conversation_id'];
                $message['sender_id'] = (int)$message['sender_id'];
                $message['is_edited'] = (int)($message['is_edited'] ?? 0);
                $message['message_type'] = $message['message_type'] ?? 'text';
                $message['reply_to_id'] = isset($message['reply_to_id']) && $message['reply_to_id'] !== '' && (int)$message['reply_to_id'] > 0 ? (int)$message['reply_to_id'] : null;
                $message['reply_to_content'] = isset($message['reply_to_content']) ? $message['reply_to_content'] : null;
                $message['reply_to_sender_name'] = isset($message['reply_to_sender_name']) ? $message['reply_to_sender_name'] : null;
                if (!empty($message['created_at']) && function_exists('formatDatetimeForClient')) {
                    $message['created_at'] = formatDatetimeForClient($message['created_at']);
                }
                $message['is_mentioned_me'] = false;
                $message['mention_type'] = null;
                if ($hasToAll) {
                    $message['has_to_all'] = true;
                    $message['show_to_all_badge'] = true;
                }
                if (!empty($toMemberIds)) {
                    $message['to_member_ids'] = $toMemberIds;
                    $message['show_to_badge'] = true;
                    $message['to_member_ids_list'] = array_values(array_unique(array_map('intval', $toMemberIds)));
                }
            }
        } catch (Throwable $e) {
            error_log('[messages.php upload_file] message fetch failed: ' . $e->getMessage() . ' message_id=' . $message_id);
            // 最小限の message を組み立て（クライアントで表示できるように）
            $senderName = '';
            try {
                $su = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
                $su->execute([$user_id]);
                $row = $su->fetch(PDO::FETCH_ASSOC);
                $senderName = $row['display_name'] ?? '';
            } catch (Exception $e2) {}
            $message = [
                'id' => (int)$message_id,
                'conversation_id' => (int)$conversation_id,
                'sender_id' => (int)$user_id,
                'content' => $content,
                'message_type' => 'text',
                'reply_to_id' => null,
                'created_at' => function_exists('formatDatetimeForClient') ? formatDatetimeForClient(date('Y-m-d H:i:s')) : date('c'),
                'is_edited' => 0,
                'sender_name' => $senderName,
                'sender_avatar' => null,
                'reply_to_content' => null,
                'reply_to_sender_name' => null,
                'is_mentioned_me' => false,
                'mention_type' => null,
            ];
            if ($hasToAll) {
                $message['has_to_all'] = true;
                $message['show_to_all_badge'] = true;
            }
            if (!empty($toMemberIds)) {
                $message['to_member_ids'] = $toMemberIds;
                $message['show_to_badge'] = true;
                $message['to_member_ids_list'] = array_values(array_unique(array_map('intval', $toMemberIds)));
            }
        }
        
        successResponse([
            'message_id' => (int)$message_id,
            'message' => $message,
            'file_url' => $fileUrl,
            'file_name' => $fileName,
            'has_to_all' => $hasToAll,
            'to_member_ids' => $toMemberIds
        ]);
        break;
        
    case 'debug_to':
        // To機能のデバッグ: サーバー上のファイルバージョン・テーブル構造・最新メンションを確認
        $debug = ['action' => 'debug_to', 'server_time' => date('Y-m-d H:i:s')];
        // message_mentions テーブル
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM message_mentions")->fetchAll(PDO::FETCH_COLUMN);
            $debug['message_mentions_columns'] = $cols;
        } catch (Throwable $e) { $debug['message_mentions_error'] = $e->getMessage(); }
        // 最新5件の message_mentions
        try {
            $rows = $pdo->query("SELECT mm.id, mm.message_id, mm.mentioned_user_id, mm.mention_type, m.content FROM message_mentions mm LEFT JOIN messages m ON mm.message_id = m.id ORDER BY mm.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            $debug['latest_mentions'] = $rows;
        } catch (Throwable $e) { $debug['latest_mentions_error'] = $e->getMessage(); }
        // Phase C マーカー（このファイルが最新版か）
        $debug['phase_c_marker'] = 'ensureMessageMentionsMentionType_v2_2026-02';
        // テストパース
        $testContent = '[To:112]Neoさん テスト';
        $debug['test_parse_all'] = (bool) preg_match('/\[To:all\]/i', $testContent);
        $debug['test_parse_ids'] = [];
        if (preg_match_all('/\[To:(\d+)\]/', $testContent, $tm)) {
            $debug['test_parse_ids'] = array_map('intval', $tm[1]);
        }
        successResponse($debug);
        break;

    default:
        errorResponse('不明なアクションです');
}

/**
 * Wishを抽出してtasksテーブルに自動保存
 */
function extractAndSaveWishes($pdo, $message, $user_id, $message_id) {
    try {
        // wish_patternsテーブルが存在するか確認
        $stmt = $pdo->query("SHOW TABLES LIKE 'wish_patterns'");
        if (!$stmt->fetch()) {
            error_log("Wish extraction: wish_patterns table not found");
            return; // テーブルがなければスキップ
        }
        
        // tasksテーブルのカラム確認
        $taskColumns = [];
        $colCheck = $pdo->query("SHOW COLUMNS FROM tasks");
        while ($col = $colCheck->fetch(PDO::FETCH_ASSOC)) {
            $taskColumns[] = $col['Field'];
        }
        
        // 必須カラム確認
        $requiredColumns = ['source', 'source_message_id', 'original_text', 'category', 'confidence'];
        $missingColumns = array_diff($requiredColumns, $taskColumns);
        if (!empty($missingColumns)) {
            error_log("Wish extraction: Missing columns in tasks table: " . implode(', ', $missingColumns));
            return;
        }
        
        // wish_patternsテーブルのカラム確認
        $patternColumns = [];
        $colCheck = $pdo->query("SHOW COLUMNS FROM wish_patterns");
        while ($col = $colCheck->fetch(PDO::FETCH_ASSOC)) {
            $patternColumns[] = $col['Field'];
        }
        $hasPriority = in_array('priority', $patternColumns);
        $hasExtractGroup = in_array('extract_group', $patternColumns);
        $hasSuffixRemove = in_array('suffix_remove', $patternColumns);
        
        // 長文判定（100文字以上）- 長文の場合はAI分析を優先
        $isLongText = mb_strlen($message) >= 100;
        
        if ($isLongText && defined('AI_WISH_EXTRACTION_ENABLED') && AI_WISH_EXTRACTION_ENABLED && isAIExtractionAvailable()) {
            // 長文の場合はAI分析で意味を理解して要約
            $aiResult = extractWishWithAI($message, $_SESSION['language'] ?? 'ja', true);
            
            if ($aiResult && $aiResult['has_wish'] && !empty($aiResult['wishes'])) {
                $aiWish = $aiResult['wishes'][0];
                $wishText = $aiWish['text'];
                $category = $aiWish['category'] ?? 'other';
                
                // 重複チェック
                $dupCheck = $pdo->prepare("
                    SELECT id FROM tasks 
                    WHERE created_by = ? 
                    AND title = ? 
                    AND source IN ('ai_extracted', 'ai_summarized')
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $dupCheck->execute([$user_id, $wishText]);
                
                if (!$dupCheck->fetch()) {
                    $description = "AI要約: 「" . mb_substr($message, 0, 80) . (mb_strlen($message) > 80 ? '...' : '') . "」";
                    $pdo->prepare("
                        INSERT INTO tasks (
                            created_by, title, description, status, priority,
                            source, source_message_id, original_text, confidence, category,
                            created_at
                        ) VALUES (
                            ?, ?, ?, 'pending', 1,
                            'ai_summarized', ?, ?, ?, ?,
                            NOW()
                        )
                    ")->execute([
                        $user_id,
                        $wishText,
                        $description,
                        $message_id,
                        mb_substr($message, 0, 200),
                        $aiWish['confidence'] ?? 0.8,
                        $category
                    ]);
                    error_log("Wish extraction: AI summarized wish saved - " . $wishText);
                }
            }
            return; // AI分析を行った場合はここで終了
        }
        
        // 短文の場合はパターンマッチング
        // アクティブなパターンを優先度順に取得（priorityカラムがない場合はidで代用）
        $orderBy = $hasPriority ? 'priority DESC, id ASC' : 'id ASC';
        $stmt = $pdo->query("
            SELECT * FROM wish_patterns 
            WHERE is_active = 1 
            ORDER BY {$orderBy}
        ");
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($patterns)) {
            error_log("Wish extraction: No active patterns found");
            return;
        }
        
        // メッセージを行ごとに分割して解析（UTF-8対応）
        $lines = preg_split('/[。！!？?\n]+/u', $message);
        
        // 1メッセージにつき最大1つのWishのみ抽出
        $wishSaved = false;
        
        foreach ($lines as $line) {
            // 既にWishが保存されている場合はスキップ
            if ($wishSaved) {
                break;
            }
            
            $line = trim($line);
            if (empty($line) || mb_strlen($line) < 3) {
                continue;
            }
            
            foreach ($patterns as $pattern) {
                // パターンが正規表現かどうか判定
                $patternStr = $pattern['pattern'];
                $isRegex = (strpos($patternStr, '(') !== false || strpos($patternStr, '[') !== false);
                
                if ($isRegex) {
                    // 正規表現パターン
                    $regex = '/' . $patternStr . '/u';
                } else {
                    // 単純な文字列パターン - 正規表現に変換
                    $regex = '/(.*)' . preg_quote($patternStr, '/') . '/u';
                }
                
                if (@preg_match($regex, $line, $matches)) {
                    // extract_groupカラムがある場合は使用、なければ0
                    $groupNum = $hasExtractGroup ? ((int)($pattern['extract_group'] ?? 0)) : 0;
                    $wishText = $matches[$groupNum] ?? $matches[0];
                    
                    // 接尾辞を除去（suffix_removeカラムがある場合のみ）
                    if ($hasSuffixRemove && !empty($pattern['suffix_remove'])) {
                        $suffixes = explode(',', $pattern['suffix_remove']);
                        foreach ($suffixes as $suffix) {
                            $wishText = rtrim($wishText, trim($suffix));
                        }
                    }
                    
                    $wishText = trim($wishText);
                    
                    // 短すぎるものは除外
                    if (mb_strlen($wishText) < 2) {
                        continue;
                    }
                    
                    // 24時間以内の重複チェック
                    $dupCheck = $pdo->prepare("
                        SELECT id FROM tasks 
                        WHERE created_by = ? 
                        AND title = ? 
                        AND source = 'ai_extracted'
                        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ");
                    $dupCheck->execute([$user_id, $wishText]);
                    
                    if (!$dupCheck->fetch()) {
                        // Wishをtasksテーブルに保存
                        $description = "自動抽出: 「{$line}」";
                        $pdo->prepare("
                            INSERT INTO tasks (
                                created_by, title, description, status, priority,
                                source, source_message_id, original_text, confidence, category,
                                created_at
                            ) VALUES (
                                ?, ?, ?, 'pending', 1,
                                'ai_extracted', ?, ?, 0.80, ?,
                                NOW()
                            )
                        ")->execute([
                            $user_id,
                            $wishText,
                            $description,
                            $message_id,
                            $line,
                            $pattern['category'] ?? 'other'
                        ]);
                        
                        error_log("Wish extraction: Pattern matched wish saved - " . $wishText . " (pattern: " . $patternStr . ")");
                        $wishSaved = true;
                        // 1メッセージにつき1つのWishのみ
                        break 2; // 両方のループを抜ける
                    }
                    
                    // 1行につき1パターンのみマッチ
                    break;
                }
            }
        }
        
        // AI抽出が有効で、パターンマッチングで抽出できなかった場合
        if (!$wishSaved && defined('AI_WISH_EXTRACTION_ENABLED') && AI_WISH_EXTRACTION_ENABLED 
            && (!defined('AI_EXTRACTION_FALLBACK_ONLY') || AI_EXTRACTION_FALLBACK_ONLY)
            && isAIExtractionAvailable()) {
            
            $aiResult = extractWishWithAI($message, $_SESSION['language'] ?? 'ja', false);
            
            if ($aiResult && $aiResult['has_wish'] && !empty($aiResult['wishes'])) {
                $aiWish = $aiResult['wishes'][0];
                $wishText = $aiWish['text'];
                $category = $aiWish['category'] ?? 'other';
                
                // 重複チェック
                $dupCheck = $pdo->prepare("
                    SELECT id FROM tasks 
                    WHERE created_by = ? 
                    AND title = ? 
                    AND source = 'ai_extracted'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $dupCheck->execute([$user_id, $wishText]);
                
                if (!$dupCheck->fetch()) {
                    $description = "AI抽出: 「{$message}」";
                    $pdo->prepare("
                        INSERT INTO tasks (
                            created_by, title, description, status, priority,
                            source, source_message_id, original_text, confidence, category,
                            created_at
                        ) VALUES (
                            ?, ?, ?, 'pending', 1,
                            'ai_extracted', ?, ?, ?, ?,
                            NOW()
                        )
                    ")->execute([
                        $user_id,
                        $wishText,
                        $description,
                        $message_id,
                        mb_substr($message, 0, 200),
                        $aiWish['confidence'] ?? 0.8,
                        $category
                    ]);
                    error_log("Wish extraction: AI fallback wish saved - " . $wishText);
                }
            }
        }
    } catch (Exception $e) {
        // Wish抽出エラーはログに記録するが、メッセージ送信は継続
        error_log("Wish extraction error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
}

/**
 * メンションを抽出（検索用にユーザー情報を返す）
 */
function extractMentions($content, $pdo) {
    // @ユーザー名 を抽出（日本語対応）
    preg_match_all('/@([a-zA-Z0-9_\p{L}]+)/u', $content, $matches);
    
    if (empty($matches[1])) {
        return [];
    }
    
    $mentionedUsers = [];
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE display_name = ? LIMIT 1");
    
    foreach (array_unique($matches[1]) as $username) {
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user) {
            $mentionedUsers[] = [
                'id' => (int)$user['id'],
                'display_name' => $user['display_name']
            ];
        }
    }
    
    return $mentionedUsers;
}
