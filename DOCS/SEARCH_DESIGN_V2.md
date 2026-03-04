# 検索機能 設計図 v2

**更新日**: 2026-02  
**前提**: 現行の検索機能（SEARCH_ARCHITECTURE.md）をベースに、要件を反映した設計図です。

---

## 1. 概要

本設計は以下の要件を織り込んでいます。

| 要件 | 概要 |
|------|------|
| **友達申請フロー** | 検索でユーザーをクリックしても即DM開始不可。友達申請（メッセージ付き）→ 承諾後にDM可能 |
| **未成年保護** | 高校生未満（15歳未満）は検索対象から除外。児童同士はQRコード等で直接やり取りしたときのみ友達可能 |
| **会話履歴検索** | 所属グループの会話履歴のみ検索可。未所属グループは**絶対に**検索不可 |
| **組織内／グローバル分離** | 組織内検索（グループ追加用）とグローバル検索（トップバー）を明確に分離 |
| **メール・携帯検索** | メールアドレス・携帯電話でも新規ユーザー検索可能 |

---

## 2. 検索種別一覧（改定版）

| 種別 | 用途 | トリガー | API | 検索対象 |
|------|------|----------|-----|----------|
| **A. グローバル検索** | メッセージ・ユーザー・グループ横断 | トップバー検索＋Enter / Ctrl+K | `api/messages.php?action=search` | メッセージ（所属会話のみ）、ユーザー（友達申請フロー）、グループ（参加会話のみ） |
| **B. 組織内検索** | グループに人を追加するとき | メンバー追加モーダル | `api/users.php?action=search&scope=org` | 同一組織のメンバーのみ |
| **C. 友達追加（メール）** | メールアドレスで友達検索 | 友達追加モーダル「Mail」タブ | `api/friends.php?action=search` | メールアドレス・表示名・携帯電話で検索 |
| **D. グループメンバー検索** | DM開始用（既存友達 or 同一グループ） | 友達追加モーダル「メンバー」タブ | `api/friends.php?action=group_members` | 同一グループメンバー（既に友達 or 即DM申請可） |
| **E. GIF検索** | メッセージ添付用GIF | 絵文字ピッカー内GIFタブ | `api/gif.php` | 変更なし |
| **F. タスク・メモ検索** | AI秘書・タスク一覧 | tasks.php / memos.php / AI秘書 | `task_memo_search_helper.php` | 変更なし |
| **G. 場所検索** | 近くのお店（AI秘書） | AI秘書で位置情報付き質問 | `api/ai.php` / `api/places.php` | 変更なし |

---

## 3. 友達申請フロー（新規）

### 3.1 現状と変更点

| 項目 | 現状 | 変更後 |
|------|------|--------|
| グローバル検索でユーザークリック | `startDmFromSearch()` → 即DM開始 | 友達申請モーダルを表示 |
| 友達申請 | メッセージなし | **メッセージ付き**で申請可能 |
| DM開始条件 | 検索結果からクリックで即開始 | **友達申請が承諾された後**のみDM可能 |

### 3.2 フロー図

```
【グローバル検索でユーザーをクリックした場合】

ユーザー検索結果表示
    │
    ▼
クリック
    │
    ├─ 既に友達（friendships.status = 'accepted'）
    │       → startDmFromSearch() で即DM開始（従来通り）
    │
    ├─ 友達申請済み（pending）
    │       → 「申請中です」表示、申請取り消しオプション
    │
    └─ 未申請
            → 友達申請モーダルを表示
                    │
                    ├─ メッセージ（任意・最大500文字）を入力して申請
                    │
                    ▼
            api/friends.php?action=request
            body: { friend_id, message }
                    │
                    ▼
            相手に通知（app_notifications / プッシュ）
                    │
                    ▼
            相手が「承諾」or「拒否」
                    │
                    ├─ 承諾 → friendships.status = 'accepted'
                    │         → DM開始可能
                    │
                    └─ 拒否 → friendships.status = 'rejected'
```

### 3.3 データベース変更

```sql
-- friendships テーブルに申請メッセージ用カラムを追加
ALTER TABLE friendships 
  ADD COLUMN IF NOT EXISTS request_message TEXT NULL 
  COMMENT '友達申請時のメッセージ' AFTER status;
```

### 3.4 API変更

| API | 変更 |
|-----|------|
| `api/friends.php` `action=request` | `message` パラメータを追加。`request_message` に保存 |
| `api/friends.php` `action=pending` | レスポンスに `request_message` を含める |
| `api/messages.php` `action=search` | ユーザー結果に `is_friend`, `is_pending`, `friendship_id` を返す |

