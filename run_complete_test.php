<?php
/**
 * COMPREHENSIVE END-TO-END TEST RUNNER
 * Run this to test the entire CPC/CPA toggle implementation
 */

// Check if running from CLI
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    header('Content-Type: text/plain');
}

include 'database/db.php';

// ANSI color codes for CLI
$RED = $isCLI ? "\033[31m" : "";
$GREEN = $isCLI ? "\033[32m" : "";
$YELLOW = $isCLI ? "\033[33m" : "";
$BLUE = $isCLI ? "\033[34m" : "";
$RESET = $isCLI ? "\033[0m" : "";

echo "\n";
echo "${BLUE}╔════════════════════════════════════════════════════════════════════╗${RESET}\n";
echo "${BLUE}║       COMPREHENSIVE CPC/CPA TOGGLE TEST - FULL WORKFLOW           ║${RESET}\n";
echo "${BLUE}╚════════════════════════════════════════════════════════════════════╝${RESET}\n";
echo "\n";

// Auto-detect environment
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

echo "Environment: ${GREEN}$environment${RESET}\n";
echo "Base Path: $basePath\n\n";

// Test configuration
$feedId = 'aaa7c0e9ef';
$jobRef = '10081';
$jobPoolId = '2244384563';
$custId = '9706023615';

$testResults = [];

// TEST 1: Database Structure
echo "${YELLOW}TEST 1: DATABASE STRUCTURE${RESET}\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
if ($result && $result->num_rows > 0) {
    echo "${GREEN}✅ budget_type column exists${RESET}\n";
    $testResults['db_column'] = true;
} else {
    echo "${RED}❌ budget_type column MISSING${RESET}\n";
    $testResults['db_column'] = false;
}

// TEST 2: Feed Configuration
echo "\n${YELLOW}TEST 2: FEED CONFIGURATION${RESET}\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT * FROM applcustfeeds WHERE feedid = '$feedId'");
if ($feed = $result->fetch_assoc()) {
    echo "${GREEN}✅ Test feed found${RESET}\n";
    echo "  Feed: " . $feed['feedname'] . "\n";
    echo "  Budget Type: " . ($feed['budget_type'] ?? 'NULL') . "\n";
    echo "  CPC: $" . $feed['cpc'] . "\n";
    echo "  CPA: $" . $feed['cpa'] . "\n";
    $testResults['feed_exists'] = true;
    $originalBudgetType = $feed['budget_type'];
} else {
    echo "${RED}❌ Test feed not found${RESET}\n";
    $testResults['feed_exists'] = false;
}

// TEST 3: File Permissions
echo "\n${YELLOW}TEST 3: FILE PERMISSIONS${RESET}\n";
echo str_repeat("-", 70) . "\n";

$files = [
    'applpass_queue.json' => 'CPC Queue',
    'applpass_cpa_queue.json' => 'CPA Queue'
];

foreach ($files as $file => $label) {
    $fullPath = $basePath . $file;
    if (!file_exists($fullPath)) {
        echo "${YELLOW}Creating $label...${RESET}\n";
        touch($fullPath);
        chmod($fullPath, 0666);
    }

    if (is_writable($fullPath)) {
        echo "${GREEN}✅ $label is writable${RESET}\n";
        $testResults['file_' . $file] = true;
    } else {
        echo "${RED}❌ $label NOT writable${RESET}\n";
        $testResults['file_' . $file] = false;
        echo "  Run: chmod 666 $fullPath\n";
    }
}

// TEST 4: CPC Campaign Scenario
echo "\n${YELLOW}TEST 4: CPC CAMPAIGN SCENARIO${RESET}\n";
echo str_repeat("-", 70) . "\n";

// Set to CPC mode
$db->query("UPDATE applcustfeeds SET budget_type = 'CPC' WHERE feedid = '$feedId'");
echo "Set campaign to CPC mode\n";

// Create test event
$eventId = 'test_cpc_' . time();
$eventData = json_encode([
    'eventid' => $eventId,
    'timestamp' => date('Y-m-d H:i:s'),
    'custid' => $custId,
    'feedid' => $feedId,
    'job_reference' => $jobRef,
    'jobpoolid' => $jobPoolId,
    'test' => true
]);

// Write to queue
$cpcQueue = $basePath . "applpass_queue.json";
$result = file_put_contents($cpcQueue, $eventData . "\n", FILE_APPEND | LOCK_EX);

if ($result !== false) {
    echo "${GREEN}✅ CPC event written to queue${RESET}\n";
    $testResults['cpc_write'] = true;
} else {
    echo "${RED}❌ Failed to write CPC event${RESET}\n";
    $testResults['cpc_write'] = false;
}

// Check expected values
$result = $db->query("SELECT budget_type, cpc FROM applcustfeeds WHERE feedid = '$feedId'");
$feed = $result->fetch_assoc();

echo "\nExpected behavior for CPC campaign:\n";
echo "  CPC events should charge: ${GREEN}$" . $feed['cpc'] . "${RESET}\n";
echo "  CPA events should charge: ${GREEN}$0.00${RESET}\n";

// TEST 5: CPA Campaign Scenario
echo "\n${YELLOW}TEST 5: CPA CAMPAIGN SCENARIO${RESET}\n";
echo str_repeat("-", 70) . "\n";

// Set to CPA mode
$db->query("UPDATE applcustfeeds SET budget_type = 'CPA' WHERE feedid = '$feedId'");
echo "Set campaign to CPA mode\n";

// Create test event
$eventId = 'test_cpa_' . time();
$eventData = json_encode([
    'eventid' => $eventId,
    'timestamp' => date('Y-m-d H:i:s'),
    'domain' => 'test.com',
    'test' => true
]);

