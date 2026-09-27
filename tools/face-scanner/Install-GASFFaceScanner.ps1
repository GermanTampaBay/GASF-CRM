<#
    Install the GASF face scanner on a Windows PC without Git or a source tree.

    Run this script from the extracted installer ZIP. It installs into the
    current user's LocalAppData, creates an isolated Python environment,
    installs Ollama and the local vision model, writes scanner configuration,
    and creates user-level shortcuts.

    NOTE: keep this file ASCII-only for Windows PowerShell 5.1.
#>
[CmdletBinding()]
param(
    [string]$InstallDirectory = (Join-Path $env:LOCALAPPDATA "GASF Face Scanner"),
    [string]$SiteUrl = "https://germantampabay.com",
    [Security.SecureString]$ScannerKey,
    # Empty means "choose from this PC's GPU": see Select-CaptionModel.
    [string]$CaptionModel = "",
    [int]$CaptionTimeout = 300,
    [switch]$InstallScheduledTask,
    [int]$TaskIntervalMinutes = 30,
    [switch]$NoDesktopShortcut,
    [switch]$SkipModelPull,
    [switch]$SkipPreflight,
    [switch]$ValidateOnly
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version 2
# Set by Resolve-PayloadRoot below: payload\ as built, or the script's own
# folder when the ZIP was extracted flat.
$payload = $null
$requiredPayload = @(
    "scan.py",
    "scan-gui.py",
    "run.ps1",
    "install-task.ps1",
    "requirements.txt",
    "config.example.json",
    "README.md"
)

function Resolve-PayloadRoot([string]$ScriptRoot, [string[]]$RequiredFiles) {
    # The ZIP holds payload\ beside this script, but extracting "flat" (or
    # copying the files by hand) puts them next to it instead. Accept either,
    # so long as every required file is actually there.
    foreach ($candidate in @((Join-Path $ScriptRoot "payload"), $ScriptRoot)) {
        if (-not (Test-Path $candidate -PathType Container)) { continue }
        $complete = $true
        foreach ($name in $RequiredFiles) {
            if (-not (Test-Path (Join-Path $candidate $name) -PathType Leaf)) { $complete = $false; break }
        }
        if ($complete) { return $candidate }
    }
    return $null
}

function Invoke-Native(
    [string]$FilePath,
    [string[]]$Arguments,
    [string]$Description
) {
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed with exit code $LASTEXITCODE."
    }
}

