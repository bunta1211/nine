# admin/ 管理画面 依存関係

このディレクトリには、Social9の管理画面が含まれています。

## ファイル一覧

| ファイル | 役割 | 権限 |
|---------|------|------|
| `_sidebar.php` | **共通サイドバーパーツ**（adminSidebarCSS/adminSidebarHTML関数）。全管理ページで使用 | - |
| `index.php` | 管理ダッシュボード | admin |
| `ai_usage.php` | AI使用量（種別別・ユーザー別集計・**AI利用料金表**） | admin |
| `users.php` | ユーザー管理 | admin |
| `user_groups.php` | ユーザー別所属グループ一覧・再入室（引っ越し等で外れたユーザー用） | system_admin |
| `members.php` | 組織アドレス帳（メンバー一覧・新規登録・既存ユーザー追加・招待再送・一斉招待） | admin |
| `groups.php` | グループ管理 | admin |
| `settings.php` | システム設定 | admin |
| `logs.php` | ログ閲覧 | admin |
| `reports.php` | レポート | admin |
| `backup.php` | バックアップ | admin |
| `import_users.php` | ユーザーインポート | admin |
| `import_groups.php` | グループインポート | admin |
| `import_chatwork_messages.php` | Chatwork API経由ログインポート（最大100件） | org_admin |
| `import_chatwork_csv.php` | Chatwork CSVエクスポートからインポート（100件超対応） | org_admin |
| `providers.php` | プロバイダー管理 | admin |
| `specs.php` | 仕様書 | admin |
| `wish_patterns.php` | Wishパターン管理 | admin |
| `wishes.php` | Wish一覧管理 | admin |
| `improvement_reports.php` | 改善・デバッグログ（改善提案一覧・報告者別件数・Cursor用コピー・改善完了通知・手動新規作成） | org_admin |
| `monitor.php` | エラーチェック（エラーログ一覧・ヘルスチェック・チェックボックス一括解決・詳細展開・ユーザー/スタック/追加情報表示） | admin |
| `security.php` | セキュリティ管理 | admin |
| `attackers.php` | 攻撃者情報 | admin |
| `set_test_passwords.php` | 会話テスト用アカウントのパスワード設定 | なし（一時セットアップ用） |
| `storage_billing.php` | 利用料請求管理（**ストレージ料金表・AI利用料金表・その他サービス料金表**・契約一覧、**無制限組織は使用量/無制限表示**、請求生成、全銀データDL、口座情報管理） | |
| `ai_memories.php` | AI記憶管理（組織の専門AIが自動収集した記憶の検索・確認・修正・追記・削除・復元） | org_admin |
| `ai_specialist_admin.php` | 専門AI管理（組織管理サイドバーに配置。組織別の専門AI設定・カスタムプロンプト編集・利用ログ。システム管理者はデフォルトプロンプト・機能フラグタブも表示） | org_admin / system_admin |
| `ai_safety_reports.php` | AI安全通報管理（社会通念違反・生命の危機・いじめ等の自動通報確認・ステータス変更・秘書への追加質問）。運営責任者のみ参照可 | system_admin |

---

## アーキテクチャ

```
admin/
├── DEPENDENCIES.md      ← このファイル
├── _sidebar.php         ← 共通サイドバー（CSS + HTML関数）
├── index.php            ← ダッシュボード
├── *.php                ← 各管理画面（全て _sidebar.php を使用）
│
└── api/                 ← 管理用API
    ├── create-organization.php  ← 組織新規作成（POST JSON）
    ├── groups.php        ← POST action=create_group（グループチャット新規作成）
    ├── members.php       ← GET action=search_candidates（候補者検索）, POST action=add_existing（既存ユーザーを組織に追加）, action=bulk_invite（一斉招待）, action=resend_invite（招待再送）
    ├── member-restrictions.php
    ├── my-organizations.php
    └── switch-organization.php
```

---

## 共通依存関係

すべての管理画面は以下に依存しています：

```php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';

// 共通サイドバー
$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

// 管理者権限チェック
if (!hasSystemAdminRole()) {
    header('Location: ../index.php');
    exit;
}
```

### 共通サイドバー（_sidebar.php）

全管理ページで使用する共通サイドバーコンポーネント。

| 関数 | 役割 |
|------|------|
| `adminSidebarCSS()` | サイドバー用CSS（`<style>` 内で呼ぶ） |
| `adminSidebarHTML($currentPage)` | サイドバーHTML出力（`<div class="admin-container">` 内で呼ぶ） |

**JS**: `assets/js/admin-sidebar-sort.js` — ドラッグ＆ドロップによるメニュー並び替え（localStorage保存）

---

## ファイル別依存関係

### index.php（ダッシュボード）

