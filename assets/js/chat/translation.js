/**
 * 翻訳機能モジュール
 * 
 * メッセージの自動翻訳機能
 * 
 * 使用例:
 * Chat.translation.translate(text, targetLang);
 * Chat.translation.init({ autoTranslate: true });
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 内部状態
    let autoTranslateEnabled = false;
    let targetLanguage = 'ja';
    let budgetRemaining = 0;
    let budgetExceeded = false;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    async function init(options = {}) {
        if (options.autoTranslate !== undefined) {
            autoTranslateEnabled = options.autoTranslate;
        }
        if (options.targetLanguage) {
            targetLanguage = options.targetLanguage;
        }
        
        // 予算状況を取得
        await checkBudgetStatus();
        
        console.log('[Translation] Initialized', {
            autoTranslate: autoTranslateEnabled,
            targetLanguage: targetLanguage,
            budgetRemaining: budgetRemaining
        });
    }
    
    /**
     * 予算状況を確認
     */
    async function checkBudgetStatus() {
        try {
            const response = await fetch('api/translate.php?action=budget_status');
            const data = await response.json();
            
            if (data.success) {
                budgetRemaining = data.remaining || 0;
                budgetExceeded = data.exceeded || false;
                
                if (budgetExceeded) {
                    autoTranslateEnabled = false;
                    console.log('[Translation] Budget exceeded, auto-translate disabled');
                }
            }
        } catch (error) {
            console.error('[Translation] Budget check error:', error);
            budgetExceeded = true;
        }
    }
    
    /**
     * テキストを翻訳
     * @param {string} text - 翻訳するテキスト
     * @param {string} target - ターゲット言語
     * @returns {Promise<string>} 翻訳されたテキスト
     */
    async function translate(text, target = null) {
        if (!text) return text;
        
        const lang = target || targetLanguage;
        
        try {
            const response = await fetch('api/translate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=translate&text=${encodeURIComponent(text)}&target=${lang}`
            });
            
            const data = await response.json();
            
            if (data.success && data.translated) {
                return data.translated;
            } else {
                console.error('[Translation] Failed:', data.error);
                return text;
            }
        } catch (error) {
            console.error('[Translation] Error:', error);
            return text;
        }
    }
    
    /**
     * メッセージを翻訳してUIに表示
     * @param {number} messageId - メッセージID
     */
    async function translateMessage(messageId) {
        const card = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!card) return;
        
        const contentEl = card.querySelector('.content');
        if (!contentEl) return;
        
        const originalText = card.dataset.content || contentEl.textContent;
        
        // 翻訳中表示
        const originalHtml = contentEl.innerHTML;
        contentEl.innerHTML = '<span class="translating">翻訳中...</span>';
        
        try {
            const translated = await translate(originalText);
            
            // 翻訳結果を表示
            contentEl.innerHTML = `
                <div class="translated-content">${Chat.utils ? Chat.utils.escapeHtml(translated) : translated}</div>
                <div class="original-content" style="display:none;">${originalHtml}</div>
                <button class="toggle-translation" onclick="Chat.translation.toggleOriginal(${messageId})">原文を表示</button>
            `;
            
            card.classList.add('translated');
        } catch (error) {
            contentEl.innerHTML = originalHtml;
            console.error('[Translation] Message translation error:', error);
        }
    }
    
    /**
     * 原文/翻訳を切り替え
     * @param {number} messageId - メッセージID
     */
    function toggleOriginal(messageId) {
        const card = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!card) return;
        
        const translatedEl = card.querySelector('.translated-content');
        const originalEl = card.querySelector('.original-content');
        const toggleBtn = card.querySelector('.toggle-translation');
        
        if (!translatedEl || !originalEl || !toggleBtn) return;
        
        if (originalEl.style.display === 'none') {
            originalEl.style.display = 'block';
            translatedEl.style.display = 'none';
            toggleBtn.textContent = '翻訳を表示';
        } else {
            originalEl.style.display = 'none';
            translatedEl.style.display = 'block';
            toggleBtn.textContent = '原文を表示';
        }
    }
    
    /**
     * 自動翻訳を有効化
     */
    function enableAutoTranslate() {
        if (budgetExceeded) {
            console.warn('[Translation] Cannot enable - budget exceeded');
            return false;
        }
        autoTranslateEnabled = true;
        return true;
    }
    
    /**
     * 自動翻訳を無効化
     */
    function disableAutoTranslate() {
        autoTranslateEnabled = false;
    }
    
    /**
     * 自動翻訳が有効かどうか
     * @returns {boolean}
     */
    function isAutoTranslateEnabled() {
        return autoTranslateEnabled && !budgetExceeded;
    }
    
    /**
     * ターゲット言語を設定
     * @param {string} lang - 言語コード
     */
    function setTargetLanguage(lang) {
        targetLanguage = lang;
    }
    
    /**
     * ターゲット言語を取得
     * @returns {string}
     */
    function getTargetLanguage() {
        return targetLanguage;
    }
    
    /**
     * 予算残高を取得
     * @returns {number}
     */
    function getBudgetRemaining() {
        return budgetRemaining;
    }
    
    // 公開API
    Chat.translation = {
        init,
        checkBudgetStatus,
        translate,
        translateMessage,
        toggleOriginal,
        enableAutoTranslate,
        disableAutoTranslate,
        isAutoTranslateEnabled,
        setTargetLanguage,
        getTargetLanguage,
        getBudgetRemaining
    };
    
    // グローバル関数との互換性
    global.translateText = translate;
    global.translateMessage = translateMessage;
    global.initAutoTranslation = init;
    global.getTranslationBudgetStatus = checkBudgetStatus;
    
})(typeof window !== 'undefined' ? window : this);
