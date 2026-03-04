/**
 * PWAインストールプロンプト
 * 
 * - Android/Chrome: beforeinstallprompt イベントを使用
 * - iOS Safari: 手動手順の説明モーダルを表示
 */

(function() {
    'use strict';
    
    // 設定
    const STORAGE_KEY = 'pwa_install_dismissed';
    const DISMISS_DAYS = 7; // PC: 閉じた後、再表示するまでの日数
    const DISMISS_DAYS_MOBILE = 0.5; // モバイル: 12時間後に再表示
    
    // 状態
    let deferredPrompt = null;
    let bannerElement = null;
    let iosModalElement = null;
    
    // ========== ユーティリティ ==========
    
    // スタンドアロンモード（既にインストール済み）かチェック
    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true ||
               document.referrer.includes('android-app://');
    }
    
    // iOS Safari かチェック
    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }
    
    // モバイルかチェック
    function isMobile() {
        return isIOS() || /Android|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 768;
    }
    
    // 閉じた記録を確認（モバイルは短い間隔で再表示）
    function isDismissed() {
        const dismissed = localStorage.getItem(STORAGE_KEY);
        if (!dismissed) return false;
        
        const dismissedTime = parseInt(dismissed, 10);
        const daysPassed = (Date.now() - dismissedTime) / (1000 * 60 * 60 * 24);
        const daysThreshold = isMobile() ? DISMISS_DAYS_MOBILE : DISMISS_DAYS;
        
        return daysPassed < daysThreshold;
    }
    
    // 閉じた記録を保存
    function setDismissed() {
        localStorage.setItem(STORAGE_KEY, Date.now().toString());
    }
    
    // ========== UI作成 ==========
    
    // インストールバナーを作成
    function createBanner() {
        const banner = document.createElement('div');
        banner.className = 'pwa-install-banner';
        banner.id = 'pwaInstallBanner';
        banner.innerHTML = `
            <img src="assets/icons/icon.svg" alt="Social9" class="pwa-icon">
            <div class="pwa-content">
                <div class="pwa-title">ホームにアイコンを追加</div>
                <div class="pwa-desc">アプリのようにすぐアクセスでき、通知も届きやすくなります</div>
            </div>
            <div class="pwa-buttons">
                <button type="button" class="pwa-btn pwa-btn-install" id="pwaInstallBtn">追加</button>
                <button type="button" class="pwa-btn pwa-btn-close" id="pwaCloseBannerBtn">✕</button>
            </div>
        `;
        document.body.appendChild(banner);
        const installBtn = banner.querySelector('#pwaInstallBtn');
        const closeBtn = banner.querySelector('#pwaCloseBannerBtn');
        if (installBtn) installBtn.addEventListener('click', function(e) { e.preventDefault(); window.pwaInstall(); });
        if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); pwaCloseBanner(); });
        return banner;
    }
    
    // iOS用モーダルを作成
    function createIOSModal() {
        const modal = document.createElement('div');
        modal.className = 'pwa-ios-modal';
        modal.id = 'pwaIOSModal';
        modal.innerHTML = `
            <div class="pwa-ios-content">
                <div class="pwa-ios-icon">🍀</div>
                <div class="pwa-ios-title">ホーム画面に追加</div>
                <div class="pwa-ios-steps">
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">1</span>
                        <span class="pwa-step-text">画面下の<span class="icon">□↑</span>共有ボタンをタップ</span>
                    </div>
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">2</span>
                        <span class="pwa-step-text">スクロールして<br>「ホーム画面に追加」をタップ</span>
                    </div>
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">3</span>
                        <span class="pwa-step-text">右上の「追加」をタップ</span>
                    </div>
                </div>
                <button class="pwa-ios-close" onclick="pwaCloseIOSModal()">閉じる</button>
            </div>
        `;
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                pwaCloseIOSModal();
            }
        });
        document.body.appendChild(modal);
        return modal;
    }
    
    // ========== 表示制御 ==========
    
    // バナーを表示
    function showBanner() {
        if (!bannerElement) {
            bannerElement = createBanner();
        }
        // 少し遅延してアニメーション
        setTimeout(() => {
            bannerElement.classList.add('show');
        }, 500);
    }
    
    // バナーを非表示
    window.pwaCloseBanner = function() {
        if (bannerElement) {
            bannerElement.classList.remove('show');
            setDismissed();
        }
    };
    
    // iOSモーダルを表示
    function showIOSModal() {
        if (!iosModalElement) {
            iosModalElement = createIOSModal();
        }
        iosModalElement.classList.add('show');
    }
    
    // iOSモーダルを非表示
    window.pwaCloseIOSModal = function() {
        if (iosModalElement) {
            iosModalElement.classList.remove('show');
            setDismissed();
        }
    };
    
    // ========== インストール処理 ==========
    
    // Android用の手動手順モーダル（「ホーム画面に追加」で統一）
    function createAndroidManualModal() {
        if (document.getElementById('pwaAndroidModal')) return;
        const modal = document.createElement('div');
        modal.className = 'pwa-ios-modal';
        modal.id = 'pwaAndroidModal';
        modal.innerHTML = `
            <div class="pwa-ios-content">
                <div class="pwa-ios-icon">🏠</div>
                <div class="pwa-ios-title">ホーム画面に追加</div>
                <div class="pwa-ios-steps">
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">1</span>
                        <span class="pwa-step-text">画面右上の<span class="icon">⋮</span>メニューをタップ</span>
                    </div>
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">2</span>
                        <span class="pwa-step-text">「ホーム画面に追加」または「アプリをインストール」をタップ</span>
                    </div>
                    <div class="pwa-ios-step">
                        <span class="pwa-step-num">3</span>
                        <span class="pwa-step-text">「追加」または「インストール」をタップして完了</span>
                    </div>
                </div>
                <button type="button" class="pwa-ios-close" id="pwaAndroidModalClose">閉じる</button>
            </div>
        `;
        modal.addEventListener('click', function(e) {
            if (e.target === modal) pwaCloseAndroidModal();
        });
        document.body.appendChild(modal);
        const closeBtn = modal.querySelector('#pwaAndroidModalClose');
        if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); pwaCloseAndroidModal(); });
    }
    
    window.pwaCloseAndroidModal = function() {
        const m = document.getElementById('pwaAndroidModal');
        if (m) m.classList.remove('show');
    };
    
    // インストール実行（Android/Chrome）
    window.pwaInstall = function() {
        if (isIOS()) {
            // iOSの場合は手順モーダルを表示
            if (bannerElement) {
                bannerElement.classList.remove('show');
            }
            showIOSModal();
            return;
        }
        
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((result) => {
                console.log('PWA install result:', result.outcome);
                if (result.outcome === 'accepted') {
                    if (bannerElement) {
                        bannerElement.classList.remove('show');
                    }
                }
                deferredPrompt = null;
            });
        } else {
            // beforeinstallprompt が発火していない場合：手動手順を表示
            if (bannerElement) {
                bannerElement.classList.remove('show');
            }
            createAndroidManualModal();
            const m = document.getElementById('pwaAndroidModal');
            if (m) {
                m.classList.add('show');
            }
        }
    };
    
    // ========== 初期化 ==========
    
    function init() {
        // 既にインストール済みの場合は何もしない
        if (isStandalone()) {
            console.log('PWA: Already installed');
            return;
        }
        
        // 閉じた記録がある場合は何もしない（モバイルは1日で再表示）
        if (isDismissed()) {
            console.log('PWA: Banner dismissed');
            return;
        }
        
        // iOS のみタイマーでバナー表示（beforeinstallprompt は発火しないため）
        // Android/Chrome/PC: beforeinstallprompt 発火時のみ表示 → 追加クリックでネイティブプロンプトを直接実行
        if (isIOS()) {
            const delay = 2000;
            setTimeout(function() {
                if (!isStandalone() && !isDismissed()) {
                    showBanner();
                }
            }, delay);
        }
        
        // Service Worker を登録
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then((reg) => {
                    console.log('Service Worker registered:', reg.scope);
                })
                .catch((err) => {
                    console.log('Service Worker registration failed:', err);
                });
        }
        
        // beforeinstallprompt イベントをリッスン（Android/Chrome）
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('PWA: beforeinstallprompt fired');
            e.preventDefault();
            deferredPrompt = e;
            showBanner();
        });
        
        // iOS は beforeinstallprompt が無いため、init内のモバイル処理で2秒後に表示
        
        // インストール完了イベント
        window.addEventListener('appinstalled', () => {
            console.log('PWA: App installed');
            if (bannerElement) {
                bannerElement.classList.remove('show');
            }
            deferredPrompt = null;
        });
    }
    
    // DOMContentLoaded で初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
