param(
    [string]$GeeklogRoot = 'Y:\geeklog\2_2_2',
    [string]$BackupRoot = 'E:\Codex\CodexVerificationTemp\happa-xpost-backups'
)
$ErrorActionPreference = 'Stop'
$env:TEMP = 'G:\temp'; $env:TMP = 'G:\temp'; $env:TMPDIR = 'G:\temp'
$sourceRoot = $PSScriptRoot
if (-not (Test-Path -LiteralPath (Join-Path $GeeklogRoot 'public_html\lib-common.php'))) {
    throw 'Geeklog root was not found.'
}
$backup = Join-Path $BackupRoot (Get-Date -Format 'yyyyMMdd-HHmmss-fff')
$files = @()
foreach ($area in @('plugins\xpost', 'public_html\admin\plugins\xpost')) {
    $files += Get-ChildItem -LiteralPath (Join-Path $sourceRoot $area) -File -Recurse
}
# Preserve existing plugin files before replacing them. Never copy credentials or histories.
foreach ($file in $files) {
    $relative = $file.FullName.Substring($sourceRoot.Length).TrimStart('\', '/')
    $target = Join-Path $GeeklogRoot $relative
    if (Test-Path -LiteralPath $target) {
        $backupFile = Join-Path $backup $relative
        New-Item -ItemType Directory -Path (Split-Path $backupFile -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $target -Destination $backupFile
    }
}
foreach ($file in $files) {
    $relative = $file.FullName.Substring($sourceRoot.Length).TrimStart('\', '/')
    $target = Join-Path $GeeklogRoot $relative
    New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
    # Stage beside the destination, then replace, avoiding partially written PHP files.
    $staged = $target + '.deploy-' + [guid]::NewGuid().ToString('N')
    Copy-Item -LiteralPath $file.FullName -Destination $staged
    Move-Item -LiteralPath $staged -Destination $target -Force
    if ((Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash) {
        throw "Deployed file hash mismatch: $relative"
    }
    Write-Output "Verified $relative"
}
Write-Output 'Files deployed. Install Happa X投稿 from Geeklog admin > Plugins; no X posts are made by installation.'
if (Test-Path -LiteralPath $backup) { Write-Output "Previous files: $backup" }
