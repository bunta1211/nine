/**
 * 遅延読み込みローダー
 * 
 * 必要なときにのみJavaScriptやCSSを読み込む
 * 
 * 使用例:
 * Chat.lazyLoader.loadScript('path/to/script.js');
 * Chat.lazyLoader.loadCSS('path/to/style.css');
 * Chat.lazyLoader.loadModule('call');
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 読み込み済みリソースを追跡
    const loadedScripts = new Set();
    const loadedCSS = new Set();
    const loadingPromises = new Map();
    
    // モジュール定義
    const modules = {
        // 通話機能
        call: {
            scripts: ['assets/js/chat/call.js'],
            css: [],
            init: () => Chat.call && Chat.call.init()
        },
        
        // 翻訳機能
        translation: {
            scripts: ['assets/js/chat/translation.js'],
            css: [],
            init: () => Chat.translation && Chat.translation.init()
        },
        
        // GIF検索
        gif: {
            scripts: [],
            css: ['assets/css/components/gif-picker.css'],
            init: null
        },
        
        // 絵文字ピッカー
        emoji: {
            scripts: [],
            css: ['assets/css/components/emoji-picker.css'],
            init: null
        },
        
        // 画像プレビュー
        imagePreview: {
            scripts: [],
            css: ['assets/css/components/image-preview.css'],
            init: null
        },
        
        // QRコード
        qrcode: {
            scripts: ['https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js'],
            css: [],
            init: null
        }
    };
    
    /**
     * スクリプトを読み込み
     * @param {string} src - スクリプトURL
     * @returns {Promise<void>}
     */
    function loadScript(src) {
        // 既に読み込み済み
        if (loadedScripts.has(src)) {
            return Promise.resolve();
        }
        
        // 読み込み中
        if (loadingPromises.has(src)) {
            return loadingPromises.get(src);
        }
        
        const promise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            
            script.onload = () => {
                loadedScripts.add(src);
                loadingPromises.delete(src);
                console.log('[LazyLoader] Script loaded:', src);
                resolve();
            };
            
            script.onerror = () => {
                loadingPromises.delete(src);
                console.error('[LazyLoader] Failed to load script:', src);
                reject(new Error(`Failed to load: ${src}`));
            };
            
            document.head.appendChild(script);
        });
        
        loadingPromises.set(src, promise);
        return promise;
    }
    
    /**
     * CSSを読み込み
     * @param {string} href - CSS URL
     * @returns {Promise<void>}
     */
    function loadCSS(href) {
        // 既に読み込み済み
        if (loadedCSS.has(href)) {
            return Promise.resolve();
        }
        
        // 読み込み中
        if (loadingPromises.has(href)) {
            return loadingPromises.get(href);
        }
        
        const promise = new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            
            link.onload = () => {
                loadedCSS.add(href);
                loadingPromises.delete(href);
                console.log('[LazyLoader] CSS loaded:', href);
                resolve();
            };
            
            link.onerror = () => {
                loadingPromises.delete(href);
                console.error('[LazyLoader] Failed to load CSS:', href);
                reject(new Error(`Failed to load: ${href}`));
            };
            
            document.head.appendChild(link);
        });
        
        loadingPromises.set(href, promise);
        return promise;
    }
    
    /**
     * モジュールを読み込み
     * @param {string} moduleName - モジュール名
     * @returns {Promise<void>}
     */
    async function loadModule(moduleName) {
        const module = modules[moduleName];
        
        if (!module) {
            console.warn('[LazyLoader] Unknown module:', moduleName);
            return;
        }
        
        console.log('[LazyLoader] Loading module:', moduleName);
        
        const promises = [];
        
        // スクリプトを読み込み
        for (const src of module.scripts) {
            promises.push(loadScript(src));
        }
        
        // CSSを読み込み
        for (const href of module.css) {
            promises.push(loadCSS(href));
        }
        
        await Promise.all(promises);
        
        // 初期化
        if (module.init) {
            module.init();
        }
        
        console.log('[LazyLoader] Module loaded:', moduleName);
    }
    
    /**
     * 複数モジュールを読み込み
     * @param {string[]} moduleNames - モジュール名の配列
     * @returns {Promise<void>}
     */
    async function loadModules(moduleNames) {
        await Promise.all(moduleNames.map(name => loadModule(name)));
    }
    
    /**
     * モジュールが読み込み済みか確認
     * @param {string} moduleName - モジュール名
     * @returns {boolean}
     */
    function isModuleLoaded(moduleName) {
        const module = modules[moduleName];
        if (!module) return false;
        
        // 全てのスクリプトとCSSが読み込み済みか確認
        const scriptsLoaded = module.scripts.every(src => loadedScripts.has(src));
        const cssLoaded = module.css.every(href => loadedCSS.has(href));
        
        return scriptsLoaded && cssLoaded;
    }
    
    /**
     * モジュールを登録
     * @param {string} name - モジュール名
     * @param {Object} config - モジュール設定
     */
    function registerModule(name, config) {
        modules[name] = {
            scripts: config.scripts || [],
            css: config.css || [],
            init: config.init || null
        };
    }
    
    /**
     * 画像の遅延読み込みを設定
     */
    function setupImageLazyLoading() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        observer.unobserve(img);
                    }
                });
            }, {
                rootMargin: '50px 0px',
                threshold: 0.01
            });
            
            // data-src属性を持つ画像を監視
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
            
            return imageObserver;
        } else {
            // フォールバック: すべての画像を即座に読み込む
            document.querySelectorAll('img[data-src]').forEach(img => {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
            });
            return null;
        }
    }
    
    /**
     * コンポーネントの遅延読み込みを設定
     * @param {string} selector - 対象要素のセレクタ
     * @param {string} moduleName - 読み込むモジュール名
     */
    function setupComponentLazyLoading(selector, moduleName) {
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        loadModule(moduleName);
                        observer.disconnect();
                    }
                });
            }, {
                rootMargin: '100px 0px',
                threshold: 0.01
            });
            
            const element = document.querySelector(selector);
            if (element) {
                observer.observe(element);
            }
            
            return observer;
        }
        return null;
    }
    
    /**
     * プリロード（ヒント）
     * @param {string} src - リソースURL
     * @param {string} as - リソースタイプ ('script', 'style', 'image')
     */
    function preload(src, as = 'script') {
        const link = document.createElement('link');
        link.rel = 'preload';
        link.href = src;
        link.as = as;
        document.head.appendChild(link);
    }
    
    // 公開API
    Chat.lazyLoader = {
        loadScript,
        loadCSS,
        loadModule,
        loadModules,
        isModuleLoaded,
        registerModule,
        setupImageLazyLoading,
        setupComponentLazyLoading,
        preload
    };
    
    // グローバルからもアクセス可能
    global.LazyLoader = Chat.lazyLoader;
    
})(typeof window !== 'undefined' ? window : this);
