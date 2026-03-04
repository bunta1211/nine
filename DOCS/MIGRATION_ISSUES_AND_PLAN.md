# サーバー移転により使えなくなる／影響を受ける機能 リストと改善計画

**目的:** 移転後（heteml → AWS 等）に動かなくなる可能性がある機能を洗い出し、対応順と改善計画を記録する。

---

## 1. 影響を受ける可能性がある機能のリスト

| # | 機能・箇所 | 想定される現象 | 主な原因 |
|---|------------|----------------|----------|
| 1 | **ログイン・セッション** | ログインできない／すぐ切れる | セッション保存先・クッキー・リダイレクトURL | 
| 2 | **Googleログイン** | 500エラー／コールバック失敗 | コールバックURLのドメイン変更、DBに google_id 未追加 |
| 3 | **データベース接続** | 接続エラー／旧データのまま | database.aws.php 未配置時、database.php が heteml を参照する fallback |
| 4 | **APP_URL に依存する処理** | リダイレクト先が localhost／旧ドメインになる | 本番で APP_URL 未設定（app.local.php や環境変数なし） |
| 5 | **ファイルアップロード** | アップロード失敗／保存先がない | uploads/ 以下のディレクトリが存在しない・書込不可 |
| 6 | **アップロード済みファイルの表示** | 画像・添付が表示されない | 移転時に uploads/ をコピーしていない、またはパス・URLの違い |
| 7 | **Web Push 通知** | 通知が届かない／登録失敗 | VAPID キー未設定、push.local.php 未配置、またはサービスワーカー scope |
| 8 | **Googleカレンダー連携** | 認証・同期が動かない | リダイレクトURIのドメイン変更、Google Console 側の設定未更新 |
| 9 | **メール送信（認証・リセット等）** | メールが届かない | 旧サーバーでは mail() 可、EC2 では未設定 or 送信制限 |
| 10 | **招待リンク・グループ参加リンク** | リンクが旧ドメインを指す | リンク生成時に APP_URL が未設定 or 古い |
| 11 | **定時処理（リマインダー等）** | リマインダーが動かない | 旧サーバーの cron がなくなり、EC2 で cron 未設定 |
| 12 | **ログ・エラー出力** | ログが残らない／確認できない | logs/ が存在しない・書込不可、または PHP の error_log 先の違い |
| 13 | **管理画面・API のアクセス制限** | 403／意図しないリダイレクト | ベースURLや requireLogin 等のリダイレクトが相対のまま |

※ 1〜4 および 13 の一部は [LOGIN_FIX_AFTER_MIGRATION.md](./LOGIN_FIX_AFTER_MIGRATION.md) で対応済み。

---

## 2. 原因別の整理

### A. 設定・環境のずれ（本番で設定し直す必要があるもの）

- **APP_URL** … app.local.php または環境変数で本番のベースURL（例: `https://social9.jp`）を設定する。
- **DB 接続** … database.aws.php を配置し、RDS のエンドポイント・ユーザー・パスワードを設定する。  
  ※ database.aws.php がないと、database.php の「本番」判定で **heteml** の DB に接続しようとする。
- **Google ログイン / カレンダー** … Google Cloud Console の「認証情報」で、リダイレクトURIを新ドメイン（例: `https://social9.jp/...`）に追加・更新する。
- **Web Push** … push.local.php で本番用 VAPID キーを設定する（必要に応じて config/generate_vapid_keys.php で再生成）。

### B. サーバー上のディレクトリ・権限

- **tmp/sessions** … セッション保存先。存在しない・書込不可だとログインが不安定になる。  
  → [LOGIN_FIX_AFTER_MIGRATION.md](./LOGIN_FIX_AFTER_MIGRATION.md) の「本番サーバーでの確認事項」および scripts/ensure_tmp_sessions.sh を参照。
