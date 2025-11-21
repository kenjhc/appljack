<?php
/**
 * PRODUCTION CPA TEST RESULTS
 * Verifies CPA conversions with proper charges
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PRODUCTION CPA TEST RESULTS                               ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

include 'database/db.php';

// Check CPC clicks for CPA campaign
echo "CPC CLICKS (from CPA campaign):\n";
echo str_repeat("=", 70) . "\n";

$cpcClicks = $db->query("
    SELECT e.eventid, e.eventtype, e.feedid, e.cpc, e.cpa, e.ipaddress, e.useragent, f.budget_type
    FROM applevents e
    JOIN applcustfeeds f ON e.feedid = f.feedid
    WHERE e.eventid LIKE 'cpa_click_%'
    ORDER BY e.id DESC
");

if ($cpcClicks->num_rows > 0) {
    echo "Found " . $cpcClicks->num_rows . " CPC click events:\n\n";

    while ($row = $cpcClicks->fetch_assoc()) {
        $cpcValue = $row['cpc'] ?? 0;
        $cpaValue = $row['cpa'] ?? 0;

        // For CPA campaigns, CPC clicks should have CPC = $0.00
        $isCorrect = ($cpcValue == 0);
        $status = $isCorrect ? "✅" : "❌";

        echo "$status Event: " . $row['eventid'] . "\n";
        echo "   Feed: " . $row['feedid'] . " (Budget Type: " . $row['budget_type'] . ")\n";
        echo "   CPC: $" . number_format($cpcValue, 2) . " (should be $0.00 for CPA campaign)\n";
        echo "   CPA: $" . number_format($cpaValue, 2) . "\n";
        echo "   IP: " . $row['ipaddress'] . " | UA: " . substr($row['useragent'], 0, 30) . "...\n\n";
    }
} else {
    echo "⚠️  No CPC clicks found\n\n";
}

// Check CPA conversions
echo str_repeat("=", 70) . "\n";
echo "CPA CONVERSIONS (from CPA campaign):\n";
echo str_repeat("=", 70) . "\n";

$cpaConversions = $db->query("
    SELECT e.eventid, e.eventtype, e.feedid, e.cpc, e.cpa, e.ipaddress, e.useragent, f.budget_type
    FROM applevents e
    JOIN applcustfeeds f ON e.feedid = f.feedid
    WHERE e.eventid LIKE 'cpa_conv_%'
    AND e.eventtype = 'cpa'
    ORDER BY e.id DESC
");

if ($cpaConversions->num_rows > 0) {
    echo "Found " . $cpaConversions->num_rows . " CPA conversion events:\n\n";

    $totalCPA = 0;

    while ($row = $cpaConversions->fetch_assoc()) {
        $cpcValue = $row['cpc'] ?? 0;
        $cpaValue = $row['cpa'] ?? 0;

        // For CPA campaigns, conversions should have CPC = $0.00 and CPA > 0
        $isCorrect = ($cpcValue == 0 && $cpaValue > 0);
        $status = $isCorrect ? "✅" : "❌";

        echo "$status Event: " . $row['eventid'] . "\n";
        echo "   Feed: " . $row['feedid'] . " (Budget Type: " . $row['budget_type'] . ")\n";
        echo "   CPC: $" . number_format($cpcValue, 2) . " (should be $0.00)\n";
        echo "   CPA: $" . number_format($cpaValue, 2) . " (should be > $0.00)\n";
        echo "   IP: " . $row['ipaddress'] . " | UA: " . substr($row['useragent'], 0, 30) . "...\n\n";

        $totalCPA += $cpaValue;
    }

    echo "Total CPA Revenue: $" . number_format($totalCPA, 2) . "\n";
} else {
    echo "⚠️  No CPA conversions found in applevents\n";
    echo "Check appleventsdel table for unmatched conversions...\n\n";

    // Check deleted events
    $deletedCPA = $db->query("
        SELECT eventid, deletecode, ipaddress
        FROM appleventsdel
        WHERE eventid LIKE 'cpa_conv_%'
    ");

    if ($deletedCPA->num_rows > 0) {
        echo "Found " . $deletedCPA->num_rows . " unmatched CPA events in appleventsdel:\n\n";
        while ($row = $deletedCPA->fetch_assoc()) {
            echo "  Event: " . $row['eventid'] . " - Code: " . $row['deletecode'] . " - IP: " . $row['ipaddress'] . "\n";
        }
        echo "\nThis means no matching CPC clicks were found with same IP/UserAgent\n";
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "BUDGET TOGGLE VERIFICATION:\n";
echo str_repeat("=", 70) . "\n";

// Check all CPA campaign events
$allCPAEvents = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN e.cpc = 0 OR e.cpc IS NULL THEN 1 ELSE 0 END) as correct_cpc,
        SUM(CASE WHEN e.cpc > 0 THEN 1 ELSE 0 END) as wrong_cpc,
        SUM(e.cpc) as total_cpc_charged,
        SUM(e.cpa) as total_cpa_charged
    FROM applevents e
    JOIN applcustfeeds f ON e.feedid = f.feedid
    WHERE (e.eventid LIKE 'cpa_click_%' OR e.eventid LIKE 'cpa_conv_%')
    AND f.budget_type = 'CPA'
");

$stats = $allCPAEvents->fetch_assoc();

echo "\nCPA Campaign Statistics:\n";
echo "  Total events: " . $stats['total'] . "\n";
echo "  Events with CPC = $0.00: " . $stats['correct_cpc'] . " ✅\n";
echo "  Events with CPC > $0.00: " . $stats['wrong_cpc'] . " " . ($stats['wrong_cpc'] > 0 ? "❌" : "✅") . "\n";
echo "  Total CPC charged: $" . number_format($stats['total_cpc_charged'], 2) . " (should be $0.00)\n";
echo "  Total CPA charged: $" . number_format($stats['total_cpa_charged'], 2) . "\n\n";

if ($stats['wrong_cpc'] == 0 && $stats['total'] > 0) {
    echo "✅ ✅ ✅ CPA CAMPAIGN TEST PASSED! ✅ ✅ ✅\n";
    echo "CPA campaigns are NOT charging for clicks (CPC = $0.00)\n";
    echo "Only charging for conversions (CPA > $0.00)\n";
} else if ($stats['total'] == 0) {
    echo "⚠️  No events found for CPA campaign test\n";
} else {
    echo "❌ CPA CAMPAIGN TEST FAILED!\n";
    echo "Some clicks are being charged CPC when they shouldn't be!\n";
}

echo "\n";

$db->close();
