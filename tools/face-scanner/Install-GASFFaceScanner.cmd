@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-GASFFaceScanner.ps1"
set "code=%ERRORLEVEL%"
echo.
if not "%code%"=="0" echo Installation failed with exit code %code%.
pause
exit /b %code%
