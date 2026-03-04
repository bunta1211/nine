# 中優先度の作業手順

高優先度完了後に行う作業です。必要なものだけ実施してください。

---

## 1. www.social9.jp の DNS 追加（任意）

`https://www.social9.jp` でもアクセスしたい場合のみ実施。

### 手順

1. **AWS コンソール** → **Route 53** → **ホストゾーン** → **social9.jp** をクリック
2. **「レコードを作成」** をクリック
3. 入力:
   - **レコード名**: `www`
   - **レコードタイプ**: A
   - **値**: `54.95.86.79`
   - **TTL**: 300（そのまま）
4. **「レコードを作成」** をクリック
5. 反映後（数分〜）、EC2 に SSH 接続して証明書を拡張:

```
sudo certbot --apache -d social9.jp -d www.social9.jp --expand
```

---

## 2. Google OAuth のリダイレクト URI 追加

**Google ログイン** または **Google カレンダー** を使う場合のみ実施。

### 手順

1. **Google Cloud Console** を開く  
   https://console.cloud.google.com/

2. プロジェクトを選択

3. **API とサービス** → **認証情報**

4. **OAuth 2.0 クライアント ID**（ウェブアプリケーション）をクリック

5. **承認済みのリダイレクト URI** に以下を追加:
   - `https://social9.jp/api/google-login-callback.php`（Google ログイン用）
   - `https://social9.jp/api/google-calendar-callback.php`（Google カレンダー用）

6. **保存** をクリック

---

## 3. RDS 自動バックアップの確認

### 手順

1. **AWS コンソール** → **RDS** → **データベース**

2. **database-1** をクリック

3. **メンテナンスとバックアップ** タブを開く

4. 確認項目:
   - **自動バックアップ**: 有効
   - **バックアップの保持期間**: 7日以上を推奨

5. 無効の場合は **「変更」** から有効化

---

## 4. session.php のアップロード（HTTPS セキュアクッキー）

HTTPS 時にセッションクッキーを secure にする変更を反映します。

### アップロード

| ローカル | リモート |
|----------|----------|
| `config/session.php` | `/var/www/html/config/session.php` |

WinSCP で上書きアップロードしてください。

---

## チェックリスト

| # | 作業 | 必要時のみ |
|---|------|-----------|
| 1 | www.social9.jp DNS 追加 | www を使う場合 |
| 2 | Google OAuth リダイレクト | Google ログイン/カレンダー使用時 |
| 3 | RDS バックアップ確認 | 全環境で推奨 |
| 4 | session.php アップロード | 全環境で推奨 |
