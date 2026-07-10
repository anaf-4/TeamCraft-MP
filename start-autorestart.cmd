@echo off
TITLE TeamCraft-MP - Auto Restart
cd /d %~dp0

set PHP_BINARY=bin\php\php.exe
set LAUNCHER_FILE=PocketMine-MP.php

if not exist %PHP_BINARY% (
    echo [ERROR] PHP binary not found at %PHP_BINARY%
    echo Make sure this script is in the TeamCraft-MP root folder.
    pause
    exit /b 1
)

if not exist %LAUNCHER_FILE% (
    echo [ERROR] %LAUNCHER_FILE% not found.
    echo Make sure this script is in the TeamCraft-MP root folder.
    pause
    exit /b 1
)

:loop
echo.
echo [%date% %time%] Starting TeamCraft-MP (launcher: %LAUNCHER_FILE%)...
echo.
%PHP_BINARY% %LAUNCHER_FILE%
echo.
echo [%date% %time%] Server stopped. Restarting in 5 seconds...
echo (Ctrl+C twice now to cancel auto-restart and exit)
timeout /t 5 /nobreak
goto loop
