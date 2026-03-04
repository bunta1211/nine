/**
 * Social9 Service Worker
 * PWAインストールプロンプト表示に必要
 * 
 * 機能:
 * - オフラインフォールバック
 * - 静的アセットのキャッシュ
 * - Web Push通知の受信と表示
 */

const CACHE_NAME = 'social9-v4';
const OFFLINE_URL = 'offline.html';

// プッシュ通知のデフォルトアイコン
const DEFAULT_ICON = '/assets/icons/icon-192x192.png';
const DEFAULT_BADGE = '/assets/icons/icon-72x72.png';

// キャッシュするファイル
const STATIC_ASSETS = [
    'assets/css/common.css',
    'assets/css/chat-main.css',
    'assets/css/chat-mobile.css',
    'assets/css/mobile.css',
    'assets/icons/icon.svg',
    'assets/icons/icon-192x192.png',
    'offline.html'
];

// インストール時: 静的アセットをキャッシュ
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('Service Worker: キャッシュを作成');
            // オフラインページは必須
            return cache.addAll([OFFLINE_URL]).catch(err => {
                console.log('Service Worker: 一部のファイルのキャッシュに失敗', err);
            });
        })
    );
    // 即座にアクティベート
    self.skipWaiting();
});

// アクティベート時: 古いキャッシュを削除
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Service Worker: 古いキャッシュを削除', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    // 即座にコントロールを取得
    self.clients.claim();
});

// フェッチ時: ネットワーク優先、失敗時はキャッシュ
self.addEventListener('fetch', (event) => {
    // APIリクエストはキャッシュしない
    if (event.request.url.includes('/api/')) {
        return;
    }
    
    // ナビゲーションリクエスト（HTML）の場合
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match(OFFLINE_URL).then((cached) => {
                    if (cached) return cached;
                    return new Response(
                        '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>オフライン</title></head><body><h1>オフラインです</h1><p>接続を確認してください。</p></body></html>',
                        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                });
            })
        );
        return;
    }
    
    // その他のリクエスト: ネットワーク優先
    event.respondWith(
        fetch(event.request).then((response) => {
            // 成功したレスポンスをキャッシュに保存（GETのみ、http/httpsのみ）
            // chrome-extension:// 等は Cache API で unsupported になるためスキップ
            const url = event.request.url;
            if (event.request.method === 'GET' && response.status === 200 &&
                (url.startsWith('http://') || url.startsWith('https://'))) {
                const responseClone = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, responseClone).catch(() => {});
                });
            }
            return response;
        }).catch(() => {
            // ネットワーク失敗時はキャッシュから（キャッシュミス時は透過的にフォールバック）
            return caches.match(event.request).then((cached) => {
                if (cached) return cached;
                return new Response('', { status: 503, statusText: 'Service Unavailable' });
            });
        })
    );
});

// ============================================
// プッシュ通知の受信
// ============================================
self.addEventListener('push', (event) => {
    console.log('Service Worker: プッシュ通知を受信', event);
    
    let notificationData = {
        title: 'Social9',
        body: '新しいメッセージがあります',
        icon: DEFAULT_ICON,
        badge: DEFAULT_BADGE,
        tag: 'social9-notification',
        data: {}
    };
    
    // プッシュデータを解析
    if (event.data) {
        try {
            const data = event.data.json();
            
            // デフォルトのバイブパターン
            let vibratePattern = [200, 100, 200];
            
            // リマインダー通知の場合は10秒間のバイブ
            if (data.data && data.data.type === 'reminder') {
                vibratePattern = [];
                for (let i = 0; i < 14; i++) {
                    vibratePattern.push(500, 200);
                }
                vibratePattern.push(500);
            } else if (data.data && data.data.type === 'call_incoming') {
                // 通話着信: 着信らしい繰り返しバイブ
                vibratePattern = [];
                for (let i = 0; i < 8; i++) {
                    vibratePattern.push(200, 100, 200, 100, 200, 500);
                }
            } else if (data.vibrate) {
                vibratePattern = data.vibrate;
            }
            
            notificationData = {
                title: data.title || notificationData.title,
                body: data.body || notificationData.body,
                icon: data.icon || DEFAULT_ICON,
                badge: data.badge || DEFAULT_BADGE,
                tag: data.tag || `social9-${Date.now()}`,
                data: data.data || {},
                actions: data.actions || [],
                requireInteraction: (data.data && data.data.type === 'call_incoming') || data.requireInteraction || false,
                renotify: data.renotify || false,
                silent: data.silent || false,
                vibrate: vibratePattern
            };
        } catch (e) {
            // JSONパース失敗時はテキストとして扱う
            notificationData.body = event.data.text();
        }
    }
    
    // 通知を表示してアプリバッジを更新
    event.waitUntil(
        Promise.all([
            // 通知を表示
            self.registration.showNotification(notificationData.title, {
                body: notificationData.body,
                icon: notificationData.icon,
                badge: notificationData.badge,
                tag: notificationData.tag,
                data: notificationData.data,
                actions: notificationData.actions,
                requireInteraction: notificationData.requireInteraction,
                renotify: notificationData.renotify,
                silent: notificationData.silent,
                vibrate: notificationData.vibrate
            }),
            // タスクバーのアプリバッジを更新（サーバーから実際の未読数を取得）
            fetchAndUpdateBadge()
        ])
    );
});

