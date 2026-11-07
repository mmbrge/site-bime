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
::BR::# BimeBama local bridge (v2.7.0) - runs elevated, listens on 127.0.0.1:8765 only
::BR::$ErrorActionPreference = 'Continue'
::BR::$Port = 8765
::BR::$Server = '__BB_SERVER__'
::BR::$ExtOrigin = 'chrome-extension://__BB_EXT_ID__'
::BR::$ExtDir = 'C:\bimebama\extension'
::BR::$BridgeVersion = '2.7.0'
::BR::$script:LastRoutes = $null; $script:LastHosts = @(); $script:HostIPs = @(); $script:HostMap = @{}; $script:HostNets = @(); $script:NrptNs = @()
::BR::$ExtIdBare = '__BB_EXT_ID__'
::BR::$script:After = $null
::BR::$script:Restart = $false
::BR::$DefaultRoutes = @('10.0.0.0/8', '172.16.0.0/12', '20.0.0.0/8')
::BR::try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
::BR::
::BR::# log for troubleshooting: %TEMP%\bimebama-bridge.log (the installer prints its tail when the bridge does not answer)
::BR::$LogFile = Join-Path $env:TEMP 'bimebama-bridge.log'
::BR::function Write-BLog($m) {
::BR::    try {
::BR::        if ((Test-Path $LogFile) -and (Get-Item $LogFile).Length -gt 524288) { Move-Item $LogFile "$LogFile.old" -Force }
::BR::        Add-Content -Path $LogFile -Value ((Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m) -Encoding UTF8
::BR::    } catch {}
::BR::}
::BR::# admin rights are needed for routes / interface metric / DNS rules (rasdial itself works without)
::BR::$IsElevated = $false
::BR::try { $IsElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator) } catch {}
::BR::
::BR::$mutex = New-Object System.Threading.Mutex($false, 'Global\BimeBamaAtiBridge')
::BR::if (-not $mutex.WaitOne(0)) { Write-BLog 'another bridge is already running; exit'; exit }
::BR::
::BR::$listener = New-Object System.Net.HttpListener
::BR::$listener.Prefixes.Add("http://127.0.0.1:$Port/")
::BR::try { $listener.Start() } catch {
::BR::    Write-BLog ("cannot listen on 127.0.0.1:$Port - " + $_.Exception.Message + ' (port used by another program, or missing urlacl for a non-admin user)')
::BR::    exit
::BR::}
::BR::Write-BLog ("bridge v$BridgeVersion started as " + [Environment]::UserName + ' elevated=' + $IsElevated)
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
::BR::    $i = Get-NetIPAddress -InterfaceAlias $name -AddressFamily IPv4 -ErrorAction SilentlyContinue | Select-Object -First 1
::BR::    if ($i) { return $i }
::BR::    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.InterfaceAlias -like "$name*" -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1
::BR::}
::BR::
::BR::# Windows command-line quoting (CommandLineToArgvW rules): backslashes before a quote are doubled
::BR::function Quote-Arg([string]$a) {
::BR::    if ($a -eq '') { return '""' }
::BR::    if ($a -notmatch '[\s"]') { return $a }
::BR::    $r = '"'; $bs = 0
::BR::    foreach ($ch in $a.ToCharArray()) {
::BR::        if ($ch -eq [char]'\') { $bs++; continue }
::BR::        if ($ch -eq [char]'"') { $r += ('\' * ($bs * 2 + 1)) + '"'; $bs = 0; continue }
::BR::        $r += ('\' * $bs) + $ch; $bs = 0
::BR::    }
::BR::    return $r + ('\' * ($bs * 2)) + '"'
::BR::}
::BR::
::BR::# rasdial with exit code + output (691 = wrong user/password, 623 = connection not found, 868/800/809 = server unreachable ...)
::BR::function Invoke-Rasdial([string[]]$parts, [int]$waitMs = 45000) {
::BR::    $psi = New-Object System.Diagnostics.ProcessStartInfo
::BR::    $psi.FileName = 'rasdial.exe'
::BR::    $psi.Arguments = ($parts | ForEach-Object { Quote-Arg $_ }) -join ' '
::BR::    $psi.UseShellExecute = $false
::BR::    $psi.CreateNoWindow = $true
::BR::    $psi.RedirectStandardOutput = $true
::BR::    $psi.RedirectStandardError = $true
::BR::    try { $p = [System.Diagnostics.Process]::Start($psi) } catch { return @{ code = -2; text = 'rasdial.exe not started' } }
::BR::    $o = $p.StandardOutput.ReadToEndAsync(); $e = $p.StandardError.ReadToEndAsync()
::BR::    if (-not $p.WaitForExit($waitMs)) { try { $p.Kill() } catch {}; return @{ code = -1; text = 'timeout' } }
::BR::    $txt = (($o.Result + ' ' + $e.Result) -replace '\s+', ' ').Trim()
::BR::    if ($txt.Length -gt 300) { $txt = $txt.Substring($txt.Length - 300) }
::BR::    return @{ code = $p.ExitCode; text = $txt }
::BR::}
::BR::# Routes that go through the VPN (from the panel; only IPv4 CIDR accepted)
::BR::# Hostnames of the insurer sites (from the panel's site list) - resolved through the VPN DNS after connecting
::BR::function Get-Hosts($data) {
::BR::    $h = @()
::BR::    if ($data -and $data.hosts) { foreach ($x in @($data.hosts)) { $x = ([string]$x).Trim().ToLower(); if ($x -match '^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$' -and $h -notcontains $x) { $h += $x } } }
::BR::    if ($h.Count -eq 0 -and $script:LastHosts) { $h = $script:LastHosts }
::BR::    return $h
::BR::}
::BR::# Host name of the VPN server itself (its DNS name must ALWAYS resolve through the normal network)
::BR::function Get-VpnServer($connName) {
::BR::    $v = $null
::BR::    try { $v = Get-VpnConnection -Name $connName -ErrorAction Stop } catch { try { $v = Get-VpnConnection -Name $connName -AllUserConnection -ErrorAction Stop } catch {} }
::BR::    if ($v -and $v.ServerAddress) { return ([string]$v.ServerAddress).Trim().ToLower() }
::BR::    return ''
::BR::}
::BR::# Remove the DNS rules this bridge added (marked 'BimeBama'), plus older unmarked ones for the same names.
::BR::# Windows keeps NRPT rules after the VPN drops or a reboot: a leftover rule can send the VPN server's own name to
::BR::# the (unreachable) VPN DNS => rasdial error 868. So: cleaned before every dial and whenever the VPN is down.
::BR::function Clear-OurNrpt($extraNs, $server = '') {
::BR::    $ns = @('.iic.local') + @($script:NrptNs) + @($extraNs)
::BR::    Get-DnsClientNrptRule -ErrorAction SilentlyContinue | Where-Object {
::BR::        $r = $_; $n0 = @($r.Namespace)
::BR::        # ours, or any rule that would catch the VPN server's own name (=> error 868)
::BR::        ($r.Comment -eq 'BimeBama') -or ($ns | Where-Object { $n0 -contains $_ }) -or
::BR::        ($server -and ($n0 | Where-Object { $x = ([string]$_).ToLower(); $x -eq $server -or ($x.StartsWith('.') -and ($server.EndsWith($x) -or $server -eq $x.TrimStart('.'))) }))
::BR::    } | Remove-DnsClientNrptRule -Force -ErrorAction SilentlyContinue
::BR::}
::BR::function Get-NrptNs($hosts, $server) {
::BR::    $out = @()
::BR::    foreach ($h in (@('sales.iic.local') + @($hosts))) {
::BR::        if ($h -match '^\d{1,3}(\.\d{1,3}){3}$') { continue }
::BR::        $parts = $h.Split('.'); if ($parts.Count -lt 2) { continue }
::BR::        $suf = '.' + ($parts[($parts.Count - 2)..($parts.Count - 1)] -join '.')
::BR::        # the VPN server lives under the same domain: rule only for the exact site name, never for the whole domain
::BR::        $n = if ($server -and ($server -eq $suf.TrimStart('.') -or $server.EndsWith($suf))) { $h } else { $suf }
::BR::        if ($n -eq $server) { continue }
::BR::        if ($out -notcontains $n) { $out += $n }
::BR::    }
::BR::    return $out
::BR::}
::BR::function Get-HostIPs($hosts, $dns) {
::BR::    $all = @(); $map = @{}
::BR::    foreach ($h in @($hosts)) {
::BR::        if ($h -match '^\d{1,3}(\.\d{1,3}){3}$') { $all += $h; $map[$h] = @($h); continue }
::BR::        $got = @()
::BR::        foreach ($d in @($dns)) {
::BR::            try { $got = @(Resolve-DnsName -Name $h -Type A -Server $d -DnsOnly -QuickTimeout -ErrorAction Stop | Where-Object { $_.Type -eq 'A' } | ForEach-Object { $_.IPAddress }) } catch {}
::BR::            if ($got.Count) { break }
::BR::        }
::BR::        if (-not $got.Count) { try { $got = @([Net.Dns]::GetHostAddresses($h) | Where-Object { $_.AddressFamily -eq 'InterNetwork' } | ForEach-Object { $_.IPAddressToString }) } catch {} }
::BR::        $map[$h] = $got; $all += $got
::BR::    }
::BR::    $script:HostMap = $map
::BR::    return @($all | Where-Object { $_ -and $_ -notmatch '^(127|0)\.' } | Select-Object -Unique)
::BR::}
::BR::function Get-Routes($data) {
::BR::    if (-not ($data -and $data.routes) -and $script:LastRoutes) { return $script:LastRoutes }
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
::BR::    $have = @(Get-NetRoute -InterfaceIndex $info.InterfaceIndex -ErrorAction SilentlyContinue | ForEach-Object { $_.DestinationPrefix })
::BR::    foreach ($p in @('0.0.0.0/0', '0.0.0.0/1', '128.0.0.0/1')) { if ($have -contains $p) { return $true } }
::BR::    $ifc = Get-NetIPInterface -InterfaceIndex $info.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue
::BR::    if ($ifc -and $ifc.InterfaceMetric -lt 1000) { return $true }
::BR::    foreach ($r in $routes) { if ($have -notcontains $r) { return $true } }
::BR::    foreach ($n in @($script:HostNets)) { if ($have -notcontains $n) { return $true } }
::BR::    if ($script:LastHosts.Count -and -not @($script:HostIPs).Count) { return $true }
::BR::    return $false
::BR::}
::BR::
::BR::# After dialing: remove every default route of the VPN interface (on-link routes have gateway 0.0.0.0, so the old
::BR::# "route delete 0.0.0.0 mask 0.0.0.0 <vpnIP>" never matched and ALL traffic went into the tunnel), push the
::BR::# interface metric very high (normal sites and DNS keep using the real network) and add only the insurer routes.
::BR::function Fix-SplitTunnel($connName, $routes, $hosts) {
::BR::    $ppp = Get-ConnInfo $connName
::BR::    if (-not $ppp) { return $false }
::BR::    $vpnIP = $ppp.IPAddress
::BR::    $ifIndex = $ppp.InterfaceIndex
::BR::    $all = @(Get-NetRoute -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue)
::BR::    $all | Where-Object { @('0.0.0.0/0', '0.0.0.0/1', '128.0.0.0/1', '::/0') -contains $_.DestinationPrefix } | Remove-NetRoute -Confirm:$false -ErrorAction SilentlyContinue
::BR::    $have = @($all | ForEach-Object { $_.DestinationPrefix })
::BR::    route delete 0.0.0.0 mask 0.0.0.0 $vpnIP 2>$null | Out-Null
::BR::    Set-NetIPInterface -InterfaceIndex $ifIndex -AddressFamily IPv4 -InterfaceMetric 5000 -ErrorAction SilentlyContinue
::BR::    Set-NetIPInterface -InterfaceIndex $ifIndex -AddressFamily IPv6 -InterfaceMetric 5000 -ErrorAction SilentlyContinue
::BR::    foreach ($r in $routes) {
::BR::        if ($have -notcontains $r) {
::BR::            New-NetRoute -DestinationPrefix $r -InterfaceIndex $ifIndex -NextHop '0.0.0.0' -RouteMetric 1 -PolicyStore ActiveStore -ErrorAction SilentlyContinue | Out-Null
::BR::        }
::BR::    }
::BR::    $dnsServers = @((Get-DnsClientServerAddress -InterfaceIndex $ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).ServerAddresses)
::BR::    if ($dnsServers.Count) {
::BR::        foreach ($d in $dnsServers) {
::BR::            if ($have -notcontains "$d/32") { New-NetRoute -DestinationPrefix "$d/32" -InterfaceIndex $ifIndex -NextHop '0.0.0.0' -RouteMetric 1 -PolicyStore ActiveStore -ErrorAction SilentlyContinue | Out-Null }
::BR::        }
::BR::        # names of the insurer sites are asked from the VPN DNS (marked rules; never the VPN server's own name)
::BR::        $ns = Get-NrptNs $hosts (Get-VpnServer $connName)
::BR::        $old = @(Get-DnsClientNrptRule -ErrorAction SilentlyContinue)
::BR::        foreach ($n in $ns) {
::BR::            $ok = $old | Where-Object { @($_.Namespace) -contains $n -and $_.Comment -eq 'BimeBama' -and ((@($_.NameServers) -join ',') -eq ($dnsServers -join ',')) }
::BR::            if (-not $ok) {
::BR::                $old | Where-Object { @($_.Namespace) -contains $n } | Remove-DnsClientNrptRule -Force -ErrorAction SilentlyContinue
::BR::                Add-DnsClientNrptRule -Namespace $n -NameServers $dnsServers -Comment 'BimeBama' -ErrorAction SilentlyContinue
::BR::            }
::BR::        }
::BR::        $script:NrptNs = $ns
::BR::    }
::BR::    ipconfig /flushdns | Out-Null
::BR::    # the insurer sites themselves: their whole /24 goes through the tunnel (other servers of the same network too),
::BR::    # unless that /24 is the local network of this PC - then only the exact address
::BR::    if ($hosts) {
::BR::        $ips = Get-HostIPs $hosts $dnsServers
::BR::        $script:HostIPs = $ips
::BR::        $local = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.InterfaceIndex -ne $ifIndex } | ForEach-Object { ($_.IPAddress -replace '\.\d+$', '.0') + '/24' })
::BR::        $nets = @()
::BR::        foreach ($ip in $ips) { $n24 = ($ip -replace '\.\d+$', '.0') + '/24'; if ($local -contains $n24) { $nets += "$ip/32" } elseif ($nets -notcontains $n24) { $nets += $n24 } }
::BR::        $script:HostNets = $nets
::BR::        foreach ($n in $nets) {
::BR::            if ($have -notcontains $n) { New-NetRoute -DestinationPrefix $n -InterfaceIndex $ifIndex -NextHop '0.0.0.0' -RouteMetric 1 -PolicyStore ActiveStore -ErrorAction SilentlyContinue | Out-Null }
::BR::        }
::BR::        ipconfig /flushdns | Out-Null
::BR::    }
::BR::    return $true
::BR::}
::BR::
::BR::function Test-Newer($a, $b) {
::BR::    try { return ([version]$a -gt [version]$b) } catch { return $false }
::BR::}
::BR::
::BR::function Get-DirVersion($dir) {
::BR::    try { return (Get-Content (Join-Path $dir 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json).version } catch { return '0' }
::BR::}
::BR::# extension id = first 32 hex of sha256(manifest key) mapped 0-f -> a-p
::BR::function Get-DirExtId($dir) {
::BR::    try {
::BR::        $m = Get-Content (Join-Path $dir 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
::BR::        $h = [Security.Cryptography.SHA256]::Create().ComputeHash([Convert]::FromBase64String([string]$m.key))
::BR::        $hex = (($h | ForEach-Object { $_.ToString('x2') }) -join '').Substring(0, 32)
::BR::        return -join ($hex.ToCharArray() | ForEach-Object { [char](97 + [Convert]::ToInt32([string]$_, 16)) })
::BR::    } catch { return '' }
::BR::}
::BR::function Read-Shared($f) {
::BR::    try { $fs = New-Object IO.FileStream($f, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite); $sr = New-Object IO.StreamReader($fs, [Text.Encoding]::UTF8); $t = $sr.ReadToEnd(); $sr.Close(); return $t } catch { return '' }
::BR::}
::BR::# Every folder Chrome/Edge/Brave loaded THIS extension from (unpacked), plus the installer copy
::BR::$script:DirsAt = [datetime]::MinValue; $script:Dirs = @()
::BR::function Find-ExtDirs([switch]$Fresh) {
::BR::    if (-not $Fresh -and ((Get-Date) - $script:DirsAt).TotalSeconds -lt 60) { return $script:Dirs }
::BR::    $dirs = New-Object System.Collections.Generic.List[string]
::BR::    $roots = @("$env:LOCALAPPDATA\Google\Chrome\User Data", "$env:LOCALAPPDATA\Microsoft\Edge\User Data", "$env:LOCALAPPDATA\BraveSoftware\Brave-Browser\User Data", "$env:LOCALAPPDATA\Chromium\User Data")
::BR::    foreach ($r in $roots) {
::BR::        if (-not (Test-Path $r)) { continue }
::BR::        foreach ($pf in (Get-ChildItem $r -Directory -ErrorAction SilentlyContinue)) {
::BR::            foreach ($fn in 'Secure Preferences', 'Preferences') {
::BR::                $f = Join-Path $pf.FullName $fn
::BR::                if (-not (Test-Path $f)) { continue }
::BR::                $t = Read-Shared $f
::BR::                if (-not $t -or $t.IndexOf($ExtIdBare) -lt 0) { continue }
::BR::                foreach ($m in [regex]::Matches($t, '"path"\s*:\s*"((?:[^"\\]|\\.)*)"')) {
::BR::                    try { $p = [regex]::Unescape($m.Groups[1].Value) } catch { continue }
::BR::                    if ($p -notmatch '^[A-Za-z]:\\' -or $dirs.Contains($p)) { continue }
::BR::                    if (-not (Test-Path (Join-Path $p 'manifest.json'))) { continue }
::BR::                    if ((Get-DirExtId $p) -eq $ExtIdBare) { $dirs.Add($p) }
::BR::                }
::BR::            }
::BR::        }
::BR::    }
::BR::    if ((Test-Path "$ExtDir\manifest.json") -and -not ($dirs | Where-Object { $_.TrimEnd('\') -ieq $ExtDir })) { $dirs.Add($ExtDir) }
::BR::    $script:Dirs = @($dirs); $script:DirsAt = Get-Date
::BR::    return $script:Dirs
::BR::}
::BR::function Get-LocalVersion {
::BR::    $v = '0'
::BR::    foreach ($d in (Find-ExtDirs)) { $x = Get-DirVersion $d; if ($v -eq '0' -or (Test-Newer $v $x)) { $v = $x } }
::BR::    return $v
::BR::}
::BR::
::BR::# New bridge from the ::BR:: lines of an installer file; written over this script, restart after the reply
::BR::function Update-SelfFrom($bat) {
::BR::    if (-not (Test-Path $bat)) { return $null }
::BR::    $lines = @([IO.File]::ReadAllLines($bat) | Where-Object { $_.StartsWith('::BR::') } | ForEach-Object { $_.Substring(6) })
::BR::    $nv = $lines | Where-Object { $_ -match "^\`$BridgeVersion = '([\d\.]+)'" } | Select-Object -First 1
::BR::    if (-not ($nv -match "'([\d\.]+)'")) { return $null }
::BR::    $v = $Matches[1]
::BR::    if (-not (Test-Newer $v $BridgeVersion) -or $lines.Count -lt 50) { return $null }
::BR::    $me = $PSCommandPath; if (-not $me) { $me = 'C:\bimebama\service\bridge.ps1' }
::BR::    [IO.File]::WriteAllLines($me, [string[]]$lines, (New-Object Text.UTF8Encoding($true)))
::BR::    $script:Restart = $true
::BR::    return $v
::BR::}
::BR::function Update-Extension($token) {
::BR::    $r = Invoke-RestMethod -Uri "$Server/api/ext_api.php?action=latest" -TimeoutSec 20
::BR::    if (-not $r -or -not $r.version) { return @{ ok = $true; code = 'no_update' } }
::BR::    $targets = @(Find-ExtDirs -Fresh | Where-Object { Test-Newer $r.version (Get-DirVersion $_) })
::BR::    if ($targets.Count -eq 0) { return @{ ok = $true; code = 'up_to_date'; version = (Get-LocalVersion) } }
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
::BR::        if ((Get-DirExtId $root) -ne $ExtIdBare) { return @{ ok = $false; code = 'bad_package' } }
::BR::        $done = @()
::BR::        foreach ($t in $targets) {
::BR::            # the installer copy is mirrored; a folder the user picked keeps any extra files of its own
::BR::            if ($t.TrimEnd('\') -ieq $ExtDir) { & robocopy.exe $root $t /MIR /NFL /NDL /NJH /NJS /NP /R:2 /W:1 | Out-Null }
::BR::            else { & robocopy.exe $root $t /E /IS /IT /NFL /NDL /NJH /NJS /NP /R:2 /W:1 | Out-Null }
::BR::            if ($LASTEXITCODE -lt 8) { $done += $t }
::BR::        }
::BR::        if ($done.Count -eq 0) { return @{ ok = $false; code = 'copy_failed' } }
::BR::        # the bridge updates itself too (from the ::BR:: lines of the new installer) and restarts after replying
::BR::        $selfUpd = [bool](Update-SelfFrom (Join-Path $root 'Setup-BimeBama.bat'))
::BR::        return @{ ok = $true; code = 'updated'; version = $r.version; paths = $done; bridgeUpdated = $selfUpd }
::BR::    } finally {
::BR::        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
::BR::    }
::BR::}
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
::BR::    $hosts = Get-Hosts $data
::BR::    if ($action -eq 'connect' -and $isPost) {
::BR::        $info = Get-ConnInfo $connName
::BR::        $rd = @{ code = 0; text = '' }
::BR::        if (-not $info) {
::BR::            Set-SplitConfig $connName | Out-Null
::BR::            Clear-OurNrpt (Get-NrptNs $hosts '') (Get-VpnServer $connName)
::BR::            ipconfig /flushdns | Out-Null
::BR::            if ($data.username -and $data.password) { $rd = Invoke-Rasdial @($connName, [string]$data.username, [string]$data.password) }
::BR::            else { $rd = Invoke-Rasdial @($connName) }
::BR::            # the PPP address can take several seconds to appear after rasdial returns
::BR::            for ($i = 0; $i -lt 25; $i++) {
::BR::                $info = Get-ConnInfo $connName
::BR::                if ($info -or ($rd.code -ne 0 -and $i -ge 2)) { break }
::BR::                Start-Sleep -Seconds 1
::BR::            }
::BR::        }
::BR::        if ($info) {
::BR::            $out.connected = $true; $out.ip = $info.IPAddress; $out.code = 'connected'; $out.elevated = $IsElevated
::BR::            Write-BLog ("connected " + $info.IPAddress + ' elevated=' + $IsElevated)
::BR::            # routing fix runs right after the reply (the extension does not wait for it); re-checked on every status call
::BR::            $script:LastRoutes = $routes; $script:LastHosts = $hosts
::BR::            $script:After = @{ conn = $connName; routes = $routes; hosts = $hosts }
::BR::        } else {
::BR::            $out.connected = $false; $out.code = 'connect_failed'; $out.rasCode = [int]$rd.code; $out.rasText = [string]$rd.text
::BR::            Write-BLog ("connect failed: rasdial " + $rd.code + ' ' + $rd.text)
::BR::        }
::BR::    }
::BR::    elseif ($action -eq 'disconnect' -and $isPost) {
::BR::        Invoke-Rasdial @($connName, '/disconnect') | Out-Null
::BR::        Clear-OurNrpt (Get-NrptNs $hosts '')
::BR::        ipconfig /flushdns | Out-Null
::BR::        $out.connected = $false; $out.code = 'disconnected'
::BR::        Write-BLog 'disconnect requested by the extension'
::BR::    }
::BR::    elseif ($action -eq 'status') {
::BR::        $info = Get-ConnInfo $connName
::BR::        if ($info -and $origin -and (Test-NeedFix $info $routes)) { Fix-SplitTunnel $connName $routes $hosts | Out-Null }
::BR::        if (-not $info -and -not $script:NrptClean) { Clear-OurNrpt (Get-NrptNs $hosts ''); $script:NrptClean = $true }
::BR::        if ($info) { $script:NrptClean = $false }
::BR::        $out.hosts = @($script:HostMap.Keys | ForEach-Object { $_ + '=' + ((@($script:HostMap[$_]) -join ',')) })
::BR::        $out.connected = [bool]$info
::BR::        $out.ip = if ($info) { $info.IPAddress } else { '' }
::BR::        $out.elevated = $IsElevated
::BR::        $out.extVersion = Get-LocalVersion
::BR::        $out.installPath = (@(Find-ExtDirs) -join ' ; ')
::BR::    }
::BR::    elseif ($action -eq 'resolve' -and $isPost) {
::BR::        # addresses of given names through the VPN DNS (when connected), for the panel's "add routes" button
::BR::        $info = Get-ConnInfo $connName
::BR::        $dns = @(); if ($info) { $dns = @((Get-DnsClientServerAddress -InterfaceIndex $info.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).ServerAddresses) }
::BR::        $q = @(); foreach ($x in @($data.resolve)) { $x = ([string]$x).Trim().ToLower(); if ($x -match '^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$' -and $q.Count -lt 10) { $q += $x } }
::BR::        $saved = $script:HostMap
::BR::        $null = Get-HostIPs $q $dns
::BR::        $out.resolved = @($script:HostMap.Keys | ForEach-Object { $_ + '=' + ((@($script:HostMap[$_]) -join ',')) })
::BR::        $script:HostMap = $saved
::BR::        $out.viaVpn = [bool]$info
::BR::    }
::BR::    elseif ($action -eq 'selfupdate' -and $isPost) {
::BR::        # bridge from the installer inside the (already updated) extension folder
::BR::        $nv = $null
::BR::        foreach ($d in (Find-ExtDirs -Fresh)) { $nv = Update-SelfFrom (Join-Path $d 'Setup-BimeBama.bat'); if ($nv) { break } }
::BR::        $out.code = if ($nv) { 'bridge_updated' } else { 'bridge_up_to_date' }; $out.newVersion = [string]$nv
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
::BR::    if ($script:After) {
::BR::        $a = $script:After; $script:After = $null
::BR::        try {
::BR::            Fix-SplitTunnel $a.conn $a.routes $a.hosts | Out-Null
::BR::            Start-Sleep -Seconds 3   # Windows sometimes puts the default route back a few seconds later
::BR::            $i2 = Get-ConnInfo $a.conn
::BR::            if (Test-NeedFix $i2 $a.routes) { Fix-SplitTunnel $a.conn $a.routes $a.hosts | Out-Null }
::BR::        } catch {}
::BR::    }
::BR::    if ($script:Restart) {
::BR::        # new bridge.ps1 was written: start it via the task in a few seconds and leave
::BR::        Start-Process -WindowStyle Hidden -FilePath 'powershell.exe' -ArgumentList '-NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 3; Start-ScheduledTask -TaskName BimeBamaAtiBridge"'
::BR::        try { $listener.Stop() } catch {}
::BR::        try { $mutex.ReleaseMutex() } catch {}
::BR::        exit
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
::IN::# «اجرا» از افزونه: اول همان کارِ زمان‌بندی‌شده (با دسترسیِ مدیر)، اگر اجازه نداشت مستقیم
::IN::[IO.File]::WriteAllText("$Srv\start_task.vbs",
::IN::    "Set s = CreateObject(""WScript.Shell"")`r`nrc = s.Run(""schtasks.exe /run /tn BimeBamaAtiBridge"", 0, True)`r`nIf rc <> 0 Then s.Run ""wscript.exe C:\bimebama\service\run_hidden.vbs"", 0, False`r`n",
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
::IN::Step 'Allowing the bridge to listen on 127.0.0.1:8765 for every user (also non-admin)'
::IN::$null = & netsh.exe http delete urlacl url=http://127.0.0.1:8765/ 2>$null
::IN::$null = & netsh.exe http add urlacl url=http://127.0.0.1:8765/ 'sddl=D:(A;;GX;;;AU)' 2>$null
::IN::# normal users may start the task on demand (the extension's «اجرا» button)
::IN::try {
::IN::    $svc = New-Object -ComObject Schedule.Service; $svc.Connect()
::IN::    $tk = $svc.GetFolder('\').GetTask('BimeBamaAtiBridge')
::IN::    $sd = $tk.GetSecurityDescriptor(0xF)
::IN::    if ($sd -notmatch ';;;AU\)') { $tk.SetSecurityDescriptor($sd + '(A;;GRGX;;;AU)', 0) }
::IN::} catch { Write-Host "[!] Could not open the task to normal users: $($_.Exception.Message)" -ForegroundColor Yellow }
::IN::
::IN::Step 'Starting service now'
::IN::Start-ScheduledTask -TaskName 'BimeBamaAtiBridge'
::IN::
::IN::# ---- the bridge must answer; otherwise start it directly and print the reason
::IN::function Test-Bridge { try { $r = Invoke-RestMethod -Uri 'http://127.0.0.1:8765/?action=status' -TimeoutSec 2; if ($r.ok) { return $r } } catch {}; return $null }
::IN::$st = $null
::IN::for ($i = 0; $i -lt 15 -and -not $st; $i++) { Start-Sleep -Seconds 1; $st = Test-Bridge }
::IN::if (-not $st) {
::IN::    Write-Host '[!] The scheduled task did not start the bridge; starting it directly...' -ForegroundColor Yellow
::IN::    Start-Process -FilePath 'wscript.exe' -ArgumentList '"C:\bimebama\service\run_hidden.vbs"'
::IN::    for ($i = 0; $i -lt 15 -and -not $st; $i++) { Start-Sleep -Seconds 1; $st = Test-Bridge }
::IN::}
::IN::if ($st) {
::IN::    Write-Host ("[OK] Bridge is running: v" + $st.bridgeVersion + "  (admin rights: " + $st.elevated + ")") -ForegroundColor Green
::IN::    if (-not $st.elevated) {
::IN::        Write-Host '[!] The bridge runs WITHOUT admin rights: the VPN can connect, but routes/DNS for the insurer sites cannot be set.' -ForegroundColor Yellow
::IN::        Write-Host '    Log on with a Windows account that is an Administrator (or make this user an Administrator), then run this installer again.' -ForegroundColor Yellow
::IN::    }
::IN::} else {
::IN::    Write-Host '[X] The bridge does not answer on 127.0.0.1:8765.' -ForegroundColor Red
::IN::    $lp = Get-NetTCPConnection -LocalPort 8765 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
::IN::    if ($lp) { $pp = Get-Process -Id $lp.OwningProcess -ErrorAction SilentlyContinue; Write-Host ("    Port 8765 is used by: " + $pp.ProcessName + " " + $pp.Path) -ForegroundColor Red }
::IN::    $lg = Join-Path $env:TEMP 'bimebama-bridge.log'
::IN::    if (Test-Path $lg) { Write-Host '    Last lines of the bridge log:'; Get-Content $lg -Tail 8 | ForEach-Object { Write-Host "    $_" } }
::IN::    Write-Host '    An antivirus may block hidden PowerShell started by wscript; allow C:\bimebama\service and run again.' -ForegroundColor Yellow
::IN::}
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
