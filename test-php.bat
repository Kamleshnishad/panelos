@echo off

echo Testing PHP directly...
echo.

set PHP=E:\xampp\php\php.exe

echo TEST 1: PHP version
%PHP% -v

echo.
echo TEST 2: PHP info (first 30 lines)
%PHP% -i | find /v "dummy"

echo.
echo TEST 3: Test PHP works with echo
%PHP% -r "echo 'PHP is working!' . PHP_EOL;"

echo.
echo TEST 4: List PHP ini file
%PHP% -i | findstr "Loaded Configuration File"