function Get-PlainText([Security.SecureString]$Secure) {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Test-Python([string]$Candidate) {
    if ([string]::IsNullOrWhiteSpace($Candidate) -or -not (Test-Path $Candidate -PathType Leaf)) {
        return $null
    }
    & $Candidate -c "import struct,sys; assert sys.version_info >= (3, 11); assert struct.calcsize('P') * 8 == 64" 2>$null
    if ($LASTEXITCODE -eq 0) { return $Candidate }
    return $null
}

function Resolve-Python {
    $launcher = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($launcher) {
        $resolved = & $launcher.Source -3 -c "import sys; print(sys.executable)" 2>$null
        if ($LASTEXITCODE -eq 0 -and $resolved) {
            $found = Test-Python ([string]@($resolved)[-1])
            if ($found) { return $found }
        }
    }

    $command = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($command) {
        $found = Test-Python $command.Source
        if ($found) { return $found }
    }

    $roots = @(
        (Join-Path $env:LOCALAPPDATA "Programs\Python"),
        (Join-Path $env:ProgramFiles "Python")
    )
    foreach ($root in $roots) {
        if (-not (Test-Path $root -PathType Container)) { continue }
        $candidates = Get-ChildItem -Path $root -Filter python.exe -Recurse -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending
        foreach ($candidate in $candidates) {
            $found = Test-Python $candidate.FullName
            if ($found) { return $found }
        }
    }
    return $null
}

function Select-CaptionModel {
    # qwen3-vl:30b writes better captions but needs about 20 GB between the
    # GPU and system RAM. Measured on a 16 GB RTX 4070 Ti SUPER it takes about
    # 85 seconds a photo with 30% of it spilled to the CPU; on a smaller GPU
    # it would run up against the 300-second caption timeout and every
    # caption would fail. Below 16 GB, or with no NVIDIA GPU, use the 8b
    # model, which fits entirely on the card and takes seconds.
    $smi = Get-Command nvidia-smi.exe -ErrorAction SilentlyContinue
    if ($smi) {
        $lines = & $smi.Source --query-gpu=memory.total --format=csv,noheader,nounits 2>$null
        $best = 0
        foreach ($line in @($lines)) {
            $mib = 0
            if ([int]::TryParse(([string]$line).Trim(), [ref]$mib) -and $mib -gt $best) { $best = $mib }
        }
        if ($best -ge 15000) {
            Write-Host "GPU memory: $best MiB. Using the larger caption model."
            return "qwen3-vl:30b"
        }
        if ($best -gt 0) { Write-Host "GPU memory: $best MiB. Using the smaller caption model." }
    } else {
        Write-Host "No NVIDIA GPU found. Using the smaller caption model."
    }
    return "qwen3-vl:8b"
}

function Resolve-Ollama {
    $command = Get-Command ollama.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    foreach ($candidate in @(
        (Join-Path $env:LOCALAPPDATA "Programs\Ollama\ollama.exe"),
        (Join-Path $env:ProgramFiles "Ollama\ollama.exe")
    )) {
        if (Test-Path $candidate -PathType Leaf) { return $candidate }
    }
    return $null
}

function Require-Winget {
    $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
    if (-not $winget) {
        throw "winget is required. Install 'App Installer' from Microsoft Store, then rerun this installer."
    }
    return $winget.Source
}

function Ensure-OllamaApi([string]$OllamaExe) {
    try {
        Invoke-RestMethod -Uri "http://127.0.0.1:11434/api/tags" -Method Get -TimeoutSec 5 | Out-Null
        Write-Host "Ollama API is reachable."
        return
    } catch {
        Write-Host "Starting the local Ollama API..."
    }

    $process = Start-Process -FilePath $OllamaExe -ArgumentList "serve" -WindowStyle Hidden -PassThru
    for ($i = 0; $i -lt 45; $i++) {
        Start-Sleep -Seconds 1
        try {
            Invoke-RestMethod -Uri "http://127.0.0.1:11434/api/tags" -Method Get -TimeoutSec 5 | Out-Null
            Write-Host "Ollama API is reachable."
            return
        } catch {}
    }
    if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id -Force }
    throw "Ollama did not start on http://127.0.0.1:11434."
}

function Read-ExistingKey([string]$ConfigPath) {
    if (-not (Test-Path $ConfigPath -PathType Leaf)) { return "" }
    try {
        $config = Get-Content -Raw -Path $ConfigPath | ConvertFrom-Json
        if ($config -and $config.key) { return [string]$config.key }
    } catch {
        throw "Existing config.json is invalid. Repair or remove it, then rerun."
    }
    return ""
}

function Write-Config(
    [string]$ConfigPath,
    [string]$Url,
    [string]$Key,
    [string]$Model,
    [int]$TimeoutSec
) {
    $existing = $null
    if (Test-Path $ConfigPath -PathType Leaf) {
        $existing = Get-Content -Raw -Path $ConfigPath | ConvertFrom-Json
    }
    $engine = "auto"
    $tolerance = $null
    $discoveryTolerance = $null
    $discoveryLimit = 1000
    $calibrationTargetPrecision = 0.99
    $calibrationMinSamples = 30
    if ($existing) {
        $engineProperty = $existing.PSObject.Properties["engine"]
        $toleranceProperty = $existing.PSObject.Properties["tolerance"]
        $discoveryToleranceProperty = $existing.PSObject.Properties["discovery_tolerance"]
        $discoveryLimitProperty = $existing.PSObject.Properties["discovery_limit"]
        $calibrationTargetProperty = $existing.PSObject.Properties["calibration_target_precision"]
        $calibrationMinimumProperty = $existing.PSObject.Properties["calibration_min_samples"]
        if ($engineProperty -and $engineProperty.Value) { $engine = [string]$engineProperty.Value }
        if ($toleranceProperty) { $tolerance = $toleranceProperty.Value }
        if ($discoveryToleranceProperty) { $discoveryTolerance = $discoveryToleranceProperty.Value }
        if ($discoveryLimitProperty -and $discoveryLimitProperty.Value) {
            $discoveryLimit = [int]$discoveryLimitProperty.Value
        }
        if ($calibrationTargetProperty -and $calibrationTargetProperty.Value) {
            $calibrationTargetPrecision = [double]$calibrationTargetProperty.Value
        }
        if ($calibrationMinimumProperty -and $calibrationMinimumProperty.Value) {
            $calibrationMinSamples = [int]$calibrationMinimumProperty.Value
        }
    }
    $config = [ordered]@{
        url = $Url.TrimEnd("/")
        key = $Key
        engine = $engine
        tolerance = $tolerance
        discovery_tolerance = $discoveryTolerance
        discovery_limit = $discoveryLimit
        calibration_target_precision = $calibrationTargetPrecision
        calibration_min_samples = $calibrationMinSamples
        caption_model = $Model
        caption_prompt = "Write a concise, factual archive description that prioritizes the event, activity, setting, and clearly visible details."
        caption_url = "http://127.0.0.1:11434/api/generate"
        caption_timeout = $TimeoutSec
        caption_passes = 2
        caption_num_ctx = 8192
    }
    $json = $config | ConvertTo-Json -Depth 8
    $utf8 = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($ConfigPath, $json + [Environment]::NewLine, $utf8)
}

