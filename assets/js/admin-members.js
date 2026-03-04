/**
 * 組織管理画面 - メンバー管理 JavaScript
 */

// グローバル変数
let currentPage = 1;
let totalPages = 1;
let searchKeyword = '';
let memberTypeFilter = '';
let deleteTargetId = null;
let orgGroupsCache = null;

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    setupEventListeners();
    // loadMembers() は loadMyOrganizations() 完了後に呼ばれる
});

// イベントリスナー設定
function setupEventListeners() {
    // 新規登録ボタン
    document.getElementById('btnAddMember').addEventListener('click', () => {
        openModal('add');
    });

    // CSV出力ボタン
    document.getElementById('btnExportCsv').addEventListener('click', exportCsv);

    // 検索
    document.getElementById('btnSearch').addEventListener('click', () => {
        searchKeyword = document.getElementById('searchInput').value;
        currentPage = 1;
        loadMembers();
    });

    document.getElementById('searchInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            searchKeyword = e.target.value;
            currentPage = 1;
            loadMembers();
        }
    });

    // メンバー追加：候補者検索
    const btnCandidateSearch = document.getElementById('btnCandidateSearch');
    const candidateSearchInput = document.getElementById('candidateSearchInput');
    if (btnCandidateSearch && candidateSearchInput) {
        btnCandidateSearch.addEventListener('click', searchCandidates);
        candidateSearchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchCandidates();
            }
        });
    }

    // フィルタータブ
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', (e) => {
            // アクティブ状態を更新
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            e.target.classList.add('active');
            
            // フィルター適用
            memberTypeFilter = e.target.dataset.filter || '';
            currentPage = 1;
            loadMembers();
        });
    });

    // モーダル閉じる
    document.getElementById('btnCloseModal').addEventListener('click', closeModal);
    document.getElementById('btnCancel').addEventListener('click', closeModal);
    document.getElementById('btnCloseDeleteModal').addEventListener('click', closeDeleteModal);
    document.getElementById('btnCancelDelete').addEventListener('click', closeDeleteModal);

    // 削除確認
    document.getElementById('btnConfirmDelete').addEventListener('click', confirmDelete);

    // フォーム送信
    document.getElementById('memberForm').addEventListener('submit', saveMember);

    // パスワード自動生成（ボタンがある場合のみ）
    const btnGeneratePassword = document.getElementById('btnGeneratePassword');
    if (btnGeneratePassword) btnGeneratePassword.addEventListener('click', generatePassword);

    // メンバー種別変更時の処理
    document.querySelectorAll('input[name="member_type"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            const externalNote = document.getElementById('externalNote');
            const isOrgAdminCheckbox = document.getElementById('isOrgAdmin');
            
            if (e.target.value === 'external') {
                externalNote.style.display = 'block';
                isOrgAdminCheckbox.checked = false;
                isOrgAdminCheckbox.disabled = true;
            } else {
                externalNote.style.display = 'none';
                isOrgAdminCheckbox.disabled = false;
            }
        });
    });

    // モーダル外クリックで閉じる
    document.getElementById('memberModal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeModal();
    });
    document.getElementById('deleteModal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeDeleteModal();
    });
}

