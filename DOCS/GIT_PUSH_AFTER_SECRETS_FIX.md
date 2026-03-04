# シークレット除去後の push 手順（PowerShell）

# GitHub が「Push cannot contain secrets」で拒否した場合、
# 履歴を捨てて「現在の状態だけ」で push し直します。

# 1. 現在の main の内容を一時退避（念のため）
git branch backup-before-rewrite

# 2. 新しい「履歴のない」main を作る
git checkout --orphan new-main

# 3. ステージングをいったんクリア
git rm -rf --cached .

# 4. 現在の作業ツリーをすべて追加（.gitignore が効く）
git add .

# 5. 1 コミットだけ作成
git commit -m "プロジェクト一式を追加（シークレット含まない版）"

# 6. 古い main を削除して new-main を main にリネーム
git branch -D main
git branch -m main

# 7. リモートの main を上書き（force push）
git push -f origin main

# 完了後、不要なら backup-before-rewrite を削除してよい
# git branch -D backup-before-rewrite
