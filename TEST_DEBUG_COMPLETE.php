<?php
/**
 * COMPREHENSIVE DEBUG AND TEST SCRIPT
 * Run this from SSH: php TEST_DEBUG_COMPLETE.php
 */

// Check if running from CLI
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    header('Content-Type: text/plain');
}

include 'database/db.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════╗\n";
echo "║           CPC/CPA BUDGET TOGGLE - COMPLETE DEBUG & TEST               ║\n";
echo "╚════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Detect environment
$currentPath = __DIR__;
if (strpos($currentPath, '/chroot/') !== false) {
    if (strpos($currentPath, '/dev/') !== false) {
        $environment = 'DEV_SERVER';
        $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    } else {
        $environment = 'PRODUCTION';
        $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    }
} else {
    $environment = 'LOCAL_DEV';
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}

echo "ENVIRONMENT: $environment\n";
echo "BASE PATH: $basePath\n";
echo str_repeat("=", 75) . "\n\n";

// Test 1: Check Files
echo "TEST 1: FILE SYSTEM CHECK\n";
echo str_repeat("-", 75) . "\n";

$files = [
    'applpass_queue.json' => 'CPC Queue',
    'applpass_cpa_queue.json' => 'CPA Queue',
    'applpass7.log' => 'CPC Log',
    'applpass_cpa.log' => 'CPA Log',
    'applpass_queue_backup.json' => 'CPC Backup',
    'applpass_cpa_backup.json' => 'CPA Backup'
];

foreach ($files as $file => $label) {
    $fullPath = $basePath . $file;
    echo sprintf("%-30s: ", $label);

    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
        $writable = is_writable($fullPath) ? 'YES' : 'NO';
        echo "✅ EXISTS | Size: {$size}B | Perms: $perms | Writable: $writable\n";

        // Show content preview for queue files
        if (strpos($file, 'queue.json') !== false && $size > 0) {
            $content = file_get_contents($fullPath);
            $lines = array_filter(explode("\n", $content));
            echo "                                Events: " . count($lines) . "\n";
            if (count($lines) > 0) {
                $last = json_decode(end($lines), true);
                if ($last) {
                    echo "                                Last Event ID: " . $last['eventid'] . " at " . $last['timestamp'] . "\n";
                }
            }
        }
    } else {
        echo "❌ NOT FOUND\n";
    }
}

echo "\n";

// Test 2: Database Check
echo "TEST 2: DATABASE CONFIGURATION\n";
echo str_repeat("-", 75) . "\n";

// Check budget_type column
$result = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
if ($result && $result->num_rows > 0) {
    $column = $result->fetch_assoc();
    echo "✅ budget_type column exists\n";
    echo "   Type: " . $column['Type'] . "\n";
    echo "   Default: " . $column['Default'] . "\n";
} else {
    echo "❌ budget_type column MISSING - Run ALTER TABLE!\n";
}

// Check test feed
$feedId = 'aaa7c0e9ef';
$result = $db->query("SELECT * FROM applcustfeeds WHERE feedid = '$feedId'");
if ($row = $result->fetch_assoc()) {
    echo "\n✅ Test Feed Found:\n";
    echo "   Feed ID: " . $row['feedid'] . "\n";
    echo "   Feed Name: " . $row['feedname'] . "\n";
    echo "   Budget Type: " . ($row['budget_type'] ?? 'NULL') . "\n";
    echo "   CPC Value: $" . $row['cpc'] . "\n";
    echo "   CPA Value: $" . $row['cpa'] . "\n";
    echo "   Status: " . $row['status'] . "\n";
} else {
    echo "❌ Test feed not found\n";
}

// Check for valid jobs
$result = $db->query("SELECT COUNT(*) as count FROM appljobs WHERE job_reference IS NOT NULL AND job_reference != ''");
$row = $result->fetch_assoc();
echo "\n✅ Valid jobs in database: " . $row['count'] . "\n";

// Get sample job
$result = $db->query("SELECT job_reference, jobpoolid FROM appljobs WHERE job_reference IS NOT NULL LIMIT 1");
if ($job = $result->fetch_assoc()) {
    echo "   Sample: job_reference=" . $job['job_reference'] . ", jobpoolid=" . $job['jobpoolid'] . "\n";
}

echo "\n";

// Test 3: Write Test
echo "TEST 3: FILE WRITE PERMISSIONS TEST\n";
echo str_repeat("-", 75) . "\n";

// Test CPC queue write
$testCPCEvent = [
    'eventid' => 'test_' . uniqid(),
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => true
];

$cpcFile = $basePath . "applpass_queue.json";
echo "Testing CPC queue write... ";
$result = @file_put_contents($cpcFile, json_encode($testCPCEvent) . "\n", FILE_APPEND | LOCK_EX);
if ($result !== false) {
    echo "✅ SUCCESS (wrote $result bytes)\n";
} else {
    echo "❌ FAILED\n";
    $error = error_get_last();
    if ($error) {
        echo "   Error: " . $error['message'] . "\n";
    }
    echo "   Try: chmod 666 $cpcFile\n";
}

