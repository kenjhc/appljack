<?php
/**
 * LOCAL TESTING SCRIPT
 * Quick test to verify everything works locally
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          LOCAL SETUP TEST                                          ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$basePath = __DIR__ . DIRECTORY_SEPARATOR;
echo "Base Path: $basePath\n\n";

// Test 1: Database
echo "TEST 1: Database Connection\n";
echo str_repeat("-", 70) . "\n";

include 'database/db.php';

if ($db && $db->ping()) {
    echo "✅ Database connected\n";

    // Check applevents table
    $result = $db->query("SHOW TABLES LIKE 'applevents'");
    if ($result && $result->num_rows > 0) {
        echo "✅ applevents table exists\n";

        // Count records
        $result = $db->query("SELECT COUNT(*) as total FROM applevents");
        $row = $result->fetch_assoc();
        echo "✅ Total events: " . $row['total'] . "\n";
    } else {
        echo "❌ applevents table NOT FOUND\n";
    }

    // Check for test feed
    $result = $db->query("SELECT * FROM applcustfeeds WHERE feedid = 'aaa7c0e9ef'");
    if ($result && $result->num_rows > 0) {
        $feed = $result->fetch_assoc();
        echo "✅ Test feed found: " . $feed['feedname'] . "\n";
        echo "   Budget Type: " . ($feed['budget_type'] ?? 'NULL') . "\n";
        echo "   CPC: $" . $feed['cpc'] . "\n";
        echo "   CPA: $" . $feed['cpa'] . "\n";
    } else {
        echo "⚠️  Test feed 'aaa7c0e9ef' not found\n";
    }
} else {
    echo "❌ Database connection FAILED\n";
    exit;
}

echo "\n";

// Test 2: Queue Files
echo "TEST 2: Queue Files\n";
echo str_repeat("-", 70) . "\n";

$cpcQueue = $basePath . "applpass_queue.json";
$cpaQueue = $basePath . "applpass_cpa_queue.json";

if (!file_exists($cpcQueue)) {
    touch($cpcQueue);
    chmod($cpcQueue, 0666);
    echo "✅ Created CPC queue file\n";
} else {
    echo "✅ CPC queue exists\n";
}

if (!file_exists($cpaQueue)) {
    touch($cpaQueue);
    chmod($cpaQueue, 0666);
    echo "✅ Created CPA queue file\n";
} else {
    echo "✅ CPA queue exists\n";
}

echo "   CPC Queue: " . (is_writable($cpcQueue) ? "✅ Writable" : "❌ Not writable") . "\n";
echo "   CPA Queue: " . (is_writable($cpaQueue) ? "✅ Writable" : "❌ Not writable") . "\n";

echo "\n";

// Test 3: Fire a test CPA event
echo "TEST 3: Fire Test CPA Event\n";
echo str_repeat("-", 70) . "\n";

// Generate short event ID (max 20 chars for char(20) column)
$shortEventId = 'test_' . substr(uniqid(), -10); // e.g., "test_b7d3828660" = 15 chars

$testEvent = [
    'eventid' => $shortEventId,
    'timestamp' => date('Y-m-d H:i:s'),
    'userAgent' => 'Local Test',
    'ipaddress' => '127.0.0.1',
    'domain' => 'http://localhost/test'
];

$result = file_put_contents($cpaQueue, json_encode($testEvent) . "\n", FILE_APPEND | LOCK_EX);
if ($result !== false) {
    echo "✅ Test CPA event written to queue ($result bytes)\n";
} else {
    echo "❌ Failed to write test event\n";
}

// Check queue
$content = file_get_contents($cpaQueue);
$lines = array_filter(explode("\n", $content));
echo "✅ CPA queue now has " . count($lines) . " events\n";

echo "\n";

// Test 4: Process the queue
echo "TEST 4: Process Queue (Test)\n";
echo str_repeat("-", 70) . "\n";

echo "Simulating queue processing...\n\n";

// Get active feed
$result = $db->query("SELECT feedid, budget_type, cpc, cpa FROM applcustfeeds WHERE status = 'active' LIMIT 1");
if ($feed = $result->fetch_assoc()) {
    echo "Found feed: " . $feed['feedid'] . "\n";
    echo "Budget type: " . ($feed['budget_type'] ?? 'NULL') . "\n";

    $budgetType = $feed['budget_type'] ?? 'CPC';
    $cpaValue = ($budgetType === 'CPA') ? $feed['cpa'] : 0.00;

    echo "CPA value would be: $" . $cpaValue . "\n";

    // Try inserting
    $eventid = $db->real_escape_string($testEvent['eventid']);
    $custid = 0; // custid is bigint - must be numeric
    $feedId = $db->real_escape_string($feed['feedid']);

    echo "Event ID length: " . strlen($eventid) . " chars (max 20)\n";

    $insertQuery = "INSERT INTO applevents (
        eventid, eventtype, custid, feedid, jobid, refurl, useragent,
        cpc, cpa, ipaddress, timestamp
    ) VALUES (
        '$eventid',
        'cpa',
        '$custid',
        '$feedId',
        'local_test',
        'http://localhost/test',
        'Local Test Agent',
        0.00,
        $cpaValue,
        '127.0.0.1',
        NOW()
    )";

    echo "\nAttempting insert...\n";

    if ($db->query($insertQuery)) {
        echo "✅ SUCCESS: Event inserted into database!\n";

        // Verify
        $result = $db->query("SELECT * FROM applevents WHERE eventid = '$eventid'");
        if ($result && $result->num_rows > 0) {
            $event = $result->fetch_assoc();
            echo "\nVerification:\n";
            echo "  Event ID: " . $event['eventid'] . "\n";
            echo "  Event Type: " . $event['eventtype'] . "\n";
            echo "  CPC: $" . $event['cpc'] . "\n";
            echo "  CPA: $" . $event['cpa'] . "\n";
            echo "  Feed: " . $event['feedid'] . "\n";
        }
    } else {
        echo "❌ FAILED: " . $db->error . "\n";
        echo "\nThis is the issue that's preventing inserts!\n";
    }
} else {
    echo "❌ No active feed found\n";
}

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "NEXT STEPS\n";
echo str_repeat("=", 70) . "\n";
echo "\n";
echo "1. Open dashboard: http://localhost/appljack/test_dashboard.html\n";
echo "2. Fire CPA event from dashboard\n";
echo "3. Click 'Process Queues'\n";
echo "4. View log: http://localhost/appljack/view_process_log.php\n";
echo "5. Check database events in dashboard\n";
echo "\n";

$db->close();