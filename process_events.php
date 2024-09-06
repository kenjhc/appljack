<?php
include 'database/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Path to the event log file
$logFilePath = '/chroot/home/appljack/appljack.com/html/appljack_event_log.txt';

// Open the log file for reading
$handle = fopen($logFilePath, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $eventData = json_decode($line, true);

        if (!$eventData) {
            error_log("[" . date('Y-m-d H:i:s') . "] Failed to decode JSON data: $line");
            continue;
        }

        // Prepare the SQL query for insertion
        $tableName = $eventData['tableName'];
        $query = $db->prepare("INSERT INTO $tableName (eventid, timestamp, custid, jobid, refurl, ipaddress, cpc, cpa, feedid, useragent, eventtype) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($query === false) {
            error_log("[" . date('Y-m-d H:i:s') . "] Prepare failed: (" . $db->errno . ") " . $db->error);
            continue;
        }

        $query->bind_param(
            "sssssssssss",
            $eventData['eventid'],
            $eventData['timestamp'],
            $eventData['custid'],
            $eventData['jobid'],
            $eventData['refurl'],
            $eventData['ipaddress'],
            $eventData['cpc'],
            $eventData['cpa'],
            $eventData['feedid'],
            $eventData['useragent'],
            $eventData['eventtype']
        );

        // Execute the query
        if (!$query->execute()) {
            error_log("[" . date('Y-m-d H:i:s') . "] Execute failed: (" . $query->errno . ") " . $query->error);
        } else {
            error_log("[" . date('Y-m-d H:i:s') . "] Event successfully added to table $tableName.");
        }
    }

    fclose($handle);

    // Optionally, delete the log file after processing
    unlink($logFilePath);
} else {
    error_log("[" . date('Y-m-d H:i:s') . "] Failed to open event log file for reading.");
}

$db->close();
