# פותח חלון קונסולה חי של השרת ומשאיר אותו פתוח.
# מציג בזמן אמת את פלט הגשר (pm2 logs): חיבור/ניתוק, הודעות שנשלחו ושגיאות.
#
# חשוב: סגירת החלון *לא* עוצרת את השרת — הוא רץ תחת pm2 ברקע.
# החלון הוא צוהר לצפייה בלבד. לעצירה אמיתית: stop-bridge.bat
#
# הערות מימוש:
#  • chcp 65001 — בלי זה חלון cmd מציג ג'יבריש במקום עברית.
#  • title בתוך ה-cmd — 'start "כותרת"' לבדו נדרס ע"י cmd /k.
#  • לא משתמשים ב-wt.exe: הוא כינוי-אפליקציה של WindowsApps ולא נפתח
#    כשמריצים אותו מהקשר לא-אינטראקטיבי (למשל משימה מתוזמנת).

$pm2   = Join-Path $env:APPDATA 'npm\pm2.cmd'
$title = 'WA Store Notify - server'

if (-not (Test-Path $pm2)) {
    Write-Output 'pm2 לא מותקן. הרץ:  npm install -g pm2'
    exit 1
}

# מניעת חלון כפול — לפי תהליך cmd שכבר מריץ את זרם הלוג
$existing = Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*pm2*logs*wa-store-notify*' }
if ($existing) { return }

& cmd.exe /c start "$title" cmd /k "chcp 65001 >nul && title $title && `"$pm2`" logs wa-store-notify --lines 40"
