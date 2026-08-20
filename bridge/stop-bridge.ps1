# עוצר את הגשר.
# במודל הנוכחי החלון *הוא* השרת, ולכן הדרך הרגילה לעצור היא פשוט לסגור
# את חלון "WA Store Notify - server". הסקריפט הזה נועד למקרה שהחלון נסגר
# בצורה חריגה והתהליך נשאר תלוי ברקע.

$found = $false

# מופע ישן שנוהל ע"י pm2 (לא אמור להתקיים יותר, אבל לוודא)
$pm2 = Join-Path $env:APPDATA 'npm\pm2.cmd'
if (Test-Path $pm2) { & $pm2 delete wa-store-notify 2>&1 | Out-Null }

# התהליך שמאזין על פורט 3200 — זה הגשר
$c = Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue
if ($c) {
    $c | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
    $found = $true
}

Start-Sleep -Seconds 2
if (Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'שים לב: עדיין יש תהליך על פורט 3200.'
} elseif ($found) {
    Write-Output 'הגשר נעצר.'
} else {
    Write-Output 'הגשר לא היה פעיל.'
}
Write-Output 'להפעלה מחדש: boot-start.bat'
