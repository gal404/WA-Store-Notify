# מריץ את הגשר ברקע (בלי חלון קונסולה) ופותח את הדשבורד בדפדפן —
# חלונות קונסולה של Windows (conhost) לא מציגים עברית טוב, אז זה עדיף על פני
# להסתכל על טקסט בחלון ריצה. הדשבורד עצמו (HTML בדפדפן) מציג עברית תקין לגמרי.

# בדיקת הרשאות מנהל — אם חסרות, מפעיל את הסקריפט מחדש עם אישור UAC
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $psExe = if (Get-Command pwsh -ErrorAction SilentlyContinue) { "pwsh" } else { "powershell" }
    Start-Process -FilePath $psExe -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

Set-Location $PSScriptRoot

# ניקוי תהליך ישן שנתקע על פורט 3200 מהפעלה קודמת
$c = Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue
if ($c) {
    $c | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 500
}

if (!(Test-Path "data")) { New-Item -ItemType Directory -Path "data" | Out-Null }
$logPath = Join-Path (Resolve-Path "data") "bridge-console.log"

# הפעלה ברקע לגמרי (בלי חלון) — הפלט (כולל עברית) נשמר לקובץ לוג לצפייה בעורך טקסט רגיל אם צריך
Start-Process -FilePath "cmd.exe" -ArgumentList "/c npm start > `"$logPath`" 2>&1" -WindowStyle Hidden

# ממתין שהדשבורד המקומי יעלה (עד 30 שניות), ואז פותח אותו בדפדפן ברירת המחדל
$deadline = (Get-Date).AddSeconds(30)
$up = $false
while ((Get-Date) -lt $deadline) {
    try {
        $r = Invoke-WebRequest -Uri "http://127.0.0.1:3200" -UseBasicParsing -TimeoutSec 1
        if ($r.StatusCode -eq 200) { $up = $true; break }
    } catch {}
    Start-Sleep -Milliseconds 500
}
Start-Process "http://127.0.0.1:3200"
