# Docker Desktop を PowerShell でダウンロードしてインストール
# 管理者として実行することを推奨: 右クリック → 「管理者として実行」

$url = "https://desktop.docker.com/win/main/amd64/Docker%20Desktop%20Installer.exe"
$out = Join-Path $PSScriptRoot "DockerDesktopInstaller.exe"

Write-Host "1. Downloading Docker Desktop (this may take a few minutes)..."
try {
    Invoke-WebRequest -Uri $url -OutFile $out -UseBasicParsing
} catch {
    Write-Host "Download failed: $_"
    exit 1
}

$size = (Get-Item $out).Length / 1MB
Write-Host "   Downloaded: $([math]::Round($size, 2)) MB to $out"

Write-Host "2. Installing Docker Desktop (quiet mode)..."
Start-Process -FilePath $out -ArgumentList "install", "--quiet" -Wait -Verb RunAs

Write-Host "3. Done. You may need to restart Windows or log off/on for WSL2."
Write-Host "   Start Docker Desktop from the Start menu when ready."
