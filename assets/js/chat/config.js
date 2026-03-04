/**
 * チャット設定モジュール
 * 
 * PHP変数をJavaScriptで使用するための設定管理
 * scripts.phpの先頭でwindow.ChatConfigに値が設定される
 * 
 * 使用例:
 * const userId = Chat.config.userId;
 * const lang = Chat.config.lang;
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を作成
    global.Chat = global.Chat || {};
    
    /**
     * 設定オブジェクト
     * scripts.phpから値が設定される
     */
    const defaultConfig = {
        // ユーザー情報
        userId: 0,
        displayName: '',
        role: 'user',
        
        // 会話情報
        conversationId: null,
        conversationType: 'dm',
        
        // 言語設定
        lang: 'ja',
        
        // 翻訳テキスト
        i18n: {
            showLess: '少なく表示',
            showMore: '他 %d 件を表示',
            recentSearch: '最近の検索',
            noSearchHistory: '検索履歴がありません',
            searchHint: 'メッセージ・ユーザー・グループを検索',
            searchResults: '検索結果',
            searching: '検索中...',
            save: '保存',
            edit: '編集',
            saving: '保存中...',
            saved: '保存しました',
            cancel: 'キャンセル',
            delete: '削除',
            confirm: '確認',
            error: 'エラー',
            success: '成功',
            loading: '読み込み中...',
            noMessages: 'メッセージがありません',
            sendMessage: 'メッセージを送信',
            typeMessage: 'メッセージを入力...',
            editMessage: 'メッセージを編集中',
            replyTo: '返信先',
            toAll: '全員',
            toSelected: '選択したメンバー'
        },
        
        // API エンドポイント
        api: {
            messages: 'api/messages.php',
            conversations: 'api/conversations.php',
            users: 'api/users.php',
            translate: 'api/translate.php',
            status: 'api/status.php',
            tasks: 'api/tasks.php',
            memos: 'api/tasks.php',
            upload: 'api/upload.php',
            notifications: 'api/notifications.php'
        },
        
        // ポーリング設定
        polling: {
            messageInterval: 3000,      // メッセージポーリング間隔（ms）
            conversationInterval: 10000, // 会話リストポーリング間隔（ms）
            heartbeatInterval: 120000,  // ハートビート間隔（2分）
            inactiveMultiplier: 3       // 非アクティブ時の間隔倍率
        },
        
        // UI設定
        ui: {
            maxMessageLength: 10000,
            maxFileSize: 10 * 1024 * 1024, // 10MB
            allowedFileTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'],
            autoResizeMaxHeight: 180     // テキストエリアの最大高さ（px）。携帯はJSで180に上書き
        },
        
        // 機能フラグ
        features: {
            translation: true,
            reactions: true,
            calls: true,
            tasks: true,
            memos: true,
            mediaUpload: true
        }
    };
    
    // 設定オブジェクト（マージ用）
    let config = { ...defaultConfig };
    
    /**
     * 設定を初期化
     * @param {Object} options - PHP から渡される設定
     */
    function init(options) {
        if (options) {
            // 深いマージ
            config = deepMerge(config, options);
        }
        
        console.log('[Chat.config] Initialized', {
            userId: config.userId,
            conversationId: config.conversationId,
            lang: config.lang
        });
    }
    
    /**
     * 深いマージ
     */
    function deepMerge(target, source) {
        const result = { ...target };
        for (const key in source) {
            if (source.hasOwnProperty(key)) {
                if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                    result[key] = deepMerge(result[key] || {}, source[key]);
                } else {
                    result[key] = source[key];
                }
            }
        }
        return result;
    }
    
    /**
     * 設定値を取得
     * @param {string} path - ドット区切りのパス（例: 'api.messages'）
     * @param {*} defaultValue - デフォルト値
     */
    function get(path, defaultValue = null) {
        const keys = path.split('.');
        let value = config;
        
        for (const key of keys) {
            if (value && typeof value === 'object' && key in value) {
                value = value[key];
            } else {
                return defaultValue;
            }
        }
        
        return value;
    }
    
    /**
     * 設定値を設定
     * @param {string} path - ドット区切りのパス
     * @param {*} value - 値
     */
    function set(path, value) {
        const keys = path.split('.');
        let obj = config;
        
        for (let i = 0; i < keys.length - 1; i++) {
            const key = keys[i];
            if (!(key in obj)) {
                obj[key] = {};
            }
            obj = obj[key];
        }
        
        obj[keys[keys.length - 1]] = value;
    }
    
    /**
     * 翻訳テキストを取得
     * @param {string} key - 翻訳キー
     * @param {Object} params - パラメータ（%s, %d などを置換）
     */
    function t(key, params = {}) {
        let text = config.i18n[key] || key;
        
        // パラメータ置換
        if (typeof params === 'number') {
            text = text.replace('%d', params);
        } else if (typeof params === 'string') {
            text = text.replace('%s', params);
        } else if (typeof params === 'object') {
            for (const [k, v] of Object.entries(params)) {
                text = text.replace(new RegExp(`%${k}`, 'g'), v);
            }
        }
        
        return text;
    }
    
    // 公開API
    Chat.config = {
        init: init,
        get: get,
        set: set,
        t: t,
        
        // 直接アクセス用のゲッター
        get userId() { return config.userId; },
        get displayName() { return config.displayName; },
        get conversationId() { return config.conversationId; },
        get conversationType() { return config.conversationType; },
        get lang() { return config.lang; },
        get api() { return config.api; },
        get polling() { return config.polling; },
        get ui() { return config.ui; },
        get features() { return config.features; },
        get i18n() { return config.i18n; }
    };
    
    // グローバルからも参照可能に（後方互換性）
    global.ChatConfig = Chat.config;
    
})(typeof window !== 'undefined' ? window : this);
