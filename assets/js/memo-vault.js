/**
 * メモページ・金庫（ログインパスワードで開錠・一覧・CRUD）
 */
(function() {
    var vaultToken = null;
    var TK = 'social9_vault_token';
    var EK = 'social9_vault_expires';

    function getStored() {
        try {
            var t = sessionStorage.getItem(TK);
            var e = sessionStorage.getItem(EK);
            if (t && e && new Date(e) > new Date()) { vaultToken = t; return true; }
        } catch (_) {}
        vaultToken = null;
        return false;
    }
    function setStored(token, exp) {
        vaultToken = token;
        try { sessionStorage.setItem(TK, token); sessionStorage.setItem(EK, exp); } catch (_) {}
    }
    function clearStored() {
        vaultToken = null;
        try { sessionStorage.removeItem(TK); sessionStorage.removeItem(EK); } catch (_) {}
    }

    function api(path, opts) {
        opts = opts || {};
        var h = opts.headers || {};
        if (vaultToken) h['X-Vault-Token'] = vaultToken;
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            h['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        return fetch(path, { method: opts.method || 'GET', headers: h, body: opts.body, credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(d) {
                if (!d.success && (r.status === 401 || (d.message && d.message.indexOf('ロック') !== -1))) clearStored();
                return d;
            }); });
    }

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    /* ---------- 金庫を開く（本人確認のため毎回パスワード入力） ---------- */
    window.openVaultModal = function() {
        clearStored();
        document.getElementById('vaultUnlockStatus').textContent = '';
        var pwEl = document.getElementById('vaultPasswordInput');
        if (pwEl) pwEl.value = '';
        document.getElementById('vaultUnlockModal').classList.add('active');
    };
    window.closeVaultUnlockModal = function() {
        document.getElementById('vaultUnlockModal').classList.remove('active');
        var pwEl = document.getElementById('vaultPasswordInput');
        if (pwEl) pwEl.value = '';
        document.getElementById('vaultUnlockStatus').textContent = '';
    };
    window.closeVaultContentModal = function() {
        clearStored();
        document.getElementById('vaultContentModal').classList.remove('active');
        hideVaultAddForm();
    };

    /* ---------- パスワードで開錠 ---------- */
    window.unlockVaultWithPassword = function() {
        var st = document.getElementById('vaultUnlockStatus');
        var btn = document.getElementById('vaultUnlockBtn');
        var pwEl = document.getElementById('vaultPasswordInput');
        var password = (pwEl && pwEl.value) ? pwEl.value : '';
        if (!password) {
            st.textContent = 'パスワードを入力してください。';
            return;
        }
        st.textContent = '確認中...';
        btn.disabled = true;
        api('api/vault.php', { method: 'POST', body: { action: 'unlock', password: password } })
            .then(function(data) {
                if (data.success && data.vault_token) {
                    setStored(data.vault_token, data.expires_at || '');
                    if (pwEl) pwEl.value = '';
                    closeVaultUnlockModal();
                    document.getElementById('vaultContentModal').classList.add('active');
                    loadVaultList();
                } else {
                    st.textContent = data.message || data.error || 'パスワードが正しくありません。';
                }
                btn.disabled = false;
            })
            .catch(function(err) {
                st.textContent = 'エラー: ' + (err.message || err);
                btn.disabled = false;
            });
    };

    /* ---------- 金庫一覧 ---------- */
    window.loadVaultList = function() {
        var area = document.getElementById('vaultListArea');
        if (!vaultToken) { area.innerHTML = '<p>金庫がロックされています。</p>'; return; }
        area.innerHTML = '<p>読み込み中...</p>';
        api('api/vault.php?action=list').then(function(data) {
            if (!data.success) {
                area.innerHTML = '<p>' + esc(data.message || data.error || '取得失敗') + '</p>';
                if ((data.message || data.error || '').indexOf('ロック') !== -1) { closeVaultContentModal(); openVaultModal(); }
                return;
            }
            var items = data.items || [];
            if (!items.length) { area.innerHTML = '<p>金庫にはまだ何もありません。「追加」からパスワード・メモを保存できます。</p>'; return; }
            var html = '';
            items.forEach(function(it) {
                var t = it.type === 'password' ? 'パスワード' : (it.type === 'note' ? 'メモ' : 'ファイル');
                html += '<div class="memos-vault-item" data-id="' + it.id + '">';
                html += '<span><strong>' + esc(it.title) + '</strong> <small>(' + t + ')</small></span>';
                html += '<span>';
                html += '<button type="button" class="memo-action-btn" onclick="vaultOpenItem(' + it.id + ')">開く</button> ';
                html += '<button type="button" class="memo-action-btn" onclick="vaultEditItem(' + it.id + ')">編集</button> ';
                html += '<button type="button" class="memo-action-btn delete" onclick="vaultDeleteItem(' + it.id + ')">削除</button>';
                html += '</span></div>';
            });
            area.innerHTML = html;
        });
    };

    /* ---------- 項目を開く ---------- */
    window.vaultOpenItem = function(id) {
        if (!vaultToken) return;
        api('api/vault.php?action=get&id=' + id).then(function(data) {
            if (!data.success || !data.item) { alert(data.message || data.error || '取得失敗'); return; }
            var it = data.item;
            if (it.type === 'password') {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(it.payload).then(function() { alert('クリップボードにコピーしました。'); });
                } else {
                    prompt('パスワード（コピーして使用）:', it.payload);
                }
            } else {
                alert(it.title + '\n\n' + (it.payload || ''));
            }
        });
    };

    /* ---------- 項目を削除 ---------- */
    window.vaultDeleteItem = function(id) {
        if (!vaultToken || !confirm('この項目を削除しますか？')) return;
        api('api/vault.php', { method: 'POST', body: { action: 'delete', id: id } }).then(function(d) {
            if (d.success) loadVaultList(); else alert(d.message || d.error || '削除失敗');
        });
    };

    /* ---------- 追加/編集フォーム ---------- */
    var editingId = null;

    function showFormArea(mode, title, type, payload) {
        editingId = mode === 'edit' ? editingId : null;
        document.getElementById('vaultAddFormArea').style.display = 'block';
        document.getElementById('vaultAddBtnArea').style.display = 'none';
        document.getElementById('vaultAddTitle').value = title || '';
        document.getElementById('vaultAddPayload').value = payload || '';
        document.getElementById('vaultAddType').value = type || 'note';
        var saveBtn = document.getElementById('vaultAddFormArea').querySelector('.btn-primary');
        if (saveBtn) saveBtn.textContent = mode === 'edit' ? '更新' : '保存';
    }

    window.showVaultAddForm = function() {
        editingId = null;
        showFormArea('add', '', 'note', '');
    };

    window.vaultEditItem = function(id) {
        if (!vaultToken) return;
        api('api/vault.php?action=get&id=' + id).then(function(data) {
            if (!data.success || !data.item) { alert(data.message || data.error || '取得失敗'); return; }
            editingId = id;
            showFormArea('edit', data.item.title, data.item.type, data.item.payload);
        });
    };

    window.hideVaultAddForm = function() {
        editingId = null;
        var el = document.getElementById('vaultAddFormArea');
        if (el) el.style.display = 'none';
        var ba = document.getElementById('vaultAddBtnArea');
        if (ba) ba.style.display = 'block';
    };

    window.submitVaultAdd = function() {
        var type = document.getElementById('vaultAddType').value;
        var title = document.getElementById('vaultAddTitle').value.trim();
        var payload = document.getElementById('vaultAddPayload').value;
        if (!title) { alert('タイトルは必須です。'); return; }

        var body;
        if (editingId) {
            body = { action: 'update', id: editingId, type: type, title: title, payload: payload };
        } else {
            body = { action: 'create', type: type, title: title, payload: payload };
        }
        api('api/vault.php', { method: 'POST', body: body })
        .then(function(d) {
            if (d.success) { hideVaultAddForm(); loadVaultList(); }
            else alert(d.message || d.error || (editingId ? '更新失敗' : '追加失敗'));
        });
    };
})();
