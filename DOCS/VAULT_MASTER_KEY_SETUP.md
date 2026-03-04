# 金庫用 VAULT_MASTER_KEY の設定（コピペのみ）

---

## サイトが白画面になった場合（復旧手順）

1. **修正した `config/app.php` を本番に再アップロードする**  
   （vault.local.php の読み込みを外した版なので、アップロードすればサイトは復旧します。）

2. **金庫用キーはサーバーの `config/app.local.php` に 1 行追加する**  
   サーバーで `config/app.local.php` を開き、**先頭付近**（`<?php` の次など）に次の 1 行を追加して保存する。  
   `あなたの64文字キー` のところは、ローカルの `config\vault.local.php` に書いてあるキー（または控え）に置き換える。

   ```php
   define('VAULT_MASTER_KEY', 'あなたの64文字キー');
   ```

   - `app.local.php` は通常 Git に含めず、サーバーだけに置くファイルなので、上書きアップロードの影響を受けません。
   - これで金庫が使えるようになります。

---

## Windows でやるとき（PowerShell）

**ここだけ実行すれば完了です。** 下の「本番サーバーでやるとき」は実行しません。

### 1. PowerShell を開く

- スタートメニューで「PowerShell」と入力して開く  
  または  
- エクスプローラーで `C:\xampp\htdocs\nine` を開き、アドレス欄に `powershell` と入力して Enter

### 2. 以下をすべてコピーして PowerShell に貼り付け、Enter

```powershell
cd C:\xampp\htdocs\nine
$key = -join ((1..32 | ForEach-Object { [byte](Get-Random -Maximum 256) }) | ForEach-Object { '{0:x2}' -f $_ })
$php = @"
<?php
define('VAULT_MASTER_KEY', '$key');
"@
if (-not (Test-Path 'config')) { New-Item -ItemType Directory -Path 'config' | Out-Null }
Set-Content -Path 'config\vault.local.php' -Value $php -Encoding UTF8
Write-Host '作成しました: config\vault.local.php'
Write-Host 'キー(控え): ' $key
```

- 上を 1 回貼り付けて Enter するだけで、`config\vault.local.php` が作成されます。
- 表示された「キー(控え)」は必要ならメモしてください。

### 3. 本番サーバーに送る

**重要:** いまの `config/app.php` は **vault.local.php を読み込みません**。本番で金庫を使うには、サーバーの **config/app.local.php** に次の 1 行を追加してください。

```php
define('VAULT_MASTER_KEY', 'ここにローカルで表示した64文字キーを貼る');
```

（vault.local.php をサーバーにアップロードする方法は、以前の手順では白画面の原因になったため廃止しています。）

- 本番では **config/app.local.php** に `define('VAULT_MASTER_KEY', '…');` を 1 行追加すれば金庫が使えます（WinSCP で app.local.php を開いて編集するか、SSH で `nano /var/www/html/config/app.local.php` などで追加）。

### 4. 本番で設定が読まれているか確認する

ブラウザで **https://social9.jp/api/deploy-check.php** を開く。  
表示の **vault_configured** が **true** なら、金庫用のキーは本番で読まれています。

---

## 本番サーバー（Linux）で最初からキーを作るとき

※ **Windows の PowerShell では実行しないでください。** 文法が違うためエラーになります。

サーバーに **SSH で接続したあと**、そのターミナル（Bash）で以下をまとめてコピーして貼り付け、Enter で実行します。

```bash
cd /var/www/html
KEY=$(openssl rand -hex 32)
echo "<?php
define('VAULT_MASTER_KEY', '$KEY');
" > config/vault.local.php
echo "作成しました: config/vault.local.php"
```

---

## 注意

- `vault.local.php` は .gitignore に入っているため、Git にはコミットされません。
- キーをなくすと金庫の暗号データは復号できません。必要なら「キー(控え)」をメモしてください。
