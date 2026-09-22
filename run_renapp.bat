@echo off
setlocal enabledelayedexpansion

rem In das Verzeichnis dieser Batch-Datei wechseln
cd /d "%~dp0"

echo Starte Parameter-Eingabemaske...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0input_mask.ps1"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Workflow wurde abgebrochen.
    echo.
    pause
    exit /b 0
)

echo.
echo Starte PHP-Workflow...
echo.

php rental_appointments.php

pause