// Write to queue
$cpaQueue = $basePath . "applpass_cpa_queue.json";
$result = file_put_contents($cpaQueue, $eventData . "\n", FILE_APPEND | LOCK_EX);

if ($result !== false) {
    echo "${GREEN}✅ CPA event written to queue${RESET}\n";
    $testResults['cpa_write'] = true;
} else {
    echo "${RED}❌ Failed to write CPA event${RESET}\n";
    $testResults['cpa_write'] = false;
}

// Check expected values
$result = $db->query("SELECT budget_type, cpa FROM applcustfeeds WHERE feedid = '$feedId'");
$feed = $result->fetch_assoc();

echo "\nExpected behavior for CPA campaign:\n";
echo "  CPC events should charge: ${GREEN}$0.00${RESET}\n";
echo "  CPA events should charge: ${GREEN}$" . $feed['cpa'] . "${RESET}\n";

// TEST 6: Queue File Analysis
echo "\n${YELLOW}TEST 6: QUEUE FILE ANALYSIS${RESET}\n";
echo str_repeat("-", 70) . "\n";

// Check CPC queue
if (file_exists($cpcQueue)) {
    $lines = file($cpcQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "CPC Queue: " . count($lines) . " events\n";
    if (count($lines) > 0) {
        $last = json_decode(end($lines), true);
        if ($last) {
            echo "  Last event: " . $last['eventid'] . " at " . $last['timestamp'] . "\n";
        }
    }
}

// Check CPA queue
if (file_exists($cpaQueue)) {
    $lines = file($cpaQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "CPA Queue: " . count($lines) . " events\n";
    if (count($lines) > 0) {
        $last = json_decode(end($lines), true);
        if ($last) {
            echo "  Last event: " . $last['eventid'] . " at " . ($last['timestamp'] ?? 'unknown') . "\n";
        }
    }
}

// TEST 7: Value Retrieval Functions
echo "\n${YELLOW}TEST 7: VALUE RETRIEVAL ANALYSIS${RESET}\n";
echo str_repeat("-", 70) . "\n";

echo "Checking if Node.js scripts are using correct functions...\n\n";

// Check applpass_putevents2.js
$nodeFile = $basePath . "applpass_putevents2.js";
if (file_exists($nodeFile)) {
    $content = file_get_contents($nodeFile);

    if (strpos($content, 'getJobWiseCPCValue') !== false) {
        echo "${RED}❌ applpass_putevents2.js is using getJobWiseCPCValue (WRONG!)${RESET}\n";
        echo "   This looks in appljobs table instead of applcustfeeds\n";
        $testResults['cpc_function'] = false;
    } else if (strpos($content, 'getCPCValue') !== false) {
        echo "${GREEN}✅ applpass_putevents2.js is using getCPCValue (CORRECT!)${RESET}\n";
        $testResults['cpc_function'] = true;
    } else {
        echo "${YELLOW}⚠️  Cannot determine which function is being used${RESET}\n";
    }
}

// Check applpass_cpa_putevent.js
$nodeFile = $basePath . "applpass_cpa_putevent.js";
if (file_exists($nodeFile)) {
    $content = file_get_contents($nodeFile);

    if (strpos($content, 'getJobWiseCPAValue') !== false) {
        echo "${RED}❌ applpass_cpa_putevent.js is using getJobWiseCPAValue (WRONG!)${RESET}\n";
        echo "   This looks in appljobs table instead of applcustfeeds\n";
        $testResults['cpa_function'] = false;
    } else if (strpos($content, 'getCPAValue') !== false) {
        echo "${GREEN}✅ applpass_cpa_putevent.js is using getCPAValue (CORRECT!)${RESET}\n";
        $testResults['cpa_function'] = true;
    } else {
        echo "${YELLOW}⚠️  Cannot determine which function is being used${RESET}\n";
    }
}

// Restore original budget type
if (isset($originalBudgetType)) {
    $db->query("UPDATE applcustfeeds SET budget_type = '$originalBudgetType' WHERE feedid = '$feedId'");
}

// SUMMARY
echo "\n${BLUE}════════════════════════════════════════════════════════════════════${RESET}\n";
echo "${BLUE}                           TEST SUMMARY                             ${RESET}\n";
echo "${BLUE}════════════════════════════════════════════════════════════════════${RESET}\n\n";

$passed = 0;
$failed = 0;

foreach ($testResults as $test => $result) {
    if ($result) {
        $passed++;
        echo "${GREEN}✅ " . str_pad($test, 30) . " PASSED${RESET}\n";
    } else {
        $failed++;
        echo "${RED}❌ " . str_pad($test, 30) . " FAILED${RESET}\n";
    }
}

echo "\nTotal: $passed passed, $failed failed\n";

if ($failed === 0) {
    echo "\n${GREEN}🎉 ALL TESTS PASSED! The CPC/CPA toggle is working correctly.${RESET}\n";
} else {
    echo "\n${RED}⚠️  Some tests failed. Please fix the issues above.${RESET}\n";
}

// Provide next steps
echo "\n${YELLOW}NEXT STEPS:${RESET}\n";
echo "1. Access the testing dashboard: http://dev.appljack.com/test_dashboard.html\n";
echo "2. Run value retrieval diagnostic: php test_value_retrieval.php\n";
echo "3. Monitor event debug log: tail -f " . $basePath . "event_debug.log\n";
echo "4. Process queues: node applpass_putevents2.js && node applpass_cpa_putevent.js\n";

echo "\n";

$db->close();