# EC2 サーバーにアップロードするファイル一覧

**推奨**: 本番反映は **PowerShell で scp する手順** で行う。→ **`DOCS/DEPLOY_POWERSHELL_SCP.md`** を参照。

以下を WinSCP で EC2 にアップロードすることも可能。

---

## 【優先】セキュリティ関連（すぐ実施）

| ローカルファイル | リモート先（EC2） |
|-----------------|-------------------|
| `.htaccess` | `/var/www/html/.htaccess` |

**目的**: server-check.php へのアクセスを禁止し、サーバー情報の公開を防ぐ

※ WinSCP で `.htaccess` が表示されない場合: 表示 → 隠しファイルを表示

---

## 基本アップロード一覧

| ローカルファイル | リモート先（EC2） |
|-----------------|-------------------|
| `includes/chat/topbar.php` | `/var/www/html/includes/chat/topbar.php` |

---

## 中優先度: アップロード推奨

| ローカルファイル | リモート先（EC2） |
|-----------------|-------------------|
| `config/session.php` | `/var/www/html/config/session.php` |

**目的**: HTTPS 時のセッションクッキーを secure にする（セキュリティ強化）

※ `app.local.php` は EC2 上で `app.local.production.php` をコピー済みの場合は不要

---

## アップロード手順（WinSCP）

1. WinSCP で `ec2-user@54.95.86.79` に接続
2. 左（ローカル）: `c:\xampp\htdocs\nine\`
3. **右（リモート）: 必ず `/var/www/html/` を開く**（`/home/ec2-user/` ではない。Apache のドキュメントルートは `/var/www/html/` のため、ここにアップロードしないと本番サイトに反映されません）
4. 以下をドラッグ＆ドロップで上書き：
   - `includes/chat/topbar.php` → `includes/chat/` フォルダへ
   - `api/server-check.php` → `api/` フォルダへ

**デザイン・時計クローバー等の表示を変えた場合**: 次も `/var/www/html/` 配下にアップロードしてください。
- `includes/design_loader.php` → `includes/`
- `includes/design_config.php`（テーマの色定義を変えた場合）→ `includes/`
- `assets/css/chat-main.css`（および変更したCSS）→ `assets/css/`

※ テーマ別の吹き出し色は `chat-main.css` にフォールバックがあるため、**このファイルだけ新しくしてもチェリー等の色は反映されます**。反映されない場合は DOCS/DEPLOY_TROUBLESHOOTING.md の「デザイン（テーマ色・チャット吹き出し）が反映されない場合」を参照し、opcache クリア（`sudo systemctl restart httpd`）を試してください。

---

## 各ファイルの役割

| ファイル | 役割 |
|----------|------|
| `topbar.php` | 「テスト」バッジを上パネルに表示（本番反映の確認用） |
| `server-check.php` | デプロイ確認用 API。`topbar_has_test_badge` 等でサーバー状態を確認 |

---

## 確認方法

アップロード後、ブラウザで以下を開く：

- `http://54.95.86.79/api/server-check.php` … JSON が表示されれば OK（本番では .htaccess で 403 の可能性あり）
- `http://54.95.86.79/chat.php` … チャットが表示されれば OK

**hosts で social9.jp を EC2 に固定した場合**は、`http://social9.jp/` でも同様に確認できます。
