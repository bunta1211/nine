# プライベートグループ・アドレス帳・招待 マスター計画 実装ログ

マスター計画（[PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md](PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md)）の実装を小分けに記録します。  
**参照**: 同計画書 セクション5（ファイル別チェックリスト）、セクション6（実装順序）。

---

## 実装順序の目安（計画書 6 より）

1. **DB**: migration_private_group_settings.sql の作成と適用
2. **プライベートグループ**: admin UI・API → チャット側 create 拒否 → メッセージ・アップロード・メンバー一覧・アドレス追加の制御
3. **表示名**: 「友達追加」→「個人アドレス帳」の一括変更（sidebar, modals, lang, settings）
4. **組織招待**: 既存ユーザー向け統合フロー → 一斉招待テーブル・API・UI
5. **検索・招待**: ボタン「アドレス追加申請」「招待メール送信」、招待メールの「〇〇のアドレス帳に追加受諾」（※検索まわりは SEARCH_INDEX で一部済）

---

## Phase 1: データベース（マイグレーション）

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 1.1 | `database/migration_private_group_settings.sql` を新規作成（conversations に is_private_group と4設定カラム追加） | ✅ 済 | 5カラムを個別 ALTER で追加。**MySQL 5.7 対応**: `IF NOT EXISTS` を削除（8.0 のみの構文のため）。既存カラムがある場合は「Duplicate column」でスキップ可 |
| 1.2 | `database/DEPENDENCIES.md` に上記マイグレーションを追記 | ✅ 済 | 既存の conversations 表・マイグレーション一覧に記載済 |
| 1.3 | 本番DBへの SQL 適用（手動。ユーザー方針に従う） | ⬜ 未 | 実行はユーザーが実施。手順は下記「本番DB適用チェックリスト」を参照 |

### 本番DB適用チェックリスト（Phase 1.3 実施時）

本機能で本番DBに実行する SQL は次の2つです。**実行順序どおりに実施**してください。

| 順 | ファイル | 内容 |
|----|----------|------|
| 1 | `database/migration_private_group_settings.sql` | conversations に is_private_group と4設定カラムを追加（必須） |
| 2 | `database/migration_org_invite_candidates.sql` | 組織招待候補テーブル作成（一斉招待を使う場合のみ） |

**手順**: [DOCS/SERVER_DEPLOY_AND_SQL.md](SERVER_DEPLOY_AND_SQL.md) の「2. SQL の実行手順」に従い、EC2 上で `mysql ... < /var/www/html/database/ファイル名.sql` を実行する。詳細は [PRODUCTION_DB_ACCESS.md](PRODUCTION_DB_ACCESS.md) を参照。

---

## Phase 2: プライベートグループ（API・チャット側）

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 2.1 | `api/conversations.php`: create で is_private_group 等を受け付けずプライベート作成を拒否 | ✅ 済 | create 先頭で is_private_group=1 時 403 |
| 2.2 | `api/conversations.php`: 一覧・詳細取得で is_private_group と4設定を返す（カラム存在チェック） | ✅ 済 | list / list_with_unread / get で返却・int キャスト |
| 2.3 | `api/messages.php`: 送信前に is_private_group=1 かつ allow_member_post=0 なら送信拒否 | ✅ 済 | 送信直前で会話取得後に 403 |
| 2.4 | `api/upload.php`: プライベートかつ allow_data_send=0 ならアップロード拒否 | ✅ 済 | チャット添付は messages.php 内で同様チェック済 |
| 2.5 | `admin/api/groups.php`: createGroup に is_private_group と4設定を受け取り INSERT | ✅ 済 | カラム存在時のみ。通常作成は is_private_group=0 |
| 2.6 | `admin/api/groups.php`: getGroups / getGroupDetail で is_private_group と4設定を返す | ✅ 済 | カラム存在チェック付き・int キャスト |
| 2.7 | `admin/api/groups.php`: 既存グループの更新で is_private_group と4設定を変更可能に（任意） | ✅ 済 | updateGroup で PUT body の is_private_group と4設定を UPDATE。カラム存在時のみ |
| 2.8 | `includes/chat/data.php`: 会話に is_private_group と4設定を SELECT（必要なら明示） | ✅ 済 | c.* で取得。219–226行で int 正規化。マイグレーション後は自動で含まれる |
| 2.9 | `includes/chat/scripts.php`: member_list_visible=0 のとき他メンバー名非表示・メンバー数は表示 | ✅ 済 | renderMembersList で showNames=false 時「その他 N人」表示 |
| 2.10 | `includes/chat/scripts.php`: allow_add_contact_from_group=0 のときグループ内アドレス追加ボタン無効 | ✅ 済 | メンバーコンテキストメニューに「アドレス追加申請」を追加。_currentConversationAllowAddContactFromGroup で表示切替 |
| 2.11 | `admin/groups.php` + JS: 「プライベートグループを作成」ボタン・説明・モーダル（4項目） | ✅ 済 | ボタン・addPrivateGroupModal・admin-groups.js で open/close/submit。4チェックボックスで送信 |
| 2.12 | 既存グループ編集でプライベート化・4設定変更可能にする（任意） | ✅ 済 | admin/groups.php 編集モーダルに5項目を追加。admin-groups.js の openEditModal で getGroupDetail から取得して表示、saveGroupName で送信。updateGroup で UPDATE 済 |

---

