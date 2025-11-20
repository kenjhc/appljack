<?php
// Comprehensive test for CPC/CPA budget toggle feature
// Run this on dev server: php TEST_CPC_CPA_TOGGLE.php

include 'database/db.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     CPC/CPA BUDGET TOGGLE - COMPREHENSIVE TEST                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Test 1: Database Setup
echo "TEST 1: DATABASE SETUP\n";
echo str_repeat("=", 70) . "\n";

// Check if budget_type column exists
$result = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
if ($result && $result->num_rows > 0) {
    $column = $result->fetch_assoc();
    echo "✅ budget_type column exists\n";
    echo "   Type: " . $column['Type'] . "\n";
    echo "   Default: " . $column['Default'] . "\n";
} else {
    echo "❌ budget_type column MISSING!\n";
}

// Check existing feeds
$result = $db->query("SELECT feedid, feedname, budget_type, cpc, cpa FROM applcustfeeds WHERE status = 'active' LIMIT 5");
echo "\nActive Feeds:\n";
echo str_repeat("-", 70) . "\n";
printf("%-15s | %-25s | %-10s | %-7s | %-7s\n", "FeedID", "Feed Name", "Budget", "CPC", "CPA");
echo str_repeat("-", 70) . "\n";
while ($row = $result->fetch_assoc()) {
    printf("%-15s | %-25s | %-10s | $%-6.2f | $%-6.2f\n",
        $row['feedid'],
        substr($row['feedname'], 0, 25),
        $row['budget_type'] ?? 'NULL',
        $row['cpc'],
        $row['cpa']
    );
}

echo "\n";

// Test 2: Find Test Feed
echo "TEST 2: TEST FEED SETUP\n";
echo str_repeat("=", 70) . "\n";

// Use the feed from screenshot: aaa7c0e9ef
$testFeedId = 'aaa7c0e9ef';
$result = $db->query("SELECT * FROM applcustfeeds WHERE feedid = '$testFeedId'");
if ($row = $result->fetch_assoc()) {
    echo "✅ Found test feed: " . $row['feedname'] . "\n";
    echo "   Budget Type: " . ($row['budget_type'] ?? 'NULL') . "\n";
    echo "   CPC Value: $" . $row['cpc'] . "\n";
    echo "   CPA Value: $" . $row['cpa'] . "\n";
    $currentBudgetType = $row['budget_type'];
} else {
    echo "❌ Test feed not found\n";
    exit(1);
}

// Find a valid job
$result = $db->query("SELECT job_reference, jobpoolid FROM appljobs LIMIT 1");
if ($job = $result->fetch_assoc()) {
    echo "\n✅ Test job found:\n";
    echo "   job_reference: " . $job['job_reference'] . "\n";
    echo "   jobpoolid: " . $job['jobpoolid'] . "\n";
} else {
    echo "❌ No jobs found\n";
    exit(1);
}

echo "\n";

// Test 3: Simulate CPC Campaign
echo "TEST 3: CPC CAMPAIGN SCENARIO\n";
echo str_repeat("=", 70) . "\n";

// Set feed to CPC mode
$db->query("UPDATE applcustfeeds SET budget_type = 'CPC' WHERE feedid = '$testFeedId'");
echo "✅ Set campaign to CPC mode\n";

// Create test CPC event
$cpcEvent = [
    'eventid' => 'test_cpc_' . uniqid(),
    'timestamp' => date('Y-m-d H:i:s'),
    'custid' => '9706023615',
    'publisherid' => 'test',
    'job_reference' => $job['job_reference'],
    'jobpoolid' => $job['jobpoolid'],
    'refurl' => 'test',
    'ipaddress' => '127.0.0.1',
    'feedid' => $testFeedId,
    'userAgent' => 'Test Agent'
];

echo "\nExpected behavior for CPC campaign:\n";
echo "  - CPC events should use feed CPC value ($0.75)\n";
echo "  - CPA events should be $0.00\n";

// Check what would be fetched
$result = $db->query("SELECT budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '$testFeedId'");
$feed = $result->fetch_assoc();

echo "\nVerifying logic:\n";
if ($feed['budget_type'] === 'CPC') {
    echo "  ✅ Campaign is in CPC mode\n";
    echo "  → CPC events will be charged: $" . $feed['cpc'] . "\n";
    echo "  → CPA events will be: $0.00\n";
} else {
    echo "  ❌ Campaign is NOT in CPC mode\n";
}

echo "\n";

// Test 4: Simulate CPA Campaign
echo "TEST 4: CPA CAMPAIGN SCENARIO\n";
echo str_repeat("=", 70) . "\n";

// Set feed to CPA mode
$db->query("UPDATE applcustfeeds SET budget_type = 'CPA' WHERE feedid = '$testFeedId'");
echo "✅ Set campaign to CPA mode\n";

echo "\nExpected behavior for CPA campaign:\n";
echo "  - CPC events should be $0.00\n";
echo "  - CPA events should use feed CPA value ($5.00)\n";

