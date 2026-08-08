@echo off
setlocal
pushd "%~dp0" >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%CD%\Install-GASFFaceScanner.ps1"
set "code=%ERRORLEVEL%"
popd >nul
echo.
if not "%code%"=="0" echo Installation failed with exit code %code%.
pause
exit /b %code%
