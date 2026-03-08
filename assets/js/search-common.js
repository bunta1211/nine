/**
 * 検索まわり共通ロジック（SEARCH_INDEX.md 準拠）
 *
 * - getSearchLabel(key): 文言を window.__SEARCH_LABELS から取得（未定義時はフォールバック）
 * - addressSearch(query): 個人アドレス帳検索 API を呼び出し、fetch の Promise を返す
 *
 * 前提: ページ側で window.__SEARCH_LABELS と（任意）window.__SEARCH_CONFIG を
 * scripts.php または search_config.php 出力で設定した後にこのファイルを読み込むこと。
 */
(function() {
    'use strict';

    var FALLBACK_LABELS = {
        search_address_placeholder: 'メールアドレスまたは携帯番号で検索',
        search_address_hint: '登録済みの方はアドレス追加申請、未登録のメールアドレスには招待を送れます',
        search_address_request_btn: 'アドレス追加申請',
        search_invite_mail_btn: '招待メール送信',
        search_invite_accept_label: '〇〇の個人アドレス帳に追加受諾',
        search_no_user: 'ユーザーが見つかりませんでした',
        search_loading: '検索中...',
        search_error: '検索エラーが発生しました',
        search_address_request_sent: 'アドレス追加申請を送信しました',
        search_sending: '送信中...',
        search_invite_sent: '招待を送信しました',
        search_invite_done: '招待済み',
        search_invite_error: '招待の送信に失敗しました'
    };

    /**
     * 検索まわり文言を取得する。__SEARCH_LABELS 未定義時はフォールバックを使用。
     * @param {string} key - lang.php のキー（例: 'search_loading', 'search_invite_sent'）
     * @returns {string}
     */
    window.getSearchLabel = function(key) {
        if (window.__SEARCH_LABELS && typeof window.__SEARCH_LABELS[key] === 'string') {
            return window.__SEARCH_LABELS[key];
        }
        if (FALLBACK_LABELS[key]) {
            return FALLBACK_LABELS[key];
        }
        return key;
    };

    /**
     * 個人アドレス帳検索 API を呼び出す。
     * window.__SEARCH_CONFIG があればそれで URL を組み立て、なければ従来の固定 URL を使用。
     * @param {string} query - 検索クエリ（2文字以上推奨）
     * @returns {Promise<Response>} fetch の Promise（呼び出し側で .then(r => r.json()) など）
     */
    window.addressSearch = function(query) {
        var base = (window.__CHAT_API_BASE || '').replace(/\/?$/, '/');
        var cfg = window.__SEARCH_CONFIG;
        var url;
        if (cfg && cfg.addressSearchEndpoint && cfg.addressSearchParam) {
            var action = (cfg.addressSearchAction != null) ? cfg.addressSearchAction : 'search';
            url = base + cfg.addressSearchEndpoint + '?action=' + encodeURIComponent(action) + '&' + cfg.addressSearchParam + '=' + encodeURIComponent(String(query));
        } else {
            url = base + 'api/friends.php?action=search&query=' + encodeURIComponent(String(query));
        }
        return fetch(url, { credentials: 'include' });
    };
})();
