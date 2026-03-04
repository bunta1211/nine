/**
 * 専門AI管理ページ JavaScript
 */
(function() {
    'use strict';

    const TYPE_LABELS = {
        work: '業務内容統括AI', people: '人財AI', finance: '会計統括AI',
        compliance: 'コンプライアンスAI', mentalcare: 'メンタルケアAI',
        education: '社内教育型AI', customer: '顧客管理AI'
    };

    const $ = id => document.getElementById(id);
    let currentOrgId = '';
    let specialists = [];

    async function loadSpecialists() {
        currentOrgId = $('aispOrgSelect').value;
        if (!currentOrgId) {
            $('aispGrid').innerHTML = '<p class="aisp-empty">組織を選択してください</p>';
            return;
        }
        try {
            const res = await fetch(`../api/ai-specialists.php?action=list&organization_id=${currentOrgId}`);
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            specialists = data.specialists || [];
            renderGrid();
        } catch (e) {
            $('aispGrid').innerHTML = '<p class="aisp-empty">読み込みに失敗しました</p>';
        }
    }

    function renderGrid() {
        if (!specialists.length) {
            $('aispGrid').innerHTML = '<p class="aisp-empty">専門AIが設定されていません。「専門AI一式を初期設定」で作成できます。</p>';
            return;
        }
        $('aispGrid').innerHTML = specialists.map(s => `
            <div class="aisp-card">
                <div class="aisp-card-header">
                    <span class="aisp-card-name">${escHtml(s.display_name || TYPE_LABELS[s.specialist_type] || s.specialist_type)}</span>
                    <span class="aisp-card-status ${s.is_enabled == 1 ? 'enabled' : 'disabled'}">
                        ${s.is_enabled == 1 ? '有効' : '無効'}
                    </span>
                </div>
                <div class="aisp-card-type">${s.specialist_type}</div>
                <div class="aisp-card-meta">
                    ${s.system_prompt ? 'カスタムプロンプト設定済み' : 'デフォルトプロンプト'}
                </div>
                <div class="aisp-card-actions">
                    <button onclick="aispEdit('${s.specialist_type}')">編集</button>
                </div>
            </div>
        `).join('');
    }

    function openEdit(type) {
        const s = specialists.find(x => x.specialist_type === type);
        if (!s) return;
        $('aispEditId').value = s.id;
        $('aispEditType').value = s.specialist_type;
        $('aispEditName').value = s.display_name || '';
        $('aispEditPrompt').value = s.system_prompt || '';
        $('aispEditRules').value = s.custom_rules || '';
        $('aispEditEnabled').checked = s.is_enabled == 1;
        $('aispModalTitle').textContent = (TYPE_LABELS[type] || type) + ' の設定';
        $('aispModal').style.display = '';
    }

    async function save() {
        const body = {
            id: parseInt($('aispEditId').value),
            display_name: $('aispEditName').value,
            system_prompt: $('aispEditPrompt').value,
            custom_rules: $('aispEditRules').value,
            is_enabled: $('aispEditEnabled').checked ? 1 : 0,
        };
        try {
            const res = await fetch(`../api/ai-specialists.php?action=update&organization_id=${currentOrgId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            $('aispModal').style.display = 'none';
            loadSpecialists();
        } catch (e) {
            alert('保存に失敗しました');
        }
    }

    async function provision() {
        if (!currentOrgId) { alert('組織を選択してください'); return; }
        if (!confirm('この組織に専門AI一式（7種）を初期設定しますか？')) return;
        try {
            const res = await fetch(`../api/ai-specialists.php?action=provision&organization_id=${currentOrgId}`, {
                method: 'POST',
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            loadSpecialists();
        } catch (e) {
            alert('初期設定に失敗しました');
        }
    }

    async function loadFlags() {
        try {
            const res = await fetch('../api/ai-specialists.php?action=flags');
            const data = await res.json();
            if (!data.flags || !data.flags.length) {
                $('aispFlags').innerHTML = '<p class="aisp-empty">機能フラグが設定されていません</p>';
                return;
            }
            $('aispFlags').innerHTML = data.flags.map(f => `
                <div class="aisp-flag-row">
                    <span class="aisp-flag-num">${f.feature_number}</span>
                    <span class="aisp-flag-name">${escHtml(f.feature_name)}</span>
                    <span class="aisp-flag-status">
                        <select onchange="aispUpdateFlag(${f.feature_number}, this.value)">
                            <option value="disabled" ${f.status==='disabled'?'selected':''}>無効</option>
                            <option value="beta" ${f.status==='beta'?'selected':''}>ベータ</option>
                            <option value="enabled" ${f.status==='enabled'?'selected':''}>有効</option>
                        </select>
                    </span>
                </div>
            `).join('');
        } catch (e) {
            $('aispFlags').innerHTML = '<p class="aisp-empty">読み込みに失敗しました</p>';
        }
    }

    window.aispUpdateFlag = async function(num, status) {
        try {
            await fetch('../api/ai-specialists.php?action=update_flag', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ feature_number: num, status }),
            });
        } catch (e) {
            alert('更新に失敗しました');
        }
    };

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    $('aispLoadBtn').addEventListener('click', loadSpecialists);
    $('aispProvisionBtn').addEventListener('click', provision);
    $('aispSaveBtn').addEventListener('click', save);
    $('aispCancelBtn').addEventListener('click', () => $('aispModal').style.display = 'none');
    $('aispModalClose').addEventListener('click', () => $('aispModal').style.display = 'none');

    window.aispEdit = openEdit;

    loadFlags();
})();
