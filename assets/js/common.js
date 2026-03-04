/**
 * Social9 共通JavaScript
 */

// ============================================
// ユーティリティ関数
// ============================================

/**
 * 要素を取得
 */
function $(selector) {
    return document.querySelector(selector);
}

function $$(selector) {
    return document.querySelectorAll(selector);
}

/**
 * API リクエスト
 */
async function api(endpoint, options = {}) {
    const defaults = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    const config = { ...defaults, ...options };
    
    if (config.body && typeof config.body === 'object') {
        config.body = JSON.stringify(config.body);
    }
    
    try {
        const response = await fetch(`/api/${endpoint}`, config);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'APIエラーが発生しました');
        }
        
        return data;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

/**
 * フォームデータをオブジェクトに変換
 */
function formToObject(form) {
    const formData = new FormData(form);
    const obj = {};
    for (const [key, value] of formData.entries()) {
        obj[key] = value;
    }
    return obj;
}

/**
 * 日付フォーマット
 */
function formatDate(date, format = 'YYYY/MM/DD') {
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
}

/**
 * 相対時間表示
 */
function timeAgo(date) {
    const now = new Date();
    const d = new Date(date);
    const diff = Math.floor((now - d) / 1000);
    
    if (diff < 60) return 'たった今';
    if (diff < 3600) return `${Math.floor(diff / 60)}分前`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}時間前`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}日前`;
    
    return formatDate(date);
}

/**
 * 数値をカンマ区切りでフォーマット
 */
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * HTMLエスケープ
 */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * デバウンス
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============================================
// 通知
// ============================================

const Toast = {
    container: null,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
            `;
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 3000) {
        this.init();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.style.cssText = `
            padding: 14px 20px;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            max-width: 350px;
        `;
        
        const colors = {
            success: '#2e7d32',
            error: '#d32f2f',
            warning: '#f57c00',
            info: '#1976d2'
        };
        toast.style.background = colors[type] || colors.info;
        toast.textContent = message;
        
        this.container.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
        });
        
        setTimeout(() => {
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },
    
    success(message) { this.show(message, 'success'); },
    error(message) { this.show(message, 'error'); },
    warning(message) { this.show(message, 'warning'); },
    info(message) { this.show(message, 'info'); }
};

// ============================================
// モーダル
// ============================================

const Modal = {
    open(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    },
    
    close(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    },
    
    closeAll() {
        $$('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
};

// モーダルの外側クリックで閉じる
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        Modal.closeAll();
    }
});

// ESCキーでモーダルを閉じる
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        Modal.closeAll();
    }
});

// ============================================
// フォームバリデーション
// ============================================

const Validator = {
    rules: {
        required: (value) => value.trim() !== '' || '入力してください',
        email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) || '有効なメールアドレスを入力してください',
        minLength: (min) => (value) => value.length >= min || `${min}文字以上で入力してください`,
        maxLength: (max) => (value) => value.length <= max || `${max}文字以下で入力してください`,
        match: (fieldId) => (value) => value === document.getElementById(fieldId)?.value || '値が一致しません',
        phone: (value) => /^[0-9-+()]*$/.test(value) || '有効な電話番号を入力してください'
    },
    
    validate(form) {
        let isValid = true;
        const errors = {};
        
        form.querySelectorAll('[data-validate]').forEach(input => {
            const rules = input.dataset.validate.split('|');
            const value = input.value;
            
            for (const rule of rules) {
                let validator;
                let param;
                
                if (rule.includes(':')) {
                    [rule, param] = rule.split(':');
                    validator = this.rules[rule](param);
                } else {
                    validator = this.rules[rule];
                }
                
                if (validator) {
                    const result = validator(value);
                    if (result !== true) {
                        isValid = false;
                        errors[input.name] = result;
                        input.classList.add('error');
                        
                        // エラーメッセージ表示
                        let errorEl = input.parentElement.querySelector('.form-error');
                        if (!errorEl) {
                            errorEl = document.createElement('div');
                            errorEl.className = 'form-error';
                            input.parentElement.appendChild(errorEl);
                        }
                        errorEl.textContent = result;
                        break;
                    } else {
                        input.classList.remove('error');
                        const errorEl = input.parentElement.querySelector('.form-error');
                        if (errorEl) errorEl.remove();
                    }
                }
            }
        });
        
        return { isValid, errors };
    }
};

// ============================================
// オンラインステータス
// ============================================

const OnlineStatus = {
    interval: null,
    
    start() {
        // 即座に送信
        this.send();
        
        // 1分ごとにハートビート
        this.interval = setInterval(() => this.send(), 60000);
        
        // ページ離脱時にオフライン
        window.addEventListener('beforeunload', () => this.offline());
        
        // ビジビリティ変更時
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.away();
            } else {
                this.online();
            }
        });
    },
    
    send() {
        navigator.sendBeacon('/api/status.php', JSON.stringify({ action: 'heartbeat' }));
    },
    
    online() {
        api('status.php', { method: 'POST', body: { action: 'online' } }).catch(() => {});
    },
    
    away() {
        api('status.php', { method: 'POST', body: { action: 'away' } }).catch(() => {});
    },
    
    offline() {
        navigator.sendBeacon('/api/status.php', JSON.stringify({ action: 'offline' }));
    },
    
    stop() {
        if (this.interval) {
            clearInterval(this.interval);
        }
    }
};

// ============================================
// 通知チェック
// ============================================

const NotificationChecker = {
    interval: null,
    badge: null,
    
    start(badgeSelector) {
        this.badge = $(badgeSelector);
        this.check();
        this.interval = setInterval(() => this.check(), 30000);
    },
    
    async check() {
        try {
            const data = await api('notifications.php?unread=1');
            if (this.badge) {
                this.badge.textContent = data.count || '';
                this.badge.style.display = data.count > 0 ? 'flex' : 'none';
            }
        } catch (e) {
            console.error('通知チェックエラー:', e);
        }
    },
    
    stop() {
        if (this.interval) {
            clearInterval(this.interval);
        }
    }
};

// ============================================
// 初期化
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    // フォームバリデーション自動適用
    $$('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            const { isValid } = Validator.validate(form);
            if (!isValid) {
                e.preventDefault();
            }
        });
    });
    
    // オンラインステータス開始（ログイン済みの場合）
    if (document.body.dataset.loggedIn === 'true') {
        OnlineStatus.start();
    }
});








