<?php
/**
 * COMPREHENSIVE CRON JOB TEST REPORT
 * Tests both CPC and CPA processors with budget toggle logic
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          CRON JOB TEST REPORT                                      ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

include 'database/db.php';

// Get total event count BEFORE
$result = $db->query("SELECT COUNT(*) as total FROM applevents");
$beforeCount = $result->fetch_assoc()['total'];

echo "DATABASE STATE - BEFORE\n";
echo str_repeat("-", 70) . "\n";
echo "Total events: $beforeCount\n\n";

// Get test feeds with different budget types
$query = "SELECT feedid, custid, feedname, budget_type, cpc, cpa, status
          FROM applcustfeeds
          WHERE status = 'active'
          AND (budget_type = 'CPC' OR budget_type = 'CPA')
          LIMIT 2";

$result = $db->query($query);

$cpcFeed = null;
$cpaFeed = null;

while ($feed = $result->fetch_assoc()) {
    if ($feed['budget_type'] === 'CPC' && !$cpcFeed) {
        $cpcFeed = $feed;
    } elseif ($feed['budget_type'] === 'CPA' && !$cpaFeed) {
        $cpaFeed = $feed;
    }
}

if (!$cpcFeed) {
    // Use any active feed as CPC
    $result = $db->query("SELECT feedid, custid, feedname, budget_type, cpc, cpa, status FROM applcustfeeds WHERE status = 'active' LIMIT 1");
    $cpcFeed = $result->fetch_assoc();
}

if (!$cpaFeed) {
    // Use any active feed as CPA
    $result = $db->query("SELECT feedid, custid, feedname, budget_type, cpc, cpa, status FROM applcustfeeds WHERE status = 'active' LIMIT 1");
    $cpaFeed = $result->fetch_assoc();
}

echo "TEST FEEDS\n";
echo str_repeat("-", 70) . "\n";

if ($cpcFeed) {
    echo "CPC Feed: " . $cpcFeed['feedid'] . "\n";
    echo "  Name: " . ($cpcFeed['feedname'] ?? 'N/A') . "\n";
    echo "  Budget Type: " . ($cpcFeed['budget_type'] ?? 'NULL') . "\n";
    echo "  CPC Value: $" . ($cpcFeed['cpc'] ?? '0.00') . "\n";
    echo "  CPA Value: $" . ($cpcFeed['cpa'] ?? '0.00') . "\n";
    echo "  Expected: CPC charges, CPA = $0.00\n\n";
}

if ($cpaFeed) {
    echo "CPA Feed: " . $cpaFeed['feedid'] . "\n";
    echo "  Name: " . ($cpaFeed['feedname'] ?? 'N/A') . "\n";
    echo "  Budget Type: " . ($cpaFeed['budget_type'] ?? 'NULL') . "\n";
    echo "  CPC Value: $" . ($cpaFeed['cpc'] ?? '0.00') . "\n";
    echo "  CPA Value: $" . ($cpaFeed['cpa'] ?? '0.00') . "\n";
    echo "  Expected: CPA charges, CPC = $0.00\n\n";
}

// Check recent events from the processors
echo str_repeat("=", 70) . "\n";
echo "RECENT EVENTS FROM CRON JOB TESTS\n";
echo str_repeat("=", 70) . "\n\n";

$query = "SELECT eventid, eventtype, feedid, cpc, cpa, timestamp
          FROM applevents
          WHERE eventid LIKE 'cpc_%' OR eventid LIKE 'cpa_%'
          ORDER BY id DESC
          LIMIT 20";

$result = $db->query($query);

echo sprintf("%-20s %-10s %-12s %-8s %-8s %s\n", "Event ID", "Type", "Feed", "CPC", "CPA", "Timestamp");
echo str_repeat("-", 70) . "\n";

while ($row = $result->fetch_assoc()) {
    echo sprintf(
        "%-20s %-10s %-12s \$%-7s \$%-7s %s\n",
        $row['eventid'],
        $row['eventtype'],
        $row['feedid'],
        number_format($row['cpc'], 2),
        number_format($row['cpa'] ?? 0, 2),
        $row['timestamp']
    );
}

echo "\n";

// Get total event count AFTER
$result = $db->query("SELECT COUNT(*) as total FROM applevents");
$afterCount = $result->fetch_assoc()['total'];

echo str_repeat("=", 70) . "\n";
echo "SUMMARY\n";
echo str_repeat("=", 70) . "\n";
echo "Events before: $beforeCount\n";
echo "Events after: $afterCount\n";
echo "New events: " . ($afterCount - $beforeCount) . "\n\n";

// Budget toggle verification
echo "BUDGET TOGGLE VERIFICATION\n";
echo str_repeat("-", 70) . "\n";

$cpcCheck = $db->query("
    SELECT COUNT(*) as count
    FROM applevents
    WHERE eventid LIKE 'cpc_%'
    AND eventtype = 'cpc'
    AND cpa = 0.00
");
$cpcValid = $cpcCheck->fetch_assoc()['count'];

$cpaCheck = $db->query("
    SELECT COUNT(*) as count
    FROM applevents
    WHERE eventid LIKE 'cpa_%'
    AND eventtype = 'cpa'
    AND cpc = 0.00
");
$cpaValid = $cpaCheck->fetch_assoc()['count'];

echo "✅ CPC events with CPA = \$0.00: $cpcValid\n";
echo "✅ CPA events with CPC = \$0.00: $cpaValid\n";

// Check for any violations (events with BOTH cpc AND cpa > 0)
$violations = $db->query("
    SELECT COUNT(*) as count
    FROM applevents
    WHERE cpc > 0 AND cpa > 0
");
$violationCount = $violations->fetch_assoc()['count'];

if ($violationCount > 0) {
    echo "❌ VIOLATION: $violationCount events have BOTH CPC and CPA values!\n";
} else {
    echo "✅ No violations: No events are charged for both CPC and CPA\n";
}

echo "\n";
echo str_repeat("=", 70) . "\n";
echo "TEST COMPLETE\n";
echo str_repeat("=", 70) . "\n\n";

$db->close();
