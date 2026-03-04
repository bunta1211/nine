# Social9 グループ・メンバー CSV インポートガイド

このドキュメントは、Social9アプリからエクスポートされたCSVデータを別のシステムにインポートする際の説明書です。

## 📋 アプリケーション概要

**Social9** は、グループチャット機能を持つWebアプリケーションです。

### 主要機能
- グループチャット（複数人での会話）
- DM（1対1のダイレクトメッセージ）
- メンバー管理（管理者権限、一般メンバー）
- ファイル共有、リアクション、メンション機能

---

## 📊 CSVファイル形式

### ファイル仕様
- **文字コード**: UTF-8 (BOM付き)
- **区切り文字**: カンマ (,)
- **改行コード**: LF または CRLF
- **ヘッダー行**: あり（1行目）

### カラム定義

| カラム名 | 型 | 説明 | 例 |
|---------|-----|------|-----|
| `group_id` | INT | グループの一意識別子 | 1, 2, 3 |
| `group_name` | VARCHAR(100) | グループ名（NULLの場合は無題） | "開発チーム" |
| `group_type` | ENUM | 'dm' = 1対1, 'group' = グループ | "group" |
| `group_description` | TEXT | グループの説明文 | "プロジェクト用" |
| `group_created_at` | DATETIME | グループ作成日時 | "2024-01-15 10:30:00" |
| `member_user_id` | INT | メンバーのユーザーID | 100 |
| `member_email` | VARCHAR(255) | メンバーのメールアドレス | "user@example.com" |
| `member_display_name` | VARCHAR(100) | メンバーの表示名 | "田中太郎" |
| `member_role` | ENUM | 'admin', 'member', 'viewer' | "admin" |
| `member_joined_at` | DATETIME | グループ参加日時 | "2024-01-15 10:30:00" |
| `member_left_at` | DATETIME | 退出日時（NULL=参加中） | NULL or "2024-02-01 12:00:00" |

### CSVサンプル

```csv
group_id,group_name,group_type,group_description,group_created_at,member_user_id,member_email,member_display_name,member_role,member_joined_at,member_left_at
1,開発チーム,group,アプリ開発プロジェクト,2024-01-15 10:00:00,100,admin@example.com,管理者,admin,2024-01-15 10:00:00,
1,開発チーム,group,アプリ開発プロジェクト,2024-01-15 10:00:00,101,member1@example.com,メンバー1,member,2024-01-16 09:00:00,
1,開発チーム,group,アプリ開発プロジェクト,2024-01-15 10:00:00,102,member2@example.com,メンバー2,member,2024-01-17 14:30:00,
2,田中・鈴木,dm,,2024-02-01 15:00:00,100,tanaka@example.com,田中,admin,2024-02-01 15:00:00,
2,田中・鈴木,dm,,2024-02-01 15:00:00,103,suzuki@example.com,鈴木,admin,2024-02-01 15:00:00,
```

---

## 🗄️ データベース構造

### ERダイアグラム

```
┌─────────────────┐       ┌─────────────────────────┐       ┌─────────────────┐
│     users       │       │  conversation_members   │       │  conversations  │
├─────────────────┤       ├─────────────────────────┤       ├─────────────────┤
│ id (PK)         │◄──────│ user_id (FK)            │       │ id (PK)         │
│ email           │       │ conversation_id (FK)    │──────►│ name            │
│ display_name    │       │ role                    │       │ type            │
│ ...             │       │ joined_at               │       │ description     │
└─────────────────┘       │ left_at                 │       │ created_at      │
                          └─────────────────────────┘       └─────────────────┘
```

### テーブル定義

#### 1. conversations（グループ/会話）

