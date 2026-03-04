# AI秘書まわり改善ログ（小分け・記録用）

再起動やデプロイで更新が停まらないよう、実施内容を小分けに記録します。

---

## AI秘書が「サーバーエラー」で反応しないときの切り分け

1. **診断URLを開く**（ログインした状態で）  
   `https://あなたのドメイン/api/ai-ping.php`  
   - `success: true` かつ `gemini_available: "yes"` なら、Gemini APIキーは設定済み。別原因。
   - `gemini_available: "no (API key not set)"` → `config/ai_config.local.php` で `GEMINI_API_KEY` を設定。
   - いずれかの `steps` が `error: ...` になっている → その段階で失敗している（DB・テーブル不足、ファイル不足など）。

2. **本番で開発モードを一時ONにする**  
   `config/app.php` または環境で `APP_DEBUG` を true にすると、AI秘書エラー時に画面に「実際の例外メッセージ」が返ります。原因特定後に必ず false に戻す。

3. **サーバーのエラーログを確認**  
   PHP の `error_log` に `AI API fatal: ...` と出ていれば、その直後のメッセージが原因です。

4. **よくある原因**  
   - Gemini APIキー未設定・無効・レート制限  
   - `ai_conversations` / `user_ai_settings` テーブル未作成  
   - `user_ai_settings.character_type` に存在しない値が入っている（修正でフォールバック済み）

5. **「会話もできない・ログが消える」場合**  
   - ask 時に例外が出ると、これまで「サーバーエラー」とだけ返し会話を保存していなかった。  
   - **対応**: Gemini 利用ブロック全体を try-catch で囲み、例外時は **フォールバック応答（getDefaultResponse）を返して必ず DB に保存**するようにした。  
   - これにより「サーバーエラー」ではなく簡易応答が返り、**会話が ai_conversations に残る**ためリロード後も表示される。  
   - 本番の PHP エラーログに `AI ask Gemini block: ...` と出ていれば、その直後のメッセージが原因。`config/ai_config.php` の定数不足やプロンプト生成エラーを確認する。

---

## 実行順（本番で消える場合）

1. **テーブル作成**: `database/migration_ai_secretary_tables_ensure.sql` を1回実行
2. **カラム不足時のみ**: `database/migration_ai_secretary_columns_add.sql` を1文ずつ実行（Duplicate column は無視）
3. **コード反映**: `chat.php`, `includes/chat/scripts.php` をデプロイ

---

## 実施済み

### 1. SQL: テーブルが無い環境用（1本で実行）

- **ファイル**: `database/migration_ai_secretary_tables_ensure.sql`
- **内容**: `ai_conversations`, `user_ai_settings`, `ai_user_memories` を CREATE TABLE IF NOT EXISTS で作成
- **実行**: 本番で会話ログ・記憶・キャラが消える場合に1回実行  
  `mysql -u user -p database_name < database/migration_ai_secretary_tables_ensure.sql`
- **注意**: 既にテーブルがある場合はスキップされるだけです。

### 2. SQL: 既存 user_ai_settings にカラムが無い場合のみ

- **ファイル**: `database/migration_ai_secretary_columns_add.sql`
- **内容**: `character_selected`, `user_profile` の追加、`character_type` の NULL 許容変更
- **実行**: テーブルはあるがカラム不足でエラーになる場合のみ。**1文ずつ実行**し、「Duplicate column」エラーはそのカラムが既にあるので無視してよい。MySQL 8.0 なら `migration_ai_secretary_fix.sql` の IF NOT EXISTS 付き ALTER も使える。

### 3. 強制リロードでキャラが消えないようにした（コード）

- **chat.php**: 秘書モード時に DB から `character_type` / `secretary_name` を取得し、`window.__AI_SECRETARY_PREFILL` として HTML に埋め込み
- **includes/chat/scripts.php**: 初期化時に `__AI_SECRETARY_PREFILL` を最優先で反映し、localStorage にも書き戻し
- **反映ファイル**: `chat.php`, `includes/chat/scripts.php`

### 4. 履歴読み込み失敗時の表示（コード）

- **includes/chat/scripts.php**: `loadAIHistory()` が失敗したとき「履歴を読み込めませんでした。上の🔄ボタンで再読み込みできます。」をウェルカムカードに表示
- **反映ファイル**: `includes/chat/scripts.php`

### 5. 履歴クリアの誤操作防止（コード）

- **includes/chat/scripts.php**: 会話履歴クリアの confirm を「本当にすべての会話履歴を削除しますか？この操作は取り消せません。」に変更
- **反映ファイル**: `includes/chat/scripts.php`

### 6. リロードで発言が消える問題（コード）

- **原因**: 秘書画面表示時に履歴取得（loadAIHistory）を待たずに表示を返していたため、タイミングによっては履歴が表示される前に他処理が走る可能性があった。またサーバーエラーで ask が失敗するとそのやり取りは DB に保存されず、リロード時に「消えた」ように見える。
- **対応**: `showAIMessages()` を async 化し、`loadAIHistory()` を **await** してから完了するように変更。あわせて「履歴を読み込み中...」を表示してから履歴を取得し、取得後にウェルカム文を復元。
- **反映ファイル**: `includes/chat/scripts.php`
- **補足**: 「サーバーエラーが発生しました」が出る問い合わせは DB に保存されないため、そのやり取りだけリロードで消える。AI秘書のサーバーエラーを解消すると、保存されるようになりリロード後も残る。

---

## 今後の小分けタスク（未実施なら順に実施）

- [ ] 本番で上記 SQL を実行し、3テーブル存在を確認
- [ ] 本番に chat.php / includes/chat/scripts.php をデプロイ
- [ ] （任意）api/ai.php の 400 原因調査・エラーメッセージ明確化
- [ ] （任意）icon-144x144.png の 404 解消

---

## 参照

- テーブル一覧・デプロイ時の確認: `database/SCHEMA_README.md` の「AI秘書関連」節
- チャット依存関係: `includes/chat/DEPENDENCIES.md`
