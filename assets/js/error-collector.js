/**
 * エラー自動収集システム
 * 
 * JavaScriptエラー、未処理のPromise拒否、ネットワークエラーを自動収集
 * サーバーに送信して管理画面で確認可能
 */

(function(global) {
    'use strict';
    
    const CONFIG = {
        endpoint: 'api/error-log.php',
        maxErrors: 10,          // 1ページあたりの最大送信数
        debounceMs: 1000,       // 同じエラーの送信間隔
        ignorePatterns: [       // 無視するエラーパターン
            /^Script error\.?$/i,
            /ResizeObserver loop/i,
            /Loading chunk .* failed/i,
            /extensions\//i,
            /chrome-extension/i,
            /^\[Console\] Push: 未読数取得エラー/i,
            /^\[Console\].*Failed to get translation budget status/i
        ],
        // 失敗してもアプリがフォールバックするため、エラー収集に送らないAPI（ネットワーク障害で多発しやすい）
        optionalFetchUrlPatterns: [
            /api\/notifications\.php(\?|.*&)action=count/,
            /api\/notifications\.php(\?|.*&)action=unread_count/,
            /api\/translate\.php(\?|.*&)action=budget_status/,
            /api\/language\.php/
        ]
    };
    
    function getFetchUrl(arg) {
        if (typeof arg === 'string') return arg;
        if (arg && typeof arg.url === 'string') return arg.url;
        return '';
    }
    
    function isOptionalEndpoint(urlArg) {
        const url = getFetchUrl(urlArg);
        return url && CONFIG.optionalFetchUrlPatterns.some(function(p) { return p.test(url); });
    }
    
    let errorCount = 0;
    const sentErrors = new Set();
    
    /**
     * エラーを送信
     */
    function sendError(errorData) {
        // 送信上限チェック
        if (errorCount >= CONFIG.maxErrors) {
            return;
        }
        
        // 重複チェック
        const errorKey = errorData.message + (errorData.url || '');
        if (sentErrors.has(errorKey)) {
            return;
        }
        
        // 無視パターンチェック
        for (const pattern of CONFIG.ignorePatterns) {
            if (pattern.test(errorData.message)) {
                return;
            }
        }
        
        sentErrors.add(errorKey);
        errorCount++;
        
        // 送信
        try {
            const data = {
                type: errorData.type || 'js',
                message: errorData.message,
                stack: errorData.stack,
                url: window.location.href,
                userAgent: navigator.userAgent,
                extra: {
                    timestamp: new Date().toISOString(),
                    screenSize: `${window.innerWidth}x${window.innerHeight}`,
                    language: navigator.language,
                    ...errorData.extra
                }
            };
            
            // Beacon APIを使用（ページ離脱時も確実に送信）
            if (navigator.sendBeacon) {
                navigator.sendBeacon(
                    CONFIG.endpoint,
                    JSON.stringify(data)
                );
            } else {
                // フォールバック
                fetch(CONFIG.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                    keepalive: true
                }).catch(() => {});
            }
            
            // デバッグモードならコンソールにも出力
            if (global.Chat && global.Chat.debug && global.Chat.debug.enabled) {
                global.Chat.debug.error('ErrorCollector', 'Error sent to server', data);
            }
            
        } catch (e) {
            // エラー収集自体のエラーは無視
        }
    }
    
    /**
     * グローバルエラーハンドラー
     */
    global.onerror = function(message, source, lineno, colno, error) {
        sendError({
            type: 'js',
            message: message,
            stack: error ? error.stack : `at ${source}:${lineno}:${colno}`,
            extra: {
                source,
                lineno,
                colno
            }
        });
        
        // デフォルトのエラー処理を続行
        return false;
    };
    
    /**
     * 未処理のPromise拒否
     */
    global.addEventListener('unhandledrejection', function(event) {
        const reason = event.reason;
        let message = 'Unhandled Promise Rejection';
        let stack = '';
        
        if (reason instanceof Error) {
            message = reason.message;
            stack = reason.stack;
        } else if (typeof reason === 'string') {
            message = reason;
        } else if (reason) {
            try {
                message = JSON.stringify(reason);
            } catch (e) {
                message = String(reason);
            }
        }
        
        sendError({
            type: 'js',
            message: `[Promise] ${message}`,
            stack: stack,
            extra: {
                promiseRejection: true
            }
        });
    });
    
    /**
     * Fetchエラーのインターセプト
     */
    const originalFetch = global.fetch;
    global.fetch = function(...args) {
        return originalFetch.apply(this, args)
            .then(response => {
                // 5xxエラーを記録（オプションAPIは送信しない＝未読数・翻訳予算等はネット障害で多発するため）
                if (response.status >= 500 && !isOptionalEndpoint(args[0])) {
                    sendError({
                        type: 'api',
                        message: `API Error: ${response.status} ${response.statusText}`,
                        extra: {
                            url: args[0],
                            status: response.status
                        }
                    });
                }
                return response;
            })
            .catch(error => {
                if (!isOptionalEndpoint(args[0])) {
                    sendError({
                        type: 'api',
                        message: `Fetch Error: ${error.message}`,
                        stack: error.stack,
                        extra: {
                            url: args[0]
                        }
                    });
                }
                throw error;
            });
    };
    
    /**
     * コンソールエラーのインターセプト（オプション）
     */
    const originalConsoleError = console.error;
    console.error = function(...args) {
        // 元のconsole.errorを呼び出し
        originalConsoleError.apply(console, args);
        
        // エラーメッセージを抽出
        const message = args.map(arg => {
            if (arg instanceof Error) {
                return arg.message;
            }
            if (typeof arg === 'object') {
                try {
                    return JSON.stringify(arg);
                } catch (e) {
                    return String(arg);
                }
            }
            return String(arg);
        }).join(' ');
        
        // 特定のパターンのみ送信（全部送ると多すぎる）
        if (message.includes('Error') || message.includes('Failed') || message.includes('Exception')) {
            sendError({
                type: 'js',
                message: `[Console] ${message.substring(0, 500)}`,
                extra: {
                    consoleError: true
                }
            });
        }
    };
    
    // 公開API
    global.ErrorCollector = {
        /**
         * 手動でエラーを送信
         */
        report: function(message, extra = {}) {
            sendError({
                type: 'js',
                message: message,
                extra: extra
            });
        },
        
        /**
         * 送信済みエラー数を取得
         */
        getErrorCount: function() {
            return errorCount;
        },
        
        /**
         * リセット
         */
        reset: function() {
            errorCount = 0;
            sentErrors.clear();
        }
    };
    
    // Chat名前空間にも追加
    if (global.Chat) {
        global.Chat.errorCollector = global.ErrorCollector;
    }
    
    console.log('[ErrorCollector] Initialized - errors will be automatically reported');
    
})(typeof window !== 'undefined' ? window : this);
