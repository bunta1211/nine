# Social9 全デバイス表示 監査一覧（Phase A）

全エントリポイントの viewport 設定と読み込み CSS を一覧化した調査結果です。

## ルート直下のページ

| ページ | viewport | 読み込みCSS | モバイル用 | 備考 |
|--------|----------|-------------|-----------|------|
| index.php | width=device-width, initial-scale=1.0 | pwa-install.css + インライン | インラインのみ（max-width: 480px, padding: 20px） | ログイン画面。フォームは font-size: 16px 済み |
| chat.php | viewport + maximum-scale=1.0, user-scalable=no | common, mobile, chat-main, ai-*, panel-*, header, task-card, chat-new, pwa-install, push-notifications, ai-personality, storage, panel-panels-unified, chat-mobile | mobile.css + chat-mobile.css + chat-mobile.js | 最も対応済み |
| settings.php | viewport + maximum-scale=1.0（2行） | common, header, panel-panels-unified, mobile, pages-mobile, push-notifications | mobile.css + pages-mobile.css | 対応済み |
| tasks.php | viewport + maximum-scale=1.0（2行） | common, header, panel-panels-unified, mobile, pages-mobile | mobile.css + pages-mobile.css | 対応済み |
| notifications.php | viewport + maximum-scale=1.0（2行） | common, header, panel-panels-unified, mobile, pages-mobile | mobile.css + pages-mobile.css | 対応済み |
| design.php | viewport（2行、片方 maximum-scale） | common, header, panel-panels-unified, mobile, pages-mobile | mobile.css + pages-mobile.css | 対応済み |
| call.php | width=device-width, initial-scale=1.0 | common.css + インライン | なし | 要: mobile.css または @media で小画面対応 |
| register.php | width=device-width, initial-scale=1.0 | common.css | なし | 要: mobile.css でフォーム・ボタン対応 |
| forgot_password.php | width=device-width, initial-scale=1.0 | common.css | なし | 要: mobile.css |
| reset_password.php | width=device-width, initial-scale=1.0 | common.css | なし | 要: mobile.css |
| verify_email.php | width=device-width, initial-scale=1.0 | common.css | なし | 要: mobile.css |
| accept_org_invite.php | width=device-width, initial-scale=1.0 | common.css + インライン | なし | 要: mobile.css |
| invite.php | width=device-width, initial-scale=1.0 | インラインのみ | なし | 要: インライン @media または common+mobile |
| join_group.php | width=device-width, initial-scale=1.0 | インラインのみ | なし | 要: インライン @media または common+mobile |
| admin.php | width=device-width, initial-scale=1.0 | インライン等 | なし | 管理入口 |
| 404.php | width=device-width, initial-scale=1.0 | インライン等 | なし | 軽いページ |
| memos.php | （リダイレクト） | - | - | tasks.php?tab=memos へ |
| export_groups_csv.php | width=device-width, initial-scale=1.0 | 要確認 | 要確認 | CSV 出力用 |
| multi_account_login.php | width=device-width, initial-scale=1.0 | 要確認 | 要確認 | マルチアカウント |

## 管理画面（admin/*）

| ページ | viewport | 読み込みCSS | モバイル用 | 備考 |
|--------|----------|-------------|-----------|------|
| admin/index.php | あり | common.css | admin.css 内に @media 1024/768 あり | サイドバー折りたたみ等あり |
| admin/users.php | あり | common.css | 同上 | |
| admin/members.php | あり | admin.css | 同上 | |
| admin/groups.php | あり | admin.css | 同上 | |
| admin/settings.php | あり | common.css | 同上 | |
| admin/security.php | あり | admin.css | 同上 | |
| admin/monitor.php | あり | admin.css | 同上 | |
| admin/attackers.php | あり | common.css | 同上 | |
| admin/logs.php | あり | common.css | 同上 | |
| admin/reports.php | あり | common.css | 同上 | |
| admin/backup.php | あり | admin.css | 同上 | |
| admin/providers.php | あり | common.css | 同上 | |
| admin/wishes.php | あり | common.css | 同上 | |
| admin/improvement_reports.php | あり | common.css | 同上 | |
| admin/page-checker.php | あり | common.css | 同上 | 検査ツール |
| admin/ai_*.php, admin/storage_billing.php 等 | あり | common / admin | 同上 | |

## Guild

| ページ | viewport | 読み込みCSS | モバイル用 | 備考 |
|--------|----------|-------------|-----------|------|
| Guild/templates/header.php | あり | common, layout + $extraCss | layout.css に @media 1024/640。各ページCSS に @media 768/640/480 あり | 対応済み |
| Guild/setup.php | あり | 同上 | インライン max-width 等 | |
| Guild/help.php, settings.php, home.css, requests.css, calendar.css 等 | 同上 | 同上 | 各CSS に @media あり | |

## テンプレート・その他

| ページ | viewport | 読み込みCSS | 備考 |
|--------|----------|-------------|------|
| templates/access_denied.php | あり | common.css | 軽いページ |
| templates/blocked.php | あり | 要確認 | 軽いページ |

## Phase B/C メモ（実機・page-checker 実施時に記入）

- **375px / 390px**: 認証・入口ページのフォーム・ボタンがタップしやすいか
- **768px**: 管理画面のサイドバー・テーブルが横スクロールで見られるか
- **1024px**: iPad 横の表示
- **page-checker**: ビューポート×テーマ×ページの視覚チェック・コンソールエラー結果

## 改善実施チェックリスト（3.2 に基づく）

- [x] 1. 全ページ viewport 統一（未設定・古い内容の修正） — 全ページに viewport あり
- [x] 2. 認証・入口ページに mobile.css 読み込みまたは @media 追加 — register, forgot_password, reset_password, verify_email, accept_org_invite, call に mobile.css 追加。invite, join_group にインライン @media 追加
- [x] 3. 管理画面の小画面確認 — admin.css の @media 1024/768 で対応済み
- [x] 4. Guild の 768px 以下確認 — layout.css および各ページ CSS に @media あり
- [x] 5. タブレット 768～1024px の扱い — 現状維持（768 以下をモバイルとして問題なし）
- [x] 6. タッチ・フォーム・セーフエリア（44px、font-size 16px、safe-area-inset） — common.css に 768px 以下でタッチ・フォーム追加。pages-mobile.css の FAB に safe-area-inset 追加
- [x] 7. モーダル・ドロップダウン小画面確認 — 既存 scripts.php 等の位置補正で対応
- [x] 8. page-checker 定期利用のドキュメント化 — DEVICE_VIEW_SURVEY_AND_IMPROVEMENT_PLAN.md に記載