### 3.5 UI変更

| 箇所 | 変更 |
|------|------|
| 検索モーダル（ユーザー結果） | 友達なら「DM開始」、未申請なら「友達申請」、申請中なら「申請中」 |
| 友達申請モーダル（新規） | メッセージ入力欄を追加。送信時に `message` を付与 |
| 友達申請一覧（承諾/拒否） | `request_message` を表示 |

---

## 4. 未成年保護

### 4.1 検索対象の除外

| 条件 | 除外対象 |
|------|----------|
| **高校生未満** | `birth_date` から計算し、15歳未満（または高校1年生相当未満）のユーザーを検索結果から除外 |
| **児童同士の友達** | 双方が未成年の場合、検索経由の友達申請は不可。QRコード・招待リンク等で「直接やり取り」した場合のみ友達可能 |

### 4.2 年齢判定

- `users.birth_date` を使用
- 15歳以上（高校1年生相当） = 検索可能
- 15歳未満 = 検索対象から除外
- 条件: `birth_date <= DATE_SUB(CURDATE(), INTERVAL 15 YEAR)` のユーザーのみ検索対象

```sql
-- 検索対象に含める条件（15歳未満を除外）
WHERE (u.birth_date IS NOT NULL AND u.birth_date <= DATE_SUB(CURDATE(), INTERVAL 15 YEAR))
```

※「高校生未満」は15歳未満とする。4月1日基準の学年区分が必要な場合は別途 `school_year` 等を検討。

### 4.3 児童同士の友達申請

| ケース | 許可 |
|--------|------|
| A（成人）→ B（未成年） | 可（検索経由で友達申請） |
| A（未成年）→ B（成人） | 可 |
| A（未成年）→ B（未成年） | **検索経由は不可**。QRコード・招待リンク経由のみ可 |

実装方針:
- `friendships` に `source` カラムを追加: `'search' | 'qr' | 'invite_link' | 'group' | null`
- 未成年同士の場合、`source` が `qr` または `invite_link` のときのみ許可

---

## 5. 会話履歴検索の厳格化

### 5.1 ルール

| 条件 | 検索 |
|------|------|
| 自分が参加している会話のメッセージ | ✅ 検索可 |
| 自分が参加していない会話のメッセージ | ❌ **絶対に検索不可** |

### 5.2 現状確認

`api/messages.php` の `action=search` におけるメッセージ検索は、既に `conversation_members` で参加会話に限定している想定。設計上は以下を再確認・担保する:

```sql
-- メッセージ検索の必須条件
INNER JOIN conversation_members cm ON c.id = cm.conversation_id
WHERE cm.user_id = ? AND cm.left_at IS NULL
```

- `conversation_id` や `sender_id` のオプション指定があっても、**必ず**上記 JOIN で自分がメンバーの会話のみに限定する。

---

## 6. 組織内検索とグローバル検索の分離

### 6.1 定義

| 種別 | 用途 | 場所 | 検索対象 |
|------|------|------|----------|
| **組織内検索** | グループに人を追加するとき | メンバー追加モーダル | 同一組織（`organization_members`）に所属するユーザーのみ |
| **グローバル検索** | メッセージ・ユーザー・グループ横断 | **トップバー検索** | メッセージ（所属会話）、ユーザー（友達申請フロー）、グループ（参加会話） |

### 6.2 組織内検索の仕様

- **API**: `api/users.php?action=search&scope=org&conversation_id=xxx`
- **条件**: 
  - 追加先グループが組織に紐づく（`conversations.organization_id` が設定されている）場合
  - その組織の `organization_members` に所属するユーザーを検索
- **条件**: グループが組織に紐づかない場合は、従来の「同一グループメンバー」検索にフォールバック（または組織未所属の場合は空を返す）

### 6.3 グローバル検索の仕様

- **API**: `api/messages.php?action=search`（現行）
- **場所**: トップバー検索バー（`topBarSearchInput`）＋ Enter でモーダル表示
- **フィルター**: all / messages / users / groups
- **ユーザークリック時**: 友達申請フロー（本設計 3 節）

---

## 7. メールアドレス・携帯電話検索

### 7.1 現状

- Google ID で登録したユーザーも `users.email` にメールが保存されている想定
- `api/users.php` の検索は `display_name`, `email` で検索済み
- `api/friends.php` の検索も `email` で検索
- 携帯電話: `DOCS/携帯電話機能のデプロイ手順.md` にて、`users.phone` での検索が実装済み

### 7.2 設計上の確認事項

