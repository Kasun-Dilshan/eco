# Build production-ready dist folder for hosting
# Usage: .\build.ps1

$ErrorActionPreference = "Stop"
$Root = $PSScriptRoot
$Dist = Join-Path $Root "dist"

Write-Host "Building dist folder..." -ForegroundColor Cyan

$DistTmp = Join-Path $Root "dist-tmp"
if (Test-Path $DistTmp) {
    Remove-Item $DistTmp -Recurse -Force -ErrorAction SilentlyContinue
}
New-Item -ItemType Directory -Path $DistTmp | Out-Null
$DistTarget = $DistTmp

$ExcludeDirs = @('.git', 'dist', 'dist-tmp', 'logs', 'node_modules')
# Include config.php so a full zip restore brings the site back online.
# For a secrets-free package, delete config.php from dist after build and use config.example.php.
$ExcludeFiles = @('debug.log', 'build.ps1', '.gitignore', 'eco-wealth-portal-dist.zip')

$Items = Get-ChildItem -Path $Root -Force
foreach ($item in $Items) {
    if ($item.Name -in $ExcludeDirs) { continue }
    if ($item.Name -in $ExcludeFiles) { continue }

    $dest = Join-Path $DistTarget $item.Name
    if ($item.PSIsContainer) {
        if ($item.Name -eq 'uploads') {
            New-Item -ItemType Directory -Path $dest -Force | Out-Null
            $htaccess = Join-Path $item.FullName '.htaccess'
            if (Test-Path $htaccess) {
                Copy-Item $htaccess (Join-Path $dest '.htaccess')
            }
            continue
        }
        if ($item.Name -eq 'admin') {
            robocopy $item.FullName $dest /E /XF temp /NFL /NDL /NJH /NJS /NC /NS | Out-Null
            if ($LASTEXITCODE -ge 8) { throw "Failed to copy admin folder" }
            continue
        }
        robocopy $item.FullName $dest /E /NFL /NDL /NJH /NJS /NC /NS | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "Failed to copy $($item.Name)" }
    } else {
        Copy-Item $item.FullName $dest
    }
}

# Empty writable directories for production
New-Item -ItemType Directory -Path (Join-Path $DistTarget "logs") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $DistTarget "uploads") -Force | Out-Null
$uploadsHtaccess = Join-Path $DistTarget "uploads\.htaccess"
if (-not (Test-Path $uploadsHtaccess)) {
    @"
Order deny,allow
Deny from all
<Files ~ "\.(jpg|jpeg|png|pdf)$">
    Allow from all
</Files>
"@ | Set-Content $uploadsHtaccess -Encoding ASCII
}

# Production config template (not local secrets)
Copy-Item (Join-Path $Root "config.example.php") (Join-Path $DistTarget "config.example.php")

# Install production PHP dependencies
Write-Host "Installing Composer dependencies (production)..."
Push-Location $DistTarget
try {
    $prevErrorAction = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) { throw "composer install failed (exit $LASTEXITCODE)" }
} finally {
    $ErrorActionPreference = $prevErrorAction
    Pop-Location
}

# Deployment instructions
@"
EcoWealth Portal - Deployment Package
=====================================

IMPORTANT: Upload the FILES INSIDE this folder into your domain document root
(e.g. public_html), NOT as a nested subfolder named dist or eco-wealth-portal-dist.

1. In SiteGround File Manager / FTP, open the folder for agreement.serendibgroups.com
   (usually public_html for that domain).

2. Upload ALL files and folders from this package into that folder
   (index.php, config.php, admin/, css/, includes/, vendor/, templates/, etc.).

3. Confirm these exist at the domain root after upload:
   - index.php
   - config.php
   - admin/index.php
   - css/style.css

4. Set folder permissions (writable by PHP):
   - uploads/
   - logs/

5. PHP requirements: 8.0+ with PDO MySQL extension
   (Site Tools > Dev > PHP Manager).

6. Open https://agreement.serendibgroups.com/ — you should see the investor form.

Built: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
"@ | Set-Content (Join-Path $DistTarget "DEPLOY.txt") -Encoding UTF8

if (Test-Path $Dist) {
    Remove-Item $Dist -Recurse -Force -ErrorAction SilentlyContinue
}
if (Test-Path $Dist) {
    Get-ChildItem $Dist | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    Get-ChildItem $DistTmp | ForEach-Object {
        $dest = Join-Path $Dist $_.Name
        if (Test-Path $dest) { Remove-Item $dest -Recurse -Force -ErrorAction SilentlyContinue }
        Move-Item $_.FullName $dest -Force
    }
    Remove-Item $DistTmp -Recurse -Force -ErrorAction SilentlyContinue
} else {
    try {
        Rename-Item $DistTmp $Dist
    } catch {
        New-Item -ItemType Directory -Path $Dist -Force | Out-Null
        Get-ChildItem $DistTmp | Move-Item -Destination $Dist -Force
        Remove-Item $DistTmp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

$ZipPath = Join-Path $Root "eco-wealth-portal-dist.zip"
if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

Write-Host "Creating zip (frontend + backend in one archive)..." -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($Dist, $ZipPath)

Write-Host ""
Write-Host "Done! dist folder is ready at:" -ForegroundColor Green
Write-Host "  $Dist"
Write-Host "Zip for SiteGround upload:" -ForegroundColor Green
Write-Host "  $ZipPath"
Write-Host ""
Write-Host "In SiteGround File Manager, open public_html and extract this zip there." -ForegroundColor Yellow
Write-Host "Files (index.php, admin/, css/, vendor/, etc.) must sit at the domain root, not inside a nested folder." -ForegroundColor Yellow
