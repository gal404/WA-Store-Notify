# בדיקת הרשאות מנהל — אם חסרות, מפעיל את הסקריפט מחדש עם אישור UAC
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Output "נדרשות הרשאות מנהל — מבקש אישור..."
    $psExe = if (Get-Command pwsh -ErrorAction SilentlyContinue) { "pwsh" } else { "powershell" }
    Start-Process -FilePath $psExe -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

$Host.UI.RawUI.WindowTitle = "WA Store Notify - גשר וואטסאפ"
Set-Location $PSScriptRoot

Write-Output "=== WA Store Notify - גשר וואטסאפ (מנהל מערכת) ==="
Write-Output ""
Write-Output "בודק אם פורט 3200 תפוס מהפעלה קודמת..."
$c = Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue
if ($c) {
    Write-Output "נמצא תהליך ישן — סוגר..."
    $c | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 500
}

Write-Output ""
Write-Output "מפעיל את הגשר..."
Write-Output ""
npm start

Write-Output ""
Write-Output "הגשר נעצר. חלון זה נשאר פתוח לצפייה בלוג."
Read-Host "לחץ Enter לסגירה"
