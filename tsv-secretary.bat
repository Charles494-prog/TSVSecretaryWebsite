@echo off
setlocal DisableDelayedExpansion
title TSV Secretary
rem One file that installs and opens TSV Secretary. The PowerShell part below the marker line does the work;
rem its path reaches PowerShell through an environment variable, never pasted into its code.
set "TSV_SELF=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$f = $env:TSV_SELF; $m = [string][char]10 + '#' + '!PS!#'; $s = [IO.File]::ReadAllText($f, [Text.Encoding]::UTF8); $i = $s.IndexOf($m); if ($i -lt 0 -or $s.IndexOf($m, $i + 1) -ge 0) { Write-Host 'TSV Secretary launcher: this file is damaged (start marker missing or repeated).' -ForegroundColor Red; Read-Host 'Press Enter to close'; exit 2 }; . ([ScriptBlock]::Create($s.Substring($i + 1)))"
exit /b
#!PS!#
$ErrorActionPreference = 'SilentlyContinue'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch {}

# ============================== CONFIG ==============================
$DriveLink = 'https://drive.google.com/file/d/15N-DX6m7uos1uXR7-P3rgTrNu-2rHJdE/view?usp=sharing'   # the site's zip on Google Drive ("Anyone with the link")
$Xampp     = 'C:\xampp'
$App       = Join-Path $Xampp 'htdocs\tsv_secretary_website'
$Site      = 'http://localhost/tsv_secretary_website/'
$XamppUrl  = 'https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe/download'
$Work      = Join-Path $env:TEMP 'tsv_install'
$MySQLPort = 3306
$WebPort   = 80
# ====================================================================

$Self = $env:TSV_SELF
$W = 74
$Logs = Join-Path $Work 'logs'

