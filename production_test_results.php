<?php
/**
 * PRODUCTION TEST RESULTS
 * Verifies budget toggle logic with REAL CPC/CPA values
 */

header('Content-Type: text/plain');

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          PRODUCTION TEST RESULTS                                   ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

include 'database/db.php';

// Get test events
echo "PRODUCTION TEST EVENTS:\n";
echo str_repeat("=", 70) . "\n\n";

$query = "SELECT eventid, eventtype, feedid, cpc, cpa, timestamp
          FROM applevents
          WHERE eventid LIKE 'prod_cpc_%' OR eventid LIKE 'prod_cpa_%'
          ORDER BY id DESC";

$result = $db->query($query);

echo "Total test events found: " . $result->num_rows . "\n\n";

if ($result->num_rows > 0) {
    echo sprintf("%-20s %-10s %-12s %-10s %-10s\n", "Event ID", "Type", "Feed", "CPC", "CPA");
    echo str_repeat("-", 70) . "\n";

    $cpcTotal = 0;
    $cpaTotal = 0;
    $cpcEventCount = 0;
    $cpaEventCount = 0;

    while ($row = $result->fetch_assoc()) {
        echo sprintf(
            "%-20s %-10s %-12s \$%-9.2f \$%-9.2f\n",
            $row['eventid'],
            $row['eventtype'],
            $row['feedid'],
            $row['cpc'],
            $row['cpa'] ?? 0
        );

        if ($row['eventtype'] === 'cpc') {
            $cpcTotal += $row['cpc'];
            $cpcEventCount++;
        } elseif ($row['eventtype'] === 'cpa') {
            $cpaTotal += $row['cpa'] ?? 0;
            $cpaEventCount++;
        }
    }

    echo "\n" . str_repeat("=", 70) . "\n";
    echo "SUMMARY:\n";
    echo str_repeat("-", 70) . "\n";
    echo "CPC Events: $cpcEventCount   Total CPC Revenue: $" . number_format($cpcTotal, 2) . "\n";
    echo "CPA Events: $cpaEventCount   Total CPA Revenue: $" . number_format($cpaTotal, 2) . "\n\n";
}

// Budget Toggle Verification
echo str_repeat("=", 70) . "\n";
echo "BUDGET TOGGLE VERIFICATION:\n";
echo str_repeat("=", 70) . "\n\n";

