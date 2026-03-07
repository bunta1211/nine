# PWA／携帯アプリ登録用アイコン生成手順

## 携帯アプリ登録時に表示するロゴ（推奨）

**緑のクローバー＋オレンジの「9」のロゴ**を、ホーム画面追加時のアイコンとして使う場合の手順です。

1. **ロゴ画像を配置**
   - 使いたいロゴ画像（PNG または JPG、正方形推奨）を **`assets/icons/logo-source.png`** に保存する。
   - ファイル名は `logo-source.png` / `logo-source.jpg` または `social9-logo.png` / `social9-logo.jpg` のいずれか。
2. **アイコンを生成**
   - ブラウザで `https://あなたのドメイン/assets/icons/generate-icons.php` にアクセスする（ローカルなら `http://localhost/nine/assets/icons/generate-icons.php`）。
   - またはコマンドライン: `php assets/icons/generate-icons.php`
3. **本番でアイコンを生成する（重要）**
   - ロゴ画像（`social9-logo.jpg` など）を本番の `assets/icons/` にアップロードしただけでは、既存の PNG はそのままです。
   - **本番サーバー上で**アイコンを再生成する必要があります。
     - **推奨・方法B**: SSH でサーバーに接続し、`cd /var/www/html` のあと `php assets/icons/generate-icons.php` を実行する。  
       → Web サーバー（apache）の権限では `assets/icons/` に書き込めず、ブラウザで「完了」と出ても実際には保存されていないことがあります。
     - 方法A: ブラウザで `https://social9.jp/assets/icons/generate-icons.php` を開く。  
       → ページに「失敗: icon-72x72.png（書き込み権限を確認してください）」と出た場合や「確認: icon-192x192.png … 更新日時=-」の場合は、**方法B（SSH）で実行**してください。  
       → 書き込み可能にする例: `chmod 775 /var/www/html/assets/icons` および `chown -R apache:ec2-user /var/www/html/assets/icons`（環境に合わせてユーザー名を変更）
   - 生成が完了すると、同じ `assets/icons/` 内の `icon-192x192.png` 等が上書きされ、更新日時が変わります。manifest はこの更新日時をバージョンとして参照するため、次回の「ホームに追加」で新しいロゴが使われます。

4. **反映**
   - 携帯で一度アプリを削除し、ブラウザで social9.jp を開いてから再度「ホーム画面に追加」する。
   - キャッシュの関係で古いアイコンが出る場合は、ブラウザのキャッシュを削除するか、シークレット表示で試す。

**ロゴが変わらないときの確認**
- **manifest.php** が本番のルート（`/var/www/html/manifest.php`）にアップロードされているか。ないとキャッシュ無効化が効かず古いアイコンが使われます。
- **アイコン生成を SSH で実行したか**。ブラウザ実行だけだと権限で保存に失敗していることがあります。ページに「確認: icon-192x192.png … 更新日時=-」と出ていたら SSH で再実行してください。
- **chat.php / index.php / sw.js** の最新版（manifest.php とアイコンに `?v=` を付与する変更）が本番に反映されているか。

`logo-source.png` を置かずに generate-icons.php を実行した場合は、従来どおりプログラム描画（クローバー＋S9）のアイコンが生成されます。

---

## 404 エラー（icon-144x144.png 等）の解消

manifest.json で参照している PNG アイコンが存在しない場合、以下の手順で生成してください。

### 方法1: ブラウザでアクセス

1. ローカル環境で XAMPP を起動
2. ブラウザで `http://localhost/nine/assets/icons/generate-icons.php` にアクセス
3. 生成完了後に `assets/icons/` に配置された PNG をサーバーにアップロード

### 方法2: コマンドライン（GD 有効時）

```bash
cd /path/to/nine
php assets/icons/generate-icons.php
```

**注意**: php.ini で `extension=gd` が有効になっている必要があります。

### 方法3: PowerShell（GD が使えない場合・Windows）

PHP の GD が無効で `generate-icons.php` が使えない場合、Windows では PowerShell で代替生成できます。緑背景＋白い「9」のアイコンが生成されます。

```powershell
cd c:\xampp\htdocs\nine
powershell -ExecutionPolicy Bypass -File assets/icons/generate-icons.ps1
```

生成後、`assets/icons/` にできた PNG をそのままコミットするか本番にアップロードしてください。  
本番サーバーが Linux の場合は、ローカルまたは CI で生成した PNG を `assets/icons/` に配置してデプロイするか、本番で `php assets/icons/generate-icons.php`（GD 有効）を実行してください。

### 生成されるファイル

- icon-72x72.png
- icon-96x96.png
- icon-128x128.png
- icon-144x144.png
- icon-152x152.png
- icon-192x192.png
- icon-384x384.png
- icon-512x512.png
- apple-touch-icon.png（180x180）
- favicon-32x32.png
