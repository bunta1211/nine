# 方法2: social9 データベースを正しく作る手順（外部キー付き）

phpMyAdmin で、**social9** データベースを選んだ状態で、次の順に進めてください。

---

## ステップ 1: データベースを選ぶ

1. phpMyAdmin を開く（例: http://localhost/phpmyadmin/）
2. **左側の一覧**で **「social9」** をクリックして選択する  
   （名前が青く反転していれば選択できています）

---

## ステップ 2: SQL タブを開く

1. 画面上部のタブのうち **「SQL」** をクリックする  
2. 大きなテキスト欄（SQL を入力する場所）が表示される

---

## ステップ 3: schema_phase1.sql を実行する

1. パソコンで次のファイルをメモ帳などで開く  
   `C:\xampp\htdocs\nine\database\schema_phase1.sql`
2. **中身をすべて選択**（Ctrl+A）して **コピー**（Ctrl+C）
3. phpMyAdmin の SQL のテキスト欄に **貼り付け**（Ctrl+V）
4. 右下の **「実行」**（または「Go」）ボタンをクリックする

**実行後:**  
「Phase 1 Schema created successfully!」や、テーブル数が表示されれば成功です。  
左の「social9」の横の **▶** をクリックすると、`users`・`conversations`・`messages`・`message_reactions` などのテーブルが一覧に出ます。

ここまでで **users** と **messages** と **message_reactions** が、外部キー付きで作成されています。

---

## ステップ 4: message_reactions をアプリ仕様に合わせる（推奨）

**リアクションがリロードで消える場合**は、テーブル制約を次の手順で合わせてください。

1. 左で **「social9」** を選択したまま、上部の **「SQL」** をクリック
2. 次のいずれかを実行：
   - **ファイルから実行する場合**  
     `C:\xampp\htdocs\nine\database\fix_message_reactions.sql` を開き、中身をすべてコピーして SQL 欄に貼り付け → **実行**
   - **以下をコピーして実行する場合**  

```sql
-- 既存の message_reactions を削除して作り直す（アプリの仕様に合わせる）
DROP TABLE IF EXISTS message_reactions;

CREATE TABLE message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reaction_type VARCHAR(10) NOT NULL DEFAULT '👍',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (message_id, user_id),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_message_id (message_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## まとめ

- **ステップ 1** … 左で **social9** をクリック  
- **ステップ 2** … 上で **SQL** をクリック  
- **ステップ 3** … `schema_phase1.sql` の中身をコピーして貼り付け → **実行**  
- **ステップ 4** … リアクションが消える場合は `database/fix_message_reactions.sql` を実行  

まずは **ステップ 1 → 2 → 3** まで進めて、エラーが出た場合はそのメッセージをそのまま教えてください。
