# 改善・デバッグログの使い方（改善提案フロー）

ユーザーや管理者が挙げた問題・改善希望を「改善提案書」として蓄積し、**Cursor に貼り付けてファイル改善につなげる**ための手順です。

---

## 誰が使うか

- **管理者（org_admin 等）**: 管理画面の「改善・デバッグログ」にアクセスし、提案の一覧・Cursor用コピー・改善完了通知を行う。
- **一般ユーザー**: AI秘書に問題・改善希望を伝え、確認後に「改善提案」と送ると提案として記録される（文中に「改善提案」が含まれていても反応）。記録は管理者画面に残る。

---

## 管理者の基本フロー

### 1. 改善・デバッグログを開く

1. 管理パネル（https://social9.jp/admin/ 等）にログインする。
2. 左メニューから **「改善・デバッグログ」** をクリックする。
3. 一覧で「報告者別 提案数」と「提案一覧」を確認する。

### 2. Cursor用にコピー → 貼り付け → ファイル改善

1. 対応したい提案の行で **「Cursor用にコピー」** ボタンをクリックする。
2. クリップボードに Markdown 形式の提案書がコピーされる（ID・日時・概要・問題の内容・問題の場所・想定原因・望ましい対応・関連ファイル）。
3. **Cursor** のチャットを開き、コピーした内容を貼り付ける。
4. 「この提案書に従って該当ファイルを修正してください」などと依頼する。
5. Cursor（AI）が差分を提示したら、**本番へは [DOCS/DEPLOY_POWERSHELL_SCP.md](./DEPLOY_POWERSHELL_SCP.md) の手順で該当ファイルをアップロード**する。

### 3. 改善完了・ユーザーに通知

1. 該当提案の **「改善完了・通知」**（または「改善完了」）ボタンをクリックする。
2. 確認ダイアログで「はい」を選ぶ。
3. 提案の status が「対応済み」になり、報告者（user_id がある場合）に **notifications** で「改善提案が受理され、改善が完了しました。ご確認ください。」が 1 件入る。
4. 報告者はチャット画面の通知や通知一覧で確認できる。

---

## 手動で新規提案を作る場合

1. 管理画面「改善・デバッグログ」の **「新規提案（手動）」** フォームを埋める。
2. タイトル・問題の内容は必須。問題の場所（上/左/中央/右パネル等）・想定原因・望ましい対応・関連ファイルは任意。
3. **保存** をクリックすると `improvement_reports` に 1 件保存され（`source='manual'`）、一覧に表示される。
4. あとは上記「Cursor用にコピー」の手順で Cursor に渡して改善する。

---

## AI秘書から提案を記録する場合

- ユーザーが AI秘書に問題・改善希望を伝える。
- AI秘書が内容を要約して「このような改善希望でよかったでしょうか？」と確認する。
- ユーザーが「改善提案」と送ったとき（文中に含まれていても可）に、**extract_improvement_report** API で `improvement_reports` に 1 件保存される（`source='ai_chat'`）。
- 管理者は「改善・デバッグログ」で同じように「Cursor用にコピー」→ Cursor に貼り付け→改善→「改善完了・通知」ができる。

---

## 関連ドキュメント

| ドキュメント | 内容 |
|-------------|------|
| [IMPROVEMENT_CONTEXT.md](./IMPROVEMENT_CONTEXT.md) | 画面・機能ごとの主要ファイル一覧（改善提案記録時のコンテキスト用） |
| [IMPROVEMENT_REPORTS_TABLE_SETUP.md](./IMPROVEMENT_REPORTS_TABLE_SETUP.md) | 本番で `improvement_reports` テーブルを作る手順（phpMyAdmin / PowerShell） |
| [DEPLOY_POWERSHELL_SCP.md](./DEPLOY_POWERSHELL_SCP.md) | 本番へファイルをアップロードする手順（PowerShell で scp） |

---

## 技術メモ

- **テーブル**: `improvement_reports`（作成は `database/improvement_reports.sql`）。
- **API**: `api/improvement_reports.php`（create / get / mark_done）、`api/ai.php` の `extract_improvement_report`。
- **管理画面**: `admin/improvement_reports.php`。権限は他管理画面と同様（org_admin 等）。