# ---- screen characters: box drawing on UTF-8 consoles, plain ASCII otherwise ----
$Ascii = $true
try { $Ascii = ([Console]::OutputEncoding.CodePage -ne 65001) } catch {}
if ($Ascii) {
    $G = @{ TL = '+'; TR = '+'; BL = '+'; BR = '+'; H = '-'; V = '|'; ML = '+'; MR = '+'; Bar = '#'; Track = '.'; Pend = 'o'; Ok = '+'; Warn = '!'; Fail = 'x'; Dot = '*'; Arrow = '>'; Ell = '~' }
    $Spin = @('|', '/', '-', '\')
    $Logo = @(
        ' _____  ____ __     __',
        '|_   _|/ ___|\ \   / /',
        '  | |  \___ \ \ \ / / ',
        '  | |   ___) | \ V /  ',
        '  |_|  |____/   \_/   ',
        '')
} else {
    $G = @{ TL = '╭'; TR = '╮'; BL = '╰'; BR = '╯'; H = '─'; V = '│'; ML = '├'; MR = '┤'; Bar = '━'; Track = '━'; Pend = '○'; Ok = '✓'; Warn = '!'; Fail = '✕'; Dot = '●'; Arrow = '›'; Ell = '…' }
    $Spin = @('⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏')
    $Logo = @(
        '████████╗███████╗██╗   ██╗',
        '╚══██╔══╝██╔════╝██║   ██║',
        '   ██║   ███████╗██║   ██║',
        '   ██║   ╚════██║╚██╗ ██╔╝',
        '   ██║   ███████║ ╚████╔╝ ',
        '   ╚═╝   ╚══════╝  ╚═══╝  ')
}

$script:Steps = @()
$script:Frac = 0.0        # how far the running step is (0-1), for the progress bar
$script:Tick = 0
$script:LastDraw = [datetime]::MinValue
$script:Theme = 'Cyan'
$script:Badge = ''
$script:Banner = $null
$script:FailStep = $null
$script:FailLog = $null
$script:T0 = Get-Date

# ------------------------------- drawing -------------------------------
function Seg($t, $fg = 'Gray', $bg = 'Black') {
    [Console]::ForegroundColor = [ConsoleColor]$fg
    [Console]::BackgroundColor = [ConsoleColor]$bg
    [Console]::Write($t)
    [Console]::BackgroundColor = [ConsoleColor]'Black'
}
function Pt($t, $fg = 'Gray', $bg = 'Black') { [pscustomobject]@{ T = [string]$t; F = $fg; B = $bg } }
function Fit($s, $n) { $s = [string]$s; if ($s.Length -gt $n) { $s.Substring(0, [math]::Max(0, $n - 1)) + $G.Ell } else { $s } }
function Row {
    $len = 1
    Seg $G.V 'DarkGray'; Seg ' '
    foreach ($x in $args) { Seg $x.T $x.F $x.B; $len += $x.T.Length }
    if ($W - $len -gt 0) { Seg (' ' * ($W - $len)) }
    Seg $G.V 'DarkGray'; [Console]::WriteLine()
}
function Sep { Seg ($G.ML + ($G.H * $W) + $G.MR) 'DarkGray'; [Console]::WriteLine() }
function Wipe { [Console]::BackgroundColor = [ConsoleColor]'Black'; [Console]::Clear() }
function Centered($text, $fg, $bg) {
    $l = [int][math]::Floor(($W - 1 - $text.Length) / 2)
    Row (Pt (' ' * $l)) (Pt $text $fg $bg)
}
function BarRow($pct, $color) {
    $n = 58
    $f = [int][math]::Floor([math]::Min(100, $pct) / 100 * $n)
    Row (Pt '  ') (Pt ($G.Bar * $f) $color) (Pt ($G.Track * ($n - $f)) 'DarkGray') (Pt ('  {0,3}%' -f [int]$pct) 'Gray')
}
function Frame($theme, $badge, [scriptblock]$body) {
    [Console]::CursorVisible = $false
    [Console]::SetCursorPosition(0, 0)
    Seg ($G.TL + ($G.H * $W) + $G.TR) 'DarkGray'; [Console]::WriteLine()
    Row
    foreach ($line in $Logo) { Row (Pt ('  ' + $line) 'White') }
    Row
    $sub = 'SECRETARY ' + $G.Dot + ' Officer Masterlist launcher'
    $pill = ' ' + $badge + ' '
    $gap = [math]::Max(1, $W - 4 - $sub.Length - $pill.Length)
    Row (Pt '  ') (Pt $sub 'Gray') (Pt (' ' * $gap)) (Pt $pill 'Black' $theme) (Pt ' ')
    Sep
    & $body
    Seg ($G.BL + ($G.H * $W) + $G.BR) 'DarkGray'; [Console]::WriteLine()
}
function StepRow($s) {
    $tag = ''; $tc = 'DarkGray'
    switch ($s.State) {
        'pending' { $ic = $G.Pend; $icc = 'DarkGray'; $nc = 'DarkGray'; $dc = 'DarkGray' }
        'run'     { $ic = $Spin[$script:Tick % $Spin.Count]; $icc = $script:Theme; $nc = 'White'; $dc = $script:Theme; $tag = ' RUN'; $tc = $script:Theme }
        'ok'      { $ic = $G.Ok; $icc = 'Green'; $nc = 'White'; $dc = 'DarkGray'; $tag = '  OK'; $tc = 'Green' }
        'warn'    { $ic = $G.Warn; $icc = 'Yellow'; $nc = 'Yellow'; $dc = 'Yellow'; $tag = 'WARN'; $tc = 'Yellow' }
        'fail'    { $ic = $G.Fail; $icc = 'Red'; $nc = 'Red'; $dc = 'Red'; $tag = 'FAIL'; $tc = 'Red' }
    }
    Row (Pt '  ') (Pt $ic $icc) (Pt ' ') (Pt ((Fit $s.Name 27).PadRight(28)) $nc) (Pt ((Fit $s.Detail 35).PadRight(36)) $dc) (Pt $tag $tc)
}
function Render {
    $total = [math]::Max(1, $script:Steps.Count)
    $done = @($script:Steps | Where-Object { $_.State -in 'ok', 'warn', 'fail' }).Count
    $pct = [math]::Min(100, ($done + $script:Frac) / $total * 100)
    $cur = $script:Steps | Where-Object { $_.State -eq 'run' } | Select-Object -First 1
    Frame $script:Theme $script:Badge {
        Row
        foreach ($s in $script:Steps) { StepRow $s }
        Row
        BarRow $pct $script:Theme
        Row
        if ($script:Banner) { Centered $script:Banner.T $script:Banner.F $script:Banner.B }
        else {
            $msg = 'Standing by'
            if ($cur) { $msg = $cur.Name + '...' }
            Row (Pt '  ') (Pt ((Fit $msg 56).PadRight(56)) 'Gray') (Pt ('T+' + ((Get-Date) - $script:T0).ToString('mm\:ss')) 'DarkGray')
        }
    }
}
# keeps the spinner and bar moving; redraws at most every 100 ms so fast loops stay fast
function Pump([switch]$NoSleep) {
    $script:Tick++
    if (((Get-Date) - $script:LastDraw).TotalMilliseconds -ge 100) { Render; $script:LastDraw = Get-Date }
    if (-not $NoSleep) { Start-Sleep -Milliseconds 80 }
}
function Res($state, $detail) { [pscustomobject]@{ State = $state; Detail = [string]$detail } }
function Step($name, [scriptblock]$action) { [pscustomobject]@{ Name = $name; State = 'pending'; Detail = ''; Action = $action } }

# ------------------------------- helpers -------------------------------
function Test-Port($p) {
    $c = New-Object Net.Sockets.TcpClient
    $ok = $false
    try { $a = $c.BeginConnect('127.0.0.1', [int]$p, $null, $null); $ok = ($a.AsyncWaitHandle.WaitOne(250) -and $c.Connected) } catch {}
    try { $c.Close() } catch {}
    [bool]$ok
}
function Test-Admin { ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator) }
function Wait-Proc($p, $maxSec) {
    $t = Get-Date
    while (-not $p.HasExited) {
        if (((Get-Date) - $t).TotalSeconds -gt $maxSec) { try { $p.Kill() } catch {}; return $false }
        Pump
    }
    $true
}
function Wait-Port($port, $sec) {
    $t = Get-Date
    while (((Get-Date) - $t).TotalSeconds -lt $sec) { if (Test-Port $port) { return $true }; Pump }
    Test-Port $port
}
function Hidden($file, $argList, $log = $null, $dir = $null, $stdin = $null) {
    $sp = @{ FilePath = $file; WindowStyle = 'Hidden'; PassThru = $true; ErrorAction = 'Stop' }
    if ($argList) { $sp.ArgumentList = $argList }
    if ($dir) { $sp.WorkingDirectory = $dir }
    if ($log) { $sp.RedirectStandardOutput = $log; $sp.RedirectStandardError = "$log.err" }
    if ($stdin) { $sp.RedirectStandardInput = $stdin }
    $p = Start-Process @sp
    $null = $p.Handle   # keeps the exit code readable after the program ends
    $p
}
function Size-Mb($b) { '{0:N1}' -f ($b / 1MB) }

