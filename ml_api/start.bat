@echo off
cd /d "%~dp0"

echo ApexSocial - starting Python services...
echo.

netstat -ano | findstr ":5000" | findstr "LISTENING" >nul 2>&1
if %errorlevel%==0 (
    echo [skip] ML API already on port 5000
) else (
    echo [start] ML API - port 5000
    start "ApexSocial ML API" cmd /k "cd /d %~dp0 && python api.py"
)

netstat -ano | findstr ":8080" | findstr "LISTENING" >nul 2>&1
if %errorlevel%==0 (
    echo [skip] WebSocket already on port 8080
) else (
    echo [start] WebSocket - ports 8080 / 8081
    start "ApexSocial WebSocket" cmd /k "cd /d %~dp0 && python ws_server.py"
)

echo.
echo App: http://localhost/apexsocial/
echo Leave both CMD windows open while you work.
pause
