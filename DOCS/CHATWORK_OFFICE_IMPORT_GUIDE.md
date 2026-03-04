# 事務局（事務局 Office）ログ継承ガイド

Chatworkの「事務局 Office」ルームのログを Social9 の「事務局」グループにインポートする手順です。

---

## 100件以上インポートしたいとき

| 方法 | 対象 | 手順 |
|------|------|------|
| **方法B: CSV** | エンタープライズプラン | Chatwork管理画面で全件CSVエクスポート → `import_chatwork_csv.php` でインポート |
| **方法C: 定期実行** | 今後の分 | 週1回などでAPIインポートを実行し、増分を蓄積 |

APIは仕様上100件までなので、それより古い履歴は **CSVエクスポート** が必要です。

---

## 前提条件

- [ ] Social9 に「事務局」グループが既に存在している（会話ID: 130 など）
- [ ] 事務局のメンバーが Social9 の users に登録済みである
- [ ] Chatwork の API トークンが取得できる（または CSV/HTML エクスポートが可能）

---

## 方法A: Chatwork API を使ってインポート（推奨）

### 1. Chatwork API トークンを取得

1. Chatwork にログイン
2. 右上のプロフィールアイコン → **サービス連携** → **APIトークン**
3. トークンを発行し、安全な場所に控える  
   ※パーソナルプラン以外は組織管理者の承認が必要な場合があります

### 2. 事務局ルームのルームIDを確認

1. Chatwork で「事務局 Office」を開く
2. ブラウザのURLを確認  
   例: `https://www.chatwork.com/#!rid123456789`  
   → `rid` の後の数字（例: `123456789`）がルームIDです

### 3. Social9 の会話IDを確認

1. Social9 で「事務局」グループを開く
2. URLの `c=` の値を確認  
   例: `chat.php?c=130` → 会話IDは **130**

### 4. インポートスクリプトを実行

`admin/import_chatwork_messages.php` を使用します。

**（任意）マイグレーション実行**  
重複インポート防止のため、事前に `database/migration_chatwork_import.sql` を実行しておくとよいです。未実行でも初回インポートは可能です。

**CLI で実行:**
```bash
php admin/import_chatwork_messages.php --token=YOUR_CHATWORK_API_TOKEN --room_id=123456789 --conversation_id=130
```

**Web で実行（組織管理者でログイン後）:**  
`https://your-domain/admin/import_chatwork_messages.php` にアクセスし、フォームに APIトークン・ルームID・会話ID を入力して実行します。

### 5. Chatwork API の仕様（参考）

- **エンドポイント**: `GET https://api.chatwork.com/v2/rooms/{room_id}/messages`
- **取得件数**: 1リクエストあたり**最大100件**（API仕様上、それ以上は取得不可）
- **force パラメータ**: `force=1` で最新100件を取得。`force=0` は未取得分のみで空になる場合あり
- **制限**: 5分間に300リクエストまで
- **フリープラン**: 直近40日以内・最新5,000件のみ閲覧可能。有料プランでより多くの履歴にアクセス可能

---

## 方法B: CSV エクスポートからインポート（100件以上に有効）

**エンタープライズプラン**で管理者権限がある場合、ログを全件CSVエクスポートできます。

### 1. Chatwork でCSVエクスポート

1. Chatwork にログイン（管理者権限のアカウント）
2. 画面左上のユーザー名 → **管理者設定** → **エクスポート**
3. 出力フォーマットで **CSV** を選択
4. 「チャットログ・エクスポート」をクリック
5. 完了通知メールのURLからダウンロード、または管理画面の「ダウンロード」ボタンで取得

※エクスポートは利用開始時から現在までの全期間が対象です。

### 2. Social9 へインポート

`admin/import_chatwork_csv.php` を使用します。

**CLI:**
```bash
php admin/import_chatwork_csv.php --file=chatwork_export.csv --conversation_id=130 --room_filter=事務局
```

**Web:**  
`/admin/import_chatwork_csv.php` にアクセスし、CSVをアップロード。会話IDとルーム名フィルタを入力して実行。

### 3. CSV形式について

スクリプトは以下のカラム名を自動検出します（いずれかがあれば可）:

| 内容 | 対応カラム名 |
|------|-------------|
| ルーム名 | room_name, room_id, ルーム名 |
| 送信者 | sender_name, account_name, name, 送信者 |
| 本文 | body, content, message, 本文 |
| 日時 | send_time, created_at, timestamp, 日時 |
| メッセージID | message_id, id |

Chatwork公式エクスポートのCSV形式が異なる場合は、ヘッダー行を上記のいずれかに合わせて編集してください。

---

## 方法C: 定期インポート（今後分の蓄積）

APIは100件までですが、**定期的に実行**することで、今後増えるメッセージを逃さず取り込めます。

- 例: 週1回、cron で `import_chatwork_messages.php` を実行
- マイグレーション（external_id）を実行済みなら、重複はスキップされます

---

## ユーザーマッピング（重要）

Chatwork のアカウントと Social9 のユーザーを対応させる必要があります。

| Chatwork | Social9 |
|----------|---------|
| アカウントID / 表示名 | users.id |
| Ken | Ken (display_name で検索) |
| Naomi | Naomi |
| Mari | Mari |
| ... | ... |

**手段:**
- `display_name` が一致するユーザーを自動マッピング
- 一致しない場合は、仮の「インポート用ユーザー」に紐付けるか、マッピングCSVを用意

---

## インポート後の確認

1. Social9 の「事務局」グループを開く
2. チャット履歴をスクロールして、古いメッセージが表示されるか確認
3. 検索機能でキーワード検索して、継承したログがヒットするか確認

---

## トラブルシューティング

| 問題 | 対処 |
|------|------|
| API で取得できるメッセージが少ない | フリープランは直近40日・最新5,000件まで。有料プランでより多くの履歴にアクセス可能 |
| ユーザーがマッピングできない | マッピングCSVを用意するか、仮ユーザーに紐付ける |
| 重複インポート | スクリプトにより `external_id` で重複チェックを行い、既存はスキップ |
