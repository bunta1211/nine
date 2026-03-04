# TCPDF セットアップ（PDF変換用）

1000文字以上の長文をPDFに変換するには、TCPDF が必要です。

## 方法1: composer（推奨）

```bash
cd /path/to/nine
composer install
```

## 方法2: 手動コピー（composer が使えない場合）

サーバーで composer が使えない場合、TCPDF を手動で配置します。

### 手順

1. **ローカルで** `vendor/tecnickcom/tcpdf` フォルダを **全体** コピー
2. プロジェクトの `includes` フォルダ内に `tcpdf` として配置
3. 最終的なパス: `includes/tcpdf/tcpdf.php` が存在すること

### フォルダ構成

```
includes/
├── pdf_helper.php
├── tcpdf/          ← vendor/tecnickcom/tcpdf をここにコピー
│   ├── tcpdf.php
│   ├── config/
│   ├── fonts/
│   ├── include/
│   └── ...
```

### Windowsの場合（コマンド）

```cmd
cd C:\xampp\htdocs\nine
xcopy /E /I vendor\tecnickcom\tcpdf includes\tcpdf
```

### アップロード

`includes/tcpdf` フォルダ全体をサーバーにアップロードしてください。
