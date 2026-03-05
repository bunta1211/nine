# 開発者ガイドライン

## 概要

このドキュメントは、Social9の新規機能開発・既存機能改修時に従うべきガイドラインです。

---

## 前提（デプロイ・ブランチ）

- **main ブランチにマージすると、GitHub Actions で自動的に EC2（social9.jp）にデプロイされます。**
- 手動デプロイ（scp / WinSCP）は不要です。
- **main に直接 push せず、ブランチから PR 経由でマージしてください。**
- 作業ブランチは **bunta/01** です。

---

## 事前準備

- **Docker Desktop** をインストールし、起動した状態にしてください（未導入の場合: https://www.docker.com/products/docker-desktop/ ）
- コマンドは **PowerShell** または **Git Bash** で実行してください。

---

## 開発開始時の手順（毎回）

1. **main を最新に pull し、bunta/01 に切り替える**
   - `git checkout main` → `git pull origin main`
   - `git checkout bunta/01`（まだなければ `git checkout -b bunta/01` で作成）

2. **Docker 環境を起動する**
   - `docker compose up -d`
   - 初回はイメージのビルドで数分かかることがあります。

3. **初回のみ、コンテナ内で composer install を実行する**
   - `docker compose exec web composer install --no-interaction`

4. **ローカル DB にデータが必要な場合**
   - **EC2 に SSH できる場合**: 本番 DB からダンプを取得し、ローカルに取り込む。手順は [PRODUCTION_TO_LOCAL_DB.md](./PRODUCTION_TO_LOCAL_DB.md) を参照。
   - **EC2 にアクセスできない場合**: 空の DB のまま新規登録で動作確認するか、データのエクスポートを依頼してください。
   - **初回のみ（テーブルがない場合）**: Docker の DB には `docker/init-db` で文字セットのみ設定され、テーブルは作成されません。初回は次でスキーマを流してください。
     ```powershell
     docker compose exec -T db mysql -u root -psocial9_dev social9 < database/schema_complete.sql
     ```

5. **動作確認**
   - ブラウザで **http://localhost:9000/** を開いて確認してください。
   - Web: ポート **9000**、MySQL: ポート **13306**。

詳細な Docker の使い方は [DOCKER_LOCAL.md](./DOCKER_LOCAL.md) を参照してください。

---

## 開発フロー

- ブランチ **bunta/01** で作業する。
- ローカルで開発する。
- コミット・push したら、**GitHub で PR を作成**する。
- **main にマージ**すると、自動で本番に反映される。

---

## コードを書くときのルール（Windows / Mac 両対応）

チームメンバーが Windows と Mac の両方で開発するため、**どちらの環境でも動くように**書いてください。

| 項目 | ルール |
|------|--------|
| **パス** | PHP では `__DIR__` や `DIRECTORY_SEPARATOR` を使うか、スラッシュ（`/`）で統一する。 |
| **シェル・コマンド** | シェルスクリプトやコマンドに OS 依存の書き方をしない。 |
| **改行コード** | **LF** を前提とする（Git の設定で統一する）。 |
| **ハードコード禁止** | ファイルパス・環境変数・絶対パスをハードコードしない。 |

---

## 依存関係の可視化（重要）

機能を変更する前に、必ず関連する `DEPENDENCIES.md` ファイルを確認してください。

| 変更対象 | 確認すべきファイル |
|---------|-------------------|
| 全体構造 | `ARCHITECTURE.md` |
| チャット機能 | `includes/chat/DEPENDENCIES.md` |
| API | `api/DEPENDENCIES.md` |
| CSS/デザイン | `assets/css/DEPENDENCIES.md` |
| 共通機能 | `includes/DEPENDENCIES.md` |
| 設定 | `config/DEPENDENCIES.md` |

---

## 新規API開発

### 1. テンプレートを使用

新しいAPIを作成する場合は、テンプレートをコピーして開始：

```bash
cp api/_template.php api/my_feature.php
```

### 2. 基本構造

