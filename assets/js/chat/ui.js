/**
 * 共通UIコンポーネントモジュール
 * 
 * トースト通知、確認ダイアログ、ローディング表示など
 * 
 * 使用例:
 * Chat.ui.toast('保存しました', 'success');
 * const confirmed = await Chat.ui.confirm('削除しますか？');
 * Chat.ui.loading.show();
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // ========================================
    // トースト通知
    // ========================================
    
    let toastContainer = null;
    
    function ensureToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'chatToastContainer';
            toastContainer.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                z-index: 100000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }
    
    /**
     * トースト通知を表示
     * @param {string} message - メッセージ
     * @param {string} type - 'success', 'error', 'warning', 'info'
     * @param {number} duration - 表示時間（ms）
     */
    function toast(message, type = 'info', duration = 3000) {
        const container = ensureToastContainer();
        
        const colors = {
            success: { bg: '#22c55e', icon: '✓' },
            error: { bg: '#ef4444', icon: '✕' },
            warning: { bg: '#f59e0b', icon: '⚠' },
            info: { bg: '#3b82f6', icon: 'ℹ' }
        };
        
        const { bg, icon } = colors[type] || colors.info;
        
        const toastEl = document.createElement('div');
        toastEl.style.cssText = `
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: ${bg};
            color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            font-size: 14px;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            pointer-events: auto;
            max-width: 350px;
        `;
        
        toastEl.innerHTML = `
            <span style="font-size: 18px;">${icon}</span>
            <span>${Chat.utils ? Chat.utils.escapeHtml(message) : message}</span>
            <button onclick="this.parentElement.remove()" style="
                background: none;
                border: none;
                color: white;
                font-size: 18px;
                cursor: pointer;
                margin-left: auto;
                opacity: 0.7;
            ">×</button>
        `;
        
        container.appendChild(toastEl);
        
        // アニメーション
        requestAnimationFrame(() => {
            toastEl.style.opacity = '1';
            toastEl.style.transform = 'translateX(0)';
        });
        
        // 自動削除
        setTimeout(() => {
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translateX(100%)';
            setTimeout(() => toastEl.remove(), 300);
        }, duration);
        
        return toastEl;
    }
    
    // ========================================
    // 確認ダイアログ
    // ========================================
    
    /**
     * 確認ダイアログを表示
     * @param {string} message - メッセージ
     * @param {Object} options - オプション
     * @returns {Promise<boolean>}
     */
    function confirm(message, options = {}) {
        const {
            title = '確認',
            confirmText = 'OK',
            cancelText = 'キャンセル',
            type = 'default' // 'default', 'danger'
        } = options;
        
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 100001;
                opacity: 0;
                transition: opacity 0.2s;
            `;
            
            const confirmColor = type === 'danger' ? '#ef4444' : '#3b82f6';
            
            overlay.innerHTML = `
                <div style="
                    background: white;
                    border-radius: 12px;
                    padding: 24px;
                    min-width: 300px;
                    max-width: 400px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    transform: scale(0.9);
                    transition: transform 0.2s;
                ">
                    <h3 style="margin: 0 0 12px; font-size: 18px; color: #1f2937;">${title}</h3>
                    <p style="margin: 0 0 20px; color: #6b7280; line-height: 1.5;">${message}</p>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button class="chat-confirm-cancel" style="
                            padding: 10px 20px;
                            border: 1px solid #e5e7eb;
                            background: white;
                            border-radius: 8px;
                            cursor: pointer;
                            font-size: 14px;
                            color: #374151;
                        ">${cancelText}</button>
                        <button class="chat-confirm-ok" style="
                            padding: 10px 20px;
                            border: none;
                            background: ${confirmColor};
                            color: white;
                            border-radius: 8px;
                            cursor: pointer;
                            font-size: 14px;
                        ">${confirmText}</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(overlay);
            
            // アニメーション
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                overlay.querySelector('div').style.transform = 'scale(1)';
            });
            
            const close = (result) => {
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                    resolve(result);
                }, 200);
            };
            
            overlay.querySelector('.chat-confirm-ok').onclick = () => close(true);
            overlay.querySelector('.chat-confirm-cancel').onclick = () => close(false);
            overlay.onclick = (e) => {
                if (e.target === overlay) close(false);
            };
        });
    }
    
    /**
     * 入力ダイアログを表示
     * @param {string} message - メッセージ
     * @param {Object} options - オプション
     * @returns {Promise<string|null>}
     */
    function prompt(message, options = {}) {
        const {
            title = '入力',
            defaultValue = '',
            placeholder = '',
            confirmText = 'OK',
            cancelText = 'キャンセル'
        } = options;
        
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 100001;
                opacity: 0;
                transition: opacity 0.2s;
            `;
            
            overlay.innerHTML = `
                <div style="
                    background: white;
                    border-radius: 12px;
                    padding: 24px;
                    min-width: 350px;
                    max-width: 450px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    transform: scale(0.9);
                    transition: transform 0.2s;
                ">
                    <h3 style="margin: 0 0 12px; font-size: 18px; color: #1f2937;">${title}</h3>
                    <p style="margin: 0 0 16px; color: #6b7280;">${message}</p>
                    <input type="text" class="chat-prompt-input" value="${defaultValue}" placeholder="${placeholder}" style="
                        width: 100%;
                        padding: 12px;
                        border: 1px solid #e5e7eb;
                        border-radius: 8px;
                        font-size: 14px;
                        margin-bottom: 20px;
                        box-sizing: border-box;
                    ">
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button class="chat-prompt-cancel" style="
                            padding: 10px 20px;
                            border: 1px solid #e5e7eb;
                            background: white;
                            border-radius: 8px;
                            cursor: pointer;
                            font-size: 14px;
                            color: #374151;
                        ">${cancelText}</button>
                        <button class="chat-prompt-ok" style="
                            padding: 10px 20px;
                            border: none;
                            background: #3b82f6;
                            color: white;
                            border-radius: 8px;
                            cursor: pointer;
                            font-size: 14px;
                        ">${confirmText}</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(overlay);
            
            const input = overlay.querySelector('.chat-prompt-input');
            input.focus();
            input.select();
            
            // アニメーション
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                overlay.querySelector('div').style.transform = 'scale(1)';
            });
            
            const close = (value) => {
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                    resolve(value);
                }, 200);
            };
            
            overlay.querySelector('.chat-prompt-ok').onclick = () => close(input.value);
            overlay.querySelector('.chat-prompt-cancel').onclick = () => close(null);
            input.onkeydown = (e) => {
                if (e.key === 'Enter') close(input.value);
                if (e.key === 'Escape') close(null);
            };
        });
    }
    
    // ========================================
    // ローディング表示
    // ========================================
    
    let loadingOverlay = null;
    let loadingCount = 0;
    
    const loading = {
        /**
         * ローディング表示
         * @param {string} message - メッセージ
         */
        show(message = '読み込み中...') {
            loadingCount++;
            
            if (!loadingOverlay) {
                loadingOverlay = document.createElement('div');
                loadingOverlay.id = 'chatLoadingOverlay';
                loadingOverlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.4);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 100002;
                `;
                
                loadingOverlay.innerHTML = `
                    <div style="
                        background: white;
                        padding: 30px 40px;
                        border-radius: 12px;
                        text-align: center;
                        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    ">
                        <div style="
                            width: 40px;
                            height: 40px;
                            border: 3px solid #e5e7eb;
                            border-top-color: #3b82f6;
                            border-radius: 50%;
                            animation: chatLoadingSpin 0.8s linear infinite;
                            margin: 0 auto 16px;
                        "></div>
                        <div class="loading-message" style="color: #374151; font-size: 14px;">${message}</div>
                    </div>
                    <style>
                        @keyframes chatLoadingSpin {
                            to { transform: rotate(360deg); }
                        }
                    </style>
                `;
                
                document.body.appendChild(loadingOverlay);
            } else {
                loadingOverlay.querySelector('.loading-message').textContent = message;
                loadingOverlay.style.display = 'flex';
            }
        },
        
        /**
         * ローディング非表示
         */
        hide() {
            loadingCount = Math.max(0, loadingCount - 1);
            
            if (loadingCount === 0 && loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
        },
        
        /**
         * 強制的に非表示
         */
        forceHide() {
            loadingCount = 0;
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
        }
    };
    
    // ========================================
    // モーダル
    // ========================================
    
    /**
     * モーダルを開く
     * @param {string} id - モーダルID
     */
    function openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    
    /**
     * モーダルを閉じる
     * @param {string} id - モーダルID
     */
    function closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    /**
     * すべてのモーダルを閉じる
     */
    function closeAllModals() {
        document.querySelectorAll('.modal, [class*="modal"]').forEach(modal => {
            if (modal.style.display === 'flex' || modal.style.display === 'block') {
                modal.style.display = 'none';
            }
        });
        document.body.style.overflow = '';
    }
    
    // ========================================
    // ツールチップ
    // ========================================
    
    /**
     * ツールチップを表示
     * @param {HTMLElement} target - 対象要素
     * @param {string} text - ツールチップテキスト
     * @param {string} position - 'top', 'bottom', 'left', 'right'
     */
    function showTooltip(target, text, position = 'top') {
        const tooltip = document.createElement('div');
        tooltip.className = 'chat-tooltip';
        tooltip.textContent = text;
        tooltip.style.cssText = `
            position: absolute;
            background: #1f2937;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 100003;
            pointer-events: none;
        `;
        
        document.body.appendChild(tooltip);
        
        const rect = target.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        
        let top, left;
        
        switch (position) {
            case 'bottom':
                top = rect.bottom + 8;
                left = rect.left + (rect.width - tooltipRect.width) / 2;
                break;
            case 'left':
                top = rect.top + (rect.height - tooltipRect.height) / 2;
                left = rect.left - tooltipRect.width - 8;
                break;
            case 'right':
                top = rect.top + (rect.height - tooltipRect.height) / 2;
                left = rect.right + 8;
                break;
            default: // top
                top = rect.top - tooltipRect.height - 8;
                left = rect.left + (rect.width - tooltipRect.width) / 2;
        }
        
        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
        
        return tooltip;
    }
    
    /**
     * ツールチップを非表示
     */
    function hideTooltip() {
        document.querySelectorAll('.chat-tooltip').forEach(t => t.remove());
    }
    
    // 公開API
    Chat.ui = {
        // トースト
        toast,
        
        // ダイアログ
        confirm,
        prompt,
        alert: (message, title = '通知') => confirm(message, { title, cancelText: '' }),
        
        // ローディング
        loading,
        
        // モーダル
        openModal,
        closeModal,
        closeAllModals,
        
        // ツールチップ
        showTooltip,
        hideTooltip
    };
    
    // グローバル関数との互換性
    global.showToast = toast;
    global.ChatUI = Chat.ui;
    
})(typeof window !== 'undefined' ? window : this);
