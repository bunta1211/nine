/**
 * AI秘書（AIクローン）専用 右パネル ロジック
 */
(function () {
    'use strict';

    const API_JUDGMENT = 'api/ai-judgment.php';
    const API_AI       = 'api/ai.php';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    function apiPost(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(r => r.json());
    }

    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin' }).then(r => r.json());
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ---- State ----
    let folders = [];

    // ---- Public API ----
    window.SecRP = {

        init: function (settings) {
            const panel = qs('#secretaryRightPanel');
            if (!panel) return;

            if (settings) {
                const langSel = qs('#secCloneLang');
                if (langSel) langSel.value = settings.clone_training_language || 'ja';

                SecRP._renderMemory(settings.conversation_memory_summary || '');
                SecRP._renderStats(settings.reply_stats || {});

                const toggle = qs('#secAutoReplyToggle');
                if (toggle) toggle.checked = (settings.clone_auto_reply_enabled == 1);
            }

            SecRP.loadFolders();
        },

        show: function () {
            const p = qs('#secretaryRightPanel');
            if (p) p.style.display = '';
        },
        hide: function () {
            const p = qs('#secretaryRightPanel');
            if (p) p.style.display = 'none';
        },

        // ---- Language ----
        saveLang: function (val) {
            apiPost(API_AI, { action: 'save_clone_settings', clone_training_language: val });
        },

        // ---- Auto Reply ----
        saveAutoReply: function (on) {
            apiPost(API_AI, { action: 'save_clone_settings', clone_auto_reply_enabled: on ? 1 : 0 });
        },

        // ---- Judgment Folders ----
        loadFolders: function () {
            apiGet(API_JUDGMENT + '?action=list_folders').then(function (res) {
                folders = (res.success && res.folders) ? res.folders : [];
                SecRP._renderTree();
            }).catch(function () {
                qs('#secJudgmentTree').innerHTML = '<p class="sec-rp-muted">読み込みに失敗しました</p>';
            });
        },

        addFolder: function () {
            var name = prompt('新しいフォルダ名を入力：');
            if (!name || !name.trim()) return;
            apiPost(API_JUDGMENT, { action: 'create_folder', name: name.trim() }).then(function (res) {
                if (res.success) SecRP.loadFolders();
                else alert(res.message || 'エラー');
            });
        },

        renameFolder: function (id) {
            var f = folders.find(function (x) { return x.id == id; });
            var name = prompt('フォルダ名を変更：', f ? f.name : '');
            if (!name || !name.trim()) return;
            apiPost(API_JUDGMENT, { action: 'rename_folder', folder_id: id, name: name.trim() }).then(function (res) {
                if (res.success) SecRP.loadFolders();
            });
        },

        deleteFolder: function (id) {
            if (!confirm('このフォルダと中のアイテムを削除しますか？')) return;
            apiPost(API_JUDGMENT, { action: 'delete_folder', folder_id: id }).then(function (res) {
                if (res.success) SecRP.loadFolders();
            });
        },

        toggleFolder: function (id) {
            var head = qs('#secFolder_' + id + ' .sec-rp-folder-head');
            var items = qs('#secFolder_' + id + ' .sec-rp-items');
            if (!head || !items) return;
            var open = head.classList.toggle('sec-rp-open');
            items.style.display = open ? '' : 'none';
            if (open && !items.dataset.loaded) {
                items.dataset.loaded = '1';
                SecRP.loadItems(id);
            }
        },

        loadItems: function (folderId) {
            var container = qs('#secFolderItems_' + folderId);
            if (!container) return;
            container.innerHTML = '<div class="sec-rp-loading">...</div>';
            apiGet(API_JUDGMENT + '?action=list_items&folder_id=' + folderId).then(function (res) {
                var items = (res.success && res.items) ? res.items : [];
                SecRP._renderItems(folderId, items);
            });
        },

        addItem: function (folderId) {
            SecRP._openItemModal(folderId, null);
        },

        editItem: function (folderId, itemId) {
            var container = qs('#secFolderItems_' + folderId);
            if (!container) return;
            var el = container.querySelector('[data-item-id="' + itemId + '"]');
            var title = el ? el.dataset.itemTitle : '';
            var content = el ? el.dataset.itemContent : '';
            SecRP._openItemModal(folderId, { id: itemId, title: title, content: content });
        },

        deleteItem: function (folderId, itemId) {
            if (!confirm('このアイテムを削除しますか？')) return;
            apiPost(API_JUDGMENT, { action: 'delete_item', item_id: itemId }).then(function () {
                SecRP.loadItems(folderId);
            });
        },

        // ---- Conversation Memory ----
        analyzeMemory: function () {
            var btn = qs('.sec-rp-action-btn');
            if (btn) { btn.disabled = true; btn.textContent = '分析中...'; }
            apiPost(API_AI, { action: 'analyze_conversation_memory' }).then(function (res) {
                var summary = (res && res.conversation_memory_summary) || (res.data && res.data.conversation_memory_summary) || '';
                if (res.success) {
                    SecRP._renderMemory(summary);
                } else {
                    alert(res.message || '分析に失敗しました');
                }
            }).catch(function (err) {
                alert('通信エラー');
            }).finally(function () {
                if (btn) { btn.disabled = false; btn.innerHTML = '<span>🔄</span> 今すぐ再分析'; }
            });
        },

        // ---- Internal ----

        _renderTree: function () {
            var tree = qs('#secJudgmentTree');
            if (!tree) return;
            if (!folders.length) {
                tree.innerHTML = '<p class="sec-rp-muted">フォルダがありません</p>';
                return;
            }
            var html = '';
            folders.forEach(function (f) {
                html += '<div class="sec-rp-folder" id="secFolder_' + f.id + '">'
                    + '<div class="sec-rp-folder-head" onclick="SecRP.toggleFolder(' + f.id + ')">'
                    + '<span class="sec-rp-folder-icon">▶</span>'
                    + '<span>' + escHtml(f.name) + '</span>'
                    + '<span class="sec-rp-folder-actions">'
                    + '<button onclick="event.stopPropagation();SecRP.renameFolder(' + f.id + ')" title="名前変更">✏</button>'
                    + '<button onclick="event.stopPropagation();SecRP.deleteFolder(' + f.id + ')" title="削除">🗑</button>'
                    + '</span>'
                    + '</div>'
                    + '<div class="sec-rp-items" id="secFolderItems_' + f.id + '" style="display:none"></div>'
                    + '</div>';
            });
            tree.innerHTML = html;
        },

        _renderItems: function (folderId, items) {
            var container = qs('#secFolderItems_' + folderId);
            if (!container) return;
            var html = '';
            items.forEach(function (it) {
                html += '<div class="sec-rp-item" data-item-id="' + it.id + '" '
                    + 'data-item-title="' + escHtml(it.title || '') + '" '
                    + 'data-item-content="' + escHtml(it.content || '') + '" '
                    + 'onclick="SecRP.editItem(' + folderId + ',' + it.id + ')">'
                    + '<span class="sec-rp-item-title">' + escHtml(it.title || '(無題)') + '</span>'
                    + '<button class="sec-rp-item-del" onclick="event.stopPropagation();SecRP.deleteItem(' + folderId + ',' + it.id + ')" title="削除">×</button>'
                    + '</div>';
            });
            html += '<button class="sec-rp-add-btn" onclick="SecRP.addItem(' + folderId + ')" style="margin-top:4px">'
                + '<span class="plus-icon">＋</span> アイテム追加</button>';
            container.innerHTML = html;
        },

        _openItemModal: function (folderId, existing) {
            var editing = !!existing;
            var bd = document.createElement('div');
            bd.className = 'sec-rp-modal-backdrop';
            bd.innerHTML = '<div class="sec-rp-modal">'
                + '<h4>' + (editing ? 'アイテム編集' : 'アイテム追加') + '</h4>'
                + '<input id="secItemTitle" placeholder="タイトル" value="' + escHtml(existing ? existing.title : '') + '">'
                + '<textarea id="secItemContent" placeholder="内容（AIが参照するテキスト）">' + escHtml(existing ? existing.content : '') + '</textarea>'
                + '<div class="sec-rp-modal-btns">'
                + '<button class="sec-rp-btn-cancel" id="secItemCancel">キャンセル</button>'
                + '<button class="sec-rp-btn-primary" id="secItemSave">保存</button>'
                + '</div></div>';
            document.body.appendChild(bd);

            qs('#secItemCancel', bd).onclick = function () { bd.remove(); };
            bd.addEventListener('click', function (e) { if (e.target === bd) bd.remove(); });

            qs('#secItemSave', bd).onclick = function () {
                var title = qs('#secItemTitle', bd).value.trim();
                var content = qs('#secItemContent', bd).value.trim();
                if (!title && !content) { alert('タイトルまたは内容を入力してください'); return; }

                var payload;
                if (editing) {
                    payload = { action: 'update_item', item_id: existing.id, title: title, content: content };
                } else {
                    payload = { action: 'create_item', folder_id: folderId, title: title, content: content };
                }
                apiPost(API_JUDGMENT, payload).then(function (res) {
                    if (res.success) {
                        bd.remove();
                        SecRP.loadItems(folderId);
                    } else {
                        alert(res.message || 'エラー');
                    }
                });
            };

            setTimeout(function () { qs('#secItemTitle', bd).focus(); }, 50);
        },

        _renderMemory: function (summary) {
            var el = qs('#secConvMemory');
            if (!el) return;
            if (!summary || summary.trim() === '') {
                el.innerHTML = '<p class="sec-rp-muted">自動分析がまだ実行されていません。</p>';
                return;
            }
            var parsed;
            try { parsed = JSON.parse(summary); } catch (e) { parsed = null; }

            if (!parsed || typeof parsed !== 'object') {
                el.textContent = summary;
                return;
            }

            var html = '';
            var labelMap = {
                summary: '要約',
                tone: 'トーン',
                first_person: '一人称',
                decision_style: '判断スタイル',
                habits: '口癖・決まり文句',
                emojis: 'よく使う絵文字',
                sentence_endings: '語尾パターン',
                per_person_style: '相手別スタイル',
                situation_phrases: '状況別フレーズ'
            };

            Object.keys(labelMap).forEach(function (key) {
                if (!parsed[key]) return;
                html += '<div class="sec-rp-memory-label">' + labelMap[key] + '</div>';
                var val = parsed[key];
                if (Array.isArray(val)) {
                    html += '<div>' + val.map(function (v) { return escHtml(String(v)); }).join('、') + '</div>';
                } else {
                    html += '<div>' + escHtml(String(val)) + '</div>';
                }
            });
            el.innerHTML = html || '<p class="sec-rp-muted">分析データなし</p>';
        },

        _renderStats: function (stats) {
            var total = stats.total_sent || 0;
            var rate = stats.modification_rate;
            var eligible = stats.auto_reply_eligible;

            qs('#secStatTotal').textContent = total;
            qs('#secStatRate').textContent = (rate !== undefined && rate !== null) ? rate + '%' : '-';
            qs('#secStatEligible').textContent = eligible ? '✅ 可能' : '❌ 未達';

            var label = qs('#secAutoReplyLabel');
            if (label) label.style.display = eligible ? '' : 'none';
        }
    };

})();
