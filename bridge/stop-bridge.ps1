# עוצר את הגשר עצירה אמיתית.
# חשוב: קודם עוצרים ב-pm2 ורק אחר כך סוגרים תהליך שנותר. אחרת pm2 מזהה
# "קריסה" ומרים את הגשר מחדש — וזה נראה כאילו העצירה לא עבדה.

$pm2 = Join-Path $env:APPDATA 'npm\pm2.cmd'
if (Test-Path $pm2) {
    & $pm2 stop wa-store-notify 2>&1 | Out-Null
    Write-Output 'pm2: הגשר נעצר.'
} else {
    Write-Output 'pm2 לא מותקן — מדלג.'
}

# ניקוי מופע ידני/יתום שעדיין מחזיק את פורט 3200
$c = Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue
if ($c) {
    $c | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
    Write-Output 'נסגר גם תהליך שנותר על פורט 3200.'
}

Write-Output 'להפעלה מחדש: boot-start.bat'
