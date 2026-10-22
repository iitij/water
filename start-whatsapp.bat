@echo off
title WhatsApp Local API Server
cd /d "%~dp0whatsapp-server"

set PATH=C:\laragon\bin\nodejs\node-v25;%PATH%

if not exist node_modules (
    call npm install
)

echo Starting Laravel Queue Worker... > ..\storage\logs\queue.log
cd ..
start /B "Laravel Queue Worker" cmd /c "php artisan queue:work >> storage\logs\queue.log 2>&1"
cd whatsapp-server

echo Starting WhatsApp Local Server... > ..\storage\logs\whatsapp.log
node server.js >> ..\storage\logs\whatsapp.log 2>&1
