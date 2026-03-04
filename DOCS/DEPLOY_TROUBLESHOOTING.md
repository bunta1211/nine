# デプロイ反映されないときの切り分け

サーバー移転後、サイトの変更が反映されない場合の原因と対処方法です。

---

## 0. まず実行する確認（推奨順）

### ステップA: デプロイ確認のURL（403 になる場合）

`api/server-check.php` は **.htaccess で意図的に 403 にしています**（セキュリティのため）。本番では使えません。

**代わりに**：**管理者でログインした状態で** 以下を開いてください。

| URL | 備考 |
|-----|------|
| https://social9.jp/api/health.php?action=deploy | 本番で「どのフォルダのファイルが使われているか」を確認（要・管理者ログイン） |

同じ内容を IP 直で確認する場合：

| URL | 備考 |
|-----|------|
| http://54.95.86.79/api/health.php?action=deploy | EC2 直接（要・管理者ログイン） |

### ステップB: 2つのURLで比較

**管理者ログイン済み**のブラウザで、以下を**別タブ**で開く（スーパーリロード推奨）：

| URL | 備考 |
|-----|------|
| https://social9.jp/api/health.php?action=deploy | ドメイン経由（普段見ているサイトと同じサーバーか確認） |
| http://54.95.86.79/api/health.php?action=deploy | EC2 直接 |

**アップロード先の確認（送り先が違うとき）：**
- `base_dir` / `base_dir_realpath` … **このサーバーが参照しているプロジェクトのルートパス**。FTP・WinSCP の「アップロード先」がこのパス（またはその配下）と一致しているか確認する。
- `topbar_realpath` … topbar.php の実際のフルパス。ここにアップロードしたファイルが反映される。
- `topbar_preview_line` … サーバー上の topbar の該当1行。ここに「テスト」や `topbar-deploy-test` が含まれていれば反映済み。

**比較する項目：**
- `deploy_verify` … 毎回変わるタイムスタンプ（同じなら同一サーバー）
- `server_addr` … サーバー内部IP（同じなら同一サーバー）
- `topbar_has_test_badge` … サーバー上の topbar に「テスト」が含まれるか（true=反映済み）
- `topbar_mtime` … topbar.php の更新日時（今日の日付ならアップロード済み）

**判定：**

| 状況 | 想定原因 | 対処 |
|------|----------|------|
| social9.jp で 404 や接続エラー | DNS未反映 or 別サーバー | ステップ1（DNS確認） |
| 2つのURLで `server_addr` が**異なる** | social9.jp が旧サーバーを指している | Route 53 / DNS の設定確認 |
| 2つのURLで `server_addr` が**同じ** かつ mtime が古い | ファイル未アップロード | WinSCP で再アップロード |
| `topbar_has_test_badge` が false | サーバー上の topbar に「テスト」がない | **アップロード先を `base_dir` と照合**。別フォルダに上げている場合は `base_dir` 配下に上げ直す。 |
| 2つのURLで**同じ** かつ mtime が今日 | サーバー反映OK・ブラウザキャッシュの可能性 | ステップ2（キャッシュ対策） |

---

## 1. 原因の切り分け

### 確認1: どちらのサーバーに接続しているか

**PowerShell で実行：**

```powershell
# social9.jp のIPを確認
nslookup social9.jp
```

**期待する結果：**
- `54.95.86.79` … 新しい EC2 を指している（正常）
- `157.7.189.251` など別IP … 旧サーバー（heteml）を指している（DNS未反映）

---

### 確認2: サーバー判定APIで比較

以下の2つのURLをブラウザで開き、表示を比較する：

| URL | 想定 |
|-----|------|
| http://social9.jp/api/server-check.php | ドメイン経由 |
| http://54.95.86.79/api/server-check.php | EC2 直接 |

**比較ポイント：**
- `server_addr` が同じか
- `design_config_mtime` / `design_loader_mtime` / `topbar_mtime` が同じか
- 各 mtime が今日の日付か（修正版がデプロイされているか）

**結果の解釈：**
- **同一サーバー・同一ファイル** → ブラウザキャッシュの可能性
- **別サーバー** → DNS やプロキシが旧サーバーを指している
- **同一サーバーで古い mtime** → ファイルがまだアップロードされていない

---

## 2. 対処方法

### DNS が旧サーバーを指している場合

1. **ムームードメイン**でネームサーバーが Route 53 になっているか確認
2. **Route 53** の A レコードで `54.95.86.79` が正しく設定されているか確認
3. DNS  propagated は最大 48 時間かかることがある
4. **一時対応**: 直接 `http://54.95.86.79/chat.php` でアクセスして動作確認

### ブラウザキャッシュの場合

1. **スーパーリロード**: `Ctrl + Shift + R` (Windows) / `Cmd + Shift + R` (Mac)
2. **シークレットウィンドウ**で開いて確認
3. Chrome: デベロッパーツール → Network → 「Disable cache」にチェック → 再読み込み

### 403 Forbidden が表示される場合（EC2 の 54.95.86.79 で）

**原因**: `search permissions are missing on a component of the path` → ディレクトリに検索（実行）権限がない

**対処（これを先に実行）**:

```bash
# ディレクトリに検索（実行）権限を付与（755 必須）
sudo find /var/www/html -type d -exec chmod 755 {} \;

# PHP ファイルの権限を確認
sudo find /var/www/html -name "*.php" -exec chmod 644 {} \;

sudo systemctl restart httpd
```

---

#### 手順1: ファイルの存在確認

```bash
ls -la /var/www/html/api/server-check.php
```

