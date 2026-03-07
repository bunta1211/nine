# 検索機能アーキテクチャ

Social9 の検索機能を整理したドキュメントです。

> **設計図（改定版）**: 友達申請フロー・未成年保護・組織内検索分離等の要件は [SEARCH_DESIGN_V2.md](./SEARCH_DESIGN_V2.md) を参照してください。

---

## 検索種別一覧

| 種別 | 用途 | トリガー | API | 主なファイル |
|------|------|----------|-----|---------------|
| **1. グローバル検索** | メッセージ・ユーザー・グループを横断検索 | Ctrl+K / トップバー検索＋Enter | `api/messages.php?action=search` | scripts.php, modals.php |
| **2. グループメンバー検索** | DM開始用：所属グループ内メンバー | 友達追加モーダル「メンバー」タブ | `api/friends.php?action=group_members`（一覧取得）＋クライアント側フィルタ | scripts.php |
| **3. ユーザー検索（グループ追加）** | グループに追加するユーザー検索 | メンバー追加モーダル | `api/users.php?action=search` | scripts.php, modals.php |
| **4. 友達検索（メール）** | メールアドレスで友達検索 | 友達追加モーダル「Mail」タブ | `api/friends.php?action=search` | scripts.php, modals.php |
| **5. GIF検索** | メッセージ添付用GIF | 絵文字ピッカー内GIFタブ | `api/gif.php` | scripts.php |
| **6. タスク・メモ検索** | AI秘書・タスク一覧内の検索 | tasks.php / memos.php / AI秘書 | `includes/task_memo_search_helper.php` | task_memo_search_helper.php |
| **7. 場所検索** | 近くのお店検索（AI秘書） | AI秘書で位置情報付き質問 | `api/ai.php`（Places API連携） | ai.php, places_helper.php |

---

## 1. グローバル検索（メッセージ・ユーザー・グループ）

### 個人のユーザー検索について

- **個人**（システム管理者以外）がグローバル検索で**人を探す**場合、**名前（表示名）だけではヒットしません**。メールアドレス（@ を含む）または携帯番号（10桁以上の数字）を入力したときのみ、該当ユーザーが結果に表示されます。メッセージ・グループの検索は従来どおりキーワードで検索できます。
- システム管理者は従来どおり表示名・メール・携帯でユーザーを検索できます。組織ページ・システム管理ページの検索は変更していません（[SEARCH_POLICY.md](./SEARCH_POLICY.md) 参照）。

### フロー

```
トップバー検索欄クリック → focusTopBarSearch() → 入力にフォーカス
                              ↓
ユーザーが入力して Enter → openSearch() → 検索モーダルを開く
                              ↓
キーワード 2文字以上 → performSearch() → api/messages.php?action=search
                              ↓
検索履歴 localStorage に保存（最大10件）
```

### ショートカット

- **Ctrl + K**: 検索モーダルを開く（直接 openSearch）

### UI要素

| ID | 場所 | 役割 |
|----|------|------|
| `topBarSearchInput` | topbar.php | トップバー検索入力（クリックでフォーカス、Enterでモーダル表示） |
| `searchModal` | modals.php | 検索モーダル |
| `searchInput` | modals.php | モーダル内検索入力 |
| `searchResults` | modals.php | 検索結果 or 検索履歴表示 |
| `searchSectionTitle` | modals.php | 「最近の検索」/「検索結果」表示 |

### フィルタータブ

- `all`: メッセージ・ユーザー・グループ全て
- `messages`: メッセージのみ
- `users`: ユーザーのみ
- `groups`: グループのみ

### API仕様（api/messages.php action=search）

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| `keyword` | string | 検索キーワード（必須、2文字以上推奨） |
| `type` | string | all / users / messages / groups |
| `conversation_id` | int | 会話限定（オプション） |
| `sender_id` | int | 送信者限定（オプション） |
| `file_type` | string | ファイル種別限定（オプション） |
| `limit` | int | 件数（デフォルト20、最大50） |
| `offset` | int | オフセット |

### 検索結果の挙動

- **ユーザー**: `startDmFromSearch(userId, displayName)` → DM開始
- **グループ**: `openGroupFromSearch(convId, isMember)` → グループ参加 or チャット表示
- **メッセージ**: 該当会話を開いてメッセージ位置へスクロール

