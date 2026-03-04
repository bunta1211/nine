# テーマCSS依存関係

このディレクトリには静的なテーマCSSファイルが含まれています。

## ファイル一覧

| ファイル | テーマ名 | 説明 |
|---------|---------|------|
| `default.css` | フォレスト | デフォルトテーマ（グリーン系） |
| `fuji.css` | 富士山 | 透明デザイン用（グレー系） |

## 使用方法

### 方法1: 直接読み込み

```html
<link rel="stylesheet" href="assets/css/themes/default.css">
```

### 方法2: CSS変数のオーバーライド

テーマCSSで定義されたCSS変数（`--dt-*`）を使用して、
コンポーネントのスタイルを制御します。

```css
.header {
    background: var(--dt-header-bg);
    color: var(--dt-header-text);
}
```

## CSS変数一覧

### グローバル
| 変数 | 説明 |
|-----|------|
| `--dt-accent` | アクセントカラー |
| `--dt-accent-hover` | アクセントホバー |
| `--dt-text-primary` | メインテキスト |
| `--dt-text-muted` | 薄いテキスト |
| `--dt-text-light` | 最も薄いテキスト |

### パネル
| 変数 | 説明 |
|-----|------|
| `--dt-header-bg` | ヘッダー背景 |
| `--dt-left-bg` | 左パネル背景 |
| `--dt-center-bg` | 中央パネル背景 |
| `--dt-right-bg` | 右パネル背景 |

### メッセージ
| 変数 | 説明 |
|-----|------|
| `--dt-msg-self-bg` | 自分のメッセージ背景 |
| `--dt-msg-other-bg` | 他人のメッセージ背景 |
| `--dt-msg-mention-bg` | メンション背景 |

## 移行状況

| テーマ | 静的CSS | 状態 |
|-------|--------|------|
| default (フォレスト) | ✅ | 完了 |
| fuji (富士山) | ✅ | 完了 |
| city (シティ) | ✅ | 完了 |
| snow (雪山) | ✅ | 完了 |
| ocean (オーシャン) | ✅ | 完了 |

## design_loader.phpとの関係

現在、テーマCSSは`design_loader.php`で動的に生成されています。
将来的には以下のように移行予定：

1. 静的テーマCSSをベースとして読み込み
2. ユーザー固有の設定（背景画像など）のみを動的に生成
3. design_loader.phpの出力を最小限に

これにより：
- サーバー負荷の軽減
- キャッシュ効率の向上
- テーマ切り替えの高速化
