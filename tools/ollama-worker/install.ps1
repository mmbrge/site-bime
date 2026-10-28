# Installs the worker as a Windows scheduled task: starts with Windows (even before anyone logs in)
# and restarts automatically if it stops. Run once: right-click > "Run with PowerShell" (as administrator).
$ErrorActionPreference = 'Stop'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Task = 'BimeBama-Ollama-Worker'
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Start-Process powershell.exe -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -File "' + $MyInvocation.MyCommand.Path + '"')
    exit
}
if (-not (Test-Path (Join-Path $Here 'worker.config.json'))) { Write-Host 'worker.config.json not found (download the package again from the site settings).' -ForegroundColor Red; Read-Host 'Enter'; exit 1 }
$user = "$env:USERDOMAIN\$env:USERNAME"
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + (Join-Path $Here 'worker.ps1') + '"') -WorkingDirectory $Here
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
Write-Host ''
Write-Host 'To run even when nobody is logged in, Windows needs the password of this user:' $user -ForegroundColor Cyan
Write-Host '(If this user has no password, just press Enter: the worker will then start when you log in.)'
$sec = Read-Host 'Windows password' -AsSecureString
$plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
try {
    if ($plain -ne '') {
        Register-ScheduledTask -TaskName $Task -Action $action -Trigger @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn -User $user)) -Settings $settings -User $user -Password $plain -RunLevel Highest -Force | Out-Null
        Write-Host 'Installed: starts with Windows (before login) and restarts if it stops.' -ForegroundColor Green
    } else { throw 'no password' }
} catch {
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Highest
    Register-ScheduledTask -TaskName $Task -Action $action -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $user) -Settings $settings -Principal $principal -Force | Out-Null
    Write-Host 'Installed: starts when this user logs in (turn on automatic login for unattended restarts).' -ForegroundColor Yellow
    if ($plain -ne '') { Write-Host 'Windows did not accept that password - run install again to make it start before login.' -ForegroundColor Yellow }
}
Start-ScheduledTask -TaskName $Task
Write-Host 'Started. In the site settings the status should turn to "connected" within a minute. Log:' (Join-Path $Here 'worker.log')
Read-Host 'Enter to close'
