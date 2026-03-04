# BEM命名規則ガイド

Social9プロジェクトのCSS命名規則です。新規CSSは全てこの規則に従ってください。

## BEMとは

BEM (Block Element Modifier) は、CSSクラス名の命名規則です。

```
.block__element--modifier
```

- **Block**: 独立したコンポーネント（例: `.input-area`）
- **Element**: ブロックの一部（例: `.input-area__textarea`）
- **Modifier**: 状態やバリエーション（例: `.input-area__btn--active`）

## 命名規則

### 基本形式

```css
/* Block */
.component-name { }

/* Element */
.component-name__element-name { }

/* Modifier */
.component-name--modifier { }
.component-name__element-name--modifier { }
```

### 命名のルール

1. **ケバブケース**: 単語はハイフンで区切る（`input-area`、`message-card`）
2. **アンダースコア2つ**: 要素の区切り（`__`）
3. **ハイフン2つ**: 修飾子の区切り（`--`）
4. **小文字のみ**: 大文字は使用しない

## 実例

### 入力エリアコンポーネント

```css
/* Block */
.input-area { }

/* Elements */
.input-area__container { }
.input-area__toolbar { }
.input-area__textarea { }
.input-area__send-btn { }
.input-area__edit-bar { }

/* Modifiers */
.input-area--hidden { }
.input-area__btn--active { }
.input-area__edit-bar--visible { }
```

### HTML例

```html
<div class="input-area">
    <div class="input-area__edit-bar input-area__edit-bar--visible">
        <span class="input-area__edit-icon">✏️</span>
        <span class="input-area__edit-text">編集中</span>
        <button class="input-area__edit-cancel">キャンセル</button>
    </div>
    <div class="input-area__toolbar">
        <button class="input-area__btn input-area__btn--active">TO</button>
        <button class="input-area__btn">GIF</button>
    </div>
    <div class="input-area__row">
        <textarea class="input-area__textarea"></textarea>
        <button class="input-area__send-btn">送信</button>
    </div>
</div>
```

## コンポーネント一覧

| Block名 | 説明 | ファイル |
|---------|------|----------|
| `.input-area` | 入力エリア | `components/input-area.css` |
| `.message-card` | メッセージカード | (予定) |
| `.conversation-item` | 会話リストアイテム | `layout/sidebar.css` |
| `.top-panel` | ヘッダー | `layout/header.css` |
| `.center-panel` | 中央パネル | `layout/center-panel.css` |
| `.left-panel` | 左パネル | `layout/sidebar.css` |
| `.right-panel` | 右パネル | `layout/right-panel.css` |

## 後方互換性

既存のクラス名は段階的に新しいBEM名に移行します。

### 移行パターン

```css
/* 旧クラス名を新クラス名にマッピング */
.input-container { /* → .input-area__container */ }
.edit-mode-bar { /* → .input-area__edit-bar */ }
.toolbar-btn { /* → .input-area__btn */ }
```

### 移行期間中の対応

両方のクラス名をサポートする場合：

```css
/* 新旧両対応 */
.input-area__textarea,
#messageInput {
    /* 共通スタイル */
}
```

## アンチパターン

### ❌ 避けるべき例

```css
/* 深いネスト */
.input-area__toolbar__left__button { }  /* ❌ */
.input-area__toolbar-left-btn { }       /* ✅ */

/* キャメルケース */
.inputArea { }        /* ❌ */
.input-area { }       /* ✅ */

/* ID セレクタ */
#messageInput { }     /* ❌ 避ける */
.input-area__textarea { }  /* ✅ */

/* タグセレクタ */
.input-area textarea { }   /* ❌ */
.input-area__textarea { }  /* ✅ */
```

### ✅ 推奨パターン

```css
/* フラットなセレクタ */
.input-area__toolbar { }
.input-area__toolbar-left { }
.input-area__toolbar-btn { }

/* 状態はModifierで */
.input-area__btn--active { }
.input-area__btn--disabled { }

/* バリエーションもModifierで */
.input-area__btn--primary { }
.input-area__btn--secondary { }
```

## 新規コンポーネント追加時のチェックリスト

- [ ] Block名は一意か
- [ ] 既存のクラス名と衝突しないか
- [ ] 命名規則に従っているか
- [ ] ファイルは `components/` または `layout/` に配置したか
- [ ] DEPENDENCIES.md を更新したか
- [ ] このガイドのコンポーネント一覧を更新したか
