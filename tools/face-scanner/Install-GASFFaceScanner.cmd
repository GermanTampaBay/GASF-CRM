@echo off
setlocal
rem pushd first: run from a network share, cmd cannot use a UNC folder as the
rem current directory, but pushd maps it to a temporary drive letter.
pushd "%~dp0" >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%CD%\Install-GASFFaceScanner.ps1"
set "code=%ERRORLEVEL%"
popd >nul
echo.
if not "%code%"=="0" echo Installation failed with exit code %code%.
pause
exit /b %code%
