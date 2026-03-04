# ブラウザテストガイド

このガイドは、AIがMCPブラウザを使ってSocial9をテストする際の手順をまとめています。

## テスト用API

### ステータス確認
```
GET /api/test-helper.php?action=status
```
現在のログイン状態、セッション情報を取得

### エラーログ確認
```
GET /api/test-helper.php?action=errors&limit=20
```
最近のJavaScriptエラーを取得

### ヘルスチェック
```
GET /api/health.php
```
システムの健全性を確認

## テストシナリオ

### 1. ログインテスト

```
1. index.php を開く
2. input[name="username"] にユーザー名を入力
3. input[name="password"] にパスワードを入力
4. button[type="submit"] をクリック
5. chat.php にリダイレクトされることを確認
```

### 2. メッセージ送信テスト

```
1. chat.php を開く（ログイン済み）
2. .conversation-item をクリックして会話を選択
3. #messageInput にテキストを入力
4. .send-btn をクリックまたはEnterキー
5. .message-card に新しいメッセージが表示されることを確認
```

### 3. モバイルレスポンシブテスト

```
1. ブラウザサイズを 375x667 に設定
2. chat.php を開く
3. レイアウトが崩れていないか確認
4. ハンバーガーメニューが表示されているか確認
5. サイドバーの開閉が動作するか確認
```

### 4. デザイン変更テスト

```
1. design.php を開く
2. .theme-card をクリックしてテーマを選択
3. 「保存」ボタンをクリック
4. chat.php に戻ってデザインが反映されているか確認
```

## 主要なセレクタ

### チャット画面 (chat.php)

| セレクタ | 説明 |
|---------|------|
| `.sidebar` | 左サイドバー |
| `.center-panel` | 中央パネル |
| `.right-panel` | 右パネル |
| `#messageInput` | メッセージ入力欄 |
| `.send-btn` | 送信ボタン |
| `.conversation-item` | 会話リストアイテム |
| `.message-card` | メッセージカード |
| `.topbar` | トップバー |
| `.to-button` | TO（宛先指定）ボタン |
| `.emoji-btn` | 絵文字ボタン |
| `.gif-btn` | GIFボタン |

### ログイン画面 (index.php)

| セレクタ | 説明 |
|---------|------|
| `input[name="username"]` | ユーザー名入力 |
| `input[name="password"]` | パスワード入力 |
| `button[type="submit"]` | ログインボタン |
| `.register-link` | 新規登録リンク |

## エラーチェックポイント

テスト後は以下を確認：

1. **コンソールエラー**: `/api/test-helper.php?action=errors` で確認
2. **ヘルスチェック**: `/api/health.php` で確認
3. **UIの崩れ**: スクリーンショットで確認

## デバッグモード

URLに `?debug=1` を追加するとデバッグモードが有効になり、
詳細なログがコンソールに出力されます。

例: `https://social9.jp/chat.php?debug=1`
