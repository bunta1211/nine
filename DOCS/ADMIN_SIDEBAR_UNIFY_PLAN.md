# 管理パネル 左サイドバー統一 計画書

## 目的
システム管理系の全ページで、左パネルにメニュー・右側に選択ページが表示されるレイアウトに統一する。

## 対象ページ（サイドバーがない／戻るリンクのみのページ）

| # | ファイル | 現状 | 対応内容 |
|---|----------|------|----------|
| 1 | `admin/monitor.php` | コンテナのみ、サイドバーなし | 左サイドバー追加、既存コンテンツを main-content 内に配置 |
| 2 | `admin/improvement_reports.php` | 独自ヘッダー＋コンテナ、サイドバーなし | 左サイドバー追加、既存コンテンツを main-content 内に配置 |
| 3 | `admin/security.php` | コンテナのみ、サイドバーなし | 左サイドバー追加、既存コンテンツを main-content 内に配置 |
| 4 | `admin/attackers.php` | 独自ダークテーマ、サイドバーなし | 左サイドバー追加、既存コンテンツを main-content 内に配置 |
| 5 | `admin/backup.php` | 「← 管理画面に戻る」のみ、サイドバーなし | 左サイドバー追加、既存コンテンツを main-content 内に配置 |

## 既にサイドバーあり（変更不要）
- `admin/index.php` … 基準レイアウト
- `admin/users.php`
- `admin/ai_usage.php`
- `admin/reports.php`
- `admin/specs.php`
- `admin/settings.php`
- `admin/user_groups.php`
- （その他 sidebar-nav を持つページ）

## 実装方針

### 1. 共通部品
- **`admin/includes/sidebar.php`**  
  - 左サイドバーHTMLを出力する include。
  - 呼び出し前に `$admin_current_page` を設定する（例: `'monitor'`, `'security'`, `'improvement'`, `'attackers'`, `'backup'`）。
  - メニュー項目と active クラスは index.php のサイドバーと同一とする。

### 2. レイアウト構造（全対象ページで統一）
```html
<div class="admin-container">
    <?php $admin_current_page = 'monitor'; require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-content">
        <!-- 既存のページ固有コンテンツ -->
    </main>
</div>
```

### 3. スタイル
- サイドバー用の共通スタイル（`.admin-container`, `.sidebar`, `.sidebar-header`, `.sidebar-nav`, `.main-content`）を `assets/css/admin.css` に追加する。
- 各ページの既存スタイルは、`.main-content` 内のコンテナ（例: `.monitor-container`）にそのまま適用し、必要に応じて padding のみ調整する。

### 4. 作業手順（1ページずつ）
1. 対象ページの先頭付近で `$admin_current_page = 'xxx';` を設定。
2. `<body>` 直後に `<div class="admin-container">` を出力。
3. `includes/sidebar.php` を require。
4. 既存のメインコンテンツを `<main class="main-content">` で囲む。
5. ページ末尾で `</main></div>` を追加（</body> の直前）。
6. タイトルや「戻る」リンクは必要に応じて main 内に残すか削除。
7. `admin.css` を読み込む（未読込の場合のみ）。
8. ドラッグ並び替え用に `admin-sidebar-sort.js` を読み込む（未読込の場合のみ）。

## 変更チェックリスト（ページ別）

### monitor.php
- [ ] require 前に `$admin_current_page = 'monitor';`
- [ ] body 直下に admin-container 開始、sidebar include、main-content 開始
- [ ] 既存 .monitor-container は main-content 内にそのまま
- [ ] body 閉じ前に main と admin-container を閉じる
- [ ] admin.css 参照あり
- [ ] admin-sidebar-sort.js 追加

### improvement_reports.php
- [ ] require 前に `$admin_current_page = 'improvement';`
- [ ] 独自 .ir-admin-page のヘッダーは「管理パネル」への戻りリンクに変更するか、main 内に移動
- [ ] body 直下に admin-container、sidebar、main-content
- [ ] 既存コンテンツは main-content 内に
- [ ] admin.css 参照追加、共通レイアウト用
- [ ] admin-sidebar-sort.js 追加

### security.php
- [ ] `$admin_current_page = 'security';`
- [ ] admin-container / sidebar / main-content で囲む
- [ ] 既存 .security-container は main 内に
- [ ] admin-sidebar-sort.js 追加

### attackers.php
- [ ] `$admin_current_page = 'attackers';`
- [ ] 独自ダーク背景は main-content 内のコンテナに限定するか、body は共通背景に
- [ ] admin-container / sidebar / main-content で囲む
- [ ] admin-sidebar-sort.js 追加

### backup.php
- [ ] `$admin_current_page = 'backup';`
- [ ] 「← 管理画面に戻る」は削除（サイドバーで代替）
- [ ] admin-container / sidebar / main-content で囲む
- [ ] admin-sidebar-sort.js 追加

## 完了基準
- 上記5ページのいずれを開いても、左に管理パネルメニュー、右に当該ページの内容が表示されること。
- メニューのドラッグ並び替えが効くこと（admin-sidebar-sort.js 読み込み済みの場合）。
- 既存の権限チェック・表示内容は変更しないこと。

## 更新日
2026-02-27
