#!/bin/bash

LOCKFILE="/chroot/home/appljack/appljack.com/html/locks/cron_jobs.lock"

# Acquire lock
if ! mkdir "$LOCKFILE" 2>/dev/null; then
    echo "Another cron job is already running. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html"
LOGFILE="$DIR/applupdatexmlfeedstatus.log"
NODE="/usr/bin/node" # Ensure this is the correct path to the node executable
PYTHON="/usr/bin/python3" # Updated path to the Python 3 executable

echo "Current user: $(whoami)" >> "$LOGFILE"
echo "Environment variables:" >> "$LOGFILE"
env >> "$LOGFILE"
# Environment variables (if needed)
# export PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# export ANOTHER_VARIABLE=value

# Start new log file
echo "Running feed status updates $(date)" > "$LOGFILE"

# Run the script to clean events by IP address before checking budget status
echo "Running applcleanevents.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applcleanevents.js" >> "$LOGFILE" 2>&1

# Run the script to clean events by time range, and ip/feedid/jobid match before checking budget status
echo "Running applcleaneventstime.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applcleaneventstime.js" >> "$LOGFILE" 2>&1

# Run the script to check revenue vs budget caps
echo "Running applbudgetcheck.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applbudgetcheck.js" >> "$LOGFILE" 2>&1

# Release lock
rmdir "$LOCKFILE"
