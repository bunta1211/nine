/**
 * デバッグモジュール
 * 
 * 開発時のログ出力、パフォーマンス計測、エラートラッキング
 * 
 * 使用例:
 * Chat.debug.log('送信', data);
 * Chat.debug.warn('警告メッセージ');
 * Chat.debug.time('処理名');
 * Chat.debug.timeEnd('処理名');
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // デバッグモード（URLパラメータまたはlocalStorageで有効化）
    const urlParams = new URLSearchParams(window.location.search);
    let debugMode = urlParams.get('debug') === '1' || 
                    localStorage.getItem('chat_debug') === '1';
    
    // ログレベル
    const LOG_LEVELS = {
        NONE: 0,
        ERROR: 1,
        WARN: 2,
        INFO: 3,
        DEBUG: 4,
        TRACE: 5
    };
    
    let currentLevel = debugMode ? LOG_LEVELS.DEBUG : LOG_LEVELS.WARN;
    
    // タイマー保存用
    const timers = new Map();
    
    // ログ履歴（最新100件）
    const logHistory = [];
    const MAX_HISTORY = 100;
    
    /**
     * ログを記録
     */
    function addToHistory(level, category, message, data) {
        logHistory.push({
            timestamp: new Date().toISOString(),
            level,
            category,
            message,
            data: data ? JSON.stringify(data).substring(0, 500) : null
        });
        
        if (logHistory.length > MAX_HISTORY) {
            logHistory.shift();
        }
    }
    
    /**
     * フォーマットされたログ出力
     */
    function formatLog(category, message) {
        const timestamp = new Date().toLocaleTimeString('ja-JP');
        return `[${timestamp}] [${category}] ${message}`;
    }
    
    /**
     * デバッグログ
     */
    function log(category, message, data) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        
        addToHistory('DEBUG', category, message, data);
        
        if (data !== undefined) {
            console.log(`%c${formatLog(category, message)}`, 'color: #6b7280', data);
        } else {
            console.log(`%c${formatLog(category, message)}`, 'color: #6b7280');
        }
    }
    
    /**
     * 情報ログ
     */
    function info(category, message, data) {
        if (currentLevel < LOG_LEVELS.INFO) return;
        
        addToHistory('INFO', category, message, data);
        
        if (data !== undefined) {
            console.info(`%c${formatLog(category, message)}`, 'color: #3b82f6', data);
        } else {
            console.info(`%c${formatLog(category, message)}`, 'color: #3b82f6');
        }
    }
    
    /**
     * 警告ログ
     */
    function warn(category, message, data) {
        if (currentLevel < LOG_LEVELS.WARN) return;
        
        addToHistory('WARN', category, message, data);
        
        if (data !== undefined) {
            console.warn(`%c${formatLog(category, message)}`, 'color: #f59e0b', data);
        } else {
            console.warn(`%c${formatLog(category, message)}`, 'color: #f59e0b');
        }
    }
    
    /**
     * エラーログ
     */
    function error(category, message, data) {
        if (currentLevel < LOG_LEVELS.ERROR) return;
        
        addToHistory('ERROR', category, message, data);
        
        if (data !== undefined) {
            console.error(`%c${formatLog(category, message)}`, 'color: #ef4444', data);
        } else {
            console.error(`%c${formatLog(category, message)}`, 'color: #ef4444');
        }
    }
    
    /**
     * トレースログ（詳細）
     */
    function trace(category, message, data) {
        if (currentLevel < LOG_LEVELS.TRACE) return;
        
        addToHistory('TRACE', category, message, data);
        console.trace(formatLog(category, message), data);
    }
    
    /**
     * タイマー開始
     */
    function time(label) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        
        timers.set(label, performance.now());
    }
    
    /**
     * タイマー終了
     */
    function timeEnd(label) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        
        const start = timers.get(label);
        if (start) {
            const duration = performance.now() - start;
            timers.delete(label);
            log('Timer', `${label}: ${duration.toFixed(2)}ms`);
            return duration;
        }
        return 0;
    }
    
    /**
     * グループログ開始
     */
    function group(label) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        console.group(label);
    }
    
    /**
     * グループログ終了
     */
    function groupEnd() {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        console.groupEnd();
    }
    
    /**
     * テーブル表示
     */
    function table(data) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        console.table(data);
    }
    
    /**
     * デバッグモードを有効化
     */
    function enable() {
        debugMode = true;
        currentLevel = LOG_LEVELS.DEBUG;
        localStorage.setItem('chat_debug', '1');
        console.log('%c[Debug] デバッグモード有効', 'color: #22c55e; font-weight: bold');
    }
    
    /**
     * デバッグモードを無効化
     */
    function disable() {
        debugMode = false;
        currentLevel = LOG_LEVELS.WARN;
        localStorage.removeItem('chat_debug');
        console.log('%c[Debug] デバッグモード無効', 'color: #ef4444');
    }
    
    /**
     * ログレベルを設定
     */
    function setLevel(level) {
        if (LOG_LEVELS[level] !== undefined) {
            currentLevel = LOG_LEVELS[level];
        } else if (typeof level === 'number') {
            currentLevel = level;
        }
    }
    
    /**
     * ログ履歴を取得
     */
    function getHistory() {
        return [...logHistory];
    }
    
    /**
     * ログ履歴をクリア
     */
    function clearHistory() {
        logHistory.length = 0;
    }
    
    /**
     * ログ履歴をダウンロード
     */
    function downloadHistory() {
        const blob = new Blob([JSON.stringify(logHistory, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `chat-debug-${new Date().toISOString().slice(0, 10)}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
    
    /**
     * 現在の状態を出力
     */
    function status() {
        console.log('%c=== Chat Debug Status ===', 'font-weight: bold');
        console.log('Debug Mode:', debugMode);
        console.log('Log Level:', currentLevel);
        console.log('History Count:', logHistory.length);
        console.log('Active Timers:', timers.size);
        
        if (Chat.config) {
            console.log('User ID:', Chat.config.userId);
            console.log('Conversation ID:', Chat.config.conversationId);
        }
    }
    
    /**
     * APIリクエストをログ
     */
    function apiRequest(endpoint, method, data) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        
        log('API', `${method} ${endpoint}`, data);
    }
    
    /**
     * APIレスポンスをログ
     */
    function apiResponse(endpoint, status, data, duration) {
        if (currentLevel < LOG_LEVELS.DEBUG) return;
        
        const color = status >= 200 && status < 300 ? '#22c55e' : '#ef4444';
        console.log(
            `%c[API Response] ${endpoint} (${status}) - ${duration}ms`,
            `color: ${color}`,
            data
        );
    }
    
    // 公開API
    Chat.debug = {
        // ログ関数
        log,
        info,
        warn,
        error,
        trace,
        
        // タイマー
        time,
        timeEnd,
        
        // グループ
        group,
        groupEnd,
        table,
        
        // 制御
        enable,
        disable,
        setLevel,
        status,
        
        // 履歴
        getHistory,
        clearHistory,
        downloadHistory,
        
        // API用
        apiRequest,
        apiResponse,
        
        // 定数
        LEVELS: LOG_LEVELS,
        
        // 状態
        get enabled() { return debugMode; },
        get level() { return currentLevel; }
    };
    
    // グローバルからもアクセス可能
    global.ChatDebug = Chat.debug;
    
    // 初期化メッセージ
    if (debugMode) {
        console.log('%c[Chat.debug] デバッグモード有効 - Chat.debug.status() で状態確認', 'color: #22c55e');
    }
    
})(typeof window !== 'undefined' ? window : this);
