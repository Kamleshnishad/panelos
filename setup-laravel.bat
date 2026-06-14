@echo off
setlocal enabledelayedexpansion

REM Laravel Setup Script
echo Setting up PanelOS Laravel project...
echo.

REM Set paths
set PHP_PATH=E:\xampp\php\php.exe
set COMPOSER_PATH=C:\ProgramData\ComposerSetup\bin\composer.phar
set WORK_DIR=e:\Puff Panel MD Files DWW

REM Change to work directory
cd /d "%WORK_DIR%"

REM Display current settings
echo Current Directory: %cd%
echo PHP Path: %PHP_PATH%
echo Composer Path: %COMPOSER_PATH%
echo.

REM Create log file
set LOG_FILE=%WORK_DIR%\laravel-setup.log

echo Creating Laravel 11 project...
echo ===== Laravel Setup Log ===== > "%LOG_FILE%"
echo Time: %date% %time% >> "%LOG_FILE%"
echo PHP: %PHP_PATH% >> "%LOG_FILE%"
echo Composer: %COMPOSER_PATH% >> "%LOG_FILE%"
echo Working Dir: %WORK_DIR% >> "%LOG_FILE%"
echo. >> "%LOG_FILE%"

REM Run Composer with output to file
"%PHP_PATH%" "%COMPOSER_PATH%" create-project laravel/laravel backend --prefer-dist >> "%LOG_FILE%" 2>&1

REM Check if successful
if exist "%WORK_DIR%\backend\composer.json" (
    echo SUCCESS: Laravel project created! >> "%LOG_FILE%"
    echo Completed successfully at %date% %time% >> "%LOG_FILE%"
) else (
    echo FAILED: Laravel project creation failed >> "%LOG_FILE%"
    echo See above for errors >> "%LOG_FILE%"
)

echo.
echo Setup complete. Log saved to: %LOG_FILE%
type "%LOG_FILE%"

endlocal
