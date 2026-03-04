# 本番（EC2）へファイルを一括アップロード
# 使い方: PowerShell で cd c:\xampp\htdocs\nine のあと .\deploy-bulk.ps1
# 鍵パスは .cursor\rules\deploy-powershell-scp.mdc に合わせてある（変更時はここを編集）

$key = "C:\Users\narak\Desktop\social9-key.pem"
$ec2 = "ec2-user@54.95.86.79"
$root = "c:\xampp\htdocs\nine"

# 送信するファイル: Local = ローカルパス, Remote = EC2 上のパス（/var/www/html/ 基準）
$files = @(
    # AI返信提案まわり（全グループチャットで利用可能）
    @{ Local = "$root\chat.php";                           Remote = "/var/www/html/" }
    @{ Local = "$root\assets\js\ai-reply-suggest.js";      Remote = "/var/www/html/assets/js/" }
    @{ Local = "$root\includes\chat\DEPENDENCIES.md";     Remote = "/var/www/html/includes/chat/" }
    @{ Local = "$root\assets\icons\line\zap.svg";          Remote = "/var/www/html/assets/icons/line/" }
    @{ Local = "$root\assets\icons\line\brain.svg";       Remote = "/var/www/html/assets/icons/line/" }
    # 今日の話題（本日のニューストピックス・興味トピックレポート）
    @{ Local = "$root\api\ai.php";                           Remote = "/var/www/html/api/" }
    @{ Local = "$root\config\app.php";                       Remote = "/var/www/html/config/" }
    @{ Local = "$root\includes\today_topics_helper.php";      Remote = "/var/www/html/includes/" }
    @{ Local = "$root\cron\ai_today_topics_evening.php";     Remote = "/var/www/html/cron/" }
    @{ Local = "$root\cron\run_today_topics_morning_per_user.php"; Remote = "/var/www/html/cron/" }
    @{ Local = "$root\cron\send_today_topics_to_user.php";   Remote = "/var/www/html/cron/" }
    @{ Local = "$root\cron\run_today_topics_test_once.php";  Remote = "/var/www/html/cron/" }
    # AI秘書・PDF読み取りまわり（先頭で送ると確実）
    @{ Local = "$root\api\ai.php";                  Remote = "/var/www/html/api/" }
    @{ Local = "$root\includes\ai_file_reader.php"; Remote = "/var/www/html/includes/" }
    @{ Local = "$root\includes\chat\scripts.php";   Remote = "/var/www/html/includes/chat/" }
    @{ Local = "$root\api\notifications.php";           Remote = "/var/www/html/api/" }
    @{ Local = "$root\assets\js\error-collector.js";    Remote = "/var/www/html/assets/js/" }
    @{ Local = "$root\assets\js\push-notifications.js"; Remote = "/var/www/html/assets/js/" }
    @{ Local = "$root\includes\chat\scripts.php";      Remote = "/var/www/html/includes/chat/" }
    @{ Local = "$root\assets\css\chat-main.css";       Remote = "/var/www/html/assets/css/" }
    @{ Local = "$root\admin\storage_billing.php";    Remote = "/var/www/html/admin/" }
    # 料金表まわり
    @{ Local = "$root\config\ai_config.php";          Remote = "/var/www/html/config/" }
    @{ Local = "$root\includes\ai_billing_rates.php"; Remote = "/var/www/html/includes/" }
    @{ Local = "$root\admin\ai_usage.php";            Remote = "/var/www/html/admin/" }
)

foreach ($f in $files) {
    Write-Host "Uploading $($f.Local) -> $ec2`:$($f.Remote)"
    scp -i $key $f.Local "${ec2}:$($f.Remote)"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "scp failed for $($f.Local)"
        exit $LASTEXITCODE
    }
}
Write-Host "Done. " -NoNewline
Write-Host ($files.Count) "file(s) uploaded."
