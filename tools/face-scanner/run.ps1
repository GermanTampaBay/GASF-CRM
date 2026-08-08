<#
    run.ps1 - one scan pass, wrapped for a Scheduled Task.

    This is what the task calls. It finds Python, runs the scanner from this
    folder, and appends a timestamped line to scan.log so a headless run leaves
    a trail. Run it by hand to see exactly what the task will do:

        powershell -ExecutionPolicy Bypass -File run.ps1
        powershell -ExecutionPolicy Bypass -File run.ps1 -ScanArgs "--status"

    By default it does "--learn --quiet": learn incrementally from newly tagged
    photos (cheap past the watermark), then scan whatever is waiting.

    NOTE: keep this file ASCII-only. Windows PowerShell 5.1 reads .ps1 as the
    legacy code page, and a stray Unicode dash breaks parsing.
#>
param(
    [string]$ScanArgs = "--learn --quiet"
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

# Prefer the private environment made by the laptop installer. Developer
# checkouts retain the existing py/python fallbacks.
$venvPython = Join-Path $here ".venv\Scripts\python.exe"
if (Test-Path $venvPython) {
    $py = $venvPython
    $pyArgs = @("scan.py")
} elseif (Get-Command py -ErrorAction SilentlyContinue) {
    $py = "py"
    $pyArgs = @("-3", "scan.py")
} else {
    $py = "python"
    $pyArgs = @("scan.py")
}

$stamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
$log = Join-Path $here "scan.log"
"[$stamp] run.ps1 $ScanArgs" | Out-File -FilePath $log -Append -Encoding utf8

# Split the args string into tokens the launcher understands, then run, folding
# stdout+stderr into the log. Pipe through Out-File -Encoding utf8 rather than a
# `*>>` file redirect: under Windows PowerShell 5.1 that redirect writes UTF-16
# and mangles the log. $LASTEXITCODE still reflects the native command.
$extra = $ScanArgs.Split(" ", [StringSplitOptions]::RemoveEmptyEntries)
& $py @pyArgs @extra 2>&1 | Out-File -FilePath $log -Append -Encoding utf8
$code = $LASTEXITCODE

"[$stamp] exit $code" | Out-File -FilePath $log -Append -Encoding utf8
exit $code
