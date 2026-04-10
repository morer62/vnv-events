@echo off
REM Autopay Renewals Batch Script for Windows Task Scheduler
REM This script runs the autopay renewals cron job

cd C:\xampp\htdocs\vnv-venue
php src\cron\autopay-renewals.php >> .logs\autopay.log 2>&1
