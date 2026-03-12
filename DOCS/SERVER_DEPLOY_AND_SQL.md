# 本番 DB への SQL 実行手順

**実装ファイル（PHP/CSS/JS）を EC2 に直接アップロードすることはありません。** 反映は **コミット → main マージ → push** により GitHub Actions で自動で本番にデプロイされます。このドキュメントは **SQL マイグレーション** を本番 DB に実行する手順です。

- **デプロイの仕組み**: [CI_CD_SETUP.md](./CI_CD_SETUP.md)

---

## 1. 本番 DB 接続情報（RDS）

| 項目 | 値 |
|------|-----|
| ホスト | `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com` |
| ポート | 3306 |
| データベース名 | `social9` |
| ユーザー名 | `admin` |
| パスワード | EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS` |

---

## 2. SQL の実行手順

マイグレーションやテーブル追加・変更用の SQL がある場合の手順。

### 2.1 SQL ファイルを EC2 に置く

- main にマージされていれば、`database/*.sql` はすでに EC2 の `/var/www/html/database/` に存在します。
- まだマージしていない場合は、PR をマージするか、EC2 に SSH してファイルを置く（例: `scp` で `/home/ec2-user/` に送る）。

### 2.2 EC2 上で MySQL に流し込む

EC2 に SSH したうえで、次のいずれかで実行する。

**コマンド例（パスワードはプロンプトで入力）:**

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /var/www/html/database/migration_xxx.sql
```

パスワードは `db-env.conf` の `DB_PASS`。

- **詳細**: 本番 DB への接続・実行方法の詳細は [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md) を参照。

### 2.3 管理ダッシュボード「本日のアクセス」を有効にする

「本日のアクセス数」を表示するには、本番 DB で次の SQL を **1 回** 実行する。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /var/www/html/database/migration_access_log.sql
```

- 実行後、index.php・chat.php・管理画面へのアクセスが自動で記録され、管理パネルの「本日のアクセス」に反映される。
- 詳細: `includes/access_logger.php`、`admin/index.php`（テーブル未作成時は画面上で案内表示）。

### 2.4 プライベートグループ・組織アドレス帳で使うマイグレーション

次の SQL は [PRIVATE_GROUP_AND_ADDRESS_BOOK_IMPLEMENTATION_LOG.md](./PRIVATE_GROUP_AND_ADDRESS_BOOK_IMPLEMENTATION_LOG.md) の Phase 1.3 で手動適用する想定です。

| ファイル | 説明 |
|----------|------|
| `migration_private_group_settings.sql` | conversations にプライベートグループ用カラム追加（必須） |
| `migration_org_invite_candidates.sql` | 組織招待候補テーブル（一斉招待を使う場合のみ） |

---

## 3. エージェントが SQL ありの場合にやること

- SQL マイグレーションが必要な場合: 上記手順と [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md) をユーザーに案内する。
- 「PR を main にマージするとファイルは自動で本番に反映されます。DB 変更は CI に含まれていないため、EC2 に SSH のうえで上記の `mysql ... < ...sql` を実行してください」と伝える。

---

## 4. 関連ドキュメント

- [CI_CD_SETUP.md](./CI_CD_SETUP.md) — 自動デプロイの仕組み
- [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md) — 本番 DB 接続の詳細
- `.cursor/rules/deploy-execute-on-complete.mdc` — デプロイルール
