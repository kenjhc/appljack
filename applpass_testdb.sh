#!/bin/bash

LOCKFILE="/chroot/home/appljack/appljack.com/html/admin/locks/cron_jobz.lock"

# Acquire lock
if ! mkdir "$LOCKFILE" 2>/dev/null; then
    echo "Another cron job is already running. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html/admin"
LOGFILE="$DIR/applpass_testdb_.log"
NODE="/usr/bin/node" # Ensure this is the correct path to the node executable
PYTHON="/usr/bin/python3" # Updated path to the Python 3 executable

echo "Current user: $(whoami)" >> "$LOGFILE"
echo "Environment variables:" >> "$LOGFILE"
env >> "$LOGFILE"
  
echo "Running testdb.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/testdb.js" >> "$LOGFILE" 2>&1

# Release lock
rmdir "$LOCKFILE"