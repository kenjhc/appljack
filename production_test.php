<?php
/**
 * PRODUCTION-READY CRON JOB TEST
 * Tests with REAL CPC/CPA values to verify budget toggle
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PRODUCTION-READY CRON JOB TEST                            ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$basePath = __DIR__ . DIRECTORY_SEPARATOR;
include 'database/db.php';

// Step 1: Find feeds with actual CPC/CPA values
echo "STEP 1: Finding feeds with CPC/CPA values...\n";
echo str_repeat("-", 70) . "\n";

$cpcFeedQuery = "SELECT feedid, custid, feedname, budget_type, cpc, cpa, status
                 FROM applcustfeeds
                 WHERE status = 'active'
                 AND cpc > 0
                 LIMIT 1";

$cpaFeedQuery = "SELECT feedid, custid, feedname, budget_type, cpc, cpa, status
                 FROM applcustfeeds
                 WHERE status = 'active'
                 AND cpa > 0
                 LIMIT 1";

$cpcFeedResult = $db->query($cpcFeedQuery);
$cpaFeedResult = $db->query($cpaFeedQuery);

$cpcFeed = $cpcFeedResult->fetch_assoc();
$cpaFeed = $cpaFeedResult->fetch_assoc();

if (!$cpcFeed) {
    echo "⚠️  No feed found with CPC > 0. Creating test feed with CPC value...\n";

    // Update the first active feed to have CPC value
    $db->query("UPDATE applcustfeeds
                SET cpc = 0.75, cpa = 5.00, budget_type = 'CPC'
                WHERE status = 'active'
                LIMIT 1");

    $cpcFeedResult = $db->query($cpcFeedQuery);
    $cpcFeed = $cpcFeedResult->fetch_assoc();
}

if (!$cpaFeed) {
    echo "⚠️  No feed found with CPA > 0. Using the same feed for CPA test...\n";
    $cpaFeed = $cpcFeed;
}

echo "✅ CPC Test Feed: " . $cpcFeed['feedid'] . "\n";
echo "   Name: " . ($cpcFeed['feedname'] ?? 'N/A') . "\n";
echo "   Budget Type: " . ($cpcFeed['budget_type'] ?? 'CPC') . "\n";
echo "   CPC: $" . $cpcFeed['cpc'] . "\n";
echo "   CPA: $" . $cpcFeed['cpa'] . "\n\n";

// Step 2: Ensure budget types are set correctly for testing
echo "STEP 2: Setting up test feeds...\n";
echo str_repeat("-", 70) . "\n";

// Set first feed to CPC mode
$db->query("UPDATE applcustfeeds
            SET budget_type = 'CPC', cpc = 0.75, cpa = 5.00
            WHERE feedid = '" . $cpcFeed['feedid'] . "'");

echo "✅ Set " . $cpcFeed['feedid'] . " to CPC mode (CPC: $0.75, CPA: $5.00)\n";

// Check if we have a second feed for CPA testing
$secondFeedQuery = "SELECT feedid, custid FROM applcustfeeds WHERE status = 'active' AND feedid != '" . $cpcFeed['feedid'] . "' LIMIT 1";
$secondFeedResult = $db->query($secondFeedQuery);

if ($secondFeedResult->num_rows > 0) {
    $cpaFeed = $secondFeedResult->fetch_assoc();

    // Set second feed to CPA mode
    $db->query("UPDATE applcustfeeds
                SET budget_type = 'CPA', cpc = 0.75, cpa = 5.00
                WHERE feedid = '" . $cpaFeed['feedid'] . "'");

    echo "✅ Set " . $cpaFeed['feedid'] . " to CPA mode (CPC: $0.75, CPA: $5.00)\n\n";
} else {
    echo "⚠️  Only one feed available - will test CPC mode only\n\n";
    $cpaFeed = null;
}

// Step 3: Count events before
$result = $db->query("SELECT COUNT(*) as total FROM applevents");
$beforeCount = $result->fetch_assoc()['total'];

echo "STEP 3: Database state before test\n";
echo str_repeat("-", 70) . "\n";
echo "Total events: $beforeCount\n\n";

// Step 4: Fire test events
echo "STEP 4: Firing test events to queues...\n";
echo str_repeat("-", 70) . "\n";

$cpcQueue = $basePath . "applpass_queue.json";
$cpaQueue = $basePath . "applpass_cpa_queue.json";

// Fire 3 CPC events for CPC campaign
for ($i = 1; $i <= 3; $i++) {
    $eventId = 'prod_cpc_' . substr(uniqid(), -8);

    $cpcEvent = [
        'eventid' => $eventId,
        'custid' => $cpcFeed['custid'],
        'feedid' => $cpcFeed['feedid'],
        'job_reference' => 'prod_job_' . $i,
        'jobpoolid' => 'prod_pool_' . $i,
        'publisherid' => 'prod_pub_' . $i,
        'timestamp' => date('Y-m-d H:i:s'),
        'userAgent' => 'Production Test CPC Agent ' . $i,
        'ipaddress' => '192.168.1.' . $i,
        'refurl' => 'http://production.test/cpc/' . $i
    ];

    file_put_contents($cpcQueue, json_encode($cpcEvent) . "\n", FILE_APPEND | LOCK_EX);
    echo "✅ CPC Event #$i: $eventId (Feed: " . $cpcFeed['feedid'] . " - CPC Mode)\n";
}

// Fire 3 CPA conversion events if we have a CPA feed
if ($cpaFeed) {
    echo "\n";
    for ($i = 1; $i <= 3; $i++) {
        $eventId = 'prod_cpa_' . substr(uniqid(), -8);

        $cpaEvent = [
            'eventid' => $eventId,
            'custid' => $cpaFeed['custid'],
            'timestamp' => date('Y-m-d H:i:s'),
            'userAgent' => 'Production Test CPA Agent ' . $i,
            'ipaddress' => '192.168.2.' . $i,
            'domain' => 'http://production.test/cpa/' . $i,
            'job_reference' => 'prod_conversion_' . $i
        ];

        file_put_contents($cpaQueue, json_encode($cpaEvent) . "\n", FILE_APPEND | LOCK_EX);
        echo "✅ CPA Event #$i: $eventId (Feed: " . $cpaFeed['feedid'] . " - CPA Mode)\n";
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "READY TO PROCESS\n";
echo str_repeat("=", 70) . "\n\n";

echo "Run these commands:\n";
echo "1. node applpass_putevents2.js\n";
echo "2. node applpass_cpa_putevent.js\n";
echo "3. php production_test_results.php\n\n";

$db->close();
