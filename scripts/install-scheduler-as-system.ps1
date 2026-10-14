<#
    Registers CIMS's scheduler as a SYSTEM task, and then proves it works.

    Run this from an ADMINISTRATOR PowerShell prompt. It needs elevation because
    only an administrator can create a task that runs as the machine account.

    Running as SYSTEM is what makes the scheduler unattended: it keeps posting to
    the ledger, taking backups and raising alerts when nobody is logged in, which
    a per-user task does not. SYSTEM needs no stored password, so nothing has to
    be typed or kept anywhere.

    The script does not stop at "task created". A task that fires every minute
    and silently fails to reach PHP looks exactly like one that works, so this
    waits for the application's own heartbeat to move before saying it is done.
#>

$ErrorActionPreference = 'Stop'

$TaskName  = 'CIMS scheduler'
$Project   = 'D:\Genova\PROJECTS\CIMS_FIXED_SOURCE\CIMS'
$Launcher  = Join-Path $Project 'scripts\schedule-run.vbs'
$Heartbeat = Join-Path $Project 'storage\framework\scheduler-heartbeat'

function Say($m) { Write-Host $m }
function Ok($m)  { Write-Host "  OK    $m" -ForegroundColor Green }
function Bad($m) { Write-Host "  FAIL  $m" -ForegroundColor Red }

# ---------------------------------------------------------------- elevation
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Bad 'This needs an administrator prompt.'
    Say ''
    Say '  Right-click PowerShell, choose "Run as administrator", then:'
    Say "    & '$PSCommandPath'"
    exit 1
}
Ok "running as administrator ($($identity.Name))"

# ------------------------------------------------------- what SYSTEM will need
if (-not (Test-Path $Launcher)) { Bad "launcher not found: $Launcher"; exit 1 }
Ok "launcher present"

$drive = (Get-Item $Project).PSDrive.Name + ':'
$disk  = Get-CimInstance Win32_LogicalDisk | Where-Object { $_.DeviceID -eq $drive }
if ($disk -and $disk.DriveType -eq 4) {
    Bad "$drive is a mapped network drive. SYSTEM cannot see mapped drives, so the"
    Say '        task would fail every minute. Move the project to a local disk, or'
    Say '        use a UNC path and grant the machine account access to the share.'
    exit 1
}
Ok "$drive is a local disk, which SYSTEM can reach"

# ------------------------------------------------------------- the task itself
Say ''
Say "Registering '$TaskName' to run as SYSTEM..."

$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$Launcher`""

# Repeat every minute, forever. A blank repetition duration is what makes it
# indefinite -- schtasks defaults can otherwise stop the task after a day.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1)).Repetition

$principalSystem = New-ScheduledTaskPrincipal -UserId 'SYSTEM' `
    -LogonType ServiceAccount -RunLevel Highest

# A till on a UPS is still a till, so it must keep running on battery. And with
# IgnoreNew, one stuck run must not be allowed to block the next three days of
# them, so the limit comes down to something this can never legitimately exceed.
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -MultipleInstances IgnoreNew

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principalSystem -Settings $settings `
    -Description 'Runs Laravel''s scheduler once a minute: ledger posting, backups, alerts, webhooks.' | Out-Null

$task = Get-ScheduledTask -TaskName $TaskName
Ok "registered, running as $($task.Principal.UserId) ($($task.Principal.LogonType))"
Ok "repeats every $($task.Triggers[0].Repetition.Interval), duration '$($task.Triggers[0].Repetition.Duration)' (blank means forever)"

# ------------------------------------------------------------- prove it works
Say ''
Say 'Waiting for the application to show proof of life...'

$was = if (Test-Path $Heartbeat) { Get-Content $Heartbeat -Raw } else { '' }
Start-ScheduledTask -TaskName $TaskName

$moved = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 4
    if (Test-Path $Heartbeat) {
        $now = Get-Content $Heartbeat -Raw
        if ($now -ne $was) { $moved = $true; break }
    }
}

$info = Get-ScheduledTaskInfo -TaskName $TaskName
Say ''
if ($moved) {
    Ok "the scheduler ran as SYSTEM and the application answered"
    Say "        heartbeat: $(Get-Content $Heartbeat -Raw)"
} else {
    Bad 'the task fired but the application never answered.'
    Say '        The task runs wscript, which starts PHP and returns immediately, so'
    Say "        a task result of 0 does not mean PHP ran. Check that SYSTEM can"
    Say '        reach the database and that the paths inside schedule-run.vbs are'
    Say '        still right.'
}
Say "        last run: $($info.LastRunTime)  result: $($info.LastTaskResult)"
Say "        next run: $($info.NextRunTime)"

Say ''
Say 'To remove it later:'
Say "    schtasks /delete /tn ""$TaskName"" /f"

if (-not $moved) { exit 1 }
