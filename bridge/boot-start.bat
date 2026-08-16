@echo off
REM ============================================================
REM  THE one way to start the WA bridge.
REM   - starts it under pm2 (auto-restart on crash / memory limit)
REM   - leaves a status window open
REM   - never restarts a bridge that is already running, and never
REM     creates a second instance (two instances corrupt the session)
REM  Runs automatically at logon via the "WA Store Notify Bridge"
REM  scheduled task, and can also be double-clicked. Safe to re-run.
REM  NOTE: ASCII only - cmd.exe mangles UTF-8 comments and breaks the file.
REM ============================================================
where pwsh >nul 2>&1
if %errorLevel%==0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-bridge.ps1"
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-bridge.ps1"
)
