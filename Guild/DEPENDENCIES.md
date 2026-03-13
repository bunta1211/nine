# Guild（報酬分配システム）依存関係

Guildは Social9 とは独立したサブシステムです。
独自の設定、API、データベースを持っています。

## 仕様・設計（作業前に必ず確認）

- **`Guild/DOCS/GUILD_SPEC_AND_CONTEXT.md`** … 単位（1pt=100円）、構造（大元→割振→ギルド→個人）、労務ページ、ギルド長権限、フロー、目指す体験などをまとめた仕様・理解メモ。Guild 関連の実装・変更時は必ず参照すること（Cursor ルール: `.cursor/rules/guild-work.mdc`）。

## アーキテクチャ概要

```
Guild/
├── DEPENDENCIES.md          ← このファイル
├── index.php                ← エントリ。Fatal時は shutdown で setup へ。親 config 無い場合も setup へ。
├── home.php                 ← ダッシュボード
├── leader.php               ← ギルド長ページ（リーダー・サブリーダー用。担当ギルドの管理入口）
├── requests.php             ← 申請一覧
├── calendar.php             ← カレンダー
│
├── api/                     ← Guild専用API
├── admin/                   ← Guild管理画面
├── config/                  ← Guild専用設定
├── includes/                ← Guild共通PHP
├── assets/                  ← Guild専用CSS/JS
├── templates/               ← 共通テンプレート
└── database/                ← Guildスキーマ
```

## Social9 との関係

```
┌────────────────────────────────────────────────────────────┐
│ Social9 (nine/)                                            │
│                                                            │
│  chat.php ──────┐                                          │
│                 │ リンク                                   │
│                 ▼                                          │
│  ┌────────────────────────────────────────────────────┐   │
│  │ Guild/ (独立サブシステム)                           │   │
│  │                                                    │   │
│  │  - 独自の config/database.php                      │   │
│  │  - 独自の includes/                                │   │
│  │  - 独自のセッション管理                            │   │
│  │  - Social9のユーザーテーブルを参照                 │   │
│  │                                                    │   │
│  └────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
```

## 共有リソース

| リソース | Social9 | Guild | 備考 |
|---------|---------|-------|------|
| users テーブル | ○ | 参照のみ | ユーザー認証に使用 |
| セッション | ○ | 共有 | 同一 session_save_path（tmp/sessions）でログイン状態を共有。Guild/config/session.php で path を設定 |
| CSS | assets/css/ | Guild/assets/css/ | 完全に独立 |
| JS | assets/js/ | Guild/assets/js/ | 完全に独立 |

---

## ファイル別依存関係

### templates/header.php

**役割**: 全ページ共通のヘッダー・サイドバー

**依存関係**:
- `assets/css/layout.css` - レイアウトスタイル
- `assets/js/layout.js` - サイドバー・メニュー制御
- `includes/lang.php` - 多言語対応

**提供するUI要素**:
- トップバー（言語切替、通知、ユーザーメニュー）
- サイドバー（ナビゲーション、Social9戻るリンク）

**変更時のチェックリスト**:
- [ ] 言語切替の動作確認
- [ ] Social9戻るリンクのパス確認（相対パス注意）
- [ ] レスポンシブ表示確認

### home.php

**依存関係**:
- `templates/header.php`
- `assets/css/home.css`
- `assets/js/home.js`
- `api/requests.php` - 申請データ取得

### requests.php / my-requests.php

**依存関係**:
- `templates/header.php`
- `assets/css/requests.css`
- `assets/js/requests.js`
- `api/requests.php`
- DB: `guild_requests`, `guild_members`

### calendar.php

**依存関係**:
- `templates/header.php`
- `assets/css/calendar.css`
- `assets/js/calendar.js`
- `api/calendar.php`

---

## API依存関係

### api/requests.php

| アクション | 役割 | DB依存 |
|-----------|------|--------|
| `list` | 申請一覧 | guild_requests |
| `create` | 申請作成 | guild_requests |
| `approve` | 承認 | guild_requests |
| `reject` | 却下 | guild_requests |

### api/notifications.php

**Social9との連携**:
- `includes/app_notify.php` を使用してSocial9に通知を送信

---

## 設定ファイル

### config/database.php

**重要**: Social9とは別のDB接続設定を持つ場合がある

```php
// Guild専用の接続設定
// 本番環境では Social9 と同じDBを使用するが、
// 接続情報は独自に管理
```

### config/app.php

| 設定 | 用途 |
|-----|------|
| `GUILD_URL` | GuildのベースURL |
| `SOCIAL9_URL` | Social9へのリンク用 |

---

## 多言語対応

### includes/lang/

| ファイル | 言語 |
|---------|------|
| `ja.php` | 日本語 |
| `en.php` | English |
| `zh.php` | 中文 |

**Social9との違い**:
- Social9: `includes/lang.php` に全言語を内包
- Guild: `includes/lang/*.php` に言語別ファイル

---

## 本番で /Guild/ が 500 になる場合

- **index.php** で Fatal Error 時は `register_shutdown_function` により **setup.php へリダイレクト**するようにしている。それでも 500 が出る場合は、PHP が Fatal の前に何か出力しているか、別原因。
- **config/database.php**: 親の `config/database.php`（`../../config/database.php`）が存在しない場合は **setup.php へリダイレクト**。本番の rsync で `config/` はそのままなので、Guild と同階層に `config/` がある構成になっているか確認する。
- **本番の PHP エラーログ**で原因を確認する（DOCS/PRODUCTION_500_ROOT_CAUSE.md 参照）:
  - PHP-FPM: `sudo tail -n 100 /var/log/php-fpm/www-error.log`
  - Apache: `sudo tail -n 100 /var/log/httpd/error_log`
- デプロイ先が `/var/www/html/` の場合、`/var/www/html/config/database.php` と `/var/www/html/Guild/config/database.php` が両方存在する必要がある。

---

## 変更時の注意点

### GuildのCSSを変更する場合
- Social9のCSSとは独立しているため、影響なし
- ただし `templates/header.php` は全ページに影響

### GuildのAPIを変更する場合
- Social9のAPIとは独立
- `includes/api-bootstrap.php` はGuild専用

### Social9に戻るリンクを変更する場合
- `templates/header.php` 内の相対パスを確認
- `/Guild/home.php` → `../chat.php`
- `/Guild/admin/index.php` → `../../chat.php`

---

## データベーステーブル（Guild専用）

| テーブル | 用途 |
|---------|------|
| `guilds` | ギルド情報 |
| `guild_members` | ギルドメンバー |
| `guild_requests` | 申請 |
| `guild_payments` | 支払い記録 |
| `guild_settings` | ギルド設定 |

スキーマ定義: `database/schema.sql`
