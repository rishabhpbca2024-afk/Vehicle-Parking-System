@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"
title SmartPark - Vehicle Parking Management System (Node.js & MySQL)

cls
echo ======================================================================
echo           SmartPark - Vehicle Parking Management System
echo                  (Node.js, Express & MySQL Edition)
echo ======================================================================
echo.

:: 1. Check Node.js
where node >nul 2>nul
if %errorlevel% equ 0 (
    if not exist "node_modules\" (
        echo Installing Node.js dependencies...
        call npm install
        echo.
    )

    echo ======================================================================
    echo Local Server is running at: http://127.0.0.1:8000
    echo Do NOT close this window while using the application.
    echo ======================================================================
    echo.
    start "" "http://127.0.0.1:8000"
    node app.js
    pause
    exit /b 0
)

echo [ERROR] Node.js was not found on your system.
echo Please install Node.js from: https://nodejs.org/
echo.
pause
exit /b 1
