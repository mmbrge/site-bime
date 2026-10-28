# Bime Ba Ma - Ollama worker (Windows PowerShell 5.1+)
# Asks the site for the next AI job, runs it on the local Ollama and sends the answer back.
# Nothing is opened to the internet: this PC only makes outgoing HTTPS requests to the site.
# Settings: worker.config.json (site, token, ollama). Log: worker.log
$ErrorActionPreference = 'Stop'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Version = '1.0'
$cfg = Get-Content (Join-Path $Here 'worker.config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$Site = ([string]$cfg.site).TrimEnd('/')
$Token = [string]$cfg.token
$Ollama = if ($cfg.ollama) { ([string]$cfg.ollama).TrimEnd('/') } else { 'http://127.0.0.1:11434' }
$LogFile = Join-Path $Here 'worker.log'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 } catch {}
$Utf8 = New-Object System.Text.UTF8Encoding($false)

function Log([string]$m) {
    $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m
    try {
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
        if ((Get-Item $LogFile).Length -gt 5MB) { Move-Item $LogFile ($LogFile + '.old') -Force }
    } catch {}
}
# HTTP with raw UTF-8 bytes in and out (no encoding guesses, no JSON size limits)
function Http([string]$method, [string]$url, [byte[]]$body, [hashtable]$headers, [int]$timeoutSec) {
    $req = [System.Net.HttpWebRequest]::Create($url)
    $req.Method = $method
    $req.Timeout = $timeoutSec * 1000
    $req.ReadWriteTimeout = $timeoutSec * 1000
    $req.UserAgent = 'BimeBama-OllamaWorker/' + $Version
    $req.ContentType = 'application/json; charset=utf-8'
    if ($headers) { foreach ($k in $headers.Keys) { $req.Headers.Add($k, [string]$headers[$k]) } }
    if ($null -ne $body) {
        $req.ContentLength = $body.Length
        $s = $req.GetRequestStream(); $s.Write($body, 0, $body.Length); $s.Close()
    }
    try { $resp = $req.GetResponse() }
    catch [System.Net.WebException] { $resp = $_.Exception.Response; if ($null -eq $resp) { throw } }
    $ms = New-Object System.IO.MemoryStream
    $resp.GetResponseStream().CopyTo($ms)
    $code = [int]$resp.StatusCode
    $resp.Close()
    return @{ code = $code; text = $Utf8.GetString($ms.ToArray()) }
}
function Json($obj) { return $Utf8.GetBytes(($obj | ConvertTo-Json -Compress -Depth 6)) }
function OllamaUp { try { return (Http 'GET' ($Ollama + '/api/tags') $null @{} 5).code -eq 200 } catch { return $false } }
function OllamaModels {
    try { return @(((Http 'GET' ($Ollama + '/api/tags') $null @{} 10).text | ConvertFrom-Json).models | ForEach-Object { $_.name }) } catch { return @() }
}
# Ollama is not running (e.g. nobody logged in yet): start "ollama serve" in the background
function StartOllama {
    $exe = $null
    foreach ($p in @((Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'), 'C:\Program Files\Ollama\ollama.exe')) { if (Test-Path $p) { $exe = $p; break } }
    if (-not $exe) { $c = Get-Command ollama -ErrorAction SilentlyContinue; if ($c) { $exe = $c.Source } }
    if (-not $exe) { Log 'ollama.exe not found'; return }
    Log ('starting ' + $exe + ' serve')
    Start-Process -FilePath $exe -ArgumentList 'serve' -WindowStyle Hidden
    Start-Sleep -Seconds 10
}

Log ('worker ' + $Version + ' started; site=' + $Site + ' ollama=' + $Ollama)
$H = @{ 'X-Worker-Token' = $Token }
$fails = 0
while ($true) {
    try {
        $up = OllamaUp
        if (-not $up) { StartOllama; $up = OllamaUp }
        $hb = Json @{ models = @(if ($up) { OllamaModels } else { @() }); host = $env:COMPUTERNAME; version = $Version; ollama = $up }
        if (-not $up) {
            # Ollama is down: only report status, take no job
            $r = Http 'POST' ($Site + '/api/ollama_worker.php?action=ping') $hb $H 30
            Log ('ollama is not responding (site HTTP ' + $r.code + ')'); Start-Sleep -Seconds 30; continue
        }
        $r = Http 'POST' ($Site + '/api/ollama_worker.php?action=next') $hb $H 45
        if ($r.code -ne 200) { Log ('next: HTTP ' + $r.code + ' ' + $r.text); Start-Sleep -Seconds 20; continue }
        $fails = 0
        $job = ($r.text | ConvertFrom-Json).job
        if ($null -eq $job) { continue }
        $p = Http 'GET' ($Site + '/api/ollama_worker.php?action=payload&id=' + $job.id) $null $H 120
        if ($p.code -ne 200) { Log ('payload ' + $job.id + ': HTTP ' + $p.code); continue }
        $t0 = Get-Date
        $res = Http 'POST' ($Ollama + '/api/chat') ($Utf8.GetBytes($p.text)) @{} 1800
        $ms = [int]((Get-Date) - $t0).TotalMilliseconds
        if ($res.code -eq 200) {
            $o = $res.text | ConvertFrom-Json
            $done = @{ id = $job.id; ok = $true; text = [string]$o.message.content; ms = $ms; tokens = ([int]$o.prompt_eval_count + [int]$o.eval_count) }
        } else {
            $done = @{ id = $job.id; ok = $false; error = ('HTTP ' + $res.code + ': ' + $res.text.Substring(0, [Math]::Min(300, $res.text.Length))); ms = $ms }
        }
        $d = Http 'POST' ($Site + '/api/ollama_worker.php?action=done') (Json $done) $H 300
        Log ('job ' + $job.id + ' (' + $job.purpose + ', ' + $job.model + '): ' + $(if ($done.ok) { 'ok' } else { $done.error }) + ', ' + $ms + ' ms, site HTTP ' + $d.code)
    } catch {
        $fails++
        Log ('error: ' + $_.Exception.Message)
        Start-Sleep -Seconds ([Math]::Min(60, 5 * $fails))
    }
}
