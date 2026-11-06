@echo off
title BimeBama Assistant Installer
net session >nul 2>&1
if not "%errorLevel%"=="0" goto elevate
set "SELF=%~f0"
set "SRC=%~dp0."
set "WORK=%TEMP%\bimebama_setup"
if not exist "%WORK%" mkdir "%WORK%"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=[IO.File]::ReadAllLines($env:SELF); foreach($t in 'BR','IN'){ $o=@($s | Where-Object { $_.StartsWith('::'+$t+'::') } | ForEach-Object { $_.Substring($t.Length+4) }); [IO.File]::WriteAllLines((Join-Path $env:WORK ($t+'.ps1')), $o, (New-Object Text.UTF8Encoding($true))) }"
powershell -NoProfile -ExecutionPolicy Bypass -File "%WORK%\IN.ps1" -SourceDir "%SRC%"
echo.
pause
exit /b
:elevate
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
exit /b
::BR::# BimeBama local bridge (v2.1.0) - runs elevated, listens on 127.0.0.1:8765 only
::BR::$ErrorActionPreference = 'Continue'
::BR::$Port = 8765
::BR::$Server = '__BB_SERVER__'
::BR::$ExtOrigin = 'chrome-extension://__BB_EXT_ID__'
::BR::$ExtDir = 'C:\bimebama\extension'
::BR::$BridgeVersion = '2.1.0'
::BR::$DefaultRoutes = @('10.0.0.0/8', '172.16.0.0/12', '20.0.0.0/8')
::BR::try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
::BR::
::BR::$mutex = New-Object System.Threading.Mutex($false, 'Global\BimeBamaAtiBridge')
::BR::if (-not $mutex.WaitOne(0)) { exit }
::BR::
::BR::$listener = New-Object System.Net.HttpListener
::BR::$listener.Prefixes.Add("http://127.0.0.1:$Port/")
::BR::try { $listener.Start() } catch { exit }
::BR::
::BR::function Send-Json($res, $obj, [int]$code = 200) {
::BR::    $json = $obj | ConvertTo-Json -Compress -Depth 4
::BR::    $buf = [System.Text.Encoding]::UTF8.GetBytes($json)
::BR::    $res.StatusCode = $code
::BR::    $res.ContentType = 'application/json; charset=utf-8'
::BR::    $res.ContentLength64 = $buf.Length
::BR::    $res.OutputStream.Write($buf, 0, $buf.Length)
::BR::    $res.Close()
::BR::}
::BR::
::BR::function Get-ConnInfo($name) {
::BR::    Get-NetIPAddress -InterfaceAlias $name -AddressFamily IPv4 -ErrorAction SilentlyContinue | Select-Object -First 1
::BR::}
::BR::
::BR::function Invoke-Rasdial([string[]]$parts) {
::BR::    $q = ($parts | ForEach-Object { '"' + ($_ -replace '"', '\"') + '"' }) -join ' '
::BR::    $psi = New-Object System.Diagnostics.ProcessStartInfo
::BR::    $psi.FileName = 'rasdial.exe'
::BR::    $psi.Arguments = $q
::BR::    $psi.UseShellExecute = $false
::BR::    $psi.CreateNoWindow = $true
::BR::    $p = [System.Diagnostics.Process]::Start($psi)
::BR::    if (-not $p.WaitForExit(40000)) { try { $p.Kill() } catch {} }
::BR::}
::BR::
::BR::# Routes that go through the VPN (from the panel; only IPv4 CIDR accepted)
::BR::function Get-Routes($data) {
::BR::    $out = @()
::BR::    if ($data -and $data.routes) { foreach ($r in @($data.routes)) { $x = [string]$r; if ($x -match '^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$') { $out += $x } } }
::BR::    if ($out.Count -eq 0) { $out = $DefaultRoutes }
::BR::    return $out
::BR::}
::BR::
::BR::# Before dialing: tell Windows NOT to use the VPN as default gateway (split tunnel). Works for user and all-user phonebooks.
::BR::function Set-SplitConfig($connName) {
::BR::    try { Set-VpnConnection -Name $connName -SplitTunneling $true -Force -ErrorAction Stop; return $true } catch {}
::BR::    try { Set-VpnConnection -Name $connName -SplitTunneling $true -AllUserConnection -Force -ErrorAction Stop; return $true } catch {}
::BR::    return $false
::BR::}
::BR::
::BR::function Test-NeedFix($info, $routes) {
::BR::    if (-not $info) { return $false }
::BR::    foreach ($p in @('0.0.0.0/0', '0.0.0.0/1', '128.0.0.0/1')) {
::BR::        if (Get-NetRoute -DestinationPrefix $p -InterfaceIndex $info.InterfaceIndex -ErrorAction SilentlyContinue) { return $true }
::BR::    }
::BR::    $ifc = Get-NetIPInterface -InterfaceIndex $info.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue
::BR::    if ($ifc -and $ifc.InterfaceMetric -lt 1000) { return $true }
::BR::    foreach ($r in $routes) { if (-not (Get-NetRoute -DestinationPrefix $r -InterfaceIndex $info.InterfaceIndex -ErrorAction SilentlyContinue)) { return $true } }
::BR::    return $false
::BR::}
::BR::
::BR::# After dialing: remove every default route of the VPN interface (on-link routes have gateway 0.0.0.0, so the old
::BR::# "route delete 0.0.0.0 mask 0.0.0.0 <vpnIP>" never matched and ALL traffic went into the tunnel), push the
::BR::# interface metric very high (normal sites and DNS keep using the real network) and add only the insurer routes.
::BR::function Fix-SplitTunnel($connName, $routes) {
::BR::    $ppp = Get-ConnInfo $connName
::BR::    if (-not $ppp) { return $false }
::BR::    $vpnIP = $ppp.IPAddress
::BR::    $ifIndex = $ppp.InterfaceIndex
::BR::    foreach ($p in @('0.0.0.0/0', '0.0.0.0/1', '128.0.0.0/1')) {
::BR::        Get-NetRoute -DestinationPrefix $p -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue | Remove-NetRoute -Confirm:$false -ErrorAction SilentlyContinue
::BR::    }
::BR::    Get-NetRoute -DestinationPrefix '::/0' -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue | Remove-NetRoute -Confirm:$false -ErrorAction SilentlyContinue
::BR::    route delete 0.0.0.0 mask 0.0.0.0 $vpnIP 2>$null | Out-Null
::BR::    Set-NetIPInterface -InterfaceIndex $ifIndex -AddressFamily IPv4 -InterfaceMetric 5000 -ErrorAction SilentlyContinue
::BR::    Set-NetIPInterface -InterfaceIndex $ifIndex -AddressFamily IPv6 -InterfaceMetric 5000 -ErrorAction SilentlyContinue
::BR::    foreach ($r in $routes) {
::BR::        if (-not (Get-NetRoute -DestinationPrefix $r -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue)) {
::BR::            New-NetRoute -DestinationPrefix $r -InterfaceIndex $ifIndex -NextHop '0.0.0.0' -RouteMetric 1 -PolicyStore ActiveStore -ErrorAction SilentlyContinue | Out-Null
::BR::        }
::BR::    }
::BR::    $dnsServers = (Get-DnsClientServerAddress -InterfaceIndex $ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).ServerAddresses
::BR::    if ($dnsServers) {
::BR::        Get-DnsClientNrptRule -ErrorAction SilentlyContinue | Where-Object { $_.Namespace -eq '.iic.local' } | Remove-DnsClientNrptRule -Force -ErrorAction SilentlyContinue
::BR::        Add-DnsClientNrptRule -Namespace '.iic.local' -NameServers $dnsServers -ErrorAction SilentlyContinue
::BR::        foreach ($d in $dnsServers) {
::BR::            if (-not (Get-NetRoute -DestinationPrefix "$d/32" -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue)) {
::BR::                New-NetRoute -DestinationPrefix "$d/32" -InterfaceIndex $ifIndex -NextHop '0.0.0.0' -RouteMetric 1 -PolicyStore ActiveStore -ErrorAction SilentlyContinue | Out-Null
::BR::            }
::BR::        }
::BR::    }
::BR::    ipconfig /flushdns | Out-Null
::BR::    return $true
::BR::}
::BR::
::BR::function Test-Newer($a, $b) {
::BR::    try { return ([version]$a -gt [version]$b) } catch { return $false }
::BR::}
::BR::
::BR::function Get-LocalVersion {
::BR::    try { return (Get-Content "$ExtDir\manifest.json" -Raw -Encoding UTF8 | ConvertFrom-Json).version } catch { return '0' }
::BR::}
::BR::
::BR::function Update-Extension($token) {
::BR::    $r = Invoke-RestMethod -Uri "$Server/api/ext_api.php?action=latest" -TimeoutSec 20
::BR::    if (-not $r -or -not $r.version) { return @{ ok = $true; code = 'no_update' } }
::BR::    $local = Get-LocalVersion
::BR::    if (-not (Test-Newer $r.version $local)) { return @{ ok = $true; code = 'up_to_date'; version = $local } }
::BR::    if (-not $token -or $token -notmatch '^[A-Za-z0-9]{20,80}$') { return @{ ok = $false; code = 'no_token' } }
::BR::    $tmp = Join-Path $env:TEMP ('bb_upd_' + [guid]::NewGuid().ToString('N'))
::BR::    New-Item -ItemType Directory -Path $tmp | Out-Null
::BR::    try {
::BR::        $zip = "$tmp\pkg.zip"
::BR::        Invoke-WebRequest -Uri "$Server/api/ext_api.php?action=package&token=$token" -OutFile $zip -UseBasicParsing -TimeoutSec 120
::BR::        Add-Type -AssemblyName System.IO.Compression.FileSystem
::BR::        $za = [System.IO.Compression.ZipFile]::OpenRead($zip)
::BR::        $bad = $false
::BR::        try {
::BR::            foreach ($e in $za.Entries) {
::BR::                if ($e.FullName -match '(^|[\\/])\.\.([\\/]|$)' -or $e.FullName -match '^[A-Za-z]:' -or $e.FullName.StartsWith('/')) { $bad = $true }
::BR::            }
::BR::        } finally { $za.Dispose() }
::BR::        if ($bad) { return @{ ok = $false; code = 'bad_zip' } }
::BR::        $x = "$tmp\x"
::BR::        [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $x)
::BR::        $root = $x
::BR::        if (-not (Test-Path "$root\manifest.json")) {
::BR::            $sub = Get-ChildItem $root -Directory | Where-Object { Test-Path "$($_.FullName)\manifest.json" } | Select-Object -First 1
::BR::            if ($sub) { $root = $sub.FullName } else { return @{ ok = $false; code = 'no_manifest' } }
::BR::        }
::BR::        & robocopy.exe $root $ExtDir /MIR /NFL /NDL /NJH /NJS /NP /R:2 /W:1 | Out-Null
::BR::        if ($LASTEXITCODE -ge 8) { return @{ ok = $false; code = 'copy_failed' } }
::BR::        return @{ ok = $true; code = 'updated'; version = $r.version }
::BR::    } finally {
::BR::        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
::BR::    }
::BR::}
::BR::
::BR::function Handle-Request($ctx) {
::BR::    $req = $ctx.Request
::BR::    $res = $ctx.Response
::BR::    $origin = $req.Headers['Origin']
::BR::    if ($origin) {
::BR::        if ($origin -ne $ExtOrigin) { Send-Json $res @{ ok = $false; code = 'forbidden' } 403; return }
::BR::        $res.AddHeader('Access-Control-Allow-Origin', $origin)
::BR::        $res.AddHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
::BR::        $res.AddHeader('Access-Control-Allow-Headers', 'Content-Type')
::BR::        $res.AddHeader('Vary', 'Origin')
::BR::    }
::BR::    if ($req.HttpMethod -eq 'OPTIONS') { $res.StatusCode = 204; $res.Close(); return }
::BR::
::BR::    $action = $req.QueryString['action']
::BR::    # Without a valid extension Origin only the read-only GET status is allowed
::BR::    if (-not $origin -and -not ($action -eq 'status' -and $req.HttpMethod -eq 'GET')) {
::BR::        Send-Json $res @{ ok = $false; code = 'forbidden' } 403; return
::BR::    }
::BR::
::BR::    $data = @{}
::BR::    if ($req.HttpMethod -eq 'POST') {
::BR::        if ($req.ContentLength64 -gt 16384) { Send-Json $res @{ ok = $false; code = 'too_large' } 413; return }
::BR::        $reader = New-Object System.IO.StreamReader($req.InputStream, [System.Text.Encoding]::UTF8)
::BR::        $body = $reader.ReadToEnd()
::BR::        if ($body) { try { $data = $body | ConvertFrom-Json } catch { $data = @{} } }
::BR::    }
::BR::
::BR::    $connName = if ($data.connectionName) { [string]$data.connectionName } else { 'Auto_Internet' }
::BR::    if ($connName -notmatch '^[\w\- \.]{1,64}$') { Send-Json $res @{ ok = $false; code = 'bad_connection_name' } 400; return }
::BR::
::BR::    $out = @{ ok = $true; bridgeOnline = $true; connected = $false; bridgeVersion = $BridgeVersion; code = '' }
::BR::    $isPost = ($req.HttpMethod -eq 'POST')
::BR::
::BR::    $routes = Get-Routes $data
::BR::    if ($action -eq 'connect' -and $isPost) {
::BR::        $info = Get-ConnInfo $connName
::BR::        if (-not $info) {
::BR::            Set-SplitConfig $connName | Out-Null
::BR::            if ($data.username -and $data.password) {
::BR::                Invoke-Rasdial @($connName, [string]$data.username, [string]$data.password)
::BR::            } else {
::BR::                Invoke-Rasdial @($connName)
::BR::            }
::BR::            Start-Sleep -Seconds 2
::BR::            $info = Get-ConnInfo $connName
::BR::        }
::BR::        if ($info) {
::BR::            Fix-SplitTunnel $connName $routes | Out-Null
::BR::            # Windows sometimes adds the default route back a few seconds later
::BR::            Start-Sleep -Seconds 3
::BR::            if (Test-NeedFix $info $routes) { Fix-SplitTunnel $connName $routes | Out-Null }
::BR::            $out.connected = $true; $out.ip = $info.IPAddress; $out.code = 'connected'
::BR::        } else {
::BR::            $out.connected = $false; $out.code = 'connect_failed'
::BR::        }
::BR::    }
::BR::    elseif ($action -eq 'disconnect' -and $isPost) {
::BR::        Get-DnsClientNrptRule -ErrorAction SilentlyContinue | Where-Object { $_.Namespace -eq '.iic.local' } | Remove-DnsClientNrptRule -Force -ErrorAction SilentlyContinue
::BR::        Invoke-Rasdial @($connName, '/disconnect')
::BR::        $out.connected = $false; $out.code = 'disconnected'
::BR::    }
::BR::    elseif ($action -eq 'status') {
::BR::        $info = Get-ConnInfo $connName
::BR::        if ($info -and $origin -and (Test-NeedFix $info $routes)) { Fix-SplitTunnel $connName $routes | Out-Null }
::BR::        $out.connected = [bool]$info
::BR::        $out.ip = if ($info) { $info.IPAddress } else { '' }
::BR::        $out.extVersion = Get-LocalVersion
::BR::        $out.installPath = $ExtDir
::BR::    }
::BR::    elseif ($action -eq 'update' -and $isPost) {
::BR::        try { $out = Update-Extension ([string]$data.token); $out.bridgeOnline = $true } catch { $out = @{ ok = $false; code = 'update_failed' } }
::BR::    }
::BR::    else {
::BR::        $out = @{ ok = $false; code = 'unknown_action' }
::BR::    }
::BR::    Send-Json $res $out
::BR::}
::BR::
::BR::while ($listener.IsListening) {
::BR::    $ctx = $null
::BR::    try {
::BR::        $ctx = $listener.GetContext()
::BR::        Handle-Request $ctx
::BR::    } catch {
::BR::        try { if ($ctx) { Send-Json $ctx.Response @{ ok = $false; code = 'error' } 500 } } catch {}
::BR::    }
::BR::}
::IN::# BimeBama assistant installer (installments + Ati gateway; runs elevated, started by Setup-BimeBama.bat)
::IN::param([string]$SourceDir)
::IN::$ErrorActionPreference = 'Stop'
::IN::$Server = '__BB_SERVER__'
::IN::$ExtId = '__BB_EXT_ID__'
::IN::$Base = 'C:\bimebama'; $Ext = "$Base\extension"; $Srv = "$Base\service"
::IN::$Work = $PSScriptRoot
::IN::try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
::IN::
::IN::function Step($m) { Write-Host "[*] $m" -ForegroundColor Cyan }
::IN::
::IN::Step 'Stopping old bridge (if any)'
::IN::Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
::IN::    Where-Object { $_.CommandLine -like '*bimebama\service\bridge.ps1*' } |
::IN::    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
::IN::
::IN::Step "Creating $Base"
::IN::foreach ($d in @($Base, $Ext, $Srv)) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
::IN::
::IN::Step 'Writing bridge service'
::IN::$br = (Get-Content (Join-Path $Work 'BR.ps1') -Raw -Encoding UTF8)
::IN::[IO.File]::WriteAllText("$Srv\bridge.ps1", $br, (New-Object Text.UTF8Encoding($true)))
::IN::[IO.File]::WriteAllText("$Srv\run_hidden.vbs",
::IN::    "Set s = CreateObject(""WScript.Shell"")`r`ns.Run ""powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """"C:\bimebama\service\bridge.ps1"""""", 0, False`r`n",
::IN::    [Text.Encoding]::ASCII)
::IN::[IO.File]::WriteAllText("$Srv\start_task.vbs",
::IN::    "Set s = CreateObject(""WScript.Shell"")`r`ns.Run ""schtasks.exe /run /tn BimeBamaAtiBridge"", 0, False`r`n",
::IN::    [Text.Encoding]::ASCII)
::IN::
::IN::# ---- locate extension files: (1) next to the installer (2) Chrome/Edge profile by ID (3) download from server
::IN::function Find-ExtensionSource {
::IN::    if ($SourceDir -and (Test-Path (Join-Path $SourceDir 'manifest.json'))) { return $SourceDir }
::IN::    $roots = @("$env:LOCALAPPDATA\Google\Chrome\User Data", "$env:LOCALAPPDATA\Microsoft\Edge\User Data")
::IN::    foreach ($r in $roots) {
::IN::        if (-not (Test-Path $r)) { continue }
::IN::        foreach ($pf in (Get-ChildItem $r -Directory -ErrorAction SilentlyContinue)) {
::IN::            foreach ($fn in 'Secure Preferences', 'Preferences') {
::IN::                $f = Join-Path $pf.FullName $fn
::IN::                if (-not (Test-Path $f)) { continue }
::IN::                try {
::IN::                    $j = Get-Content $f -Raw -Encoding UTF8 | ConvertFrom-Json
::IN::                    $p = $j.extensions.settings.PSObject.Properties[$ExtId]
::IN::                    if ($p -and $p.Value.path -and (Test-Path (Join-Path $p.Value.path 'manifest.json'))) { return $p.Value.path }
::IN::                } catch {}
::IN::            }
::IN::        }
::IN::    }
::IN::    return $null
::IN::}
::IN::
::IN::function Install-From($src) {
::IN::    Step "Copying extension files from $src"
::IN::    if ((Resolve-Path $src).Path.TrimEnd('\') -ieq $Ext) { return }
::IN::    & robocopy.exe $src $Ext /MIR /XD '.git' /NFL /NDL /NJH /NJS /NP /R:2 /W:1 | Out-Null
::IN::    if ($LASTEXITCODE -ge 8) { throw 'copy failed' }
::IN::}
::IN::
::IN::$installed = $false
::IN::$src = Find-ExtensionSource
::IN::if ($src) { Install-From $src; $installed = $true }
::IN::else { Write-Host '[!] Extension files not found next to the installer. Extract the whole ZIP from the panel and run Setup-BimeBama.bat from inside it.' -ForegroundColor Yellow }
::IN::
::IN::Step 'Locking down folder permissions (read-only for normal users)'
::IN::# Lock ONLY our own sub-folders (other tools live in C:\bimebama too, e.g. mamoot / ollama-worker)
::IN::foreach ($d in @($Srv, $Ext)) {
::IN::    $null = & icacls.exe $d /inheritance:r
::IN::    $null = & icacls.exe $d /grant:r '*S-1-5-18:(OI)(CI)F'
::IN::    $null = & icacls.exe $d /grant:r '*S-1-5-32-544:(OI)(CI)F'
::IN::    $null = & icacls.exe $d /grant '*S-1-5-32-545:(OI)(CI)RX'
::IN::    $null = & icacls.exe $d /grant '*S-1-5-11:(OI)(CI)RX'
::IN::    $null = & icacls.exe "$d\*" /reset /T /C
::IN::}
::IN::foreach ($f in @("$Srv\start_task.vbs", "$Srv\run_hidden.vbs", "$Srv\bridge.ps1")) {
::IN::    $acl = (& icacls.exe $f) -join ' '
::IN::    if ($acl -notmatch 'Users|Authenticated') { Write-Host "[!] Permission problem on $f" -ForegroundColor Yellow }
::IN::}
::IN::
::IN::Step 'Registering background task (starts at every logon, elevated, restarts on failure)'
::IN::$act = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument '"C:\bimebama\service\run_hidden.vbs"'
::IN::$trg = New-ScheduledTaskTrigger -AtLogOn
::IN::$pri = New-ScheduledTaskPrincipal -GroupId 'BUILTIN\Users' -RunLevel Highest
::IN::$set = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
::IN::        -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1)
::IN::Register-ScheduledTask -TaskName 'BimeBamaAtiBridge' -Action $act -Trigger $trg -Principal $pri -Settings $set -Force | Out-Null
::IN::
::IN::Step 'Registering bimebama:// protocol'
::IN::New-Item -Path 'HKLM:\SOFTWARE\Classes\bimebama' -Force | Out-Null
::IN::Set-ItemProperty -Path 'HKLM:\SOFTWARE\Classes\bimebama' -Name '(default)' -Value 'URL:BimeBama Bridge'
::IN::New-ItemProperty -Path 'HKLM:\SOFTWARE\Classes\bimebama' -Name 'URL Protocol' -Value '' -Force | Out-Null
::IN::New-Item -Path 'HKLM:\SOFTWARE\Classes\bimebama\shell\open\command' -Force | Out-Null
::IN::Set-ItemProperty -Path 'HKLM:\SOFTWARE\Classes\bimebama\shell\open\command' -Name '(default)' -Value 'wscript.exe "C:\bimebama\service\start_task.vbs"'
::IN::
::IN::# remove legacy Startup copy (would start the bridge WITHOUT admin rights)
::IN::Remove-Item "$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup\BimeBamaBridge.vbs" -Force -ErrorAction SilentlyContinue
::IN::
::IN::Step 'Starting service now'
::IN::Start-ScheduledTask -TaskName 'BimeBamaAtiBridge'
::IN::
::IN::Write-Host ''
::IN::Write-Host '=============================================================' -ForegroundColor Green
::IN::Write-Host ' Service installed. It starts automatically at every logon.'
::IN::if ($installed) {
::IN::    Write-Host " Extension copied to: $Ext" -ForegroundColor Green
::IN::    Write-Host ''
::IN::    Write-Host ' LAST STEP (one time):'
::IN::    Write-Host '  1) chrome://extensions  -> remove the OLD copy of the extension'
::IN::    Write-Host "  2) Developer mode ON -> Load unpacked -> select: $Ext"
::IN::    Start-Process explorer.exe $Ext
::IN::    try { Start-Process 'chrome.exe' 'chrome://extensions/' } catch {}
::IN::} else {
::IN::    Write-Host ' Extension files were not found/downloaded. Ask admin to publish the package in the panel,' -ForegroundColor Yellow
::IN::    Write-Host " then run this installer again (or copy the extension folder to $Ext)." -ForegroundColor Yellow
::IN::}
::IN::Write-Host '=============================================================' -ForegroundColor Green
