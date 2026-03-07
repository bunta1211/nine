/**
 * Social9 モバイル用JavaScript
 * PC版の機能はそのままで、モバイル専用の操作を追加
 */

(function() {
    'use strict';

    // モバイル判定
    const isMobile = () => window.innerWidth <= 768;

    // DOM要素
    let leftPanel, rightPanel, mobileOverlay;
    let touchStartX = 0;
    let touchStartY = 0;
    let touchMoveX = 0;
    let isSwiping = false;

    // 初期化
    function initMobile() {
        leftPanel = document.getElementById('leftPanel');
        rightPanel = document.getElementById('rightPanel');
        
        // 携帯: 三本線(≡)ボタンは使わないので確実に非表示（CSSより優先するためインラインで指定）
        if (isMobile()) {
            const threeLineBtn = document.getElementById('toggleTaskMemoBtn');
            if (threeLineBtn) {
                threeLineBtn.style.setProperty('display', 'none', 'important');
                threeLineBtn.style.setProperty('visibility', 'hidden', 'important');
                threeLineBtn.setAttribute('aria-hidden', 'true');
            }
        }
        
        // オーバーレイを作成
        createOverlay();
        
        // モバイルメニューボタンを追加
        addMobileMenuButtons();
        
        // スワイプイベントを設定
        setupSwipeGestures();
        
        // リサイズ時の処理
        window.addEventListener('resize', handleResize);
        
        console.log('Mobile features initialized');
    }

    /* setupTopPanelTapToReveal は廃止（設定・FAB等は常時表示） */

    // オーバーレイ作成（既にHTMLにあれば参照だけ取得）
    function createOverlay() {
        const existing = document.querySelector('.mobile-overlay') || document.getElementById('mobileOverlay');
        if (existing) {
            mobileOverlay = existing;
            if (!mobileOverlay.hasAttribute('data-close-bound')) {
                mobileOverlay.setAttribute('data-close-bound', '1');
                mobileOverlay.addEventListener('click', closeAllPanels);
            }
            return;
        }
        mobileOverlay = document.createElement('div');
        mobileOverlay.className = 'mobile-overlay';
        mobileOverlay.addEventListener('click', closeAllPanels);
        document.body.appendChild(mobileOverlay);
    }

    function addMobileMenuButtons() {
        const chatHeaderActions = document.querySelector('.chat-header-actions');
        
        if (chatHeaderActions && !document.querySelector('.mobile-settings-btn')) {
            const settingsBtn = document.createElement('button');
            settingsBtn.className = 'mobile-settings-btn';
            settingsBtn.innerHTML = '⚙️';
            settingsBtn.setAttribute('aria-label', '設定');
            settingsBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleRightPanel();
            });
            chatHeaderActions.appendChild(settingsBtn);
        }

        addPanelCloseButton(leftPanel || document.getElementById('leftPanel'), 'left');
        addPanelCloseButton(rightPanel || document.getElementById('rightPanel'), 'right');
    }

    function addPanelCloseButton(panel, side) {
        if (!panel || panel.querySelector('.mobile-panel-close-btn')) return;
        var btn = document.createElement('button');
        btn.className = 'mobile-panel-close-btn';
        btn.setAttribute('aria-label', 'パネルを閉じる');
        btn.textContent = '×';
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            closeAllPanels();
        });
        panel.insertBefore(btn, panel.firstChild);
    }

    // 左パネルのトグル（固定オーバーレイで開閉）
    function toggleLeftPanel() {
        if (!isMobile()) return;
        const lp = leftPanel || document.getElementById('leftPanel');
        if (!lp) return;
        if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
        if (lp.classList.contains('mobile-open')) {
            closeAllPanels();
        } else {
            closeAllPanels();
            lp.classList.add('mobile-open');
            const overlay = mobileOverlay || document.querySelector('.mobile-overlay') || document.getElementById('mobileOverlay');
            if (overlay) { overlay.classList.add('show'); overlay.style.display = 'block'; overlay.style.opacity = '1'; }
            document.body.classList.add('mobile-panel-open');
            document.body.style.overflow = 'hidden';
        }
    }

    function toggleRightPanel() {
        if (!isMobile()) return;
        const rp = rightPanel || document.getElementById('rightPanel');
        if (!rp) return;
        if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
        if (rp.classList.contains('mobile-open')) {
            closeAllPanels();
        } else {
            closeAllPanels();
            rp.classList.add('mobile-open');
            const overlay = mobileOverlay || document.querySelector('.mobile-overlay') || document.getElementById('mobileOverlay');
            if (overlay) { overlay.classList.add('show', 'active'); overlay.style.display = 'block'; overlay.style.opacity = '1'; }
            document.body.classList.add('mobile-panel-open');
            document.body.style.overflow = 'hidden';
        }
    }

    // すべてのパネルを閉じる
    function closeAllPanels() {
        const left = leftPanel || document.getElementById('leftPanel');
        const right = rightPanel || document.getElementById('rightPanel');
        const overlay = mobileOverlay || document.querySelector('.mobile-overlay') || document.getElementById('mobileOverlay');
        if (left) {
            left.classList.remove('mobile-open');
        }
        if (right) {
            right.classList.remove('mobile-open');
        }
        if (overlay) { overlay.classList.remove('show', 'active'); overlay.style.display = 'none'; overlay.style.opacity = '0'; }
        document.body.classList.remove('mobile-panel-open');
        document.body.style.overflow = '';
        showCenterPanel();
    }

    // ⇒タップ: 右パネルを閉じる／開いていなければ左パネルを開く
    function handleMobileArrowRight() {
        if (!isMobile()) return;
        if (rightPanel && rightPanel.classList.contains('mobile-open')) {
            closeAllPanels();
        } else {
            toggleLeftPanel();
        }
    }

    // ⇐タップ: 左パネルを閉じる／開いていなければ右パネルを開く
    function handleMobileArrowLeft() {
        if (!isMobile()) return;
        if (leftPanel && leftPanel.classList.contains('mobile-open')) {
            closeAllPanels();
        } else {
            toggleRightPanel();
        }
    }

    // 中央パネルを非表示（チャットヘッダー、メッセージエリア、入力エリアすべて）
    function hideCenterPanel() {
        var vaultView = document.getElementById('storageVaultView');
        var isVaultOpen = vaultView && vaultView.style.display !== 'none' && vaultView.style.display !== '';
        if (isVaultOpen) return;

        const centerPanel = document.querySelector('.center-panel');
        const chatHeader = document.querySelector('.chat-header');
        const messagesArea = document.querySelector('.messages-area');
        const inputArea = document.querySelector('.input-area');
        const memberPopup = document.querySelector('.member-popup');
        const memberPopupOverlay = document.querySelector('.member-popup-overlay');
        
        if (centerPanel) {
            centerPanel.style.display = 'none';
            centerPanel.style.visibility = 'hidden';
        }
        if (chatHeader) {
            chatHeader.style.display = 'none';
        }
        if (messagesArea) {
            messagesArea.style.display = 'none';
        }
        if (inputArea) {
            inputArea.style.display = 'none';
        }
        if (memberPopup) {
            memberPopup.style.display = 'none';
        }
        if (memberPopupOverlay) {
            memberPopupOverlay.style.display = 'none';
        }
    }

    // 中央パネルを表示
    function showCenterPanel() {
        const centerPanel = document.querySelector('.center-panel');
        const chatHeader = document.querySelector('.chat-header');
        const messagesArea = document.querySelector('.messages-area');
        const inputArea = document.querySelector('.input-area');
        
        if (centerPanel) {
            centerPanel.style.display = '';
            centerPanel.style.visibility = '';
        }
        if (chatHeader) {
            chatHeader.style.display = '';
        }
        var vaultView = document.getElementById('storageVaultView');
        var isVaultOpen = vaultView && vaultView.style.display !== 'none' && vaultView.style.display !== '';
        if (!isVaultOpen) {
            if (messagesArea) {
                messagesArea.style.display = '';
            }
            if (inputArea) {
                inputArea.style.display = '';
            }
        }
    }

    // 右パネルを閉じる（グローバル関数として公開）
    window.closeMobileRightPanel = function() {
        if (rightPanel) rightPanel.classList.remove('mobile-open');
        if (mobileOverlay) mobileOverlay.classList.remove('show');
        document.body.style.overflow = '';
    };

    // 左パネルを閉じる（scripts.php 等から呼ばれる）
    window.closeMobileLeftPanel = function() {
        closeAllPanels();
    };

    // スワイプジェスチャーの設定
    function setupSwipeGestures() {
        const centerPanel = document.querySelector('.center-panel');
        if (!centerPanel) return;

        centerPanel.addEventListener('touchstart', handleTouchStart, { passive: true });
        centerPanel.addEventListener('touchmove', handleTouchMove, { passive: false });
        centerPanel.addEventListener('touchend', handleTouchEnd, { passive: true });
    }

    function handleTouchStart(e) {
        if (!isMobile()) return;
        
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        isSwiping = false;
    }

    function handleTouchMove(e) {
        if (!isMobile()) return;
        
        touchMoveX = e.touches[0].clientX;
        const touchMoveY = e.touches[0].clientY;
        
        const diffX = touchMoveX - touchStartX;
        const diffY = touchMoveY - touchStartY;
        
        // 水平方向の動きが垂直より大きい場合のみスワイプ
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 30) {
            isSwiping = true;
            // 縦スクロールを防止
            // e.preventDefault(); // 入力欄のスクロールに影響するためコメントアウト
        }
    }

    function handleTouchEnd(e) {
        if (!isMobile() || !isSwiping) return;
        
        const diffX = touchMoveX - touchStartX;
        const threshold = 80;
        const vw = window.innerWidth;
        const lp = leftPanel || document.getElementById('leftPanel');
        const rp = rightPanel || document.getElementById('rightPanel');

        if (diffX > threshold && touchStartX < 50) {
            toggleLeftPanel();
        } else if (diffX < -threshold && touchStartX > vw - 50) {
            toggleRightPanel();
        } else if (diffX < -threshold && lp && lp.classList.contains('mobile-open')) {
            closeAllPanels();
        } else if (diffX > threshold && rp && rp.classList.contains('mobile-open')) {
            closeAllPanels();
        }
        isSwiping = false;
    }

    // リサイズ時の処理
    function handleResize() {
        if (!isMobile()) {
            closeAllPanels();
        }
    }

    // グループ選択時にパネルを閉じる（中央へスクロール＋body.mobile-panel-open を外してログを表示）。イベント委譲で動的追加の conv-item にも対応
    function setupConversationClick() {
        const convList = document.getElementById('conversationList');
        const root = convList || document.body;
        root.addEventListener('click', function(e) {
            if (!isMobile()) return;
            const item = e.target && e.target.closest ? e.target.closest('.conv-item') : null;
            if (!item) return;
            if (typeof window.closeMobileAllPanels === 'function') {
                window.closeMobileAllPanels();
            } else {
                window.closeMobileLeftPanel();
            }
        });
    }

    // DOMContentLoaded時に初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initMobile();
            setupConversationClick();
        });
    } else {
        initMobile();
        setupConversationClick();
    }

    // ESCキーでパネルを閉じる
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isMobile()) {
            closeAllPanels();
        }
    });

    /**
     * グループ詳細パネルへ移動（左パネルから「グループ管理」押下時用）
     * 左パネルを閉じてから右パネル（詳細）を開く／表示する
     */
    function openGroupDetailsPanel() {
        if (!isMobile()) {
            if (typeof window.toggleRightPanel === 'function') window.toggleRightPanel();
            return;
        }
        closeAllPanels();
        toggleRightPanel();
    }

    // モバイル用のみグローバルに公開。toggleRightPanel は scripts.php の定義をそのまま使う（PCで⇒収納が動くように上書きしない）
    window.toggleMobileLeftPanel = toggleLeftPanel;
    window.toggleMobileRightPanel = toggleRightPanel;
    window.toggleMobileRightPanelFn = toggleRightPanel;
    window.closeMobileAllPanels = closeAllPanels;
    window.openGroupDetailsPanel = openGroupDetailsPanel;

    // ========================================
    // Phase 3: スワイプジェスチャー、タッチ操作
    // ========================================

    // プルダウンで更新
    let pullStartY = 0;
    let isPulling = false;
    let pullIndicator = null;

    function setupPullToRefresh() {
        const messagesArea = document.querySelector('.messages-area');
        if (!messagesArea) return;

        // プルインジケーター作成
        pullIndicator = document.createElement('div');
        pullIndicator.className = 'pull-to-refresh-indicator';
        pullIndicator.innerHTML = '↓ 引っ張って更新';
        pullIndicator.style.cssText = `
            position: absolute;
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            padding: 10px 20px;
            background: var(--primary, #6b8e23);
            color: white;
            border-radius: 20px;
            font-size: 13px;
            opacity: 0;
            transition: opacity 0.2s, top 0.2s;
            z-index: 100;
        `;
        messagesArea.style.position = 'relative';
        messagesArea.insertBefore(pullIndicator, messagesArea.firstChild);

        messagesArea.addEventListener('touchstart', (e) => {
            if (messagesArea.scrollTop <= 0) {
                pullStartY = e.touches[0].clientY;
                isPulling = true;
            }
        }, { passive: true });

        messagesArea.addEventListener('touchmove', (e) => {
            if (!isPulling) return;
            
            const currentY = e.touches[0].clientY;
            const diff = currentY - pullStartY;
            
            if (diff > 0 && diff < 150) {
                pullIndicator.style.top = (diff - 50) + 'px';
                pullIndicator.style.opacity = Math.min(diff / 80, 1);
                
                if (diff > 80) {
                    pullIndicator.innerHTML = '↑ 離して更新';
                } else {
                    pullIndicator.innerHTML = '↓ 引っ張って更新';
                }
            }
        }, { passive: true });

        messagesArea.addEventListener('touchend', (e) => {
            if (!isPulling) return;
            
            const pullDistance = parseInt(pullIndicator.style.top) + 50;
            
            if (pullDistance > 80) {
                pullIndicator.innerHTML = '更新中...';
                pullIndicator.style.top = '10px';
                
                // ページをリロード
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                pullIndicator.style.top = '-50px';
                pullIndicator.style.opacity = '0';
            }
            
            isPulling = false;
        }, { passive: true });
    }

    // メッセージのタップでオプション表示（LINE風）
    let lineMenuOverlay = null;
    let lineMenuSheet = null;
    let lastTapTime = 0;

    function setupMessageTap() {
        const messagesArea = document.querySelector('.messages-area');
        if (!messagesArea) return;

        // LINE風メニューを作成
        createLineStyleMenu();

        // タップでメニュー表示
        messagesArea.addEventListener('click', (e) => {
            if (!isMobile()) return;
            
            // リンクやボタンのクリックは除外
            if (e.target.closest('a, button, .reaction-badge')) return;
            
            const messageCard = e.target.closest('.message-card');
            if (!messageCard || messageCard.classList.contains('system-message')) return;

            // ダブルタップ防止
            const now = Date.now();
            if (now - lastTapTime < 300) return;
            lastTapTime = now;

            e.preventDefault();
            e.stopPropagation();
            
            showLineStyleMenu(messageCard);
        });
    }
    
    // 互換性のため旧関数名も残す
    function setupLongPress() {
        setupMessageTap();
    }

    // LINE風メニューを作成
    function createLineStyleMenu() {
        if (document.getElementById('lineMenuOverlay')) return;

        // オーバーレイ
        lineMenuOverlay = document.createElement('div');
        lineMenuOverlay.id = 'lineMenuOverlay';
        lineMenuOverlay.className = 'line-menu-overlay';
        lineMenuOverlay.addEventListener('click', hideLineStyleMenu);

        // ボトムシート
        lineMenuSheet = document.createElement('div');
        lineMenuSheet.id = 'lineMenuSheet';
        lineMenuSheet.className = 'line-menu-sheet';

        document.body.appendChild(lineMenuOverlay);
        document.body.appendChild(lineMenuSheet);
    }

    // LINE風メニューを表示
    function showLineStyleMenu(messageCard) {
        if (!messageCard || !lineMenuSheet) return;

        // バイブレーション
        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        const messageId = messageCard.dataset.messageId;
        const messageContent = messageCard.dataset.content || messageCard.querySelector('.message-content')?.textContent || '';
        const isOwn = messageCard.classList.contains('own');

        // メニュー項目を生成
        let menuHTML = `
            <div class="line-menu-handle"></div>
            <div class="line-menu-preview">
                <div class="line-menu-preview-text">${escapeHtmlForMenu(messageContent.substring(0, 50))}${messageContent.length > 50 ? '...' : ''}</div>
            </div>
            <div class="line-menu-grid">
                <button class="line-menu-item" onclick="lineMenuAction('copy', '${messageId}')">
                    <span class="line-menu-icon">📋</span>
                    <span class="line-menu-label">コピー</span>
                </button>
                <button class="line-menu-item" onclick="lineMenuAction('reply', '${messageId}')">
                    <span class="line-menu-icon">↩️</span>
                    <span class="line-menu-label">リプライ</span>
                </button>
                <button class="line-menu-item" onclick="lineMenuAction('react', '${messageId}')">
                    <span class="line-menu-icon">😊</span>
                    <span class="line-menu-label">リアクション</span>
                </button>
                <button class="line-menu-item" onclick="lineMenuAction('memo', '${messageId}')">
                    <span class="line-menu-icon"><img src="assets/icons/line/memo.svg" alt="" class="icon-line" width="22" height="22"></span>
                    <span class="line-menu-label">メモ</span>
                </button>
                <button class="line-menu-item" onclick="lineMenuAction('wish', '${messageId}')">
                    <span class="line-menu-icon"><img src="assets/icons/line/clipboard.svg" alt="" class="icon-line" width="22" height="22"></span>
                    <span class="line-menu-label">${typeof window.__TASK_LABEL !== 'undefined' ? window.__TASK_LABEL : 'タスク'}</span>
                </button>
                <button class="line-menu-item" onclick="lineMenuAction('forward', '${messageId}')">
                    <span class="line-menu-icon"><img src="assets/icons/line/arrow-up-right.svg" alt="" class="icon-line" width="22" height="22"></span>
                    <span class="line-menu-label">転送</span>
                </button>
        `;

        // 自分のメッセージの場合は編集・削除を追加
        if (isOwn) {
            menuHTML += `
                <button class="line-menu-item" onclick="lineMenuAction('edit', '${messageId}')">
                    <span class="line-menu-icon"><img src="assets/icons/line/pencil.svg" alt="" class="icon-line" width="22" height="22"></span>
                    <span class="line-menu-label">編集</span>
                </button>
                <button class="line-menu-item line-menu-danger" onclick="lineMenuAction('delete', '${messageId}')">
                    <span class="line-menu-icon"><img src="assets/icons/line/trash.svg" alt="" class="icon-line" width="22" height="22"></span>
                    <span class="line-menu-label">削除</span>
                </button>
            `;
        }

        menuHTML += `
            </div>
            <button class="line-menu-cancel" onclick="hideLineStyleMenu()">キャンセル</button>
        `;

        lineMenuSheet.innerHTML = menuHTML;
        
        // 表示
        lineMenuOverlay.classList.add('show');
        lineMenuSheet.classList.add('show');
        document.body.style.overflow = 'hidden';

        // タップしたメッセージがボトムシートの上に見えるようスクロール（最新メッセージが隠れないように）
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                scrollMessageAboveSheet(messageCard);
            });
        });
    }

    // メッセージがボトムシートに隠れないよう、メッセージエリアをスクロール
    function scrollMessageAboveSheet(messageCard) {
        const messagesArea = document.querySelector('.messages-area');
        if (!messagesArea || !messageCard || !lineMenuSheet) return;
        const sheetHeight = lineMenuSheet.offsetHeight || 180;
        const msgRect = messageCard.getBoundingClientRect();
        const areaRect = messagesArea.getBoundingClientRect();
        const maxVisibleBottom = areaRect.bottom - sheetHeight;
        if (msgRect.bottom > maxVisibleBottom) {
            messagesArea.scrollTop += (msgRect.bottom - maxVisibleBottom);
        } else if (msgRect.top < areaRect.top) {
            messagesArea.scrollTop -= (areaRect.top - msgRect.top);
        }
    }

    // LINE風メニューを非表示
    function hideLineStyleMenu() {
        if (lineMenuOverlay) lineMenuOverlay.classList.remove('show');
        if (lineMenuSheet) lineMenuSheet.classList.remove('show');
        document.body.style.overflow = '';
    }
    window.hideLineStyleMenu = hideLineStyleMenu;

    // 編集時など：チャット入力欄を確実に表示（閉じていた場合も開く）
    function ensureMobileInputAreaVisible() {
        const inputArea = document.getElementById('inputArea') || document.querySelector('.input-area');
        if (!inputArea) return;
        inputArea.classList.remove('hidden');
        inputArea.style.transform = 'translateY(0)';
        inputArea.style.opacity = '1';
        inputArea.style.pointerEvents = '';
        try {
            localStorage.setItem('inputAreaHidden', '0');
        } catch (e) {}
        const showBtn = document.getElementById('inputShowBtn');
        if (showBtn) showBtn.style.display = 'none';
        try { if (typeof isInputHidden !== 'undefined') isInputHidden = false; } catch (e) {}
        if (typeof window.showInputArea === 'function') {
            window.showInputArea();
        }
    }

    // メニューアクション実行
    window.lineMenuAction = function(action, messageId) {
        hideLineStyleMenu();

        const messageCard = document.querySelector(`.message-card[data-message-id="${messageId}"]`);
        if (!messageCard) return;

        switch (action) {
            case 'copy':
                const content = messageCard.dataset.content || messageCard.querySelector('.message-content')?.textContent || '';
                navigator.clipboard.writeText(content).then(() => {
                    showToast('コピーしました');
                }).catch(() => {
                    // フォールバック
                    const textarea = document.createElement('textarea');
                    textarea.value = content;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    showToast('コピーしました');
                });
                break;

            case 'reply':
                ensureMobileInputAreaVisible();
                if (typeof replyToMessage === 'function') {
                    replyToMessage(messageId);
                }
                break;

            case 'react':
                showReactionPicker(messageId);
                break;

            case 'memo':
                if (typeof saveToMemo === 'function') {
                    saveToMemo(messageId);
                } else {
                    showToast('メモに保存しました');
                }
                break;

            case 'wish':
                if (typeof saveToWish === 'function') {
                    saveToWish(messageId);
                } else {
                    showToast(typeof window.__TASK_ADDED_MSG !== 'undefined' ? window.__TASK_ADDED_MSG : 'タスクに追加しました');
                }
                break;

            case 'forward':
                showToast('転送機能は準備中です');
                break;

            case 'edit':
                ensureMobileInputAreaVisible();
                if (typeof editMessage === 'function') {
                    editMessage(messageId);
                }
                break;

            case 'delete':
                if (typeof deleteMessage === 'function') {
                    deleteMessage(messageId);
                }
                break;
        }
    };

    // リアクションピッカーを表示
    function showReactionPicker(messageId) {
        const reactions = ['❤️', '👍', '😊', '😂', '😢', '😮', '🎉', '🙏', '🙇'];
        
        const picker = document.createElement('div');
        picker.className = 'line-reaction-picker';
        picker.innerHTML = reactions.map(r => 
            `<button class="line-reaction-btn" onclick="addReactionFromPicker('${messageId}', '${r}')">${r}</button>`
        ).join('');
        
        lineMenuSheet.innerHTML = `
            <div class="line-menu-handle"></div>
            <div class="line-reaction-picker-container">
                <div class="line-reaction-title">リアクションを選択</div>
                ${picker.outerHTML}
            </div>
            <button class="line-menu-cancel" onclick="hideLineStyleMenu()">キャンセル</button>
        `;
        
        lineMenuOverlay.classList.add('show');
        lineMenuSheet.classList.add('show');
    }

    window.addReactionFromPicker = function(messageId, emoji) {
        hideLineStyleMenu();
        if (typeof addReaction === 'function') {
            addReaction(messageId, emoji);
        }
        showToast(emoji + ' を追加しました');
    };

    // トースト通知
    function showToast(message) {
        let toast = document.getElementById('mobileToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'mobileToast';
            toast.className = 'mobile-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2000);
    }

    // HTMLエスケープ
    function escapeHtmlForMenu(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ダブルタップでリアクション
    let doubleTapTime = 0;
    let doubleTapTarget = null;

    function setupDoubleTap() {
        const messagesArea = document.querySelector('.messages-area');
        if (!messagesArea) return;

        messagesArea.addEventListener('touchend', (e) => {
            const messageCard = e.target.closest('.message-card');
            if (!messageCard) return;

            const currentTime = new Date().getTime();
            const tapGap = currentTime - doubleTapTime;

            if (tapGap < 300 && doubleTapTarget === messageCard) {
                // ダブルタップ検出
                e.preventDefault();
                addQuickReaction(messageCard);
                doubleTapTime = 0;
                doubleTapTarget = null;
            } else {
                doubleTapTime = currentTime;
                doubleTapTarget = messageCard;
            }
        });
    }

    function addQuickReaction(messageCard) {
        const messageId = messageCard.dataset.messageId;
        if (!messageId) return;

        // バイブレーション
        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        // ハートリアクションを追加（既存の関数を使用）
        if (typeof addReaction === 'function') {
            addReaction(messageId, '❤️');
        }

        // ビジュアルフィードバック
        const heart = document.createElement('div');
        heart.innerHTML = '❤️';
        heart.style.cssText = `
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            font-size: 48px;
            animation: heartPop 0.6s ease-out forwards;
            pointer-events: none;
            z-index: 1000;
        `;
        messageCard.style.position = 'relative';
        messageCard.appendChild(heart);

        setTimeout(() => heart.remove(), 600);
    }

    // ハートアニメーションのCSS追加
    function addMobileAnimations() {
        if (document.getElementById('mobile-animations')) return;
        
        const style = document.createElement('style');
        style.id = 'mobile-animations';
        style.textContent = `
            @keyframes heartPop {
                0% { transform: translate(-50%, -50%) scale(0); opacity: 1; }
                50% { transform: translate(-50%, -50%) scale(1.2); opacity: 1; }
                100% { transform: translate(-50%, -50%) scale(1); opacity: 0; }
            }
            
            @keyframes slideUp {
                from { transform: translateY(100%); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }

    /** メッセージエリアを最下部へスクロール（入力欄の上にメッセージが来るように） */
    function scrollMessagesToBottom() {
        const messagesArea = document.querySelector('.messages-area');
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }
    }

    /** 表示中のビューポート高さをCSS変数に反映（キーボード表示でメッセージエリア高さを縮める） */
    function updateVisualViewportHeight() {
        const h = window.visualViewport ? window.visualViewport.height : window.innerHeight;
        document.documentElement.style.setProperty('--visual-viewport-height', h + 'px');
    }

    /** キーボード表示時に入力欄をキーボード直上に密着させる（visualViewport の下端に合わせる） */
    function applyInputAreaAboveKeyboard(inputArea) {
        if (!inputArea || !window.visualViewport) return;
        const vp = window.visualViewport;
        /* ビジュアルビューポート下端からの距離 = レイアウトビューポート下端から入力欄下端までの px */
        const bottomGap = window.innerHeight - (vp.offsetTop + vp.height);
        updateVisualViewportHeight();
        if (bottomGap > 60) {
            inputArea.style.bottom = bottomGap + 'px';
            document.body.classList.add('mobile-input-focused');
        } else {
            inputArea.style.bottom = '0';
            document.body.classList.remove('mobile-input-focused');
        }
    }

    /** フォーカス後しばらくの間、ビューポート・入力位置を連続で同期（キーボードアニメーションの遅れで空白が出ないようにする） */
    var mobileKeyboardSyncInterval = null;
    function startMobileKeyboardSyncLoop(inputAreaEl) {
        if (!isMobile()) return;
        if (mobileKeyboardSyncInterval) clearInterval(mobileKeyboardSyncInterval);
        var count = 0;
        var maxTicks = 25;
        mobileKeyboardSyncInterval = setInterval(function() {
            updateVisualViewportHeight();
            applyInputAreaAboveKeyboard(inputAreaEl);
            scrollMessagesToBottom();
            count++;
            if (count >= maxTicks) {
                clearInterval(mobileKeyboardSyncInterval);
                mobileKeyboardSyncInterval = null;
            }
        }, 40);
    }

    // キーボード表示時の調整
    function setupKeyboardHandling() {
        const inputArea = document.querySelector('.input-area');
        const messagesArea = document.querySelector('.messages-area');
        if (!inputArea || !messagesArea) return;

        // 初期値とリサイズでビューポート高さ・入力欄の位置を反映（キーボード直上に密着）
        updateVisualViewportHeight();
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', () => {
                applyInputAreaAboveKeyboard(inputArea);
                requestAnimationFrame(() => scrollMessagesToBottom());
                setTimeout(scrollMessagesToBottom, 50);
                setTimeout(scrollMessagesToBottom, 300);
            });
            window.visualViewport.addEventListener('scroll', () => updateVisualViewportHeight());
        }

        // 入力エリアにフォーカスが入ったら位置を即時＋遅延で適用（キーボードアニメーション完了に合わせる）
        const inputAreaEl = document.querySelector('.input-area') || document.getElementById('inputArea');
        if (inputAreaEl) {
            inputAreaEl.addEventListener('focusin', () => {
                document.body.classList.add('mobile-input-focused');
                updateVisualViewportHeight();
                applyInputAreaAboveKeyboard(inputAreaEl);
                startMobileKeyboardSyncLoop(inputAreaEl);
                requestAnimationFrame(() => scrollMessagesToBottom());
                setTimeout(function reapply() { applyInputAreaAboveKeyboard(inputAreaEl); scrollMessagesToBottom(); }, 100);
                setTimeout(function reapply() { applyInputAreaAboveKeyboard(inputAreaEl); scrollMessagesToBottom(); }, 300);
                setTimeout(function reapply() { applyInputAreaAboveKeyboard(inputAreaEl); scrollMessagesToBottom(); }, 500);
                setTimeout(function reapply() { applyInputAreaAboveKeyboard(inputAreaEl); scrollMessagesToBottom(); }, 800);
                setTimeout(scrollMessagesToBottom, 80);
                setTimeout(scrollMessagesToBottom, 250);
                setTimeout(scrollMessagesToBottom, 500);
                setTimeout(scrollMessagesToBottom, 900);
            });
            inputAreaEl.addEventListener('focusout', () => {
                if (mobileKeyboardSyncInterval) {
                    clearInterval(mobileKeyboardSyncInterval);
                    mobileKeyboardSyncInterval = null;
                }
                setTimeout(() => {
                    const active = document.activeElement;
                    if (!active || !inputAreaEl.contains(active)) {
                        document.body.classList.remove('mobile-input-focused');
                        inputAreaEl.style.bottom = '';
                    }
                }, 0);
            });
        }
    }


    function initPhase3() {
        if (!isMobile()) return;
        addMobileAnimations();
        setupPullToRefresh();
        setupLongPress();
        setupDoubleTap();
        setupKeyboardHandling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPhase3);
    } else {
        initPhase3();
    }

})();

// ========================================
// フローティングアクションボタン (FAB)
// ========================================

(function() {
    'use strict';

    const isMobile = () => window.innerWidth <= 768;

    function initFAB() {
        if (!isMobile()) return;

        // FAB要素を作成
        createFAB();

        // リサイズ時の処理
        window.addEventListener('resize', () => {
            const fabContainer = document.querySelector('.fab-container');
            const fabOverlay = document.querySelector('.fab-overlay');
            if (!isMobile()) {
                if (fabContainer) fabContainer.style.display = 'none';
                if (fabOverlay) fabOverlay.style.display = 'none';
            } else {
                if (fabContainer) fabContainer.style.display = 'block';
            }
        });

        console.log('FAB initialized');
    }

    function createFAB() {
        // 既に存在する場合は削除して再作成
        const existingContainer = document.querySelector('.fab-container');
        const existingOverlay = document.querySelector('.fab-overlay');
        if (existingContainer) existingContainer.remove();
        if (existingOverlay) existingOverlay.remove();
        const existingStoragePicker = document.querySelector('.fab-storage-picker-overlay');
        if (existingStoragePicker) existingStoragePicker.remove();

        // オーバーレイ
        const overlay = document.createElement('div');
        overlay.className = 'fab-overlay';
        overlay.addEventListener('click', (e) => {
            // メニュー内のクリックは無視
            if (e.target.closest('.fab-menu')) return;
            closeFAB();
        });
        overlay.addEventListener('touchend', (e) => {
            // メニュー内のタッチは無視
            if (e.target.closest('.fab-menu')) return;
            closeFAB();
        }, { passive: true });
        document.body.appendChild(overlay);

        // 共有フォルダ用グループ選択モーダル（FAB「共有フォルダ」で使用）
        const storagePickerOverlay = document.createElement('div');
        storagePickerOverlay.className = 'fab-storage-picker-overlay';
        storagePickerOverlay.setAttribute('aria-hidden', 'true');
        storagePickerOverlay.innerHTML = `
            <div class="fab-storage-picker">
                <h3 class="fab-storage-picker-title">共有フォルダを開くグループを選択</h3>
                <div class="fab-storage-picker-list"></div>
                <button type="button" class="fab-storage-picker-close">閉じる</button>
            </div>
        `;
        const storagePickerList = storagePickerOverlay.querySelector('.fab-storage-picker-list');
        const storagePickerClose = storagePickerOverlay.querySelector('.fab-storage-picker-close');
        storagePickerOverlay.addEventListener('click', (e) => {
            if (e.target === storagePickerOverlay || e.target === storagePickerClose) {
                closeStoragePickerModal();
            }
        });
        storagePickerClose.addEventListener('click', closeStoragePickerModal);
        document.body.appendChild(storagePickerOverlay);

        // FABコンテナ（上パネルのユーザーアイコン横に配置）
        const container = document.createElement('div');
        container.className = 'fab-container';
        container.innerHTML = `
            <div class="fab-menu">
                <div class="fab-menu-item" data-action="groups">
                    <span class="fab-btn groups"><img src="assets/icons/line/users.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">チャット</span>
                </div>
                <div class="fab-menu-item" data-action="storage-new">
                    <span class="fab-btn storage"><img src="assets/icons/line/folder.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">共有フォルダ</span>
                </div>
                <a href="design.php" class="fab-menu-item fab-link" data-action="design">
                    <span class="fab-btn design"><img src="assets/icons/line/palette.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">デザイン</span>
                </a>
                <a href="tasks.php" class="fab-menu-item fab-link" data-action="wish">
                    <span class="fab-btn wish"><img src="assets/icons/line/clipboard.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">タスク/メモ</span>
                </a>
                <a href="notifications.php" class="fab-menu-item fab-link" data-action="notify">
                    <span class="fab-btn notify"><img src="assets/icons/line/bell.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">お知らせ</span>
                </a>
                <a href="settings.php" class="fab-menu-item fab-link" data-action="settings">
                    <span class="fab-btn settings"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">設定</span>
                </a>
                <div class="fab-menu-item" data-action="language">
                    <span class="fab-btn language"><img src="assets/icons/line/globe.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">言語</span>
                </div>
                <div class="fab-menu-item" data-action="reload">
                    <span class="fab-btn reload"><img src="assets/icons/line/refresh.svg" alt="" class="icon-line" width="24" height="24"></span>
                    <span class="fab-label">強制リロード</span>
                </div>
            </div>
            <button class="fab-main" aria-label="メニュー"><img src="assets/icons/line/app-grid.svg" alt="" class="icon-line fab-main-icon" width="24" height="24" aria-hidden="true"><span class="fab-main-close" aria-hidden="true">×</span></button>
        `;

        // 上パネルの左側（top-left）に戻るボタンとFABを挿入
        const topLeft = document.querySelector('.top-panel .top-left');
        const topRight = document.querySelector('.top-panel .top-right');
        
        if (topLeft) {
            // チャット画面用戻るボタン（会話選択時のみ表示）
            const backBtn = document.createElement('button');
            backBtn.className = 'mobile-chat-back-btn';
            backBtn.setAttribute('aria-label', '戻る');
            backBtn.title = '戻る';
            backBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>';
            backBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                if (!isMobile()) return;
                location.href = 'chat.php';
            });
            topLeft.insertBefore(backBtn, topLeft.firstChild);
            
            // FABを左パネルボタン（⇒）の後に挿入
            const toggleLeftBtn = topLeft.querySelector('.toggle-left-btn, .mobile-menu-btn');
            if (toggleLeftBtn && toggleLeftBtn.nextSibling) {
                topLeft.insertBefore(container, toggleLeftBtn.nextSibling);
            } else {
                topLeft.insertBefore(container, topLeft.firstChild.nextSibling);
            }
        }
        
        if (topRight) {
            // 右パネル用の⇐ボタンは廃止（⇒のみ残す）
        }
        
        if (!topLeft && !topRight) {
            // フォールバック: body に追加
            document.body.appendChild(container);
        }
        
        console.log('FAB created and appended to body');

        // イベント設定
        const mainBtn = container.querySelector('.fab-main');
        mainBtn.addEventListener('click', toggleFAB);

        // メニューアイテムのクリック - リンク以外の項目（groups, language）のみ
        container.querySelectorAll('.fab-menu-item:not(.fab-link)').forEach(item => {
            const action = item.dataset.action;
            
            // タッチイベント（モバイル優先）
            item.addEventListener('touchend', (e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('FAB menu item touched:', action);
                executeFABAction(action);
            }, { passive: false });
            
            // クリックイベント（フォールバック）
            item.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('FAB menu item clicked:', action);
                executeFABAction(action);
            });
        });
        
        // リンク型メニューアイテムはクリック時にFABを閉じるだけ
        container.querySelectorAll('.fab-menu-item.fab-link').forEach(link => {
            link.addEventListener('click', (e) => {
                console.log('FAB link clicked:', link.href);
                closeFAB();
                // リンクのデフォルト動作（ナビゲーション）を許可
            });
        });

        syncFABBadges();
    }

    function toggleFAB() {
        const container = document.querySelector('.fab-container');
        const overlay = document.querySelector('.fab-overlay');
        const mainBtn = container.querySelector('.fab-main');

        if (container.classList.contains('open')) {
            closeFAB();
        } else {
            container.classList.add('open');
            overlay.classList.add('show');
            mainBtn.classList.add('open');
        }
    }

    function closeFAB() {
        const container = document.querySelector('.fab-container');
        const overlay = document.querySelector('.fab-overlay');
        if (!container) return;

        const mainBtn = container.querySelector('.fab-main');
        container.classList.remove('open');
        overlay.classList.remove('show');
        mainBtn.classList.remove('open');
    }

    // FABアクション実行用のフラグ（重複実行防止）
    let fabActionExecuting = false;
    
    // 共有フォルダ用グループ選択モーダルを閉じる
    function closeStoragePickerModal() {
        const el = document.querySelector('.fab-storage-picker-overlay');
        if (el) {
            el.classList.remove('show');
            el.setAttribute('aria-hidden', 'true');
            const list = el.querySelector('.fab-storage-picker-list');
            if (list) list.innerHTML = '';
        }
        fabActionExecuting = false;
    }
    
    // 共有フォルダを開くグループを選択するモーダルを表示
    function showStorageNewPicker() {
        const overlayEl = document.querySelector('.fab-storage-picker-overlay');
        const listEl = overlayEl ? overlayEl.querySelector('.fab-storage-picker-list') : null;
        if (!overlayEl || !listEl) return;
        
        listEl.innerHTML = '<p class="fab-storage-picker-loading">読み込み中…</p>';
        overlayEl.classList.add('show');
        overlayEl.setAttribute('aria-hidden', 'false');
        
        fetch('api/conversations.php?action=list_with_unread')
            .then(res => res.json())
            .then(data => {
                if (!data.success || !Array.isArray(data.conversations)) {
                    listEl.innerHTML = '<p class="fab-storage-picker-msg">会話一覧を取得できませんでした。</p>';
                    return;
                }
                const groups = data.conversations.filter(c => c.type === 'group');
                if (groups.length === 0) {
                    listEl.innerHTML = '<p class="fab-storage-picker-msg">グループがありません。</p>';
                    return;
                }
                listEl.innerHTML = '';
                groups.forEach(conv => {
                    const unread = parseInt(conv.unread_count, 10) || 0;
                    const name = (conv.name || '').trim() || ('グループ ' + conv.id);
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'fab-storage-picker-item';
                    item.dataset.convId = String(conv.id);
                    item.innerHTML = `
                        <span class="fab-storage-picker-item-name">${escapeHtml(name)}</span>
                        ${unread > 0 ? `<span class="fab-storage-picker-item-unread">${unread > 99 ? '99+' : unread}</span>` : ''}
                    `;
                    item.addEventListener('click', () => {
                        const id = item.dataset.convId;
                        if (id) {
                            closeStoragePickerModal();
                            location.href = 'chat.php?c=' + id + '#storage';
                        }
                    });
                    listEl.appendChild(item);
                });
            })
            .catch(() => {
                listEl.innerHTML = '<p class="fab-storage-picker-msg">会話一覧を取得できませんでした。</p>';
            });
    }
    
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    // FABアクションを直接実行（重複防止付き）
    function executeFABAction(action) {
        if (fabActionExecuting) {
            console.log('FAB action already executing, skipping:', action);
            return;
        }
        
        fabActionExecuting = true;
        console.log('executeFABAction:', action);
        
        // FABを閉じる
        closeFAB();
        
        // アクション実行
        switch (action) {
            case 'groups':
                // グループ一覧（左パネル）を開く
                openLeftPanelForGroups();
                break;
            case 'storage-new':
                showStorageNewPicker();
                return;
            case 'language':
                showLanguageSelector();
                break;
            case 'reload':
                // アプリで強制リロードができないためメニューから実行
                try {
                    if (typeof location.reload === 'function') {
                        location.reload(true);
                    } else {
                        location.href = location.href;
                    }
                } catch (e) {
                    location.href = location.href;
                }
                return;
            default:
                console.warn('Unknown FAB action:', action);
        }
        
        // フラグをリセット
        setTimeout(() => {
            fabActionExecuting = false;
        }, 300);
    }
    
    // 左パネルを確実に開く（トグルではなく常に開く）
    function openLeftPanelForGroups() {
        console.log('Opening left panel for groups');
        var lp = document.getElementById('leftPanel') || document.querySelector('.left-panel');
        if (!lp) { console.error('Left panel not found'); return; }
        if (lp.classList.contains('mobile-open')) return;
        if (typeof window.closeMobileAllPanels === 'function') window.closeMobileAllPanels();
        lp.classList.add('mobile-open');
        var ov = document.querySelector('.mobile-overlay') || document.getElementById('mobileOverlay');
        if (ov) { ov.classList.add('show'); ov.style.display = 'block'; ov.style.opacity = '1'; }
        document.body.classList.add('mobile-panel-open');
        document.body.style.overflow = 'hidden';
    }
    
    // 旧関数名の互換性維持
    function handleFABAction(action) {
        executeFABAction(action);
    }

    // グループ管理ボタンを作成（ユーザーアイコンの右側に配置）
    function createGroupManagementButton(topRight) {
        // 既に存在する場合は作成しない
        if (document.querySelector('.mobile-group-mgmt-btn')) return;
        
        const btn = document.createElement('button');
        btn.className = 'mobile-group-mgmt-btn';
        btn.innerHTML = '⇐';
        btn.title = '詳細';
        btn.setAttribute('aria-label', 'グループ詳細');
        
        btn.addEventListener('click', () => {
            handleMobileArrowLeft();
        });
        
        // 設定ボタンの前に挿入（ユーザーアイコンの後）
        const settingsBtn = topRight.querySelector('.settings-btn');
        if (settingsBtn) {
            topRight.insertBefore(btn, settingsBtn);
        } else {
            topRight.appendChild(btn);
        }
    }

    function showLanguageSelector() {
        // 言語選択ポップアップを表示
        const popup = document.createElement('div');
        popup.className = 'fab-language-popup';
        popup.innerHTML = `
            <div class="fab-language-backdrop" onclick="this.parentNode.remove()"></div>
            <div class="fab-language-sheet">
                <div class="fab-language-title">🌐 言語を選択</div>
                <div class="fab-language-option" onclick="changeLanguage('ja'); this.closest('.fab-language-popup').remove();">
                    🇯🇵 日本語
                </div>
                <div class="fab-language-option" onclick="changeLanguage('en'); this.closest('.fab-language-popup').remove();">
                    🇺🇸 English
                </div>
                <div class="fab-language-option" onclick="changeLanguage('zh'); this.closest('.fab-language-popup').remove();">
                    🇨🇳 中文
                </div>
                <button class="fab-language-cancel" onclick="this.closest('.fab-language-popup').remove();">キャンセル</button>
            </div>
        `;

        // スタイルを追加
        popup.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000;
            display: flex; align-items: flex-end; justify-content: center;
        `;
        popup.querySelector('.fab-language-backdrop').style.cssText = `
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
        `;
        popup.querySelector('.fab-language-sheet').style.cssText = `
            position: relative; background: white; width: 100%; max-width: 400px;
            border-radius: 16px 16px 0 0; padding: 20px; padding-bottom: calc(20px + env(safe-area-inset-bottom, 0px));
        `;
        popup.querySelector('.fab-language-title').style.cssText = `
            font-size: 18px; font-weight: 600; margin-bottom: 16px; text-align: center;
        `;
        popup.querySelectorAll('.fab-language-option').forEach(opt => {
            opt.style.cssText = `
                padding: 16px; font-size: 16px; border-radius: 12px; cursor: pointer;
                margin-bottom: 8px; background: #f5f5f5; text-align: center;
            `;
        });
        popup.querySelector('.fab-language-cancel').style.cssText = `
            width: 100%; padding: 16px; font-size: 16px; border: none; border-radius: 12px;
            background: #e5e5e5; cursor: pointer; margin-top: 8px;
        `;

        document.body.appendChild(popup);
    }

    function syncFABBadges() {
        // 元のバッジからFABバッジに同期
        const taskBadge = document.getElementById('taskBadge');
        const notifyBadge = document.getElementById('notificationBadge');

        if (taskBadge && taskBadge.style.display !== 'none') {
            const fabWish = document.querySelector('.fab-btn.wish');
            if (fabWish) {
                const badge = document.createElement('span');
                badge.className = 'fab-badge';
                badge.textContent = taskBadge.textContent;
                fabWish.appendChild(badge);
            }
        }

        if (notifyBadge && notifyBadge.style.display !== 'none') {
            const fabNotify = document.querySelector('.fab-btn.notify');
            if (fabNotify) {
                const badge = document.createElement('span');
                badge.className = 'fab-badge';
                badge.textContent = notifyBadge.textContent;
                fabNotify.appendChild(badge);
            }
        }
    }

    // 初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFAB);
    } else {
        initFAB();
    }

})();

