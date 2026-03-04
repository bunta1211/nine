/**
 * APIクライアントモジュール
 * 
 * 統一されたAPIリクエスト処理、エラーハンドリング、リトライ機能
 * 
 * 使用例:
 * const result = await Chat.api.post('messages.php', { action: 'send', content: 'Hello' });
 * const data = await Chat.api.get('conversations.php', { action: 'list' });
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // デフォルト設定
    const DEFAULT_CONFIG = {
        baseUrl: 'api/',
        timeout: 30000,
        retries: 2,
        retryDelay: 1000
    };
    
    let config = { ...DEFAULT_CONFIG };
    
    // エラーコード定義
    const ERROR_CODES = {
        NETWORK_ERROR: 'NETWORK_ERROR',
        TIMEOUT: 'TIMEOUT',
        UNAUTHORIZED: 'UNAUTHORIZED',
        FORBIDDEN: 'FORBIDDEN',
        NOT_FOUND: 'NOT_FOUND',
        SERVER_ERROR: 'SERVER_ERROR',
        PARSE_ERROR: 'PARSE_ERROR',
        UNKNOWN: 'UNKNOWN'
    };
    
    // エラーメッセージ（日本語）
    const ERROR_MESSAGES = {
        NETWORK_ERROR: 'ネットワークエラーが発生しました',
        TIMEOUT: 'リクエストがタイムアウトしました',
        UNAUTHORIZED: 'ログインが必要です',
        FORBIDDEN: 'アクセス権限がありません',
        NOT_FOUND: 'リソースが見つかりません',
        SERVER_ERROR: 'サーバーエラーが発生しました',
        PARSE_ERROR: 'レスポンスの解析に失敗しました',
        UNKNOWN: '予期しないエラーが発生しました'
    };
    
    /**
     * APIエラークラス
     */
    class ApiError extends Error {
        constructor(code, message, status, data) {
            super(message);
            this.name = 'ApiError';
            this.code = code;
            this.status = status;
            this.data = data;
        }
    }
    
    /**
     * 設定を初期化
     */
    function init(options = {}) {
        config = { ...config, ...options };
    }
    
    /**
     * HTTPステータスからエラーコードを取得
     */
    function getErrorCode(status) {
        if (status === 401) return ERROR_CODES.UNAUTHORIZED;
        if (status === 403) return ERROR_CODES.FORBIDDEN;
        if (status === 404) return ERROR_CODES.NOT_FOUND;
        if (status >= 500) return ERROR_CODES.SERVER_ERROR;
        return ERROR_CODES.UNKNOWN;
    }
    
    /**
     * URLを構築
     */
    function buildUrl(endpoint, params = {}) {
        const url = new URL(endpoint, window.location.origin + '/' + config.baseUrl);
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                url.searchParams.append(key, value);
            }
        });
        return url.toString();
    }
    
    /**
     * リクエストを実行
     */
    async function request(endpoint, options = {}) {
        const {
            method = 'GET',
            params = {},
            body = null,
            headers = {},
            timeout = config.timeout,
            retries = config.retries
        } = options;
        
        const url = method === 'GET' ? buildUrl(endpoint, params) : buildUrl(endpoint);
        const startTime = performance.now();
        
        // デバッグログ
        if (Chat.debug && Chat.debug.enabled) {
            Chat.debug.apiRequest(endpoint, method, body || params);
        }
        
        // タイムアウト用AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        
        const fetchOptions = {
            method,
            headers: {
                'Accept': 'application/json',
                ...headers
            },
            signal: controller.signal,
            credentials: 'same-origin'
        };
        
        // ボディを設定
        if (body && method !== 'GET') {
            if (body instanceof FormData) {
                fetchOptions.body = body;
            } else {
                fetchOptions.headers['Content-Type'] = 'application/json';
                fetchOptions.body = JSON.stringify(body);
            }
        }
        
        let lastError;
        
        for (let attempt = 0; attempt <= retries; attempt++) {
            try {
                const response = await fetch(url, fetchOptions);
                clearTimeout(timeoutId);
                
                const duration = performance.now() - startTime;
                
                // レスポンスを解析
                let data;
                const contentType = response.headers.get('content-type') || '';
                
                if (contentType.includes('application/json')) {
                    try {
                        data = await response.json();
                    } catch (e) {
                        throw new ApiError(
                            ERROR_CODES.PARSE_ERROR,
                            ERROR_MESSAGES.PARSE_ERROR,
                            response.status,
                            null
                        );
                    }
                } else {
                    data = await response.text();
                }
                
                // デバッグログ
                if (Chat.debug && Chat.debug.enabled) {
                    Chat.debug.apiResponse(endpoint, response.status, data, duration.toFixed(0));
                }
                
                // エラーレスポンスのチェック
                if (!response.ok) {
                    const errorCode = getErrorCode(response.status);
                    const errorMessage = (data && data.error) || ERROR_MESSAGES[errorCode];
                    throw new ApiError(errorCode, errorMessage, response.status, data);
                }
                
                // APIレスポンスの success: false チェック
                if (data && typeof data === 'object' && data.success === false) {
                    throw new ApiError(
                        ERROR_CODES.UNKNOWN,
                        data.error || data.message || 'リクエストが失敗しました',
                        response.status,
                        data
                    );
                }
                
                return data;
                
            } catch (error) {
                clearTimeout(timeoutId);
                lastError = error;
                
                // AbortErrorはタイムアウト
                if (error.name === 'AbortError') {
                    lastError = new ApiError(
                        ERROR_CODES.TIMEOUT,
                        ERROR_MESSAGES.TIMEOUT,
                        0,
                        null
                    );
                }
                
                // ネットワークエラー
                if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    lastError = new ApiError(
                        ERROR_CODES.NETWORK_ERROR,
                        ERROR_MESSAGES.NETWORK_ERROR,
                        0,
                        null
                    );
                }
                
                // リトライ不可のエラー
                if (lastError.code === ERROR_CODES.UNAUTHORIZED ||
                    lastError.code === ERROR_CODES.FORBIDDEN) {
                    break;
                }
                
                // リトライ待機
                if (attempt < retries) {
                    await new Promise(r => setTimeout(r, config.retryDelay * (attempt + 1)));
                }
            }
        }
        
        throw lastError;
    }
    
    /**
     * GETリクエスト
     */
    function get(endpoint, params = {}, options = {}) {
        return request(endpoint, { ...options, method: 'GET', params });
    }
    
    /**
     * POSTリクエスト
     */
    function post(endpoint, body = {}, options = {}) {
        return request(endpoint, { ...options, method: 'POST', body });
    }
    
    /**
     * POSTリクエスト（FormData用）
     */
    function postForm(endpoint, formData, options = {}) {
        return request(endpoint, { ...options, method: 'POST', body: formData });
    }
    
    /**
     * POSTリクエスト（URL encoded用）
     */
    async function postUrlEncoded(endpoint, data = {}, options = {}) {
        const body = new URLSearchParams();
        Object.entries(data).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        
        return request(endpoint, {
            ...options,
            method: 'POST',
            body: body,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                ...options.headers
            }
        });
    }
    
    /**
     * エラーを処理してユーザーに表示
     */
    function handleError(error, showToast = true) {
        let message = error.message || ERROR_MESSAGES.UNKNOWN;
        
        // 未認証エラーの場合はログインページにリダイレクト
        if (error.code === ERROR_CODES.UNAUTHORIZED) {
            if (confirm('セッションが切れました。ログインページに移動しますか？')) {
                window.location.href = 'index.php';
            }
            return;
        }
        
        // トースト表示
        if (showToast && Chat.ui && Chat.ui.toast) {
            Chat.ui.toast(message, 'error');
        } else if (showToast) {
            alert(message);
        }
        
        // デバッグログ
        if (Chat.debug) {
            Chat.debug.error('API', error.message, error);
        }
    }
    
    // 公開API
    Chat.api = {
        init,
        request,
        get,
        post,
        postForm,
        postUrlEncoded,
        handleError,
        
        // エラー関連
        ApiError,
        ERROR_CODES,
        ERROR_MESSAGES
    };
    
    // グローバルからもアクセス可能
    global.ChatApi = Chat.api;
    
})(typeof window !== 'undefined' ? window : this);
