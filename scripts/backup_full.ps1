param(
    [string]$ProjectRoot = "",
    [string]$DestRoot = "",
    [switch]$IncludeVendor
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

$items = @("storage", "src", "public", "composer.json", "composer.lock", "scripts")
if ($IncludeVendor -and (Test-Path (Join-Path $rootPath "vendor"))) {
    $items += "vendor"
}

$paths = @()
foreach ($item in $items) {
    if (Test-Path (Join-Path $rootPath $item)) {
        $paths += $item
    }
}
if ($paths.Count -eq 0) {
    Write-Error "Tidak ada item yang bisa di-backup."
    exit 1
}

New-Item -ItemType Directory -Force -Path $dest | Out-Null
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$zipPath = Join-Path $dest ("project-admin_" + $timestamp + ".zip")

Push-Location $rootPath
try {
    Compress-Archive -Path $paths -DestinationPath $zipPath -Force
} finally {
    Pop-Location
}

Write-Output ("Backup lengkap dibuat: " + $zipPath)