// ========================================
// モバイル用インラインフォーム機能
// ========================================
(function() {
    'use strict';

    const isMobile = () => window.innerWidth <= 768;

    // グループ作成ボタンクリック
    window.handleCreateGroupClick = function() {
        if (isMobile()) {
            showMobileForm('group');
        } else {
            // PC版: モーダルを開く
            if (typeof openCreateGroupModal === 'function') {
                openCreateGroupModal();
            }
        }
    };

    // 友達追加ボタンクリック
    window.handleAddFriendClick = function() {
        if (isMobile()) {
            showMobileForm('friend');
        } else {
            // PC版: モーダルを開く
            if (typeof openAddFriendModal === 'function') {
                openAddFriendModal();
            }
        }
    };

    // 選択されたメンバーIDを保持
    let selectedMemberIds = new Set();

    // モバイルフォーム表示
    function showMobileForm(type) {
        const container = document.getElementById('mobileInlineForm');
        const groupForm = document.getElementById('mobileGroupForm');
        const friendForm = document.getElementById('mobileFriendForm');
        
        if (!container) return;
        
        // 両方非表示にする
        if (groupForm) groupForm.style.display = 'none';
        if (friendForm) friendForm.style.display = 'none';
        
        // 指定されたフォームを表示
        if (type === 'group' && groupForm) {
            groupForm.style.display = 'block';
            container.style.display = 'block';
            const leftPanel = document.getElementById('leftPanel');
            if (leftPanel) leftPanel.classList.add('left-panel-group-form-open');
            const input = document.getElementById('mobileGroupName');
            if (input) {
                input.value = '';
                input.focus();
            }
            // メンバーリストを読み込む
            selectedMemberIds.clear();
            loadAvailableMembers();
        } else if (type === 'friend' && friendForm) {
            friendForm.style.display = 'block';
            container.style.display = 'block';
            const input = document.getElementById('mobileFriendSearchInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            // 検索結果をクリア
            const results = document.getElementById('mobileFriendResults');
            if (results) results.innerHTML = '';
        }
    }

    // 追加可能なメンバーを読み込む
    async function loadAvailableMembers() {
        const listContainer = document.getElementById('mobileGroupMemberList');
        if (!listContainer) return;
        
        listContainer.innerHTML = '<div class="mobile-member-loading">読み込み中...</div>';
        
        try {
            // 所属グループのメンバー全員を取得（グループ作成用：DM制限を無視）
            const response = await fetch('api/users.php?action=list_group_members&include_dm_restricted=1');
            const data = await response.json();
            
            if (data.success && data.users && data.users.length > 0) {
                listContainer.innerHTML = data.users.map(user => `
                    <div class="mobile-member-item" data-user-id="${user.id}" onclick="toggleMemberSelection(this, ${user.id})">
                        <div class="mobile-member-checkbox">✓</div>
                        <div class="mobile-member-avatar">${escapeHtml((user.display_name || user.name || 'U').charAt(0).toUpperCase())}</div>
                        <div class="mobile-member-name">${escapeHtml(user.display_name || user.name)}</div>
                    </div>
                `).join('');
            } else {
                listContainer.innerHTML = '<div class="mobile-member-empty">追加できるメンバーがいません</div>';
            }
            
            updateSelectedCount();
        } catch (error) {
            console.error('メンバー読み込みエラー:', error);
            listContainer.innerHTML = '<div class="mobile-member-empty">読み込みに失敗しました</div>';
        }
    }

    // メンバー選択をトグル
    window.toggleMemberSelection = function(element, userId) {
        if (selectedMemberIds.has(userId)) {
            selectedMemberIds.delete(userId);
            element.classList.remove('selected');
        } else {
            selectedMemberIds.add(userId);
            element.classList.add('selected');
        }
        updateSelectedCount();
    };

    // 選択数を更新
    function updateSelectedCount() {
        const countEl = document.getElementById('mobileSelectedCount');
        if (countEl) {
            countEl.textContent = `選択中: ${selectedMemberIds.size}人`;
        }
    }

    // モバイルフォームを閉じる
    window.closeMobileInlineForm = function() {
        const container = document.getElementById('mobileInlineForm');
        const groupForm = document.getElementById('mobileGroupForm');
        const friendForm = document.getElementById('mobileFriendForm');
        const leftPanel = document.getElementById('leftPanel');
        if (leftPanel) leftPanel.classList.remove('left-panel-group-form-open');
        
        if (container) container.style.display = 'none';
        if (groupForm) groupForm.style.display = 'none';
        if (friendForm) friendForm.style.display = 'none';
        
        // 選択をクリア
        selectedMemberIds.clear();
    };

    // グループ作成
    window.createMobileGroup = async function() {
        const input = document.getElementById('mobileGroupName');
        const groupName = input ? input.value.trim() : '';
        
        if (!groupName) {
            showMobileToast('グループ名を入力してください');
            return;
        }
        
        // 選択されたメンバーIDの配列
        const memberIds = Array.from(selectedMemberIds);
        
        try {
            const response = await fetch('api/conversations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create',
                    type: 'group',
                    name: groupName,
                    member_ids: memberIds
                })
            });
            const data = await response.json();
            
            if (data.success) {
                showMobileToast('グループを作成しました');
                closeMobileInlineForm();
                // ページをリロードして新しいグループを表示
                if (data.conversation_id) {
                    location.href = 'chat.php?c=' + data.conversation_id;
                } else {
                    location.reload();
                }
            } else {
                showMobileToast(data.error || 'エラーが発生しました');
            }
        } catch (error) {
            console.error('グループ作成エラー:', error);
            showMobileToast('通信エラーが発生しました');
        }
    };

    // 友達検索
    window.searchMobileFriend = async function() {
        const input = document.getElementById('mobileFriendSearchInput');
        const resultsContainer = document.getElementById('mobileFriendResults');
        const inviteRow = document.getElementById('mobileFriendInviteRow');
        const inviteBtn = document.getElementById('mobileFriendInviteBtn');
        
        if (!input || !resultsContainer) return;
        
        const query = input.value.trim();
        if (!query) {
            resultsContainer.innerHTML = '';
            if (inviteRow) inviteRow.style.display = 'none';
            return;
        }
        
        try {
            const response = await fetch(`api/friends.php?action=search&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (inviteRow) inviteRow.style.display = 'none';
            
            if (data.success && data.users && data.users.length > 0) {
                resultsContainer.innerHTML = data.users.map(user => `
                    <div class="mobile-search-result-item" data-user-id="${user.id}">
                        <div class="mobile-search-result-avatar">${escapeHtml((user.display_name || user.name || 'U').charAt(0).toUpperCase())}</div>
                        <div class="mobile-search-result-info">
                            <div class="mobile-search-result-name">${escapeHtml(user.display_name || user.name)}</div>
                            <div class="mobile-search-result-id">ID: ${user.friend_id || user.id}</div>
                        </div>
                        <button class="mobile-search-result-action" onclick="sendMobileFriendRequest(${user.id}, this)">追加</button>
                    </div>
                `).join('');
            } else {
                resultsContainer.innerHTML = '<div class="mobile-search-no-result">ユーザーが見つかりませんでした</div>';
                if (data.invite_available && data.contact && inviteRow && inviteBtn) {
                    inviteBtn.setAttribute('data-invite-contact', data.contact);
                    inviteRow.style.display = 'block';
                }
            }
        } catch (error) {
            console.error('検索エラー:', error);
            resultsContainer.innerHTML = '<div class="mobile-search-no-result">検索中にエラーが発生しました</div>';
            if (inviteRow) inviteRow.style.display = 'none';
        }
    };

    // 未登録メールに招待を送る（モバイル友達追加フォームから）
    window.sendMobileInvite = async function() {
        const btn = document.getElementById('mobileFriendInviteBtn');
        const contact = btn ? btn.getAttribute('data-invite-contact') : '';
        if (!contact) return;
        if (btn) { btn.disabled = true; btn.textContent = '送信中...'; }
        try {
            const response = await fetch('api/friends.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send_invite', contact: contact, type: 'email' })
            });
            const data = await response.json();
            if (data.success) {
                showMobileToast('招待を送信しました');
                const row = document.getElementById('mobileFriendInviteRow');
                if (row) row.style.display = 'none';
            } else {
                showMobileToast(data.error || '送信に失敗しました');
            }
        } catch (e) {
            showMobileToast('通信エラーが発生しました');
        }
        if (btn) { btn.disabled = false; btn.textContent = 'このメールアドレスに友達申請を送る'; }
    };

    // 友達追加モーダルをQRタブで開く（携帯から）
    window.openAddFriendModalForQR = function() {
        if (typeof openAddFriendModal === 'function') openAddFriendModal();
        if (typeof switchAddFriendTab === 'function') switchAddFriendTab('qr');
    };

    // 自分の招待用QRコードを表示（携帯）
    window.showMyQRCodeMobile = function() {
        const container = document.getElementById('mobileMyQRContainer');
        const img = document.getElementById('mobileMyQRImage');
        if (!container || !img) return;
        const uid = (typeof window._currentUserId !== 'undefined') ? window._currentUserId : 0;
        const inviteUrl = uid ? (window.location.origin + '/invite.php?u=' + uid) : '';
        if (inviteUrl) {
            img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(inviteUrl);
            img.alt = '招待用QRコード';
        }
        container.style.display = 'block';
    };

    window.closeMyQRCodeMobile = function() {
        const container = document.getElementById('mobileMyQRContainer');
        if (container) container.style.display = 'none';
    };

    // 友達リクエスト送信
    window.sendMobileFriendRequest = async function(userId, btn) {
        if (!userId) return;
        
        btn.disabled = true;
        btn.textContent = '送信中...';
        
        try {
            const response = await fetch('api/friends.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add', friend_id: userId })
            });
            const data = await response.json();
            
            if (data.success) {
                btn.textContent = '送信済み';
                btn.style.background = '#6b7280';
                showMobileToast('友達リクエストを送信しました');
            } else {
                btn.textContent = '追加';
                btn.disabled = false;
                showMobileToast(data.error || 'エラーが発生しました');
            }
        } catch (error) {
            console.error('リクエストエラー:', error);
            btn.textContent = '追加';
            btn.disabled = false;
            showMobileToast('通信エラーが発生しました');
        }
    };

    // トースト表示
    function showMobileToast(message) {
        let toast = document.querySelector('.mobile-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'mobile-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    // HTMLエスケープ
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Enterキー対応
    document.addEventListener('DOMContentLoaded', function() {
        const groupInput = document.getElementById('mobileGroupName');
        if (groupInput) {
            groupInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    createMobileGroup();
                }
            });
        }
        
        const friendInput = document.getElementById('mobileFriendSearchInput');
        if (friendInput) {
            friendInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchMobileFriend();
                }
            });
        }
    });
})();

