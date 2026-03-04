# 本番で migration_messages_reply_to_id.sql を実行する（PowerShell・コピー用）

返信引用機能用に `messages.reply_to_id` カラムを追加するマイグレーションを、本番で実行する手順です。  
**各ブロックをそのままコピーして、PowerShell または EC2 で順に実行してください。**

---

## 鍵のパスについて

このドキュメントでは、次の鍵パスを使っています。

```
C:\Users\narak\Desktop\social9-key.pem
```

※ 鍵の場所が違う場合は、[本番データベースへの接続方法](./PRODUCTION_DB_ACCESS.md) を参照し、手順2・3の `-i` の後のパスだけを書き換えてください。

---

## 手順1: プロジェクトフォルダへ移動

PowerShell で次をコピーして実行します。

```powershell
cd c:\xampp\htdocs\nine
```

---

## 手順2: マイグレーションSQLをEC2に送る

次を**1行まるごと**コピーして実行します。

```powershell
scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\database\migration_messages_reply_to_id.sql ec2-user@54.95.86.79:/home/ec2-user/
```

初回のみ「続行しますか？」と出たら **`yes`** と入力して Enter。

---

## 手順3: EC2にSSHで入る

次を**1行まるごと**コピーして実行します。

```powershell
ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

プロンプトが `[ec2-user@...]$` のようになれば成功です。

---

## 手順4: RDSに対してマイグレーションを実行する

EC2に接続した状態で、次を**1行まるごと**コピーして実行します。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_messages_reply_to_id.sql
```

**`-p` のあとで RDS のパスワードを入力します。**  
（`/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS` の値。入力中は画面に表示されません。）

- エラーがなくプロンプトに戻れば成功です。
- エラーが出た場合は、表示されたメッセージを控えてください。

---

## 手順5: 結果を確認する（任意）

カラムが追加されたか確認する場合、EC2のシェルで次を実行します。

まず MySQL に接続（パスワードを聞かれたら入力）:

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9
```

接続できたら、次のSQLをコピーして貼り付けて Enter:

```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'social9' AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'reply_to_id';
```

`reply_to_id` の1行が表示されれば追加されています。終了するときは:

```sql
exit
```

---

## 手順6: EC2から抜ける

EC2のシェルで次を実行します。

```bash
exit
```

---

## コピー用まとめ（鍵パス込み）

| # | 実行場所 | コピーして実行する内容 |
|---|----------|--------------------------|
| 1 | PowerShell | `cd c:\xampp\htdocs\nine` |
| 2 | PowerShell | `scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\database\migration_messages_reply_to_id.sql ec2-user@54.95.86.79:/home/ec2-user/` |
| 3 | PowerShell | `ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79` |
| 4 | EC2 | `mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_messages_reply_to_id.sql` |
| 5 | EC2 | `exit` |

---

## 引用がリロードで消える場合の確認

マイグレーション済みでも引用がリロードで消える場合は、次を順に確認してください。

1. **api/messages.php を本番に反映しているか**  
   送信直後に `UPDATE messages SET reply_to_id = ? WHERE id = ?` が動くのはこのファイルです。未反映なら scp でアップロードしてください。
   ```powershell
   scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\api\messages.php ec2-user@54.95.86.79:/var/www/html/api/
   ```
2. **includes/chat/data.php を本番に反映しているか**  
   初回表示で `reply_to_id` 付きでメッセージを取得するのはこのファイルです。
   ```powershell
   scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\includes\chat\data.php ec2-user@54.95.86.79:/var/www/html/includes/chat/
   ```
3. **chat.php を本番に反映しているか**  
   引用ブロックを描画するのはこのファイルです。
   ```powershell
   scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\chat.php ec2-user@54.95.86.79:/var/www/html/
   ```
4. **PHP の opcache を使っている場合**  
   反映後に Apache または PHP-FPM を再起動すると、新しいコードが確実に読み込まれます。
   ```bash
   sudo systemctl restart httpd
   ```
5. **動作確認**  
   「返信」でメッセージを送信 → 引用が表示される → **ページをリロード** → 同じメッセージに引用が残っていれば成功です。  
   ※ 修正前に送ったメッセージは DB に `reply_to_id` が入っていないため、リロード後も引用は出ません。**修正後に送った返信**で確認してください。

---

## 関連ドキュメント

- [本番データベースへの接続方法](./PRODUCTION_DB_ACCESS.md)
- [CI/CD 自動デプロイ](./CI_CD_SETUP.md) — main マージで本番に反映
