@echo off
chcp 65001 >nul
echo social9.jp を EC2 に強制向けする hosts 設定
echo.
powershell -Command "Start-Process powershell -ArgumentList '-ExecutionPolicy Bypass -File \"%~dp0setup-hosts-ec2.ps1\" add' -Verb RunAs"
pause
