<#
    install-task.ps1 - register (or remove) the Windows Scheduled Task that runs
    the face scanner on a schedule.

        # every 30 minutes while you are logged in (the default):
        powershell -ExecutionPolicy Bypass -File install-task.ps1

        # a different cadence:
        powershell -ExecutionPolicy Bypass -File install-task.ps1 -IntervalMinutes 15

        # see what it would do without touching anything:
        powershell -ExecutionPolicy Bypass -File install-task.ps1 -WhatIf

        # tear it down:
        powershell -ExecutionPolicy Bypass -File install-task.ps1 -Uninstall

    The task runs run.ps1, which does an incremental learn and one scan pass. It
    is set to run only while a user is logged on: the whole design is that this
    private machine polls out when it happens to be awake, and if it is off the
    CRM simply gets no suggestions. It needs no stored password and no admin
    rights for that logon-only mode.

    Because the recognition backend and the biometric vectors live on THIS
    machine, do not register this task on the web host or any shared box. Run
    "python scan.py --check" first - a green preflight is the precondition.

    NOTE: keep this file ASCII-only. Windows PowerShell 5.1 reads .ps1 as the
    legacy code page, and a stray Unicode dash breaks parsing.
#>
param(
    [int]$IntervalMinutes = 30,
    [string]$TaskName = "GASF Face Scanner",
    [switch]$Uninstall,
    [switch]$WhatIf
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$runner = Join-Path $here "run.ps1"

if ($Uninstall) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        if ($WhatIf) { "Would unregister task '$TaskName'."; return }
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        "Removed scheduled task '$TaskName'."
    } else {
        "No scheduled task named '$TaskName' to remove."
    }
    return
}

if (-not (Test-Path $runner)) { throw "run.ps1 not found next to this script at $runner" }
if ($IntervalMinutes -lt 1) { throw "IntervalMinutes must be at least 1." }

# The action: powershell running run.ps1, headless, from this folder.
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$runner`"" `
    -WorkingDirectory $here

# Trigger: at logon, then repeat forever at the chosen interval.
$trigger = New-ScheduledTaskTrigger -AtLogOn
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)).Repetition

# Run as the current user, only when logged on - no stored password needed.
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive

# Don't stack runs; let a slow pass finish; allow it on battery.
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable

if ($WhatIf) {
    "Would register task '$TaskName':"
    "  every $IntervalMinutes minute(s), at logon, while '$env:USERNAME' is signed in"
    "  action: powershell -File ""$runner"""
    return
}

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings `
    -Description "Polls germantampabay.com for library photos and suggests who is in them. Biometric vectors stay on this machine (faces.db). Suggestions only, never tags." `
    -Force | Out-Null

"Registered scheduled task '$TaskName' - every $IntervalMinutes minute(s), while '$env:USERNAME' is logged on."
"Check it under Task Scheduler, or run one pass now with:  powershell -ExecutionPolicy Bypass -File run.ps1"
"Output is appended to scan.log in this folder."
