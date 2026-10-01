@echo off
rem One-command local setup for Windows. Extra args are passed through, e.g.
rem   setup-local.cmd -MysqlRootPassword secret -SkipNpm
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\setup-local.ps1" %*
