<?php
// Quick status check script
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║        EVENT TRACKING STATUS CHECK                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$cpcQueue = __DIR__ . DIRECTORY_SEPARATOR . "applpass_queue.json";
$cpaQueue = __DIR__ . DIRECTORY_SEPARATOR . "applpass_cpa_queue.json";
$cpcLog = __DIR__ . DIRECTORY_SEPARATOR . "applpass7.log";
$cpaLog = __DIR__ . DIRECTORY_SEPARATOR . "applpass_cpa.log";

// Check CPC Queue
echo "📄 CPC Queue (applpass_queue.json)\n";
echo str_repeat("-", 60) . "\n";
if (file_exists($cpcQueue)) {
    $size = filesize($cpcQueue);
    if ($size > 0) {
        $contents = file_get_contents($cpcQueue);
        $events = array_filter(explode("\n", $contents));
        echo "✅ Status: HAS EVENTS\n";
        echo "   Events count: " . count($events) . "\n";
        echo "   File size: " . $size . " bytes\n";
        echo "   Latest event: " . substr(end($events), 0, 100) . "...\n";
    } else {
        echo "⚠️  Status: EMPTY\n";
        echo "   No events have been written yet\n";
    }
} else {
    echo "❌ Status: DOES NOT EXIST\n";
}
echo "\n";

// Check CPA Queue
echo "📄 CPA Queue (applpass_cpa_queue.json)\n";
echo str_repeat("-", 60) . "\n";
if (file_exists($cpaQueue)) {
    $size = filesize($cpaQueue);
    if ($size > 0) {
        $contents = file_get_contents($cpaQueue);
        $events = array_filter(explode("\n", $contents));
        echo "✅ Status: HAS EVENTS\n";
        echo "   Events count: " . count($events) . "\n";
        echo "   File size: " . $size . " bytes\n";
        echo "   Latest event: " . substr(end($events), 0, 100) . "...\n";
    } else {
        echo "⚠️  Status: EMPTY\n";
        echo "   No events have been written yet\n";
    }
} else {
    echo "❌ Status: DOES NOT EXIST\n";
}
echo "\n";

// Check logs
echo "📝 Error Logs\n";
echo str_repeat("-", 60) . "\n";
echo "CPC Log: ";
if (file_exists($cpcLog) && filesize($cpcLog) > 0) {
    $lines = file($cpcLog);
    echo count($lines) . " entries (last 3 below)\n";
    $recent = array_slice($lines, -3);
    foreach ($recent as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "No entries\n";
}

echo "\nCPA Log: ";
if (file_exists($cpaLog) && filesize($cpaLog) > 0) {
    $lines = file($cpaLog);
    echo count($lines) . " entries (last 3 below)\n";
    $recent = array_slice($lines, -3);
    foreach ($recent as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "No entries\n";
}
echo "\n";

// Database check
echo "🗄️  Database\n";
echo str_repeat("-", 60) . "\n";
try {
    include 'database/db.php';
    $result = $db->query("SELECT COUNT(*) as count FROM appljobs WHERE job_reference IS NOT NULL AND job_reference != ''");
    $row = $result->fetch_assoc();
    echo "✅ Connected\n";
    echo "   Valid jobs available: " . $row['count'] . "\n";
    $db->close();
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Recommendations
echo "💡 NEXT STEPS\n";
echo str_repeat("=", 60) . "\n";

if (!file_exists($cpcQueue) || filesize($cpcQueue) == 0) {
    echo "⚠️  CPC Queue is empty:\n";
    echo "   1. Open: http://localhost/appljack/test_page.html\n";
    echo "   2. Click 'Fire CPC Event' button\n";
    echo "   3. Run this script again\n";
    echo "\n";
}

if (!file_exists($cpaQueue) || filesize($cpaQueue) == 0) {
    echo "⚠️  CPA Queue is empty:\n";
    echo "   1. Open: http://localhost/appljack/test_page.html\n";
    echo "   2. Click 'Fire CPA Event' button\n";
    echo "   3. Run this script again\n";
    echo "\n";
}

if ((file_exists($cpcQueue) && filesize($cpcQueue) > 0) &&
    (file_exists($cpaQueue) && filesize($cpaQueue) > 0)) {
    echo "✅ Both queues have events! Ready to process:\n";
    echo "   node applpass_putevents2.js\n";
    echo "   node applpass_cpa_putevent.js\n";
    echo "\n";
}

echo "\n";
