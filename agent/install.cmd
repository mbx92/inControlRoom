@echo off
setlocal
cd /d "%~dp0"

echo.
echo InfraControl Agent - dependency install
echo ======================================
echo.

where corepack >nul 2>&1
if errorlevel 1 (
  echo Corepack was not found. Install Node.js 20+ from https://nodejs.org
  exit /b 1
)

echo Using Corepack pnpm ^(recommended; avoids broken npm on mixed Node installs^)
corepack enable >nul 2>&1
corepack prepare pnpm@9.15.4 --activate
corepack pnpm install
if errorlevel 1 exit /b 1

echo.
echo Done.
echo   Run tests: npm test
echo.
echo If npm install fails on your machine, see README.md - Troubleshooting.
exit /b 0
