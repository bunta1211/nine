# 本番反映用スクリプト

## social9.jp を EC2 に強制向けする（hosts 設定）

**問題**: social9.jp で変更が反映されず、404 が出る  
**原因**: ブラウザが旧サーバー（heteml）を参照している  
**対処**: hosts で social9.jp を EC2（54.95.86.79）に固定

### 手順

1. **`setup-hosts-ec2-enable.bat`** をダブルクリック
2. UAC で「管理者として実行」を許可
3. 完了後、ブラウザを再起動
4. `http://social9.jp` を開く → 「EC2」バッジが表示されれば OK

### 元に戻す

**`setup-hosts-ec2-disable.bat`** をダブルクリック（管理者権限で実行）