**表示内容**:
- ユーザー統計
- グループ統計
- 本日のアクセス（同ドメイン除く）・検索経由・離脱率（access_log / includes/access_logger.php）
- 最近のアクティビティ

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| DB | `users`, `conversations`, `messages`, `access_log`（migration_access_log.sql） |
| 共通 | `config/app.php`, `includes/access_logger.php`（get_access_stats_today） |
| CSS | 管理画面共通CSS（インライン） |
| 認証 | `includes/auth.php`, `includes/roles.php` |

### ai_usage.php（AI使用量）

**機能**:
- AI秘書チャット・翻訳・タスク検索の使用量集計
- 期間フィルタ（今月/先月/今週/過去7日/カスタム）
- ユーザー別・AI種別ごとの表示
- CSVエクスポート

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| DB | `ai_usage_logs`, `translation_usage`, `users` |
| 設定 | `config/ai_config.php`（USD_TO_JPY_RATE 等） |
| 認証 | `includes/auth.php`（isOrgAdminUser） |

### users.php（ユーザー管理）

**機能**:
- ユーザー一覧表示（検索・役割・アカウント状態フィルター）
- ユーザー編集（表示名・メール・氏名・アカウント状態・役割）
- ユーザー削除（ソフト削除: status=deleted、重複アカウントの無効化に利用）
- 所属グループ確認

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| DB | `users` |
| API | `admin/api/users.php`（取得 GET?id= / 更新 PUT / 削除 DELETE） |

### members.php（メンバー管理）

**機能**:
- 組織メンバー管理
- 権限設定
- 制限設定
- **所属グループの一括登録**: 新規・既存メンバーを2個以上のグループに一括で所属させられる（`group_ids` で create/update）

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| DB | `users`, `organization_members`, `member_restrictions`, `conversations`, `conversation_members` |
| API | `admin/api/members.php`（GET ?action=org_groups で組織グループ一覧、create/update で group_ids 受け取り） |
| JS | `assets/js/admin-members.js` |

### groups.php（グループ管理）

**機能**:
- グループ一覧
- グループ作成/編集/削除
- メンバー割当

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| DB | `conversations`, `conversation_members` |
| API | `api/groups.php` |
| JS | `assets/js/admin-groups.js` |

---

## 管理用API

### api/groups.php

| アクション | 役割 |
|-----------|------|
| `list` | グループ一覧取得 |
| `create` | グループ作成 |
| `update` | グループ更新 |
| `delete` | グループ削除 |
| `members` | メンバー一覧 |
| `add_member` | メンバー追加 |
| `remove_member` | メンバー削除 |

### api/members.php

`organization_members.left_at` の有無でクエリを分岐（本番スキーマ互換）。権限チェック `hasOrgAdminRole` 失敗時はログ出力後に 500 を返す。招待再送（`resend_invite`）は `password_reset_tokens` テーブルを使用。テーブルが無い場合は自動作成を試み、失敗時は `database/migration_password_reset_tokens.sql` の実行を案内。

| アクション | 役割 |
|-----------|------|
| `list` | メンバー一覧 |
| `resend_invite` | 招待メール再送（未承諾メンバー用） |
| `update_role` | ロール変更 |
| `toggle_restriction` | 制限切替 |

### api/member-restrictions.php

| アクション | 役割 |
|-----------|------|
| `list` | 制限一覧 |
| `create` | 制限作成 |
| `delete` | 制限削除 |

---

## JavaScript依存関係

### assets/js/admin-members.js

**役割**: メンバー管理画面のインタラクション

**依存API**: `admin/api/members.php`

**主要関数**:
- `loadMembers()` - メンバー読込
- `updateRole()` - ロール変更
- `toggleRestriction()` - 制限切替

### assets/js/admin-groups.js

**役割**: グループ管理画面のインタラクション

**依存API**: `admin/api/groups.php`

**主要関数**:
- `loadGroups()` - グループ読込
- `createGroup()` - グループ作成
- `deleteGroup()` - グループ削除

---

## 権限チェック

管理画面へのアクセスには以下の権限が必要：

```php
// includes/roles.php で定義
function hasSystemAdminRole() {
    // users.role が 'system_admin' または 'org_admin' の場合 true
}
```

---

## 変更時のチェックリスト

### 管理画面のUIを変更する場合
- [ ] 権限チェックが正しく行われているか
- [ ] 一般ユーザーがアクセスできないか確認
- [ ] CSSは既存のスタイルと競合していないか

### 管理用APIを変更する場合
- [ ] 権限チェックが含まれているか
- [ ] 対応するJSファイルを更新したか
- [ ] エラーハンドリングが適切か

### インポート機能を変更する場合
- [ ] CSVフォーマットを確認
- [ ] バリデーションが適切か
- [ ] 大量データ処理のパフォーマンス確認
