<?php
/**
 * PRODUCTION CPA TEST
 * Tests CPA conversions with matching CPC clicks
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PRODUCTION CPA CONVERSION TEST                            ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$basePath = __DIR__ . DIRECTORY_SEPARATOR;
include 'database/db.php';

// Get the CPA feed we set up earlier
$cpaFeed = $db->query("SELECT feedid, custid, budget_type, cpc, cpa FROM applcustfeeds WHERE feedid = '559c46798d'")->fetch_assoc();

echo "CPA Test Feed: " . $cpaFeed['feedid'] . "\n";
echo "Budget Type: " . $cpaFeed['budget_type'] . "\n";
echo "CPC: $" . $cpaFeed['cpc'] . "\n";
echo "CPA: $" . $cpaFeed['cpa'] . "\n\n";

$cpcQueue = $basePath . "applpass_queue.json";
$cpaQueue = $basePath . "applpass_cpa_queue.json";

// Step 1: Fire CPC click events (these will be in the database)
echo "STEP 1: Firing CPC clicks for CPA campaign...\n";
echo str_repeat("-", 70) . "\n";

for ($i = 1; $i <= 3; $i++) {
    $userAgent = "CPA Test User Agent $i";
    $ipAddress = "10.0.0.$i";

    $eventId = 'cpa_click_' . substr(uniqid(), -7);

    $cpcEvent = [
        'eventid' => $eventId,
        'custid' => $cpaFeed['custid'],
        'feedid' => $cpaFeed['feedid'],
        'job_reference' => 'cpa_job_' . $i,
        'jobpoolid' => 'cpa_pool_' . $i,
        'publisherid' => 'cpa_pub_' . $i,
        'timestamp' => date('Y-m-d H:i:s'),
        'userAgent' => $userAgent,
        'ipaddress' => $ipAddress,
        'refurl' => 'http://cpatest.com/job/' . $i
    ];

    file_put_contents($cpcQueue, json_encode($cpcEvent) . "\n", FILE_APPEND | LOCK_EX);
    echo "✅ CPC Click #$i: $eventId (UA: $userAgent, IP: $ipAddress)\n";
}

echo "\n  Run: node applpass_putevents2.js (to insert these clicks)\n";
echo "  Press Enter when done...\n";

// Wait for user to process CPC events
if (php_sapi_name() === 'cli') {
    fgets(STDIN);
} else {
    echo "\n  (Skipping wait in web mode)\n";
}

// Step 2: Fire matching CPA conversion events
echo "\nSTEP 2: Firing CPA conversions (matching the clicks above)...\n";
echo str_repeat("-", 70) . "\n";

for ($i = 1; $i <= 3; $i++) {
    $userAgent = "CPA Test User Agent $i";  // SAME as CPC click
    $ipAddress = "10.0.0.$i";                // SAME as CPC click

    $eventId = 'cpa_conv_' . substr(uniqid(), -7);

    $cpaEvent = [
        'eventid' => $eventId,
        'custid' => $cpaFeed['custid'],
        'timestamp' => date('Y-m-d H:i:s', time() + 60), // 1 minute after click
        'userAgent' => $userAgent,
        'ipaddress' => $ipAddress,
        'domain' => 'http://cpatest.com/conversion/' . $i,
        'job_reference' => 'cpa_conversion_' . $i
    ];

    file_put_contents($cpaQueue, json_encode($cpaEvent) . "\n", FILE_APPEND | LOCK_EX);
    echo "✅ CPA Conversion #$i: $eventId (UA: $userAgent, IP: $ipAddress)\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "READY TO PROCESS CPA CONVERSIONS\n";
echo str_repeat("=", 70) . "\n\n";

echo "Now run:\n";
echo "1. node applpass_cpa_putevent.js\n";
echo "2. php production_cpa_results.php\n\n";

$db->close();
