<?php
header('Content-Type: text/plain');
include 'database/db.php';

echo "Checking for test events (cpc_03* and cpa_03*)...\n\n";

$result = $db->query("SELECT eventid, eventtype, feedid, cpc, cpa, timestamp
                      FROM applevents
                      WHERE eventid LIKE 'cpc_03%' OR eventid LIKE 'cpa_03%'
                      ORDER BY id DESC");

echo "Found " . $result->num_rows . " test events\n\n";

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo $row['eventid'] . " - " . $row['eventtype'] . " - Feed: " . $row['feedid'] .
             " - CPC: $" . $row['cpc'] . " - CPA: $" . ($row['cpa'] ?? '0.00') .
             " - " . $row['timestamp'] . "\n";
    }
} else {
    echo "No test events found. They may have been cleaned or database was reset.\n";
}

echo "\n\nTotal events in database:\n";
$result = $db->query("SELECT COUNT(*) as total FROM applevents");
$row = $result->fetch_assoc();
echo "Total: " . $row['total'] . "\n";