# downloads a file with a live percentage in the step detail (e.g. "45%  70.1 / 150.3 MB")
function Download($url, $out, $step) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $req = [Net.HttpWebRequest]::Create($url)
    $req.UserAgent = 'Wget/1.21'   # SourceForge sends the file itself (not a mirror page) to download tools
    $req.AllowAutoRedirect = $true
    $req.Timeout = 30000
    $req.ReadWriteTimeout = 60000
    $resp = $req.GetResponse()
    $total = [long]$resp.ContentLength
    $in = $resp.GetResponseStream()
    $fs = [IO.File]::Create($out)
    try {
        $buf = New-Object byte[] 262144
        $done = [long]0
        while (($n = $in.Read($buf, 0, $buf.Length)) -gt 0) {
            $fs.Write($buf, 0, $n)
            $done += $n
            if ($total -gt 0) {
                $script:Frac = $done / $total
                $step.Detail = ('{0,3}%  {1} / {2} MB' -f [int]($done * 100 / $total), (Size-Mb $done), (Size-Mb $total))
            } else { $step.Detail = (Size-Mb $done) + ' MB' }
            Pump -NoSleep
        }
    } finally { $fs.Close(); $in.Close(); $resp.Close() }
    $done
}
function Get-DriveId {
    if ($DriveLink -match '/d/([\w-]+)') { return $Matches[1] }
    if ($DriveLink -match 'id=([\w-]+)') { return $Matches[1] }
    ''
}

