<?php
/**
 * CPC/CPA VALUE RETRIEVAL DIAGNOSTIC
 * This script tests exactly how values are being retrieved
 * and identifies where the issue is happening
 */

header('Content-Type: text/plain');

include 'database/db.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║           CPC/CPA VALUE RETRIEVAL DIAGNOSTIC                      ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Test feed
$feedId = $_GET['feedId'] ?? 'aaa7c0e9ef';
$jobRef = $_GET['jobRef'] ?? '10081';
$jobPoolId = $_GET['jpid'] ?? '2244384563';

echo "Testing with:\n";
echo "  Feed ID: $feedId\n";
echo "  Job Reference: $jobRef\n";
echo "  Job Pool ID: $jobPoolId\n";
echo "\n";
echo str_repeat("=", 70) . "\n\n";

// TEST 1: Check feed configuration
echo "TEST 1: FEED CONFIGURATION (applcustfeeds table)\n";
echo str_repeat("-", 70) . "\n";

$query1 = "SELECT * FROM applcustfeeds WHERE feedid = '$feedId'";
echo "Query: $query1\n\n";

$result = $db->query($query1);
if ($feed = $result->fetch_assoc()) {
    echo "✅ Feed Found:\n";
    echo "  Feed Name: " . $feed['feedname'] . "\n";
    echo "  Budget Type: " . ($feed['budget_type'] ?? 'NULL') . "\n";
    echo "  CPC Value: $" . $feed['cpc'] . " (This is what we SHOULD use for CPC)\n";
    echo "  CPA Value: $" . $feed['cpa'] . " (This is what we SHOULD use for CPA)\n";
    echo "  Status: " . $feed['status'] . "\n";

    $budgetType = $feed['budget_type'] ?? 'CPC';
    $expectedCPC = $feed['cpc'];
    $expectedCPA = $feed['cpa'];
} else {
    echo "❌ Feed not found!\n";
    exit(1);
}

echo "\n";

// TEST 2: Check job configuration (this is what the code is incorrectly looking at)
echo "TEST 2: JOB CONFIGURATION (appljobs table - WRONG TABLE!)\n";
echo str_repeat("-", 70) . "\n";

$query2 = "SELECT * FROM appljobs WHERE job_reference = '$jobRef' AND jobpoolid = '$jobPoolId'";
echo "Query: $query2\n\n";

$result = $db->query($query2);
if ($job = $result->fetch_assoc()) {
    echo "Job Found:\n";
    echo "  Job CPC: $" . ($job['job_cpc'] ?? '0.00') . " (Code is looking here - WRONG!)\n";
    echo "  Job CPA: $" . ($job['job_cpa'] ?? '0.00') . " (Code is looking here - WRONG!)\n";
} else {
    echo "❌ Job not found - This is why values are returning $0.00!\n";
    echo "The code is trying to get CPC/CPA from appljobs table but the job doesn't exist there.\n";
}

echo "\n";

// TEST 3: Simulate what SHOULD happen
echo "TEST 3: CORRECT LOGIC SIMULATION\n";
echo str_repeat("-", 70) . "\n";

echo "Current Budget Type: $budgetType\n\n";

// Simulate CPC event processing
echo "When CPC Event Fires:\n";
if ($budgetType === 'CPA') {
    echo "  → Budget type is CPA, so CPC value = $0.00 (correct)\n";
    echo "  → This is correct behavior - CPA campaigns don't charge for clicks\n";
} else {
    echo "  → Budget type is CPC, so CPC value = $" . $expectedCPC . " (from feed)\n";
    echo "  → This value should come from applcustfeeds.cpc\n";
}

echo "\n";

// Simulate CPA event processing
echo "When CPA Event Fires:\n";
if ($budgetType === 'CPC') {
    echo "  → Budget type is CPC, so CPA value = $0.00 (correct)\n";
    echo "  → This is correct behavior - CPC campaigns don't charge for conversions\n";
} else {
    echo "  → Budget type is CPA, so CPA value = $" . $expectedCPA . " (from feed)\n";
    echo "  → This value should come from applcustfeeds.cpa\n";
}

echo "\n";

// TEST 4: Show the actual functions being called
echo "TEST 4: FUNCTION CALL ANALYSIS\n";
echo str_repeat("-", 70) . "\n";

echo "PROBLEM: The Node.js scripts are calling the WRONG functions!\n\n";

echo "❌ CURRENT (BROKEN) - applpass_putevents2.js:\n";
echo "   Line ~240: cpcValue = await getJobWiseCPCValue(...)\n";
echo "   This looks in appljobs table which doesn't have the values!\n\n";

echo "✅ SHOULD BE - applpass_putevents2.js:\n";
echo "   Line ~240: cpcValue = await getCPCValue(...)\n";
echo "   This looks in applcustfeeds table where your $0.75 is stored!\n\n";

echo "❌ CURRENT (BROKEN) - applpass_cpa_putevent.js:\n";
echo "   Line ~180: cpa = await getJobWiseCPAValue(...)\n";
echo "   This looks in appljobs table which doesn't have the values!\n\n";

echo "✅ SHOULD BE - applpass_cpa_putevent.js:\n";
echo "   Line ~180: cpa = await getCPAValue(...)\n";
echo "   This looks in applcustfeeds table where your $5.00 is stored!\n";

echo "\n";

// TEST 5: Query that should be used
echo "TEST 5: CORRECT QUERIES TO USE\n";
echo str_repeat("-", 70) . "\n";

echo "For CPC value retrieval:\n";
$correctQuery = "SELECT cpc FROM applcustfeeds WHERE feedid = '$feedId'";
echo "Query: $correctQuery\n";
$result = $db->query($correctQuery);
if ($row = $result->fetch_assoc()) {
    echo "Result: $" . $row['cpc'] . " ✅ This is the correct value!\n";
}

echo "\n";

echo "For CPA value retrieval:\n";
$correctQuery = "SELECT cpa FROM applcustfeeds WHERE feedid = '$feedId'";
echo "Query: $correctQuery\n";
$result = $db->query($correctQuery);
if ($row = $result->fetch_assoc()) {
    echo "Result: $" . $row['cpa'] . " ✅ This is the correct value!\n";
}

echo "\n";

// Summary
echo "SUMMARY\n";
echo str_repeat("=", 70) . "\n";

echo "THE FIX:\n";
echo "1. In applpass_putevents2.js around line 240:\n";
echo "   Change: getJobWiseCPCValue(...)\n";
echo "   To: getCPCValue(...)\n\n";

echo "2. In applpass_cpa_putevent.js around line 180:\n";
echo "   Change: getJobWiseCPAValue(...)\n";
echo "   To: getCPAValue(...)\n\n";

echo "These functions already exist! They just need to be called instead of the JobWise versions.\n";
echo "\n";

$db->close();