function New-ScannerShortcut([string]$ShortcutPath, [string]$Pythonw, [string]$AppDirectory) {
    $parent = Split-Path -Parent $ShortcutPath
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($ShortcutPath)
    $shortcut.TargetPath = $Pythonw
    $shortcut.Arguments = "`"$(Join-Path $AppDirectory 'scan-gui.py')`""
    $shortcut.WorkingDirectory = $AppDirectory
    $shortcut.Description = "GASF local photo face tagging and captioning"
    $shortcut.Save()
}

$payload = Resolve-PayloadRoot -ScriptRoot $PSScriptRoot -RequiredFiles $requiredPayload
if (-not $payload) {
    # Report against the folder the files were evidently meant to be in.
    $looked = Join-Path $PSScriptRoot "payload"
    if (-not (Test-Path $looked -PathType Container)) { $looked = $PSScriptRoot }
    $missing = @($requiredPayload | Where-Object { -not (Test-Path (Join-Path $looked $_) -PathType Leaf) })
    throw ("Installer files are incomplete: " + ($missing -join ", ") + " not found in '" + $PSScriptRoot + "\payload' or next to this script. Extract the entire ZIP before running.")
}
if ($CaptionTimeout -lt 15 -or $CaptionTimeout -gt 300) {
    throw "CaptionTimeout must be between 15 and 300 seconds."
}
if ($TaskIntervalMinutes -lt 1) { throw "TaskIntervalMinutes must be at least 1." }

if ([string]::IsNullOrWhiteSpace($CaptionModel)) {
    $CaptionModel = Select-CaptionModel
}

if ($ValidateOnly) {
    Write-Host "Installer bundle is complete."
    Write-Host "Install directory: $InstallDirectory"
    Write-Host "Python package: Python.Python.3.13"
    Write-Host "Ollama package: Ollama.Ollama"
    Write-Host "Caption model: $CaptionModel"
    return
}

Write-Host ""
Write-Host "GASF Face Scanner laptop installer"
Write-Host "Install directory: $InstallDirectory"
Write-Host ""

$python = Resolve-Python
if (-not $python) {
    $winget = Require-Winget
    Write-Host "Installing 64-bit Python 3.13..."
    Invoke-Native -FilePath $winget -Arguments @(
        "install", "--id", "Python.Python.3.13", "-e", "--source", "winget",
        "--scope", "user", "--silent", "--accept-package-agreements",
        "--accept-source-agreements", "--disable-interactivity"
    ) -Description "Python installation"
    $python = Resolve-Python
    if (-not $python) { throw "Python installed, but a supported python.exe could not be located." }
}
Write-Host "Python: $python"

$ollama = Resolve-Ollama
if (-not $ollama) {
    $winget = Require-Winget
    Write-Host "Installing Ollama..."
    Invoke-Native -FilePath $winget -Arguments @(
        "install", "--id", "Ollama.Ollama", "-e", "--source", "winget",
        "--scope", "user", "--silent", "--accept-package-agreements",
        "--accept-source-agreements", "--disable-interactivity"
    ) -Description "Ollama installation"
    $ollama = Resolve-Ollama
    if (-not $ollama) { throw "Ollama installed, but ollama.exe could not be located." }
}
Write-Host "Ollama: $ollama"
Ensure-OllamaApi -OllamaExe $ollama

New-Item -ItemType Directory -Path $InstallDirectory -Force | Out-Null
foreach ($name in $requiredPayload) {
    Copy-Item -LiteralPath (Join-Path $payload $name) -Destination (Join-Path $InstallDirectory $name) -Force
}
[IO.File]::WriteAllText(
    (Join-Path $InstallDirectory ".gasf-face-scanner-install"),
    "Installed by Install-GASFFaceScanner.ps1" + [Environment]::NewLine,
    (New-Object Text.UTF8Encoding($false))
)

$venv = Join-Path $InstallDirectory ".venv"
$venvPython = Join-Path $venv "Scripts\python.exe"
$venvPythonw = Join-Path $venv "Scripts\pythonw.exe"
if (-not (Test-Path $venvPython -PathType Leaf)) {
    Write-Host "Creating private Python environment..."
    Invoke-Native -FilePath $python -Arguments @("-m", "venv", $venv) -Description "Virtual environment creation"
}
if (-not (Test-Path $venvPython -PathType Leaf)) {
    throw "The private Python environment was not created correctly."
}

Write-Host "Installing scanner Python packages..."
Invoke-Native -FilePath $venvPython -Arguments @("-m", "pip", "install", "--upgrade", "pip") -Description "pip upgrade"
Invoke-Native -FilePath $venvPython -Arguments @(
    "-m", "pip", "install", "-r", (Join-Path $InstallDirectory "requirements.txt")
) -Description "Core dependency installation"
Invoke-Native -FilePath $venvPython -Arguments @(
    "-m", "pip", "install", "insightface", "onnxruntime"
) -Description "Face backend installation"

if (-not $SkipModelPull) {
    Write-Host "Downloading Ollama model $CaptionModel. This is several GB and may take a while..."
    Invoke-Native -FilePath $ollama -Arguments @("pull", $CaptionModel) -Description "Ollama model download"
}

$configPath = Join-Path $InstallDirectory "config.json"
$plainKey = Read-ExistingKey $configPath
if ($ScannerKey) {
    $plainKey = Get-PlainText $ScannerKey
}
if ([string]::IsNullOrWhiteSpace($plainKey)) {
    $secure = Read-Host -Prompt "Paste the scanner key from WordPress (hidden)" -AsSecureString
    $plainKey = Get-PlainText $secure
}
if ([string]::IsNullOrWhiteSpace($plainKey) -or -not $plainKey.StartsWith("gasf_face_")) {
    throw "A valid scanner key beginning with 'gasf_face_' is required."
}
Write-Config -ConfigPath $configPath -Url $SiteUrl -Key $plainKey -Model $CaptionModel -TimeoutSec $CaptionTimeout
Write-Host "Scanner configuration written."

$startMenu = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\GASF Face Scanner.lnk"
New-ScannerShortcut -ShortcutPath $startMenu -Pythonw $venvPythonw -AppDirectory $InstallDirectory
if (-not $NoDesktopShortcut) {
    $desktop = [Environment]::GetFolderPath("Desktop")
    New-ScannerShortcut -ShortcutPath (Join-Path $desktop "GASF Face Scanner.lnk") -Pythonw $venvPythonw -AppDirectory $InstallDirectory
}

if ($InstallScheduledTask) {
    Write-Host "Registering scheduled scan task..."
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $InstallDirectory "install-task.ps1") `
        -IntervalMinutes $TaskIntervalMinutes
    if ($LASTEXITCODE -ne 0) { throw "Scheduled task registration failed with exit code $LASTEXITCODE." }
}

if (-not $SkipPreflight) {
    Write-Host "Running scanner preflight. The first face-model download may take a few minutes..."
    Invoke-Native -FilePath $venvPython -Arguments @(
        (Join-Path $InstallDirectory "scan.py"), "--check"
    ) -Description "Scanner preflight"
}

Write-Host ""
Write-Host "Installation complete."
Write-Host "Launch 'GASF Face Scanner' from the Desktop or Start Menu."
Write-Host "Local biometric database: $(Join-Path $InstallDirectory 'faces.db')"