/**
 * サーバーから未読数を取得してバッジを更新
 */
async function fetchAndUpdateBadge() {
    try {
        // キャッシュ防止のためタイムスタンプを付与
        // 絶対パスを使用（Service Workerのスコープに依存しない）
        const response = await fetch(`/api/notifications.php?action=count&_t=${Date.now()}`, { cache: 'no-store' });
        const data = await response.json();
        
        if (data.success) {
            const count = data.total || 0;
            await setAppBadgeCount(count);
            console.log('Service Worker: サーバーから未読数取得:', count);
        }
    } catch (error) {
        // オフラインの場合などはローカルカウントを+1
        console.log('Service Worker: サーバー接続エラー、ローカルで+1');
        await updateAppBadge(1);
    }
}

// ============================================
// アプリバッジ（タスクバーの未読数表示）
// ============================================

// 現在のバッジカウント
let badgeCount = 0;

/**
 * アプリバッジを更新
 * @param {number} increment - 増加させる数（負の値で減少、nullでカウント設定）
 */
async function updateAppBadge(increment = null) {
    if (!('setAppBadge' in navigator)) {
        return; // Badge APIがサポートされていない
    }
    
    try {
        if (increment !== null) {
            badgeCount = Math.max(0, badgeCount + increment);
        }
        
        if (badgeCount > 0) {
            await navigator.setAppBadge(9);
        } else {
            await navigator.clearAppBadge();
        }
        console.log('Service Worker: アプリバッジ更新:', badgeCount > 0 ? '9' : 'クリア');
    } catch (error) {
        console.error('Service Worker: バッジ更新エラー:', error);
    }
}

/**
 * 未読数を直接設定
 * @param {number} count - 未読数
 */
async function setAppBadgeCount(count) {
    if (!('setAppBadge' in navigator)) {
        return;
    }
    
    try {
        badgeCount = Math.max(0, count);
        if (badgeCount > 0) {
            await navigator.setAppBadge(9);
        } else {
            await navigator.clearAppBadge();
        }
    } catch (error) {
        console.error('Service Worker: バッジ設定エラー:', error);
    }
}

// クライアントからのメッセージを受信（バッジ更新用）
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SET_BADGE') {
        setAppBadgeCount(event.data.count || 0);
    } else if (event.data && event.data.type === 'CLEAR_BADGE') {
        setAppBadgeCount(0);
    }
});

// ============================================
// 通知クリック時の処理
// ============================================
self.addEventListener('notificationclick', (event) => {
    console.log('Service Worker: 通知がクリックされました', event);
    
    event.notification.close();
    
    const data = event.notification.data || {};
    let targetUrl = '/chat.php';
    
    // 会話IDがある場合は直接その会話を開く
    if (data.conversation_id) {
        targetUrl = `/chat.php?c=${data.conversation_id}`;
    }
    
    // 通話着信: チャットを開くとポーリングで着信モーダルが表示される
    if (data.type === 'call_incoming') {
        targetUrl = data.conversation_id ? `/chat.php?c=${data.conversation_id}` : '/chat.php';
    }
    
    // リマインダー通知の場合
    if (data.type === 'reminder') {
        targetUrl = '/chat.php?secretary=1';
        
        // スヌーズアクション
        if (event.action === 'snooze') {
            // 5分後に再通知
            fetch('/api/ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'snooze_reminder',
                    reminder_id: data.reminder_id,
                    minutes: 5
                })
            }).catch(err => console.error('Snooze error:', err));
            return;
        }
    }
    
    // アクションボタンがクリックされた場合
    if (event.action) {
        switch (event.action) {
            case 'reply':
                // 返信アクション（将来の拡張用）
                targetUrl = `/chat.php?c=${data.conversation_id}&reply=1`;
                break;
            case 'view':
                // 表示アクション
                break;
            case 'dismiss':
                // 閉じるアクション
                return;
        }
    }
    
    // 既存のタブを探すか、新しいタブを開く
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // 既に開いているタブがあればフォーカス
            for (const client of clientList) {
                if (client.url.includes('/chat.php') && 'focus' in client) {
                    // 会話IDを通知
                    client.postMessage({
                        type: 'NOTIFICATION_CLICK',
                        conversationId: data.conversation_id
                    });
                    return client.focus();
                }
            }
            // 開いているタブがなければ新しく開く
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// ============================================
// 通知を閉じた時の処理
// ============================================
self.addEventListener('notificationclose', (event) => {
    console.log('Service Worker: 通知が閉じられました', event);
    // 分析用にログを送信することも可能
});

// ============================================
// プッシュ購読の変更時
// ============================================
self.addEventListener('pushsubscriptionchange', (event) => {
    console.log('Service Worker: プッシュ購読が変更されました', event);
    
    // 新しい購読を取得してサーバーに送信
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options).then((subscription) => {
            // サーバーに新しい購読情報を送信
            return fetch('/api/push.php?action=update_subscription', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    old_endpoint: event.oldSubscription ? event.oldSubscription.endpoint : null,
                    new_subscription: subscription.toJSON()
                })
            });
        })
    );
});
