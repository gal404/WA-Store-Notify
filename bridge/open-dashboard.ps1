# פותח את חלון הסטטוס של הגשר ומשאיר אותו פתוח.
# נפתח כ"חלון אפליקציה" של כרום (בלי סרגלי דפדפן) — נראה כמו חלון שרת ייעודי,
# והעברית מוצגת מושלם (בניגוד לחלון קונסולה של Windows).
# סגירת החלון *לא* עוצרת את הגשר — הוא ממשיך לרוץ תחת pm2.

$url   = 'http://127.0.0.1:3200'
$title = 'WA Store Notify'

# --- זיהוי חלונות פתוחים לפי כותרת ---
# בכוונה לא לפי שורת הפקודה של התהליך: כשכרום כבר רץ הוא פותח את החלון החדש
# דרך התהליך הקיים, ואז אין תהליך חדש עם --app ובדיקה כזו תמיד תיכשל.
if (-not ('WsnWin' -as [type])) {
    Add-Type @"
using System;using System.Text;using System.Runtime.InteropServices;using System.Collections.Generic;
public class WsnWin {
  [DllImport("user32.dll")] static extern bool EnumWindows(EnumProc f, IntPtr l);
  [DllImport("user32.dll")] static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] static extern bool IsWindowVisible(IntPtr h);
  delegate bool EnumProc(IntPtr h, IntPtr l);
  public static List<string> All(){
    var r=new List<string>();
    EnumWindows((h,l)=>{ if(IsWindowVisible(h)){ var sb=new StringBuilder(300); GetWindowText(h,sb,300);
      if(sb.Length>0) r.Add(sb.ToString()); } return true; }, IntPtr.Zero);
    return r; }
}
"@
}
function Test-DashboardOpen { @([WsnWin]::All() | Where-Object { $_ -like "*$title*" }).Count -gt 0 }

if (Test-DashboardOpen) { return }

# ממתין שהגשר יענה (עד 90 שניות) כדי לא לפתוח חלון על שרת שעוד לא עלה
$deadline = (Get-Date).AddSeconds(90)
while ((Get-Date) -lt $deadline) {
    try { Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2 | Out-Null; break }
    catch { Start-Sleep -Seconds 2 }
}

# איתור כרום — הרישום קודם, ואז נתיבי ההתקנה הרגילים
$chrome = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe' -ErrorAction SilentlyContinue).'(default)'
if (-not $chrome -or -not (Test-Path $chrome)) {
    $chrome = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
}

if ($chrome) {
    # הפעלה ישירה (ולא Start-Process) — כך כרום מקבל את --app כמו שצריך
    & $chrome "--app=$url" '--window-size=1150,850'
} else {
    Start-Process $url   # נפילה חזרה לדפדפן ברירת המחדל
}
