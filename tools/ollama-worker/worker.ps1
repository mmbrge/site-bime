# Bime Ba Ma - Ollama worker (Windows PowerShell 5.1+)
# Asks the site for the next AI job, runs it on the local Ollama and sends the answer back.
# Nothing is opened to the internet: this PC only makes outgoing HTTPS requests to the site.
# Settings: worker.config.json (site, token, ollama). Log: worker.log
$ErrorActionPreference = 'Stop'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Version = '1.2'
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

# Chat answers are streamed: the growing text goes to the site about once a second (Hooshix shows it as typing)
function ChatStream($jobId, [string]$payload) {
    $body = $Utf8.GetBytes(($payload -replace '"stream":false', '"stream":true'))
    $req = [System.Net.HttpWebRequest]::Create($Ollama + '/api/chat')
    $req.Method = 'POST'; $req.Timeout = 1800000; $req.ReadWriteTimeout = 900000; $req.ContentType = 'application/json; charset=utf-8'
    $req.ContentLength = $body.Length; $s = $req.GetRequestStream(); $s.Write($body, 0, $body.Length); $s.Close()
    try { $resp = $req.GetResponse() } catch [System.Net.WebException] { $resp = $_.Exception.Response; if ($null -eq $resp) { throw } }
    $code = [int]$resp.StatusCode
    $rd = New-Object System.IO.StreamReader($resp.GetResponseStream(), $Utf8)
    if ($code -ne 200) { $t = $rd.ReadToEnd(); $rd.Close(); $resp.Close(); return @{ code = $code; text = $t } }
    $sb = New-Object System.Text.StringBuilder; $tok = 0; $last = [DateTime]::MinValue; $sent = 0; $err = ''
    while ($null -ne ($line = $rd.ReadLine())) {
        if ($line.Trim() -eq '') { continue }
        $o = $null; try { $o = $line | ConvertFrom-Json } catch { continue }
        if ($o.error) { $err = [string]$o.error; break }
        if ($o.message -and $o.message.content) { [void]$sb.Append([string]$o.message.content) }
        if ($o.done) { $tok = [int]$o.prompt_eval_count + [int]$o.eval_count; break }
        if (((Get-Date) - $last).TotalMilliseconds -ge 700 -and $sb.Length -gt $sent) {
            try { [void](Http 'POST' ($Site + '/api/ollama_worker.php?action=part') (Json @{ id = $jobId; text = $sb.ToString() }) $H 15) } catch {}
            $last = Get-Date; $sent = $sb.Length
        }
    }
    $rd.Close(); $resp.Close()
    if ($err) { return @{ code = 500; text = $err } }
    return @{ code = 200; text = $sb.ToString(); tokens = $tok }
}
# Load the models the site needs into memory ahead of time (first answer without loading delay)
$warmed = @{}
function Warm($models) {
    foreach ($m in @($models)) {
        if (-not $m) { continue }
        if ($warmed.ContainsKey($m) -and ((Get-Date) - $warmed[$m]).TotalMinutes -lt 10) { continue }
        try {
            $w = Http 'POST' ($Ollama + '/api/generate') (Json @{ model = [string]$m; keep_alive = -1 }) @{} 600
            if ($w.code -eq 200) { if (-not $warmed.ContainsKey($m)) { Log ('model ' + $m + ' is loaded and kept in memory') } } else { Log ('could not load ' + $m + ': HTTP ' + $w.code) }
        } catch { Log ('could not load ' + $m + ': ' + $_.Exception.Message) }
        $warmed[$m] = Get-Date
    }
}

Log ('worker ' + $Version + ' started; site=' + $Site + ' ollama=' + $Ollama)
$H = @{ 'X-Worker-Token' = $Token }
$fails = 0
$linked = $false   # آخرین وضعیتِ اتصال به سایت (فقط تغییرِ وضعیت در گزارش نوشته می‌شود)
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
        if ($r.code -ne 200) {
            $linked = $false
            if ($r.code -eq 503 -and $r.text -match 'maintenance') { Log 'site is in maintenance mode (only admins can use it) - waiting...' } else { Log ('next: HTTP ' + $r.code + ' ' + $r.text) }
            Start-Sleep -Seconds 20; continue
        }
        if (-not $linked) { Log 'connected to the site - waiting for jobs'; $linked = $true }
        $fails = 0
        $nx = $r.text | ConvertFrom-Json
        $job = $nx.job
        if ($null -eq $job) { if ($nx.warm) { Warm $nx.warm }; continue }
        $p = Http 'GET' ($Site + '/api/ollama_worker.php?action=payload&id=' + $job.id) $null $H 120
        if ($p.code -ne 200) { Log ('payload ' + $job.id + ': HTTP ' + $p.code); continue }
        $t0 = Get-Date
        if ($job.purpose -eq 'chat') { $res = ChatStream $job.id $p.text } else { $res = Http 'POST' ($Ollama + '/api/chat') ($Utf8.GetBytes($p.text)) @{} 1800 }
        $ms = [int]((Get-Date) - $t0).TotalMilliseconds
        if ($res.code -eq 200 -and $job.purpose -eq 'chat') {
            $done = @{ id = $job.id; ok = $true; text = [string]$res.text; ms = $ms; tokens = [int]$res.tokens }
        } elseif ($res.code -eq 200) {
            $o = $res.text | ConvertFrom-Json
            $done = @{ id = $job.id; ok = $true; text = [string]$o.message.content; ms = $ms; tokens = ([int]$o.prompt_eval_count + [int]$o.eval_count) }
        } else {
            $done = @{ id = $job.id; ok = $false; error = ('HTTP ' + $res.code + ': ' + $res.text.Substring(0, [Math]::Min(300, $res.text.Length))); ms = $ms }
        }
        $d = Http 'POST' ($Site + '/api/ollama_worker.php?action=done') (Json $done) $H 300
        Log ('job ' + $job.id + ' (' + $job.purpose + ', ' + $job.model + '): ' + $(if ($done.ok) { 'ok' } else { $done.error }) + ', ' + $ms + ' ms, site HTTP ' + $d.code)
    } catch {
        $fails++; $linked = $false
        Log ('error: ' + $_.Exception.Message)
        Start-Sleep -Seconds ([Math]::Min(60, 5 * $fails))
    }
}
