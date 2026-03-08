<?php
/**
 * 組織管理画面 - グループ一覧
 */
ob_start(); // 出力バッファリング開始
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// 組織管理者チェック
requireOrgAdmin();

$pageTitle = 'グループ一覧';
$currentUser = getCurrentUser();
$pdo = getDB();

// 現在選択中の組織IDを取得
$currentOrgId = $_SESSION['current_org_id'] ?? null;
if (!$currentOrgId) {
    // セッションに組織IDがない場合、ユーザーの所属組織を取得
    $stmt = $pdo->prepare("
        SELECT organization_id FROM organization_members 
        WHERE user_id = ? AND left_at IS NULL AND role IN ('owner', 'admin')
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $currentOrgId = $stmt->fetchColumn();
    if ($currentOrgId) {
        $_SESSION['current_org_id'] = $currentOrgId;
    }
}

// サマリ統計を取得（現在の組織に絞り込み）
$stats = [];

// グループ総数（現在の組織のみ）
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE type = 'group' AND organization_id = ?");
$stmt->execute([$currentOrgId]);
$stats['total_groups'] = (int)$stmt->fetchColumn();

// 総メンバー数（現在の組織のグループに所属するユニークユーザー）
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT cm.user_id) 
    FROM conversation_members cm
    INNER JOIN conversations c ON cm.conversation_id = c.id
    WHERE c.organization_id = ? AND c.type = 'group' AND cm.left_at IS NULL
");
$stmt->execute([$currentOrgId]);
$stats['total_members'] = (int)$stmt->fetchColumn();

// 今月作成されたグループ数（現在の組織のみ）
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversations 
    WHERE type = 'group' AND organization_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
");
$stmt->execute([$currentOrgId]);
$stats['new_groups_this_month'] = (int)$stmt->fetchColumn();

// メンバーが0人のグループ数（現在の組織のみ）
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversations c 
    WHERE c.type = 'group' AND c.organization_id = ?
    AND (SELECT COUNT(*) FROM conversation_members cm WHERE cm.conversation_id = c.id AND cm.left_at IS NULL) = 0
");
$stmt->execute([$currentOrgId]);
$stats['empty_groups'] = (int)$stmt->fetchColumn();

