# migration_standard_design_only.sql の実行手順（PowerShell）

本番で MySQL に接続し、標準デザイン統一用マイグレーションを実行します。  
**※ 接続情報（ホスト・ユーザー・DB名）は環境に合わせて書き換えてください。**

---

## ローカル（XAMPP）で実行する場合

### 事前に：MySQL を起動する

- **XAMPP Control Panel** を開き、**MySQL** の **Start** をクリックして起動する。
- `ERROR 2002 (HY000): Can't connect to MySQL server on 'localhost' (10061)` は、MySQL が止まっているときに出ます。

### 1. 最初にコピーして実行（プロジェクト直下へ移動）

```powershell
cd c:\xampp\htdocs\nine
```

### 2. 次にコピーして実行（MySQL で SQL ファイルを実行）

```powershell
Get-Content .\database\migration_standard_design_only.sql -Raw | & "C:\xampp\mysql\bin\mysql.exe" -h localhost -u root -p social9
```

- パスワードを聞かれたら、XAMPP の MySQL root のパスワードを入力（未設定の場合はそのまま Enter）。
- データベース名が `social9` でない場合は、最後の `social9` を実際の DB 名に書き換えてください。

---

## 本番サーバーなどで実行する場合

### 1. 最初にコピーして実行（プロジェクト直下へ移動）

```powershell
cd c:\xampp\htdocs\nine
```

※ 本番サーバーで実行する場合は、マイグレーションを置いたディレクトリに合わせてパスを変更してください。

### 2. 次にコピーして実行（接続情報を実際の値に書き換える）

```powershell
Get-Content .\database\migration_standard_design_only.sql -Raw | & "C:\xampp\mysql\bin\mysql.exe" -h 実際のホスト -u 実際のユーザー -p 実際のDB名
```

- `実際のホスト` … 本番 DB のホスト（例: `localhost` や RDS のエンドポイント）
- `実際のユーザー` … DB ユーザー名
- `実際のDB名` … 接続先データベース名（例: `social9`）
- **「ホスト名」「ユーザー名」「データベース名」のままにしないで、必ず実際の値に置き換えてください。**

---

## 別のやり方：mysql にログインしてから実行する場合

### 1. 最初にコピーして実行（MySQL にログイン）

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -h ホスト名 -u ユーザー名 -p データベース名
```

パスワードを入力して Enter。

### 2. 次にコピーして実行（mysql プロンプト内で）

```sql
source c:/xampp/htdocs/nine/database/migration_standard_design_only.sql
```

※ パスはスラッシュ `/` で。本番サーバーでは、その環境のフルパスに書き換えてください。

---

## 実行する SQL の中身（コピー用）

ファイルを渡さず、SQL だけ貼りたい場合用です。

```sql
UPDATE user_settings
SET theme = 'lavender',
    background_image = 'none',
    updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
WHERE theme != 'lavender' OR (background_image IS NOT NULL AND background_image != 'none' AND background_image != '');
```
