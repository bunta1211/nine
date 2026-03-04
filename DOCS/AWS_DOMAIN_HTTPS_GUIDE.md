# AWS ドメイン割り当て・HTTPS 設定ガイド

social9.jp を EC2 に割り当て、HTTPS を有効にする手順です。

---

## 前提

- EC2（Elastic IP）: 54.95.86.79（固定）
- ドメイン: social9.jp（heteml 本番で使用中）
- 重要: 本番移行時は **DNS 切り替えのタイミング** を慎重に

---

## ステップ1: Elastic IP の割り当て（必須）

EC2 の IP を固定にし、再起動後も変わらないようにします。

### 1-1. Elastic IP を取得

1. **AWS コンソール** → 検索で **「EC2」** を開く
2. 左メニュー **「Elastic IP」**（ネットワーク & セキュリティ内）をクリック
3. **「Elastic IP アドレスの割り当て」** をクリック
4. 設定はそのまま **「割り当て」** をクリック

### 1-2. EC2 に紐づける

1. 作成した Elastic IP を選択
2. **「アクション」** → **「Elastic IP アドレスの関連付け」**
3. **インスタンス** で **Social9** を選択
4. **「関連付け」** をクリック

### 1-3. 新しい IP を確認

- **注意**: 関連付け後、EC2 の **パブリック IP が変わります**
- Elastic IP の表示されている値が新しい固定 IP です
- 以降の設定では **この新しい IP** を使用してください

---

## ステップ2: Route 53 の設定

### 2-1. social9.jp の現在の取得状況を確認

| 状況 | 次のアクション |
|------|----------------|
| すでに取得済み（お名前.com、GoDaddy 等） | 2-2 へ（ホストゾーン作成） |
| まだ取得していない | Route 53「ドメインの登録」で取得 |

### 2-2. ホストゾーンを作成

1. **AWS コンソール** → 検索で **「Route 53」** を開く
2. 左メニュー **「ホストゾーン」** → **「ホストゾーンの作成」**
3. 入力:
   - **ドメイン名**: `social9.jp`
   - **タイプ**: パブリック ホストゾーン
4. **「ホストゾーンの作成」** をクリック

### 2-3. A レコードを追加

1. 作成した **social9.jp** のホストゾーンをクリック
2. **「レコードを作成」** をクリック
3. 入力:
   - **レコード名**: 空欄（ルートドメイン）または `www`
   - **レコードタイプ**: A
   - **値**: Elastic IP のアドレス（例: 54.xxx.xxx.xxx）
   - **ルーティングポリシー**: シンプルルーティング
4. **「レコードを作成」** をクリック

### 2-4. ネームサーバーを既存のドメインに設定（重要）

Route 53 のホストゾーン画面で **NS レコード** を確認します。

**social9.jp 用の Route 53 ネームサーバー:**
```
ns-76.awsdns-09.com.
ns-712.awsdns-25.net.
ns-1892.awsdns-44.co.uk.
ns-1149.awsdns-15.org.
```

---

#### ムームードメインでネームサーバーを変更する場合

1. **https://muumuu-domain.com/?mode=conpane** にログイン
2. 左メニュー **「ドメイン管理」** → **「ネームサーバー設定変更」** をクリック
   （または「ドメイン操作」内から選択）
3. 取得済みドメイン一覧から **social9.jp** の **「ネームサーバー設定変更」** をクリック
4. **「GMOペパボ以外のネームサーバーを使用する」** にチェック
5. 上記の Route 53 の NS 4件を入力（ns1〜ns4 の欄にそれぞれ）
6. **「ネームサーバー設定変更」** をクリック
7. 確認画面で **「OK」** をクリック

※ 反映まで 1時間〜最大48時間かかることがあります

---

## ステップ3: HTTPS（SSL証明書）の設定

**詳細な手順は [AWS_HTTPS_SETUP_STEPS.md](./AWS_HTTPS_SETUP_STEPS.md) を参照してください。**

### 概要（Amazon Linux 2023）

```bash
# 1. Certbot インストール
sudo dnf install -y certbot python3-certbot-apache

# 2. 証明書取得（対話式）
sudo certbot --apache -d social9.jp -d www.social9.jp

# 3. 自動更新の有効化
sudo systemctl enable --now certbot-renew.timer
```

---

## ステップ4: アプリの APP_URL を更新

EC2 の Apache 設定に APP_URL を追加します。

```bash
# db-env.conf に APP_URL を追加
echo 'SetEnv APP_URL https://social9.jp' | sudo tee -a /etc/httpd/conf.d/db-env.conf
sudo systemctl restart httpd
```

または、既存の db-env.conf を編集:

```bash
sudo nano /etc/httpd/conf.d/db-env.conf
# SetEnv APP_URL https://social9.jp を追加
```

---

## 作業の順序まとめ

| 順番 | 作業 | 補足 |
|------|------|------|
| 1 | Elastic IP 割り当て | EC2 の IP が変わる |
| 2 | Route 53 ホストゾーン作成 | |
| 3 | A レコード追加 | Elastic IP を指定 |
| 4 | ドメイン取得元で NS 変更 |  propagation を待つ |
| 5 | Certbot で SSL 取得 | ドメインが有効になってから |
| 6 | APP_URL 更新 | |

---

## 注意事項

- **本番（heteml）稼働中**: DNS 切り替え後、social9.jp は AWS の EC2 を向きます。**切り替え前にバックアップと動作確認を完了**してください。
- **Elastic IP の料金**: インスタンスに紐づけている間は無料。紐づけ解除後は課金対象です。
- **Route 53 料金**: ホストゾーン $0.50/月、クエリ $0.40/100万件

---

*関連: [AWS移転 進捗状況](./AWS_MIGRATION_STATUS.md)*