// メンバー一覧読み込み
async function loadMembers() {
    const tbody = document.getElementById('membersBody');
    tbody.innerHTML = '<tr><td colspan="9" class="loading">読み込み中...</td></tr>';

    try {
        const params = new URLSearchParams({
            page: currentPage,
            search: searchKeyword
        });
        
        if (memberTypeFilter) {
            params.append('member_type', memberTypeFilter);
        }

        const response = await fetch(`/admin/api/members.php?${params}`);
        let data;
        try {
            data = await response.json();
        } catch (_) {
            const msg = response.status === 500
                ? 'サーバーエラーが発生しました。しばらく経ってから再度お試しください。'
                : (response.ok ? 'レスポンスの解析に失敗しました' : `サーバーエラー (${response.status})`);
            throw new Error(msg);
        }

        if (data.success) {
            renderMembers(data.members);
            renderPagination(data.pagination);
            renderStats(data.stats);
        } else {
            const msg = (data.error || data.message || '読み込みに失敗しました').toString();
            throw new Error(msg === 'サーバーエラーが発生しました'
                ? msg + ' しばらく経ってから再度お試しください。'
                : msg);
        }
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="9" class="loading">エラー: ${escapeHtml(error.message)}</td></tr>`;
    }
}

// メンバー追加：候補者検索
async function searchCandidates() {
    const input = document.getElementById('candidateSearchInput');
    const wrap = document.getElementById('candidateResultsWrap');
    const resultsEl = document.getElementById('candidateResults');
    if (!input || !wrap || !resultsEl) return;
    const q = (input.value || '').trim();
    if (q.length < 1) {
        wrap.style.display = 'none';
        return;
    }
    try {
        const response = await fetch(`/admin/api/members.php?action=search_candidates&q=${encodeURIComponent(q)}`);
        const data = await response.json();
        if (!data.success) {
            resultsEl.innerHTML = '<div class="loading">' + escapeHtml(data.message || '検索に失敗しました') + '</div>';
            wrap.style.display = 'block';
            return;
        }
        const candidates = data.candidates || [];
        if (candidates.length === 0) {
            resultsEl.innerHTML = '<div class="loading">該当する候補者はいません</div>';
        } else {
            resultsEl.innerHTML = candidates.map(c => {
                const name = escapeHtml(c.display_name || '');
                const email = escapeHtml(c.email || '');
                const fullName = (c.full_name && c.full_name !== c.display_name) ? ' <span class="result-email">(' + escapeHtml(c.full_name) + ')</span>' : '';
                return (
                    '<div class="add-member-result-item" data-user-id="' + parseInt(c.id, 10) + '">' +
                    '<div class="result-info">' +
                    '<span class="result-name">' + name + '</span>' + fullName + '<br>' +
                    '<span class="result-email">' + email + '</span>' +
                    '</div>' +
                    '<button type="button" class="btn btn-primary btn-add-candidate" data-user-id="' + parseInt(c.id, 10) + '">追加</button>' +
                    '</div>'
                );
            }).join('');
            resultsEl.querySelectorAll('.btn-add-candidate').forEach(btn => {
                btn.addEventListener('click', () => {
                    const userId = parseInt(btn.dataset.userId, 10);
                    if (userId) addCandidateAsMember(userId, btn);
                });
            });
        }
        wrap.style.display = 'block';
    } catch (err) {
        resultsEl.innerHTML = '<div class="loading">エラー: ' + escapeHtml(err.message) + '</div>';
        wrap.style.display = 'block';
    }
}

// メンバー追加：候補者を組織に追加
async function addCandidateAsMember(userId, buttonEl) {
    if (!userId || !buttonEl) return;
    buttonEl.disabled = true;
    buttonEl.textContent = '追加中...';
    try {
        const response = await fetch('/admin/api/members.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_existing', user_id: userId })
        });
        const data = await response.json();
        if (data.success) {
            showToast('✅ メンバーを追加しました', 'success');
            const row = buttonEl.closest('.add-member-result-item');
            if (row) row.remove();
            if (typeof loadMembers === 'function') loadMembers();
        } else {
            alert(data.message || '追加に失敗しました');
            buttonEl.disabled = false;
            buttonEl.textContent = '追加';
        }
    } catch (err) {
        alert('エラー: ' + err.message);
        buttonEl.disabled = false;
        buttonEl.textContent = '追加';
    }
}

// 統計カード描画
function renderStats(stats) {
    const container = document.getElementById('statsCards');
    if (!container || !stats) return;
    
    container.innerHTML = `
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-info">
                <div class="stat-value">${stats.total}</div>
                <div class="stat-label">総メンバー数</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div class="stat-info">
                <div class="stat-value">${stats.internal}</div>
                <div class="stat-label">社員（内部）</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🤝</div>
            <div class="stat-info">
                <div class="stat-value">${stats.external}</div>
                <div class="stat-label">外部協力者</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👑</div>
            <div class="stat-info">
                <div class="stat-value">${stats.admins}</div>
                <div class="stat-label">組織管理者</div>
            </div>
        </div>
    `;
}

// メンバー一覧描画
function renderMembers(members) {
    const tbody = document.getElementById('membersBody');
    
    if (members.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="loading">メンバーが見つかりません</td></tr>';
        return;
    }

    tbody.innerHTML = members.map(member => {
        const isExternal = member.member_type === 'external';
        const typeBadge = isExternal 
            ? '<span class="badge badge-external">🤝 外部</span>'
            : '<span class="badge badge-internal">🏢 社員</span>';
        
        // 登録済み（承諾前）／所属（承諾済み）。招待メール未設定時は組織管理で登録し本人にパスワードを伝える運用
        const isPending = (member.invitation_pending == 1 || member.invitation_pending === '1');
        const statusCell = isPending 
            ? '<span class="badge badge-pending">登録済み</span>' 
            : '<span class="badge badge-accepted">所属</span>';
        
        // 未成年・制限付きバッジ
        const minorBadge = member.is_minor == 1 ? '<span class="minor-badge">👶 未成年</span>' : '';
        const restrictedBadge = member.is_restricted == 1 ? '<span class="restricted-badge">🔒 制限付き</span>' : '';
        
        // 制限設定ボタン（未成年または制限付きメンバーの場合のみ表示）
        const showRestrictionsBtn = (member.is_minor == 1 || member.is_restricted == 1);
        const restrictionsBtn = showRestrictionsBtn 
            ? `<button class="btn-restrictions" onclick="openRestrictionsModal(${member.id}, '${escapeJs(member.display_name)}')" title="利用制限設定">⏰</button>`
            : '';
        
        const resendBtn = isPending 
            ? `<button class="btn-resend-invite" onclick="resendInvite(${member.id})" title="招待メールを送信（メール設定時のみ有効）">📧 送信</button>`
            : '';
        
        return `
            <tr>
                <td>${member.id}</td>
                <td>${typeBadge}</td>
                <td>
                    ${escapeHtml(member.full_name || '')}
                    ${minorBadge}${restrictedBadge}
                </td>
                <td>${escapeHtml(member.display_name)}</td>
                <td>${escapeHtml(member.email || member.phone || '')}</td>
                <td>
                    <span class="badge ${member.is_org_admin == 1 ? 'badge-admin' : 'badge-member'}">
                        ${member.is_org_admin == 1 ? '組織管理者' : '一般'}
                    </span>
                </td>
                <td>${statusCell}</td>
                <td>${formatDate(member.created_at)}</td>
                <td>
                    <div class="action-btns">
                        ${resendBtn}
                        ${restrictionsBtn}
                        <button onclick="editMember(${member.id})" title="編集">✏️</button>
                        <button onclick="deleteMember(${member.id}, '${escapeJs(member.display_name)}')" title="削除">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

// ページネーション描画（全ページをクリック可能に。10ページ以下は全番号表示、それ以上は前後2ページ＋先頭・末尾＋...）
function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    totalPages = pagination.total_pages;

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    var pagesToShow = [];
    if (totalPages <= 10) {
        for (var i = 1; i <= totalPages; i++) pagesToShow.push(i);
    } else {
        pagesToShow.push(1);
        var lo = Math.max(1, currentPage - 2);
        if (lo > 2) pagesToShow.push('...');
        for (var j = lo; j <= Math.min(totalPages, currentPage + 2); j++) pagesToShow.push(j);
        var hi = Math.min(totalPages, currentPage + 2);
        if (hi < totalPages - 1) pagesToShow.push('...');
        if (totalPages > 1) pagesToShow.push(totalPages);
    }

    var html = '';
    html += '<button ' + (currentPage === 1 ? 'disabled' : '') + ' onclick="goToPage(' + (currentPage - 1) + ')">◀</button>';
    for (var p = 0; p < pagesToShow.length; p++) {
        var num = pagesToShow[p];
        if (num === '...') {
            html += '<button disabled>...</button>';
        } else {
            html += '<button class="' + (num === currentPage ? 'active' : '') + '" onclick="goToPage(' + num + ')">' + num + '</button>';
        }
    }
    html += '<button ' + (currentPage === totalPages ? 'disabled' : '') + ' onclick="goToPage(' + (currentPage + 1) + ')">▶</button>';

    container.innerHTML = html;
}

// ページ移動
function goToPage(page) {
    currentPage = page;
    loadMembers();
}

// 組織グループ一覧を取得してチェックボックスを描画
async function loadMemberGroupsList(checkedIds = []) {
    const container = document.getElementById('memberGroupsList');
    if (!container) return;
    container.innerHTML = '<span class="loading-inline">読み込み中...</span>';

    try {
        let groups = orgGroupsCache;
        if (!groups) {
            const res = await fetch('/admin/api/members.php?action=org_groups');
            const data = await res.json();
            if (!data.success || !Array.isArray(data.groups)) {
                container.innerHTML = '<span class="loading-inline">グループがありません</span>';
                return;
            }
            orgGroupsCache = data.groups;
            groups = data.groups;
        }
        if (groups.length === 0) {
            container.innerHTML = '<span class="loading-inline">組織にグループがありません。グループ一覧で先に作成してください。</span>';
            return;
        }
        const set = new Set(checkedIds.map(String));
        container.innerHTML = groups.map(g => {
            const id = g.id;
            const name = escapeHtml(g.name || '無題のグループ');
            const checked = set.has(String(id)) ? ' checked' : '';
            return `<label><input type="checkbox" name="member_group_ids" value="${id}"${checked}> ${name}</label>`;
        }).join('');
    } catch (e) {
        container.innerHTML = '<span class="loading-inline">読み込みに失敗しました</span>';
    }
}

// モーダルを開く
async function openModal(mode, member = null) {
    const modal = document.getElementById('memberModal');
    const form = document.getElementById('memberForm');
    const title = document.getElementById('modalTitle');
    const passwordHint = document.getElementById('passwordHint');
    const externalNote = document.getElementById('externalNote');
    const isOrgAdminCheckbox = document.getElementById('isOrgAdmin');

    form.reset();
    document.getElementById('memberId').value = '';
    externalNote.style.display = 'none';
    isOrgAdminCheckbox.disabled = false;

    // メンバー種別をリセット（内部をデフォルト）
    document.querySelector('input[name="member_type"][value="internal"]').checked = true;

    const groupIdsToCheck = (member && Array.isArray(member.group_ids)) ? member.group_ids : [];
    await loadMemberGroupsList(groupIdsToCheck);

    if (mode === 'add') {
        title.textContent = '新規メンバー登録';
        passwordHint.style.display = 'block';
        document.getElementById('password').required = false;
        document.getElementById('password').placeholder = '空欄でOK（本人にパスワードを伝えてください）';
    } else {
        title.textContent = 'メンバー編集';
        document.getElementById('password').required = false;
        document.getElementById('password').placeholder = '変更時のみ入力';
        
        // 既存データをセット
        document.getElementById('memberId').value = member.id;
        document.getElementById('fullName').value = member.full_name || '';
        document.getElementById('displayName').value = member.display_name;
        document.getElementById('email').value = member.email || '';
        var phoneEl = document.getElementById('phone');
        if (phoneEl) phoneEl.value = member.phone || '';
        document.getElementById('isOrgAdmin').checked = member.is_org_admin == 1;
        
        // メンバー種別をセット
        const memberType = member.member_type || 'internal';
        document.querySelector(`input[name="member_type"][value="${memberType}"]`).checked = true;
        
        if (memberType === 'external') {
            externalNote.style.display = 'block';
            isOrgAdminCheckbox.disabled = true;
        }
    }

    modal.classList.add('show');
}

// モーダルを閉じる
function closeModal() {
    document.getElementById('memberModal').classList.remove('show');
}

// 削除モーダルを閉じる
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    deleteTargetId = null;
}

