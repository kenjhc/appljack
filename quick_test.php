<?php
/**
 * QUICK TEST - Run this to verify everything is working
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════╗\n";
echo "║      QUICK CPC/CPA TOGGLE TEST             ║\n";
echo "╚════════════════════════════════════════════╝\n\n";

$tests = [];
$passed = 0;
$failed = 0;

// Test 1: Check environment
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (strpos($httpHost, 'dev.appljack.com') !== false) {
    echo "✅ Environment: DEV_SERVER\n";
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
    $passed++;
} else if (strpos($httpHost, 'appljack.com') !== false) {
    echo "✅ Environment: PRODUCTION\n";
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
    $passed++;
} else {
    echo "✅ Environment: LOCAL_DEV\n";
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
    $passed++;
}

// Test 2: Check database
include 'database/db.php';
if ($db && $db->ping()) {
    echo "✅ Database connected\n";
    $passed++;
} else {
    echo "❌ Database connection failed\n";
    $failed++;
}

// Test 3: Check budget_type column
$result = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
if ($result && $result->num_rows > 0) {
    echo "✅ budget_type column exists\n";
    $passed++;
} else {
    echo "❌ budget_type column missing\n";
    $failed++;
}

// Test 4: Check test feed
$result = $db->query("SELECT feedid, feedname, budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = 'aaa7c0e9ef'");
if ($row = $result->fetch_assoc()) {
    echo "✅ Test feed exists: {$row['feedname']}\n";
    echo "   Budget Type: " . ($row['budget_type'] ?? 'NULL') . "\n";
    echo "   CPC: $" . $row['cpc'] . ", CPA: $" . $row['cpa'] . "\n";
    $passed++;
} else {
    echo "❌ Test feed not found\n";
    $failed++;
}

// Test 5: Check queue files
$cpcQueue = $basePath . "applpass_queue.json";
$cpaQueue = $basePath . "applpass_cpa_queue.json";

if (file_exists($cpcQueue) && is_writable($cpcQueue)) {
    echo "✅ CPC queue writable\n";
    $passed++;
} else {
    echo "❌ CPC queue not writable - Run: chmod 666 $cpcQueue\n";
    $failed++;
}

if (file_exists($cpaQueue) && is_writable($cpaQueue)) {
    echo "✅ CPA queue writable\n";
    $passed++;
} else {
    echo "❌ CPA queue not writable - Run: chmod 666 $cpaQueue\n";
    $failed++;
}

// Test 6: Check CORS headers in applpass.php
if (file_exists('applpass.php')) {
    $content = file_get_contents('applpass.php');
    if (strpos($content, 'Access-Control-Allow-Origin') !== false) {
        echo "✅ applpass.php has CORS headers\n";
        $passed++;
    } else {
        echo "❌ applpass.php missing CORS headers\n";
        $failed++;
    }
}

// Test 7: Check test version exists
if (file_exists('applpass_test.php')) {
    echo "✅ applpass_test.php exists\n";
    $passed++;
} else {
    echo "❌ applpass_test.php not found\n";
    $failed++;
}

// Test 8: Test write to CPC queue
$testEvent = [
    'eventid' => 'quicktest_' . time(),
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => true
];

$result = @file_put_contents($cpcQueue, json_encode($testEvent) . "\n", FILE_APPEND | LOCK_EX);
if ($result !== false) {
    echo "✅ Successfully wrote test event to CPC queue\n";
    $passed++;
} else {
    echo "❌ Failed to write to CPC queue\n";
    $failed++;
}

// Summary
echo "\n";
echo str_repeat("=", 50) . "\n";
echo "RESULTS: $passed passed, $failed failed\n";
echo str_repeat("=", 50) . "\n\n";

if ($failed === 0) {
    echo "🎉 ALL TESTS PASSED!\n\n";
    echo "Next steps:\n";
    echo "1. Open: http://$httpHost/test_dashboard.html\n";
    echo "2. Fire CPC and CPA test events\n";
    echo "3. Check queues are updating\n";
    echo "4. Process queues: node applpass_putevents2.js\n";
} else {
    echo "⚠️  Some tests failed. Fix the issues above.\n\n";
    echo "Common fixes:\n";
    echo "- chmod 666 $cpcQueue\n";
    echo "- chmod 666 $cpaQueue\n";
    echo "- Make sure applpass.php has CORS headers\n";
}

echo "\n";
$db->close();