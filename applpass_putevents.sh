#!/bin/bash

LOCKFILE="/chroot/home/appljack/appljack.com/html/admin/locks/applpass_put_events_log.lock"

# Acquire lock
if ! mkdir "$LOCKFILE" 2>/dev/null; then
    echo "Another cron job is already running. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html/admin"
LOGFILE="$DIR/applpass_putevents.log"
NODE="/usr/bin/node" # Ensure this is the correct path to the node executable
PYTHON="/usr/bin/python3" # Updated path to the Python 3 executable

echo "Current user: $(whoami)" >> "$LOGFILE"
echo "Environment variables:" >> "$LOGFILE"
env >> "$LOGFILE"
# Environment variables (if needed)
# export PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# export ANOTHER_VARIABLE=value

# Start new log file
echo "Running event updates $(date)" > "$LOGFILE"

# Run the script to put the CPC events into the applevents table
echo "Running applpass_putevents2.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applpass_putevents2.js" >> "$LOGFILE" 2>&1

# Run the script to put the CPA events into the applevents table
echo "Running applpass_cpa_putevent.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applpass_cpa_putevent.js" >> "$LOGFILE" 2>&1

echo "Running stats_updater.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/stats_updater.js" >> "$LOGFILE" 2>&1

# Release lock
rmdir "$LOCKFILE"