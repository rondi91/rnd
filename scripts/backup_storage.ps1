param(
    [string]$ProjectRoot = "",
    [string]$DestRoot = ""
)

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = $ProjectRoot
if (-not $root) {
    $root = Join-Path $scriptRoot ".."
}
$rootPath = Resolve-Path $root

$dest = $DestRoot
if (-not $dest) {
    $dest = Join-Path $env:USERPROFILE "Documents\\project-admin-backup"
}

$storagePath = Join-Path $rootPath "storage"
if (-not (Test-Path $storagePath)) {
    Write-Error "Folder storage tidak ditemukan: $storagePath"
    exit 1
}

New-Item -ItemType Directory -Force -Path $dest | Out-Null
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$zipPath = Join-Path $dest ("storage_" + $timestamp + ".zip")

Compress-Archive -Path (Join-Path $storagePath "*") -DestinationPath $zipPath -Force
Write-Output ("Backup dibuat: " + $zipPath)