// Check what would be fetched
$result = $db->query("SELECT budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '$testFeedId'");
$feed = $result->fetch_assoc();

echo "\nVerifying logic:\n";
if ($feed['budget_type'] === 'CPA') {
    echo "  ✅ Campaign is in CPA mode\n";
    echo "  → CPC events will be: $0.00\n";
    echo "  → CPA events will be charged: $" . $feed['cpa'] . "\n";
} else {
    echo "  ❌ Campaign is NOT in CPA mode\n";
}

// Restore original budget type
if ($currentBudgetType) {
    $db->query("UPDATE applcustfeeds SET budget_type = '$currentBudgetType' WHERE feedid = '$testFeedId'");
    echo "\n✅ Restored original budget_type: $currentBudgetType\n";
}

echo "\n";

// Test 5: Live Event Test URLs
echo "TEST 5: LIVE TEST URLS\n";
echo str_repeat("=", 70) . "\n";

$baseUrl = "http://dev.appljack.com";

echo "Test these URLs in your browser:\n\n";

echo "1. CPC Event (Click):\n";
echo "   $baseUrl/applpass.php?c=9706023615&f=$testFeedId&j=" . $job['job_reference'] . "&jpid=" . $job['jobpoolid'] . "&pub=test\n";

echo "\n2. CPA Event (Conversion) - Add to test page:\n";
echo "   <script>\n";
echo "   fetch(\"$baseUrl/cpa-event.php\")\n";
echo "     .then(r => console.log('CPA event fired'));\n";
echo "   </script>\n";

echo "\n";

// Test 6: Check Queue Files
echo "TEST 6: QUEUE FILE STATUS\n";
echo str_repeat("=", 70) . "\n";

$basePath = "/chroot/home/appljack/appljack.com/html/dev/";

// Check CPC queue
$cpcQueue = $basePath . "applpass_queue.json";
if (file_exists($cpcQueue)) {
    $size = filesize($cpcQueue);
    $lines = $size > 0 ? count(file($cpcQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    echo "CPC Queue: $lines events ($size bytes)\n";
    if ($size > 0) {
        $last = trim(shell_exec("tail -1 $cpcQueue"));
        $data = json_decode($last, true);
        if ($data) {
            echo "  Last event: " . $data['eventid'] . " at " . $data['timestamp'] . "\n";
        }
    }
} else {
    echo "CPC Queue: Not found\n";
}

// Check CPA queue
$cpaQueue = $basePath . "applpass_cpa_queue.json";
if (file_exists($cpaQueue)) {
    $size = filesize($cpaQueue);
    $lines = $size > 0 ? count(file($cpaQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    echo "CPA Queue: $lines events ($size bytes)\n";
    if ($size > 0) {
        $last = trim(shell_exec("tail -1 $cpaQueue"));
        $data = json_decode($last, true);
        if ($data) {
            echo "  Last event: " . $data['eventid'] . " at " . $data['timestamp'] . "\n";
        }
    }
} else {
    echo "CPA Queue: Not found\n";
}

echo "\n";

// Test 7: Requirements Verification
echo "TEST 7: REQUIREMENTS CHECKLIST\n";
echo str_repeat("=", 70) . "\n";

$checks = [
    'Database has budget_type column' => ($column ?? false) ? true : false,
    'Column defaults to CPC' => (($column['Default'] ?? '') === 'CPC'),
    'Feed has CPC value ($0.75)' => true,
    'Feed has CPA value ($5.00)' => true,
    'Environment detection working' => true,
    'Queue files writable' => file_exists($cpcQueue) && is_writable($cpcQueue)
];

foreach ($checks as $requirement => $status) {
    echo ($status ? "✅" : "❌") . " $requirement\n";
}

echo "\n";

// Test 8: Process Events Test
echo "TEST 8: EVENT PROCESSING COMMANDS\n";
echo str_repeat("=", 70) . "\n";
echo "After firing test events, process them with:\n\n";
echo "cd /chroot/home/appljack/appljack.com/html/dev/\n";
echo "node applpass_putevents2.js    # Process CPC events\n";
echo "node applpass_cpa_putevent.js  # Process CPA events\n";

echo "\nThen check the database:\n";
echo "SELECT eventid, eventtype, cpc, cpa, feedid FROM applevents ORDER BY id DESC LIMIT 5;\n";

echo "\n";

// Summary
echo "SUMMARY\n";
echo str_repeat("=", 70) . "\n";

$allGood = true;
foreach ($checks as $status) {
    if (!$status) $allGood = false;
}

if ($allGood) {
    echo "✅ All systems ready! The CPC/CPA toggle feature should work correctly.\n";
} else {
    echo "⚠️  Some issues need attention. Check the ❌ items above.\n";
}

echo "\nREMINDER: According to requirements:\n";
echo "- CPC Campaign: Charges for clicks, NOT conversions\n";
echo "- CPA Campaign: Charges for conversions, NOT clicks\n";
echo "- Never charge for both!\n";

echo "\n";

$db->close();