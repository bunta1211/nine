# AWS 移転 進捗状況

最終更新: 2026年2月8日

---

## 全体の進捗イメージ

```
[Phase 0: 準備]     ████████████████████ 100% 完了
[Phase 1: インフラ]  ████████████████████ 100% 完了
[Phase 2: データ]   ████████████████████ 100% 完了
[Phase 3: アプリ]   ████████████████████ 100% 完了
```

**✅ AWS 移転 完了**

---

## 完了している項目

| 項目 | 状態 | 備考 |
|------|------|------|
| AWSアカウント | ✅ 完了 | |
| 請求アラート（予算） | ✅ 完了 | Social9: $100 |
| EC2 インスタンス | ✅ 完了 | Social9, t3.micro, 東京リージョン |
| EC2 に SSH 接続 | ✅ 完了 | 54.95.86.79 |
| Apache（Webサーバー） | ✅ 完了 | 動作確認済み |
| PHP | ✅ 完了 | 環境変数で DB 接続 |
| RDS（データベース） | ✅ 完了 | database-1, MySQL |
| RDS 接続設定 | ✅ 完了 | EC2 から接続可能 |
| social9 データベース作成 | ✅ 完了 | RDS 内に空の DB が存在 |

---

## 完了済み（Phase 2・3）

| 項目 | 状態 |
|------|------|
| データインポート | ✅ social9.sql を RDS にインポート完了 |
| アプリデプロイ | ✅ EC2 の /var/www/html/ に配置完了 |
| 動作確認 | ✅ ログイン・チャット画面が表示・動作 |

---

## 構成図（現状）

```
[ユーザー] → http://54.95.86.79/ （Elastic IP で固定）
    │
    ▼
[EC2 54.95.86.79]  Apache + PHP + Social9 動作中
    │
    │ 環境変数で接続
    ▼
[RDS database-1]  social9 データインポート済み
```

---

## 参考: 主要な接続情報

| 項目 | 値 |
|------|-----|
| EC2 IP（Elastic IP） | 54.95.86.79 |
| RDS エンドポイント | database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com |
| DB 名 | social9 |
| DB ユーザー | admin |
| キーファイル | C:\Users\user\Downloads\social9-key.pem |

---

## 次のステップ（ドメイン・HTTPS）

| 項目 | 状態 | 参照 |
|------|------|------|
| Elastic IP | ✅ 完了（54.95.86.79） | |
| Route 53 ホストゾーン・A レコード | ✅ 完了 | social9.jp → 54.95.86.79 |
| 取得元で NS 変更 | ✅ 完了 | ムームードメインで Route 53 の NS を設定 |
| HTTPS（SSL） | 未着手 | [ドメイン・HTTPS ガイド](./AWS_DOMAIN_HTTPS_GUIDE.md) |

---

*関連: [AWS移転 起動手順](./AWS_MIGRATION_STEPS.md) | [EC2 設定手順](./AWS_EC2_SETUP_GUIDE.md) | [ドメイン・HTTPS ガイド](./AWS_DOMAIN_HTTPS_GUIDE.md)*