```php
<?php
// 1. ブートストラップ（必須）
require_once __DIR__ . '/../includes/api-bootstrap.php';

// 2. 必要に応じて追加のインクルード
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/Validator.php';

// 3. 認証が必要な場合
requireLogin();

// 4. 変数初期化
$pdo = getDB();
$input = getJsonInput();
$action = getAction();
$userId = getAuthUserId();

// 5. アクション分岐
switch ($action) {
    case 'list':
        handleList($pdo, $userId, $input);
        break;
    // ...
    default:
        errorResponse('不明なアクションです');
}

// 6. ハンドラ関数を実装
function handleList($pdo, $userId, $input) {
    // 処理
    successResponse(['items' => $items]);
}
```

---

## バリデーション

### Validatorクラスを使用

```php
require_once __DIR__ . '/../includes/Validator.php';

$v = new Validator($input);
$v->required('email', 'メールアドレス')
  ->email('email')
  ->required('password', 'パスワード')
  ->minLength('password', 8, 'パスワード');

if (!$v->isValid()) {
    errorResponse($v->getFirstError(), 400, ['errors' => $v->getErrors()]);
}
```

### 利用可能なバリデーション

| メソッド | 説明 | 例 |
|----------|------|-----|
| `required($field, $label)` | 必須チェック | `->required('email', 'メール')` |
| `email($field)` | メール形式 | `->email('email')` |
| `minLength($field, $min, $label)` | 最小文字数 | `->minLength('password', 8)` |
| `maxLength($field, $max, $label)` | 最大文字数 | `->maxLength('name', 50)` |
| `numeric($field)` | 数値 | `->numeric('price')` |
| `integer($field)` | 整数 | `->integer('count')` |
| `between($field, $min, $max)` | 範囲 | `->between('age', 1, 150)` |
| `in($field, $allowed)` | 許可値リスト | `->in('status', ['active', 'inactive'])` |
| `date($field, $format)` | 日付形式 | `->date('birthday', 'Y-m-d')` |
| `time($field)` | 時間形式 | `->time('start_time')` |
| `regex($field, $pattern, $msg)` | 正規表現 | `->regex('code', '/^[A-Z]{3}$/', '無効')` |
| `custom($field, $fn, $msg)` | カスタム | `->custom('x', fn($v) => $v > 0, 'エラー')` |

---

## 権限チェック

### permissions.phpを使用

```php
require_once __DIR__ . '/../includes/permissions.php';

// 組織のメンバー管理権限
if (!canManageOrgMembers($pdo, $userId, $orgId)) {
    denyAccess('管理者権限が必要です');
}

// グループ作成権限
if (!canCreateGroup($pdo, $userId, $orgId)) {
    denyAccess('グループを作成する権限がありません');
}

// 会話へのアクセス権限
if (!canAccessConversation($pdo, $userId, $conversationId)) {
    denyAccess('この会話にアクセスする権限がありません');
}
```

### 利用可能な権限関数

| 関数 | 説明 |
|------|------|
| `getOrgPermissions($pdo, $userId, $orgId)` | 組織内権限情報を取得 |
| `canManageOrgMembers($pdo, $userId, $orgId)` | メンバー管理権限 |
| `canCreateGroup($pdo, $userId, $orgId)` | グループ作成権限 |
| `canAccessConversation($pdo, $userId, $convId)` | 会話アクセス権限 |
| `isConversationAdmin($pdo, $userId, $convId)` | 会話管理者権限 |
| `canContactUser($pdo, $from, $to, $orgId)` | 連絡可能チェック |
| `canDeleteMessage($pdo, $userId, $msgId)` | メッセージ削除権限 |
| `canLeaveOrganization($pdo, $userId, $orgId)` | 組織退出可能 |
| `canMakeCall($pdo, $from, $to, $orgId)` | 通話発信権限 |
| `hasSystemAdminRole()` | システム管理者チェック |
| `denyAccess($message)` | 権限エラーで終了 |

---

## レスポンス

### 成功レスポンス

