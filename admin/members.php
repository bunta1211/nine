<?php
/**
 * 組織管理画面 - メンバー管理
 */
ob_start(); // 出力バッファリング開始
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// 組織管理者チェック
requireOrgAdmin();

$pageTitle = 'メンバー管理';
$currentUser = getCurrentUser();
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
                <a href="members.php" class="active">👥 メンバー管理</a>
                <a href="groups.php">📁 グループ一覧</a>
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
                <h2>👥 メンバー管理</h2>
                <div class="admin-actions">
                    <button id="btnExportCsv" class="btn btn-secondary">📥 CSV出力</button>
                    <button id="btnAddMember" class="btn btn-primary">➕ 新規登録</button>
                </div>
            </header>

            <!-- メンバー追加（候補者検索） -->
            <section class="admin-section admin-add-member-section">
                <div class="add-member-header">
                    <span class="add-member-icon" aria-hidden="true">👤</span>
                    <div class="add-member-heading-wrap">
                        <h3 class="admin-section-title">メンバー追加</h3>
                        <p class="admin-section-desc">氏名・表示名・メールで検索し、組織に未所属のユーザーを追加できます。</p>
                    </div>
                </div>
                <div class="add-member-search-card">
                    <label for="candidateSearchInput" class="add-member-search-label">候補者を検索</label>
                    <div class="add-member-search-row">
                        <span class="add-member-search-icon" aria-hidden="true">🔍</span>
                        <input type="text" id="candidateSearchInput" class="add-member-search-input" placeholder="氏名・表示名・メールアドレス..." autocomplete="off">
                        <button type="button" id="btnCandidateSearch" class="btn btn-primary add-member-search-btn">検索</button>
                    </div>
                </div>
                <div id="candidateResultsWrap" class="add-member-results-wrap" style="display: none;">
                    <div id="candidateResults" class="add-member-results"></div>
                </div>
            </section>

            <!-- サマリカード -->
            <div class="stats-cards" id="statsCards">
                <!-- JavaScriptで動的に生成 -->
            </div>

            <!-- フィルタータブ -->
            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="">全て</button>
                <button class="filter-tab" data-filter="internal">🏢 社員</button>
                <button class="filter-tab" data-filter="external">🤝 外部</button>
            </div>

            <!-- 検索 -->
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="氏名・表示名・メールで検索...">
                <button id="btnSearch">🔍</button>
            </div>

            <!-- メンバー一覧テーブル -->
            <div class="table-container">
                <table class="data-table" id="membersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>種別</th>
                            <th>氏名（本名）</th>
                            <th>表示名</th>
                            <th>メールアドレス</th>
                            <th>権限</th>
                            <th>状態</th>
                            <th>登録日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="membersBody">
                        <!-- JavaScriptで動的に生成 -->
                    </tbody>
                </table>
            </div>

            <!-- ページネーション -->
            <div class="pagination" id="pagination"></div>
        </main>
    </div>

    <!-- メンバー登録/編集モーダル -->
    <div class="modal" id="memberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">新規メンバー登録</h3>
                <button class="modal-close" id="btnCloseModal">&times;</button>
            </div>
            <form id="memberForm">
                <input type="hidden" id="memberId" name="id">
                <div class="form-group">
                    <label>メンバー種別 <span class="required">*</span></label>
                    <div class="member-type-select">
                        <label class="type-option">
                            <input type="radio" name="member_type" value="internal" checked>
                            <span class="type-label type-internal">🏢 社員（内部）</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="member_type" value="external">
                            <span class="type-label type-external">🤝 外部協力者</span>
                        </label>
                    </div>
                    <small id="externalNote" style="color:#e67e22; display:none;">⚠️ 外部協力者は組織管理者にはなれません</small>
                </div>
                <div class="form-group">
                    <label for="fullName">氏名（本名） <span class="required">*</span></label>
                    <input type="text" id="fullName" name="full_name" required>
                    <small style="color:#888;">管理者確認用。他ユーザーには表示されません</small>
                </div>
                <div class="form-group">
                    <label for="displayName">表示名 <span class="required">*</span></label>
                    <input type="text" id="displayName" name="display_name" required>
                    <small style="color:#888;">チャットで相手に表示される名前</small>
                </div>
                <div class="form-group">
                    <label for="email">メールアドレス</label>
                    <input type="email" id="email" name="email" placeholder="例: user@example.com">
                </div>
                <div class="form-group">
                    <label for="phone">携帯電話番号</label>
                    <input type="tel" id="phone" name="phone" placeholder="例: 09012345678（ハイフンなし）">
                    <small style="color:#888;">メールアドレスまたは携帯電話番号のどちらか一方を入力してください</small>
                </div>
                <div class="form-group" id="passwordGroup">
                    <label for="password">パスワード</label>
                    <div class="password-input">
                        <input type="text" id="password" name="password" placeholder="編集時のみ（新規は不要）">
                    </div>
                    <small id="passwordHint">新規登録の場合は空欄のまま保存してください。本人に招待メールが送られ、パスワードは本人がリンク先で設定します。<br>編集時：空欄＝変更なし、入力＝新しいパスワードに変更</small>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="isOrgAdmin" name="is_org_admin">
                        組織管理者にする
                    </label>
                </div>
                <div class="form-group" id="memberGroupsWrap">
                    <label>所属グループ <span class="optional">（2個以上選択可・一括登録）</span></label>
                    <div id="memberGroupsList" class="member-groups-checkboxes">
                        <span class="loading-inline">読み込み中...</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btnCancel">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 削除確認モーダル -->
    <div class="modal" id="deleteModal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h3>削除確認</h3>
                <button class="modal-close" id="btnCloseDeleteModal">&times;</button>
            </div>
            <p id="deleteMessage">このメンバーを削除しますか？</p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btnCancelDelete">キャンセル</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDelete">削除</button>
            </div>
        </div>
    </div>

    <!-- 利用制限設定モーダル -->
    <div class="modal" id="restrictionsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="restrictionsModalTitle">利用制限設定</h3>
                <button class="modal-close" id="btnCloseRestrictionsModal">&times;</button>
            </div>
            <form id="restrictionsForm">
                <div class="restrictions-section">
                    <h4>⏰ 利用可能時間帯</h4>
                    <p class="section-desc">お子様がアプリを利用できる時間帯を設定します</p>
                    <div class="time-range-group">
                        <div class="form-group">
                            <label for="usageStartTime">開始時間</label>
                            <input type="time" id="usageStartTime" value="07:00">
                        </div>
                        <span class="time-separator">〜</span>
                        <div class="form-group">
                            <label for="usageEndTime">終了時間</label>
                            <input type="time" id="usageEndTime" value="21:00">
                        </div>
                    </div>
                </div>
                
                <div class="restrictions-section">
                    <h4>⏱️ 1日の利用時間制限</h4>
                    <p class="section-desc">1日あたりの最大利用時間を設定します（0で無制限）</p>
                    <div class="form-group">
                        <label for="dailyLimitMinutes">利用制限（分）</label>
                        <input type="number" id="dailyLimitMinutes" value="120" min="0" max="1440" step="15">
                        <small>例: 120分 = 2時間</small>
                    </div>
                </div>
                
                <div class="restrictions-section">
                    <h4>📞 連絡先制限</h4>
                    <p class="section-desc">連絡・通話できる相手を制限します</p>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="externalContact">
                            組織外のユーザーへの連絡を許可
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="callRestriction">通話制限</label>
                        <select id="callRestriction">
                            <option value="none">制限なし</option>
                            <option value="org_only">組織内メンバーのみ</option>
                            <option value="approved_only">承認された連絡先のみ</option>
                        </select>
                    </div>
                </div>
                
                <div class="restrictions-section">
                    <h4>🔒 その他の制限</h4>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="canCreateGroups">
                            グループの作成を許可
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="canLeaveOrg">
                            組織からの退出を許可
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btnCancelRestrictions">キャンセル</button>
                    <button type="button" class="btn btn-primary" id="btnSaveRestrictions">保存</button>
                </div>
            </form>
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
                
                // 現在の組織をセッションに保存（初回のみ）
                if (!data.current_org_id) {
                    await switchOrganization(data.organizations[0].id, false);
                }
                
                // 組織が1件以上ある場合のみメンバー一覧を取得（current_org_id が無いとAPIが400を返す）
                if (typeof loadMembers === 'function') {
                    loadMembers();
                }
            } else if (data.success && Array.isArray(data.organizations) && data.organizations.length === 0) {
                // 組織が0件のときだけ空状態を表示（APIは呼ばない）
                const tbody = document.getElementById('membersBody');
                const statsCards = document.getElementById('statsCards');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="8" class="loading">組織がありません。組織を作成するか、組織に参加してください。</td></tr>';
                }
                if (statsCards) {
                    statsCards.innerHTML = '<div class="stat-card"><div class="stat-info"><div class="stat-value">0</div><div class="stat-label">総メンバー数</div></div></div>';
                }
            } else {
                // 組織一覧API失敗時
                const tbody = document.getElementById('membersBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="8" class="loading">組織一覧の取得に失敗しました。</td></tr>';
                }
            }
        } catch (error) {
            console.error('組織一覧の取得に失敗:', error);
        }
    }
    
    async function switchOrganization(orgId, reload = true) {
        try {
            const response = await fetch('/admin/api/switch-organization.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ organization_id: parseInt(orgId) })
            });
            const data = await response.json();
            
            if (data.success) {
                if (reload) {
                    // ページをリロードして新しい組織のデータを表示
                    location.reload();
                }
            } else {
                alert(data.message);
            }
        } catch (error) {
            console.error('組織切り替えに失敗:', error);
        }
    }
    
    // ページ読み込み時に組織一覧を取得
    document.addEventListener('DOMContentLoaded', loadMyOrganizations);
    </script>
    <script src="/assets/js/admin-members.js?v=11"></script>
    <script src="/assets/js/admin-restrictions.js?v=1"></script>
</body>
</html>




