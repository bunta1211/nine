-- 検索デフォルトを「検索可能」に変更（携帯番号検索でヒットするようにする）
-- 2026-02
-- 既存ユーザーを検索可能にする。設定画面で「検索に表示しない」をONにしたユーザーのみ検索から除外される。

UPDATE user_privacy_settings SET exclude_from_search = 0 WHERE exclude_from_search = 1;
