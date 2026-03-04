# チーム開発環境の整備ガイド

Social9 をチームで開発するときの、リポジトリ・環境・運用の推奨方法です。

---

## 1. 推奨する構成の全体像

| 項目 | 推奨 |
|------|------|
| バージョン管理 | Git（すでに利用中） |
| リモートリポジトリ | GitHub または GitLab（プライベート可） |
| ブランチ戦略 | main（本番） + develop（開発） + feature/xxx（機能ごと） |
| 環境 | ローカル / ステージング（任意） / 本番（EC2+RDS） |
| 設定の管理 | *local.php や .env は Git に入れず、*.example を共有 |
| コードレビュー | プルリクエスト（PR）またはマージリクエスト（MR） |
| ドキュメント | DOCS/、DEPENDENCIES.md、ARCHITECTURE.md を更新し続ける |

---

## 2. リモートリポジトリの用意

### GitHub の場合

1. GitHub で新規リポジトリ作成（Private 推奨）
2. ローカルでリモートを追加して初回プッシュ（既に origin がある場合は URL を差し替え）

```bash
git remote add origin https://github.com/あなたのorg/social9.git
# または既存の origin を変更する場合:
# git remote set-url origin https://github.com/あなたのorg/social9.git
git push -u origin main
```

### GitLab / Bitbucket の場合

- 同様にリポジトリを作成し、`git remote add origin <URL>` で追加してプッシュ

### チームメンバーの追加

- GitHub: リポジトリの Settings → Collaborators で招待
- メンバーは `git clone` で取得し、各自の PC で開発

---

## 3. ブランチ戦略（シンプル版）

```
main     … 本番反映済み（常にデプロイ可能な状態）
develop  … 開発の集約（任意。main のみでも可）
feature/xxx  … 機能ごとの作業用
```

### 運用例

- **main のみで運用する場合**  
  - 機能開発: `git checkout -b feature/スプレッドシート改善` で作業  
  - 完了後: main にマージしてから本番デプロイ  

- **develop を挟む場合**  
  - feature → develop にマージして結合テスト  
  - 問題なければ develop → main にマージして本番デプロイ  

### よく使うコマンド

```bash
# 最新の main を取得
git checkout main
git pull origin main

# 機能ブランチを作成して作業
git checkout -b feature/〇〇
# 編集...
git add .
git commit -m "〇〇を追加"
git push origin feature/〇〇
```

その後、GitHub/GitLab 上で **Pull Request（PR）** を作成し、メンバーにレビューしてもらってから main にマージする流れがおすすめです。

---

## 4. 環境の分離（ローカル / 本番）

### すでにやっていること（推奨のまま）

- **機密設定は Git に含めない**  
  `.gitignore` に以下が含まれています。  
  `config/database.aws.php`, `config/app.local.php`, `config/ai_config.local.php`,  
  `config/google_calendar.local.php`, `config/google_login.local.php`, `config/push.local.php`, `*.pem`, `.env` など

- **サンプルだけリポジトリで共有**  
  `config/*.example.php` や `config/app.local.example.php` をコミットし、新メンバーはコピーして `*.local.php` を作成

### 新メンバーが最初にやること

1. リポジトリをクローン
2. `config/` 内の `*.example` をコピーして `*.local.php` などを作成
3. データベース接続（ローカル用）を設定
4. `composer install` で依存関係をインストール
5. 必要なら `database/schema.sql` やマイグレーションで DB を構築

本番の `database.aws.php` や API キーは **リポジトリには上げず**、別手段（共有ストレージ・1Password 等）で安全に共有します。

---

## 5. データベースの変更（チームで行う場合）

- **スキーマ変更は SQL ファイルで管理**  
  `database/migration_xxx.sql` のように番号や日付で命名し、リポジトリにコミットする
- **本番適用手順を DOCS に書く**  
  例: `DOCS/AI_SECRETARY_IMPROVEMENTS_LOG.md` のように「どの SQL をいつ実行するか」を記録
- 本番では、**該当マイグレーションを確認してから** RDS 等で実行する

---

## 6. デプロイの流れ（現状に近い形）

1. main（または develop）の最新を取得
2. 本番サーバー（EC2）に必要なファイルをアップロード  
   - FTP/SCP や WinSCP、または `git pull`（サーバーで Git を使う場合）
3. 必要に応じて RDS でマイグレーション SQL を実行
4. 動作確認

将来的に、GitHub Actions などで「main にマージしたら自動で EC2 にデプロイ」も検討できます。

---

## 7. コードレビューとドキュメント

- **PR を出すとき**  
  - 変更内容の要約  
  - 関連する DOCS / DEPENDENCIES.md の更新有無  
  を PR 説明に書くと、レビューしやすくなります。
- **依存関係の記録**  
  機能追加・API 追加時は、`.cursor/rules/update-dependencies-docs.mdc` のとおり、該当する `DEPENDENCIES.md` を更新してください。
- **仕様・設計**  
  `DOCS/spec/` や `ARCHITECTURE.md` を、大きな変更のたびに少しずつ更新すると、チーム全体で認識が揃います。

---

## 8. チェックリスト（環境整備が終わったか確認）

- [ ] リモートリポジトリ（GitHub/GitLab）を作成し、main をプッシュした
- [ ] チームメンバーを Collaborators 等で招待した
- [ ] `.gitignore` で機密ファイルが除外されていることを確認した
- [ ] `config/*.example` がリポジトリに含まれており、新メンバー用の手順を DOCS に書いた（または既存の README に追記した）
- [ ] ブランチ戦略（main + feature または main + develop + feature）を決めた
- [ ] PR を作成してから main にマージする運用にした（任意だが推奨）
- [ ] 本番デプロイ手順と、DB マイグレーションの実行手順を DOCS にまとめた

---

## 参照

- コーディング規約・API 開発: `DOCS/DEVELOPER_GUIDE.md`
- 依存関係の更新ルール: `.cursor/rules/update-dependencies-docs.mdc`
- アーキテクチャ: `ARCHITECTURE.md`
- 本番デプロイ・AWS: `DOCS/AWS_EC2_SETUP_GUIDE.md` など
