<?php
/**
 * DATABASE DEBUG - Check what's actually in the applevents table
 */

header('Content-Type: text/plain');

include 'database/db.php';

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          DATABASE EVENTS DEBUG                                     ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// Test 1: Check if applevents table exists
echo "TEST 1: Check applevents table\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SHOW TABLES LIKE 'applevents'");
if ($result && $result->num_rows > 0) {
    echo "✅ applevents table exists\n\n";
} else {
    echo "❌ applevents table NOT FOUND!\n";
    echo "This is the problem - the table doesn't exist.\n";
    exit;
}

// Test 2: Check table structure
echo "TEST 2: Table Structure\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("DESCRIBE applevents");
if ($result) {
    echo "Columns in applevents table:\n\n";
    while ($row = $result->fetch_assoc()) {
        echo sprintf("  %-20s %-20s %s\n",
            $row['Field'],
            $row['Type'],
            $row['Null'] === 'NO' ? 'REQUIRED' : 'optional'
        );
    }
} else {
    echo "❌ Could not describe table\n";
}

echo "\n";

// Test 3: Count total records
echo "TEST 3: Total Records\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT COUNT(*) as total FROM applevents");
if ($result) {
    $row = $result->fetch_assoc();
    $total = $row['total'];
    echo "Total events in database: $total\n\n";

    if ($total == 0) {
        echo "⚠️  DATABASE IS EMPTY - No events have been inserted yet!\n";
        echo "This means either:\n";
        echo "1. Events were never processed\n";
        echo "2. Processing failed with errors\n";
        echo "3. Events are stuck in the queue files\n\n";
    }
} else {
    echo "❌ Could not count records\n";
}

// Test 4: Show recent records
echo "TEST 4: Recent Records (Last 10)\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT * FROM applevents ORDER BY id DESC LIMIT 10");
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " recent events:\n\n";

    while ($row = $result->fetch_assoc()) {
        echo "Event ID: " . ($row['eventid'] ?? 'NULL') . "\n";
        echo "  Type: " . ($row['eventtype'] ?? 'NULL') . "\n";
        echo "  CPC: $" . ($row['cpc'] ?? '0.00') . "\n";
        echo "  CPA: $" . ($row['cpa'] ?? '0.00') . "\n";
        echo "  Feed: " . ($row['feedid'] ?? 'NULL') . "\n";
        echo "  Timestamp: " . ($row['timestamp'] ?? 'NULL') . "\n";
        echo "  Custid: " . ($row['custid'] ?? 'NULL') . "\n";
        echo str_repeat("-", 70) . "\n";
    }
} else {
    echo "❌ No records found\n\n";
    echo "DATABASE IS EMPTY!\n\n";
}

// Test 5: Check for events from today
echo "\nTEST 5: Today's Events\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT COUNT(*) as count FROM applevents WHERE DATE(timestamp) = CURDATE()");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Events from today: " . $row['count'] . "\n\n";
}

// Test 6: Check queue files
echo "TEST 6: Queue Files Status\n";
echo str_repeat("-", 70) . "\n";

$currentPath = __DIR__;
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

if (strpos($httpHost, 'dev.appljack.com') !== false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
} elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
} elseif (strpos($currentPath, '/chroot/') !== false) {
    if (strpos($currentPath, "/dev/") !== false) {
        $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    } else {
        $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    }
} else {
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

$cpcQueue = $basePath . "applpass_queue.json";
$cpaQueue = $basePath . "applpass_cpa_queue.json";

echo "CPC Queue: $cpcQueue\n";
if (file_exists($cpcQueue)) {
    $size = filesize($cpcQueue);
    echo "  Size: $size bytes\n";
    if ($size > 0) {
        $content = file_get_contents($cpcQueue);
        $lines = array_filter(explode("\n", $content));
        echo "  Events in queue: " . count($lines) . "\n";
        echo "  ⚠️  Events are waiting to be processed!\n";
    } else {
        echo "  Status: Empty (all processed)\n";
    }
} else {
    echo "  Status: File doesn't exist\n";
}

echo "\nCPA Queue: $cpaQueue\n";
if (file_exists($cpaQueue)) {
    $size = filesize($cpaQueue);
    echo "  Size: $size bytes\n";
    if ($size > 0) {
        $content = file_get_contents($cpaQueue);
        $lines = array_filter(explode("\n", $content));
        echo "  Events in queue: " . count($lines) . "\n";
        echo "  ⚠️  Events are waiting to be processed!\n";
    } else {
        echo "  Status: Empty (all processed)\n";
    }
} else {
    echo "  Status: File doesn't exist\n";
}

echo "\n";

// Test 7: Test the check_events.php query
echo "TEST 7: Check Events Query Test\n";
echo str_repeat("-", 70) . "\n";

$testQuery = "SELECT eventid, eventtype, cpc, cpa, feedid, timestamp FROM applevents ORDER BY id DESC LIMIT 10";
echo "Query: $testQuery\n\n";

$result = $db->query($testQuery);
if ($result) {
    echo "Query executed successfully\n";
    echo "Rows returned: " . $result->num_rows . "\n\n";

    if ($result->num_rows == 0) {
        echo "⚠️  Query works but returns 0 rows\n";
        echo "This means the database is empty!\n";
    }
} else {
    echo "❌ Query failed: " . $db->error . "\n";
}

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "DIAGNOSIS\n";
echo str_repeat("=", 70) . "\n";

// Get total again
$result = $db->query("SELECT COUNT(*) as total FROM applevents");
$row = $result->fetch_assoc();
$total = $row['total'];

if ($total == 0) {
    echo "\n❌ PROBLEM: Database is empty!\n\n";
    echo "This is why you're not seeing events in the dashboard.\n\n";
    echo "Possible causes:\n";
    echo "1. Events were never processed from queue files\n";
    echo "2. process_queues.php had errors (now fixed)\n";
    echo "3. Events are still waiting in queue files\n\n";
    echo "SOLUTION:\n";
    echo "1. Fire a test event from the dashboard\n";
    echo "2. Check that it writes to the queue file\n";
    echo "3. Click 'Process Queues' button\n";
    echo "4. Check for any errors\n";
    echo "5. Refresh database events\n\n";
    echo "OR manually insert a test event:\n";
    echo "INSERT INTO applevents (eventid, eventtype, custid, feedid, cpc, cpa, ipaddress, timestamp)\n";
    echo "VALUES ('test123', 'click', 'test', 'aaa7c0e9ef', 0.75, 0.00, '127.0.0.1', NOW());\n";
} else {
    echo "\n✅ Database has $total events\n";
    echo "The check_events.php should work correctly.\n";
}

echo "\n";

$db->close();