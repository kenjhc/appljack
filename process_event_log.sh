#!/bin/bash

# Set environment variables
export PATH=/usr/local/bin:/usr/bin:/bin

# Define lockfile for this cron job to prevent multiple simultaneous executions
LOCKFILE="/chroot/home/appljack/appljack.com/html/dev/locks/process_event_log.lock"

# Function to clean up the lockfile
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
    echo "Failed to acquire lock. Another cron job might already be running or there might be a permission issue. Exiting."
    exit 1
fi

# Paths
DIR="/chroot/home/appljack/appljack.com/html/dev"
LOGFILE="$DIR/process_event_log.log"
EVENT_LOG_FILE="$DIR/appljack_event_log.txt"
PHP_SCRIPT="$DIR/process_events.php"

# Start new log file entry
echo "Starting event log processing: $(date)" >> "$LOGFILE"

# Process the event log file
if [ -f "$EVENT_LOG_FILE" ]; then
    echo "Processing event log file..." >> "$LOGFILE"
    php "$PHP_SCRIPT" >> "$LOGFILE" 2>&1
    echo "Event log processed: $(date)" >> "$LOGFILE"
else
    echo "No event log file found: $(date)" >> "$LOGFILE"
fi

# Release lock and cleanup
cleanup
