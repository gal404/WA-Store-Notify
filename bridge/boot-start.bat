@echo off
REM ============================================================
REM  WA Store Notify - bridge server
REM
REM  THIS WINDOW *IS* THE SERVER.
REM  Closing this window STOPS the bridge - WhatsApp messages will not be
REM  sent until it is opened again. That is intentional (requested behaviour).
REM
REM  While the window is open, a crash is recovered automatically:
REM  the server is relaunched after a few seconds.
REM
REM  Opens automatically at logon via the "WA Store Notify Bridge" task.
REM  NOTE: ASCII only - cmd.exe mangles UTF-8 comments and breaks the file.
REM ============================================================

REM UTF-8 first, otherwise Hebrew output shows up as gibberish
chcp 65001 >nul
title WA Store Notify - server
cd /d "%~dp0"

REM Safety: make sure no pm2-managed copy is running in the background.
REM Two instances sharing the WhatsApp session folder corrupt it.
if exist "%APPDATA%\npm\pm2.cmd" (
    call "%APPDATA%\npm\pm2.cmd" delete wa-store-notify >nul 2>&1
    call "%APPDATA%\npm\pm2.cmd" save --force >nul 2>&1
)

echo ============================================
echo   WA Store Notify - bridge
echo   Closing this window STOPS the server.
echo ============================================
echo.

:run
node src\index.js

REM Reaching here means the server process exited on its own (a crash).
REM Closing the window never gets here - the child dies with the console.
echo.
echo [%date% %time%] Server exited unexpectedly - restarting in 5 seconds...
echo [Close this window to stop for good]
timeout /t 5 /nobreak >nul
echo.
goto run
