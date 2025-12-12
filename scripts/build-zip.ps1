# iKnowAviation Gamification - Build ZIP (Windows / PowerShell)
# Creates a WordPress-installable ZIP from /plugin contents only.

$ErrorActionPreference = "Stop"

# Repo root is one level above /scripts
$RepoRoot  = Resolve-Path (Join-Path $PSScriptRoot "..")
$PluginDir = Join-Path $RepoRoot "plugin"
$MainFile  = Join-Path $PluginDir "iknowaviation-gamification.php"
$DistDir   = Join-Path $RepoRoot "dist"

if (!(Test-Path $PluginDir)) { throw "Missing folder: $PluginDir" }
if (!(Test-Path $MainFile))  { throw "Missing main plugin file: $MainFile" }

# Extract version from: define( 'IKA_GAM_PLUGIN_VERSION', 'x.y.z' );
$php = Get-Content $MainFile -Raw
$match = [regex]::Match($php, "define\(\s*'IKA_GAM_PLUGIN_VERSION'\s*,\s*'([^']+)'\s*\)\s*;")
$version = if ($match.Success) { $match.Groups[1].Value } else { "0.0.0" }

New-Item -ItemType Directory -Force -Path $DistDir | Out-Null

$ZipName = "iknowaviation-gamification-$version.zip"
$ZipPath = Join-Path $DistDir $ZipName

if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

# IMPORTANT: zip the CONTENTS of /plugin (not the /plugin folder itself)
Push-Location $PluginDir
try {
  Compress-Archive -Path * -DestinationPath $ZipPath -Force
}
finally {
  Pop-Location
}

Write-Host "Built: $ZipPath"
