@echo off
chcp 65001 >nul
echo hosts から EC2 設定を削除
echo.
powershell -Command "Start-Process powershell -ArgumentList '-ExecutionPolicy Bypass -File \"%~dp0setup-hosts-ec2.ps1\" remove' -Verb RunAs"
pause
