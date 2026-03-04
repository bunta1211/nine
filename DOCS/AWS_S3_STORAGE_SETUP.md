# AWS S3 共有フォルダセットアップ手順

Social9 共有フォルダで使用する S3 バケットと IAM ユーザーのセットアップ手順。

## 前提

- AWS アカウントが作成済みであること
- リージョン: `ap-northeast-1`（東京）

---

## 1. S3 バケットの作成

1. AWS コンソール → **S3** → **「バケットを作成」**
2. 設定:
   - **バケット名**: `social9-storage`
   - **リージョン**: アジアパシフィック（東京）ap-northeast-1
   - **バケットタイプ**: 汎用
   - **オブジェクト所有者**: ACL 無効（推奨）
   - **パブリックアクセスをすべてブロック**: チェックあり（そのまま）
   - **バージョニング**: 無効
   - **暗号化**: Amazon S3 マネージドキー（SSE-S3）
3. **「バケットを作成」** をクリック

## 2. S3 バケットの CORS 設定

1. 作成したバケット → **「アクセス許可」** タブ
2. 下にスクロール → **「Cross-Origin Resource Sharing (CORS)」** → **「編集」**
3. 以下の JSON を貼り付けて **「変更の保存」**:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "POST", "HEAD"],
        "AllowedOrigins": ["https://social9.jp"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

> ローカル開発環境からもテストする場合は `AllowedOrigins` に `"http://localhost"` を追加する。

## 3. IAM ユーザーの作成

1. AWS コンソール → **IAM** → **ユーザー** → **「ユーザーの作成」**
2. **ユーザー名**: `social9-storage-user`
3. **「AWS マネジメントコンソールへのユーザーアクセスを提供する」**: チェックなし
4. **「次へ」**

## 4. IAM 権限の設定

### 方法 A: 既存ポリシーをアタッチ（簡易）

1. **「ポリシーを直接アタッチする」** を選択
2. `AmazonS3FullAccess` を検索してチェック
3. **「次へ」** → **「ユーザーの作成」**

### 方法 B: インラインポリシー（推奨・バケット限定）

`AmazonS3FullAccess` が見つからない場合、またはバケットを限定したい場合:

1. ユーザー作成後、ユーザー詳細ページ → **「許可」** タブ
2. **「インラインポリシーを作成」** をクリック
3. **「JSON」** タブを選択し、以下を貼り付け:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "s3:*",
            "Resource": [
                "arn:aws:s3:::social9-storage",
                "arn:aws:s3:::social9-storage/*"
            ]
        }
    ]
}
```

4. **「次へ」** → ポリシー名: `Social9StorageAccess` → **「ポリシーの作成」**

## 5. アクセスキーの作成

1. ユーザー詳細ページ → **「セキュリティ認証情報」** タブ
2. **「アクセスキーを作成」** をクリック
3. **「主要なベストプラクティスと代替案にアクセスする」** を選択 → **「次へ」**
4. 説明タグ（任意） → **「アクセスキーを作成」**
5. **アクセスキー** と **シークレットアクセスキー** を記録
   - この画面を閉じるとシークレットキーは再表示不可
   - `.csv ファイルをダウンロード` しておくと安全

## 6. EC2 サーバーへの設定反映

EC2 にSSHで接続し、以下のファイルを作成:

```bash
ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

```bash
cat > /var/www/html/config/storage.local.php << 'EOF'
<?php
define('STORAGE_S3_BUCKET',  'social9-storage');
define('STORAGE_S3_KEY',     'アクセスキーをここに');
define('STORAGE_S3_SECRET',  'シークレットキーをここに');
EOF
```

> `storage.local.php` は `.gitignore` に含めてリポジトリにコミットしないこと。

## 7. 確認

1. チャットページで共有フォルダを開く
2. ファイルをドラッグ＆ドロップしてアップロードが成功することを確認
3. ブラウザのコンソールに CORS エラーが出ないことを確認

---

## トラブルシューティング

| 症状 | 原因 | 対処 |
|------|------|------|
| CORS policy エラー | S3 バケットの CORS 未設定 | 手順2 の CORS JSON を設定 |
| 403 Forbidden | IAM 権限不足 | `AmazonS3FullAccess` またはインラインポリシーを確認 |
| 500 Internal Server Error (api/storage.php) | AWS SDK 未インストール or autoload 未読込 | EC2 で `composer require aws/aws-sdk-php:^3.0` を実行。`includes/storage_s3_helper.php` に `require_once __DIR__ . '/../vendor/autoload.php';` があるか確認 |
| アクセスキーが動作しない | キーが無効/削除済み | IAM コンソールで新しいアクセスキーを作成 |

## 現在の設定値（2026-02-27時点）

- **バケット名**: `social9-storage`
- **リージョン**: `ap-northeast-1`
- **IAM ユーザー**: `social9-storage-user`
- **設定ファイル**: EC2 上の `/var/www/html/config/storage.local.php`
