@echo off
where pwsh >nul 2>&1
if %errorLevel%==0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-bridge.ps1"
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-bridge.ps1"
)
pause
