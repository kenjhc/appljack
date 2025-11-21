<?php
/**
 * FIRE TEST EVENTS TO BOTH QUEUES
 * Creates sample CPC and CPA events for testing the Node.js processors
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          FIRE TEST EVENTS                                          ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$basePath = __DIR__ . DIRECTORY_SEPARATOR;

include 'database/db.php';

// Get a test feed
$result = $db->query("SELECT feedid, custid, budget_type, cpc, cpa FROM applcustfeeds WHERE status = 'active' LIMIT 1");
if (!$result || $result->num_rows === 0) {
    echo "❌ No active feed found in database\n";
    exit;
}

$feed = $result->fetch_assoc();
echo "Using test feed: " . $feed['feedid'] . "\n";
echo "Budget Type: " . ($feed['budget_type'] ?? 'CPC') . "\n";
echo "CPC: $" . $feed['cpc'] . "\n";
echo "CPA: $" . $feed['cpa'] . "\n\n";

// Fire 3 CPC events
echo "FIRING CPC EVENTS\n";
echo str_repeat("-", 70) . "\n";

$cpcQueue = $basePath . "applpass_queue.json";

for ($i = 1; $i <= 3; $i++) {
    $eventId = 'cpc_' . substr(uniqid(), -10);

    $cpcEvent = [
        'eventid' => $eventId,
        'custid' => $feed['custid'],
        'feedid' => $feed['feedid'],
        'job_reference' => 'test_job_' . $i,
        'jobpoolid' => 'test_pool_' . $i,
        'publisherid' => 'test_pub_' . $i, // REQUIRED by applpass_putevents2.js
        'timestamp' => date('Y-m-d H:i:s'),
        'userAgent' => 'Test CPC Agent ' . $i,
        'ipaddress' => '127.0.0.' . $i,
        'refurl' => 'http://localhost/test/cpc/' . $i
    ];

    file_put_contents($cpcQueue, json_encode($cpcEvent) . "\n", FILE_APPEND | LOCK_EX);
    echo "✅ CPC Event #$i: $eventId\n";
}

echo "\n";

// Fire 3 CPA events
echo "FIRING CPA EVENTS\n";
echo str_repeat("-", 70) . "\n";

$cpaQueue = $basePath . "applpass_cpa_queue.json";

for ($i = 1; $i <= 3; $i++) {
    $eventId = 'cpa_' . substr(uniqid(), -10);

    $cpaEvent = [
        'eventid' => $eventId,
        'custid' => $feed['custid'],
        'timestamp' => date('Y-m-d H:i:s'),
        'userAgent' => 'Test CPA Agent ' . $i,
        'ipaddress' => '127.0.0.' . ($i + 10),
        'domain' => 'http://localhost/test/cpa/' . $i,
        'job_reference' => 'test_conversion_' . $i
    ];

    file_put_contents($cpaQueue, json_encode($cpaEvent) . "\n", FILE_APPEND | LOCK_EX);
    echo "✅ CPA Event #$i: $eventId\n";
}

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "QUEUE STATUS\n";
echo str_repeat("=", 70) . "\n";

// Check queue status
$cpcContent = file_get_contents($cpcQueue);
$cpcLines = array_filter(explode("\n", $cpcContent));
echo "CPC Queue: " . count($cpcLines) . " events\n";

$cpaContent = file_get_contents($cpaQueue);
$cpaLines = array_filter(explode("\n", $cpaContent));
echo "CPA Queue: " . count($cpaLines) . " events\n";

echo "\n✅ Test events fired!\n\n";
echo "Next steps:\n";
echo "1. Run: node applpass_putevents2.js (to process CPC events)\n";
echo "2. Run: node applpass_cpa_putevent.js (to process CPA events)\n";
echo "3. Check database: php check_events.php\n";
echo "\n";

$db->close();
