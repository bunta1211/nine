# 検索インデックス（検索まわり 単一参照ドキュメント）

検索関連のポリシー・API・UI・文言・フロー・変更チェックリストを一箇所にまとめたドキュメントです。**検索まわりを変更するときは本書を最初に参照**し、該当セクションとチェックリストに従って実装してください。

- **詳細**: [SEARCH_POLICY.md](SEARCH_POLICY.md)（ポリシー）、[SEARCH_ARCHITECTURE.md](SEARCH_ARCHITECTURE.md)（技術）、[SEARCH_DESIGN_V2.md](SEARCH_DESIGN_V2.md)（設計v2）

---

## 1. ポリシー（要約）

- **個人が人を探すとき**（グローバル検索）: **名前だけでは検索できない**。メールアドレス（@含む）または携帯番号（10桁以上）を入力したときのみユーザーが結果に表示される。メッセージ・グループはキーワード検索可。
- **本名・表示名で検索できるのは組織内のみ**。組織外は表示名ではヒットしない。
- **個人アドレス帳の検索**（友達追加モーダル・Mailタブ）: メールアドレスまたは携帯番号で検索。**登録済み**の場合は「**アドレス追加申請**」を表示して申請送信。**未登録**の場合は「**招待メール送信**」を表示し、受信者には「**〇〇の個人アドレス帳に追加受諾**」リンクを送る。
- **グループ追加の検索**: 自分が所属する組織（organization_members）にいるユーザーのみ。組織未所属の場合は従来どおり同一グループメンバーから選択。
- **PC・モバイル共通**で上記条件。

---

## 2. 検索種別一覧

| 種別ID | 用途 | トリガー | API | 推奨パラメータ | 検索対象 |
|--------|------|----------|-----|----------------|----------|
| **global** | メッセージ・ユーザー・グループ横断 | トップバー検索＋Enter / Ctrl+K | `api/messages.php?action=search` | `keyword`, `type`, `limit` | メッセージ（所属会話のみ）、ユーザー、グループ（参加会話のみ） |
| **group_members** | DM開始用（所属グループ内メンバー） | 個人アドレス帳モーダル「メンバー」タブ | `api/friends.php?action=group_members` | （一覧取得＋クライアント絞り込み） | 同一グループメンバー |
| **users_for_group** | グループに追加するユーザー | メンバー追加モーダル | `api/users.php?action=search` | `q`, `for_group_add=1`, `scope=org`（組織時） | 組織メンバー or 同一グループメンバー |
| **address_search** | 個人アドレス帳検索（メール/携帯） | 個人アドレス帳モーダル「Mail」タブ、モバイル検索フォーム | `api/friends.php?action=search` | **`query`**（統一。`q` は非推奨） | メールアドレス・携帯番号・表示名 |
| **gif** | メッセージ添付用GIF | 絵文字ピッカーGIFタブ | `api/gif.php` | `q`, `limit` | GIF |
| **task_memo** | タスク・メモ検索 | tasks.php / AI秘書 | `includes/task_memo_search_helper.php` | （画面依存） | タスク・メモ・メッセージ |
| **places** | 近くのお店（AI秘書） | AI秘書で位置情報付き質問 | `api/ai.php` | （位置情報） | Places API |

---

## 3. API 一覧

| API | メソッド | 必須パラメータ | 主な返却 | 備考 |
|-----|----------|----------------|----------|------|
| `api/messages.php?action=search` | GET | `keyword`（2文字以上） | `messages`, `users`, `groups` | 個人はユーザー検索でメール/携帯のみ。`type`: all\|users\|messages\|groups |
| `api/friends.php?action=search` | GET | **`query`**（2文字以上） | `users`, `invite_available`, `contact` | メール/携帯で検索。未登録時は `invite_available: true` と `contact` |
| `api/friends.php?action=group_members` | GET | — | グループメンバー一覧 | クライアント側でフィルタ |
| `api/users.php?action=search` | GET | `q`, `for_group_add=1` | ユーザー一覧 | `scope=org` で組織内に限定 |
| `api/gif.php` | GET | `q` | GIF一覧 | `limit` 省略時24 |

---

## 4. UI・文言

検索種別ごとのラベルは **`includes/lang.php`** のキーで管理する。PC・モバイルとも **`getSearchLabel(key)`**（`assets/js/search-common.js`）で取得し、未読込時は `window.__SEARCH_LABELS` をフォールバックする。

### 4.0 共通レイヤー（search_config.php / search-common.js）

| ファイル | 役割 |
|----------|------|
| **includes/search_config.php** | 個人アドレス帳検索の API パス・パラメータ名を定数で定義。`search_config_for_js()` で `window.__SEARCH_CONFIG` 用の連想配列を返す。`scripts.php` が require し、JS に出力する。 |
| **assets/js/search-common.js** | `getSearchLabel(key)`：文言を `window.__SEARCH_LABELS` から取得（未定義時はフォールバック）。`addressSearch(query)`：個人アドレス帳検索 API を呼び出し、fetch の Promise を返す。`chat.php` で scripts.php の後に読み込む。 |

