/**
 * チャットモジュール エントリーポイント
 * 
 * 全てのサブモジュールを初期化し、グローバルAPIを提供
 * 
 * 読み込み順序:
 * 1. config.js - 設定
 * 2. utils.js - ユーティリティ
 * 3. to-selector.js - TO機能
 * 4. reactions.js - リアクション
 * 5. tasks.js - タスク・メモ
 * 6. messages.js - メッセージ送受信
 * 7. translation.js - 翻訳
 * 8. media.js - メディア管理
 * 9. polling.js - ポーリング
 * 10. call.js - 通話
 * 11. index.js - このファイル（初期化）
 * 
 * 使用例:
 * Chat.init({ userId: 1, conversationId: 123 });
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // バージョン
    Chat.version = '1.0.0';
    
    // 初期化済みフラグ
    let initialized = false;
    
    /**
     * チャットシステムを初期化
     * @param {Object} options - 初期化オプション
     */
    function init(options = {}) {
        if (initialized) {
            console.warn('[Chat] Already initialized');
            return;
        }
        
        console.log('[Chat] Initializing v' + Chat.version);
        
        // 設定を初期化
        if (Chat.config && Chat.config.init) {
            Chat.config.init(options);
        }
        
        // TO機能を初期化
        if (Chat.toSelector && Chat.toSelector.init) {
            Chat.toSelector.init({
                members: options.members || [],
                currentUserId: options.userId || 0
            });
        }
        
        // 将来のモジュール初期化をここに追加
        // if (Chat.reactions && Chat.reactions.init) { ... }
        // if (Chat.messages && Chat.messages.init) { ... }
        
        initialized = true;
        console.log('[Chat] Initialization complete');
        
        // 初期化完了イベントを発火
        document.dispatchEvent(new CustomEvent('chat:initialized', { detail: options }));
    }
    
    /**
     * 初期化済みかどうか
     * @returns {boolean}
     */
    function isInitialized() {
        return initialized;
    }
    
    /**
     * モジュールを登録
     * @param {string} name - モジュール名
     * @param {Object} module - モジュールオブジェクト
     */
    function registerModule(name, module) {
        if (Chat[name]) {
            console.warn('[Chat] Module already exists:', name);
            return;
        }
        
        Chat[name] = module;
        console.log('[Chat] Module registered:', name);
    }
    
    // 公開API
    Chat.init = init;
    Chat.isInitialized = isInitialized;
    Chat.registerModule = registerModule;
    
    // DOMContentLoaded時に自動初期化（window.ChatInitOptionsがある場合）
    document.addEventListener('DOMContentLoaded', function() {
        if (global.ChatInitOptions && !initialized) {
            init(global.ChatInitOptions);
        }
    });
    
})(typeof window !== 'undefined' ? window : this);
