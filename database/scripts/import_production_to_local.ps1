# 本番 DB をローカル（Docker）に取り込む
# 使い方: .\database\scripts\import_production_to_local.ps1 [ダンプファイルのパス]
# 例: .\database\scripts\import_production_to_local.ps1 .\database\backup\social9_production_20260304_120000.sql
#     .sql.gz の場合は先に解凍して .sql にしてください。

param(
    [Parameter(Mandatory = $false)]
    [string]$DumpPath
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
if (-not $DumpPath) {
    $backupDir = Join-Path $ProjectRoot "database\backup"
    $latest = Get-ChildItem -Path $backupDir -Filter "social9_production_*.sql" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $latest) {
        Write-Host "ダンプファイルを指定してください。"
        Write-Host "例: .\database\scripts\import_production_to_local.ps1 .\database\backup\social9_production_YYYYMMDD_HHMMSS.sql"
        Write-Host "本番からエクスポート手順は DOCS\PRODUCTION_TO_LOCAL_DB.md を参照してください。"
        exit 1
    }
    $DumpPath = $latest.FullName
    Write-Host "最新のダンプを使用します: $DumpPath"
}

if (-not (Test-Path $DumpPath)) {
    Write-Host "ファイルが見つかりません: $DumpPath"
    exit 1
}

$absoluteDump = (Resolve-Path $DumpPath).Path
$containerDump = "/tmp/import_dump.sql"

Set-Location $ProjectRoot

try {
    Write-Host "ローカル Docker の social9 をリセットしています..."
    docker compose exec -T db mysql -u root -psocial9_dev -e "DROP DATABASE IF EXISTS social9; CREATE DATABASE social9 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>$null

    Write-Host "ダンプをコンテナにコピーしてインポートしています..."
    docker compose cp $absoluteDump "db:${containerDump}"
    docker compose exec db bash -c "mysql -u root -psocial9_dev social9 < ${containerDump}"
    docker compose exec -T db rm -f "${containerDump}" 2>$null

    Write-Host "完了しました。http://localhost:9000/ で本番と同じデータを利用できます。"
} finally {
    Set-Location -Path $ProjectRoot
}
