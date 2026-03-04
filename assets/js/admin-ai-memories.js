/**
 * AI記憶管理ページ JavaScript
 */
(function() {
    'use strict';

    const TYPE_LABELS = {
        work: '業務内容統括', people: '人財', finance: '会計統括',
        compliance: 'コンプライアンス', mentalcare: 'メンタルケア',
        education: '社内教育', customer: '顧客管理'
    };
    const SOURCE_LABELS = {
        auto_batch: '自動抽出', auto_chat: 'チャット', manual: '手動', import: 'インポート'
    };

    let currentPage = 1;
    let currentOrgId = '';

    const $ = id => document.getElementById(id);

    function getOrgId() {
        return $('aimemOrgSelect').value;
    }

    function buildParams() {
        const params = new URLSearchParams();
        params.set('action', 'search');
        params.set('organization_id', getOrgId());
        const type = $('aimemTypeSelect').value;
        if (type) params.set('specialist_type', type);
        const kw = $('aimemKeyword').value.trim();
        if (kw) params.set('keyword', kw);
        const df = $('aimemDateFrom').value;
        if (df) params.set('date_from', df);
        const dt = $('aimemDateTo').value;
        if (dt) params.set('date_to', dt);
        params.set('page', currentPage);
        params.set('per_page', 20);
        return params;
    }

    async function search() {
        const orgId = getOrgId();
        if (!orgId) {
            $('aimemTableBody').innerHTML = '<tr><td colspan="7" class="aimem-empty">組織を選択してください</td></tr>';
            return;
        }
        currentOrgId = orgId;

        try {
            const res = await fetch('../api/ai-memories.php?' + buildParams());
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            renderTable(data.items);
            renderPagination(data);
            renderStats(data);
        } catch (e) {
            console.error(e);
            $('aimemTableBody').innerHTML = '<tr><td colspan="7" class="aimem-empty">エラーが発生しました</td></tr>';
        }
    }

    function renderTable(items) {
        if (!items.length) {
            $('aimemTableBody').innerHTML = '<tr><td colspan="7" class="aimem-empty">記憶が見つかりません</td></tr>';
            return;
        }
        $('aimemTableBody').innerHTML = items.map(item => `
            <tr>
                <td>${item.id}</td>
                <td><span class="aimem-type-badge">${TYPE_LABELS[item.specialist_type] || item.specialist_type}</span></td>
                <td>${escHtml(item.title || '(無題)')}</td>
                <td>${escHtml(item.content_preview || '')}</td>
                <td><span class="aimem-source-badge">${SOURCE_LABELS[item.source_type] || item.source_type}</span></td>
                <td>${formatDate(item.updated_at)}</td>
                <td>
                    <button class="aimem-btn-sm" onclick="aimemEdit(${item.id})">編集</button>
                    <button class="aimem-btn-sm aimem-btn-danger" onclick="aimemDelete(${item.id})">削除</button>
                </td>
            </tr>
        `).join('');
    }

    function renderPagination(data) {
        if (data.pages <= 1) { $('aimemPagination').innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= data.pages; i++) {
            html += `<button class="${i === data.page ? 'active' : ''}" onclick="aimemGoPage(${i})">${i}</button>`;
        }
        $('aimemPagination').innerHTML = html;
    }

    function renderStats(data) {
        $('aimemStats').innerHTML = `
            <div class="aimem-stat-card">
                <span>合計</span><span class="aimem-stat-count">${data.total}</span><span>件</span>
            </div>
        `;
    }

    async function openEdit(memoryId) {
        if (!currentOrgId) return;
        try {
            const res = await fetch(`../api/ai-memories.php?action=get&organization_id=${currentOrgId}&id=${memoryId}`);
            const item = await res.json();
            if (item.error) { alert(item.error); return; }
            $('aimemEditId').value = item.id;
            $('aimemEditType').value = item.specialist_type;
            $('aimemEditTitle').value = item.title || '';
            $('aimemEditContent').value = item.content || '';
            $('aimemEditTags').value = (item.tags || []).join(', ');
            if (item.source_conversation_id) {
                $('aimemSourceInfo').style.display = '';
                $('aimemSourceText').textContent = `会話ID: ${item.source_conversation_id}` +
                    (item.source_message_id ? ` / メッセージID: ${item.source_message_id}` : '');
            } else {
                $('aimemSourceInfo').style.display = 'none';
            }
            $('aimemModalTitle').textContent = '記憶の編集';
            $('aimemModal').style.display = '';
        } catch (e) {
            alert('取得に失敗しました');
        }
    }

    function openAdd() {
        if (!currentOrgId) { alert('先に組織を選択してください'); return; }
        $('aimemEditId').value = '';
        $('aimemEditType').value = 'work';
        $('aimemEditTitle').value = '';
        $('aimemEditContent').value = '';
        $('aimemEditTags').value = '';
        $('aimemSourceInfo').style.display = 'none';
        $('aimemModalTitle').textContent = '記憶の追加';
        $('aimemModal').style.display = '';
    }

    async function save() {
        const memId = $('aimemEditId').value;
        const tags = $('aimemEditTags').value.split(',').map(t => t.trim()).filter(Boolean);
        const body = {
            specialist_type: $('aimemEditType').value,
            title: $('aimemEditTitle').value,
            content: $('aimemEditContent').value,
            tags: tags,
        };
        const action = memId ? 'update' : 'create';
        if (memId) body.id = parseInt(memId);

        try {
            const res = await fetch(`../api/ai-memories.php?action=${action}&organization_id=${currentOrgId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            $('aimemModal').style.display = 'none';
            search();
        } catch (e) {
            alert('保存に失敗しました');
        }
    }

    async function deleteMemory(memoryId) {
        if (!confirm('この記憶を削除しますか？')) return;
        try {
            const res = await fetch(`../api/ai-memories.php?action=delete&organization_id=${currentOrgId}&id=${memoryId}`, {
                method: 'POST',
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            search();
        } catch (e) {
            alert('削除に失敗しました');
        }
    }

    async function showHistory() {
        const memId = $('aimemEditId').value;
        if (!memId) return;
        try {
            const res = await fetch(`../api/ai-memories.php?action=history&organization_id=${currentOrgId}&id=${memId}`);
            const items = await res.json();
            $('aimemHistoryBody').innerHTML = items.length
                ? items.map(h => `
                    <div class="aimem-history-item">
                        <span class="aimem-history-action">${h.action}</span>
                        <span class="aimem-history-date">${formatDate(h.changed_at)}</span>
                        <span> by ${escHtml(h.changed_by_name || 'unknown')}</span>
                    </div>
                `).join('')
                : '<p class="aimem-empty">履歴がありません</p>';
            $('aimemHistoryModal').style.display = '';
        } catch (e) {
            alert('履歴の取得に失敗しました');
        }
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatDate(s) {
        if (!s) return '';
        const d = new Date(s);
        return d.toLocaleDateString('ja-JP') + ' ' + d.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    }

    $('aimemSearchBtn').addEventListener('click', () => { currentPage = 1; search(); });
    $('aimemKeyword').addEventListener('keydown', e => { if (e.key === 'Enter') { currentPage = 1; search(); } });
    $('aimemAddBtn').addEventListener('click', openAdd);
    $('aimemSaveBtn').addEventListener('click', save);
    $('aimemCancelBtn').addEventListener('click', () => $('aimemModal').style.display = 'none');
    $('aimemModalClose').addEventListener('click', () => $('aimemModal').style.display = 'none');
    $('aimemHistoryBtn').addEventListener('click', showHistory);
    $('aimemHistoryModalClose').addEventListener('click', () => $('aimemHistoryModal').style.display = 'none');

    window.aimemEdit = openEdit;
    window.aimemDelete = deleteMemory;
    window.aimemGoPage = p => { currentPage = p; search(); };
})();