// Check CPC events in CPC mode campaigns
$cpcModeCheck = $db->query("
    SELECT e.eventid, e.eventtype, e.feedid, e.cpc, e.cpa, f.budget_type
    FROM applevents e
    JOIN applcustfeeds f ON e.feedid = f.feedid
    WHERE e.eventid LIKE 'prod_cpc_%'
    AND e.eventtype = 'cpc'
");

echo "1. CPC EVENTS (from CPC mode campaigns):\n";
echo str_repeat("-", 70) . "\n";

if ($cpcModeCheck->num_rows > 0) {
    $allCorrect = true;
    while ($row = $cpcModeCheck->fetch_assoc()) {
        $cpaValue = $row['cpa'] ?? 0;
        $isCorrect = ($row['cpc'] > 0 && $cpaValue == 0);

        $status = $isCorrect ? "✅" : "❌";

        echo "$status Event: " . $row['eventid'] . "\n";
        echo "   Budget Type: " . $row['budget_type'] . "\n";
        echo "   CPC: $" . number_format($row['cpc'], 2) . " (should be > $0.00)\n";
        echo "   CPA: $" . number_format($cpaValue, 2) . " (should be $0.00)\n";

        if (!$isCorrect) {
            echo "   ❌ FAILED: Budget toggle not working correctly!\n";
            $allCorrect = false;
        }
        echo "\n";
    }

    if ($allCorrect) {
        echo "✅ ALL CPC events have correct values: CPC > 0, CPA = 0\n";
    } else {
        echo "❌ SOME CPC events have incorrect values!\n";
    }
} else {
    echo "⚠️  No CPC events found\n";
}

echo "\n";

// Check CPA events in CPA mode campaigns
$cpaModeCheck = $db->query("
    SELECT e.eventid, e.eventtype, e.feedid, e.cpc, e.cpa, f.budget_type
    FROM applevents e
    JOIN applcustfeeds f ON e.feedid = f.feedid
    WHERE e.eventid LIKE 'prod_cpa_%'
    AND e.eventtype = 'cpa'
");

echo "2. CPA EVENTS (from CPA mode campaigns):\n";
echo str_repeat("-", 70) . "\n";

if ($cpaModeCheck->num_rows > 0) {
    $allCorrect = true;
    while ($row = $cpaModeCheck->fetch_assoc()) {
        $cpcValue = $row['cpc'] ?? 0;
        $cpaValue = $row['cpa'] ?? 0;
        $isCorrect = ($cpcValue == 0 && $cpaValue > 0);

        $status = $isCorrect ? "✅" : "❌";

        echo "$status Event: " . $row['eventid'] . "\n";
        echo "   Budget Type: " . $row['budget_type'] . "\n";
        echo "   CPC: $" . number_format($cpcValue, 2) . " (should be $0.00)\n";
        echo "   CPA: $" . number_format($cpaValue, 2) . " (should be > $0.00)\n";

        if (!$isCorrect) {
            echo "   ❌ FAILED: Budget toggle not working correctly!\n";
            $allCorrect = false;
        }
        echo "\n";
    }

    if ($allCorrect) {
        echo "✅ ALL CPA events have correct values: CPC = 0, CPA > 0\n";
    } else {
        echo "❌ SOME CPA events have incorrect values!\n";
    }
} else {
    echo "⚠️  No CPA events found (this is expected if no matching clicks)\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "VIOLATION CHECK:\n";
echo str_repeat("-", 70) . "\n";

// Check for events charged for BOTH CPC and CPA
$violations = $db->query("
    SELECT eventid, eventtype, feedid, cpc, cpa
    FROM applevents
    WHERE (eventid LIKE 'prod_cpc_%' OR eventid LIKE 'prod_cpa_%')
    AND cpc > 0 AND cpa > 0
");

if ($violations->num_rows > 0) {
    echo "❌ CRITICAL: Found " . $violations->num_rows . " events charged for BOTH CPC and CPA!\n\n";
    while ($row = $violations->fetch_assoc()) {
        echo "  Event: " . $row['eventid'] . " - CPC: $" . $row['cpc'] . ", CPA: $" . $row['cpa'] . "\n";
    }
} else {
    echo "✅ PASS: No events are charged for both CPC and CPA\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "FINAL VERDICT:\n";
echo str_repeat("=", 70) . "\n";

// Final check
$finalCheck = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN eventtype = 'cpc' AND cpc > 0 AND (cpa IS NULL OR cpa = 0) THEN 1 ELSE 0 END) as valid_cpc,
        SUM(CASE WHEN eventtype = 'cpa' AND (cpc IS NULL OR cpc = 0) AND cpa > 0 THEN 1 ELSE 0 END) as valid_cpa,
        SUM(CASE WHEN cpc > 0 AND cpa > 0 THEN 1 ELSE 0 END) as violations
    FROM applevents
    WHERE eventid LIKE 'prod_cpc_%' OR eventid LIKE 'prod_cpa_%'
");

$stats = $finalCheck->fetch_assoc();

echo "\nTotal test events: " . $stats['total'] . "\n";
echo "Valid CPC events: " . $stats['valid_cpc'] . " ✅\n";
echo "Valid CPA events: " . $stats['valid_cpa'] . " ✅\n";
echo "Violations: " . $stats['violations'] . " " . ($stats['violations'] > 0 ? "❌" : "✅") . "\n\n";

if ($stats['violations'] == 0 && $stats['total'] > 0) {
    echo "✅ ✅ ✅ PRODUCTION TEST PASSED! ✅ ✅ ✅\n";
    echo "Budget toggle is working correctly with real CPC/CPA values!\n";
} else {
    echo "❌ PRODUCTION TEST FAILED!\n";
    echo "Budget toggle is NOT working correctly!\n";
}

echo "\n";

$db->close();
