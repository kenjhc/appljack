#!/bin/bash

LOCKFILE="/chroot/home/appljack/appljack.com/html/admin/locks/update_xml_files_log.lock"

# Acquire lock
if ! mkdir "$LOCKFILE" 2>/dev/null; then
    echo "Another cron job is already running. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html/admin"
LOGFILE="$DIR/applupdatexmlfiles.log"
NODE="/usr/bin/node" # Ensure this is the correct path to the node executable
PYTHON="/usr/bin/python3" # Updated path to the Python 3 executable

echo "Current user: $(whoami)" >> "$LOGFILE"
echo "Environment variables:" >> "$LOGFILE"
env >> "$LOGFILE"
# Environment variables (if needed)
# export PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# export ANOTHER_VARIABLE=value

# Start new log file
echo "Running XML file updates $(date)" > "$LOGFILE"

# Run the script to empty xml files if the feeds are capped or stopped
echo "Running applonoffxmls.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applonoffxmls.js" >> "$LOGFILE" 2>&1

# Run the script to check the count of jobs in the appljobs table based on query in campaign
echo "Running applcountjobs2.js $(date)" >> "$LOGFILE"
"$NODE" "$DIR/applcountjobs2.js" >> "$LOGFILE" 2>&1

# Release lock
rmdir "$LOCKFILE"