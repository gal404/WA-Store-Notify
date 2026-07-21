@echo off
where pwsh >nul 2>&1
if %errorLevel%==0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-as-admin.ps1"
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-as-admin.ps1"
)
