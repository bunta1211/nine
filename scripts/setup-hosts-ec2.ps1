# social9.jp を EC2 に強制向けする hosts 設定
# 管理者権限で実行してください

$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
$ec2Ip = "54.95.86.79"
$entries = @(
    "$ec2Ip social9.jp",
    "$ec2Ip www.social9.jp"
)

$action = $args[0]  # "add" or "remove"

 function Add-HostsEntry {
    $content = Get-Content $hostsPath -Raw -ErrorAction Stop
    $marker = "# Social9 EC2 (auto)"
    if ($content -match [regex]::Escape($marker)) {
        Write-Host "既に設定済みです。" -ForegroundColor Yellow
        return
    }
    $add = "`n`n$marker`n" + ($entries -join "`n") + "`n"
    Add-Content -Path $hostsPath -Value $add -Force
    Write-Host "hosts に EC2 を追加しました。" -ForegroundColor Green
    Write-Host "  social9.jp -> $ec2Ip" -ForegroundColor Gray
    Write-Host "ブラウザを再起動して http://social9.jp を開いてください。" -ForegroundColor Cyan
}

function Remove-HostsEntry {
    $lines = Get-Content $hostsPath -ErrorAction Stop
    $marker = "# Social9 EC2 (auto)"
    $ec2Escaped = [regex]::Escape($ec2Ip)
    $inBlock = $false
    $newLines = @()
    foreach ($line in $lines) {
        if ($line -match [regex]::Escape($marker)) { $inBlock = $true; continue }
        if ($inBlock) {
            if ($line -match "^\s*$ec2Escaped\s+" -or $line -match "^\s*$") { continue }
        }
        $inBlock = $false
        $newLines += $line
    }
    $newLines | Set-Content $hostsPath -Encoding ASCII
    Write-Host "hosts から EC2 設定を削除しました。" -ForegroundColor Green
}

# 管理者チェック
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "管理者権限が必要です。右クリック→管理者として実行してください。" -ForegroundColor Red
    exit 1
}

if ($action -eq "remove") {
    Remove-HostsEntry
} else {
    Add-HostsEntry
}

# DNS キャッシュクリア
ipconfig /flushdns | Out-Null
Write-Host "DNS キャッシュをクリアしました。" -ForegroundColor Gray
