@echo off
setlocal
title RNN Besichtigungstermine - Protokoll Registrierung
echo =========================================================================
echo   Sonne & Cie. KG - Registrierung des rnn-besichtigung:// Protokolls
echo =========================================================================
echo.
echo Fuege Registry-Eintraege fuer den aktuellen Benutzer (HKCU) hinzu...
echo.

reg.exe add "HKCU\Software\Classes\rnn-besichtigung" /ve /d "URL:RNN Besichtigungstermine Protocol" /f >nul 2>&1
reg.exe add "HKCU\Software\Classes\rnn-besichtigung" /v "URL Protocol" /d "" /f >nul 2>&1
reg.exe add "HKCU\Software\Classes\rnn-besichtigung\shell\open\command" /ve /d "cmd.exe /c \"C:\Arbeit\web\rnnutils\besichtigungstermine\run_renapp.bat\"" /f >nul 2>&1

if %ERRORLEVEL% EQU 0 (
    echo [ERFOLG] Das Protokoll 'rnn-besichtigung://' wurde erfolgreich registriert!
    echo.
    echo Sie koennen das Besichtigungstermine-Modul nun direkt per Klick im
    echo Sonne Makler-Portal aufrufen.
) else (
    echo [FEHLER] Die Registrierung konnte nicht automatisch durchgefuehrt werden.
    echo Bitte fuehren Sie 'register_protocol.reg' per Doppelklick aus.
)

echo.
echo =========================================================================
pause
