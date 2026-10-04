param(
    [string]$Database = "diamond",
    [string]$User = "root",
    [string]$HostName = "127.0.0.1",
    [int]$Port = 3306,
    [string]$MySqlBin = "C:\xampp\mysql\bin",
    [string]$OutputDirectory = "$PSScriptRoot\..\storage\backups",
    [int]$RetentionDays = 14
)

$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$sqlPath = Join-Path $OutputDirectory "$Database-$stamp.sql"
$zipPath = Join-Path $OutputDirectory "$Database-$stamp.zip"
$dump = Join-Path $MySqlBin "mysqldump.exe"
if (-not (Test-Path $dump)) { throw "mysqldump.exe was not found at $dump" }

$credential = Get-Credential -UserName $User -Message "MySQL credentials (password is never written to disk)"
$plainPassword = $credential.GetNetworkCredential().Password
try {
    $env:MYSQL_PWD = $plainPassword
    & $dump "--host=$HostName" "--port=$Port" "--user=$User" `
        --single-transaction --routines --triggers --events `
        --default-character-set=utf8mb4 `
        "--result-file=$sqlPath" $Database
    if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }
    Compress-Archive -Path $sqlPath -DestinationPath $zipPath -CompressionLevel Optimal
}
finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item $sqlPath -ErrorAction SilentlyContinue
    $plainPassword = $null
}

Get-ChildItem $OutputDirectory -Filter "$Database-*.zip" |
    Where-Object LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) |
    Remove-Item -Force

Write-Host "Backup created: $zipPath"