// 編集
async function editMember(id) {
    try {
        const response = await fetch(`/admin/api/members.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            openModal('edit', data.member);
        } else {
            throw new Error(data.error || 'メンバー情報の取得に失敗しました');
        }
    } catch (error) {
        alert(error.message);
    }
}

// 削除確認
function deleteMember(id, name) {
    deleteTargetId = id;
    document.getElementById('deleteMessage').textContent = `「${name}」さんを削除しますか？`;
    document.getElementById('deleteModal').classList.add('show');
}

// 削除実行
async function confirmDelete() {
    if (!deleteTargetId) return;

    try {
        const response = await fetch('/admin/api/members.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: deleteTargetId })
        });

        const data = await response.json();

        if (data.success) {
            closeDeleteModal();
            loadMembers();
            showToast('✅ メンバーを削除しました', 'success');
        } else {
            throw new Error(data.error || '削除に失敗しました');
        }
    } catch (error) {
        alert(error.message);
    }
}

// 招待メール送信（未承諾のメンバー向け。メール設定時のみ有効）
async function resendInvite(userId) {
    if (!userId) return;
    try {
        const response = await fetch('/admin/api/members.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resend_invite', user_id: userId })
        });
        let data;
        try {
            data = await response.json();
        } catch (_) {
            throw new Error(response.ok ? '送信に失敗しました' : 'サーバーエラーが発生しました。しばらくしてから再度お試しください。');
        }
        if (data.success) {
            showToast('✅ ' + (data.message || '招待メールを送信しました'), 'success');
        } else {
            throw new Error(data.error || data.message || '送信に失敗しました');
        }
    } catch (error) {
        alert(error.message);
    }
}

// 保存
async function saveMember(e) {
    e.preventDefault();

    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.is_org_admin = document.getElementById('isOrgAdmin').checked ? 1 : 0;
    data.member_type = document.querySelector('input[name="member_type"]:checked').value;

    const groupIds = [];
    document.querySelectorAll('#memberGroupsList input[name="member_group_ids"]:checked').forEach(function(cb) {
        const n = parseInt(cb.value, 10);
        if (!isNaN(n)) groupIds.push(n);
    });
    data.group_ids = groupIds;

    const isEdit = !!data.id;

    try {
        const response = await fetch('/admin/api/members.php', {
            method: isEdit ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

            if (result.success) {
            closeModal();
            loadMembers();
            showToast('✅ ' + (result.message || (isEdit ? '保存しました' : '登録しました')), 'success');
        } else {
            throw new Error(result.error || '保存に失敗しました');
        }
    } catch (error) {
        alert(error.message);
    }
}

// トースト通知を表示
function showToast(message, type = 'info') {
    // 既存のトーストを削除
    const existingToast = document.querySelector('.admin-toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = `admin-toast admin-toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    // アニメーション
    setTimeout(() => toast.classList.add('show'), 10);
    
    // 3秒後に消える
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// パスワード自動生成
function generatePassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let password = '';
    for (let i = 0; i < 10; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('password').value = password;
}

// CSV出力
async function exportCsv() {
    try {
        const response = await fetch('/admin/api/members.php?export=csv');
        const blob = await response.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `members_${formatDateForFile(new Date())}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        alert('CSV出力に失敗しました: ' + error.message);
    }
}

// ユーティリティ関数
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



