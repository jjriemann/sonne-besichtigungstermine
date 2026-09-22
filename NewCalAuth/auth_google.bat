@echo off
setlocal enabledelayedexpansion

rem In das Verzeichnis dieser Batch-Datei wechseln
cd /d "%~dp0"

rem Xdebug deaktivieren
set XDEBUG_MODE=off

echo.
echo =========================================================================
echo  Google Kalender - Re-Authentifizierung
echo =========================================================================
echo.

php -d xdebug.mode=off auth_google.php

echo.
pause