- 表示されない → ファイル未アップロード。WinSCP でアップロードする

#### 手順2: パーミッションと所有者（上記で解決しない場合）

```bash
# ディレクトリ 755、PHP ファイル 644
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo find /var/www/html -name "*.php" -exec chmod 644 {} \;

# 所有者を apache に（Apache が読み取れるように）
sudo chown -R apache:apache /var/www/html/
```

※ WinSCP でアップロードするため ec2-user に戻す必要がある場合は、後で `sudo chown -R ec2-user:apache /var/www/html/` を実行

#### 手順3: SELinux の一時無効化で切り分け

```bash
# 一時的に SELinux を Permissive に（原因切り分け用）
sudo setenforce 0
```

その後、ブラウザで `http://54.95.86.79/api/server-check.php` を再読み込み：

- **表示される** → SELinux が原因。手順4 へ
- **403 のまま** → パーミッションか Apache 設定が原因

#### 手順4: SELinux を元に戻し、コンテキストを設定

```bash
# SELinux を Enforcing に戻す
sudo setenforce 1

# 正しいコンテキストを設定（既存の html を参照）
sudo chcon -R --reference=/var/www/html /var/www/html/api/

# または restorecon でデフォルトに戻す
sudo restorecon -R -v /var/www/html/

sudo systemctl restart httpd
```

### 404 Not Found が social9.jp で表示される場合

- **social9.jp が旧サーバー（heteml 等）を指している**可能性が高い
- `http://54.95.86.79/chat.php` で直接アクセスして、EC2 に接続できているか確認

### ファイルがアップロードされていない場合

WinSCP で以下をアップロード：

| ファイル | 配置先 |
|----------|--------|
| `api/server-check.php` | `/var/www/html/api/` |
| `includes/design_config.php` | `/var/www/html/includes/` |
| `includes/design_loader.php` | `/var/www/html/includes/` |
| `includes/chat/topbar.php` | `/var/www/html/includes/chat/` |

**アップロード後、server-check.php を再読み込み**して `topbar_mtime` が更新されているか確認する。

### PHP opcache が効いている場合

EC2 に SSH 接続して：

```bash
# Apache 再起動で opcache クリア
sudo systemctl restart httpd
```

### デザイン（テーマ色・チャット吹き出し）が反映されない場合

**症状**: チェリーなのに吹き出しが白／薄緑のまま、などテーマに合った色にならない。

**依存関係（次の3つが揃っている必要があります）**:

| ファイル | 役割 |
|----------|------|
| `includes/design_loader.php` | テーマごとの色をインラインCSSで出力。**ここを更新したら本番でも必ずこのファイルをアップロードする。** |
| `includes/design_config.php` | テーマ定義（フォレスト・チェリー等の色）。色を変えた場合はこちらもアップロード。 |
| `assets/css/chat-main.css` | テーマ別のフォールバックを定義。**design_loader が opcache で古いままでも、このファイルだけ新しくすればテーマ色が効く。** |

**確認手順**:

1. **ページのソースを表示**（右クリック → ページのソース）し、`デザインCSS ver.2` または `theme=cherry` で検索する。
   - 出てこない → サーバーで読まれている `design_loader.php` が古い、または opcache で古い版が使われている。
   - `theme=cherry` などが出てくる → インラインCSSは出ている。次の項目へ。

2. **opcache をクリアする**（本番で PHP の opcache が有効な場合、PHP を更新しても古いバイトコードが使われることがあります）:
   ```bash
   sudo systemctl restart httpd
   ```

3. **必ずアップロードするファイル**（デザイン修正後）:
   - `includes/design_loader.php`
   - 必要に応じて `includes/design_config.php`
   - **必ず** `assets/css/chat-main.css`（テーマ別フォールバック入りなので、ここだけ新しくしても吹き出し色は変わる）

4. ブラウザは**スーパーリロード**（Ctrl+Shift+R）で再読み込み。

**補足**: `chat-main.css` に `body[data-theme="cherry"]` 等のフォールバックを入れているため、**design_loader が古くても `chat-main.css` を新しくデプロイすればテーマ色は反映されます**。まずは `assets/css/chat-main.css` のアップロードとスーパーリロードを試してください。

**透明テーマ＋明るい背景の場合**: 吹き出し色は以前「薄緑／白」で固定されていましたが、現在は `--dt-msg-*` のデザイントークンを参照するように変更済みです。背景画像に合わせた色を出したい場合は `chat-main.css` を本番に反映し、design_loader の背景別オーバーライド（`getBackgroundDesignOverrides`）で希望の色を定義してください。

### ファイル・スクショ送信で「ファイルの保存に失敗しました」と表示される場合

チャットでファイルやスクリーンショットを送信する際に保存に失敗する場合は、**アップロード先フォルダの権限**を確認してください。

**EC2 で実行（例）：**

```bash
# uploads と uploads/messages を作成し、Apache から書き込み可能に
sudo mkdir -p /var/www/html/uploads/messages
sudo chown -R apache:apache /var/www/html/uploads
sudo chmod -R 0755 /var/www/html/uploads
```

- 画像・ファイル送信は `api/messages.php` の `upload_file` で `uploads/messages/` に保存します。
- `api/upload.php` は `uploads/YYYY/MM/` に保存します。いずれも **Web サーバー（apache）が書き込めること**が必要です。

---

## 3. server-check.php について

- 用途: デプロイ確認・切り分け用
- 本番稼働後は削除またはアクセス制限を推奨
- 削除する場合: `api/server-check.php` を削除
