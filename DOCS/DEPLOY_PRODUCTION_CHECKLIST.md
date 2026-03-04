# 本番環境への反映チェックリスト

ローカルと本番で見え方が違う場合、以下の送り先に該当ファイルをアップロードしてください。  
WinSCP のリモート側パスが `/web/social9.jp/` の場合、表の「本番（送り先）」をそのまま使えます。

---

## 送り先の確認

| 環境 | パス（目安） |
|------|----------------|
| ローカル | `C:\xampp\htdocs\nine\` |
| 本番（送り先） | `/web/social9.jp/` （WinSCP 右ペインのパス） |

※ 本番のドキュメントルートが別の場合は、そのルート直下に同じ相対パスで配置してください。

---

## 1. 時計クローバーのデザインを本番に合わせる

本番で時計クローバーが正しく表示されない場合、以下をアップロードします。

| ローカル | 本番（送り先） |
|----------|----------------|
| `includes/design_loader.php` | `/web/social9.jp/includes/design_loader.php` |
| `includes/design_config.php` | `/web/social9.jp/includes/design_config.php` |
| `assets/samples/tokei_clover01.jpg` | `/web/social9.jp/assets/samples/tokei_clover01.jpg` |
| `chat.php` | `/web/social9.jp/chat.php` |
| `design.php` | `/web/social9.jp/design.php` |
| `assets/js/design-settings.js` | `/web/social9.jp/assets/js/design-settings.js` |

---

## 2. 詳細（所属グループ）を本番で見れるようにする

管理画面で「所属グループ」を表示・利用するために必要なファイルです。

| ローカル | 本番（送り先） |
|----------|----------------|
| `admin/users.php` | `/web/social9.jp/admin/users.php` |
| `admin/user_groups.php` | `/web/social9.jp/admin/user_groups.php` |
| `admin/user_detail.php` | `/web/social9.jp/admin/user_detail.php` |

※ 「詳細」リンクは `user_detail.php` 経由で `user_groups.php` にリダイレクトされます。3つともそろっていることを確認してください。

---

## 3. チャットの右パネル（詳細パネル）を本番に合わせる

チャット画面の右側「詳細」パネルの見た目・動作をローカルと揃える場合です。

| ローカル | 本番（送り先） |
|----------|----------------|
| `includes/chat/rightpanel.php` | `/web/social9.jp/includes/chat/rightpanel.php` |
| `includes/chat/scripts.php` | `/web/social9.jp/includes/chat/scripts.php` |
| `assets/css/chat-main.css` | `/web/social9.jp/assets/css/chat-main.css` |
| `chat.php` | `/web/social9.jp/chat.php` |

---

## 4. その他の原因で差が出る場合

- **更新日時が本番のほうが古い**  
  → そのファイルをローカルから上書きアップロードする。
- **サイズが違う**  
  → 中身が違うので、ローカル版を本番に上書きする。
- **キャッシュ**  
  → アップロード後、ブラウザで強制リロード（Ctrl+Shift+R）またはシークレットウィンドウで確認する。

---

## 5. 一括アップロードしたい場合

次のディレクトリごと、ローカル → 本番で上書きすると、ローカルと本番の差を一気に減らせます。

- `admin/` 一式
- `includes/` 一式（`design_loader.php`, `design_config.php`, `includes/chat/` 含む）
- `assets/samples/` 一式
- `assets/css/` 一式
- `assets/js/` 一式
- ルートの `chat.php`, `design.php` など、変更した PHP

※ `config/*.local.php` や `config/database.aws.php` など、本番専用の設定は上書きしないでください。

---

## 6. 反映後の確認URL（本番）

- 時計クローバー: `https://social9.jp/design.php` でテーマを選択し、`https://social9.jp/chat.php` で背景を確認。
- 所属グループ: `https://social9.jp/admin/users.php` → いずれかのユーザーで「所属グループ」をクリック → `user_groups.php` が開くこと。
