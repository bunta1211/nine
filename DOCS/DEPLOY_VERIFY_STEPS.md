# 本番反映の確認手順

social9.jp に「テスト」が表示されない場合の確認チェックリストです。

**ローカルでは「テスト」が出るが本番・アプリでは出ない**  
→ 本番サーバーで「どのフォルダのファイルが読まれているか」を確認し、FTP/WinSCP のアップロード先がそのパスと一致しているか確認してください。

---

## ステップ0: 管理者用デプロイ確認（推奨）

**health.php?action=deploy で 500 が出る場合**（DB 接続エラーなど）は、代わりに **deploy-check.php** を使ってください。DB に依存しないため、サーバーエラー時でもパスと topbar の有無を確認できます。

1. ブラウザで以下を開く（ログイン不要）：
   - **https://social9.jp/api/deploy-check.php**
   - **404 になる場合**：チャットを **どのURLで開いているか** を確認してください。  
     - チャットが `social9.jp/chat.php` なら → API は `social9.jp/api/deploy-check.php`  
     - チャットが `social9.jp/nine/chat.php` などサブディレクトリなら → API は `social9.jp/nine/api/deploy-check.php`（`/nine/` を付ける）  
     また、**deploy-check.php を本番の api フォルダにアップロード済みか**も確認してください。
2. 表示された JSON を確認：
   - **`base_dir`** … このサーバーが参照しているプロジェクトのルート。アップロード先がこのパス（またはその配下）になっているか確認。
   - **`topbar_has_test_badge`** … `true` ならサーバー上の topbar に「テスト」が含まれている（反映済み）。`false` なら別のフォルダにアップロードしている可能性。
   - **`topbar_preview_line`** … サーバー上の topbar の該当1行。ここに「テスト」が含まれていれば反映済み。
   - **`bootstrap_error`** が出ている場合 … DB/セッションでエラーが出ているが、上記のパス・topbar 情報は参照できる。

**DB が正常なとき**は、管理者でログインした状態で **https://social9.jp/api/health.php?action=deploy** でも同じ内容を確認できます。

※ `api/server-check.php` は .htaccess で 403 にしているため本番では使えません。

---

## deploy-check.php が 404 のとき

| 確認すること | 対処 |
|--------------|------|
| チャットのURL | 普段 `social9.jp/chat.php` で開いているか、`social9.jp/nine/chat.php` など別パスか。同じプレフィックスで `…/api/deploy-check.php` を開く。 |
| アップロード先 | WinSCP 等で `api/deploy-check.php` を、**チャットが動いているのと同じディレクトリの api フォルダ**に置く。例: チャットが `/var/www/html/chat.php` なら `/var/www/html/api/deploy-check.php`。 |
| 本番のドキュメントルート | レンタルサーバーや AWS で「ドキュメントルート」が `public_html` や `htdocs` など別名の場合、その中に `api` フォルダを作り、その中に deploy-check.php を置く。 |

---

## ステップ1: EC2 上のファイルを確認

EC2 に SSH 接続して実行：

```bash
# topbar.php に「テスト」が含まれているか
grep -n "テスト" /var/www/html/includes/chat/topbar.php
```

**期待する結果**: `topbar-deploy-test` や `テスト` を含む行が表示される

- **何も表示されない** → ファイル未アップロード。WinSCP で `includes/chat/topbar.php` を再アップロード

---

## ステップ2: IP 直接アクセスで確認

ブラウザで以下を開く：

```
http://54.95.86.79/chat.php
```

| 結果 | 意味 |
|------|------|
| ロゴ横に「テスト」が表示される | EC2 のファイルは正しい。social9.jp の向き先が原因 |
| 「テスト」が表示されない | EC2 の topbar.php が古い。再アップロードが必要 |

---

## ステップ3: hosts ファイルの確認

**管理者メモ帳**で `C:\Windows\System32\drivers\etc\hosts` を開き、以下の行があるか確認：

```
54.95.86.79 social9.jp
54.95.86.79 www.social9.jp
```

- **ない** → 末尾に追加して保存
- **ある** → ブラウザを完全終了してから起動し直す

---

## ステップ4: シークレットモードで確認

1. **Ctrl + Shift + N** でシークレットウィンドウを開く
2. `http://social9.jp/chat.php` を開く（`http://` を明示）
3. ロゴ横に「テスト」が表示されるか確認

---

## まとめ：想定される原因

| 54.95.86.79 で「テスト」 | social9.jp で「テスト」 | 原因 |
|-------------------------|-------------------------|------|
| 表示される | 表示されない | hosts 未設定 or ブラウザキャッシュ |
| 表示されない | - | topbar.php 未アップロード or 古いファイル |
