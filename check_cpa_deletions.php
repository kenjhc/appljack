<?php
header('Content-Type: text/plain');
include 'database/db.php';

echo "Checking appleventsdel table for CPA events...\n\n";

$result = $db->query("SELECT eventid, eventtype, deletecode, ipaddress, cpc, cpa, timestamp
                      FROM appleventsdel
                      WHERE eventid LIKE 'cpa_0315%'
                      ORDER BY id DESC");

echo "Found " . $result->num_rows . " CPA events in appleventsdel table\n";
echo str_repeat("-", 70) . "\n";

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "Event ID: " . $row['eventid'] . "\n";
        echo "  Type: " . $row['eventtype'] . "\n";
        echo "  Delete Code: " . $row['deletecode'] . "\n";
        echo "  IP Address: " . $row['ipaddress'] . "\n";
        echo "  CPC: $" . $row['cpc'] . "\n";
        echo "  CPA: $" . $row['cpa'] . "\n";
        echo "  Timestamp: " . $row['timestamp'] . "\n\n";
    }
} else {
    echo "\nNo CPA events found in appleventsdel table.\n";
    echo "This could mean:\n";
    echo "1. CPA events matched existing CPC clicks and went to applevents table\n";
    echo "2. CPA processor skipped them for another reason\n";
}
