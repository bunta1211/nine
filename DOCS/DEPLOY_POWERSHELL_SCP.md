# 本番（EC2）へのファイル反映 — scp

本番サーバー（EC2）へファイルをアップロードする手順です。

---

## 前提

- EC2: `ec2-user@54.95.86.79`
- ドキュメントルート: `/var/www/html/`
- 秘密鍵: **実際に .pem を置いているパス**を指定する（下記参照）

| 環境 | PEM キーのパス |
|------|---------------|
| **Mac** | `~/.ssh/social9-key.pem` |
| **Windows** | `C:\Users\narak\Desktop\social9-key.pem` |

---

## 手順A: Mac / Linux で deploy.sh を使う

プロジェクトルートの `deploy.sh` を使って簡単にデプロイできます。

### 特定のファイルだけ送信

```bash
cd /Users/yusei/Documents/Project/nine
./deploy.sh api/messages.php
./deploy.sh includes/chat/scripts.php assets/css/chat-main.css
```

### 定義済みファイルを一括送信

```bash
./deploy.sh
```

### 手動で scp を実行する場合

```bash
KEY="$HOME/.ssh/social9-key.pem"
EC2="ec2-user@54.95.86.79"
scp -i "$KEY" api/messages.php "$EC2:/var/www/html/api/"
```

---

## 手順B: Windows — PowerShell で scp を使う

### 1. プロジェクトフォルダへ移動

```powershell
cd c:\xampp\htdocs\nine
```

### 2. キーパスの注意

**`C:\Users\user\Desktop\social9-key.pem` は「user」というユーザー名の例です。**  
鍵が別の場所にある場合は、そのパスに書き換えてください。

- 例（鍵がデスクトップにある場合）:  
  `C:\Users\narak\Desktop\social9-key.pem`
- 例（鍵が Downloads にある場合）:  
  `C:\Users\narak\Downloads\social9-key.pem`

パスが間違っていると次のエラーになります。  
`Identity file ... not accessible: No such file or directory`

### 3. 初回のみ: ホスト鍵の確認

初めて 54.95.86.79 に接続するとき、  
`Are you sure you want to continue connecting (yes/no/[fingerprint])?`  
と出たら **`yes`** と入力して Enter。  
ここで入力しないと `Host key verification failed` で接続が閉じます。

### 4. ファイルを 1 つアップロードする

**`<鍵のパス>` を実際の .pem のパスに書き換えてから実行してください。**

```powershell
scp -i "<鍵のパス>" c:\xampp\htdocs\nine\api\messages.php ec2-user@54.95.86.79:/var/www/html/api/
```

```powershell
scp -i "<鍵のパス>" c:\xampp\htdocs\nine\includes\chat\scripts.php ec2-user@54.95.86.79:/var/www/html/includes/chat/
```

例（鍵が `C:\Users\narak\Desktop\social9-key.pem` の場合）:

```powershell
scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\api\messages.php ec2-user@54.95.86.79:/var/www/html/api/
scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\includes\chat\scripts.php ec2-user@54.95.86.79:/var/www/html/includes/chat/
```

**AI秘書でPDF・ファイル添付を使う場合**は、少なくとも次の3つを本番に反映してください（`file_path` 送信は scripts.php 側のため）。  
`api/ai.php` と `includes/ai_file_reader.php` に加え、**`includes/chat/scripts.php`** もアップロードしてください。

#### AI秘書・PDFまわり 3ファイルだけ一括送信（PowerShell）

次のブロックをコピーして PowerShell で実行すると、上記3ファイルだけをまとめて送信できます。

```powershell
cd c:\xampp\htdocs\nine
$key = "C:\Users\narak\Desktop\social9-key.pem"
$ec2 = "ec2-user@54.95.86.79"
$root = "c:\xampp\htdocs\nine"
scp -i $key "$root\api\ai.php" "${ec2}:/var/www/html/api/"
scp -i $key "$root\includes\ai_file_reader.php" "${ec2}:/var/www/html/includes/"
scp -i $key "$root\includes\chat\scripts.php" "${ec2}:/var/www/html/includes/chat/"
Write-Host "Done. 3 file(s) uploaded."
```

### 5. 一括アップロード（deploy-bulk.ps1 / deploy-bulk.cmd）

複数ファイルをまとめて送る場合は、プロジェクトルートの **`deploy-bulk.cmd`** または **`deploy-bulk.ps1`** を使います。  
鍵パスは `C:\Users\narak\Desktop\social9-key.pem` で記録済みです（変更する場合はファイル先頭の `KEY` / `$key` を編集）。

**実行ポリシーで .ps1 が実行できない場合**は、**.cmd を実行してください**（ポリシー変更不要）:

```powershell
cd c:\xampp\htdocs\nine
.\deploy-bulk.cmd
```

PowerShell で .ps1 を実行できる場合:

```powershell
cd c:\xampp\htdocs\nine
.\deploy-bulk.ps1
```

送信対象を変えるときは、`deploy-bulk.ps1` の `$files` 配列、または `deploy-bulk.cmd` 内の scp 行を追加・削除してください。

### 6. 今日の話題をデプロイした場合（KEN への配信テスト）

`config/app.php` や `cron/run_today_topics_test_once.php` などを本番に反映したあと、**デプロイ後すぐにニュース配信テスト**を行うには、EC2 に SSH して次を 1 回実行します。

```bash
php /var/www/html/cron/run_today_topics_test_once.php
```

これでニュースを取得し、KEN（user_id=6）に「本日のニューストピックス」が 1 通送信されます。詳細は [DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md](TODAY_TOPICS_PHASED_ROLLOUT.md) を参照してください。

---

## 手順2: WinSCP でアップロードする（代替）

scp で鍵が見つからない・ホスト鍵で止まる場合は、**WinSCP** で同じファイルをアップロードできます。

1. WinSCP で `ec2-user@54.95.86.79` に接続（秘密鍵のパスは WinSCP の設定で指定）。
2. 左: `c:\xampp\htdocs\nine\api\` → 右: `/var/www/html/api/`  
   `messages.php` を右側にドラッグして上書き。
3. 左: `c:\xampp\htdocs\nine\includes\chat\` → 右: `/var/www/html/includes/chat/`  
   `scripts.php` を右側にドラッグして上書き。

詳細は [WinSCP で AWS EC2 に接続する設定](./AWS_WINSCP_SETUP.md) を参照。

---

## アップロード後の権限（必要な場合）

EC2 上で Apache が読めるようにするには:

```bash
sudo chown -R apache:apache /var/www/html/
```

または対象だけ:

```bash
sudo chown -R apache:apache /var/www/html/api/
sudo chown -R apache:apache /var/www/html/includes/chat/
```

---

*本番 DB への接続方法: [本番データベースへの接続方法](./PRODUCTION_DB_ACCESS.md)*
