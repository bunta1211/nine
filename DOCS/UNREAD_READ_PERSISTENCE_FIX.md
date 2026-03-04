# 未読・既読の永続化（次回ログインでも維持する）対応

## 現象
未読を既読にしても、次回ログインすると既読が消え、前回の未読ラインが再度案内されてしまう。

## 多角的な調査と対応

### 1. 既読の二重記録（last_read_at + last_read_message_id）
- **問題**: 未読数の算出に `last_read_at`（日時）のみを使うと、サーバー・クライアントのタイムゾーン差や時刻ずれで「既読なのに未読」になる可能性がある。
- **対応**: `conversation_members.last_read_message_id` を併用。既読更新時に「その会話の最新メッセージID」も保存する。
- **実装**:
  - `includes/chat/data.php` の `updateLastReadAt()`: `last_read_at = NOW()` に加え、`last_read_message_id = (会話内の最大メッセージID)` を一度の UPDATE で設定。`left_at IS NULL` の行のみ更新。
  - `api/conversations.php` の `mark_read`: 上記と同様に両カラムを更新（`last_read_message_id` がない環境では try-catch で `last_read_at` のみ更新にフォールバック）。
  - `api/messages.php` のメッセージ取得時: 既読更新で同じく両カラムを更新。

### 2. 未読数算出の安定化（last_read_message_id 優先）
- **問題**: 未読数サブクエリが `m.created_at > cm.last_read_at` のみだと、日時の比較不整合で件数がずれる可能性がある。
- **対応**: `last_read_message_id` が設定されている場合は「`m.id > last_read_message_id`」で未読数を算出。未設定のときだけ従来どおり `last_read_at` で判定。
- **実装**:
  - `includes/chat/data.php`: 会話一覧の `unread_count` サブクエリを上記ロジックに変更。
  - `api/conversations.php`: `list` と `list_with_unread` の未読数サブクエリを同様に変更。
  - `api/notifications.php`: 未読メッセージ数（バッジ用）のサブクエリを同様に変更。

### 3. 既読 UPDATE の対象を明確化（left_at 考慮）
- **問題**: 退会済み（`left_at` が設定されている）行まで更新すると、意図しない行が更新される可能性がある。
- **対応**: すべての既読 UPDATE に `AND left_at IS NULL` を付与し、「参加中の会話」の行だけを更新。

### 4. 既読更新のタイミングと未読ライン描画（chat.php）
- 既読更新を**先に**実行し、その**直後に**DB から `last_read_at` と `last_read_message_id` を再取得。
- **未読ライン（「↓ここから未読↓」）の判定**: `last_read_message_id` がある場合は **メッセージID** で判定（`id > last_read_message_id` の最初の他人メッセージの手前に表示）。ない場合は従来どおり `last_read_at` と `created_at` の日時比較。  
  → 日時ずれやリロードで既読が戻って見える問題を防ぐため、ID 判定を優先している。

### 5. クライアントからの既読送信（二重化）
- チャット画面表示時に `api/conversations.php?action=mark_read&conversation_id=...` を 1 回呼ぶ（以前の対応で実施済み）。サーバー側更新が失敗してもクライアントから送れるようにする。

## マイグレーション
- `conversation_members` に `last_read_message_id` がない場合は `database/migration_unread_count.sql` を実行する。
- カラムがない環境では、既読更新は `last_read_at` のみ更新するように try-catch でフォールバックしている。

## 確認ポイント
- 会話を開く → 既読になる → ログアウト → 再ログイン → 同じ会話の未読数が 0・未読ラインが出ないこと。
- 一覧の未読バッジ・未読メッセージ数（通知API）が、上記と同じロジックで一貫していること。

---

## 重要なポイント（解決メモ）

### MySQL の制約と既読 UPDATE の実装
- **制約**: `UPDATE conversation_members SET ... = (SELECT ... FROM conversation_members ...)` のように、UPDATE の SET 内で**更新対象テーブル自身**を参照する相関サブクエリは MySQL で許可されない。そのまま実行すると SQL エラー → **HTTP 500** になる。
- **解決**: 既読更新は「**先に SELECT で会話内の最大メッセージIDを取得** → **その値で conversation_members を 1 本の UPDATE**」の 2 段階で行う。  
  対象: `includes/chat/data.php` の `updateLastReadAt()`、`api/conversations.php` の `mark_read`、`api/messages.php` の既読更新。

### 本番で last_read_message_id がまだない場合
- 会話一覧取得（`getChatPageData` 内の SELECT）で `cm.last_read_message_id` を参照しているため、カラムが無いと SQL エラーで 500 になる。
- **対応**: 会話一覧クエリを **try-catch** で実行し、`Unknown column 'last_read_message_id'` 等のときに **last_read_at のみで未読数を算出するクエリ**に切り替えるフォールバックを入れている（`includes/chat/data.php`）。  
  これにより、マイグレーション未実行の本番でも 500 にならず、未読数は従来どおり「最後に読んだ時刻」ベースで表示される。

### 本番（EC2）でマイグレーションを実行する手順
- `config/show_mysql_connection.php` は未デプロイのことがある。その場合は **PHP 1 行で接続情報を取得**してから `mysql` で接続する。

1. **プロジェクトルートに移動**（例: `/var/www/html`）
   ```bash
   cd /var/www/html
   ```

2. **接続コマンドを表示**（DB 設定から取得）
   ```bash
   php -r 'define("IS_API",true); require "config/database.php"; echo "mysql -h ".DB_HOST." -u ".DB_USER." -p ".DB_NAME."\n";'
   ```
   表示例: `mysql -h database-1.xxx.rds.amazonaws.com -u admin -p social9`

3. **表示されたコマンドをコピーして実行**（`-p` のあとでパスワード入力）
   ```bash
   mysql -h <表示されたホスト> -u <表示されたユーザー> -p <表示されたDB名>
   ```

4. **接続後、以下を実行**
   ```sql
   ALTER TABLE conversation_members
     ADD COLUMN last_read_message_id INT UNSIGNED NULL DEFAULT NULL AFTER last_read_at,
     ADD INDEX idx_last_read_message_id (last_read_message_id);
   ```

5. **終了**: `exit`

- マイグレーション後は、既読・未読が **メッセージID ベース**で安定して永続する。

### 参照する主なファイル
| ファイル | 役割 |
|---------|------|
| `chat.php` | 既読更新後に `last_read_at` / `last_read_message_id` を取得し、未読ラインを **last_read_message_id 優先**で描画 |
| `includes/chat/data.php` | `updateLastReadAt()`、会話一覧の unread_count（last_read_message_id 対応＋フォールバック） |
| `api/conversations.php` | list / list_with_unread の未読数、mark_read の既読更新 |
| `api/messages.php` | メッセージ取得時の既読更新 |
| `api/notifications.php` | 未読メッセージ数（バッジ）のサブクエリ |
| `database/migration_unread_count.sql` | last_read_message_id 追加用 SQL |
| `config/show_mysql_connection.php` | ローカルで MySQL 接続コマンドを表示（本番に無い場合は上記 php -r を使用） |
