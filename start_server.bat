@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"
title SmartPark Parking Management System

cls
echo ======================================================================
echo           SmartPark - Vehicle Parking Management System
echo ======================================================================
echo.

:: 1. Auto-detect PHP Binary
set "PHP_BIN="
where php >nul 2>nul
if %errorlevel% equ 0 (
    set "PHP_BIN=php"
) else if exist "C:\php\php.exe" (
    set "PHP_BIN=C:\php\php.exe"
) else if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
) else if exist "D:\xampp\php\php.exe" (
    set "PHP_BIN=D:\xampp\php\php.exe"
)

if "%PHP_BIN%"=="" (
    echo [ERROR] PHP was not found on your system.
    echo Please install PHP or XAMPP, or add php.exe to your System PATH.
    echo Download PHP: https://windows.php.net/download/
    echo.
    pause
    exit /b 1
)

:: 2. Check if .env exists; if not, configure it interactively
if not exist ".env" (
    echo ----------------------------------------------------------------------
    echo  First-Time Setup: Database Configuration
    echo ----------------------------------------------------------------------
    echo.
    set "INPUT_DB_URL="
    set /p "INPUT_DB_URL=Enter DATABASE_URL [Press Enter for default: mysql://root@127.0.0.1:3306/parking_management]: "
    if "!INPUT_DB_URL!"=="" set "INPUT_DB_URL=mysql://root@127.0.0.1:3306/parking_management"
    
    set "INPUT_DB_PASS="
    set /p "INPUT_DB_PASS=Enter MySQL Password [Press Enter if empty]: "
    
    echo Creating .env file...
    (
        echo # ==============================================================================
        echo # Vehicle Parking Management System - Environment Configuration
        echo # ==============================================================================
        echo DATABASE_URL="!INPUT_DB_URL!"
        echo DB_PASSWORD="!INPUT_DB_PASS!"
        echo.
        echo APP_NAME="SmartPark - Parking Management System"
        echo APP_ENV="development"
        echo APP_TIMEZONE="Asia/Kolkata"
        echo.
        echo APP_CURRENCY_SYMBOL="₹"
        echo APP_CURRENCY_CODE="INR"
    ) > .env
    echo [.env created successfully!]
    echo.
)

:: 3. Automatically run database installer & verify schema
echo Initializing database schema and default rates...
"%PHP_BIN%" install.php
echo.

:: 4. Start local web server & open browser
echo ======================================================================
echo Local Server is running at: http://127.0.0.1:8000
echo Do NOT close this window while using the application.
echo ======================================================================
echo.

start "" "http://127.0.0.1:8000"

"%PHP_BIN%" -S 0.0.0.0:8000
pause
