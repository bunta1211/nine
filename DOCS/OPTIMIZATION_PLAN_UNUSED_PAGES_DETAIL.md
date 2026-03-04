# 最適化計画：使用していないページの詳細追記

**ステータス**: 未実施（いずれ練り直して実行する想定）  
**関連**: [CLEANUP_PLAN_UNUSED_FILES_AND_PAGES.md](CLEANUP_PLAN_UNUSED_FILES_AND_PAGES.md)

---

## 目的

削除対象を選定するために、**使用していない／メニューから辿れないページ**を一覧化した詳細セクションを、既存のクリーンアップ計画書に追加する。実装変更は行わず、**計画書の追記のみ**を行う。

---

## 追加する内容

[CLEANUP_PLAN_UNUSED_FILES_AND_PAGES.md](CLEANUP_PLAN_UNUSED_FILES_AND_PAGES.md) に以下のセクションを追加する。

### 新規セクション: 「3. 使用していないページの詳細（削除対象選定用）」

既存の「2. 削除・統合候補一覧」の直後、現在の「3. 実施フェーズ」の前に挿入する。既存の「3. 実施フェーズ」以降は「4. 実施フェーズ」に繰り下げ、セクション番号を1つずつずらす。

#### 3.1 ルート直下のページ

| ファイル | 役割 | 参照元 | メニュー／リンク | 削除候補の理由 |
|----------|------|--------|------------------|----------------|
| debug-chat.php | chat.php の require 連鎖の動作診断 | なし | なし | コメントに「使用後は削除すること」と明記。開発用。 |
| google_calendar_check.php | Googleカレンダー設定の診断 | なし | なし | コメントに「確認後は必ず削除すること」と明記。診断用。 |
| export_groups_csv.php | グループ・メンバーをCSVダウンロード | なし | なし | ログイン後でもどこからもリンクされていない。URLを直接知っている場合のみ利用可能。 |
| export_csv_simple.php | グループ・メンバーCSVエクスポート（簡易版） | なし | なし | 上記と役割が重複。どちらもメニュー未掲載。 |
| ai_chat.php | AI相談室（独立ページ） | admin/page-checker.php、assets/js/test-runner.js。自身から chat.php / settings.php への戻りリンクあり | 画面上のメニューからは直接なし（URL直打ちまたはブックマーク） | 秘書モードは chat.php?secretary=1 が主経路。役割が重複している可能性があり、廃止する場合は参照先を secretary=1 に統一する必要あり。 |

**参照ありで削除候補にしないもの（記載のみ）**: invite.php（友達招待リンク・モーダル・APIで参照）、verify_email.php（Authでメール認証リンク）、forgot_password.php（index.php にリンク）、reset_password.php（forgot_password からリダイレクト）、join_group.php（招待コード用）、accept_org_invite.php（組織招待メール）、multi_account_login.php（index.php にリンク）、call.php（通話画面・page-checker に記載）。

#### 3.2 管理画面（admin/）のページ

**サイドバーに掲載されているページ**（admin/_sidebar.php または admin/includes/sidebar.php）は一覧から除外し、**サイドバーにないページ**のみ詳細を記載する。

| ファイル | 役割 | 参照元 | メニュー／リンク | 削除候補の理由 |
|----------|------|--------|------------------|----------------|
| admin/debug_user_login.php | ログインデバッグ | なし | なし | 開発・障害調査用。本番から除外推奨。 |
| admin/debug_pdf_search.php | PDF検索デバッグ | なし | なし | 同上。 |
| admin/test_pdf_conversion.php | PDF変換テスト | なし | なし | 同上。 |
| admin/set_test_passwords.php | テスト用アカウントのパスワード一時設定 | なし | なし | 一時セットアップ用。本番では使わない運用なら削除候補。 |
| admin/extract_pdf_text.php | PDFテキスト抽出（DB更新） | 自身のみ（通常実行・強制再抽出・クリアのリンク） | サイドバーになし | 管理画面メニューから辿れない。URLを直接知っている場合のみ。運用で使うなら残し、使わないなら削除候補。 |
| admin/import_chatwork_messages.php | Chatwork メッセージインポート | なし（CLI/Web のドキュメントのみ） | なし | 移行用。移行完了後は削除候補。 |
| admin/import_chatwork_csv.php | Chatwork CSV インポート | なし | なし | 同上。 |
| admin/page-checker.php | ページチェック（エラー詳細確認） | api/page-check.php のエラーメッセージで「admin/page-checker.php で確認」と案内 | サイドバーになし | 運用で使うツール。削除する場合は api の案内文言を変更する必要あり。 |
| admin/providers.php | プロバイダー管理 | admin/wishes.php からリンク。page-checker の対象一覧に含まれる | サイドバーになし | メニューにないが wishes から利用。残すかどうかは Wish 機能の継続方針に依存。 |
| admin/wishes.php / admin/wish_patterns.php | Wish 管理・パターン | wishes と providers の相互リンク。page-checker に記載。api/wish_extractor.php が wish_patterns を参照 | サイドバーになし | 機能としては利用中。メニューに載せていないだけの可能性あり。 |

**参照ありで「詳細のみ」記載するもの**: admin/user_detail.php（user_groups または users へリダイレクト）、admin/user_groups.php（users.php の「所属グループ」からリンク）、admin/create_organization.php（topbar・settings-account-bar からリンク）、admin/members.php / admin/groups.php / admin/ai_specialist_admin.php（組織用サブナビで相互リンク）。

---

## 実施内容（変更箇所）

1. **CLEANUP_PLAN_UNUSED_FILES_AND_PAGES.md の編集**
   - 見出し「## 3. 実施フェーズ」を「## 4. 実施フェーズ」に変更。
   - 同様に「## 4. 削除前の必須確認」を「## 5. 削除前の必須確認」、「## 5. 計画の維持」を「## 6. 計画の維持」、「## 6. 関連ドキュメント」を「## 7. 関連ドキュメント」、「## 7. 実施済みメモ」を「## 8. 実施済みメモ」に変更。
   - 「## 2. 削除・統合候補一覧」の直後に、上記「3. 使用していないページの詳細（削除対象選定用）」を 3.1・3.2 の表付きで挿入する。

2. **補足**
   - 各ページの「削除候補の理由」は、選定時に「削除する／残す／要確認」を決めるための材料として記載する。
   - 参照元・メニュー有無は、現時点の grep および admin サイドバーの内容に基づく。今後のコード変更で参照が増減した場合は、計画書を更新する。

---

## 変更しないもの

- 既存の「1. 観点と選定基準」「2. 削除・統合候補一覧」「4. 実施フェーズ」以降の本文は、番号の繰り下げ以外は変更しない。
- 実際のファイル削除・リネーム・リファクタは行わない。計画書の追記のみとする。

---

## 練り直し時のメモ

- 参照関係はコード変更で変わるため、実行前に再度 grep で確認すること。
- 削除候補の優先度（Phase 1/2/3）を運用方針に合わせて見直すこと。
- CLEANUP_PLAN の「実施済みメモ」に実施日と内容を記録すること。