// 最大メンバー数（現在の組織のグループのみ）
$stmt = $pdo->prepare("
    SELECT COALESCE(MAX(cnt), 0) FROM (
        SELECT COUNT(*) as cnt 
        FROM conversation_members cm
        INNER JOIN conversations c ON cm.conversation_id = c.id
        WHERE c.organization_id = ? AND c.type = 'group' AND cm.left_at IS NULL 
        GROUP BY cm.conversation_id
    ) as sub
");
$stmt->execute([$currentOrgId]);
$stats['max_members'] = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - 組織管理</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="admin-container">
        <!-- サイドバー -->
        <aside class="admin-sidebar">
            <div class="admin-logo">
                <h1>🏢 組織管理</h1>
                <select id="orgSwitcher" class="org-switcher" onchange="switchOrganization(this.value)">
                    <!-- 動的に読み込み -->
                </select>
            </div>
            <nav class="admin-nav">
                <a href="members.php">👥 組織アドレス帳</a>
                <a href="groups.php" class="active">📁 グループ一覧</a>
                <a href="ai_specialist_admin.php">🎓 専門AI管理</a>
                <a href="/chat.php">💬 チャットへ戻る</a>
                <a href="/api/auth.php?action=logout">🚪 ログアウト</a>
            </nav>
            <div class="admin-user">
                <p>👤 <?= htmlspecialchars($currentUser['display_name'] ?? '') ?></p>
            </div>
        </aside>

        <!-- メインコンテンツ -->
        <main class="admin-main">
            <header class="admin-header">
                <h2>📁 グループ一覧</h2>
                <div class="admin-actions">
                    <button id="btnAddGroup" class="btn btn-primary">💬 グループチャットを追加</button>
                    <button id="btnAddPrivateGroup" class="btn btn-secondary">🔒 プライベートグループを作成</button>
                    <button id="btnExportCsv" class="btn btn-secondary">📥 CSV出力</button>
                </div>
            </header>

            <!-- サマリカード -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon">📁</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['total_groups'] ?></div>
                        <div class="stat-label">グループ総数</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['total_members'] ?></div>
                        <div class="stat-label">参加メンバー数</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🆕</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['new_groups_this_month'] ?></div>
                        <div class="stat-label">今月の新規</div>
                    </div>
                </div>
                <?php if ($stats['empty_groups'] > 0): ?>
                <div class="stat-card stat-warning">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['empty_groups'] ?></div>
                        <div class="stat-label">メンバー0件</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="stat-card">
                    <div class="stat-icon">👑</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['max_members'] ?></div>
                        <div class="stat-label">最大メンバー数</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- 検索 -->
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="グループ名で検索...">
                <button id="btnSearch">🔍</button>
            </div>

            <!-- グループ一覧テーブル -->
            <div class="table-container">
                <table class="data-table" id="groupsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>グループ名</th>
                            <th>メンバー数</th>
                            <th>作成日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="groupsBody">
                        <!-- JavaScriptで動的に生成 -->
                    </tbody>
                </table>
            </div>

            <!-- ページネーション -->
            <div class="pagination" id="pagination"></div>
        </main>
    </div>

    <!-- プライベートグループ作成モーダル（マスター計画 2.11） -->
    <div class="modal" id="addPrivateGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🔒 プライベートグループを作成</h3>
                <button class="modal-close" id="btnCloseAddPrivateGroupModal">&times;</button>
            </div>
            <p class="admin-private-group-desc">チャット画面からは作成できません。発言・データ送信・メンバー一覧・アドレス追加の許可を個別に設定できます。</p>
            <form id="addPrivateGroupForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="newPrivateGroupName">グループ名 <span class="required">*</span></label>
                        <input type="text" id="newPrivateGroupName" name="name" required placeholder="例: 役員会議">
                    </div>
                    <div class="form-group">
                        <label for="newPrivateGroupDescription">説明 <span class="optional">（任意）</span></label>
                        <textarea id="newPrivateGroupDescription" name="description" rows="2" placeholder="グループの説明"></textarea>
                    </div>
                    <div class="form-group admin-private-group-options">
                        <label>プライベート設定</label>
                        <label class="checkbox-label"><input type="checkbox" id="privateAllowMemberPost" name="allow_member_post" value="1" checked> 発言を許可する</label>
                        <label class="checkbox-label"><input type="checkbox" id="privateAllowDataSend" name="allow_data_send" value="1" checked> データ送信を許可する</label>
                        <label class="checkbox-label"><input type="checkbox" id="privateMemberListVisible" name="member_list_visible" value="1" checked> メンバー一覧を表示する</label>
                        <label class="checkbox-label"><input type="checkbox" id="privateAllowAddContact" name="allow_add_contact_from_group" value="1" checked> グループ内からアドレス追加を許可する</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btnCancelAddPrivateGroup">キャンセル</button>
                    <button type="submit" class="btn btn-primary">作成する</button>
                </div>
            </form>
        </div>
    </div>

    <!-- グループチャット作成モーダル -->
    <div class="modal" id="addGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>💬 グループチャットを追加</h3>
                <button class="modal-close" id="btnCloseAddGroupModal">&times;</button>
            </div>
            <form id="addGroupForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="newGroupName">グループ名 <span class="required">*</span></label>
                        <input type="text" id="newGroupName" name="name" required placeholder="例: 営業部チャット">
                    </div>
                    <div class="form-group">
                        <label for="newGroupDescription">説明 <span class="optional">（任意）</span></label>
                        <textarea id="newGroupDescription" name="description" rows="2" placeholder="グループの説明"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btnCancelAddGroup">キャンセル</button>
                    <button type="submit" class="btn btn-primary">作成する</button>
                </div>
            </form>
        </div>
    </div>

    <!-- グループ詳細・メンバー管理モーダル -->
    <div class="modal" id="groupDetailModal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h3 id="groupDetailTitle">グループ詳細</h3>
                <button class="modal-close" id="btnCloseModal">&times;</button>
            </div>
            <div class="modal-body" id="groupDetailBody">
                <!-- メンバー一覧 -->
            </div>
            <div class="modal-footer">
                <button id="btnAddMember" class="btn btn-success">➕ メンバー追加</button>
                <button id="btnExportGroupCsv" class="btn btn-secondary">📥 メンバーCSV出力</button>
                <button class="btn btn-primary" id="btnCloseDetail">閉じる</button>
            </div>
        </div>
    </div>

    <!-- グループ名編集モーダル -->
    <div class="modal" id="editGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>グループ名編集</h3>
                <button class="modal-close" id="btnCloseEditModal">&times;</button>
            </div>
            <form id="editGroupForm">
                <div class="modal-body">
                    <input type="hidden" id="editGroupId" name="id">
                    <div class="form-group">
                        <label for="editGroupName">🇯🇵 グループ名（日本語）</label>
                        <input type="text" id="editGroupName" name="name" required placeholder="日本語名">
                    </div>
                    <div class="form-group">
                        <label for="editGroupNameEn">🇺🇸 グループ名（英語）</label>
                        <input type="text" id="editGroupNameEn" name="name_en" placeholder="English name (optional)">
                    </div>
                    <div class="form-group">
                        <label for="editGroupNameZh">🇨🇳 グループ名（中国語）</label>
                        <input type="text" id="editGroupNameZh" name="name_zh" placeholder="中文名（可选）">
                    </div>
                    <!-- マスター計画 2.12: プライベートグループ設定（編集時のみ表示。API がカラム存在時のみ返す） -->
                    <div id="editGroupPrivateSection" class="form-group private-group-fields" style="display: none;">
                        <hr style="margin: 16px 0;">
                        <label class="checkbox-label"><input type="checkbox" id="editGroupIsPrivate" name="is_private_group" value="1"> 🔒 プライベートグループにする</label>
                        <div id="editGroupPrivateOptions" style="margin-top: 12px; margin-left: 20px; display: none;">
                            <label class="checkbox-label"><input type="checkbox" id="editGroupAllowPost" name="allow_member_post" value="1" checked> メンバー発言を許可</label><br>
                            <label class="checkbox-label"><input type="checkbox" id="editGroupAllowData" name="allow_data_send" value="1" checked> ファイル送信を許可</label><br>
                            <label class="checkbox-label"><input type="checkbox" id="editGroupMemberListVisible" name="member_list_visible" value="1" checked> メンバー一覧を表示</label><br>
                            <label class="checkbox-label"><input type="checkbox" id="editGroupAllowAddContact" name="allow_add_contact_from_group" value="1" checked> グループ内からアドレス追加を許可</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btnCancelEdit">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- メンバー追加モーダル -->
    <div class="modal" id="addMemberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>メンバー追加</h3>
                <button class="modal-close" id="btnCloseAddMemberModal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>追加するメンバーを選択</label>
                    <input type="text" id="memberSearchInput" placeholder="名前で検索..." style="margin-bottom: 10px;">
                    <div id="userListContainer" class="user-list-container">
                        <!-- ユーザー一覧 -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelAddMember">キャンセル</button>
            </div>
        </div>
    </div>

    <!-- グループ削除確認モーダル -->
    <div class="modal" id="deleteGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚠️ グループ削除</h3>
                <button class="modal-close" id="btnCloseDeleteModal">&times;</button>
            </div>
            <div class="modal-body">
                <p>本当にこのグループを削除しますか？</p>
                <p><strong id="deleteGroupName"></strong></p>
                <p style="color: #e74c3c;">※ この操作は取り消せません。メンバー情報も削除されます。</p>
                <input type="hidden" id="deleteGroupId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelDelete">キャンセル</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDelete">削除する</button>
            </div>
        </div>
    </div>

    <script>
    // 組織切り替え機能
    async function loadMyOrganizations() {
        try {
            const response = await fetch('/admin/api/my-organizations.php');
            const data = await response.json();
            
            if (data.success && data.organizations.length > 0) {
                const select = document.getElementById('orgSwitcher');
                select.innerHTML = data.organizations.map(org => {
                    const typeIcon = org.type === 'corporation' ? '🏢' : (org.type === 'family' ? '👨‍👩‍👧' : '👥');
                    const ownerBadge = org.relationship === 'owner' ? ' ★' : '';
                    const selected = org.id === data.current_org_id ? 'selected' : '';
                    return `<option value="${org.id}" ${selected}>${typeIcon} ${org.name}${ownerBadge}</option>`;
                }).join('');
            }
        } catch (error) {
            console.error('組織一覧の取得に失敗:', error);
        }
    }
    
    async function switchOrganization(orgId) {
        try {
            const response = await fetch('/admin/api/switch-organization.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ organization_id: parseInt(orgId) })
            });
            const data = await response.json();
            
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        } catch (error) {
            console.error('組織切り替えに失敗:', error);
        }
    }
    
    document.addEventListener('DOMContentLoaded', loadMyOrganizations);
    </script>
    <script src="/assets/js/admin-groups.js?v=5"></script>
</body>
</html>