# ------------------------------- steps -------------------------------
function Start-MySQL {
    if (Test-Port $MySQLPort) { return (Res 'ok' "already running :$MySQLPort") }
    [void](Hidden (Join-Path $Xampp 'mysql\bin\mysqld.exe') '--defaults-file=mysql\bin\my.ini --standalone' $null $Xampp)
    if (Wait-Port $MySQLPort 40) { return (Res 'ok' "online :$MySQLPort") }
    Res 'fail' "no answer on port $MySQLPort - is it used by another program?"
}
function Start-Apache {
    if (Test-Port $WebPort) { return (Res 'ok' "already running :$WebPort") }
    [void](Hidden (Join-Path $Xampp 'apache\bin\httpd.exe') $null $null $Xampp)
    if (Wait-Port $WebPort 30) { return (Res 'ok' "online :$WebPort") }
    Res 'fail' "no answer on port $WebPort - is it used by another program (Skype, IIS)?"
}
# database.sql only creates what is missing, so it is safe on every launch (existing records stay)
function Ensure-Database($okText) {
    $log = Join-Path $Logs 'database.log'
    $p = Hidden (Join-Path $Xampp 'mysql\bin\mysql.exe') '-uroot' $log $null (Join-Path $App 'database.sql')
    [void](Wait-Proc $p 120)
    if ($p.ExitCode -eq 0) { return (Res 'ok' $okText) }
    $script:FailLog = "$log.err"
    $why = (Get-Content "$log.err" -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($why -match 'Access denied') { return (Res 'fail' 'MySQL root has a password - put it in db.php') }
    Res 'fail' ('database error: ' + $why)
}
function Make-Shortcut {
    $path = [Environment]::GetFolderPath('Desktop') + '\TSV Secretary.lnk'
    if (Test-Path $path) { return (Res 'ok' 'already on the desktop') }
    $s = (New-Object -ComObject WScript.Shell).CreateShortcut($path)
    $s.TargetPath = (Join-Path $App 'tsv-secretary.bat'); $s.WorkingDirectory = $App
    $s.IconLocation = (Join-Path $Xampp 'xampp-control.exe') + ',0'
    $s.Save()
    if (Test-Path $path) { Res 'ok' 'TSV Secretary.lnk added' } else { Res 'warn' 'could not create the shortcut' }
}

function Install-Steps {
    $l = @()
    if (-not (Test-Path (Join-Path $Xampp 'mysql\bin\mysqld.exe'))) {
        $l += (Step 'Retrieving XAMPP' {
            $s = $script:Cur
            $n = Download $XamppUrl (Join-Path $Work 'xampp-installer.exe') $s
            if ($n -lt 50MB) { return (Res 'fail' 'download incomplete - check the internet') }
            Res 'ok' ('XAMPP 8.2.12 ' + (Size-Mb $n) + ' MB')
        })
        $l += (Step 'Installing XAMPP' {
            $script:Cur.Detail = 'this takes a few minutes...'
            $p = Hidden (Join-Path $Work 'xampp-installer.exe') ('--mode unattended --prefix "' + $Xampp + '"')
            [void](Wait-Proc $p 1800)
            if (Test-Path (Join-Path $Xampp 'mysql\bin\mysqld.exe')) { return (Res 'ok' "installed to $Xampp") }
            # silent mode did not work: open the normal installer, then Retry continues from here
            Start-Process (Join-Path $Work 'xampp-installer.exe')
            Res 'fail' 'finish the XAMPP installer (keep C:\xampp), then Retry'
        })
    }
    $l += (Step 'Retrieving TSV Secretary' {
        $id = Get-DriveId
        if (-not $id) { return (Res 'fail' 'DriveLink is not a Google Drive file link') }
        $zip = Join-Path $Work 'app.zip'
        $n = Download "https://drive.usercontent.google.com/download?id=$id&export=download&confirm=t" $zip $script:Cur
        $b = [IO.File]::ReadAllBytes($zip)
        if ($b.Length -lt 4 -or $b[0] -ne 0x50 -or $b[1] -ne 0x4B) { return (Res 'fail' 'Drive sent no zip - share it as "Anyone with the link"') }
        Res 'ok' ('site package ' + (Size-Mb $n) + ' MB')
    })
    $l += (Step 'Unpacking files' {
        $dest = Join-Path $Work 'app'
        Remove-Item $dest -Recurse -Force -ErrorAction SilentlyContinue
        $p = Hidden 'powershell.exe' ('-NoProfile -Command "Expand-Archive -LiteralPath ''' + (Join-Path $Work 'app.zip') + ''' -DestinationPath ''' + $dest + ''' -Force"')
        [void](Wait-Proc $p 300)
        # the zip may hold the files directly or inside one folder
        $script:Source = $null
        if (Test-Path (Join-Path $dest 'index.php')) { $script:Source = $dest }
        else { $script:Source = (Get-ChildItem $dest -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'index.php') } | Select-Object -First 1).FullName }
        if (-not $script:Source) { return (Res 'fail' 'the zip does not contain the TSV Secretary site') }
        Res 'ok' ([string](@(Get-ChildItem $script:Source -Recurse -File | Where-Object { $_.FullName -notmatch '\\\.git\\' }).Count) + ' files')
    })
    $l += (Step 'Copying to htdocs' {
        $p = Hidden 'robocopy.exe' ('"' + $script:Source + '" "' + $App + '" /E /XD .git /NFL /NDL /NJH /NJS /NP') (Join-Path $Logs 'robocopy.log')
        [void](Wait-Proc $p 300)
        if ($p.ExitCode -ge 8 -or -not (Test-Path (Join-Path $App 'index.php'))) { $script:FailLog = Join-Path $Logs 'robocopy.log'; return (Res 'fail' 'could not copy the files') }
        Res 'ok' $App
    })
    $l += (Step 'Starting MySQL' { Start-MySQL })
    $l += (Step 'Starting Apache' { Start-Apache })
    $l += (Step 'Creating database' { Ensure-Database 'tsv_secretary ready' })
    $l += (Step 'Desktop shortcut' { Make-Shortcut })
    $l += (Step 'Opening the site' { Start-Process $Site; Res 'ok' $Site })
    $l
}
function Launch-Steps {
    @(
        (Step 'Locating TSV Secretary' { Res 'ok' $App }),
        (Step 'Starting MySQL' { Start-MySQL }),
        (Step 'Starting Apache' { Start-Apache }),
        (Step 'Checking database' { Ensure-Database 'tsv_secretary ready' }),
        (Step 'Desktop shortcut' { Make-Shortcut }),
        (Step 'Opening the site' { Start-Process $Site; Res 'ok' $Site })
    )
}

function Run-Steps($theme, $badge) {
    $script:Theme = $theme; $script:Badge = $badge; $script:Banner = $null; $script:T0 = Get-Date; $script:FailLog = $null
    Wipe
    foreach ($s in $script:Steps) {
        if ($s.State -eq 'ok') { continue }   # Retry continues where it stopped
        $script:Cur = $s; $script:Frac = 0
        $s.State = 'run'; $s.Detail = 'working...'
        Pump
        $r = $null
        try { $r = @(& $s.Action) | Where-Object { $_ -and $_.PSObject.Properties['State'] } | Select-Object -Last 1 }
        catch { $r = Res 'fail' $_.Exception.Message }
        if (-not $r) { $r = Res 'ok' '' }
        $s.State = $r.State; $s.Detail = $r.Detail; $script:Frac = 0
        Render
        if ($r.State -eq 'fail') { $script:FailStep = $s; return 'fail' }
    }
    $script:Banner = @{ T = ('  READY IN ' + [math]::Round(((Get-Date) - $script:T0).TotalSeconds, 1) + ' s  '); F = 'Black'; B = 'Green' }
    for ($i = 0; $i -lt 12; $i++) { Pump }
    'ok'
}

# ------------------------------- screens -------------------------------
function ButtonRow($items, $sel) {
    $parts = @(Pt ' ')
    for ($i = 0; $i -lt $items.Count; $i++) {
        $label = '  ' + $items[$i].Label + '  '
        if ($i -eq $sel) { $parts += (Pt $label 'Black' $items[$i].Color) } else { $parts += (Pt $label $items[$i].Color) }
        $parts += (Pt ' ')
    }
    Row @parts
}
function Choose($items, $sel, [scriptblock]$draw) {
    Wipe
    $last = [datetime]::MinValue
    while ($true) {
        if (((Get-Date) - $last).TotalSeconds -ge 3) { & $draw $sel; $last = Get-Date }
        if ([Console]::KeyAvailable) {
            $k = [Console]::ReadKey($true)
            if ($k.Key -eq 'LeftArrow' -or $k.Key -eq 'UpArrow') { $sel = ($sel + $items.Count - 1) % $items.Count }
            elseif ($k.Key -eq 'RightArrow' -or $k.Key -eq 'DownArrow') { $sel = ($sel + 1) % $items.Count }
            elseif ($k.Key -eq 'Enter') { return $items[$sel].Id }
            else { foreach ($it in $items) { if ([string]$k.KeyChar -ieq $it.Label.Substring(0, 1)) { return $it.Id } } }
            & $draw $sel; $last = Get-Date
        } else { Start-Sleep -Milliseconds 100 }
    }
}
function Item($label, $color, $id) { [pscustomobject]@{ Label = $label; Color = $color; Id = $id } }
function SvcRow($label, $port) {
    $up = Test-Port $port
    $c = 'Red'; $st = 'OFFLINE'; if ($up) { $c = 'Green'; $st = 'ONLINE' }
    Row (Pt '  ') (Pt $G.Dot $c) (Pt ' ') (Pt ($label.PadRight(26)) 'White') (Pt ($st.PadRight(10)) $c) (Pt (':' + $port) 'DarkGray')
}
function Show-Online {
    $items = @((Item 'Open site' 'Cyan' 'open'), (Item 'Shut down servers' 'Red' 'stop'), (Item 'Close' 'Gray' 'close'))
    while ($true) {
        $a = Choose $items 0 {
            param($sel)
            Frame 'Green' 'ONLINE' {
                Row
                Centered '  TSV SECRETARY IS ONLINE  ' 'Black' 'Green'
                Row
                Row (Pt '  Open it at:' 'Gray')
                Row (Pt ('  ' + $G.Arrow + ' ') 'Green') (Pt $Site 'White')
                Row
                SvcRow 'Web server (Apache)' $WebPort
                SvcRow 'Database (MySQL)' $MySQLPort
                Row
                Row (Pt '  Close keeps the servers running; the desktop shortcut opens it again.' 'DarkGray')
                Sep
                ButtonRow $items $sel
                Row (Pt '  Left / Right  move        Enter  select' 'DarkGray')
            }
        }
        if ($a -eq 'open') { Start-Process $Site; continue }
        if ($a -eq 'stop') {
            Wipe
            Frame 'Magenta' 'SHUTDOWN' { Row; Row (Pt '  Stopping Apache and MySQL...' 'White'); Row }
            Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
            $p = Hidden (Join-Path $Xampp 'mysql\bin\mysqladmin.exe') '-uroot shutdown'
            [void]$p.WaitForExit(20000)
            Wipe
            Frame 'Magenta' 'OFFLINE' { Row; Centered '  SERVERS STOPPED - GOODBYE  ' 'White' 'DarkMagenta'; Row }
            Start-Sleep -Milliseconds 1200
        }
        return
    }
}
function Show-Fail {
    $s = $script:FailStep
    $tail = @()
    if ($script:FailLog -and (Test-Path $script:FailLog)) { $tail = @(Get-Content $script:FailLog -Tail 6 | Where-Object { $_.Trim() }) }
    $items = @((Item 'Retry' 'Yellow' 'retry'), (Item 'Open log' 'Cyan' 'log'), (Item 'Quit' 'Red' 'quit'))
    while ($true) {
        $a = Choose $items 0 {
            param($sel)
            Frame 'Red' 'FAILED' {
                Row
                Centered '  SETUP STOPPED  ' 'White' 'Red'
                Row
                Row (Pt '  Step   : ' 'Gray') (Pt (Fit $s.Name 60) 'White')
                Row (Pt '  Reason : ' 'Gray') (Pt (Fit $s.Detail 60) 'Red')
                Row
                for ($i = 0; $i -lt 6; $i++) { $line = ''; if ($i -lt $tail.Count) { $line = Fit $tail[$i] 66 }; Row (Pt ('  ' + $G.V + ' ' + $line) 'DarkGray') }
                Row
                Sep
                ButtonRow $items $sel
                Row (Pt '  Left / Right  move        Enter  select' 'DarkGray')
            }
        }
        if ($a -eq 'log') {
            if ($script:FailLog -and (Test-Path $script:FailLog)) { Start-Process notepad.exe ('"' + $script:FailLog + '"') } else { Start-Process explorer.exe ('"' + $Logs + '"') }
            continue
        }
        return $a
    }
}

# ------------------------------- main -------------------------------
try {
    $ui = $Host.UI.RawUI
    $ui.WindowTitle = 'TSV Secretary'
    $ui.BackgroundColor = 'Black'
    try { $ui.WindowSize = New-Object Management.Automation.Host.Size(80, 34) } catch {}
    try { $ui.BufferSize = New-Object Management.Automation.Host.Size(80, 34) } catch {}
    try { $ui.WindowSize = New-Object Management.Automation.Host.Size(80, 34) } catch {}
    New-Item -ItemType Directory -Force $Work, $Logs | Out-Null

    $installed = Test-Path (Join-Path $App 'index.php')
    # installing XAMPP into C:\ needs Administrator: ask once, the elevated copy carries on
    if (-not (Test-Path (Join-Path $Xampp 'mysql\bin\mysqld.exe')) -and -not (Test-Admin)) {
        Wipe
        Frame 'Yellow' 'PERMISSION' {
            Row
            Row (Pt '  XAMPP is not installed yet.' 'White')
            Row (Pt '  Windows will ask for permission to install it - click Yes.' 'Gray')
            Row
        }
        try { Start-Process -FilePath $Self -Verb RunAs -ErrorAction Stop } catch { Write-Host ''; Read-Host '  Permission was not given. Press Enter to close' }
        exit
    }

    if ($installed) { $script:Steps = Launch-Steps; $theme = 'Cyan'; $badge = 'STARTING' }
    else { $script:Steps = Install-Steps; $theme = 'Yellow'; $badge = 'INSTALLING' }
    while ($true) {
        $r = Run-Steps $theme $badge
        if ($r -eq 'ok') { Show-Online; break }
        if ((Show-Fail) -ne 'retry') { break }
    }
    if (-not $installed -and (Test-Path (Join-Path $App 'index.php'))) { Remove-Item (Join-Path $Work 'app'), (Join-Path $Work '*.zip'), (Join-Path $Work '*.exe') -Recurse -Force -ErrorAction SilentlyContinue }
} catch {
    [Console]::ResetColor()
    Write-Host ''
    Write-Host ('TSV Secretary launcher crashed: ' + $_.Exception.Message) -ForegroundColor Red
    Read-Host 'Press Enter to close'
} finally {
    [Console]::CursorVisible = $true
    [Console]::ResetColor()
}
