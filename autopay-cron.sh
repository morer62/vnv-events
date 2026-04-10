#!/bin/bash
# Autopay Renewals Cron Script for Linux Server
# This script runs the autopay renewals cron job
# Schedule: Daily at 2:00 AM

# Change to the project directory
cd /path/to/your/vnv-venue || exit 1

# Run the PHP cron job and log output
php src/cron/autopay-renewals.php >> .logs/autopay.log 2>&1

# Optional: Rotate logs if they get too large (keep last 30 days)
find .logs -name "autopay_*.log" -type f -mtime +30 -delete

