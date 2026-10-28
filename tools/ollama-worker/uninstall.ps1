# Removes the worker scheduled task (Ollama itself stays installed)
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Start-Process powershell.exe -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -File "' + $MyInvocation.MyCommand.Path + '"'); exit }
Stop-ScheduledTask -TaskName 'BimeBama-Ollama-Worker' -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName 'BimeBama-Ollama-Worker' -Confirm:$false -ErrorAction SilentlyContinue
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -like '*worker.ps1*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Write-Host 'Removed.' -ForegroundColor Green
Read-Host 'Enter to close'
