@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"
title SmartPark - Node.js Parking Management System

cls
echo ======================================================================
echo           SmartPark - Vehicle Parking Management System
echo                  (Node.js, Express & MySQL Edition)
echo ======================================================================
echo.

:: 1. Check Node.js
where node >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Node.js was not found on your system.
    echo Please install Node.js: https://nodejs.org/
    echo.
    pause
    exit /b 1
)

:: 2. Check if node_modules exists, otherwise run npm install
if not exist "node_modules\" (
    echo Installing required Node.js packages...
    call npm install
    echo.
)

:: 3. Start server & open browser
echo ======================================================================
echo Local Server is running at: http://127.0.0.1:8000
echo Do NOT close this window while using the application.
echo ======================================================================
echo.

start "" "http://127.0.0.1:8000"

node app.js
pause