### 4.1 既存キー（検索モーダル・グローバル検索）

| キー | 用途 |
|------|------|
| `search_placeholder` | グローバル検索のプレースホルダー |
| `recent_search` | 最近の検索 |
| `no_search_history` | 検索履歴なし |
| `search_hint` | 検索ヒント |
| `messages` / `users` / `groups` | フィルタータブ |

### 4.2 個人アドレス帳検索用キー（追加）

| キー | 用途 | 例（ja） |
|------|------|----------|
| `search_address_placeholder` | メール/携帯検索のプレースホルダー | メールアドレスまたは携帯番号で検索 |
| `search_address_hint` | 検索説明文 | 登録済みの方はアドレス追加申請、未登録のメールアドレスには招待を送れます |
| `search_address_request_btn` | 登録済みユーザー用ボタン | アドレス追加申請 |
| `search_invite_mail_btn` | 未登録向けボタン | 招待メール送信 |
| `search_invite_accept_label` | 招待メール内のリンク文言（〇〇に差し替え） | 〇〇の個人アドレス帳に追加受諾 |
| `search_no_user` | ユーザーがいないとき | ユーザーが見つかりません |
| `search_loading` | 検索中 | 検索中... |
| `search_error` | 検索エラー | 検索エラーが発生しました |
| `search_address_request_sent` | アドレス追加申請送信後のメッセージ | アドレス追加申請を送信しました |
| `search_sending` | 招待送信ボタンの送信中表示 | 送信中... |
| `search_invite_sent` | 招待送信成功時のメッセージ | 招待を送信しました |
| `search_invite_done` | 招待済みラベル（チェック横） | 招待済み |
| `search_invite_error` | 招待送信失敗時のメッセージ | 招待の送信に失敗しました |

---

## 5. フロー（種別ごと）

| 種別 | トリガー | 呼び出し元（PC） | 呼び出し元（モバイル） | API | 結果表示・クリック |
|------|----------|------------------|------------------------|-----|--------------------|
| global | トップバーEnter / Ctrl+K | scripts.php: openSearch → performSearch | （同一） | messages.php?action=search | searchModal, startDmFromSearch / openGroupFromSearch |
| group_members | 個人アドレス帳モーダル「メンバー」タブ | scripts.php: loadAllGroupMembersForSearch, filterGroupMembers | （同一） | friends.php?action=group_members | クライアントフィルタ、startDmFromSearch |
| users_for_group | メンバー追加モーダル入力 | scripts.php: searchMembersToAdd | — | users.php?action=search | メンバー追加 |
| address_search | 個人アドレス帳モーダル「Mail」タブ、モバイル検索フォーム | scripts.php: searchUser()（addressSearch） | chat-mobile.js: searchMobileFriend()（addressSearch） | friends.php?action=search&**query=** | アドレス追加申請 / 招待メール送信 |
| gif | 絵文字ピッカーGIFタブ | scripts.php: searchGif | — | gif.php | GIF一覧 |

---

## 6. 変更時のチェックリスト

### ポリシーを変えたとき

1. [ ] [SEARCH_POLICY.md](SEARCH_POLICY.md) を更新
2. [ ] 本書「1. ポリシー」要約を更新
3. [ ] 影響する API（messages.php / friends.php / users.php）のコメント・制御ロジックを確認
4. [ ] 該当する UI の説明文・ツールチップを確認

### API を変えたとき

1. [ ] 本書「2. 検索種別一覧」「3. API 一覧」を更新
2. [ ] 該当 API の実装を変更
3. [ ] PC（includes/chat/scripts.php）・モバイル（assets/js/chat-mobile.js）の呼び出し（URL・パラメータ）を両方確認・統一

### 文言を変えたとき

1. [ ] `includes/lang.php` の該当キーを変更
2. [ ] 本書「4. UI・文言」を更新
3. [ ] scripts.php / modals.php / chat-mobile.js で `getSearchLabel(key)` または `__SEARCH_LABELS` を参照しているか確認（search-common.js 読込ページでは getSearchLabel を優先）

### 新規検索種別を足したとき

1. [ ] 本書の 2〜5 に種別を追加
2. [ ] API または既存 API のパラメータを定義
3. [ ] 文言キーを lang.php に追加
4. [ ] フロントのトリガー・結果表示を実装
5. [ ] チェックリストに「この種別は〇〇」と一行メモを追加

---

## 関連ドキュメント

- [SEARCH_POLICY.md](SEARCH_POLICY.md) — 検索ポリシー詳細
- [SEARCH_ARCHITECTURE.md](SEARCH_ARCHITECTURE.md) — 検索アーキテクチャ・変更時チェックリスト
- [SEARCH_DESIGN_V2.md](SEARCH_DESIGN_V2.md) — 友達申請フロー・未成年保護・組織内検索
- [PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md](PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md) — 個人アドレス帳表記・アドレス追加申請／招待メール送信の文言方針
