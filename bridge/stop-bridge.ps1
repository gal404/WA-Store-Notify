# עוצר את הגשר הרץ ברקע (מחפש לפי מי שמאזין על פורט 3200)
$c = Get-NetTCPConnection -LocalPort 3200 -State Listen -ErrorAction SilentlyContinue
if ($c) {
    $c | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
    Write-Output "העצירה בוצעה."
} else {
    Write-Output "הגשר לא היה פעיל."
}
