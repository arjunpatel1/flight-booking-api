@echo off
rem Starts the local API, queue worker and Reverb on Windows.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-local.ps1" %*
