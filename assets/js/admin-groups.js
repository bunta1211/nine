/**
 * 組織管理画面 - グループ一覧 JavaScript
 */

// グローバル変数
let currentPage = 1;
let totalPages = 1;
let searchKeyword = '';
let currentGroupId = null;
let currentGroupName = '';
let currentGroupMemberIds = []; // 現在のグループのメンバーID一覧（追加モーダルで「メンバー」表示用）
let allUsers = [];

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    setupEventListeners();
    try {
        loadGroups();
    } catch (err) {
        console.error('loadGroups error:', err);
        const tbody = document.getElementById('groupsBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="loading">読み込みに失敗しました</td></tr>';
    }
});

// イベントリスナー設定
function setupEventListeners() {
    // グループチャットを追加ボタン
    const btnAddGroup = document.getElementById('btnAddGroup');
    if (btnAddGroup) {
        btnAddGroup.addEventListener('click', openAddGroupModal);
    }
    // プライベートグループを作成ボタン（マスター計画 2.11）
    const btnAddPrivateGroup = document.getElementById('btnAddPrivateGroup');
    if (btnAddPrivateGroup) {
        btnAddPrivateGroup.addEventListener('click', openAddPrivateGroupModal);
    }

    // グループ作成モーダル
    const btnCloseAddGroupModal = document.getElementById('btnCloseAddGroupModal');
    const btnCancelAddGroup = document.getElementById('btnCancelAddGroup');
    const addGroupModal = document.getElementById('addGroupModal');
    const addGroupForm = document.getElementById('addGroupForm');
    if (btnCloseAddGroupModal) btnCloseAddGroupModal.addEventListener('click', closeAddGroupModal);
    if (btnCancelAddGroup) btnCancelAddGroup.addEventListener('click', closeAddGroupModal);
    if (addGroupModal) {
        addGroupModal.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAddGroupModal();
        });
    }
    if (addGroupForm) addGroupForm.addEventListener('submit', submitAddGroup);

    // プライベートグループ作成モーダル
    const btnCloseAddPrivateGroupModal = document.getElementById('btnCloseAddPrivateGroupModal');
    const btnCancelAddPrivateGroup = document.getElementById('btnCancelAddPrivateGroup');
    const addPrivateGroupModal = document.getElementById('addPrivateGroupModal');
    const addPrivateGroupForm = document.getElementById('addPrivateGroupForm');
    if (btnCloseAddPrivateGroupModal) btnCloseAddPrivateGroupModal.addEventListener('click', closeAddPrivateGroupModal);
    if (btnCancelAddPrivateGroup) btnCancelAddPrivateGroup.addEventListener('click', closeAddPrivateGroupModal);
    if (addPrivateGroupModal) {
        addPrivateGroupModal.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAddPrivateGroupModal();
        });
    }
    if (addPrivateGroupForm) addPrivateGroupForm.addEventListener('submit', submitAddPrivateGroup);

    // CSV出力ボタン
    const btnExportCsv = document.getElementById('btnExportCsv');
    if (btnExportCsv) btnExportCsv.addEventListener('click', exportGroupsCsv);

    // 検索
    const btnSearch = document.getElementById('btnSearch');
    if (btnSearch) btnSearch.addEventListener('click', () => {
        searchKeyword = document.getElementById('searchInput').value;
        currentPage = 1;
        loadGroups();
    });
    const searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            searchKeyword = e.target.value;
            currentPage = 1;
            loadGroups();
        }
    });

    // グループ詳細モーダル
    const btnCloseModal = document.getElementById('btnCloseModal');
    const btnCloseDetail = document.getElementById('btnCloseDetail');
    const groupDetailModal = document.getElementById('groupDetailModal');
    if (btnCloseModal) btnCloseModal.addEventListener('click', closeDetailModal);
    if (btnCloseDetail) btnCloseDetail.addEventListener('click', closeDetailModal);
    if (groupDetailModal) groupDetailModal.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeDetailModal();
    });

    // グループメンバーCSV出力
    const btnExportGroupCsv = document.getElementById('btnExportGroupCsv');
    if (btnExportGroupCsv) btnExportGroupCsv.addEventListener('click', exportGroupMembersCsv);

    // メンバー追加ボタン
    const btnAddMember = document.getElementById('btnAddMember');
    if (btnAddMember) btnAddMember.addEventListener('click', openAddMemberModal);

    // グループ名編集モーダル
    const btnCloseEditModal = document.getElementById('btnCloseEditModal');
    const btnCancelEdit = document.getElementById('btnCancelEdit');
    const editGroupModal = document.getElementById('editGroupModal');
    const editGroupForm = document.getElementById('editGroupForm');
    if (btnCloseEditModal) btnCloseEditModal.addEventListener('click', closeEditModal);
    if (btnCancelEdit) btnCancelEdit.addEventListener('click', closeEditModal);
    if (editGroupModal) editGroupModal.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeEditModal();
    });
    if (editGroupForm) editGroupForm.addEventListener('submit', saveGroupName);

    // 編集モーダル: プライベートグループのチェックで4設定の表示を切り替え（マスター計画 2.12）
    const editGroupIsPrivate = document.getElementById('editGroupIsPrivate');
    const editGroupPrivateOptions = document.getElementById('editGroupPrivateOptions');
    if (editGroupIsPrivate && editGroupPrivateOptions) {
        editGroupIsPrivate.addEventListener('change', () => {
            editGroupPrivateOptions.style.display = editGroupIsPrivate.checked ? 'block' : 'none';
        });
    }

    // メンバー追加モーダル
    const btnCloseAddMemberModal = document.getElementById('btnCloseAddMemberModal');
    const btnCancelAddMember = document.getElementById('btnCancelAddMember');
    const addMemberModal = document.getElementById('addMemberModal');
    const memberSearchInput = document.getElementById('memberSearchInput');
    if (btnCloseAddMemberModal) btnCloseAddMemberModal.addEventListener('click', closeAddMemberModal);
    if (btnCancelAddMember) btnCancelAddMember.addEventListener('click', closeAddMemberModal);
    if (addMemberModal) addMemberModal.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeAddMemberModal();
    });
    if (memberSearchInput) memberSearchInput.addEventListener('input', filterUserList);

    // グループ削除モーダル
    const btnCloseDeleteModal = document.getElementById('btnCloseDeleteModal');
    const btnCancelDelete = document.getElementById('btnCancelDelete');
    const btnConfirmDelete = document.getElementById('btnConfirmDelete');
    const deleteGroupModal = document.getElementById('deleteGroupModal');
    if (btnCloseDeleteModal) btnCloseDeleteModal.addEventListener('click', closeDeleteModal);
    if (btnCancelDelete) btnCancelDelete.addEventListener('click', closeDeleteModal);
    if (btnConfirmDelete) btnConfirmDelete.addEventListener('click', confirmDeleteGroup);
    if (deleteGroupModal) deleteGroupModal.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeDeleteModal();
    });
}

