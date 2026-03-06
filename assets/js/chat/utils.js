/**
 * チャットユーティリティモジュール
 * 
 * 共通で使用するユーティリティ関数
 * 
 * 使用例:
 * Chat.utils.escapeHtml('<script>');
 * Chat.utils.debounce(fn, 300);
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    /**
     * HTMLエスケープ
     * @param {string} str - エスケープする文字列
     * @returns {string} エスケープされた文字列
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    /**
     * HTMLアンエスケープ
     * @param {string} str - アンエスケープする文字列
     * @returns {string} アンエスケープされた文字列
     */
    function unescapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.innerHTML = str;
        return div.textContent;
    }
    
    /**
     * テキストエリアの自動リサイズ
     * 長文貼り付けでも入力欄が伸びないよう、計測時も max-height を外さない。
     * @param {HTMLTextAreaElement} textarea - テキストエリア要素
     * @param {number} maxHeight - 最大高さ（px）
     */
    function autoResizeInput(textarea, maxHeight) {
        if (!textarea) return;
        if (maxHeight == null) maxHeight = 280;
        var minH = 168;
        textarea.style.setProperty('min-height', '0px', 'important');
        textarea.style.setProperty('max-height', maxHeight + 'px', 'important');
        textarea.style.setProperty('height', '0px', 'important');
        textarea.style.setProperty('overflow-y', 'hidden', 'important');
        var sh = textarea.scrollHeight;
        var newHeight = Math.min(maxHeight, Math.max(minH, sh));
        textarea.style.setProperty('height', newHeight + 'px', 'important');
        textarea.style.setProperty('min-height', minH + 'px', 'important');
        textarea.style.setProperty('max-height', maxHeight + 'px', 'important');
        textarea.style.setProperty('overflow-y', newHeight >= maxHeight ? 'auto' : 'hidden', 'important');
    }
    
    /**
     * デバウンス
     * @param {Function} fn - 実行する関数
     * @param {number} delay - 遅延時間（ms）
     * @returns {Function} デバウンスされた関数
     */
    function debounce(fn, delay = 300) {
        let timeoutId = null;
        
        return function(...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => fn.apply(this, args), delay);
        };
    }
    
    /**
     * スロットル
     * @param {Function} fn - 実行する関数
     * @param {number} limit - 間隔（ms）
     * @returns {Function} スロットルされた関数
     */
    function throttle(fn, limit = 100) {
        let lastCall = 0;
        
        return function(...args) {
            const now = Date.now();
            if (now - lastCall >= limit) {
                lastCall = now;
                return fn.apply(this, args);
            }
        };
    }
    
    /**
     * 時間フォーマット
     * @param {string|Date} date - 日付
     * @param {string} format - フォーマット（'time', 'date', 'datetime', 'relative'）
     * @returns {string} フォーマットされた時間
     */
    function formatTime(date, format = 'time') {
        if (!date) return '';
        
        const d = typeof date === 'string' ? new Date(date) : date;
        if (isNaN(d.getTime())) return '';
        
        const now = new Date();
        const isToday = d.toDateString() === now.toDateString();
        const isYesterday = new Date(now - 86400000).toDateString() === d.toDateString();
        
        const hours = d.getHours().toString().padStart(2, '0');
        const minutes = d.getMinutes().toString().padStart(2, '0');
        const timeStr = `${hours}:${minutes}`;
        
        switch (format) {
            case 'time':
                return timeStr;
                
            case 'date':
                return `${d.getFullYear()}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getDate().toString().padStart(2, '0')}`;
                
            case 'datetime':
                return `${formatTime(d, 'date')} ${timeStr}`;
                
            case 'relative':
                if (isToday) return timeStr;
                if (isYesterday) return '昨日';
                
                const diffDays = Math.floor((now - d) / 86400000);
                if (diffDays < 7) return `${diffDays}日前`;
                
                return formatTime(d, 'date');
                
            default:
                return timeStr;
        }
    }
    
    /**
     * ファイルサイズフォーマット
     * @param {number} bytes - バイト数
     * @returns {string} フォーマットされたサイズ
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        
        return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + units[i];
    }
    
    /**
     * URLからファイル名を取得
     * @param {string} url - URL
     * @returns {string} ファイル名
     */
    function getFilenameFromUrl(url) {
        if (!url) return '';
        return url.split('/').pop().split('?')[0];
    }
    
    /**
     * ファイルタイプを判定
     * @param {string} filename - ファイル名またはMIMEタイプ
     * @returns {string} 'image', 'video', 'audio', 'document', 'other'
     */
    function getFileType(filename) {
        if (!filename) return 'other';
        
        const ext = filename.split('.').pop().toLowerCase();
        
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return 'image';
        if (['mp4', 'webm', 'ogg', 'mov', 'avi'].includes(ext)) return 'video';
        if (['mp3', 'wav', 'ogg', 'aac', 'm4a'].includes(ext)) return 'audio';
        if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'].includes(ext)) return 'document';
        
        return 'other';
    }
    
    /**
     * クリップボードにコピー
     * @param {string} text - コピーするテキスト
     * @returns {Promise<boolean>} 成功したかどうか
     */
    async function copyToClipboard(text) {
        try {
            if (navigator.clipboard) {
                await navigator.clipboard.writeText(text);
                return true;
            }
            
            // フォールバック
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            return true;
        } catch (e) {
            console.error('Copy to clipboard failed:', e);
            return false;
        }
    }
    
    /**
     * 要素をスムーズにスクロール
     * @param {HTMLElement} element - スクロール対象要素
     * @param {string} position - 'top', 'center', 'bottom'
     */
    function scrollToElement(element, position = 'center') {
        if (!element) return;
        
        element.scrollIntoView({
            behavior: 'smooth',
            block: position
        });
    }
    
    /**
     * ランダムID生成
     * @param {number} length - 長さ
     * @returns {string} ランダムID
     */
    function generateId(length = 8) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }
    
    /**
     * クエリパラメータを解析
     * @param {string} url - URL（省略時はcurrent URL）
     * @returns {Object} パラメータオブジェクト
     */
    function parseQueryParams(url = window.location.href) {
        const params = {};
        const urlObj = new URL(url);
        urlObj.searchParams.forEach((value, key) => {
            params[key] = value;
        });
        return params;
    }
    
    /**
     * イベントを一度だけ実行
     * @param {HTMLElement} element - 要素
     * @param {string} eventType - イベントタイプ
     * @param {Function} handler - ハンドラ
     */
    function once(element, eventType, handler) {
        const wrapper = function(e) {
            element.removeEventListener(eventType, wrapper);
            handler.call(this, e);
        };
        element.addEventListener(eventType, wrapper);
    }
    
    /**
     * 安全なJSON解析
     * @param {string} str - JSON文字列
     * @param {*} defaultValue - デフォルト値
     * @returns {*} 解析結果
     */
    function safeJsonParse(str, defaultValue = null) {
        try {
            return JSON.parse(str);
        } catch (e) {
            return defaultValue;
        }
    }
    
    // 公開API
    Chat.utils = {
        escapeHtml,
        unescapeHtml,
        autoResizeInput,
        debounce,
        throttle,
        formatTime,
        formatFileSize,
        getFilenameFromUrl,
        getFileType,
        copyToClipboard,
        scrollToElement,
        generateId,
        parseQueryParams,
        once,
        safeJsonParse
    };
    
    // グローバルからも参照可能に（後方互換性）
    global.ChatUtils = Chat.utils;
    
})(typeof window !== 'undefined' ? window : this);
