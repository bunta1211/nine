# 本番 500 エラーの根本原因と対処（2026-03-05 調査結果）

## 確認結果サマリ

| 確認項目 | 結果 |
|----------|------|
| 本番の `api/messages.php` に 500 対策コード（hasUsersAvatarPath 等）は入っているか | **はい**（764, 767, 800 行目に存在） |
| 本番の `api/tasks.php` に count フォールバックは入っているか | **はい**（922, 925 行目に存在） |
| 本番の PHP エラーログの場所 | `/var/log/php-fpm/www-error.log` |

## 根本原因（本番で 500 が出ている本当の理由）

**Composer の vendor が本番で欠けているため、`vendor/autoload.php` 読み込み時に例外が発生している。**

- **エラー内容（PHP-FPM ログより）**  
  `Failed opening required '/var/www/html/vendor/composer/../ralouphie/getallheaders/src/getallheaders.php': No such file or directory`  
  in `vendor/composer/autoload_real.php` on line 41

- **発生箇所**  
  - `api/messages.php` の 12 行目付近で `require_once __DIR__ . '/../vendor/autoload.php'` を実行  
  - `api/tasks.php` は `includes/push_helper.php` 経由で同様に `vendor/autoload.php` を読み込むため、同じエラーで 500

- **なぜこれまで気づきにくかったか**  
  - 私たちが行った対策（avatar_path 条件付き・count の try-catch など）は、**その前に実行される** `vendor/autoload.php` の require が成功した後にしか到達しない。  
  - 本番では require の段階で落ちているため、DB まわりの対策は一切効いていない。

## 対処方法（いずれかで実施）

### 方法 A: 本番で `composer install` を実行（推奨・実施済み）

本番の `vendor` が欠けていたため、**vendor を削除してから `--prefer-dist` で再インストール**した。

```bash
cd /var/www/html
sudo rm -rf vendor
sudo COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --no-dev --prefer-dist
sudo chown -R apache:apache /var/www/html/vendor
```

- 実施後、`api/messages.php` と `api/tasks.php` は **401（未認証）** を返すようになり、500 は解消された。
- 今後、本番で `composer update` やパッケージ追加をしたときも、`--prefer-dist` と `chown apache:apache` を忘れずに実行すること。

### 方法 B: ローカルの `vendor` を本番にアップロード

- ローカルで `composer install` を実行し、`vendor` 一式を用意する。  
- 本番の `/var/www/html/vendor` は **root または apache 所有のため、scp で直接アップロードすると Permission denied になる**ことがある。  
- その場合は、本番で次のいずれかを行う。  
  - **B-1.** 一時ディレクトリに受け取り、sudo でコピーする例:
    ```bash
    # ローカルから（src だけ送る例）
    scp -i "..." "c:\xampp\htdocs\nine\vendor\ralouphie\getallheaders\src\getallheaders.php" ec2-user@54.95.86.79:/tmp/
    # 本番サーバーで
    ssh -i "..." ec2-user@54.95.86.79
    sudo mkdir -p /var/www/html/vendor/ralouphie/getallheaders/src
    sudo cp /tmp/getallheaders.php /var/www/html/vendor/ralouphie/getallheaders/src/
    sudo chown -R apache:apache /var/www/html/vendor/ralouphie
    ```
  - **B-2.** 本番で `composer install` を実行する（方法 A）が確実。

### 方法 C: 一時的に vendor 読み込みをスキップする（非推奨）

- `api/messages.php` などで `vendor/autoload.php` を require しないようにすると、getallheaders のエラーは出なくなるが、**Web Push や Google API など、Composer パッケージに依存する機能が動かなくなる**ため、恒久対策としては推奨しない。

## その他のログ（参考）

- `checkTranslationBudget: Unknown column 'cost_usd' in 'field list'`  
  - 翻訳予算用のカラムが本番 DB にない可能性。  
  - 500 の直接原因は上記の vendor 不足。必要に応じて別途マイグレーションや条件分岐で対応する。

## 本番でログを再確認するとき

```bash
# PHP のエラー（500 の原因はここに出る）
sudo tail -n 100 /var/log/php-fpm/www-error.log
```
