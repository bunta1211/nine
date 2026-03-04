<?php
/**
 * 多言語サポート
 */

require_once __DIR__ . '/../config/app.php';

/**
 * 現在の言語を取得
 */
function getCurrentLanguage() {
    // セッションから取得
    if (isset($_SESSION['guild_language'])) {
        return $_SESSION['guild_language'];
    }
    
    // ユーザー設定から取得
    if (isset($_SESSION['guild_user_id'])) {
        require_once __DIR__ . '/../config/database.php';
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT language FROM guild_user_profiles WHERE user_id = ?");
        $stmt->execute([$_SESSION['guild_user_id']]);
        $result = $stmt->fetch();
        if ($result && !empty($result['language'])) {
            $_SESSION['guild_language'] = $result['language'];
            return $result['language'];
        }
    }
    
    // デフォルト言語
    return DEFAULT_LANGUAGE;
}

/**
 * 言語を設定
 */
function setLanguage($lang) {
    if (array_key_exists($lang, SUPPORTED_LANGUAGES)) {
        $_SESSION['guild_language'] = $lang;
        
        // 翻訳キャッシュをクリア
        clearTranslationCache();
        
        // ユーザー設定を更新
        if (isset($_SESSION['guild_user_id'])) {
            require_once __DIR__ . '/../config/database.php';
            $pdo = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO guild_user_profiles (user_id, language) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE language = ?
            ");
            $stmt->execute([$_SESSION['guild_user_id'], $lang, $lang]);
        }
        
        return true;
    }
    return false;
}

/**
 * 翻訳キャッシュをクリア
 */
function clearTranslationCache() {
    global $_guild_translations_cache;
    $_guild_translations_cache = null;
}

/**
 * 言語ファイルを読み込み
 */
function loadLanguageFile($lang = null) {
    global $_guild_translations_cache;
    
    if ($_guild_translations_cache === null) {
        $_guild_translations_cache = [];
    }
    
    if ($lang === null) {
        $lang = getCurrentLanguage();
    }
    
    if (!isset($_guild_translations_cache[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        if (file_exists($file)) {
            $_guild_translations_cache[$lang] = require $file;
        } else {
            // フォールバック
            $_guild_translations_cache[$lang] = require __DIR__ . '/lang/ja.php';
        }
    }
    
    return $_guild_translations_cache[$lang];
}

/**
 * 翻訳を取得
 * @param string $key 翻訳キー
 * @param array $params 置換パラメータ
 * @return string 翻訳されたテキスト
 */
function __($key, $params = []) {
    $translations = loadLanguageFile();
    
    $text = $translations[$key] ?? $key;
    
    // パラメータ置換
    if (!empty($params)) {
        foreach ($params as $k => $v) {
            $text = str_replace(':' . $k, $v, $text);
        }
    }
    
    return $text;
}

/**
 * 翻訳をエコー
 */
function _e($key, $params = []) {
    echo __($key, $params);
}

/**
 * 多言語対応の名前を取得
 */
function getLocalizedName($item, $field = 'name') {
    $lang = getCurrentLanguage();
    
    // 言語別フィールドを試す
    $langField = $field . '_' . $lang;
    if (isset($item[$langField]) && !empty($item[$langField])) {
        return $item[$langField];
    }
    
    // デフォルトフィールド
    if (isset($item[$field])) {
        return $item[$field];
    }
    
    return '';
}
