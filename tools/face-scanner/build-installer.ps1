<#
    Build a source-free transfer ZIP for a second Windows PC.

    The ZIP contains the runtime scripts and installer, but never config.json,
    faces.db, logs, Git metadata, or Python caches.

    NOTE: keep this file ASCII-only for Windows PowerShell 5.1.
#>
param(
    [string]$OutputDirectory = ""
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $here "dist"
}

$pluginFile = [IO.Path]::GetFullPath((Join-Path $here "..\..\gasf-crm.php"))
$version = "dev"
if (Test-Path $pluginFile) {
    $match = Select-String -Path $pluginFile -Pattern "^\s*\*\s*Version:\s+([0-9.]+)\s*$" | Select-Object -First 1
    if ($match) { $version = $match.Matches[0].Groups[1].Value }
}

$required = @(
    "scan.py",
    "scan-gui.py",
    "run.ps1",
    "install-task.ps1",
    "requirements.txt",
    "config.example.json",
    "README.md",
    "Install-GASFFaceScanner.ps1",
    "Install-GASFFaceScanner.cmd",
    "INSTALL-LAPTOP.txt"
)
foreach ($name in $required) {
    $path = Join-Path $here $name
    if (-not (Test-Path $path -PathType Leaf)) {
        throw "Required installer file is missing: $path"
    }
}

$stage = Join-Path ([IO.Path]::GetTempPath()) ("gasf-face-installer-" + [Guid]::NewGuid().ToString("N"))
$payload = Join-Path $stage "payload"
$zip = Join-Path ([IO.Path]::GetFullPath($OutputDirectory)) ("GASF-Face-Scanner-" + $version + ".zip")

try {
    New-Item -ItemType Directory -Path $payload -Force | Out-Null
    Copy-Item (Join-Path $here "Install-GASFFaceScanner.ps1") $stage
    Copy-Item (Join-Path $here "Install-GASFFaceScanner.cmd") $stage
    Copy-Item (Join-Path $here "INSTALL-LAPTOP.txt") $stage

    foreach ($name in @(
        "scan.py",
        "scan-gui.py",
        "run.ps1",
        "install-task.ps1",
        "requirements.txt",
        "config.example.json",
        "README.md"
    )) {
        Copy-Item (Join-Path $here $name) $payload
    }

    New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($zip)) -Force | Out-Null
    if (Test-Path $zip) { Remove-Item -LiteralPath $zip -Force }
    Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $zip -CompressionLevel Optimal
    Write-Host "Built laptop installer:"
    Write-Host "  $zip"
} finally {
    if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