- **logs/** … エラー・アプリログの出力先。存在しない・書込不可だとログが残らない。
- **uploads/** … アップロードファイルの保存先。存在しない・書込不可だとアップロードが失敗する。  
  さらに **uploads/backgrounds/** や **uploads/messages/** など、アプリが参照しているサブディレクトリも必要。

### C. データ・資産の移行

- **アップロード済みファイル** … 旧サーバーの uploads/ を新サーバーにコピーしないと、既存の画像・添付が 404 になる。
- **DB データ** … ユーザー・メッセージ等は RDS に移行済みを想定。未移行の場合は別途インポートが必要。

### D. サーバー外の設定・仕組み

- **メール送信** … EC2 の mail() は未設定 or 制限されていることが多い。  
  SMTP や SES 等を使う場合は、送信処理の実装と設定の見直しが必要。
- **cron** … リマインダー等の定時実行は、EC2 上で cron を設定するか、外部のスケジューラから URL を叩く必要がある。

---

## 3. 改善計画（優先度・順序）

### Phase 1: すでに対応済み（確認のみ）

| 項目 | 内容 | 参照 |
|------|------|------|
| ログイン・セッション | 絶対URLリダイレクト、tmp/sessions 利用 | LOGIN_FIX_AFTER_MIGRATION.md |
| Google ログイン（コールバック） | 例外捕捉・ログ、DB に google_id 追加 | api/google-login-callback.php, migration_google_login.sql |

### Phase 2: 設定・ドキュメントの整理（短期） ✅ 対応済み

| 順 | タスク | 内容 | 成果物・対象 |
|----|--------|------|--------------|
| 2-1 | 本番用設定チェックリスト | APP_URL, database.aws.php, push.local.php, Google リダイレクトURI を1枚で確認できる一覧 | **DOCS/PRODUCTION_CHECKLIST.md** |
| 2-2 | database.php の fallback 注記 | 「本番＝social9.jp」時に heteml を参照するのは database.aws.php が無い場合である旨をコメントで明記 | **config/database.php** |
| 2-3 | uploads / logs の初回作成 | 本番で uploads/, logs/, uploads/backgrounds/, uploads/messages/ 等を自動作成する手順 or スクリプト | **scripts/ensure_dirs.sh** |

### Phase 3: 動作保証のためのコード修正（中期） ✅ 対応済み

| 順 | タスク | 内容 | 成果物・対象 |
|----|--------|------|--------------|
| 3-1 | 招待・共有リンクの URL 生成 | リンク生成箇所で getBaseUrl() を使用 | **api/conversations.php**, **join_group.php**, **Auth.php**（メール認証・パスワードリセットのリンク） |
| 3-2 | メール送信の見直し | 現状 mail() はコメントアウトのため未実施。本番で SMTP/SES 利用時は別途実装 | — |
| 3-3 | リマインダー cron 手順 | EC2 で process_reminders.php を定期実行する方法を DOCS に記載 | **DOCS/CRON_REMINDERS.md** |

### Phase 4: 運用・監視（任意・続きの改善候補）

| 順 | タスク | 内容 | 状態 |
|----|--------|------|------|
| 4-1 | ヘルスチェックの活用 | api/health.php で uploads/logs/tmp の書込可否を確認済み。移転後の初回確認で `GET /api/health.php` を叩く手順を DOCS に追記可能 | 未実施（任意） |
| 4-2 | エラーログの確認方法 | 本番の PHP error_log と logs/ の場所・見方を DOCS にまとめる | 未実施（任意） |
| 4-3 | その他 | メール送信を有効化する場合は SMTP/SES 導入と送信失敗ログの追加 | 要検討 |

---

## 4. 実施した修正一覧（完了分）

- config/database.php … AWS / database.aws.php の注記を追加
- DOCS/PRODUCTION_CHECKLIST.md … 本番用チェックリストを新規作成
- scripts/ensure_dirs.sh … tmp/sessions, logs, uploads 等を一括作成するスクリプトを新規作成
- api/conversations.php … 招待リンクの baseUrl に getBaseUrl() を使用
- join_group.php … config/app.php を読み込み、リダイレクトを getBaseUrl() で絶対URLに
- includes/auth/Auth.php … メール認証・パスワードリセットのリンクで getBaseUrl() を利用
- DOCS/CRON_REMINDERS.md … リマインダー cron の設定手順を新規作成

---

## 5. 続きの改善はあるか

**必須の対応は一通り完了しています。** 以下は任意です。

| 種類 | 内容 |
|------|------|
| **Phase 4-1** | 移転後の初回確認手順に「`/api/health.php` を開いて uploads/logs/tmp の状態を確認する」を DOCS に追記する。 |
| **Phase 4-2** | 本番サーバーでの PHP error_log の場所・logs/ の見方を DOCS にまとめる（運用マニュアルの一部として）。 |
| **メール** | メール送信を有効化する場合、SMTP や AWS SES の導入と、送信失敗時の error_log 出力を実装する。 |
| **その他** | 新ドメイン・新機能追加時に、都度「APP_URL・コールバックURL・cron」をチェックリストで確認する。 |

必要になったタイミングで Phase 4 やメールまわりに着手すれば十分です。

---

*作成日: サーバー移転後の不具合リスト・改善計画として作成*
