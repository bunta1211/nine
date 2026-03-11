<?php
/**
 * ログイン画面専用 上パネル（ゲスト用）
 * ロゴ・言語切替・「ログイン」表示のみ。見た目は topbar に合わせる。
 * 必要な変数: $currentLang（includes/lang.php で取得済み）
 * オプション: $login_base_url（getBaseUrl() の結果。空のときは 'index.php' 等相対でOK）
 */
$login_base_url = isset($login_base_url) ? $login_base_url : (function_exists('getBaseUrl') ? getBaseUrl() : '');
$index_path = $login_base_url !== '' ? $login_base_url . '/index.php' : 'index.php';
$login_topbar_header_id = isset($login_topbar_header_id) ? $login_topbar_header_id : '';
$header_id_attr = $login_topbar_header_id !== '' ? ' id="' . htmlspecialchars($login_topbar_header_id) . '"' : '';
?>
<header class="top-panel top-panel-login"<?= $header_id_attr ?>>
    <div class="top-panel-inner">
        <div class="top-left">
            <div class="logo">
                <span class="logo-pc"><?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Social9') ?></span>
                <span class="logo-mobile">9</span>
            </div>
            <span class="login-topbar-search-placeholder"><?= $currentLang === 'en' ? 'Search available after login' : ($currentLang === 'zh' ? '登录后可使用搜索' : 'ログイン後に検索をご利用いただけます') ?></span>
        </div>
        <div class="top-right top-panel-login-right">
            <div class="language-selector" style="position: relative;">
                <button class="top-btn" type="button" onclick="toggleLoginLanguageMenu(event)" id="loginLanguageBtn" aria-haspopup="true" aria-expanded="false">
                    <img src="assets/icons/line/globe.svg" alt="" class="icon-line" width="20" height="20" onerror="this.style.display='none'"> <span class="btn-label"><?= $currentLang === 'en' ? 'EN' : ($currentLang === 'zh' ? '中' : 'JP') ?></span>
                </button>
                <div class="language-dropdown" id="loginLanguageDropdown" aria-hidden="true">
                    <a href="<?= htmlspecialchars($index_path) ?>?lang=ja" class="language-option <?= $currentLang === 'ja' ? 'active' : '' ?>">🇯🇵 日本語</a>
                    <a href="<?= htmlspecialchars($index_path) ?>?lang=en" class="language-option <?= $currentLang === 'en' ? 'active' : '' ?>">🇺🇸 English</a>
                    <a href="<?= htmlspecialchars($index_path) ?>?lang=zh" class="language-option <?= $currentLang === 'zh' ? 'active' : '' ?>">🇨🇳 中文</a>
                </div>
            </div>
            <span class="login-topbar-login-label"><?= __('login') ?></span>
        </div>
    </div>
</header>
<script>
(function() {
    function toggleLoginLanguageMenu(e) {
        if (e) e.stopPropagation();
        var dropdown = document.getElementById('loginLanguageDropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
            var btn = document.getElementById('loginLanguageBtn');
            if (btn) btn.setAttribute('aria-expanded', dropdown.classList.contains('show'));
        }
    }
    document.addEventListener('click', function() {
        var d = document.getElementById('loginLanguageDropdown');
        if (d) d.classList.remove('show');
        var b = document.getElementById('loginLanguageBtn');
        if (b) b.setAttribute('aria-expanded', 'false');
    });
    var btn = document.getElementById('loginLanguageBtn');
    if (btn) btn.addEventListener('click', function(e) { e.stopPropagation(); toggleLoginLanguageMenu(e); });
    window.toggleLoginLanguageMenu = toggleLoginLanguageMenu;
})();
</script>
