@echo off
REM ============================================================
REM  THE one way to start the WA bridge.
REM  Double-click it (or use the desktop shortcut): THIS window becomes the
REM  server window and shows the live log. Closing it does NOT stop the
REM  server - it keeps running under pm2. To really stop: stop-bridge.bat
REM
REM  Also used by the "WA Store Notify Bridge" scheduled task at logon.
REM  NOTE: ASCII only - cmd.exe mangles UTF-8 comments and breaks the file.
REM ============================================================

REM UTF-8 first, otherwise Hebrew log lines show up as gibberish
chcp 65001 >nul
title WA Store Notify - server
cd /d "%~dp0"

echo ============================================
echo   WA Store Notify - bridge
echo ============================================
echo.

REM Make sure the bridge is running under pm2 (starts it only if it is down -
REM restarting a healthy bridge can leave WhatsApp stuck at "authenticated")
where pwsh >nul 2>&1
if %errorLevel%==0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-bridge.ps1"
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-bridge.ps1"
)

echo.
echo --- live log (closing this window does NOT stop the server) ---
echo.

REM Stream the live log in THIS window
call "%APPDATA%\npm\pm2.cmd" logs wa-store-notify --lines 40

REM If the log stream ever exits, keep the window open so the reason is visible
echo.
echo [log stream ended]
pause
