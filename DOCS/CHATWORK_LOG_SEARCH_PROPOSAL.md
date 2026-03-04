# チャットワーク時代のログ検索 アイデア提案書

チャットワークからログを引き継いだ後、Social9に社員を招待して使い始めつつ、チャットワーク時代のログも遡って検索できるようにするための実装案です。

---

## 前提・課題

- **チャットワークのルーム** → Social9の **conversations（グループ）** に対応させる
- **チャットワークのアカウント** → Social9の **users** に対応させる（メールアドレス等でマッピング）
- **チャットワークのメッセージ** → 検索対象に含める必要がある

---

## 提案1: messages テーブルに直接インポート（推奨）

### 概要
チャットワークのログを既存の `messages` テーブルにインポートし、`source` カラムで出所を区別する。

### 実装イメージ

1. **DBスキーマ変更**
   ```sql
   ALTER TABLE messages ADD COLUMN source ENUM('social9','chatwork') DEFAULT 'social9' AFTER content;
   ALTER TABLE messages ADD COLUMN external_id VARCHAR(100) DEFAULT NULL COMMENT 'ChatworkメッセージID等' AFTER source;
   ```

2. **インポート処理**
   - チャットワークのルーム → 既存の conversations にマッピング（同一グループ名 or 手動マッピング）
   - チャットワークのアカウント → users テーブルで email 等で紐付け（未登録者は「インポート用ユーザー」を仮作成）
   - メッセージを messages に INSERT（`source='chatwork'`, `external_id` に元ID）

3. **検索**
   - 既存の `api/messages.php?action=search` は `messages` を検索しているため、**追加実装なしでチャットワークログも検索対象になる**

4. **UI**
   - 検索結果やメッセージ表示時に `source='chatwork'` のものに「チャットワーク時代」バッジを表示

### メリット
- 既存の検索APIをそのまま利用できる
- 実装コストが小さい
- チャット履歴と過去ログを同じ画面で一括検索できる

### デメリット
- ユーザーマッピング（チャットワーク→Social9）の準備が必要
- インポートユーザーが多くなると users が増える

---

## 提案2: アーカイブ専用テーブルで分離

### 概要
チャットワークログを専用テーブル `message_archive` に保存し、検索時に messages と UNION する。

### 実装イメージ

1. **新規テーブル**
   ```sql
   CREATE TABLE message_archive (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       conversation_id INT UNSIGNED NOT NULL,
       sender_id INT UNSIGNED,
       sender_name VARCHAR(100) COMMENT 'マッピングできない場合の表示名',
       content TEXT,
       source ENUM('chatwork') DEFAULT 'chatwork',
       external_id VARCHAR(100),
       created_at DATETIME,
       INDEX idx_conversation (conversation_id),
       INDEX idx_created (created_at),
       FULLTEXT INDEX ft_content (content)
   );
   ```

2. **検索API拡張**
   - `api/messages.php` の search で、messages と message_archive を UNION して検索
   - conversation_members で参加している会話に限り、その会話のアーカイブも検索対象に含める

3. **表示**
   - 検索結果に「チャットワーク時代」バッジを表示
   - クリックしたら該当会話を開き、該当メッセージへスクロール（アーカイブ用の「過去ログ」タブ表示など）

### メリット
- 本番の messages と分離できる
- インポート失敗や再実行の影響を抑えやすい
- sender_id が null でも sender_name で表示できる

### デメリット
- 検索クエリが複雑になる
- 会話画面での「過去ログ」表示用の仕組みが必要

---

## 提案3: アーカイブ専用の検索画面

### 概要
チャットワークログを「アーカイブ検索」専用画面で検索する。

### 実装イメージ

1. **データ保存**
   - 提案2と同様に `message_archive` テーブルを使用

2. **専用UI**
   - 検索モーダルまたは画面上に「メッセージ検索」と「アーカイブ検索（チャットワーク時代）」のタブを用意
   - アーカイブ検索は期間指定（例: 2025年以前）や「チャットワーク時代」フィルタをデフォルト

3. **表示**
   - アーカイブ検索結果は、会話名・送信者・本文・日時を表示し、クリックで会話画面を開いて該当箇所を表示

### メリット
- 通常のチャット検索とアーカイブ検索を用途で分けられる
- アーカイブ専用のUI設計がしやすい

### デメリット
- ユーザーが2つの検索を意識して使い分ける必要がある
- 「一つの検索で全部出る」という体験にはならない

---

## 提案4: 外部検索エンジン（Elasticsearch / Meilisearch）

### 概要
Elasticsearch や Meilisearch を導入し、メッセージ＋チャットワークログをまとめてインデックスする。

### 実装イメージ

1. **インフラ**
   - Meilisearch または Elasticsearch をサーバーに構築

2. **インデックス**
   - messages と message_archive の内容を検索エンジンに登録
   - メッセージ作成・編集時にインデックスを更新（webhook や キュー）

3. **検索API**
   - 検索リクエストを検索エンジンに投げ、results を返す

### メリット
- 全文検索が高速
- ハイライト、ファセット検索、日付範囲などが使いやすい

### デメリット
- 追加インフラと運用コスト
- データ同期の仕組みが必要

---

## 推奨: 提案1（messages に直接インポート）

### 理由

1. **既存の検索がそのまま使える**  
   Social9 の検索はすでに `messages` を検索しているため、チャットワークログを messages に入れれば追加実装なしで検索対象になる。

2. **インポート作業の流れが明確**
   - チャットワークのルームを conversations にマッピング
   - アカウントを users にマッピング（メールで紐付け or 仮ユーザー作成）
   - メッセージを messages に INSERT（`source='chatwork'`）

3. **段階的に拡張できる**
   - まずは `source` カラム追加とインポートスクリプトのみ実装
   - 必要に応じて検索結果への「チャットワーク時代」バッジ表示を追加
   - 将来的に外部検索エンジンへ移行することも可能

### 次のステップ

1. `messages` に `source`, `external_id` カラムを追加するマイグレーションを用意
2. チャットワークのエクスポート形式（CSV/JSON 等）を確認
3. インポートスクリプト（admin 用）の作成
4. ユーザー・ルームのマッピング方法の設計（手動マッピング or メールベース自動マッピング）
5. 検索結果・メッセージ表示に「チャットワーク時代」バッジを表示する UI の追加

---

## チャットワークのエクスポートについて

チャットワークの公式 API やエクスポート機能で取得できる形式を確認し、それに合わせてインポートスクリプトを設計する必要があります。取得できる主な項目は次のようなものです：

- ルームID、ルーム名
- メッセージID、本文、送信日時
- アカウントID、メールアドレス、表示名

これらの形式が分かれば、具体的なインポート処理の設計が可能です。
