<?php
/**
 * 検索まわり設定（SEARCH_INDEX.md / SEARCH_ARCHITECTURE.md 準拠）
 *
 * 個人アドレス帳検索（address_search）の API パス・パラメータ名を一元定義し、
 * PHP と JS の両方で同じ設定を参照できるようにする。
 *
 * 利用: includes/chat/scripts.php, settings.php, assets/js/search-common.js
 */

if (!defined('SEARCH_ADDRESS_ENDPOINT')) {
    /** 個人アドレス帳検索 API パス（ベースURL除く） */
    define('SEARCH_ADDRESS_ENDPOINT', 'api/friends.php');
}
if (!defined('SEARCH_ADDRESS_ACTION')) {
    /** 個人アドレス帳検索 action パラメータ値 */
    define('SEARCH_ADDRESS_ACTION', 'search');
}
if (!defined('SEARCH_ADDRESS_PARAM')) {
    /** 個人アドレス帳検索のクエリパラメータ名（統一: query） */
    define('SEARCH_ADDRESS_PARAM', 'query');
}

/**
 * 個人アドレス帳検索の URL プレフィックス（クエリ値は JS で付与）
 * 例: "api/friends.php?action=search&query="
 *
 * @return string
 */
function search_config_get_address_url_prefix() {
    return SEARCH_ADDRESS_ENDPOINT . '?action=' . SEARCH_ADDRESS_ACTION . '&' . SEARCH_ADDRESS_PARAM . '=';
}

/**
 * JS の window.__SEARCH_CONFIG 用の連想配列を返す
 * credentials: 'include' は search-common.js 側で付与する
 *
 * @return array{addressSearchEndpoint: string, addressSearchAction: string, addressSearchParam: string}
 */
function search_config_for_js() {
    return [
        'addressSearchEndpoint' => SEARCH_ADDRESS_ENDPOINT,
        'addressSearchAction'  => SEARCH_ADDRESS_ACTION,
        'addressSearchParam'   => SEARCH_ADDRESS_PARAM,
    ];
}
