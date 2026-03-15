# Guild 仕様・理解まとめ

Guild 関連の実装・設計を行うときは **このファイルを必ず確認すること**。  
（Cursor ルール: `.cursor/rules/guild-work.mdc` で参照）

**作業時**: 「現在の実装（ベースライン）」と「仕様・目標」の**両方**を踏まえ、抜け目なく変更する。

---

## 1. Guild の位置づけ

- **報酬分配システム**。保育園・学童保育の運営で、依頼とポイント（評価）のやり取りを管理する。
- **Social9 とは独立したサブシステム**（`Guild/` 以下に独自の config・api・includes・DB を持つ）。
- Social9 の **users テーブルは参照のみ**。**セッションは共有**（`tmp/sessions`）し、ログイン状態でそのまま Guild に入れる。
- 入口: Social9 チャットのトップバー「ギルド」リンク → `Guild/index.php` → ログイン済みなら `home.php`。

---

## 2. 単位・換算（重要）

- **単位はつけない**（「1pt」のように pt 表記はするが、通貨名などの単位表記はつけない）。
- **1pt = 100円** で換算する（表示例: 1pt→100円）。
- ※ 既存ドキュメントの「Earth」「1 Earth = 10円」は、上記に合わせて pt・100円に統一する方針。

---

## 3. 構造（大元 → 割振 → ギルド → 個人）

| 階層 | 役割 |
|------|------|
| **大元** | 予算の元。ここに予算がある。 |
| **割り振るところ** | 大元から各ギルドなどへ予算（ポイント）を割り振る。 |
| **各ギルド** | ギルド管理人が「依頼」とセットでメンバーにポイントを割り振る。 |
| **一覧（労務ページ）** | 事務担当が見る。「誰に何pt割り振られたか」の一覧。 |

---

## 4. 労務ページ（事務担当・管理者向け）

- 割り振り結果の一覧を表示する。
- **管理者がメンバーを追加できる**（Social9 名簿から Guild に登録する操作を管理者が行う）。

---

## 5. ギルド管理人向け

- **どこに誰がいるか** が分かる（ギルド・メンバー配置の可視化）。
- **誰がどの依頼を受託したか** が分かる。
- **経過報告** の機能があるとよい。
- **評価ポイント移動時期（お金が動くタイミング）** を **ギルド長が設定できる**。

---

## 6. フロー（Social9 ↔ Guild）

1. **Social9 の名簿** から Guild に登録（管理者が登録、または本人＋管理者）。
2. **ギルド管理人が依頼** を作成。
3. **メンバーが受託**。
4. **確認**（完了・承認など）。

---

## 7. 全ギルド統括

- **全ギルドを統括できるページ** を用意する（システム管理者・統括担当向け）。

---

## 8. 個人向け

- **自分がいくら（何pt）持っているか** を個人画面で分かるようにする。
- **自分への評価を可視化** する（「自分がどう評価されているか見えると人は嬉しい」）。

---

## 9. 目指す体験

- **仕事をしていて楽しい・刺激になるアプリ** にすることが一番の目標。

---

## 10. 既存実装の参照（コード・ドキュメント）

- **概要・機能一覧**: `Guild/DOCS/README.md`
- **依存関係・アーキテクチャ**: `Guild/DEPENDENCIES.md`
- **エントリ**: `Guild/index.php`（ログイン確認 → home または Social9 へ）
- **セッション**: `Guild/config/session.php`（Social9 とセッション共有）
- **ギルド長用**: `Guild/leader.php`
- **依頼**: `Guild/requests.php`、`Guild/api/requests.php`（list / create / approve / reject）
- **Social9 からの導線**: `includes/chat/topbar.php`（ギルドリンク）、`includes/chat/scripts.php`（Guild 通知バッジ）

---

## 11. 既存の役割・権限（README より）

- **システム**: システム管理者、給与支払い担当
- **ギルド内**: ギルドリーダー → サブリーダー → コーディネーター → メンバー

上記に加え、**評価ポイント移動時期をギルド長が決める** ことを仕様として扱う。

---

## 12. 現在の実装（ベースライン）— 見た目・構造の元

作業時は **ここが実装の出発点**。変更の影響範囲はここを基準に押さえる。

### 12.1 ページ一覧

| ファイル | 役割 | 主な参照 |
|----------|------|----------|
| `index.php` | エントリ。ログイン確認 → home または Social9 ログインへ。Fatal 時は setup へ | session, database |
| `home.php` | ダッシュボード（新着依頼・自分の受託・取引履歴・ギルド予算） | header, api/requests, common |
| `leader.php` | ギルド長用（リーダー・サブリーダー向け管理入口） | header, common |
| `guilds.php` | 自分のギルド一覧 | header |
| `requests.php` | 依頼一覧 | header, api/requests |
| `request.php` | 依頼詳細 | header, api/requests |
| `request-new.php` | 依頼作成 | header |
| `my-requests.php` | 自分の依頼関連 | header, api/requests |
| `calendar.php` | カレンダー（勤務予定等） | header, api/calendar |
| `notifications.php` | 通知一覧 | header, api/notifications |
| `settings.php` | 設定 | header, api/settings |
| `payments.php` | 支払い履歴 | header |
| `help.php` | ヘルプ | header |
| `admin/index.php` | 管理者トップ | admin |
| `admin/payroll.php` | 給与・支払い管理 | admin |
| `setup.php` | 初回セットアップ誘導 | config |

