# API設計

## 概要

Social9のREST API設計。全APIは `/api/` 配下に配置。

---

## 🔧 共通仕様

### ベースURL

```
開発環境: http://localhost/nine/api/
本番環境: https://[ドメイン]/api/
```

### リクエスト形式

| 項目 | 値 |
|------|-----|
| Content-Type | application/json |
| 認証 | セッション（Cookie） |

### レスポンス形式

**成功時**
```json
{
  "success": true,
  "message": "処理が完了しました",
  "data": { ... }
}
```

**エラー時**
```json
{
  "success": false,
  "message": "エラーメッセージ"
}
```

### HTTPステータスコード

| コード | 意味 |
|:------:|------|
| 200 | 成功 |
| 400 | リクエストエラー |
| 401 | 認証が必要 |
| 403 | 権限がない |
| 404 | リソースが見つからない |
| 500 | サーバーエラー |

---

## 🔐 認証API

### `POST /api/auth.php?action=register`

ユーザー登録

**リクエスト**
```json
{
  "email": "user@example.com",
  "password": "password123",
  "display_name": "田中太郎",
  "birth_date": "1990-01-15",
  "prefecture": "愛知県",
  "city": "岡崎市"
}
```

**レスポンス**
```json
{
  "success": true,
  "user_id": 123,
  "is_minor": false
}
```

---

### `POST /api/auth.php?action=login`

ログイン

**リクエスト**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**レスポンス**
```json
{
  "success": true,
  "user": {
    "id": 123,
    "display_name": "田中太郎",
    "role": "user"
  }
}
```

---

### `POST /api/auth.php?action=logout`

ログアウト

---

## 💬 メッセージAPI

### `POST /api/messages.php?action=send`

メッセージ送信

**リクエスト**
```json
{
  "conversation_id": 1,
  "content": "こんにちは！",
  "message_type": "text",
  "reply_to_id": null
}
```

**レスポンス**
```json
{
  "success": true,
  "message_id": 456
}
```

---

### `GET /api/messages.php?action=get`

メッセージ取得

**パラメータ**
| パラメータ | 必須 | 説明 |
|------------|:----:|------|
| conversation_id | ✅ | 会話ID |
| limit | | 取得件数（デフォルト50） |
| before_id | | このID以前を取得 |
| after_id | | このID以降を取得 |

**レスポンス**
```json
{
  "success": true,
  "messages": [
    {
      "id": 456,
      "sender_id": 123,
      "sender_name": "田中太郎",
      "content": "こんにちは！",
      "message_type": "text",
      "created_at": "2024-12-24 10:30:00"
    }
  ],
  "has_more": true
}
```

---

### `POST /api/messages.php?action=delete`

メッセージ削除

**リクエスト**
```json
{
  "message_id": 456
}
```

---

### `POST /api/messages.php?action=react`

リアクション追加

**リクエスト**
```json
{
  "message_id": 456,
  "reaction_type": "👍"
}
```

---

### `GET /api/messages.php?action=search`

検索

**パラメータ**
| パラメータ | 必須 | 説明 |
|------------|:----:|------|
| keyword | ✅ | 検索キーワード |
| type | | all/users/messages/groups |
| conversation_id | | 特定の会話内で検索 |

---

## 👥 会話API

### `GET /api/conversations.php?action=list`

会話一覧取得

**レスポンス**
```json
{
  "success": true,
  "conversations": [
    {
      "id": 1,
      "type": "group",
      "name": "開発チーム",
      "last_message": "了解しました",
      "last_message_at": "2024-12-24 10:30:00",
      "unread_count": 3
    }
  ]
}
```

---

### `POST /api/conversations.php?action=create`

会話作成

**リクエスト（DM）**
```json
{
  "type": "dm",
  "member_ids": [456]
}
```

**リクエスト（グループ）**
```json
{
  "type": "group",
  "name": "新規プロジェクト",
  "member_ids": [456, 789]
}
```

---

### `POST /api/conversations.php?action=add_member`

メンバー追加

**リクエスト**
```json
{
  "conversation_id": 1,
  "user_id": 789
}
```

---

## 📞 通話API

### `POST /api/calls.php?action=create`

通話開始

**リクエスト**
```json
{
  "conversation_id": 1,
  "call_type": "video"
}
```

**レスポンス**
```json
{
  "success": true,
  "call_id": 123,
  "room_id": "social9_abc123",
  "join_url": "https://meet.jit.si/social9_abc123"
}
```

---

### `POST /api/calls.php?action=end`

通話終了

**リクエスト**
```json
{
  "call_id": 123
}
```

---

## 🏢 ソーシャルグループAPI

> **ソーシャルグループ (Social Groups)**: 家族、老人会、子ども会、青年会議所、株式会社等のまとまった集団
> メンバーには「窓口」「代表」「事務局」等の肩書を設定可能

### `POST /api/socialgroups.php?action=create`

ソーシャルグループ作成

**リクエスト**
```json
{
  "name": "田中家",
  "type": "family",
  "description": "田中家のグループ"
}
```

---

### `GET /api/socialgroups.php?action=list`