### 権限・制限

- システム管理者: 全ユーザー・全グループ検索可
- 通常ユーザー: 同一グループメンバー＋システム管理者のみ。参加グループのみ検索
- 保護者制限: `parental_restrictions.search_restricted` で検索制限あり

---

## 2. グループメンバー検索（DM開始用）

### フロー

```
友達追加モーダル「メンバー」タブを開く → loadAllGroupMembersForSearch()
                              ↓
api/friends.php?action=group_members → allGroupMembers にキャッシュ
                              ↓
searchMemberInput に入力 → debounceSearchGroupMembers() → filterGroupMembers()
                              ↓
クライアント側で display_name, group_names をフィルタ
```

### 備考

- 初期表示時: `loadAllGroupMembersForSearch()` でグループメンバー一覧を取得
- 検索はクライアント側フィルタ（`searchMemberInput` の値で絞り込み）

---

## 3. ユーザー検索（グループ追加用）

### フロー

```
メンバー追加モーダル → addMemberSearch に入力
                              ↓
searchMembersToAdd(query) → api/users.php?action=search&for_group_add=1
```

### API仕様（api/users.php action=search）

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| `q` | string | 検索クエリ（2文字以上） |
| `for_group_add` | 1 | グループ追加用（DM許可制限なし） |

---

## 4. 友達検索（メールアドレス）

### フロー

```
友達追加モーダル「Mail」タブ → searchUserInput に入力
                              ↓
debounceSearchUser() → searchUser() → api/friends.php?action=search
```

### API仕様（api/friends.php action=search）

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| `query` | string | メールアドレス or 表示名 |

---

## 5. GIF検索

### フロー

```
絵文字ピッカー → GIFタブ → gifSearchInput に入力 or カテゴリクリック
                              ↓
searchGif(query) → api/gif.php?q=...&limit=24
```

### API仕様（api/gif.php）

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| `q` | string | 検索クエリ |
| `limit` | int | 件数（デフォルト24） |

---

## 6. タスク・メモ検索

### フロー

- **tasks.php / memos.php**: 画面上の検索入力で `searchTasksAndMemos()` を呼び出し
- **AI秘書**: `includes/task_memo_search_helper.php` の `searchTasksAndMemos()` を利用

### 検索対象

- tasks: title, description
- memos: title, content
- messages: content, extracted_text（該当カラムがある場合）

---

## 7. 場所検索（Places API）

### フロー

```
AI秘書で「近くのランチ」「この辺のカフェ」など位置情報キーワードを含む質問
                              ↓
api/ai.php が latitude, longitude を受け取り
                              ↓
places_helper.php 経由で Places API 検索
```

---

## 共通・言語リソース

### includes/lang.php

| キー | 用途 |
|------|------|
| `search_placeholder` | グローバル検索のプレースホルダー |
| `recent_search` | 最近の検索 |
| `no_search_history` | 検索履歴なし |
| `search_hint` | 検索ヒント |
| `messages` / `users` / `groups` | フィルタータブ表示 |

---

## デバウンス・タイミング

| 検索種別 | 遅延 | 関数 |
|----------|------|------|
| グローバル検索 | 300ms | performSearch (searchTimeout) |
| グループメンバー（友達追加） | 200ms | debounceSearchGroupMembers |
| ユーザー検索（友達追加） | 300ms | debounceSearchUser |
| GIF検索 | 300ms | debounceGifSearch |

---

## 変更時のチェックリスト

### グローバル検索を変更する場合

- [ ] `includes/chat/scripts.php` の performSearch, openSearch, showSearchHistory
- [ ] `includes/chat/modals.php` の searchModal, searchInput, searchResults
- [ ] `api/messages.php` の action=search
- [ ] `includes/lang.php` の検索関連キー
- [ ] `assets/css/chat-main.css` の .search-modal, .search-result-item

### ユーザー検索を変更する場合

- [ ] `api/users.php` action=search
- [ ] `api/friends.php` action=search
- [ ] 保護者制限 `parental_restrictions.search_restricted`
- [ ] プライバシー検索設定（該当機能がある場合）

### 検索結果のクリック動作を変更する場合

- [ ] `startDmFromSearch()`, `openGroupFromSearch()` の定義場所
- [ ] メッセージ検索結果のスクロール処理
