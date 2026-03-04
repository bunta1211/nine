<?php
/**
 * 友だち管理API
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/online_status.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/friend_request_mail.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$lang = getCurrentLanguage();

// ユーザーのアクティビティを更新
updateUserActivity($pdo, $user_id);

// 非アクティブユーザーを更新
updateInactiveUsers($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // 友だちリスト取得
            $status = $_GET['status'] ?? 'accepted';
            $stmt = $pdo->prepare("
                SELECT 
                    f.id as friendship_id,
                    f.status,
                    f.nickname,
                    f.memo,
                    f.is_favorite,
                    f.group_name,
                    f.created_at as friendship_created,
                    u.id as user_id,
                    u.display_name,
                    u.display_name_en,
                    u.display_name_zh,
                    u.email,
                    u.avatar,
                    u.online_status,
                    u.last_activity
                FROM friendships f
                JOIN users u ON f.friend_id = u.id
                WHERE f.user_id = ? AND f.status = ?
                ORDER BY f.is_favorite DESC, u.display_name ASC
            ");
            $stmt->execute([$user_id, $status]);
            $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // オンライン状態のラベルを追加
            foreach ($friends as &$friend) {
                $friend['online_status_label'] = getOnlineStatusLabel($friend['online_status'], $lang);
                $friend['online_status_color'] = getOnlineStatusColor($friend['online_status']);
                $friend['last_activity_formatted'] = formatLastActivity($friend['last_activity'], $lang);
                $friend['is_favorite'] = (int)$friend['is_favorite'];
            }
            
            echo json_encode(['success' => true, 'friends' => $friends]);
            break;
            
        case 'pending':
            // 受信した友だち申請（request_message 含む、カラム未存在時は除外）
            $selectCols = "f.id as friendship_id, f.created_at as requested_at, u.id as user_id, u.display_name, u.email, u.online_status, u.last_activity";
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM friendships LIKE 'request_message'");
                if ($chk && $chk->rowCount() > 0) {
                    $selectCols .= ", f.request_message";
                }
                $chkAvatar = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
                $selectCols .= ($chkAvatar && $chkAvatar->rowCount() > 0) ? ", u.avatar_path as avatar" : "";
            } catch (Exception $e) {}
            $stmt = $pdo->prepare("
                SELECT $selectCols
                FROM friendships f
                JOIN users u ON f.user_id = u.id
                WHERE f.friend_id = ? AND f.status = 'pending'
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($requests as &$request) {
                $request['online_status_label'] = getOnlineStatusLabel($request['online_status'], $lang);
                $request['online_status_color'] = getOnlineStatusColor($request['online_status']);
                $request['last_activity_formatted'] = formatLastActivity($request['last_activity'], $lang);
            }
            
            echo json_encode(['success' => true, 'requests' => $requests]);
            break;
            
        case 'sent':
            // 送信した友だち申請
            $stmt = $pdo->prepare("
                SELECT 
                    f.id as friendship_id,
                    f.created_at as requested_at,
                    u.id as user_id,
                    u.display_name,
                    u.email,
                    u.avatar
                FROM friendships f
                JOIN users u ON f.friend_id = u.id
                WHERE f.user_id = ? AND f.status = 'pending'
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $sent = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'sent' => $sent]);
            break;
            
        case 'add':
            // 友だち追加申請（メッセージ付き、source 指定可）
            $friend_email = $input['email'] ?? '';
            $friend_id = $input['friend_id'] ?? null;
            $request_message = isset($input['message']) ? mb_substr(trim($input['message']), 0, 500) : null;
            $source = in_array($input['source'] ?? '', ['search', 'qr', 'invite_link', 'group']) ? $input['source'] : 'search';
            
            if (!$friend_email && !$friend_id) {
                echo json_encode(['success' => false, 'error' => 'メールアドレスまたはユーザーIDが必要です']);
                exit;
            }
            
            // ユーザーを検索
            if ($friend_email) {
                $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$friend_email, $user_id]);
            } else {
                $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id = ? AND id != ?");
                $stmt->execute([$friend_id, $user_id]);
            }
            $friend = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$friend) {
                echo json_encode(['success' => false, 'error' => 'ユーザーが見つかりません']);
                exit;
            }
            
            // 既存の関係をチェック
            $stmt = $pdo->prepare("SELECT status FROM friendships WHERE user_id = ? AND friend_id = ?");
            $stmt->execute([$user_id, $friend['id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['status'] === 'accepted') {
                    echo json_encode(['success' => false, 'error' => '既に友だちです']);
                } elseif ($existing['status'] === 'pending') {
                    echo json_encode(['success' => false, 'error' => '申請済みです']);
                } elseif ($existing['status'] === 'blocked') {
                    echo json_encode(['success' => false, 'error' => 'このユーザーはブロックされています']);
                } else {
                    // rejected の場合は再申請可能
                    try {
                        $chkCol = $pdo->query("SHOW COLUMNS FROM friendships LIKE 'request_message'");
                        if ($chkCol && $chkCol->rowCount() > 0) {
                            $stmt = $pdo->prepare("UPDATE friendships SET status = 'pending', request_message = ?, source = ?, updated_at = NOW() WHERE user_id = ? AND friend_id = ?");
                            $stmt->execute([$request_message ?: null, $source, $user_id, $friend['id']]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE friendships SET status = 'pending', updated_at = NOW() WHERE user_id = ? AND friend_id = ?");
                            $stmt->execute([$user_id, $friend['id']]);
                        }
                    } catch (Exception $e) {
                        $stmt = $pdo->prepare("UPDATE friendships SET status = 'pending', updated_at = NOW() WHERE user_id = ? AND friend_id = ?");
                        $stmt->execute([$user_id, $friend['id']]);
                    }
                    sendFriendRequestNotification($pdo, $user_id, (int)$friend['id']);
                    echo json_encode(['success' => true, 'message' => '友だち申請を送信しました']);
                }
                exit;
            }
            
            // 未成年同士の友達申請制限：search 経由は不可、qr/invite_link のみ可
            $cutoff = date('Y-m-d', strtotime('-15 years'));
            $stmtBirth = $pdo->prepare("SELECT birth_date FROM users WHERE id = ?");
            $stmtBirth->execute([$user_id]);
            $reqBirth = $stmtBirth->fetch(PDO::FETCH_ASSOC);
            $stmtBirth->execute([$friend['id']]);
            $tgtBirth = $stmtBirth->fetch(PDO::FETCH_ASSOC);
            $isRequesterMinor = ($reqBirth && !empty($reqBirth['birth_date']) && $reqBirth['birth_date'] > $cutoff);
            $isTargetMinor = ($tgtBirth && !empty($tgtBirth['birth_date']) && $tgtBirth['birth_date'] > $cutoff);
            if ($isRequesterMinor && $isTargetMinor && $source === 'search') {
                echo json_encode(['success' => false, 'error' => '未成年同士の友達申請は、QRコードまたは招待リンク経由でのみ可能です']);
                exit;
            }
            
            // 相手からの申請があるかチェック
            $stmt = $pdo->prepare("SELECT id, status FROM friendships WHERE user_id = ? AND friend_id = ?");
            $stmt->execute([$friend['id'], $user_id]);
            $reverse = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reverse && $reverse['status'] === 'pending') {
                // 相手からの申請があれば、両方を承認
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reverse['id']]);
                
                $stmt = $pdo->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                $stmt->execute([$user_id, $friend['id']]);
                
                echo json_encode(['success' => true, 'message' => '友だちになりました！', 'status' => 'accepted']);
                exit;
            }
            
            // 相手にブロックされている場合は申請不可
            if ($reverse && $reverse['status'] === 'blocked') {
                echo json_encode(['success' => false, 'error' => 'このユーザーからブロックされているため申請できません']);
                exit;
            }
            
            // 新規申請（request_message, source 付き）
            $cols = "user_id, friend_id, status";
            $vals = "?, ?, 'pending'";
            $params = [$user_id, $friend['id']];
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM friendships LIKE 'request_message'");
                if ($chk && $chk->rowCount() > 0) {
                    $cols .= ", request_message, source";
                    $vals .= ", ?, ?";
                    $params[] = $request_message ?: null;
                    $params[] = $source;
                }
            } catch (Exception $e) {}
            $stmt = $pdo->prepare("INSERT INTO friendships ($cols) VALUES ($vals)");
            $stmt->execute($params);
            
            sendFriendRequestNotification($pdo, $user_id, (int)$friend['id']);
            echo json_encode(['success' => true, 'message' => '友だち申請を送信しました']);
            break;
            
        case 'accept':
            // 友だち申請を承認
            $friendship_id = $input['friendship_id'] ?? null;
            
            if (!$friendship_id) {
                echo json_encode(['success' => false, 'error' => '申請IDが必要です']);
                exit;
            }
            
            // 申請を取得
            $stmt = $pdo->prepare("SELECT user_id, friend_id FROM friendships WHERE id = ? AND friend_id = ? AND status = 'pending'");
            $stmt->execute([$friendship_id, $user_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                echo json_encode(['success' => false, 'error' => '申請が見つかりません']);
                exit;
            }
            
            // 承認
            $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$friendship_id]);
            
            // 逆方向の友だち関係も作成
            $stmt = $pdo->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'accepted') ON DUPLICATE KEY UPDATE status = 'accepted', updated_at = NOW()");
            $stmt->execute([$user_id, $request['user_id']]);
            
            echo json_encode(['success' => true, 'message' => '友だち申請を承認しました']);
            break;
            
        case 'reject':
            // 友だち申請を拒否
            $friendship_id = $input['friendship_id'] ?? null;
            
            if (!$friendship_id) {
                echo json_encode(['success' => false, 'error' => '申請IDが必要です']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE friendships SET status = 'rejected', updated_at = NOW() WHERE id = ? AND friend_id = ? AND status = 'pending'");
            $stmt->execute([$friendship_id, $user_id]);
            
            echo json_encode(['success' => true, 'message' => '友だち申請を拒否しました']);
            break;
            
        case 'defer':
            // 受信した申請を保留（申請レコードを削除し、自分・相手両方から申請事実を消す）
            $friendship_id = $input['friendship_id'] ?? null;
            if (!$friendship_id) {
                echo json_encode(['success' => false, 'error' => '申請IDが必要です']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ? AND friend_id = ? AND status = 'pending'");
            $stmt->execute([$friendship_id, $user_id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => '保留できる申請がありません']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => '申請を保留しました（双方の一覧から消えました）']);
            break;
            
        case 'cancel_sent':
            // 送信した友だち申請を取り消す（自分が申請者で status=pending のもののみ）
            $friend_id = $input['friend_id'] ?? null;
            if (!$friend_id) {
                echo json_encode(['success' => false, 'error' => '相手のユーザーIDが必要です']);
                exit;
            }
            $friend_id = (int) $friend_id;
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'pending'");
            $stmt->execute([$user_id, $friend_id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => '取り消せる申請がありません']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => '友だち申請を取り消しました']);
            break;
            
        case 'remove':
            // 友だちを削除
            $friend_id = $input['friend_id'] ?? null;
            
            if (!$friend_id) {
                echo json_encode(['success' => false, 'error' => '友だちIDが必要です']);
                exit;
            }
            
            // 双方向の関係を削除
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
            
            echo json_encode(['success' => true, 'message' => '友だちを削除しました']);
            break;
            
        case 'block':
            // ブロック（相手から自分への pending 申請があれば削除し、相手には再申請不可になる）
            $friend_id = $input['friend_id'] ?? null;
            
            if (!$friend_id) {
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }
            $friend_id = (int) $friend_id;
            
            // 既存の関係を更新またはブロック作成
            $stmt = $pdo->prepare("
                INSERT INTO friendships (user_id, friend_id, status) 
                VALUES (?, ?, 'blocked') 
                ON DUPLICATE KEY UPDATE status = 'blocked', updated_at = NOW()
            ");
            $stmt->execute([$user_id, $friend_id]);
            
            // 相手→自分 の pending 申請を削除（受信した申請から消す）
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'pending'");
            $stmt->execute([$friend_id, $user_id]);
            
            echo json_encode(['success' => true, 'message' => 'ユーザーをブロックしました']);
            break;
            
        case 'unblock':
            // ブロック解除
            $friend_id = $input['friend_id'] ?? null;
            
            if (!$friend_id) {
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'blocked'");
            $stmt->execute([$user_id, $friend_id]);
            
            echo json_encode(['success' => true, 'message' => 'ブロックを解除しました']);
            break;
            
        case 'blocked':
            // ブロックリスト取得
            $stmt = $pdo->prepare("
                SELECT 
                    f.id as friendship_id,
                    f.created_at as blocked_at,
                    u.id as user_id,
                    u.display_name,
                    u.email,
                    u.avatar
                FROM friendships f
                JOIN users u ON f.friend_id = u.id
                WHERE f.user_id = ? AND f.status = 'blocked'
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'blocked' => $blocked]);
            break;
            
        case 'group_members':
            $current_role = $_SESSION['role'] ?? null;
            if ($current_role === null || $current_role === '') {
                $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmtRole->execute([$user_id]);
                $row = $stmtRole->fetch(PDO::FETCH_ASSOC);
                $current_role = $row['role'] ?? 'user';
                $_SESSION['role'] = $current_role;
            }
            $is_system_admin = in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin']);
            
            if ($is_system_admin) {
                // システム管理者は全ユーザーを取得
                $stmt = $pdo->prepare("
                    SELECT u.id, u.display_name, u.avatar_path, u.online_status, '全ユーザー' as group_names
                    FROM users u
                    WHERE u.id != ? AND u.status = 'active'
                    ORDER BY u.display_name ASC
                ");
                $stmt->execute([$user_id]);
            } else {
                // allow_member_dmカラムの存在確認（NULL/0以外＝許可扱い、環境差異対応）
                $dmCond = '';
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'allow_member_dm'");
                    if ($chk && $chk->rowCount() > 0) {
                        $dmCond = ' AND (c.allow_member_dm IS NULL OR c.allow_member_dm != 0)';
                    }
                } catch (PDOException $e) {}
                // 自分が所属するグループのメンバー + システム管理者を取得（重複排除）
                // allow_member_dm=1 のグループのメンバーのみ（メンバー間DM許可のグループ）
                $stmt = $pdo->prepare("
                (
                    SELECT DISTINCT
                        u.id,
                        u.display_name,
                        u.avatar_path,
                        u.online_status,
                        GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as group_names
                    FROM users u
                    INNER JOIN conversation_members cm ON u.id = cm.user_id AND cm.left_at IS NULL
                    INNER JOIN conversations c ON cm.conversation_id = c.id AND c.type IN ('group', 'organization') $dmCond
                    INNER JOIN conversation_members my_cm ON c.id = my_cm.conversation_id 
                        AND my_cm.user_id = ? AND my_cm.left_at IS NULL
                    WHERE u.id != ?
                    GROUP BY u.id, u.display_name, u.avatar_path, u.online_status
                )
                UNION
                (
                    SELECT
                        u.id,
                        u.display_name,
                        u.avatar_path,
                        u.online_status,
                        'システム管理者' as group_names
                    FROM users u
                    WHERE u.role = 'system_admin'
                    AND u.id != ?
                    AND u.status = 'active'
                )
                ORDER BY display_name ASC
            ");
                $stmt->execute([$user_id, $user_id, $user_id]);
            }
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 重複排除（システム管理者がグループメンバーでもいる場合）
            $seen = [];
            $members = [];
            foreach ($raw as $m) {
                $id = (int)$m['id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $m['id'] = $id;
                    $members[] = $m;
                }
            }
            
            echo json_encode(['success' => true, 'members' => $members]);
            break;
            
        case 'search':
            // 友達追加検索: Email または 携帯番号 のみで検索（表示名は使わない）
            $query = trim($input['query'] ?? $_GET['query'] ?? $_GET['q'] ?? '');
            
            if (strlen($query) < 2) {
                echo json_encode(['success' => false, 'error' => '2文字以上入力してください']);
                exit;
            }
            
            $isEmail = (strpos($query, '@') !== false || filter_var($query, FILTER_VALIDATE_EMAIL) !== false);
            $phoneDigits = preg_replace('/\D/', '', $query);
            $isPhone = (strlen($phoneDigits) >= 10);
            
            if (!$isEmail && !$isPhone) {
                echo json_encode(['success' => true, 'users' => [], 'invite_available' => false]);
                exit;
            }
            
            $current_role = $_SESSION['role'] ?? null;
            if ($current_role === null || $current_role === '') {
                $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmtRole->execute([$user_id]);
                $row = $stmtRole->fetch(PDO::FETCH_ASSOC);
                $current_role = $row['role'] ?? 'user';
                $_SESSION['role'] = $current_role;
            }
            $is_system_admin = in_array($current_role, ['system_admin', 'developer', 'org_admin', 'admin']);
            
            // 15歳未満除外条件
            $ageCond = '';
            try {
                $chkBirth = $pdo->query("SHOW COLUMNS FROM users LIKE 'birth_date'");
                if ($chkBirth && $chkBirth->rowCount() > 0) {
                    $ageCond = " AND (u.birth_date IS NULL OR u.birth_date <= DATE_SUB(CURDATE(), INTERVAL 15 YEAR))";
                }
            } catch (Exception $e) {}
            
            $users = [];
            $emailToReturn = null;
            if ($isEmail) {
                $emailNorm = strtolower(trim($query));
                $stmt = $pdo->prepare("
                    SELECT DISTINCT
                        u.id,
                        u.display_name,
                        u.email,
                        u.avatar_path as avatar,
                        u.online_status,
                        u.last_active_at as last_activity,
                        f.status as friendship_status
                    FROM users u
                    LEFT JOIN friendships f ON (f.user_id = ? AND f.friend_id = u.id) OR (f.user_id = u.id AND f.friend_id = ?)
                    WHERE u.id != ? AND u.status = 'active'
                    AND (u.email = ? OR u.email LIKE ?)
                    $ageCond
                    ORDER BY u.display_name ASC
                    LIMIT 20
                ");
                $like = $emailNorm . '%';
                $stmt->execute([$user_id, $user_id, $user_id, $emailNorm, $like]);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($users) === 0 && filter_var($emailNorm, FILTER_VALIDATE_EMAIL) !== false) {
                    $emailToReturn = $emailNorm;
                }
            }
            if (count($users) === 0 && $isPhone) {
                $phonePat = '%' . $phoneDigits . '%';
                $phoneCond = '';
                try {
                    $chkPhone = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'");
                    if ($chkPhone && $chkPhone->rowCount() > 0) {
                        $phoneCond = " AND u.phone LIKE ?";
                    }
                } catch (Exception $e) {}
                if ($phoneCond) {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT
                            u.id,
                            u.display_name,
                            u.email,
                            u.avatar_path as avatar,
                            u.online_status,
                            u.last_active_at as last_activity,
                            f.status as friendship_status
                        FROM users u
                        LEFT JOIN friendships f ON (f.user_id = ? AND f.friend_id = u.id) OR (f.user_id = u.id AND f.friend_id = ?)
                        WHERE u.id != ? AND u.status = 'active'
                        $phoneCond
                        $ageCond
                        ORDER BY u.display_name ASC
                        LIMIT 20
                    ");
                    $stmt->execute([$user_id, $user_id, $user_id, $phonePat]);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            foreach ($users as &$user) {
                $user['id'] = (int)$user['id'];
                $user['online_status_label'] = getOnlineStatusLabel($user['online_status'] ?? '', $lang);
                $user['online_status_color'] = getOnlineStatusColor($user['online_status'] ?? '');
                $user['last_activity_formatted'] = formatLastActivity($user['last_activity'] ?? '', $lang);
            }
            
            $payload = ['success' => true, 'users' => $users];
            if (count($users) === 0 && $emailToReturn !== null) {
                $payload['invite_available'] = true;
                $payload['contact'] = $emailToReturn;
            }
            echo json_encode($payload);
            break;
            
        case 'import_contacts':
            // 連絡先インポート
            $contacts = $input['contacts'] ?? [];
            
            if (empty($contacts)) {
                echo json_encode(['success' => false, 'error' => '連絡先が必要です']);
                exit;
            }
            
            $imported = 0;
            $matched = 0;
            
            foreach ($contacts as $contact) {
                $name = $contact['name'] ?? '';
                $email = $contact['email'] ?? null;
                $phone = $contact['phone'] ?? null;
                
                if (!$name) continue;
                
                // メールアドレスでマッチングを試みる
                $matchedUserId = null;
                if ($email) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $user_id]);
                    $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($matchedUser) {
                        $matchedUserId = $matchedUser['id'];
                        $matched++;
                    }
                }
                
                // メールでマッチしなければ電話番号でマッチング（正規化して users.phone と照合）
                if (!$matchedUserId && $phone) {
                    $phoneNormalized = preg_replace('/[^\d+]/', '', $phone);
                    if (strlen($phoneNormalized) >= 10) {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
                        $stmt->execute([$phoneNormalized, $user_id]);
                        $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($matchedUser) {
                            $matchedUserId = $matchedUser['id'];
                            $matched++;
                        }
                    }
                }
                
                // インポート履歴に保存
                $stmt = $pdo->prepare("
                    INSERT INTO contact_imports (user_id, contact_name, contact_email, contact_phone, matched_user_id, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        contact_name = VALUES(contact_name),
                        matched_user_id = VALUES(matched_user_id),
                        status = VALUES(status)
                ");
                $status = $matchedUserId ? 'matched' : 'pending';
                $stmt->execute([$user_id, $name, $email, $phone, $matchedUserId, $status]);
                $imported++;
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "{$imported}件の連絡先をインポートしました（{$matched}件がマッチ）",
                'imported' => $imported,
                'matched' => $matched
            ]);
            break;
            
        case 'imported_contacts':
            // インポートした連絡先一覧
            $stmt = $pdo->prepare("
                SELECT 
                    ci.*,
                    u.display_name as matched_user_name,
                    u.avatar as matched_user_avatar,
                    u.online_status,
                    u.last_activity
                FROM contact_imports ci
                LEFT JOIN users u ON ci.matched_user_id = u.id
                WHERE ci.user_id = ?
                ORDER BY ci.status = 'matched' DESC, ci.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($contacts as &$contact) {
                if ($contact['matched_user_id']) {
                    $contact['online_status_label'] = getOnlineStatusLabel($contact['online_status'] ?? 'offline', $lang);
                    $contact['online_status_color'] = getOnlineStatusColor($contact['online_status'] ?? 'offline');
                    $contact['last_activity_formatted'] = formatLastActivity($contact['last_activity'], $lang);
                    // 友達申請ボタン表示用：既に友達か申請中か
                    $mid = (int)$contact['matched_user_id'];
                    $stmtF = $pdo->prepare("
                        SELECT status FROM friendships 
                        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                        LIMIT 1
                    ");
                    $stmtF->execute([$user_id, $mid, $mid, $user_id]);
                    $fs = $stmtF->fetch(PDO::FETCH_ASSOC);
                    $contact['is_friend'] = $fs && $fs['status'] === 'accepted' ? 1 : 0;
                    $contact['is_pending'] = $fs && $fs['status'] === 'pending' ? 1 : 0;
                } else {
                    $contact['is_friend'] = 0;
                    $contact['is_pending'] = 0;
                }
            }
            
            echo json_encode(['success' => true, 'contacts' => $contacts]);
            break;
            
        case 'heartbeat':
            // ハートビート（オンライン状態を維持）
            echo json_encode(['success' => true, 'status' => 'online']);
            break;
            
        case 'check_contacts':
            // 連絡先のユーザー登録状況をチェック（検索除外・ブロックは候補から外す）
            $contacts = $input['contacts'] ?? [];
            
            if (empty($contacts) || !is_array($contacts)) {
                echo json_encode(['success' => true, 'matches' => []]);
                exit;
            }
            
            // 最大500件に制限
            $contacts = array_slice($contacts, 0, 500);
            
            $matches = [];
            $hasPrivacy = false;
            $hasBlocked = false;
            try {
                $pdo->query("SELECT 1 FROM user_privacy_settings LIMIT 1");
                $hasPrivacy = true;
            } catch (Throwable $e) { /* テーブルなし */ }
            try {
                $pdo->query("SELECT 1 FROM blocked_users LIMIT 1");
                $hasBlocked = true;
            } catch (Throwable $e) { /* テーブルなし */ }
            
            foreach ($contacts as $contactData) {
                $contact = $contactData['contact'] ?? '';
                $type = $contactData['type'] ?? 'email';
                
                if (!$contact) continue;
                
                // メールまたは電話番号でユーザーを検索
                if ($type === 'email') {
                    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([strtolower(trim($contact)), $user_id]);
                } else {
                    // 電話番号で検索（users.phone、正規化して比較）
                    $phoneNormalized = preg_replace('/[^\d+]/', '', $contact);
                    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE phone = ? AND id != ?");
                    $stmt->execute([$phoneNormalized, $user_id]);
                }
                
                $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($matchedUser) {
                    $mid = (int)$matchedUser['id'];
                    // 検索から除外しているユーザーは候補に含めない
                    if ($hasPrivacy) {
                        $stmtP = $pdo->prepare("SELECT exclude_from_search FROM user_privacy_settings WHERE user_id = ?");
                        $stmtP->execute([$mid]);
                        $privacy = $stmtP->fetch(PDO::FETCH_ASSOC);
                        if ($privacy && (int)($privacy['exclude_from_search'] ?? 0) === 1) {
                            continue;
                        }
                    }
                    // こちらをブロックしているユーザーは候補に含めない
                    if ($hasBlocked) {
                        $stmtB = $pdo->prepare("SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1");
                        $stmtB->execute([$mid, $user_id]);
                        if ($stmtB->fetch()) {
                            continue;
                        }
                    }
                    // 友だち関係をチェック
                    $stmt2 = $pdo->prepare("
                        SELECT status FROM friendships 
                        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                        LIMIT 1
                    ");
                    $stmt2->execute([$user_id, $mid, $mid, $user_id]);
                    $friendship = $stmt2->fetch(PDO::FETCH_ASSOC);
                    
                    $matches[] = [
                        'contact' => $contact,
                        'user_id' => $mid,
                        'display_name' => $matchedUser['display_name'],
                        'is_friend' => $friendship && $friendship['status'] === 'accepted',
                        'is_pending' => $friendship && $friendship['status'] === 'pending'
                    ];
                }
            }
            
            echo json_encode(['success' => true, 'matches' => $matches]);
            break;
            
        case 'send_invite':
            // 招待を送信
            $contact = $input['contact'] ?? '';
            $type = $input['type'] ?? 'email';
            $groupId = isset($input['group_id']) ? (int)$input['group_id'] : null;
            
            if (!$contact) {
                echo json_encode(['success' => false, 'error' => '連絡先が必要です']);
                exit;
            }
            
            // 招待者の情報を取得
            $stmt = $pdo->prepare("SELECT display_name, email FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $inviter = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$inviter) {
                echo json_encode(['success' => false, 'error' => 'ユーザーが見つかりません']);
                exit;
            }
            
            // グループが指定されている場合、グループ名を取得
            $groupName = null;
            if ($groupId) {
                $stmt = $pdo->prepare("SELECT name FROM conversations WHERE id = ?");
                $stmt->execute([$groupId]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($group) {
                    $groupName = $group['name'];
                }
            }
            
            // 招待トークンを生成
            $inviteToken = bin2hex(random_bytes(32));
            
            // 招待をデータベースに保存
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS invitations (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        inviter_id INT NOT NULL,
                        contact VARCHAR(255) NOT NULL,
                        contact_type ENUM('email', 'phone') NOT NULL,
                        token VARCHAR(64) NOT NULL UNIQUE,
                        group_id INT NULL,
                        status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + INTERVAL 7 DAY),
                        INDEX idx_token (token),
                        INDEX idx_contact (contact),
                        INDEX idx_group (group_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {
                // テーブルが既に存在する場合は無視
            }
            
            // group_idカラムを追加（存在しない場合）
            try {
                $pdo->exec("ALTER TABLE invitations ADD COLUMN group_id INT NULL AFTER token");
            } catch (Exception $e) {
                // カラムが既に存在する場合は無視
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO invitations (inviter_id, contact, contact_type, token, group_id, expires_at)
                VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ON DUPLICATE KEY UPDATE 
                    token = VALUES(token), 
                    group_id = VALUES(group_id), 
                    status = 'pending', 
                    created_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$user_id, $contact, $type, $inviteToken, $groupId]);
            
            // 招待リンクを生成
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'];
            $inviteLink = $baseUrl . '/invite.php?token=' . $inviteToken;
            
            // 招待メッセージ
            $inviterName = $inviter['display_name'] ?? 'Social9ユーザー';
            
            if ($groupName) {
                // グループからの招待
                $message = "{$inviterName}さんがあなたを「{$groupName}」グループへ招待しています。\n\n下記リンクから登録できます：\n{$inviteLink}";
                $emailSubject = "「{$groupName}」への招待";
                $htmlMessage = "<html><body><p>{$inviterName}さんがあなたを「{$groupName}」グループへ招待しています。</p><p><a href=\"{$inviteLink}\">こちらから登録</a></p></body></html>";
            } else {
                // 通常の招待
                $message = "{$inviterName}さんがあなたをSocial9へ招待しています。\n\n下記リンクから登録できます：\n{$inviteLink}";
                $emailSubject = 'Social9への招待';
                $htmlMessage = "<html><body><p>{$inviterName}さんがあなたをSocial9へ招待しています。</p><p><a href=\"{$inviteLink}\">こちらから登録</a></p></body></html>";
            }
            
            $sent = false;
            
            if ($type === 'email') {
                // メール送信
                try {
                    require_once __DIR__ . '/../includes/Mailer.php';
                    $mailer = new Mailer();
                    $sent = $mailer->send(
                        $contact,
                        $emailSubject,
                        $message,
                        $htmlMessage
                    );
                } catch (Exception $e) {
                    error_log('Invite email error: ' . $e->getMessage());
                    // メール送信に失敗しても成功として扱う（招待レコードは作成済み）
                    $sent = true;
                }
            } else {
                // SMS送信（現時点ではダミー実装）
                // 実際のSMS送信にはTwilioなどのサービスが必要
                // ここでは招待リンクを作成したことを成功とする
                $sent = true;
                error_log("SMS invite to {$contact}: {$message}");
            }
            
            echo json_encode([
                'success' => true, 
                'message' => '招待を送信しました',
                'invite_link' => $inviteLink
            ]);
            break;
            
        case 'find_suggestions':
            // 連絡先からの友だち候補検索（ハッシュマッチング）
            $hashedEmails = $input['hashed_emails'] ?? [];
            
            if (empty($hashedEmails) || !is_array($hashedEmails)) {
                echo json_encode(['success' => false, 'error' => 'メールアドレスのハッシュが必要です']);
                exit;
            }
            
            // 最大500件に制限
            $hashedEmails = array_slice($hashedEmails, 0, 500);
            
            // hidden_friend_suggestionsテーブルから非表示のユーザーIDを取得
            $hiddenUserIds = [];
            try {
                $stmt = $pdo->prepare("SELECT suggested_user_id FROM hidden_friend_suggestions WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $hiddenUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                // テーブルがない場合は空配列
            }
            
            // 全ユーザーのメールアドレスをハッシュ化して比較
            $suggestions = [];
            
            // ユーザー一覧を取得（自分と既に友だちのユーザーを除外）
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.display_name, u.display_name_en, u.display_name_zh, u.avatar
                FROM users u
                WHERE u.id != ?
                AND u.id NOT IN (
                    SELECT friend_id FROM friendships 
                    WHERE user_id = ? AND status IN ('accepted', 'blocked')
                )
                AND u.email IS NOT NULL
                AND u.email != ''
            ");
            $stmt->execute([$user_id, $user_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users as $user) {
                // 非表示ユーザーはスキップ
                if (in_array($user['id'], $hiddenUserIds)) {
                    continue;
                }
                
                // メールアドレスをハッシュ化して比較
                $emailHash = hash('sha256', strtolower(trim($user['email'])));
                
                if (in_array($emailHash, $hashedEmails)) {
                    // 申請中かどうかをチェック
                    $stmt2 = $pdo->prepare("
                        SELECT id, status FROM friendships 
                        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                        LIMIT 1
                    ");
                    $stmt2->execute([$user_id, $user['id'], $user['id'], $user_id]);
                    $friendship = $stmt2->fetch(PDO::FETCH_ASSOC);
                    
                    $suggestions[] = [
                        'id' => (int)$user['id'],
                        'display_name' => $user['display_name'] ?? $user['display_name_en'] ?? '',
                        'email' => $user['email'],
                        'avatar_url' => $user['avatar'] ? 'uploads/avatars/' . $user['avatar'] : null,
                        'is_friend' => $friendship && $friendship['status'] === 'accepted',
                        'is_pending' => $friendship && $friendship['status'] === 'pending'
                    ];
                }
            }
            
            echo json_encode(['success' => true, 'suggestions' => $suggestions]);
            break;
            
        case 'hide_suggestion':
            // 友だち候補を非表示にする
            $suggestedUserId = $input['user_id'] ?? null;
            
            if (!$suggestedUserId) {
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO hidden_friend_suggestions (user_id, suggested_user_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE hidden_at = NOW()
                ");
                $stmt->execute([$user_id, $suggestedUserId]);
                echo json_encode(['success' => true, 'message' => '非表示にしました']);
            } catch (Exception $e) {
                // テーブルがない場合は作成を試みる
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS hidden_friend_suggestions (
                            id INT PRIMARY KEY AUTO_INCREMENT,
                            user_id INT NOT NULL,
                            suggested_user_id INT NOT NULL,
                            hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY unique_hidden (user_id, suggested_user_id),
                            INDEX idx_user (user_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    
                    $stmt = $pdo->prepare("INSERT INTO hidden_friend_suggestions (user_id, suggested_user_id) VALUES (?, ?)");
                    $stmt->execute([$user_id, $suggestedUserId]);
                    echo json_encode(['success' => true, 'message' => '非表示にしました']);
                } catch (Exception $e2) {
                    echo json_encode(['success' => false, 'error' => 'テーブル作成に失敗: ' . $e2->getMessage()]);
                }
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => '不明なアクションです']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

