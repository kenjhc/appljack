#!/bin/bash

# Set environment variables
export PATH=/usr/local/bin:/usr/bin:/bin
export NODE_ENV=production

LOCKFILE="/chroot/home/appljack/appljack.com/html/admin/locks/cron_jobs.lock"

# Function to clean up lockfile
cleanup() {
    if [ -d "$LOCKFILE" ]; then
        rmdir "$LOCKFILE"
        echo "Lockfile removed."
    else
        echo "Lockfile already removed."
    fi
}

# Trap statements to ensure cleanup happens on exit or interruption
trap cleanup EXIT
trap cleanup INT
trap cleanup TERM

# Acquire lock
if ! mkdir "$LOCKFILE"; then
    echo "Failed to acquire lock. Error: $?"
    echo "Another cron job might already be running or there might be a permission issue. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html/admin"
LOGFILE="$DIR/applupdate2.log"
NODE="/usr/bin/node" # Ensure this is the correct path to the node executable
PYTHON="/usr/bin/python3" # Updated path to the Python 3 executable

# Start new log file
echo "Current user: $(whoami)" > "$LOGFILE"
echo "Environment variables:" >> "$LOGFILE"
env >> "$LOGFILE"
echo "Running job updates $(date)" >> "$LOGFILE"

# Run commands
echo "Running xmldl_all.js" >> "$LOGFILE"
$NODE "$DIR/xmldl_all.js" >> "$LOGFILE" 2>&1

echo "Running applfeedtransform.js" >> "$LOGFILE"
$NODE "$DIR/applfeedtransform.js" >> "$LOGFILE" 2>&1

echo "Running applupload7.js" >> "$LOGFILE"
$NODE "$DIR/applupload7.js" >> "$LOGFILE" 2>&1

echo "Running dbtoxml_appl2.js" >> "$LOGFILE"
$NODE "$DIR/dbtoxml_appl2.js" >> "$LOGFILE" 2>&1

echo "Running dbtoxml_combo.js" >> "$LOGFILE"
$NODE "$DIR/dbtoxml_combo.js" >> "$DIR/dbtoxml_combo_output.log" 2>> "$DIR/dbtoxml_combo_error.log"

echo "Running dbtoxml_combo_acct.js" >> "$LOGFILE"
$NODE "$DIR/dbtoxml_combo_acct.js" >> "$LOGFILE" 2>&1

echo "Finished job updates $(date)" >> "$LOGFILE"

# Release lock
cleanup
