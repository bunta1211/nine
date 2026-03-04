# 携帯番号登録・SMS認証 実装進捗

## 完了済み
- [x] DB マイグレーション: `database/migration_phone_registration.sql`
- [x] SMS 設定: `config/sms.php`, `config/sms.local.example.php`
- [x] SmsSender: `includes/SmsSender.php`
- [x] api/auth_otp.php: send_code（SMS）/ verify_code（phone）/ set_password（phone）の電話対応、setLoginSession 共通化
- [x] Auth クラス: register の電話のみ対応（email NULL 可、phone 必須時は電話のみ登録）
- [x] register.php: 「メールで登録」「携帯で登録」タブ、携帯フロー（register-phone.js）

## 完了済み（続き）
- [x] admin/api/members.php: createMember / updateMember でメール or 電話対応、INSERT/UPDATE に phone

## 完了済み（最終）
- [x] ドキュメント・DEPENDENCIES 更新（api/config/database/DEPENDENCIES.md、電話機能_実装計画.md 2.3）
- [x] 管理画面メンバー: 一覧で email or phone 表示、編集で phone をフォームにセット、一覧APIで phone を返却
