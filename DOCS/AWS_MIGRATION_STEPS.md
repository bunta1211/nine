# AWS移転 起動手順

本文書は [AWS移転計画書](./AWS_MIGRATION_PLAN.md) に基づく、具体的な起動手順です。

---

## Phase 0: 準備（今すぐ実施可能）

### 1. AWSアカウント作成
- **[AWS はじめての契約・進め方ガイド](./AWS_SIGNUP_GUIDE.md)** を参照（未契約者の方はこちら）
- [AWS アカウント作成](https://aws.amazon.com/jp/register/)
- ルートユーザーにMFAを設定
- 請求アラートを設定（予算超過防止）

### 2. 費用アラートの設定
```text
AWSマネジメントコンソール → 請求 → 予算 → 予算の作成
例: 月額10,000円の予算を設定し、80%でアラート
```

### 3. 現行環境のバックアップ
- [ ] phpMyAdmin で MySQL ダンプ取得
- [ ] uploads/ フォルダをZIPでバックアップ
- [ ] 設定ファイル（config/）のバックアップ

---

## Phase 1: 検証環境構築（AWS上で実施）

### 1. EC2インスタンス起動
```text
リージョン: ap-northeast-1（東京）
AMI: Amazon Linux 2023
インスタンスタイプ: t3.micro（無料枠対象）
ストレージ: 8GB（gp3）
```

### 2. セキュリティグループ
| タイプ | ポート | ソース |
|--------|--------|--------|
| SSH | 22 | 自分のIP |
| HTTP | 80 | 0.0.0.0/0 |
| HTTPS | 443 | 0.0.0.0/0 |

### 3. EC2にPHP環境を構築
```bash
# SSHでEC2に接続後
# ※ Amazon Linux 2023 では php8.2-curl が存在しないため省く（php-curl で別途追加可能）
sudo dnf install -y php8.2 php8.2-mysqlnd php8.2-mbstring php8.2-xml php8.2-gd php8.2-zip
sudo dnf install -y httpd
sudo systemctl enable httpd
sudo systemctl start httpd
# curl 拡張が必要な場合: sudo dnf install -y php-curl
```

### 4. RDS MySQL 作成
```text
エンジン: MySQL 8.0
インスタンス: db.t3.micro（無料枠対象）
ストレージ: 20GB
パブリックアクセス: 初期は「あり」（検証用）
```

### 5. 環境変数の設定（EC2）
`/etc/environment` または `.bashrc` に追加:
```bash
export DB_HOST=your-rds-endpoint.ap-northeast-1.rds.amazonaws.com
export DB_NAME=social9
export DB_USER=admin
export DB_PASS=your-secure-password
export APP_ENV=production
export APP_URL=https://your-domain.com
```

### 6. アプリケーションのデプロイ
```bash
# Git または SCP でソースを配置
# /var/www/html/ に配置
sudo chown -R apache:apache /var/www/html/
```

### 7. データインポート
```bash
mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < backup.sql
```

---

## アプリ側の準備（実施済み）

| 項目 | 状態 |
|------|------|
| database.php の環境変数対応 | ✅ DB_HOST, DB_NAME, DB_USER, DB_PASS を読み取り |
| config/app.aws.example.php | ✅ 作成済み |

---

## 次のアクション

1. **AWSアカウント** を作成
2. **Phase 0** のバックアップを取得
3. **Phase 1** のEC2・RDSを構築
4. 動作確認後、本番ドメイン（Route 53）を設定

---

*詳細は [AWS移転計画書](./AWS_MIGRATION_PLAN.md) を参照*
