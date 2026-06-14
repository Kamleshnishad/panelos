@echo off
REM ============================================================
REM  PanelOS quick backup — commit all changes and push to GitHub
REM  Double-click this file, type a short message, press Enter.
REM ============================================================
cd /d "%~dp0"

echo.
echo === Changes to be saved ===
git status --short
echo.

set "msg="
set /p msg="Commit message (blank = timestamp): "
if "%msg%"=="" set "msg=Backup %date% %time%"

git add -A
git commit -m "%msg%"
if errorlevel 1 (
  echo.
  echo Nothing to commit ^(or commit failed^). See message above.
  pause
  exit /b
)

echo.
echo === Pushing to GitHub... ===
git push
echo.
echo Done. Your work is backed up on GitHub.
pause