```php
// データのみ
successResponse(['items' => $items]);

// メッセージ付き
successResponse(['id' => $newId], '作成しました');

// ページネーション付き
successResponse([
    'items' => $items,
    'pagination' => buildPaginationResponse($total, $page, $perPage)
]);
```

### エラーレスポンス

```php
// 基本
errorResponse('エラーメッセージ');

// ステータスコード指定
errorResponse('認証が必要です', 401);
errorResponse('権限がありません', 403);
errorResponse('見つかりません', 404);

// 追加データ付き
errorResponse('バリデーションエラー', 400, ['errors' => $errors]);
```

---

## ロール定義

### システムロール（users.role）

| ロール | 説明 |
|--------|------|
| `system_admin` | システム管理者 |
| `org_admin` | 組織管理者（組織作成可能） |
| `user` | 一般ユーザー |

### 組織ロール（organization_members.role）

| ロール | 説明 |
|--------|------|
| `owner` | 組織オーナー |
| `admin` | 組織管理者 |
| `member` | 一般メンバー |
| `restricted` | 制限付きメンバー（未成年等） |

### 使い分け

- **システム機能**（ユーザー作成、システム設定）→ `users.role`
- **組織機能**（メンバー管理、グループ管理）→ `organization_members.role`

---

## ページネーション

### パラメータ取得

```php
$pagination = getPaginationParams(20, 100);
// 結果: ['page' => 1, 'per_page' => 20, 'offset' => 0]
```

### レスポンス作成

```php
$pagination = buildPaginationResponse($total, $page, $perPage);
// 結果: ['total' => 100, 'page' => 1, 'per_page' => 20, 'total_pages' => 5]
```

---

## 既存コードの改修

### 原則

1. **既存の動作を壊さない**
2. **テストしてから本番適用**
3. **段階的に移行**

### 改修時の流れ

1. 既存コードをバックアップ
2. 変更を実施
3. 動作確認
4. 必要に応じて新しい仕組みを適用

### 新しい仕組みへの移行（任意）

既存APIを改修する機会があれば、以下を検討：

```php
// Before（既存）
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

// After（改善後）
require_once __DIR__ . '/../includes/api-bootstrap.php';
```

---

## ファイル構成

```
nine/
├── api/                    # 公開API
│   ├── _template.php       # 新規API用テンプレート
│   ├── test_bootstrap.php  # 動作確認用
│   └── *.php               # 各種API
├── admin/                  # 管理画面
│   └── api/                # 管理画面用API
├── includes/               # 共通ライブラリ
│   ├── api-bootstrap.php   # API初期化
│   ├── api-helpers.php     # 共通ヘルパー
│   ├── permissions.php     # 権限チェック
│   ├── roles.php           # ロール定義
│   ├── Validator.php       # バリデーション
│   └── usage-check.php     # 利用時間制限
├── config/                 # 設定
│   ├── app.php             # アプリ設定
│   ├── database.php        # DB接続
│   ├── env.php             # 環境変数
│   └── session.php         # セッション
└── DOCS/                   # ドキュメント
    ├── DEVELOPER_GUIDE.md  # このファイル
    ├── IMPROVEMENT_PROPOSAL.md
    └── spec/               # 機能仕様書
```

---

## テスト

### ブートストラップテスト

```
http://localhost/nine/api/test_bootstrap.php?action=check
```

### バリデーションテスト

```bash
curl -X POST http://localhost/nine/api/test_bootstrap.php?action=validate \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com", "name": "テスト"}'
```

---

## トラブルシューティング

### 関数が未定義エラー

`includes/api-bootstrap.php`をインクルードしているか確認

### レスポンス形式が違う

古いAPIは独自のレスポンス形式を使用している場合があります。
新規開発では`successResponse`/`errorResponse`を使用してください。

### 権限チェックが効かない

1. `requireLogin()`を呼んでいるか確認
2. `getAuthUserId()`でユーザーIDが取得できているか確認
3. `organization_members`テーブルにデータがあるか確認