所属ソーシャルグループ一覧

**レスポンス**
```json
{
  "success": true,
  "socialgroups": [
    {
      "id": 1,
      "name": "田中家",
      "type": "family",
      "role": "admin",
      "title": null,
      "member_count": 4
    },
    {
      "id": 2,
      "name": "株式会社クローバー",
      "type": "corporation",
      "role": "admin",
      "title": "窓口",
      "member_count": 20
    }
  ]
}
```

---

### `POST /api/socialgroups.php?action=add_member`

メンバー追加

**リクエスト**
```json
{
  "organization_id": 1,
  "user_id": 123,
  "role": "admin",
  "title": "事務局"
}
```

---

### `POST /api/socialgroups.php?action=update_member`

メンバー設定・肩書変更

**リクエスト（肩書設定）**
```json
{
  "organization_id": 1,
  "user_id": 5,
  "title": "窓口"
}
```

**リクエスト（制限設定）**
```json
{
  "organization_id": 1,
  "user_id": 5,
  "external_contact": "approved",
  "call_restriction": "group_only",
  "usage_start_time": "07:00",
  "usage_end_time": "21:00",
  "daily_limit_minutes": 120
}
```

---

## 🌐 翻訳API

### `POST /api/translate.php?action=translate`

テキスト翻訳

**リクエスト**
```json
{
  "message_id": 456,
  "target_language": "ja"
}
```

**レスポンス**
```json
{
  "success": true,
  "source_language": "en",
  "target_language": "ja",
  "original": "Hello!",
  "translated": "こんにちは！"
}
```

---

## ⚙️ 設定API

### `GET /api/settings.php?action=get_ui`

UI設定取得

**レスポンス**
```json
{
  "success": true,
  "settings": {
    "color_mode": "dark",
    "accent_color": "green",
    "background_theme": "fuji",
    "font_size": "medium"
  }
}
```

---

### `POST /api/settings.php?action=save_ui`

UI設定保存

**リクエスト**
```json
{
  "color_mode": "dark",
  "accent_color": "purple",
  "background_theme": "snow",
  "font_size": "large"
}
```

---

## 🤝 マッチングAPI

### `POST /api/matching.php?action=create_request`

リクエスト作成

**リクエスト**
```json
{
  "title": "引っ越しの手伝い",
  "description": "1月15日に引っ越しがあります...",
  "category": "暮らし・家事",
  "prefecture": "愛知県",
  "city": "岡崎市",
  "budget": 10000,
  "preferred_date": "2025-01-15",
  "urgency": "normal"
}
```

---

### `GET /api/matching.php?action=list_requests`

リクエスト一覧

**パラメータ**
| パラメータ | 必須 | 説明 |
|------------|:----:|------|
| category | | カテゴリでフィルタ |
| prefecture | | 都道府県でフィルタ |
| status | | ステータスでフィルタ |

---

### `POST /api/matching.php?action=send_offer`

オファー送信

**リクエスト**
```json
{
  "request_id": 1,
  "price": 8000,
  "message": "対応可能です。経験があります。",
  "available_date": "2025-01-15"
}
```

---

### `POST /api/matching.php?action=accept_offer`

オファー承諾

**リクエスト**
```json
{
  "offer_id": 5
}
```

---

### `POST /api/matching.php?action=review`

評価投稿

**リクエスト**
```json
{
  "request_id": 1,
  "overall_rating": 5,
  "punctuality_rating": 5,
  "quality_rating": 5,
  "communication_rating": 5,
  "comment": "とても良かったです！"
}
```

---

## 🔔 通知API

### `GET /api/notifications.php?action=list`

通知一覧

**レスポンス**
```json
{
  "success": true,
  "notifications": [
    {
      "id": 1,
      "type": "mention",
      "title": "@メンション",
      "content": "田中さんがあなたをメンションしました",
      "is_read": false,
      "created_at": "2024-12-24 10:30:00"
    }
  ],
  "unread_count": 5
}
```

---

### `POST /api/notifications.php?action=read`

既読にする

**リクエスト**
```json
{
  "notification_id": 1
}
```

---

## 📁 ファイルAPI

### `POST /api/upload.php`

ファイルアップロード

**リクエスト**
- Content-Type: multipart/form-data
- file: アップロードファイル
- conversation_id: 会話ID

**レスポンス**
```json
{
  "success": true,
  "file_id": 123,
  "file_path": "/uploads/2024/12/abc123.jpg",
  "thumbnail_path": "/uploads/2024/12/thumb_abc123.jpg"
}
```

---

## 📝 APIファイル一覧

| ファイル | 用途 |
|----------|------|
| auth.php | 認証（ログイン/登録/ログアウト） |
| messages.php | メッセージ操作 |
| conversations.php | 会話管理 |
| calls.php | 通話管理 |
| socialgroups.php | ソーシャルグループ管理 |
| translate.php | 翻訳 |
| settings.php | 設定 |
| matching.php | マッチング |
| notifications.php | 通知 |
| upload.php | ファイルアップロード |
| status.php | オンライン状態更新 |

---

*作成日: 2024-12-24*








