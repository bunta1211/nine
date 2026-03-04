# 自動翻訳・言語選択の解決方法

## 現象

- 表示言語を英語・中国語にしても、**3日以内のメッセージが自動翻訳されない**
- 地球アイコン（手動翻訳）は動くが、**自動翻訳APIが起動していない**
- 強制リロード後、**他の言語を選択できなくなる**ことがある
- コンソールに `POST api/translate.php 500 (Internal Server Error)` が出る

---

## 原因の整理

1. **翻訳APIが500で落ちる**
   - 本番DBに `messages.source_lang` や `translation_usage` のカラムが無い／スキーマが違うと、SQL 実行時に例外 → 500
   - 予算チェック（`budget_status`）や `auto_translate_messages` 内の例外がそのまま外に出て 500 になる

2. **自動翻訳が1回しか走らない・走るタイミングがずれる**
   - `DOMContentLoaded` の後にスクリプトが読み込まれると、イベントが既に発火済みで `initAutoTranslation` が一度も実行されない
   - 1秒後だけ実行しており、DOM の準備が遅いと対象メッセージが 0 件のまま終わる

3. **言語APIが例外で500になる**
   - `api/language.php` の POST で DB 更新時に例外（カラムなし等）→ HTML の 500 が返る
   - クライアントの `response.json()` がパースに失敗し、言語切り替えが失敗する

---

## 解決方法（実施した対応）

### 1. api/translate.php

| 対応 | 内容 |
|------|------|
| **メッセージ取得** | `SELECT m.content, m.source_lang` → `SELECT m.content` のみに変更。`source_lang` は本番で未追加の可能性があるため使わず、言語は `detectLanguage()` で判定 |
| **予算チェック** | `checkTranslationBudget()` 内の `catch (PDOException)` → `catch (Throwable)` に変更。テーブル・カラム未作成時もコスト 0 として扱い 500 を防ぐ |
| **budget_status** | `catch (Exception)` → `catch (Throwable)` に変更。例外時も **200 + JSON**（`allowed: true`, `auto_translation_enabled: true`）を返し、500 にしない |
| **キャッシュ・DB** | `message_translations` / `translation_cache` の SELECT・INSERT を try-catch で囲み、テーブルが無くても翻訳本体は実行して結果を返す |
| **auto_translate_messages** | メッセージ取得～翻訳～記録のループ内を try-catch で囲み、1件失敗しても他は続行し 200 で返す |

### 2. includes/chat/scripts.php（自動翻訳・言語切り替え）

| 対応 | 内容 |
|------|------|
| **実行タイミング** | `document.readyState === 'loading'` のときだけ `DOMContentLoaded` に登録。既に読み込み済みならその場で `scheduleAutoTranslation()` を呼び、**DOMContentLoaded を逃しても必ず実行** |
| **リトライ** | 1秒後と 3.5秒後にそれぞれ `initAutoTranslation` を実行。初回で 0 件でも DOM が遅れて描画される環境で再実行 |
| **表示言語取得** | `getDisplayLanguageForTranslation()` で `response.text()` → `JSON.parse` に変更。`credentials: 'same-origin'` を付与 |
| **予算・翻訳 fetch** | `getTranslationBudgetStatus` と `api/translate.php`（auto_translate_messages）の fetch に `credentials: 'same-origin'` を付与 |
| **changeLanguage** | URL を `'/api/language.php'` → `'api/language.php'`（相対）に変更。`response.text()` → `JSON.parse` で安全にパース。`credentials: 'same-origin'` を付与。`languageDropdown` は `?.` で参照 |

### 3. api/language.php

| 対応 | 内容 |
|------|------|
| **POST 全体を try-catch** | 例外時も **500 でも body は JSON**（`success: false, error: 'Server error'`）を返す |
| **DB 更新** | `catch (Exception)` → `catch (Throwable)`。`getDB()` 失敗時は display_language 用に再度 `getDB()` を呼び、未定義の `$db` を参照しない |

### 4. 翻訳用テーブル（本番で未作成の場合）

- `database/translation_tables_for_production.sql` で一括作成する。
- 実行方法は **DOCS/AWS_RDS_SQL_EXECUTE.md** の「翻訳テーブルを一括作成する」「次のSQL追加時にも使える共通手順」を参照。

---

## 本番にアップロードするファイル

| ファイル | 役割 |
|----------|------|
| **api/translate.php** | 翻訳APIの例外対策・source_lang 依存削除・予算・キャッシュの try-catch |
| **api/language.php** | 言語APIの例外対策・常に JSON で返す |
| **includes/chat/scripts.php** | 自動翻訳の実行タイミング・リトライ・言語切り替えの安全な fetch |

本番で翻訳用テーブルがまだ無い場合は、上記に加えて **database/translation_tables_for_production.sql** を EC2 に置き、**DOCS/AWS_RDS_SQL_EXECUTE.md** の手順で MySQL を実行する。

---

## 確認ポイント

1. **言語選択**  
   地球アイコン → 日本語 / English / 中文 を選択 → 画面がリロードして言語が変わること。コンソールに 500 が出ないこと。

2. **自動翻訳**  
   表示言語を英語（EN）にしてチャットをリロード → 3日以内の他者メッセージがある会話を開く → 数秒以内に日本語が英語で表示されること。コンソールに `[Auto translation] Applied N translations` が出ること。

3. **手動翻訳**  
   メッセージ横の地球アイコンを押すと、翻訳結果または原文表示に切り替わること。

---

## 関連ドキュメント

- **DOCS/AWS_RDS_SQL_EXECUTE.md** … 本番で SQL を実行する手順（翻訳テーブル作成・次のSQL追加時の共通手順）
- **DOCS/spec/04_翻訳機能.md** … 翻訳機能の仕様
