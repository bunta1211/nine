# サーバー送信ファイル一覧：スワイプ3ページ＋縁の光のすき間

本計画（PLAN_EDGE_GLOW_AND_THREE_PAGES）で変更・追加したファイルです。サーバーにアップロードする際は以下のパスを基準に配置してください。

---

## 必須（アップロードするファイル）

| パス | 内容 |
|------|------|
| **chat.php** | メインコンテナ内にエッジグロー div、mobile-pages-strip ラッパーを追加 |
| **includes/chat/sidebar.php** | 左ヘッダーに settings-account-bar include（Phase 4） |
| **includes/chat/rightpanel.php** | 右ヘッダーに settings-account-bar include（Phase 4） |
| **includes/chat/settings-account-bar.php** | 新規。設定＋アカウントドロップダウン共通パーツ |
| **includes/chat/scripts.php** | toggleUserMenu / closeUserMenu の複数ドロップダウン対応 |
| **assets/css/chat-main.css** | .left-header-actions, .panel-account-bar のレイアウト（Phase 4） |
| **assets/css/chat-mobile.css** | Phase 1〜5 の携帯用スタイル（ボタン非表示、エッジグロー、3ページストリップ、panel-account-bar、z-index コメント） |
| **assets/js/chat-mobile.js** | Phase 3.1/3.2（ストリップ初期スクロール・エッジグロー同期・端スワイプ）、Phase 3.4（toggle/close をストリップスクロールに統合） |

---

## ドキュメント（任意・推奨）

| パス | 内容 |
|------|------|
| **DOCS/PLAN_EDGE_GLOW_PROGRESS.md** | 実装進捗メモ（Phase 1〜5 および 3.3・3.4 の記録） |
| **DOCS/PLAN_EDGE_GLOW_AND_THREE_PAGES.md** | 計画書（参照用・変更なしの場合は送らなくてよい） |
| **DOCS/DEPLOY_FILES_EDGE_GLOW_THREE_PAGES.md** | 本ファイル（送信リスト） |
| **assets/css/DEPENDENCIES.md** | Phase 3 ストリップ・chat-mobile の追記 |
| **assets/js/DEPENDENCIES.md** | Phase 3.1/3.2 の追記 |
| **includes/chat/DEPENDENCIES.md** | settings-account-bar.php の追記 |

---

## 送信時の注意

1. **includes/chat/scripts.php** は非常に長いファイルのため、該当関数（`toggleUserMenu`, `closeUserMenu`）周辺のみ変更している場合は、差分でマージするか、ファイル全体をバックアップしてから上書きしてください。
2. **chat.php** は .main-container 内の HTML 構造を変更しています。既存の修正と衝突しないか確認してください。
3. アップロード後、携帯表示（幅 768px 以下）で以下を確認してください。
   - 左右の収納ボタンが非表示
   - 横スワイプで左・中央・右の3ページが切り替わる
   - 画面左右の縁に「光のすき間」が表示され、現在ページで出し分けされる
   - 左・右パネルヘッダーに設定リンクとアカウント（▼）が表示され、ドロップダウンが開く
