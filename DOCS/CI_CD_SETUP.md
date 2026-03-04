# CI/CD 自動デプロイ セットアップガイド

main ブランチにマージされたら、GitHub Actions が自動で EC2 にデプロイする仕組み。

---

## 概要

```
開発ブランチで作業 → PR 作成 → main にマージ
        ↓
GitHub Actions が自動起動
        ↓
rsync で EC2 (/var/www/html/) にファイル同期
        ↓
権限修正 → httpd reload → デプロイ完了
```

---

## 1. GitHub Secrets の設定（必須）

GitHub リポジトリの **Settings → Secrets and variables → Actions → New repository secret** で以下を登録する。

| Secret 名 | 値 | 説明 |
|---|---|---|
| `EC2_SSH_KEY` | deploy ユーザーの秘密鍵（全文） | `-----BEGIN OPENSSH PRIVATE KEY-----` から `-----END OPENSSH PRIVATE KEY-----` まで |
| `EC2_HOST` | `54.95.86.79` | EC2 のパブリック IP |
| `EC2_USER` | `deploy` | デプロイ専用ユーザー |

### EC2_SSH_KEY の登録手順

1. GitHub で `https://github.com/bunta1211/nine/settings/secrets/actions` を開く
2. 「New repository secret」をクリック
3. Name: `EC2_SSH_KEY`
4. Secret: 秘密鍵の全文をペースト（改行含む）
5. 「Add secret」をクリック
6. 同様に `EC2_HOST` と `EC2_USER` も登録

---

## 2. EC2 側の構成（セットアップ済み）

### deploy ユーザー

| 項目 | 値 |
|---|---|
| ユーザー名 | `deploy` |
| SSH 鍵 | `/home/deploy/.ssh/authorized_keys` に公開鍵登録済み |
| グループ | `apache` グループに所属 |
| sudo 権限 | `chown -R apache:apache /var/www/html` と `systemctl restart/reload httpd` のみ |

### sudo 設定ファイル

`/etc/sudoers.d/deploy`:
```
deploy ALL=(ALL) NOPASSWD: /usr/bin/chown -R apache\:apache /var/www/html/*
deploy ALL=(ALL) NOPASSWD: /usr/bin/chown -R apache\:apache /var/www/html
deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart httpd
deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload httpd
```

---

## 3. ワークフローの仕組み

`.github/workflows/deploy.yml` が以下を行う:

1. **Checkout**: GitHub からコードを取得
2. **SSH 設定**: Secrets の秘密鍵で SSH 接続準備
3. **rsync**: EC2 の `/var/www/html/` にファイルを同期
   - `--delete` で不要ファイルも削除（EC2 のみのファイルは除外設定で保護）
   - 設定ファイル（`config/*.local.php` 等）は除外し、EC2 上のものを保持
4. **権限修正 & reload**: Apache が読めるよう所有権を修正し、httpd を reload

### rsync で除外されるファイル

以下は EC2 上のファイルが保持され、デプロイで上書き・削除されない:

- `config/database.aws.php` — 本番 DB 接続情報
- `config/app.local.php` — 本番アプリ設定
- `config/ai_config.local.php` — AI API キー
- `config/google_*.local.php` — Google 連携設定
- `config/push.local.php` — プッシュ通知設定
- `config/mail.local.php` — メール設定
- `config/vault.local.php` — 金庫マスターキー
- `config/sms.local.php` — SMS 設定
- `config/google.php` — Google 認証情報
- `storage/cache/*.json` — キャッシュファイル
- `logs/` — ログファイル
- `tmp/sessions/` — セッションファイル
- `.git/`, `.github/`, `.cursor/` — 開発ツール設定

---

## 4. セキュリティ上の注意点

### やってあること

- **deploy 専用ユーザー**: ec2-user ではなく権限を限定した専用ユーザーを使用
- **sudo 制限**: deploy ユーザーは `chown` と `systemctl` のみ実行可能。シェルの自由な操作はできない
- **SSH 鍵の分離**: 既存の `social9-key.pem` とは別の鍵を使用。GitHub 専用
- **設定ファイルの保護**: rsync の除外設定で本番の機密ファイルを保護

### 追加で推奨すること

- **ブランチ保護**: GitHub の Settings → Branches で main ブランチに保護ルールを設定し、直接 push を禁止、PR 必須にする
- **Elastic IP**: EC2 の IP が変わると `EC2_HOST` の更新が必要。Elastic IP を割り当て済みなら問題なし
- **SSH 鍵のローテーション**: 年に 1 回程度、deploy 用の SSH 鍵を再発行して更新する

---

## 5. SQL マイグレーション

SQL マイグレーションは CI/CD に含めていない。DB 変更が必要な場合は従来どおり手動で実行する。

手順: [SERVER_DEPLOY_AND_SQL.md](./SERVER_DEPLOY_AND_SQL.md)

---

## 6. トラブルシューティング

### デプロイが失敗した場合

1. GitHub の Actions タブでログを確認
2. よくある原因:
   - `EC2_SSH_KEY` の値が間違っている（改行が欠けている等）
   - EC2 のセキュリティグループで SSH（ポート 22）がブロックされている
   - EC2 の IP が変わった（Elastic IP でない場合）

### 手動でデプロイしたい場合

EC2 に SSH して `rsync` でローカルと同期するか、GitHub の Actions タブから「Run workflow」で main ブランチを指定してワークフローを手動実行できる。

---

## 関連ドキュメント

- [SERVER_DEPLOY_AND_SQL.md](./SERVER_DEPLOY_AND_SQL.md) — SQL マイグレーションの実行手順
