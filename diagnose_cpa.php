<?php
/**
 * CPA DIAGNOSTIC SCRIPT
 * Identifies issues with CPA queue and CPA value processing
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          CPA DIAGNOSTIC REPORT                                     ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

include 'database/db.php';

// 1. Check CPA Queue File
echo "1. CPA QUEUE FILE STATUS:\n";
echo str_repeat("-", 70) . "\n";

$cpaQueueFile = __DIR__ . DIRECTORY_SEPARATOR . "applpass_cpa_queue.json";

if (file_exists($cpaQueueFile)) {
    $size = filesize($cpaQueueFile);
    $perms = substr(sprintf('%o', fileperms($cpaQueueFile)), -4);
    $content = file_get_contents($cpaQueueFile);
    $lines = array_filter(explode("\n", $content));

    echo "✅ File exists: $cpaQueueFile\n";
    echo "   Size: $size bytes\n";
    echo "   Permissions: $perms\n";
    echo "   Events in queue: " . count($lines) . "\n";

    if (count($lines) > 0) {
        echo "\n   Last event:\n";
        $lastEvent = json_decode(end($lines), true);
        print_r($lastEvent);
    }
} else {
    echo "❌ Queue file does not exist: $cpaQueueFile\n";
    echo "   Creating empty file...\n";
    file_put_contents($cpaQueueFile, "");
    chmod($cpaQueueFile, 0666);
}

// 2. Check CPA Log File
echo "\n\n2. CPA LOG FILE (last 20 lines):\n";
echo str_repeat("-", 70) . "\n";

$cpaLogFile = __DIR__ . DIRECTORY_SEPARATOR . "applpass_cpa.log";

if (file_exists($cpaLogFile)) {
    $logContent = file($cpaLogFile);
    $lastLines = array_slice($logContent, -20);
    foreach ($lastLines as $line) {
        echo $line;
    }
} else {
    echo "❌ Log file does not exist\n";
}

// 3. Check Feed CPA Values
echo "\n\n3. FEED CPA VALUES:\n";
echo str_repeat("-", 70) . "\n";

$feedQuery = "SELECT feedid, feedname, budget_type, cpc, cpa, status
              FROM applcustfeeds
              WHERE status = 'active'
              ORDER BY feedid";

$result = $db->query($feedQuery);

echo sprintf("%-12s %-20s %-12s %-10s %-10s\n", "Feed ID", "Name", "Budget Type", "CPC", "CPA");
echo str_repeat("-", 70) . "\n";

$issueFeeds = [];

while ($row = $result->fetch_assoc()) {
    $budgetType = $row['budget_type'] ?? 'NOT SET';
    $cpc = $row['cpc'] ?? 'NULL';
    $cpa = $row['cpa'] ?? 'NULL';

    echo sprintf(
        "%-12s %-20s %-12s \$%-9s \$%-9s\n",
        $row['feedid'],
        substr($row['feedname'] ?? 'N/A', 0, 18),
        $budgetType,
        $cpc,
        $cpa
    );

    // Check for issues
    if ($budgetType === 'CPA' && (empty($cpa) || $cpa == 0)) {
        $issueFeeds[] = [
            'feedid' => $row['feedid'],
            'issue' => 'CPA campaign has no CPA value set'
        ];
    }
    if ($budgetType === 'NOT SET' || $budgetType === null) {
        $issueFeeds[] = [
            'feedid' => $row['feedid'],
            'issue' => 'budget_type not set'
        ];
    }
}

// 4. Report Issues
echo "\n\n4. DETECTED ISSUES:\n";
echo str_repeat("-", 70) . "\n";

if (count($issueFeeds) > 0) {
    foreach ($issueFeeds as $issue) {
        echo "❌ Feed " . $issue['feedid'] . ": " . $issue['issue'] . "\n";
    }
} else {
    echo "✅ No issues detected with feed configuration\n";
}

// 5. Check Recent CPA Events in Database
echo "\n\n5. RECENT CPA EVENTS IN DATABASE:\n";
echo str_repeat("-", 70) . "\n";

$cpaEventsQuery = "SELECT eventid, eventtype, feedid, cpc, cpa, timestamp
                   FROM applevents
                   WHERE eventtype = 'cpa'
                   ORDER BY id DESC
                   LIMIT 10";

$result = $db->query($cpaEventsQuery);

if ($result->num_rows > 0) {
    echo sprintf("%-20s %-12s %-10s %-10s %s\n", "Event ID", "Feed", "CPC", "CPA", "Timestamp");
    echo str_repeat("-", 70) . "\n";

    while ($row = $result->fetch_assoc()) {
        echo sprintf(
            "%-20s %-12s \$%-9.2f \$%-9.2f %s\n",
            $row['eventid'],
            $row['feedid'],
            $row['cpc'],
            $row['cpa'] ?? 0,
            $row['timestamp']
        );
    }
} else {
    echo "⚠️  No CPA events found in database\n";
}

// 6. Check appleventsdel for unmatched CPA events
echo "\n\n6. UNMATCHED CPA EVENTS (appleventsdel):\n";
echo str_repeat("-", 70) . "\n";

$deletedQuery = "SELECT eventid, deletecode, ipaddress, timestamp
                 FROM appleventsdel
                 WHERE eventtype = 'cpa'
                 ORDER BY id DESC
                 LIMIT 10";

$result = $db->query($deletedQuery);

if ($result->num_rows > 0) {
    echo sprintf("%-20s %-15s %-15s %s\n", "Event ID", "Delete Code", "IP Address", "Timestamp");
    echo str_repeat("-", 70) . "\n";

    while ($row = $result->fetch_assoc()) {
        echo sprintf(
            "%-20s %-15s %-15s %s\n",
            $row['eventid'],
            $row['deletecode'],
            $row['ipaddress'],
            $row['timestamp']
        );
    }
    echo "\n⚠️  These CPA events didn't match any CPC clicks (same IP/UserAgent)\n";
} else {
    echo "✅ No unmatched CPA events in appleventsdel\n";
}

// 7. Recommendations
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "RECOMMENDATIONS:\n";
echo str_repeat("=", 70) . "\n\n";

echo "If CPA events are NOT going into queue:\n";
echo "  1. Check cpa-event.php is being called (check applpass_cpa.log)\n";
echo "  2. Verify file permissions: chmod 666 applpass_cpa_queue.json\n";
echo "  3. Check the tracking pixel/script is implemented correctly\n\n";

echo "If CPA value is $0.00:\n";
echo "  1. Set budget_type = 'CPA' for CPA campaigns\n";
echo "  2. Set a CPA value > 0 in applcustfeeds table\n";
echo "  3. Run: UPDATE applcustfeeds SET cpa = 5.00, budget_type = 'CPA' WHERE feedid = 'YOUR_FEED_ID'\n\n";

echo "If CPA events go to appleventsdel:\n";
echo "  1. CPA conversion must have SAME IP and UserAgent as the prior CPC click\n";
echo "  2. CPA conversion must happen within 48 hours of the CPC click\n\n";

$db->close();
