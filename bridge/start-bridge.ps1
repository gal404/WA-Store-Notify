# מפעיל את הגשר תחת pm2 ופותח את חלון הסטטוס.
# עיקרון: לא נוגעים בגשר שכבר רץ. אתחול מיותר עלול להשאיר את וואטסאפ תקוע
# במצב "authenticated" בלי להגיע ל-"ready", ולכן מפעילים רק אם הוא באמת כבוי.

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$pm2 = Join-Path $env:APPDATA 'npm\pm2.cmd'
if (-not (Test-Path $pm2)) {
    Write-Output 'pm2 לא מותקן. הרץ:  npm install -g pm2'
    exit 1
}

# האם התהליך כבר online אצל pm2?
$online = $false
try {
    $raw = & $pm2 jlist 2>$null
    if ($raw) {
        $list = $raw | ConvertFrom-Json -AsHashtable
        $app  = $list | Where-Object { $_.name -eq 'wa-store-notify' }
        if ($app -and $app.pm2_env.status -eq 'online') { $online = $true }
    }
} catch { $online = $false }

if ($online) {
    Write-Output 'הגשר כבר רץ — לא מאתחלים אותו (כדי לא לנתק חיבור תקין).'
} else {
    Write-Output 'מפעיל את הגשר...'
    & $pm2 start ecosystem.config.js 2>&1 | Out-Null
    & $pm2 save 2>&1 | Out-Null
}

# פותח את חלון הקונסולה של השרת (לוג חי). אם כבר פתוח — לא נפתח כפול.
# סגירת החלון לא עוצרת את השרת.
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'open-console.ps1')
