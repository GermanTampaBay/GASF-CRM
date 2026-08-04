<#
    install-ollama.ps1 - end-to-end setup for local captioning on Windows.

    What it does:
      1) installs Ollama (via winget) if missing
      2) starts/verifies the local Ollama API
      3) pulls a vision model (default: llava:7b)
      4) installs Python dependencies for scan.py
      5) writes/updates config.json for scanner + captions
      6) runs scan.py --check

    Example:
      powershell -ExecutionPolicy Bypass -File install-ollama.ps1 `
        -ScannerKey "gasf_face_xxxxx" `
        -SiteUrl "https://germantampabay.com" `
        -CaptionModel "llava:7b"

    NOTE: keep this file ASCII-only. Windows PowerShell 5.1 reads .ps1 as the
    legacy code page, and non-ASCII punctuation can break parsing.
#>
param(
    [string]$SiteUrl = "https://germantampabay.com",
    [string]$ScannerKey = "",
    [string]$CaptionModel = "llava:7b",
    [string]$CaptionUrl = "http://127.0.0.1:11434/api/generate",
    [int]$CaptionTimeout = 120
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

function Get-PlainTextFromSecureString([Security.SecureString]$Secure) {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Resolve-OllamaExe {
    $cmd = Get-Command ollama -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $candidate = Join-Path $env:LOCALAPPDATA "Programs\Ollama\ollama.exe"
    if (Test-Path $candidate) { return $candidate }
    return $null
}

function Ensure-OllamaInstalled {
    $ollama = Resolve-OllamaExe
    if ($ollama) {
        Write-Host "Ollama found at: $ollama"
        return $ollama
    }

    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
        throw "Ollama is not installed and winget is not available. Install App Installer from Microsoft Store, then rerun."
    }

    Write-Host "Installing Ollama with winget..."
    winget install --id Ollama.Ollama -e --accept-package-agreements --accept-source-agreements --silent

    $ollama = Resolve-OllamaExe
    if (-not $ollama) {
        throw "Ollama install completed but ollama.exe was not found. Open Ollama once from Start Menu, then rerun."
    }
    Write-Host "Ollama installed at: $ollama"
    return $ollama
}

function Ensure-OllamaApi([string]$OllamaExe) {
    try {
        Invoke-RestMethod -Uri "http://127.0.0.1:11434/api/tags" -Method Get -TimeoutSec 5 | Out-Null
        Write-Host "Ollama API already reachable."
        return
    } catch {}

    Write-Host "Starting Ollama API..."
    $proc = Start-Process -FilePath $OllamaExe -ArgumentList "serve" -WindowStyle Hidden -PassThru

    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        try {
            Invoke-RestMethod -Uri "http://127.0.0.1:11434/api/tags" -Method Get -TimeoutSec 5 | Out-Null
            Write-Host "Ollama API is reachable."
            return
        } catch {}
    }

    if ($proc -and -not $proc.HasExited) {
        Stop-Process -Id $proc.Id -Force
    }
    throw "Ollama API did not start on 127.0.0.1:11434."
}

function Ensure-PythonDeps {
    if (-not (Get-Command py -ErrorAction SilentlyContinue)) {
        throw "Python launcher 'py' not found. Install Python 3.13+ first, then rerun."
    }
    Write-Host "Installing scanner Python dependencies..."
    py -3 -m pip install -r requirements.txt
    py -3 -m pip install insightface onnxruntime
}

function Update-Config(
    [string]$ConfigPath,
    [string]$Url,
    [string]$Key,
    [string]$Model,
    [string]$ApiUrl,
    [int]$TimeoutSec
) {
    $cfg = @{}
    if (Test-Path $ConfigPath) {
        $raw = Get-Content -Raw -Path $ConfigPath
        if ($raw.Trim()) {
            $cfg = $raw | ConvertFrom-Json -AsHashtable
        }
    }

    $cfg["url"] = $Url.TrimEnd("/")
    $cfg["key"] = $Key
    if (-not $cfg.ContainsKey("engine")) { $cfg["engine"] = "auto" }
    if (-not $cfg.ContainsKey("tolerance")) { $cfg["tolerance"] = $null }
    $cfg["caption_model"] = $Model
    $cfg["caption_prompt"] = "Write one short, neutral description of this photo for a club archive. Describe visible people, activity, setting, and notable objects. Do not guess names or identities."
    $cfg["caption_url"] = $ApiUrl
    $cfg["caption_timeout"] = $TimeoutSec

    $json = $cfg | ConvertTo-Json -Depth 8
    Set-Content -Path $ConfigPath -Value $json -Encoding UTF8
    Write-Host "Updated config: $ConfigPath"
}

if ([string]::IsNullOrWhiteSpace($ScannerKey)) {
    $secure = Read-Host -Prompt "Paste scanner key from wp-admin (input hidden)" -AsSecureString
    $ScannerKey = Get-PlainTextFromSecureString $secure
}

if ([string]::IsNullOrWhiteSpace($ScannerKey)) {
    throw "ScannerKey is required."
}

if ($CaptionTimeout -lt 15 -or $CaptionTimeout -gt 300) {
    throw "CaptionTimeout must be 15..300 seconds."
}

$ollamaExe = Ensure-OllamaInstalled
Ensure-OllamaApi -OllamaExe $ollamaExe

Write-Host "Pulling model: $CaptionModel"
& $ollamaExe pull $CaptionModel

Ensure-PythonDeps

$configPath = Join-Path $here "config.json"
Update-Config -ConfigPath $configPath -Url $SiteUrl -Key $ScannerKey -Model $CaptionModel -ApiUrl $CaptionUrl -TimeoutSec $CaptionTimeout

Write-Host "Running scanner preflight..."
py -3 scan.py --check

Write-Host ""
Write-Host "Done. Run one pass now with:"
Write-Host "  py -3 scan.py --learn"
