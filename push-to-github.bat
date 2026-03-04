@echo off
chcp 65001 >nul
cd /d c:\xampp\htdocs\nine
git add .
git status
git commit -m "プロジェクト一式を追加"
git push origin main
pause
