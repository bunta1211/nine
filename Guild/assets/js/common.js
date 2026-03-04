/**
 * Guild 共通JavaScript
 */

// グローバル設定
const Guild = {
    baseUrl: window.location.pathname.replace(/\/[^\/]*$/, ''),
    csrfToken: null,
    language: 'ja',
    
    /**
     * 初期化
     */
    init() {
        this.initTheme();
        this.initCsrfToken();
    },
    
    /**
     * テーマ初期化
     */
    initTheme() {
        const savedTheme = localStorage.getItem('guild_theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.documentElement.classList.add('dark');
            document.cookie = 'guild_dark_mode=1; path=/; max-age=31536000';
        } else {
            document.documentElement.classList.remove('dark');
            document.cookie = 'guild_dark_mode=0; path=/; max-age=31536000';
        }
    },
    
    /**
     * テーマ切り替え
     */
    toggleTheme() {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('guild_theme', isDark ? 'dark' : 'light');
        document.cookie = `guild_dark_mode=${isDark ? '1' : '0'}; path=/; max-age=31536000`;
    },
    
    /**
     * CSRFトークン初期化
     */
    initCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            this.csrfToken = meta.getAttribute('content');
        }
    },
    
    /**
     * APIリクエスト
     */
    async api(endpoint, options = {}) {
        const url = `${this.baseUrl}/api/${endpoint}`;
        
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken || '',
            },
        };
        
        const mergedOptions = { ...defaultOptions, ...options };
        
        if (mergedOptions.body && typeof mergedOptions.body === 'object') {
            mergedOptions.body = JSON.stringify(mergedOptions.body);
        }
        
        try {
            const response = await fetch(url, mergedOptions);
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.message || 'エラーが発生しました');
            }
            
            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },
    
    /**
     * トースト通知を表示
     */
    toast(message, type = 'info', duration = 5000) {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ',
        };
        
        toast.innerHTML = `
            <div class="toast-icon">${icons[type] || icons.info}</div>
            <div class="toast-content">
                <div class="toast-message">${this.escapeHtml(message)}</div>
            </div>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },
    
    /**
     * 確認ダイアログ
     */
    async confirm(message, title = '確認') {
        return new Promise((resolve) => {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop active';
            
            const modal = document.createElement('div');
            modal.className = 'modal active';
            modal.style.width = '400px';
            modal.innerHTML = `
                <div class="modal-header">
                    <h3 class="modal-title">${this.escapeHtml(title)}</h3>
                </div>
                <div class="modal-body">
                    <p>${this.escapeHtml(message)}</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-action="cancel">キャンセル</button>
                    <button class="btn btn-primary" data-action="confirm">確認</button>
                </div>
            `;
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            
            const cleanup = () => {
                backdrop.remove();
                modal.remove();
            };
            
            modal.querySelector('[data-action="cancel"]').onclick = () => {
                cleanup();
                resolve(false);
            };
            
            modal.querySelector('[data-action="confirm"]').onclick = () => {
                cleanup();
                resolve(true);
            };
            
            backdrop.onclick = () => {
                cleanup();
                resolve(false);
            };
        });
    },
    
    /**
     * HTMLエスケープ
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },
    
    /**
     * 日付フォーマット
     */
    formatDate(date, format = 'YYYY/MM/DD') {
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        
        return format
            .replace('YYYY', year)
            .replace('MM', month)
            .replace('DD', day)
            .replace('HH', hours)
            .replace('mm', minutes);
    },
    
    /**
     * 数値をEarth形式でフォーマット
     */
    formatEarth(amount) {
        return new Intl.NumberFormat().format(amount) + ' Earth';
    },
    
    /**
     * 数値を円形式でフォーマット
     */
    formatYen(amount) {
        return '¥' + new Intl.NumberFormat().format(amount);
    },
};

// DOM読み込み完了時に初期化
document.addEventListener('DOMContentLoaded', () => {
    Guild.init();
});

// テーマ切り替えボタン
document.addEventListener('click', (e) => {
    if (e.target.closest('#theme-toggle')) {
        Guild.toggleTheme();
    }
});