```sql
CREATE TABLE conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('dm', 'group') NOT NULL DEFAULT 'group',
    name VARCHAR(100) DEFAULT NULL,
    description TEXT,
    icon VARCHAR(255) DEFAULT NULL,
    is_public TINYINT(1) DEFAULT 0,
    invite_link VARCHAR(100) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    allow_member_dm TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### 2. conversation_members（メンバー関係）

```sql
CREATE TABLE conversation_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
    is_pinned TINYINT(1) DEFAULT 0,
    is_muted TINYINT(1) DEFAULT 0,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,
    last_read_at DATETIME DEFAULT NULL,
    UNIQUE KEY unique_member (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### 3. users（ユーザー）

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    avatar_path VARCHAR(500) DEFAULT NULL,
    bio TEXT,
    role ENUM('system_admin', 'org_admin', 'user') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 📥 インポート手順

### ステップ1: CSVをパース

```python
import csv

with open('groups_members.csv', 'r', encoding='utf-8-sig') as f:
    reader = csv.DictReader(f)
    
    groups = {}
    for row in reader:
        group_id = row['group_id']
        if group_id not in groups:
            groups[group_id] = {
                'id': group_id,
                'name': row['group_name'],
                'type': row['group_type'],
                'description': row['group_description'],
                'created_at': row['group_created_at'],
                'members': []
            }
        
        if row['member_user_id']:
            groups[group_id]['members'].append({
                'user_id': row['member_user_id'],
                'email': row['member_email'],
                'display_name': row['member_display_name'],
                'role': row['member_role'],
                'joined_at': row['member_joined_at'],
                'left_at': row['member_left_at'] or None
            })
```

### ステップ2: データベースにインポート

```sql
-- 1. ユーザーを作成（存在しない場合）
INSERT INTO users (id, email, display_name, password_hash)
VALUES (100, 'user@example.com', '田中太郎', 'dummy_hash')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- 2. グループを作成
INSERT INTO conversations (id, name, type, description, created_at)
VALUES (1, '開発チーム', 'group', 'プロジェクト用', '2024-01-15 10:00:00')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 3. メンバー関係を登録
INSERT INTO conversation_members (conversation_id, user_id, role, joined_at, left_at)
VALUES (1, 100, 'admin', '2024-01-15 10:00:00', NULL)
ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = VALUES(left_at);
```

---

## ⚠️ 注意事項

### データ整合性
1. **user_id** は `users` テーブルに存在する必要があります
2. **conversation_id** は `conversations` テーブルに存在する必要があります
3. 同じグループに同じユーザーを複数回登録することはできません（UNIQUE制約）

### 役割（role）の意味
| 役割 | 説明 | 権限 |
|------|------|------|
| `admin` | 管理者 | メンバー追加/削除、グループ設定変更、グループ削除 |
| `member` | 一般メンバー | メッセージ送信、ファイル共有 |
| `viewer` | 閲覧者 | メッセージ閲覧のみ |

### DMとグループの違い
| タイプ | 説明 |
|--------|------|
| `dm` | 1対1のダイレクトメッセージ。通常2名のadminで構成 |
| `group` | 複数人のグループチャット。1名以上のadminが必要 |

### left_at の扱い
- **NULL**: 現在もグループに参加中
- **日時**: その日時にグループを退出した

---

## 🔄 逆変換（インポート用CSV生成）

他のシステムからSocial9にインポートする場合のCSV形式:

```csv
group_name,group_type,group_description,member_email,member_display_name,member_role
新チーム,group,新プロジェクト,admin@example.com,管理者,admin
新チーム,group,新プロジェクト,member@example.com,メンバー,member
```

このCSVをインポートするPHPスクリプト例:

```php
<?php
$csv = array_map('str_getcsv', file('import.csv'));
$header = array_shift($csv);

$groups = [];
foreach ($csv as $row) {
    $data = array_combine($header, $row);
    $groupName = $data['group_name'];
    
    if (!isset($groups[$groupName])) {
        // グループ作成
        $stmt = $pdo->prepare("INSERT INTO conversations (name, type, description) VALUES (?, ?, ?)");
        $stmt->execute([$groupName, $data['group_type'], $data['group_description']]);
        $groups[$groupName] = $pdo->lastInsertId();
    }
    
    // ユーザー検索または作成
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['member_email']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // 新規ユーザー作成
        $stmt = $pdo->prepare("INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$data['member_email'], $data['member_display_name'], password_hash('temp123', PASSWORD_DEFAULT)]);
        $userId = $pdo->lastInsertId();
    } else {
        $userId = $user['id'];
    }
    
    // メンバー追加
    $stmt = $pdo->prepare("INSERT IGNORE INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->execute([$groups[$groupName], $userId, $data['member_role']]);
}
```

---

## 📞 サポート

このCSVデータに関する質問は、Social9アプリの管理者にお問い合わせください。