| 項目 | 内容 |
|------|------|
| **メールアドレス検索** | Google ログインユーザーも `email` を持つため、メアド検索でヒットする設計とする |
| **携帯電話検索** | `users.phone` を検索対象に含める。数字のみ正規化して部分一致検索 |
| **友達追加（Mail タブ）** | `api/friends.php?action=search` で `query` にメール or 携帯 or 表示名を指定 |

### 7.3 検索対象カラム

| API | 検索対象 |
|-----|----------|
| `api/users.php` action=search | `display_name`, `email`, `phone` |
| `api/friends.php` action=search | `display_name`, `email`, `phone` |
| `api/messages.php` action=search（ユーザー） | `display_name`, `email`（既存）＋ `phone` を追加 |

---

## 8. アーキテクチャ図

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  チャット画面（chat.php）                                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  【グローバル検索】トップバー                                                  │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ [🔍 検索... (Ctrl+K)]  ← Enter で検索モーダルを開く                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│       │                                                                   │
│       │ api/messages.php?action=search                                    │
│       │ ・メッセージ: 所属会話のみ                                          │
│       │ ・ユーザー: 友達申請フロー（クリック→申請→承諾後にDM）               │
│       │ ・グループ: 参加会話のみ                                            │
│       │ ・15歳未満はユーザー検索対象外                                       │
│       ▼                                                                   │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ 検索モーダル（searchModal）                                           │  │
│  │ ・ユーザー: [友達申請] or [DM開始]（友達の場合）or [申請中]              │  │
│  │ ・友達申請時: メッセージ入力欄あり                                      │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│  【組織内検索】メンバー追加モーダル                                            │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ [メンバーを検索]  ← api/users.php?action=search&scope=org             │  │
│  │ ・同一組織の organization_members のみ検索                               │  │
│  │ ・組織未所属グループは従来ロジック（同一グループメンバー等）               │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│  【友達追加】 Mail タブ                                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ [メール or 携帯 or 名前で検索]  ← api/friends.php?action=search         │  │
│  │ ・メールアドレス・携帯電話・表示名で検索                                  │  │
│  │ ・検索結果クリック → 友達申請（メッセージ付き）→ 承諾後にDM               │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 9. 実装チェックリスト

### 9.1 バックエンド

- [ ] `friendships` に `request_message` カラム追加
- [ ] `friendships` に `source` カラム追加（qr / invite_link / search / group）
- [ ] `api/friends.php` request に `message` パラメータ対応
- [ ] `api/friends.php` pending に `request_message` 返却
- [ ] `api/messages.php` search のユーザー結果に `is_friend`, `is_pending`, `friendship_id` を追加
- [ ] `api/messages.php` search のユーザー検索で 15歳未満を除外
- [ ] `api/messages.php` search のメッセージ検索で未所属会話を含まないことを再確認
- [ ] `api/users.php` search に `scope=org` を追加（組織内検索）
- [ ] 未成年同士の友達申請で `source` チェック（qr / invite_link のみ許可）

### 9.2 フロントエンド

- [ ] グローバル検索のユーザー結果クリック時、友達なら `startDmFromSearch`、未申請なら友達申請モーダル表示
- [ ] 友達申請モーダルにメッセージ入力欄を追加
- [ ] 友達申請一覧に `request_message` を表示
- [ ] 組織内検索用の API 呼び出しを `scope=org` で切り替え

### 9.3 データベース

- [ ] `friendships.request_message` 追加マイグレーション
- [ ] `friendships.source` 追加マイグレーション
- [ ] `users.phone` の検索インデックス確認

### 9.4 ドキュメント

- [ ] `SEARCH_ARCHITECTURE.md` を本設計に合わせて更新
- [ ] `DOCS/携帯電話機能のデプロイ手順.md` との整合確認

---

## 10. 参考：現行ファイル一覧

| ファイル | 役割 |
|---------|------|
| `includes/chat/topbar.php` | トップバー検索欄（グローバル検索） |
| `includes/chat/modals.php` | 検索モーダル、友達追加モーダル |
| `includes/chat/scripts.php` | performSearch, startDmFromSearch, 友達追加 UI |
| `api/messages.php` | グローバル検索（action=search） |
| `api/users.php` | ユーザー検索（action=search） |
| `api/friends.php` | 友達検索、友達申請（request/accept/reject） |
| `database/migration_friends.sql` | friendships テーブル |
| `DOCS/SEARCH_ARCHITECTURE.md` | 現行検索アーキテクチャ |
| `DOCS/携帯電話機能のデプロイ手順.md` | 携帯電話登録・検索 |
