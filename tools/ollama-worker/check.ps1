# Bime Ba Ma - connection check for the Ollama worker. Double-click check.bat (or it runs at the end of install).
# Checks: Ollama on this PC, the site + this PC's key, the Windows scheduled task, and the last lines of worker.log.
param([switch]$NoPause)
$ErrorActionPreference = 'Continue'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 } catch {}
$Utf8 = New-Object System.Text.UTF8Encoding($false)
function Ok([string]$m) { Write-Host ('  [OK] ' + $m) -ForegroundColor Green }
function Bad([string]$m) { Write-Host ('  [X]  ' + $m) -ForegroundColor Red }
function Tip([string]$m) { Write-Host ('       -> ' + $m) -ForegroundColor Yellow }
Write-Host ''
Write-Host '=== Bime Ba Ma - Ollama worker check ===' -ForegroundColor Cyan
$cfgFile = Join-Path $Here 'worker.config.json'
if (-not (Test-Path $cfgFile)) { Bad 'worker.config.json not found'; Tip 'Download the package again from the site (settings > smart file processing > internal system).'; if (-not $NoPause) { Read-Host 'Enter to close' }; exit 1 }
$cfg = Get-Content $cfgFile -Raw -Encoding UTF8 | ConvertFrom-Json
$Site = ([string]$cfg.site).TrimEnd('/')
$Ollama = if ($cfg.ollama) { ([string]$cfg.ollama).TrimEnd('/') } else { 'http://127.0.0.1:11434' }
Write-Host ('Site:   ' + $Site)
Write-Host ('Ollama: ' + $Ollama)
Write-Host ''

# 1) Ollama
Write-Host '1) Ollama on this PC'
$models = @(); $up = $false
try {
    $t = Invoke-WebRequest ($Ollama + '/api/tags') -UseBasicParsing -TimeoutSec 8
    $models = @(($t.Content | ConvertFrom-Json).models | ForEach-Object { $_.name }); $up = $true
    Ok 'Ollama is running'
    if ($models.Count) { Ok ('Installed models: ' + ($models -join ', ')) } else { Bad 'No model is installed'; Tip 'Open cmd and run for example:  ollama pull qwen2.5vl:7b' }
} catch { Bad 'Ollama is not running'; Tip 'Open the Ollama app (or run "ollama serve" in cmd). The worker also tries to start it by itself.' }
Write-Host ''

# 2) Site + key
Write-Host '2) Connection to the site and the key of this PC'
try {
    $body = $Utf8.GetBytes((@{ models = $models; host = $env:COMPUTERNAME; version = 'check'; ollama = $up } | ConvertTo-Json -Compress))
    $req = [System.Net.HttpWebRequest]::Create($Site + '/api/ollama_worker.php?action=ping')
    $req.Method = 'POST'; $req.Timeout = 30000; $req.ContentType = 'application/json; charset=utf-8'; $req.UserAgent = 'BimeBama-OllamaWorker/check'
    $req.Headers.Add('X-Worker-Token', [string]$cfg.token)
    $req.ContentLength = $body.Length; $s = $req.GetRequestStream(); $s.Write($body, 0, $body.Length); $s.Close()
    try { $resp = $req.GetResponse() } catch [System.Net.WebException] { $resp = $_.Exception.Response; if ($null -eq $resp) { throw } }
    $ms = New-Object System.IO.MemoryStream; $resp.GetResponseStream().CopyTo($ms); $code = [int]$resp.StatusCode; $resp.Close()
    $txt = $Utf8.GetString($ms.ToArray())
    $j = $null; try { $j = $txt | ConvertFrom-Json } catch {}
    if ($code -eq 200 -and $j -and $j.ok) { Ok ('The site accepted this PC (system: ' + $j.node + '). In the site settings it should now show "connected".') }
    elseif ($j -and $j.code -eq 'bad_token') { Bad 'The site REJECTED the key of this PC (old package: a new key was made or this system was deleted)'; Tip 'In the site: on the card of this system press "download Windows program", replace this folder with the new one and run install.bat again.' }
    elseif ($j -and $j.code -eq 'disabled') { Bad 'The site is reachable, but the internal system (Ollama) is switched OFF in the site settings'; Tip 'Site: Inquiries > Settings > smart file processing > internal system: tick "internal system on".' }
    elseif ($j -and $j.code -eq 'node_disabled') { Bad 'The site is reachable, but THIS system is switched off in the site settings'; Tip 'Tick "on" on the card of this system in the site settings.' }
    elseif ($code -eq 503 -and $txt -match 'maintenance') { Bad 'The site is in MAINTENANCE mode, so only admins can use it'; Tip 'Turn off maintenance mode in the site (security settings), or upload the latest site files: the new version lets this program work even during maintenance.' }
    elseif ($code -eq 404) { Bad ('HTTP 404: api/ollama_worker.php was not found on the site'); Tip 'Upload the latest site files (api/ollama_worker.php and api/_ollama.php) to the host.' }
    else { Bad ('Unexpected answer from the site: HTTP ' + $code); Write-Host ('       ' + $txt.Substring(0, [Math]::Min(300, $txt.Length))); Tip 'A firewall / security module on the host (or a wrong site address in worker.config.json) may block it.' }
} catch { Bad ('Cannot reach the site: ' + $_.Exception.Message); Tip 'Check the internet of this PC and the "site" address in worker.config.json (it must open in a browser).' }
Write-Host ''

# 3) Scheduled task
Write-Host '3) Windows background task'
try {
    $task = Get-ScheduledTask -TaskName 'BimeBama-Ollama-Worker' -ErrorAction Stop
    $info = $task | Get-ScheduledTaskInfo
    if ($task.State -eq 'Running') { Ok 'The worker is running in the background' }
    else { Bad ('The task exists but is not running (state: ' + $task.State + ', last result: ' + $info.LastTaskResult + ')'); Tip 'Run install.bat again, or in Task Scheduler right-click BimeBama-Ollama-Worker > Run.' }
} catch { Bad 'The background task is not installed'; Tip 'Right-click install.bat > Run as administrator.' }
Write-Host ''

# 4) Log
Write-Host '4) Last lines of worker.log'
$log = Join-Path $Here 'worker.log'
if (Test-Path $log) { Get-Content $log -Tail 8 -Encoding UTF8 | ForEach-Object { Write-Host ('       ' + $_) } } else { Write-Host '       (no log yet - the worker has not started)' }
Write-Host ''
if (-not $NoPause) { Read-Host 'Enter to close' }
