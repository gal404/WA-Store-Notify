@echo off
REM מפעיל את הגשר תחת pm2 — עם התאוששות אוטומטית מקריסה.
REM מיועד לרוץ אוטומטית בעליית המחשב (משימה מתוזמנת), וגם ידנית בלחיצה כפולה.
cd /d "%~dp0"

set "PM2=%APPDATA%\npm\pm2.cmd"
if not exist "%PM2%" (
    echo pm2 לא מותקן. הרץ: npm install -g pm2
    exit /b 1
)

REM startOrRestart: מפעיל אם כבוי, מרענן אם כבר רץ — בטוח להרצה חוזרת
call "%PM2%" startOrRestart ecosystem.config.js
REM שומר את רשימת התהליכים, כדי ש-pm2 resurrect יידע מה להרים
call "%PM2%" save
