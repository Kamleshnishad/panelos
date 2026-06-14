@echo off
setlocal enabledelayedexpansion

set PHP_PATH=E:\xampp\php\php.exe
set COMPOSER_PATH=C:\ProgramData\ComposerSetup\bin\composer.phar
set WORK_DIR=e:\Puff Panel MD Files DWW

cd /d "%WORK_DIR%"

echo Testing Composer...
echo.

REM Test 1: Check if Composer works at all
echo TEST 1: Composer version
"%PHP_PATH%" "%COMPOSER_PATH%" --version

echo.
echo TEST 2: Check PHP extensions required by Composer
"%PHP_PATH%" -m

echo.
echo TEST 3: Try creating project with verbose output
echo Running: composer create-project laravel/laravel backend -vvv --prefer-dist
"%PHP_PATH%" "%COMPOSER_PATH%" create-project laravel/laravel backend -vvv --prefer-dist

endlocal
