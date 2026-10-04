param(
    [Parameter(Mandatory = $true)][string]$Backup,
    [string]$Database = "diamond",
    [string]$User = "root",
    [string]$HostName = "127.0.0.1",
    [int]$Port = 3306,
    [string]$MySqlBin = "C:\xampp\mysql\bin"
)

$ErrorActionPreference = "Stop"
if (-not (Test-Path $Backup)) { throw "Backup does not exist: $Backup" }
$mysql = Join-Path $MySqlBin "mysql.exe"
if (-not (Test-Path $mysql)) { throw "mysql.exe was not found at $mysql" }
if ((Read-Host "Type RESTORE-$Database to replace data in '$Database'") -ne "RESTORE-$Database") {
    throw "Restore cancelled."
}

$temporaryDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "diamond-restore-$([guid]::NewGuid())"
$temporary = Join-Path $temporaryDirectory "restore.sql"
$credential = Get-Credential -UserName $User -Message "MySQL credentials (password is never written to disk)"
$plainPassword = $credential.GetNetworkCredential().Password
try {
    New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
    Expand-Archive -Path $Backup -DestinationPath $temporaryDirectory -Force
    $expanded = Get-ChildItem $temporaryDirectory -Filter "*.sql" |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($null -eq $expanded) { throw "The archive contains no SQL dump." }
    Move-Item $expanded.FullName $temporary -Force
    $env:MYSQL_PWD = $plainPassword
    Get-Content -Raw $temporary | & $mysql "--host=$HostName" "--port=$Port" "--user=$User" $Database
    if ($LASTEXITCODE -ne 0) { throw "mysql restore failed with exit code $LASTEXITCODE" }
}
finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item $temporaryDirectory -Recurse -Force -ErrorAction SilentlyContinue
    $plainPassword = $null
}

Write-Host "Restore completed. Run: php artisan migrate:status"
