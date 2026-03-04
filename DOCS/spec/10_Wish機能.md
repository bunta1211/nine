# Wish機能仕様書

## 概要

Wishとは「やりたいこと、欲しいもの、叶えたい願い」をリスト化する機能です。
従来の「タスク」という言葉を「Wish」に置き換え、より前向きでポジティブな印象を与えます。

### 主な機能

1. **手動Wish登録**: ユーザーが直接Wishを追加・編集・削除
2. **手動抽出**: `api/wish_extractor.php` の `extract` アクションでメッセージからWishを抽出・保存（任意）
3. **パターン管理**: 管理者が抽出パターン（wish_patterns）を設定

> **注意**: チャット送信時に自動で文章をタスク化する処理は**無効**です（api/messages.php では `extractAndSaveWishes` を呼び出していません）。自動でタスクにしたい場合は上記の手動抽出APIを利用してください。

---

## AI自動抽出システム

### 概念図

```
┌─────────────────────────────────────────────────────────────┐
│ チャットメッセージ送信                                        │
│ 「明日、田中さんに資料送っておいてください」                    │
│ 「来月、沖縄行きたいなー」                                    │
│ 「新しいノートパソコンほしい」                                 │
└─────────────────────────────────────────────────────────────┘
                           ↓ パターンマッチング
┌─────────────────────────────────────────────────────────────┐
│ 自動抽出されたWish（自動保存）                                │
│ ⭐ 田中さんに資料を送る（依頼）                               │
│ ⭐ 沖縄旅行（旅行）                                          │
│ ⭐ 新しいノートパソコン（欲しい）                              │
└─────────────────────────────────────────────────────────────┘
```

### 抽出フロー（手動抽出時）

※ 送信時の自動実行は廃止済み。手動で `api/wish_extractor.php` の `extract` を呼ぶ場合の流れです。

1. クライアントまたは管理者が `api/wish_extractor.php` に `action=extract` でリクエスト
2. アクティブなパターンを優先度順に適用（または長文時はAI分析）
3. マッチした願望を `auto_save=true` なら tasks テーブルに保存（`source='ai_extracted'` 等）
4. 24時間以内の同一内容は重複スキップ

---

## 抽出カテゴリ

| カテゴリ | 英語名 | 例 |
|----------|--------|-----|
| 依頼 | request | 「〜しておいて」「〜お願いします」 |
| 願望 | desire | 「〜したいな」「〜見たい」 |
| 欲しい | want | 「〜ほしい」 |
| 旅行 | travel | 「〜行きたい」 |
| 購入 | purchase | 「〜買いたい」 |
| やること | work | 「〜やらなきゃ」「〜忘れないように」 |
| その他 | other | 上記以外 |

---

## データベース設計

### wish_patterns テーブル

```sql
CREATE TABLE wish_patterns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(500) NOT NULL,           -- 正規表現パターン
    category ENUM('request', 'desire', 'want', 'travel', 'purchase', 'work', 'other'),
    category_label VARCHAR(50),              -- カテゴリ表示名
    description VARCHAR(200),                -- パターンの説明
    example_input TEXT,                      -- 例文（入力）
    example_output VARCHAR(200),             -- 例文（抽出結果）
    extract_group INT DEFAULT 1,             -- 抽出するキャプチャグループ番号
    suffix_remove VARCHAR(100),              -- 除去する接尾辞（カンマ区切り）
    is_active TINYINT(1) DEFAULT 1,
    priority INT DEFAULT 0,                  -- 優先度（高い順に適用）
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### tasks テーブル追加カラム

```sql
ALTER TABLE tasks 
    ADD COLUMN source ENUM('manual', 'ai_extracted') DEFAULT 'manual',
    ADD COLUMN source_message_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN original_text TEXT DEFAULT NULL,
    ADD COLUMN confidence DECIMAL(3,2) DEFAULT 1.00,
    ADD COLUMN category VARCHAR(50) DEFAULT NULL;
```

---

## 初期パターン例

| パターン | カテゴリ | 優先度 | 例 |
|----------|----------|--------|-----|
| `(.+?)(?:して\|やって)(?:おいて\|ください)` | request | 100 | 「資料準備しておいて」→「資料準備」 |
| `(.+?)(?:行き\|いき)たい(?:な)?$` | travel | 85 | 「沖縄行きたいな」→「沖縄」 |
| `(.+?)(?:欲し\|ほし)い(?:な)?$` | want | 85 | 「パソコンほしいな」→「パソコン」 |
| `(.+?)(?:買い\|かい)たい(?:な)?$` | purchase | 85 | 「バッグ買いたい」→「バッグ」 |
| `(.+?)(?:やらなきゃ\|しなきゃ)$` | work | 75 | 「レポートやらなきゃ」→「レポート」 |

---

## API仕様

### エンドポイント

`api/wish_extractor.php`

### アクション一覧

| アクション | 説明 | 権限 |
|------------|------|------|
| extract | メッセージからWishを抽出・保存 | ユーザー |
| test | パターンテスト（保存せず） | ユーザー |
| patterns | パターン一覧取得 | ユーザー |
| add_pattern | パターン追加 | 管理者 |
| update_pattern | パターン更新 | 管理者 |
| delete_pattern | パターン削除 | 管理者 |

### extract リクエスト例

```json
{
    "action": "extract",
    "message": "明日の資料準備しておいてね。あと沖縄行きたいなー",
    "message_id": 12345,
    "auto_save": true
}
```

### extract レスポンス例

```json
{
    "success": true,
    "data": {
        "extracted": [
            {
                "wish": "明日の資料準備",
                "category": "request",
                "category_label": "依頼",
                "original_text": "明日の資料準備しておいてね",
                "pattern_id": 1,
                "confidence": 0.80
            },
            {
                "wish": "沖縄",
                "category": "travel",
                "category_label": "旅行",
                "original_text": "沖縄行きたいなー",
                "pattern_id": 5,
                "confidence": 0.80
            }
        ],
        "count": 2
    }
}
```

---

## UI仕様

### Wishリスト画面（tasks.php）

- AI抽出されたWishには「✨ AI抽出」バッジを表示
- カテゴリバッジを表示
- 抽出元の原文を引用表示（斜体・ボーダー付き）
- 編集モーダルで抽出元情報を確認可能

### 管理画面（admin/wish_patterns.php）

- パターン一覧表示（優先度、カテゴリ、正規表現、例文、状態）
- パターン追加・編集・削除
- リアルタイムパターンテスト機能
- 管理者/サポート権限のみアクセス可能

---

## 将来拡張（Phase 2）

### AI API連携

- OpenAI API / Claude API による高精度抽出
- 文脈理解によるより自然な要約
- wish_training_examples テーブルを使った学習

### ハイブリッド方式

```
メッセージ → ルールベース候補抽出 → AI精査・整形 → 保存
```

- ルールベースで候補を絞り込み（コスト削減）
- AIで最終判断と自然な表現に整形

---

## ファイル構成

```
nine/
├── tasks.php                    # Wish・メモ管理画面
├── api/
│   ├── tasks.php                # Wish CRUD API
│   ├── wish_extractor.php       # Wish抽出API
│   └── messages.php             # メッセージAPI（抽出処理統合）
├── admin/
│   └── wish_patterns.php        # パターン管理画面
└── database/
    └── schema_wish.sql          # Wishテーブル定義
```

---

*作成日: 2024-12-24*
*バージョン: 1.0*