// Test CPA queue write
$testCPAEvent = [
    'eventid' => 'test_' . uniqid(),
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => true
];

$cpaFile = $basePath . "applpass_cpa_queue.json";
echo "Testing CPA queue write... ";
$result = @file_put_contents($cpaFile, json_encode($testCPAEvent) . "\n", FILE_APPEND | LOCK_EX);
if ($result !== false) {
    echo "✅ SUCCESS (wrote $result bytes)\n";
} else {
    echo "❌ FAILED\n";
    $error = error_get_last();
    if ($error) {
        echo "   Error: " . $error['message'] . "\n";
    }
    echo "   Try: chmod 666 $cpaFile\n";
}

echo "\n";

// Test 4: Recent Logs
echo "TEST 4: RECENT ERROR LOGS\n";
echo str_repeat("-", 75) . "\n";

// Check CPC log
$logFile = $basePath . "applpass7.log";
if (file_exists($logFile) && filesize($logFile) > 0) {
    echo "CPC Log (last 5 entries):\n";
    $lines = file($logFile);
    $recent = array_slice($lines, -5);
    foreach ($recent as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "CPC Log: No entries\n";
}

echo "\n";

// Check CPA log
$logFile = $basePath . "applpass_cpa.log";
if (file_exists($logFile) && filesize($logFile) > 0) {
    echo "CPA Log (last 5 entries):\n";
    $lines = file($logFile);
    $recent = array_slice($lines, -5);
    foreach ($recent as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "CPA Log: No entries\n";
}

echo "\n";

// Test 5: Event Processing Simulation
echo "TEST 5: EVENT PROCESSING SIMULATION\n";
echo str_repeat("-", 75) . "\n";

// Simulate what happens when processing events
$result = $db->query("SELECT budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '$feedId'");
if ($feed = $result->fetch_assoc()) {
    $budgetType = $feed['budget_type'] ?? 'CPC';

    echo "Feed budget_type: $budgetType\n\n";

    echo "CPC Event Processing:\n";
    if ($budgetType === 'CPA') {
        echo "  → CPC value will be: $0.00 (correctly set to 0 for CPA campaign)\n";
    } else {
        echo "  → CPC value will be: $" . $feed['cpc'] . " (from feed)\n";
    }

    echo "\nCPA Event Processing:\n";
    if ($budgetType === 'CPC') {
        echo "  → CPA value will be: $0.00 (correctly set to 0 for CPC campaign)\n";
    } else {
        echo "  → CPA value will be: $" . $feed['cpa'] . " (from feed)\n";
    }
}

echo "\n";

// Test 6: Commands to Run
echo "TEST 6: COMMANDS TO RUN\n";
echo str_repeat("-", 75) . "\n";

echo "1. Fix Permissions (if needed):\n";
echo "   cd $basePath\n";
echo "   chmod 666 applpass_queue.json applpass_cpa_queue.json\n";
echo "   chmod 666 applpass7.log applpass_cpa.log\n";

echo "\n2. Fire Test Events:\n";
echo "   # CPC Event:\n";
echo "   curl \"http://dev.appljack.com/applpass.php?c=9706023615&f=$feedId&j=10081&jpid=2244384563\"\n";
echo "\n   # CPA Event:\n";
echo "   curl \"http://dev.appljack.com/cpa-event.php\"\n";

echo "\n3. Check Queue Files:\n";
echo "   cat $basePath" . "applpass_queue.json\n";
echo "   cat $basePath" . "applpass_cpa_queue.json\n";

echo "\n4. Process Events:\n";
echo "   cd $basePath\n";
echo "   node applpass_putevents2.js\n";
echo "   node applpass_cpa_putevent.js\n";

echo "\n5. Check Database:\n";
echo "   SELECT * FROM applevents ORDER BY id DESC LIMIT 5;\n";

echo "\n";

// Summary
echo "SUMMARY\n";
echo str_repeat("=", 75) . "\n";

$issues = [];

// Check for issues
if (!file_exists($basePath . "applpass_queue.json")) {
    $issues[] = "CPC queue file doesn't exist";
}
if (!file_exists($basePath . "applpass_cpa_queue.json")) {
    $issues[] = "CPA queue file doesn't exist";
}
if (!is_writable($basePath . "applpass_queue.json")) {
    $issues[] = "CPC queue file not writable";
}
if (!is_writable($basePath . "applpass_cpa_queue.json")) {
    $issues[] = "CPA queue file not writable";
}

if (count($issues) > 0) {
    echo "⚠️  Issues Found:\n";
    foreach ($issues as $issue) {
        echo "   - $issue\n";
    }
} else {
    echo "✅ All systems ready!\n";
}

echo "\n";

$db->close();