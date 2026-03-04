# データベーススキーマ構成

## 公式スキーマファイル

### メインスキーマ
- **`schema_phase1.sql`** - 本番環境用メインスキーマ（Phase 1 MVP）
  - ユーザー、組織、会話、メッセージ等のコアテーブル

### 追加機能スキーマ
- **`schema_additional.sql`** - 追加設定テーブル
- **`schema_messages.sql`** - メッセージ拡張機能
- **`schema_matching.sql`** - マッチング機能
- **`schema_announcements.sql`** - お知らせ機能
- **`schema_tasks.sql`** - タスク管理機能
- **`schema_investor.sql`** - 投資家向け機能
- **`schema_wish.sql`** - 願い事機能

### マイグレーション
- **`migration_to_phase1.sql`** - 旧スキーマからPhase 1への移行
- **`migration_usage_logs.sql`** - 利用履歴テーブル追加

---

## ロール定義

### users.role（システムレベルロール）
システム全体での権限を表す。ユーザー固有の属性。

| ロール | 説明 |
|--------|------|
| `system_admin` | システム管理者（開発者）。全機能へのアクセス権 |
| `org_admin` | 組織管理者。組織を作成・管理できる |
| `user` | 一般ユーザー。基本機能のみ利用可能 |

### organization_members.role（組織レベルロール）
特定の組織内での権限を表す。組織ごとに異なる。

| ロール | 説明 |
|--------|------|
| `owner` | 組織オーナー。組織の全管理権限。削除・解散も可能 |
| `admin` | 組織管理者。メンバー管理、グループ管理等が可能 |
| `member` | 一般メンバー。組織のリソースを利用可能 |
| `restricted` | 制限付きメンバー（未成年等）。親/管理者の承認が必要 |

### 使い分け
- **システム全体の機能**（ユーザー作成、システム設定等）→ `users.role` を使用
- **組織内の機能**（メンバー管理、グループ管理等）→ `organization_members.role` を使用

---

## 主要テーブル

### ユーザー関連
- `users` - ユーザーアカウント
- `user_settings` - ユーザー設定

### 組織関連
- `organizations` - 組織マスタ
- `organization_members` - 組織メンバー（多対多）
- `approved_contacts` - 承認済み連絡先（制限付きメンバー用）

### AI秘書関連（会話ログ・キャラ選択・記憶の永続化に必須）
- `ai_conversations` - AI秘書との会話履歴（質問・回答）
- `user_ai_settings` - 秘書名・キャラクタータイプ・選択状態・プロファイル
- `ai_user_memories` - 秘書が覚えるユーザー情報（家族・趣味等）

※ 本番で「会話ログ・記憶・キャラが消える」場合は、上記テーブルが存在するか確認してください。`schema.sql` に定義があります。

**AI秘書デプロイ時の確認**
- 本番DBに `schema.sql` を適用済みであること（または `ai_conversations`, `user_ai_settings`, `ai_user_memories` が存在すること）
- テーブルが無い場合: `database/migration_ai_secretary_tables_ensure.sql` を1回実行（CREATE TABLE IF NOT EXISTS）
- `user_ai_settings` に `character_type`, `secretary_name`, `character_selected`, `user_profile` カラムがあること。不足時は `database/migration_ai_secretary_columns_add.sql` の ALTER を1つずつ実行（既存カラムはエラーになるのでスキップ可）
- 改善の小分け記録: `DOCS/AI_SECRETARY_IMPROVEMENTS_LOG.md`
- 会話履歴は「履歴をクリア」ボタンでしか削除されません。誤操作防止のため確認ダイアログを表示しています

### 会話関連
- `conversations` - 会話（DM/グループ）
- `conversation_members` - 会話メンバー
- `messages` - メッセージ
- `message_reads` - 既読管理
- `message_reactions` - リアクション
- `files` - 添付ファイル

### 通話関連
- `calls` - 通話履歴
- `call_participants` - 通話参加者

### 利用制限関連
- `usage_logs` - 利用履歴（利用時間制限用）

---

## organization_members テーブル

ユーザーと組織の多対多関係を管理するテーブル。
`users.organization_id`（非推奨）ではなく、このテーブルを使用すること。

### 主要カラム
| カラム | 型 | 説明 |
|--------|------|------|
| `organization_id` | INT | 組織ID |
| `user_id` | INT | ユーザーID |
| `role` | ENUM | 組織内ロール |
| `member_type` | ENUM | internal/external |
| `joined_at` | DATETIME | 参加日時 |
| `left_at` | DATETIME | 退出日時（論理削除） |

### 利用制限カラム
| カラム | 型 | 説明 |
|--------|------|------|
| `usage_start_time` | TIME | 利用開始時間 |
| `usage_end_time` | TIME | 利用終了時間 |
| `daily_limit_minutes` | INT | 1日の利用制限（分） |
| `external_contact` | TINYINT | 組織外連絡許可 |
| `call_restriction` | ENUM | 通話制限 |
| `can_create_groups` | TINYINT | グループ作成許可 |
| `can_leave_org` | TINYINT | 組織退出許可 |

---

## 非推奨カラム

以下のカラムは後方互換性のため残っていますが、新規開発では使用しないでください：

- `users.organization_id` → `organization_members` を使用
- `users.member_type` → `organization_members.member_type` を使用
- `users.is_org_admin` → `organization_members.role` を使用

---

## スキーマ適用手順

1. 新規環境: `schema_phase1.sql` を実行
2. 追加機能: 必要な `schema_*.sql` を実行
3. マイグレーション: `migration_*.sql` を順番に実行

```bash
# 新規環境の場合
mysql -u root -p nine < database/schema_phase1.sql
mysql -u root -p nine < database/migration_usage_logs.sql
```