### 12.2 共通UI（見た目の元）

- **`templates/header.php`**
  - サイドバー: ロゴ、**「Social9 に戻る」リンク**、**Earth 残高カード**（`your_earth` / `unpaid_earth`）、ナビ（ホーム・依頼・カレンダー・通知・設定・マイギルド・ギルド長メニュー・管理者メニュー）、言語切替、テスト期間バナー。
  - 現在の表記: **「Earth」**・`getUserEarthBalance()`・`earth_amount` など。仕様では **pt・1pt=100円・単位つけない** に寄せる方針。
- **`templates/footer.php`**
  - Earth 受け取りアニメーション用オーバーレイ（`earth-animation-overlay`）。
- **CSS**: `assets/css/common.css`, `layout.css`, `home.css`, `requests.css`, `calendar.css` など（ページごとに `$extraCss` で追加）。
- **JS**: `assets/js/layout.js`, `home.js`, `requests.js` など（`$extraJs`）。

### 12.3 共通ロジック・設定

- **`includes/common.php`**
  - `getCurrentUser()`, **`getUserEarthBalance()`**, **`formatEarth($amount)`**（現状 "X Earth" 表記）、権限系（`isGuildLeaderOrSubLeader()`, `isGuildSystemAdmin()` 等）、年度・精算・凍結（`getCurrentFiscalYear()`, `shouldShowSettlementWarning()`, `isFreezeZPeriod()`）。
- **`config/database.php`** … DB 接続（Social9 と同 DB を参照する構成）。
- **`config/session.php`** … セッション開始・**Social9 と共有**（`tmp/sessions`）・`isGuildLoggedIn()`, `getGuildUserId()`。
- **`config/app.php`** … `GUILD_URL`, `SOCIAL9_URL` 等。
- **`includes/lang.php`** および **`includes/lang/ja.php`, en.php, zh.php`** … 多言語。`__('your_earth')`, `__('unpaid_earth')` 等。

### 12.4 API

| パス | 主な用途 |
|------|----------|
| `api/requests.php` | list / create / approve / reject 等（依頼一覧・作成・承認・却下） |
| `api/notifications.php` | 通知取得。Social9 連携は `includes/app_notify.php` |
| `api/calendar.php` | カレンダー用 |
| `api/settings.php` | 設定の保存・取得 |
| `api/auth.php` | 認証まわり |

### 12.5 データベース（主要テーブル）

- **ギルド・メンバー**: `guild_guilds`, `guild_members`（role: leader / sub_leader / coordinator / member）
- **ポイント（現行は Earth 名）**: `guild_earth_balances`, `guild_earth_transactions`, `guild_payments`, `guild_advance_requests`
- **依頼**: `guild_requests`, `guild_request_targets`, `guild_request_applications`, `guild_request_assignees`（受託・完了報告・承認・earth_paid）
- **ユーザー拡張・権限**: `guild_user_profiles`, `guild_system_permissions`

スキーマ・コメントではいまだ **「Earth」**・「報酬Earth額」等の表記あり。仕様に合わせる場合は **pt・1pt=100円** に寄せつつ、単位表記はつけない方針で変更する。

### 12.6 Social9 との接点

- **チャットトップバー**: `includes/chat/topbar.php` の「ギルド」リンク（`getBaseUrl() . '/Guild/'`）。
- **通知バッジ**: `includes/chat/scripts.php` で `Guild/api/notifications.php?action=count` を叩き、バッジ表示。

---

## 13. 作業時の結びつけ（抜け目なくやるために）

1. **影響範囲の把握**  
   変更する機能が「現在の実装」のどのページ・API・DB・共通関数に載っているかを、**§12 を基準に** 確認する。
2. **仕様・目標との照合**  
   §1〜§11 の「単位」「構造」「労務ページ」「ギルド長権限」「フロー」「全ギルド統括」「個人の可視化」「目指す体験」と矛盾・抜けがないか確認する。
3. **表記・仕様の統一**  
   新規・修正する画面・API・DB コメントでは **1pt=100円・単位つけない** を前提にし、既存の「Earth」「1 Earth=10円」は段階的に pt・100円に寄せる。
4. **この .md の更新**  
   仕様を変えたり、新規ページ・API・テーブルを足したりしたら、**§12 現在の実装** と §10・§11 の参照を必要に応じて更新する。
