/**
 * Social9 Web Push Notifications
 * プッシュ通知の購読管理
 */

const PushNotifications = {
    // 状態
    isSupported: false,
    isSubscribed: false,
    subscription: null,
    vapidPublicKey: null,
    
    /**
     * 初期化
     * @param {string} vapidPublicKey - VAPID公開鍵
     */
    async init(vapidPublicKey) {
        this.vapidPublicKey = vapidPublicKey;
        
        // ブラウザサポートチェック
        if (!('serviceWorker' in navigator)) {
            console.log('Push: Service Workerがサポートされていません');
            return false;
        }
        
        if (!('PushManager' in window)) {
            console.log('Push: Push APIがサポートされていません');
            return false;
        }
        
        if (!('Notification' in window)) {
            console.log('Push: Notification APIがサポートされていません');
            return false;
        }
        
        this.isSupported = true;
        
        // 現在の購読状態を確認
        try {
            const registration = await navigator.serviceWorker.ready;
            this.subscription = await registration.pushManager.getSubscription();
            this.isSubscribed = this.subscription !== null;
            
            console.log('Push: 初期化完了', {
                isSupported: this.isSupported,
                isSubscribed: this.isSubscribed,
                permission: Notification.permission
            });
            
            // UIを更新
            this.updateUI();
            
            return true;
        } catch (error) {
            console.error('Push: 初期化エラー', error);
            return false;
        }
    },
    
    /**
     * 通知許可をリクエスト
     * @returns {Promise<string>} - 許可状態 ('granted', 'denied', 'default')
     */
    async requestPermission() {
        if (!this.isSupported) {
            return 'unsupported';
        }
        
        const permission = await Notification.requestPermission();
        console.log('Push: 許可状態', permission);
        
        if (permission === 'granted') {
            // 許可されたら購読を開始
            await this.subscribe();
        }
        
        this.updateUI();
        return permission;
    },
    
    /**
     * プッシュ通知を購読
     */
    async subscribe() {
        if (!this.isSupported || !this.vapidPublicKey) {
            console.error('Push: サポートされていないか、VAPID公開鍵がありません');
            return false;
        }
        
        try {
            const registration = await navigator.serviceWorker.ready;
            
            // 既存の購読があれば解除
            const existingSubscription = await registration.pushManager.getSubscription();
            if (existingSubscription) {
                await existingSubscription.unsubscribe();
            }
            
            // 新しい購読を作成
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
            });
            
            console.log('Push: 購読成功', subscription);
            
            // サーバーに購読情報を送信
            const saved = await this.saveSubscription(subscription);
            
            if (saved) {
                this.subscription = subscription;
                this.isSubscribed = true;
                this.updateUI();
                return true;
            }
            
            return false;
        } catch (error) {
            console.error('Push: 購読エラー', error);
            
            if (Notification.permission === 'denied') {
                console.log('Push: 通知が拒否されています');
            }
            
            return false;
        }
    },
    
    /**
     * プッシュ通知の購読を解除
     */
    async unsubscribe() {
        if (!this.subscription) {
            return true;
        }
        
        try {
            // サーバーから購読情報を削除
            await this.deleteSubscription(this.subscription);
            
            // ブラウザの購読を解除
            await this.subscription.unsubscribe();
            
            this.subscription = null;
            this.isSubscribed = false;
            this.updateUI();
            
            console.log('Push: 購読解除完了');
            return true;
        } catch (error) {
            console.error('Push: 購読解除エラー', error);
            return false;
        }
    },
    
    /**
     * 購読情報をサーバーに保存
     */
    async saveSubscription(subscription) {
        try {
            const response = await fetch('api/push.php?action=subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    subscription: subscription.toJSON()
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('Push: サーバーへの保存成功');
                return true;
            } else {
                console.error('Push: サーバーへの保存失敗', result.message);
                return false;
            }
        } catch (error) {
            console.error('Push: サーバー通信エラー', error);
            return false;
        }
    },
    
    /**
     * 購読情報をサーバーから削除
     */
    async deleteSubscription(subscription) {
        try {
            const response = await fetch('api/push.php?action=unsubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: subscription.endpoint
                })
            });
            
            const result = await response.json();
            return result.success;
        } catch (error) {
            console.error('Push: 購読削除エラー', error);
            return false;
        }
    },
    
    /**
     * UIを更新
     */
    updateUI() {
        const enableBtn = document.getElementById('pushEnableBtn');
        const disableBtn = document.getElementById('pushDisableBtn');
        const statusText = document.getElementById('pushStatus');
        const pushToggle = document.getElementById('pushNotificationToggle');
        
        if (enableBtn) {
            enableBtn.style.display = this.isSubscribed ? 'none' : 'inline-block';
        }
        
        if (disableBtn) {
            disableBtn.style.display = this.isSubscribed ? 'inline-block' : 'none';
        }
        
        if (statusText) {
            if (!this.isSupported) {
                statusText.textContent = 'このブラウザはプッシュ通知に対応していません';
                statusText.className = 'push-status unsupported';
            } else if (Notification.permission === 'denied') {
                statusText.textContent = '通知がブロックされています。ブラウザ設定から許可してください';
                statusText.className = 'push-status denied';
            } else if (this.isSubscribed) {
                statusText.textContent = 'プッシュ通知が有効です';
                statusText.className = 'push-status enabled';
            } else {
                statusText.textContent = 'プッシュ通知を有効にすると、新着メッセージを受け取れます';
                statusText.className = 'push-status disabled';
            }
        }
        
        if (pushToggle) {
            pushToggle.checked = this.isSubscribed;
            pushToggle.disabled = !this.isSupported || Notification.permission === 'denied';
        }
    },
    
    /**
     * 自分宛通知時に表示する「ブラウザの通知許可」ホップ
     * 通知がオフの場合、自分宛通知のたびに表示（7日スキップなし）
     */
    showNotificationPermissionHop() {
        if (!this.isSupported || this.isSubscribed) return;
        if (Notification.permission === 'granted') return;
        
        // 既存のホップがあれば一度閉じてから表示
        const existing = document.getElementById('pushPermissionHop');
        if (existing) {
            existing.remove();
        }
        
        const hop = document.createElement('div');
        hop.id = 'pushPermissionHop';
        hop.className = 'push-permission-banner push-permission-hop';
        
        if (Notification.permission === 'denied') {
            hop.innerHTML = `
                <div class="push-banner-content">
                    <span class="push-banner-icon">🔔</span>
                    <div class="push-banner-text">
                        <strong>Social9の通知オン</strong>
                        <p>通知がブロックされています。ブラウザの設定から「サイトの設定」→「通知」を許可してください。</p>
                    </div>
                    <div class="push-banner-actions">
                        <button class="push-banner-btn push-banner-later" onclick="document.getElementById('pushPermissionHop').remove()">閉じる</button>
                    </div>
                </div>
            `;
        } else {
            hop.innerHTML = `
                <div class="push-banner-content">
                    <span class="push-banner-icon">🔔</span>
                    <div class="push-banner-text">
                        <strong>Social9の通知オン</strong>
                        <p>新着メッセージをすぐに受け取るには、通知を有効にしてください。</p>
                    </div>
                    <div class="push-banner-actions">
                        <button class="push-banner-btn push-banner-enable" onclick="PushNotifications.handleBannerEnable(); document.getElementById('pushPermissionHop')&&document.getElementById('pushPermissionHop').remove();">有効にする</button>
                        <button class="push-banner-btn push-banner-later" onclick="document.getElementById('pushPermissionHop').remove()">閉じる</button>
                    </div>
                </div>
            `;
        }
        
        document.body.appendChild(hop);
        setTimeout(() => hop.classList.add('show'), 100);
    },
    
    /**
     * 通知許可バナーを表示
     */
    showPermissionBanner() {
        if (!this.isSupported || this.isSubscribed || Notification.permission === 'denied') {
            return;
        }
        
        // ローカルストレージで dismiss 状態を確認
        const dismissed = localStorage.getItem('pushBannerDismissed');
        if (dismissed) {
            const dismissedTime = parseInt(dismissed);
            // 7日間は再表示しない
            if (Date.now() - dismissedTime < 7 * 24 * 60 * 60 * 1000) {
                return;
            }
        }
        
        // バナーがすでに存在する場合は何もしない
        if (document.getElementById('pushPermissionBanner')) {
            return;
        }
        
        const banner = document.createElement('div');
        banner.id = 'pushPermissionBanner';
        banner.className = 'push-permission-banner';
        banner.innerHTML = `
            <div class="push-banner-content">
                <span class="push-banner-icon">🔔</span>
                <div class="push-banner-text">
                    <strong>通知を有効にしますか？</strong>
                    <p>新着メッセージをすぐに受け取れます</p>
                </div>
                <div class="push-banner-actions">
                    <button class="push-banner-btn push-banner-enable" onclick="PushNotifications.handleBannerEnable()">有効にする</button>
                    <button class="push-banner-btn push-banner-later" onclick="PushNotifications.dismissBanner()">後で</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(banner);
        
        // アニメーション
        setTimeout(() => banner.classList.add('show'), 100);
    },
    
    /**
     * バナーの有効化ボタンがクリックされた時
     */
    async handleBannerEnable() {
        const hop = document.getElementById('pushPermissionHop');
        if (hop) hop.remove();
        const permission = await this.requestPermission();
        this.dismissBanner();
        
        if (permission === 'granted') {
            this.showToast('プッシュ通知が有効になりました', 'success');
        } else if (permission === 'denied') {
            this.showToast('通知がブロックされています', 'error');
        }
    },
    
    /**
     * バナーを閉じる
     */
    dismissBanner() {
        const banner = document.getElementById('pushPermissionBanner');
        if (banner) {
            banner.classList.remove('show');
            setTimeout(() => banner.remove(), 300);
        }
        localStorage.setItem('pushBannerDismissed', Date.now().toString());
    },
    
    /**
     * トースト通知を表示
     */
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `push-toast push-toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },
    
    /**
     * Base64 URL文字列をUint8Arrayに変換
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        
        return outputArray;
    },
    
    /**
     * テスト通知を送信（デバッグ用）
     */
    async sendTestNotification() {
        try {
            const response = await fetch('api/push.php?action=test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast('テスト通知を送信しました', 'success');
            } else {
                this.showToast('テスト通知の送信に失敗しました: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Push: テスト通知エラー', error);
            this.showToast('テスト通知の送信に失敗しました', 'error');
        }
    },
    
    // ============================================
    // アプリバッジ（タスクバーの未読数表示）
    // ============================================
    
    /**
     * アプリバッジに未読数を設定
     * @param {number} count - 未読数
     */
    async setAppBadge(count) {
        // Navigator Badge API（クライアント側）
        if ('setAppBadge' in navigator) {
            try {
                if (count > 0) {
                    await navigator.setAppBadge(count);
                } else {
                    await navigator.clearAppBadge();
                }
            } catch (error) {
                console.error('Push: バッジ設定エラー:', error);
            }
        }
        
        // Service Workerにも通知
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'SET_BADGE',
                count: count
            });
        }
    },
    
    /**
     * アプリバッジをクリア
     */
    async clearAppBadge() {
        if ('clearAppBadge' in navigator) {
            try {
                await navigator.clearAppBadge();
            } catch (error) {
                console.error('Push: バッジクリアエラー:', error);
            }
        }
        
        // Service Workerにも通知
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'CLEAR_BADGE'
            });
        }
    },
    
    /**
     * 未読数を取得してバッジを更新
     */
    async updateBadgeFromServer() {
        try {
            const response = await fetch(`api/notifications.php?action=count&_t=${Date.now()}`, { cache: 'no-store' });
            const text = await response.text();
            if (!text || text.trim() === '') return 0;

            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.warn('Push: 未読数レスポンス解析失敗');
                return 0;
            }
            
            if (data.success) {
                const count = data.total || 0;
                if (count === 0) {
                    await this.clearAppBadge();
                } else {
                    await this.setAppBadge(9);
                }
                return count;
            }
        } catch (error) {
            console.warn('Push: 未読数取得エラー:', error);
        }
        return 0;
    }
};

// グローバルに公開
window.PushNotifications = PushNotifications;
