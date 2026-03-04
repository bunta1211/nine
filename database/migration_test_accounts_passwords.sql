-- =====================================================
-- 会話テスト用アカウントのパスワード設定
-- システム管理者と奈良健太郎を別人格でログイン可能に
-- =====================================================
--
-- 【phpMyAdminで実行する場合】
-- このファイルを開き、下のUPDATE文2つをコピーして
-- phpMyAdminの「SQL」タブに貼り付け、実行してください。
-- ※「database/migration_test_accounts_passwords.sql」という
--   ファイルパスを貼り付けるとエラーになります。
--
-- 【推奨】PHPスクリプトで実行:
--   http://localhost/nine/admin/set_test_passwords.php
--   ※XAMPPのMySQLが起動している必要があります
-- =====================================================

UPDATE users SET password_hash = '$2y$10$bHDb2l9ymE4eeJOSFOwBC..keneDYHA.Gq0Gw0I.mYOwZYTWyQ4wm', updated_at = NOW() WHERE email = 'admin@social9.jp';

UPDATE users SET password_hash = '$2y$10$cq/KZdZa6DsRRnKrLKaXBebzv0.neWh3r.88Edomd8f7krOjnKuKq', updated_at = NOW() WHERE email = 'narakenn1211@gmail.com';
