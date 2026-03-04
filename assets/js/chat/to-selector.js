/**
 * TO（宛先指定）機能モジュール
 * 
 * メッセージの宛先を指定する機能
 * 
 * 使用例:
 * Chat.toSelector.toggle(event);
 * Chat.toSelector.select(memberId);
 * Chat.toSelector.getSelected();
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 内部状態
    let members = [];
    let selectedMembers = [];
    let popupJustOpened = false;
    let currentUserId = 0;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.members) members = options.members;
        if (options.currentUserId) currentUserId = options.currentUserId;
        
        // ドキュメントクリックで閉じる
        document.addEventListener('click', function(e) {
            if (popupJustOpened) return;
            
            const popup = document.getElementById('toSelectorPopup');
            const btn = document.querySelector('.toolbar-btn.to-btn');
            
            if (popup && popup.style.display !== 'none') {
                if (!popup.contains(e.target) && (!btn || !btn.contains(e.target))) {
                    close();
                }
            }
        });
        
        console.log('[ToSelector] Initialized');
    }
    
    /**
     * メンバーリストを設定
     * @param {Array} memberList - メンバー配列
     */
    function setMembers(memberList) {
        members = memberList || [];
        renderList();
    }
    
    /**
     * 現在のユーザーIDを設定
     * @param {number} id - ユーザーID
     */
    function setCurrentUserId(id) {
        currentUserId = id;
    }
    
    /**
     * ポップアップをトグル
     * @param {Event} event - イベント
     */
    function toggle(event) {
        if (event) {
            event.stopPropagation();
            event.preventDefault();
        }
        
        const popup = document.getElementById('toSelectorPopup');
        const btn = document.querySelector('.toolbar-btn.to-btn');
        
        if (!popup || !btn) {
            console.error('[ToSelector] Elements not found');
            return;
        }
        
        const isHidden = !popup.style.display || popup.style.display === 'none';
        
        if (isHidden) {
            open(btn);
        } else {
            close();
        }
    }
    
    /**
     * ポップアップを開く
     * @param {HTMLElement} btn - ボタン要素
     */
    function open(btn) {
        const popup = document.getElementById('toSelectorPopup');
        if (!popup) return;
        
        // ポップアップをbodyに移動
        if (popup.parentElement !== document.body) {
            document.body.appendChild(popup);
        }
        
        // リストを描画
        renderList();
        
        // 位置を計算
        const btnRect = btn.getBoundingClientRect();
        
        // スタイルを設定
        popup.style.cssText = [
            'position: fixed !important',
            'z-index: 2147483647 !important',
            'background: #ffffff !important',
            'border: 3px solid #667eea !important',
            'border-radius: 12px !important',
            'box-shadow: 0 10px 50px rgba(0,0,0,0.3) !important',
            'display: block !important',
            'min-width: 250px !important',
            'max-width: 300px !important',
            'max-height: 350px !important',
            'overflow: hidden !important'
        ].join('; ');
        
        popup.style.left = btnRect.left + 'px';
        popup.style.bottom = (window.innerHeight - btnRect.top + 10) + 'px';
        
        btn.classList.add('active');
        
        // 開いた直後フラグ
        popupJustOpened = true;
        setTimeout(() => { popupJustOpened = false; }, 200);
    }
    
    /**
     * ポップアップを閉じる
     */
    function close() {
        if (popupJustOpened) return;
        
        const popup = document.getElementById('toSelectorPopup');
        const btn = document.querySelector('.toolbar-btn.to-btn');
        
        if (popup) popup.style.display = 'none';
        if (btn) btn.classList.remove('active');
    }
    
    /**
     * 入力欄のカーソル位置にテキストを挿入（Chatwork風に文中にToを表示）
     */
    function insertToTextAtCursor(text) {
        const input = document.getElementById('messageInput');
        if (!input) return;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const val = input.value;
        input.value = val.slice(0, start) + text + val.slice(end);
        input.selectionStart = input.selectionEnd = start + text.length;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * メンバーを選択/解除
     * @param {number|string} memberId - メンバーID（'all'も可）
     */
    function select(memberId) {
        if (memberId === 'all') {
            if (selectedMembers.includes('all')) {
                selectedMembers = [];
            } else {
                selectedMembers = ['all'];
                insertToTextAtCursor('[To:all]全員\n');
            }
        } else {
            // 'all'を選択していたら解除
            const allIdx = selectedMembers.indexOf('all');
            if (allIdx !== -1) {
                selectedMembers.splice(allIdx, 1);
            }
            
            const idx = selectedMembers.indexOf(memberId);
            if (idx !== -1) {
                selectedMembers.splice(idx, 1);
            } else {
                selectedMembers.push(memberId);
                const member = members.find(function(m) { return m.id == memberId; });
                const name = member ? (member.display_name || member.name || '') : '';
                insertToTextAtCursor('[To:' + memberId + ']' + name + 'さん\n');
            }
        }
        
        if (typeof window !== 'undefined') {
            window.chatSelectedToIds = getSelected();
        }
        renderList();
        updateIndicator();
    }
    
    /**
     * メンバーを解除
     * @param {number|string} memberId - メンバーID
     */
    function remove(memberId) {
        const idx = selectedMembers.indexOf(memberId);
        if (idx !== -1) {
            selectedMembers.splice(idx, 1);
        }
        renderList();
        updateIndicator();
    }
    
    /**
     * 選択をクリア
     */
    function clear() {
        selectedMembers = [];
        renderList();
        updateIndicator();
        close();
    }
    
    /**
     * 選択されたメンバーを取得
     * @returns {Array} 選択されたメンバーID
     */
    function getSelected() {
        return [...selectedMembers];
    }
    
    /**
     * 選択を設定
     * @param {Array} ids - メンバーID配列
     */
    function setSelected(ids) {
        selectedMembers = ids || [];
        renderList();
        updateIndicator();
    }
    
    /**
     * リストを描画
     */
    function renderList() {
        const list = document.getElementById('toSelectorList');
        if (!list) return;
        
        let html = '';
        
        // 全員オプション
        const isAllSelected = selectedMembers.includes('all');
        html += `
            <div class="to-selector-item all-item ${isAllSelected ? 'selected' : ''}" 
                 onclick="Chat.toSelector.select('all')">
                <div class="avatar">👥</div>
                <span class="name">全員 (All)</span>
                <span class="check">✓</span>
            </div>
        `;
        
        // メンバー一覧
        if (members.length === 0) {
            html += '<div style="padding: 15px; text-align: center; color: #999;">メンバーを読み込み中...</div>';
        } else {
            members.forEach(member => {
                if (member.id === currentUserId) return;
                
                const isSelected = selectedMembers.includes(member.id);
                const initial = (member.display_name || '?').charAt(0).toUpperCase();
                const escapedName = Chat.utils ? Chat.utils.escapeHtml(member.display_name || '不明') : (member.display_name || '不明');
                
                html += `
                    <div class="to-selector-item ${isSelected ? 'selected' : ''}" 
                         onclick="Chat.toSelector.select(${member.id})">
                        <div class="avatar">${initial}</div>
                        <span class="name">${escapedName}</span>
                        <span class="check">✓</span>
                    </div>
                `;
            });
        }
        
        list.innerHTML = html;
    }
    
    /**
     * インジケーターを更新
     */
    function updateIndicator() {
        const indicator = document.getElementById('toIndicator');
        const btn = document.querySelector('.toolbar-btn.to-btn');
        
        if (!indicator) return;
        
        if (selectedMembers.length === 0) {
            indicator.style.display = 'none';
            if (btn) btn.classList.remove('has-selection');
        } else {
            indicator.style.display = 'inline-flex';
            if (btn) btn.classList.add('has-selection');
            
            let html = '';
            
            if (selectedMembers.includes('all')) {
                html = `<span class="to-tag">全員<button class="to-tag-remove" onclick="event.stopPropagation(); Chat.toSelector.remove('all')">×</button></span>`;
            } else {
                selectedMembers.forEach(memberId => {
                    const member = members.find(m => m.id === memberId);
                    const name = member ? member.display_name : '不明';
                    const escapedName = Chat.utils ? Chat.utils.escapeHtml(name) : name;
                    html += `<span class="to-tag">${escapedName}<button class="to-tag-remove" onclick="event.stopPropagation(); Chat.toSelector.remove(${memberId})">×</button></span>`;
                });
            }
            
            indicator.innerHTML = html;
        }
    }
    
    // 公開API
    Chat.toSelector = {
        init,
        setMembers,
        setCurrentUserId,
        toggle,
        open,
        close,
        select,
        remove,
        clear,
        getSelected,
        setSelected,
        renderList,
        updateIndicator
    };
    
    // グローバル関数との互換性（後方互換）
    global.toggleToSelector = toggle;
    global.closeToSelector = close;
    global.selectToMember = select;
    global.removeToMember = remove;
    global.clearToSelection = clear;
    global.getSelectedToMembers = getSelected;
    global.renderToSelectorList = renderList;
    global.updateToIndicator = updateIndicator;
    
    // レガシー変数との互換性
    Object.defineProperty(global, '_toSelectorMembers', {
        get: () => members,
        set: (val) => { members = val; }
    });
    Object.defineProperty(global, '_toSelectedMembers', {
        get: () => selectedMembers,
        set: (val) => { selectedMembers = val; }
    });
    Object.defineProperty(global, '_currentUserId', {
        get: () => currentUserId,
        set: (val) => { currentUserId = val; }
    });
    
})(typeof window !== 'undefined' ? window : this);
