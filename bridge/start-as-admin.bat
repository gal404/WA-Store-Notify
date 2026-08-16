@echo off
REM DEPRECATED - kept only so existing shortcuts keep working.
REM This used to start the bridge WITHOUT pm2, which allowed a second instance
REM to run next to the pm2 one. Two instances sharing the WhatsApp session
REM folder corrupt it (the bridge then hangs at "authenticated", never "ready").
REM It now simply forwards to the single supported entry point.
call "%~dp0boot-start.bat"