// グループ一覧読み込み
async function loadGroups() {
    const tbody = document.getElementById('groupsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="loading">読み込み中...</td></tr>';

    try {
        const params = new URLSearchParams({
            page: currentPage,
            search: searchKeyword
        });

        const response = await fetch(`/admin/api/groups.php?${params}`);
        const data = await response.json();

        if (data.success) {
            renderGroups(data.groups);
            renderPagination(data.pagination);
        } else {
            throw new Error(data.error || '読み込みに失敗しました');
        }
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="5" class="loading">エラー: ${error.message}</td></tr>`;
    }
}

// グループチャット作成モーダル
function openAddGroupModal() {
    const modal = document.getElementById('addGroupModal');
    const form = document.getElementById('addGroupForm');
    if (modal) {
        modal.classList.add('show');
        if (form) form.reset();
        document.getElementById('newGroupName').focus();
    }
}

function closeAddGroupModal() {
    const modal = document.getElementById('addGroupModal');
    if (modal) modal.classList.remove('show');
}

// プライベートグループ作成モーダル（マスター計画 2.11）
function openAddPrivateGroupModal() {
    const modal = document.getElementById('addPrivateGroupModal');
    const form = document.getElementById('addPrivateGroupForm');
    if (modal) {
        modal.classList.add('show');
        if (form) form.reset();
        const nameEl = document.getElementById('newPrivateGroupName');
        if (nameEl) nameEl.focus();
    }
}
// インラインonclick・外部からの呼び出し用にグローバルに公開
if (typeof window !== 'undefined') window.openAddPrivateGroupModal = openAddPrivateGroupModal;

function closeAddPrivateGroupModal() {
    const modal = document.getElementById('addPrivateGroupModal');
    if (modal) modal.classList.remove('show');
}

async function submitAddPrivateGroup(e) {
    e.preventDefault();
    const nameInput = document.getElementById('newPrivateGroupName');
    const descInput = document.getElementById('newPrivateGroupDescription');
    const name = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
    if (!name) {
        alert('グループ名を入力してください');
        return;
    }
    const allowMemberPost = document.getElementById('privateAllowMemberPost').checked ? 1 : 0;
    const allowDataSend = document.getElementById('privateAllowDataSend').checked ? 1 : 0;
    const memberListVisible = document.getElementById('privateMemberListVisible').checked ? 1 : 0;
    const allowAddContactFromGroup = document.getElementById('privateAllowAddContact').checked ? 1 : 0;

    const form = document.getElementById('addPrivateGroupForm');
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '作成中...';
    }
    try {
        const response = await fetch('/admin/api/groups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_group',
                name: name,
                description: (descInput && descInput.value) ? descInput.value.trim() : '',
                is_private_group: 1,
                allow_member_post: allowMemberPost,
                allow_data_send: allowDataSend,
                member_list_visible: memberListVisible,
                allow_add_contact_from_group: allowAddContactFromGroup
            })
        });
        const data = await response.json();
        if (data.success) {
            closeAddPrivateGroupModal();
            loadGroups();
            if (typeof showToast === 'function') {
                showToast('✅ プライベートグループを作成しました', 'success');
            } else {
                alert('プライベートグループを作成しました');
            }
        } else {
            alert(data.error || '作成に失敗しました');
        }
    } catch (err) {
        alert('エラー: ' + (err.message || '通信に失敗しました'));
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '作成する';
        }
    }
}

async function submitAddGroup(e) {
    e.preventDefault();
    const nameInput = document.getElementById('newGroupName');
    const descInput = document.getElementById('newGroupDescription');
    const name = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
    if (!name) {
        alert('グループ名を入力してください');
        return;
    }
    const addGroupForm = document.getElementById('addGroupForm');
    const submitBtn = addGroupForm ? addGroupForm.querySelector('button[type="submit"]') : null;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '作成中...';
    }
    try {
        const response = await fetch('/admin/api/groups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_group',
                name: name,
                description: (descInput && descInput.value) ? descInput.value.trim() : ''
            })
        });
        const data = await response.json();
        if (data.success) {
            closeAddGroupModal();
            loadGroups();
            if (typeof showToast === 'function') {
                showToast('✅ グループを作成しました', 'success');
            } else {
                alert('グループを作成しました');
            }
        } else {
            alert(data.error || '作成に失敗しました');
        }
    } catch (err) {
        alert('エラー: ' + (err.message || '通信に失敗しました'));
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '作成する';
        }
    }
}

// グループ一覧描画
function renderGroups(groups) {
    const tbody = document.getElementById('groupsBody');
    
    if (groups.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="loading">グループが見つかりません</td></tr>';
        return;
    }

    tbody.innerHTML = groups.map(group => {
        const isPrivate = (group.is_private_group === 1 || group.is_private_group === '1');
        const nameCell = isPrivate
            ? `<span class="badge-private-inline" title="プライベートグループ">🔒 プライベート</span> ${escapeHtml(group.name)}`
            : escapeHtml(group.name);
        return `
        <tr>
            <td>${group.id}</td>
            <td>${nameCell}</td>
            <td>${group.member_count}名</td>
            <td>${formatDate(group.created_at)}</td>
            <td>
                <div class="action-btns">
                    <button onclick="showGroupDetail(${group.id}, '${escapeJs(group.name)}')" title="メンバー管理">👥</button>
                    <button onclick="openEditModal(${group.id}, '${escapeJs(group.name)}')" title="編集">✏️</button>
                    <button onclick="openDeleteModal(${group.id}, '${escapeJs(group.name)}')" title="削除" class="btn-danger-icon">🗑️</button>
                </div>
            </td>
        </tr>
    `;
    }).join('');
}

// ページネーション描画
function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    totalPages = pagination.total_pages;

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    
    html += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">◀</button>`;
    
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            html += `<button class="${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            html += `<button disabled>...</button>`;
        }
    }
    
    html += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">▶</button>`;

    container.innerHTML = html;
}

// ページ移動
function goToPage(page) {
    currentPage = page;
    loadGroups();
}

// ============ グループ詳細モーダル ============

// グループ詳細表示
async function showGroupDetail(groupId, groupName) {
    currentGroupId = groupId;
    currentGroupName = groupName;
    
    const modal = document.getElementById('groupDetailModal');
    const title = document.getElementById('groupDetailTitle');
    const body = document.getElementById('groupDetailBody');
    
    title.textContent = `${groupName} - メンバー一覧`;
    body.innerHTML = '<div class="loading">読み込み中...</div>';
    modal.classList.add('show');

    try {
        const response = await fetch(`/admin/api/groups.php?id=${groupId}`);
        const data = await response.json();

        if (data.success) {
            currentGroupMemberIds = (data.members || []).map(m => parseInt(m.id, 10));
            renderGroupMembers(data.members);
        } else {
            throw new Error(data.error || 'メンバー情報の取得に失敗しました');
        }
    } catch (error) {
        body.innerHTML = `<div class="loading">エラー: ${error.message}</div>`;
    }
}

// グループメンバー描画
function renderGroupMembers(members) {
    const body = document.getElementById('groupDetailBody');
    
    if (members.length === 0) {
        body.innerHTML = '<div class="loading">メンバーがいません</div>';
        return;
    }

    // 内部/外部のカウント
    const internalCount = members.filter(m => m.member_type !== 'external').length;
    const externalCount = members.filter(m => m.member_type === 'external').length;

    body.innerHTML = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>種別</th>
                    <th>表示名</th>
                    <th>氏名</th>
                    <th>権限</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                ${members.map(member => {
                    const isExternal = member.member_type === 'external';
                    const typeBadge = isExternal 
                        ? '<span class="badge badge-external">🤝外部</span>'
                        : '<span class="badge badge-internal">🏢社員</span>';
                    
                    const isAdmin = member.is_group_admin == 1;
                    return `
                        <tr>
                            <td>${typeBadge}</td>
                            <td>${escapeHtml(member.display_name)}</td>
                            <td>${escapeHtml(member.full_name || '')}</td>
                            <td>
                                <button onclick="toggleGroupAdmin(${member.id}, ${isAdmin ? 0 : 1})" 
                                        class="role-toggle-btn ${isAdmin ? 'role-admin' : 'role-member'}"
                                        title="クリックで権限を変更">
                                    ${isAdmin ? 'グループ管理者' : '一般メンバー'}
                                </button>
                            </td>
                            <td>
                                <button onclick="removeMemberFromGroup(${member.id}, '${escapeJs(member.display_name)}')" 
                                        class="btn-small btn-danger" title="削除">✕</button>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
        <div style="margin-top: 15px; color: #666; text-align: right;">
            全 ${members.length}名（🏢社員 ${internalCount}名 / 🤝外部 ${externalCount}名）
        </div>
    `;
}

// メンバー削除
async function removeMemberFromGroup(userId, displayName) {
    if (!confirm(`${displayName} をグループから削除しますか？`)) {
        return;
    }

    try {
        const response = await fetch('/admin/api/groups.php?action=remove_member', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                group_id: currentGroupId,
                user_id: userId
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('メンバーを削除しました', 'success');
            showGroupDetail(currentGroupId, currentGroupName);
            loadGroups(); // メンバー数を更新
        } else {
            throw new Error(data.error || '削除に失敗しました');
        }
    } catch (error) {
        alert('エラー: ' + error.message);
    }
}

// グループ管理者権限の切り替え
async function toggleGroupAdmin(userId, newRole) {
    const roleName = newRole === 1 ? 'グループ管理者' : '一般メンバー';
    
    try {
        const response = await fetch('/admin/api/groups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'change_role',
                group_id: currentGroupId,
                user_id: userId,
                role: newRole === 1 ? 'admin' : 'member'
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast(`${roleName}に変更しました`, 'success');
            showGroupDetail(currentGroupId, currentGroupName);
        } else {
            throw new Error(data.error || '権限変更に失敗しました');
        }
    } catch (error) {
        alert('エラー: ' + error.message);
    }
}

// モーダルを閉じる
function closeDetailModal() {
    document.getElementById('groupDetailModal').classList.remove('show');
}

// ============ グループ名編集モーダル ============

async function openEditModal(groupId, groupName) {
    document.getElementById('editGroupId').value = groupId;
    document.getElementById('editGroupName').value = groupName;
    document.getElementById('editGroupNameEn').value = '';
    document.getElementById('editGroupNameZh').value = '';
    document.getElementById('editGroupModal').classList.add('show');
    
    // グループの詳細を取得して多言語フィールドに設定（admin API使用）
    try {
        const response = await fetch(`/admin/api/groups.php?id=${groupId}&action=get_detail`);
        const data = await response.json();
        
        if (data.success && data.group) {
            const group = data.group;
            document.getElementById('editGroupName').value = group.name || '';
            document.getElementById('editGroupNameEn').value = group.name_en || '';
            document.getElementById('editGroupNameZh').value = group.name_zh || '';
            // マスター計画 2.12: プライベートグループ設定（常に表示して設定変更可能に）
            const privateSection = document.getElementById('editGroupPrivateSection');
            const privateOpts = document.getElementById('editGroupPrivateOptions');
            const cbPrivate = document.getElementById('editGroupIsPrivate');
            const cbPost = document.getElementById('editGroupAllowPost');
            const cbData = document.getElementById('editGroupAllowData');
            const cbList = document.getElementById('editGroupMemberListVisible');
            const cbContact = document.getElementById('editGroupAllowAddContact');
            if (privateSection && cbPrivate) {
                const isPrivate = (group.is_private_group === 1 || group.is_private_group === '1');
                cbPrivate.checked = !!isPrivate;
                const def = (v) => (v === 1 || v === '1');
                if (cbPost) cbPost.checked = group.allow_member_post !== undefined && group.allow_member_post !== null ? def(group.allow_member_post) : true;
                if (cbData) cbData.checked = group.allow_data_send !== undefined && group.allow_data_send !== null ? def(group.allow_data_send) : true;
                if (cbList) cbList.checked = group.member_list_visible !== undefined && group.member_list_visible !== null ? def(group.member_list_visible) : true;
                if (cbContact) cbContact.checked = group.allow_add_contact_from_group !== undefined && group.allow_add_contact_from_group !== null ? def(group.allow_add_contact_from_group) : true;
                privateSection.style.display = 'block';
                if (privateOpts) privateOpts.style.display = isPrivate ? 'block' : 'none';
            }
        }
    } catch (error) {
        console.error('グループ情報取得エラー:', error);
    }

    // プライベート設定欄は編集時は常に表示（取得失敗時も表示して設定変更可能にする）
    const privateSection = document.getElementById('editGroupPrivateSection');
    if (privateSection) {
        privateSection.style.display = 'block';
    }
}

function closeEditModal() {
    document.getElementById('editGroupModal').classList.remove('show');
}

async function saveGroupName(e) {
    e.preventDefault();

    const id = document.getElementById('editGroupId').value;
    const name = document.getElementById('editGroupName').value.trim();
    const name_en = document.getElementById('editGroupNameEn').value.trim();
    const name_zh = document.getElementById('editGroupNameZh').value.trim();

    if (!name) {
        alert('グループ名（日本語）を入力してください');
        return;
    }

    const payload = { id, name, name_en, name_zh };
    // プライベート設定は編集モーダルにある場合は必ず整数で送信（APIで確実にUPDATEされるようにする）
    const cbPrivate = document.getElementById('editGroupIsPrivate');
    const cbPost = document.getElementById('editGroupAllowPost');
    const cbData = document.getElementById('editGroupAllowData');
    const cbList = document.getElementById('editGroupMemberListVisible');
    const cbContact = document.getElementById('editGroupAllowAddContact');
    if (cbPrivate) {
        payload.is_private_group = cbPrivate.checked ? 1 : 0;
        payload.allow_member_post = (cbPost && cbPost.checked) ? 1 : 0;
        payload.allow_data_send = (cbData && cbData.checked) ? 1 : 0;
        payload.member_list_visible = (cbList && cbList.checked) ? 1 : 0;
        payload.allow_add_contact_from_group = (cbContact && cbContact.checked) ? 1 : 0;
    }

    try {
        const response = await fetch('/admin/api/groups.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data.success) {
            showToast('グループ名と設定を保存しました（プライベート設定を含む）', 'success');
            closeEditModal();
            loadGroups();
        } else {
            throw new Error(data.error || '保存に失敗しました');
        }
    } catch (error) {
        alert('エラー: ' + error.message);
    }
}

// ============ メンバー追加モーダル ============

async function openAddMemberModal() {
    document.getElementById('addMemberModal').classList.add('show');
    document.getElementById('memberSearchInput').value = '';
    document.getElementById('userListContainer').innerHTML = '<div class="loading">読み込み中...</div>';

    try {
        const response = await fetch('/admin/api/groups.php?all_users=1');
        const data = await response.json();

        if (data.success) {
            allUsers = data.users;
            renderUserList(allUsers);
        } else {
            throw new Error(data.error || 'ユーザー一覧の取得に失敗しました');
        }
    } catch (error) {
        document.getElementById('userListContainer').innerHTML = `<div class="loading">エラー: ${error.message}</div>`;
    }
}

function closeAddMemberModal() {
    document.getElementById('addMemberModal').classList.remove('show');
}

function renderUserList(users) {
    const container = document.getElementById('userListContainer');
    
    if (users.length === 0) {
        container.innerHTML = '<div class="loading">ユーザーが見つかりません</div>';
        return;
    }

    container.innerHTML = users.map(user => {
        const isExternal = user.member_type === 'external';
        const typeIcon = isExternal ? '🤝' : '🏢';
        const isAlreadyMember = currentGroupMemberIds.indexOf(parseInt(user.id, 10)) !== -1;
        
        if (isAlreadyMember) {
            return `
                <div class="user-list-item user-list-item--member">
                    <span class="user-type-icon" title="${isExternal ? '外部協力者' : '社員'}">${typeIcon}</span>
                    <span class="user-name">${escapeHtml(user.display_name)}</span>
                    <span class="user-fullname">${escapeHtml(user.full_name || '')}</span>
                    <span class="user-list-item-badge">メンバー</span>
                </div>
            `;
        }
        return `
            <div class="user-list-item" onclick="addMemberToGroup(${user.id}, '${escapeJs(user.display_name)}')">
                <span class="user-type-icon" title="${isExternal ? '外部協力者' : '社員'}">${typeIcon}</span>
                <span class="user-name">${escapeHtml(user.display_name)}</span>
                <span class="user-fullname">${escapeHtml(user.full_name || '')}</span>
                <button class="btn-small btn-success">追加</button>
            </div>
        `;
    }).join('');
}

function filterUserList() {
    const keyword = document.getElementById('memberSearchInput').value.toLowerCase();
    const filtered = allUsers.filter(user => 
        user.display_name.toLowerCase().includes(keyword) ||
        (user.full_name && user.full_name.toLowerCase().includes(keyword))
    );
    renderUserList(filtered);
}

async function addMemberToGroup(userId, displayName) {
    try {
        const response = await fetch('/admin/api/groups.php?action=add_member', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                group_id: currentGroupId,
                user_id: userId
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast(`${displayName} を追加しました`, 'success');
            closeAddMemberModal();
            showGroupDetail(currentGroupId, currentGroupName);
            loadGroups(); // メンバー数を更新
        } else {
            throw new Error(data.error || '追加に失敗しました');
        }
    } catch (error) {
        alert('エラー: ' + error.message);
    }
}

// ============ グループ削除モーダル ============

function openDeleteModal(groupId, groupName) {
    document.getElementById('deleteGroupId').value = groupId;
    document.getElementById('deleteGroupName').textContent = groupName;
    document.getElementById('deleteGroupModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteGroupModal').classList.remove('show');
}

async function confirmDeleteGroup() {
    const groupId = document.getElementById('deleteGroupId').value;

    try {
        const response = await fetch('/admin/api/groups.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: groupId })
        });

        const data = await response.json();

        if (data.success) {
            showToast('グループを削除しました', 'success');
            closeDeleteModal();
            loadGroups();
        } else {
            throw new Error(data.error || '削除に失敗しました');
        }
    } catch (error) {
        alert('エラー: ' + error.message);
    }
}

// ============ CSV出力 ============

async function exportGroupsCsv() {
    try {
        const response = await fetch('/admin/api/groups.php?export=csv');
        const blob = await response.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `groups_${formatDateForFile(new Date())}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        alert('CSV出力に失敗しました: ' + error.message);
    }
}

async function exportGroupMembersCsv() {
    if (!currentGroupId) return;
    
    try {
        const response = await fetch(`/admin/api/groups.php?id=${currentGroupId}&export=csv`);
        const blob = await response.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `group_members_${currentGroupId}_${formatDateForFile(new Date())}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        alert('CSV出力に失敗しました: ' + error.message);
    }
}

// ============ ユーティリティ関数 ============

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, (match) => {
        const escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return escapeMap[match];
    });
}

function escapeJs(str) {
    if (!str) return '';
    return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return `${date.getFullYear()}/${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`;
}

function formatDateForFile(date) {
    return `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}`;
}

// トースト通知
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.admin-toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = `admin-toast admin-toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}



