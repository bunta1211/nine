# HTTPS 設定後の推奨作業

本番環境（https://social9.jp）稼働後の、優先度順の推奨作業一覧です。

---

## 高優先度（セキュリティ）

### 1. server-check.php のアクセス制限 ✅

**対応済み**: `.htaccess` に `api/server-check.php` へのアクセス禁止ルールを追加済み。

**完了**: .htaccess アップロード済み。server-check.php は 403 でブロックされている。

---

### 2. 機密情報の保護 ✅

**対応済み**:
- `.gitignore` を作成（database.aws.php, app.local.php, *.local.php 等を除外）
- [SECURITY_CHECKLIST.md](./SECURITY_CHECKLIST.md) で確認手順を整備

**確認**: EC2 の database.aws.php が Web から読めないこと（.htaccess で config/ はブロック済み）

---

## 中優先度（動作・管理）

### 3〜5. 中優先度の作業

詳細は **[NEXT_STEPS_MEDIUM_PRIORITY.md](./NEXT_STEPS_MEDIUM_PRIORITY.md)** を参照。

- **www.social9.jp** … Route 53 に A レコード追加（任意）
- **Google OAuth** … リダイレクト URI に https://social9.jp を追加
- **RDS バックアップ** … 自動バックアップの有効化を確認
- **session.php** … HTTPS セキュアクッキー対応版をアップロード

---

## 低優先度（運用改善）

### 6. hosts ファイルの整理

- **あなたの PC**: social9.jp を EC2 に固定する hosts があれば、Route 53 の DNS が通っているなら削除してよい
- **削除する場合**: `C:\Windows\System32\drivers\etc\hosts` から該当2行を削除

---

### 7. バックアップ運用の確立

- **RDS**: スナップショットまたは自動バックアップの運用確認
- **アップロードファイル**: `/var/www/html/uploads/` の定期バックアップ（S3 等）
- **設定ファイル**: `config/*.local.php`、`config/database.aws.php` のバックアップ

---

### 8. session.php のアップロード（未実施の場合）

HTTPS 時にセッションクッキーを secure にする変更を反映するため、`config/session.php` を EC2 にアップロードする。

---

## チェックリスト

| # | 作業 | 優先度 | 状態 |
|---|------|--------|------|
| 1 | server-check.php 制限 | 高 | 完了 |
| 2 | 機密情報の保護 | 高 | .gitignore 作成済み |
| 3 | www.social9.jp DNS 追加 | 中 | 任意 |
| 4 | Google OAuth リダイレクト | 中 | 未 |
| 5 | RDS バックアップ確認 | 中 | 未 |
| 6 | hosts 整理 | 低 | 任意 |
| 7 | バックアップ運用 | 低 | 未 |
| 8 | session.php アップロード | 低 | 完了 |

---

*関連: [AWS_HTTPS_SETUP_STEPS.md](./AWS_HTTPS_SETUP_STEPS.md)*