## Phase 3: 表示名「友達追加」→「個人アドレス帳」

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 3.1 | `includes/lang.php`: add_friend の ja を「+ 個人アドレス帳」に変更 | ✅ 済 | en/zh も Address book / 个人通讯录 に変更 |
| 3.2 | `includes/lang.php`: filter_friends の ja を「個人アドレス帳」に変更 | ✅ 済 | 左パネルフィルタで使用 |
| 3.3 | `includes/chat/sidebar.php`: 第2ボタン・モバイルフォームの文言を「個人アドレス帳」等に | ✅ 済 | add_friend / search_* は lang 参照のため 3.1 で反映 |
| 3.4 | `includes/chat/modals.php`: 友達追加モーダル「友達追加・DM」→「個人アドレス帳・DM」、説明・ボタン文言 | ✅ 済 | タイトル・招待リンク説明・QR説明・Mail説明・招待ボタン（search_invite_mail_btn）に変更 |
| 3.5 | `includes/chat/scripts.php`: 検索結果・モーダル内の「友達申請」→「アドレス追加申請」等（文言のみ。既存 getSearchLabel と整合） | ✅ 済 | フォールバック・confirm/alert をアドレス追加申請に統一 |
| 3.6 | `settings.php`: セクション名「友だち管理」→「個人アドレス帳」、ボタン・説明を「アドレス追加申請」等に | ✅ 済 | 見出し・タブ・placeholder・モーダル・ボタン・空文言を変更済 |
| 3.7 | `invite.php`: タイトル・見出しを「アドレス帳に追加する招待」等に | ✅ 済 | タイトル・invite-title・inviter-message・ログインボタン・コメントを変更済 |
| 3.8 | その他（api/friends.php コメント、friend_request_mail.php 件名・本文、ARCHITECTURE.md） | ✅ 済 | friends.php は message/error をアドレス追加申請に。friend_request_mail は件名・本文済。ARCHITECTURE は個人アドレス帳表記済 |

---

## Phase 4: 組織招待（統合フロー・一斉招待）

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 4.1 | `accept_org_invite.php`: 既存ユーザー（パスワード設定済み）なら「統合する／キャンセル」UI | ✅ 済 | 同一ページ内で is_existing_user 時に統合フォーム表示。POST action=merge で accepted_at 更新、action=cancel でトークン無効化。GET 時は message を上書きしないよう修正済 |
| 4.2 | 組織招待候補テーブル（例: org_invite_candidates）の追加 | ✅ 済 | migration_org_invite_candidates.sql で作成。database/DEPENDENCIES.md に記載済。bulk_invite で利用可能 |
| 4.3 | `admin/api/members.php`: 既存ユーザー向け統合用メール・リンク | ✅ 済 | doCreateMember 内で sendOrgInviteMail($email, $orgName, $acceptUrl, !empty($existingUser))。org_invite_mail.php が is_existing_user 時は「統合する」案内のメールを送信 |
| 4.4 | `admin/api/members.php`: action=bulk_invite 追加 | ✅ 済 | doCreateMember を追加し createMember をリファクタ。bulkInvite で candidates をループし doCreateMember を呼び results/succeeded/failed を返す |
| 4.5 | 管理画面「メンバー管理」→「組織アドレス帳」にリネーム、一斉招待UI同居 | ✅ 済 | members.php / groups.php / ai_specialist_admin.php / create_organization.php / import_* / page-checker.php の表示を「組織アドレス帳」に変更。一斉招待UIは既存の新規登録・候補検索で代替可能。DEPENDENCIES.md 更新 |

---

## Phase 5: 検索・招待メール文言

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 5.1 | `api/friends.php` send_invite: メール件名・本文を「〇〇の個人アドレス帳に追加受諾」に変更 | ✅ 済 | 通常招待の件名・本文・リンク文言を変更。グループ招待は従来のまま |
| 5.2 | 検索結果ボタン「アドレス追加申請」「招待メール送信」は SEARCH_INDEX の lang キーで済済のため確認のみ | ✅ 済 | getSearchLabel / __SEARCH_LABELS で search_address_request_btn・search_invite_mail_btn を参照済（Phase 3 で対応） |

---

## Phase 6: ドキュメント更新（ワークスペースルール）

| # | 項目 | 状態 | 実施日・メモ |
|---|------|------|--------------|
| 6.1 | `api/DEPENDENCIES.md`: friends.php の説明を「個人アドレス帳・アドレス追加申請」に | ✅ 済 | 一覧行を更新。send_invite の件名・本文「〇〇の個人アドレス帳に追加受諾」を追記 |
| 6.2 | `includes/chat/DEPENDENCIES.md` 変更内容に応じて更新 | ✅ 済 | sidebar.php の説明を「モバイル個人アドレス帳」「招待メール送信」に変更 |
| 6.3 | `admin/DEPENDENCIES.md` 変更内容に応じて更新 | ✅ 済 | members.php の api 説明に bulk_invite・resend_invite を追記。ファイル一覧は既に「組織アドレス帳」 |
| 6.4 | `DOCS/ADDRESS_BOOK_PLAN.md` 本計画に合わせて文言・仕様を更新 | ✅ 済 | 冒頭にマスター計画書への参照を追加 |
| 6.5 | `DOCS/SEARCH_POLICY.md` 「アドレス追加申請」「招待メール送信」を明記 | ✅ 済 | タイトル・総則・個人アドレス帳の検索・関連ファイルをアドレス追加申請・招待メール送信・〇〇の個人アドレス帳に追加受諾に統一 |
| 6.6 | `ARCHITECTURE.md` 友達追加→個人アドレス帳に合わせて更新 | ✅ 済 | 検索種別表は既に「個人アドレス帳モーダル」「個人アドレス帳検索」表記のため変更なし |

---

## 進捗サマリ

- **Phase 1**: 2 / 3（1.3 は手動適用のため未）
- **Phase 2**: 12 / 12
- **Phase 3**: 8 / 8
- **Phase 4**: 5 / 5
- **Phase 5**: 2 / 2
- **Phase 6**: 6 / 6

（状態が「✅ 済」になったら上記数を都度更新）
