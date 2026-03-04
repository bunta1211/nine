# Guild セットアップガイド

## 初期設定

### 1. データベーステーブルの作成

phpMyAdminまたはコマンドラインで以下のSQLを実行してください：

```sql
-- database/schema.sql の内容を実行
```

### 2. システム管理者の設定

最初にシステム管理者を設定します：

```sql
-- 既存のSocial9ユーザーIDを確認
SELECT id, email, display_name FROM users WHERE email = 'admin@example.com';

-- システム管理者権限を付与（user_id = 取得したID）
INSERT INTO guild_system_permissions (user_id, is_system_admin, can_manage_users, can_manage_guilds, can_approve_large_requests, can_approve_advances, can_view_all_data, can_export_data, can_manage_fiscal_year, can_register_qualifications)
VALUES (1, 1, 1, 1, 1, 1, 1, 1, 1, 1);
```

### 3. 年度設定

```sql
-- 2026年度の設定（schema.sqlで作成済み）
-- 必要に応じてstatusを変更
UPDATE guild_fiscal_years 
SET status = 'active', opened_at = NOW(), opened_by = 1
WHERE fiscal_year = 2026;
```

### 4. ギルドの作成

管理者ダッシュボードまたはSQLで作成：

```sql
INSERT INTO guild_guilds (name, fiscal_year, annual_budget, remaining_budget)
VALUES 
('本園', 2026, 100000, 100000),
('分園A', 2026, 50000, 50000),
('学童保育', 2026, 50000, 50000);
```

### 5. ギルドメンバーの追加

```sql
-- ギルドリーダーを設定
INSERT INTO guild_members (guild_id, user_id, role)
VALUES (1, 1, 'leader');

-- メンバーを追加
INSERT INTO guild_members (guild_id, user_id, role)
VALUES (1, 2, 'member');
```

## 年度運用

### 年度初めの配布

1. 管理者ダッシュボード → 年度管理
2. 「年度初め配布」で全職員の在籍年数・役職ボーナスを設定
3. 承認後、Earthが各職員に配布される

### 年度末処理

1. 3月1日から「3月10日が最終決済日」の警告が表示される
2. 3月10日に全Earthが精算される
3. 3月11日〜31日は依頼停止期間
4. 4月1日以降、システム管理者が「新年度開始」を実行

## 権限の委譲

### 給与支払い担当の設定

```sql
INSERT INTO guild_system_permissions (user_id, is_payroll_admin, can_register_qualifications)
VALUES (5, 1, 1);
```

### システム管理者の追加

```sql
UPDATE guild_system_permissions 
SET is_system_admin = 1, can_manage_users = 1, can_manage_guilds = 1,
    can_approve_large_requests = 1, can_approve_advances = 1,
    can_view_all_data = 1, can_export_data = 1, can_manage_fiscal_year = 1
WHERE user_id = 10;
```

## トラブルシューティング

### ログインできない

1. Social9のusersテーブルにユーザーが存在するか確認
2. パスワードが正しいか確認
3. is_active = 1 になっているか確認

### Earthが付与されない

1. 依頼のdistribution_timingを確認
2. 依頼者が完了承認したか確認
3. guild_earth_transactionsテーブルを確認

### 通知が届かない

1. guild_notificationsテーブルを確認
2. ユーザーの通知設定を確認
