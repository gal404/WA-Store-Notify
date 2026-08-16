# Resets the WhatsApp session so the bridge can be re-linked with a fresh QR scan.
# Reversible: the current session folder is BACKED UP (never deleted outright),
# so it can be restored if the reset does not help.
#
# Use when the bridge is stuck at state "authenticated" and never reaches "ready"
# (a corrupted LocalAuth session - e.g. after two bridge instances ran at once).
#
# Requires the store's phone to scan the QR afterwards.

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$pm2 = Join-Path $env:APPDATA 'npm\pm2.cmd'
$session = Join-Path $PSScriptRoot 'session'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = Join-Path $PSScriptRoot "session-backup-$stamp"

Write-Output '1/4 עוצר את הגשר...'
if (Test-Path $pm2) { & $pm2 stop wa-store-notify 2>&1 | Out-Null }
# משחרר גם מופע ידני שתופס את פורט 3200
Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue |
    ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 3

Write-Output '2/4 מגבה את הסשן הקיים...'
if (Test-Path $session) {
    Move-Item -LiteralPath $session -Destination $backup
    Write-Output "    גובה אל: $backup"
} else {
    Write-Output '    אין תיקיית סשן קיימת - ממשיך.'
}

Write-Output '3/4 מפעיל מחדש...'
if (Test-Path $pm2) {
    & $pm2 startOrRestart ecosystem.config.js 2>&1 | Out-Null
    & $pm2 save 2>&1 | Out-Null
} else {
    Write-Output '    pm2 חסר - הרץ: npm install -g pm2'
    exit 1
}

Write-Output '4/4 ממתין לקוד QR (עד 90 שניות)...'
$deadline = (Get-Date).AddSeconds(90)
while ((Get-Date) -lt $deadline) {
    try {
        $r = Invoke-RestMethod -Uri 'http://127.0.0.1:3200/api/status' -TimeoutSec 3
        if ($r.state -eq 'qr') {
            Write-Output ''
            Write-Output 'קוד QR מוכן! פתח:  http://127.0.0.1:3200'
            Write-Output 'בטלפון: וואטסאפ > מכשירים מקושרים > קישור מכשיר'
            Start-Process 'http://127.0.0.1:3200'
            exit 0
        }
        if ($r.state -eq 'ready') {
            Write-Output 'הגשר עלה ומחובר (לא נדרש QR).'
            exit 0
        }
    } catch { }
    Start-Sleep -Seconds 5
}
Write-Output "עדיין אין QR. בדוק לוג:  $env:USERPROFILE\.pm2\logs\wa-store-notify-error.log"
Write-Output "לשחזור הסשן הקודם: עצור את הגשר, מחק את תיקיית 'session', ושנה בחזרה את '$backup' ל-'session'."
