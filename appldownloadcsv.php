<?php
include "database/db.php";

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Get custid, startdate, and enddate
$custid = $_GET['custid'] ?? null;
$startdate = $_GET['startdate'] ?? date('Y-m-01');
$enddate = $_GET['enddate'] ?? date('Y-m-d');

$startdate = date('Y-m-d', strtotime($startdate)) . " 00:00:00";
$enddate = date('Y-m-d', strtotime($enddate)) . " 23:59:59";
  
try { 

    $stmt = $pdo->prepare("SELECT feedid, feedname, budget, status, numjobs
                           FROM applcustfeeds
                           WHERE custid = ? ORDER BY feedname ASC");
    $stmt->execute([$custid]);
    $feeds = $stmt->fetchAll();

    $data = []; // Prepare for CSV output

    foreach ($feeds as &$feed) {
        $clickStmt = $pdo->prepare("SELECT COUNT(DISTINCT eventid) AS clicks, SUM(cpc) AS total_cpc
                                    FROM applevents
                                    WHERE custid = ? AND feedid = ? AND eventtype = 'cpc'
                                    AND timestamp BETWEEN ? AND ?");
        $clickStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
        $clickData = $clickStmt->fetch();

        $appliesStmt = $pdo->prepare("SELECT COUNT(*) AS applies, SUM(cpa) AS total_cpa
                                      FROM applevents
                                      WHERE custid = ? AND feedid = ? AND eventtype = 'cpa'
                                      AND timestamp BETWEEN ? AND ?");
        $appliesStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
        $appliesData = $appliesStmt->fetch();

        $feed['clicks'] = $clickData['clicks'] ?? 0;
        $feed['total_cpc'] = $clickData['total_cpc'] ?? 0;
        $feed['total_cpa'] = $appliesData['total_cpa'] ?? 0;
        $feed['applies'] = $appliesData['applies'] ?? 0;

        $total_spend = $feed['total_cpc'] + $feed['total_cpa'];
        $feed['formatted_spend'] = '$' . number_format((float)$total_spend, 2, '.', '');

        $feed['spend_per_click'] = $feed['clicks'] > 0 ? '$' . number_format($feed['total_cpc'] / $feed['clicks'], 2, '.', '') : '$0.00';
        $feed['spend_per_apply'] = $feed['applies'] > 0 ? '$' . number_format($feed['total_cpa'] / $feed['applies'], 2, '.', '') : '$0.00';
        $feed['conversion_rate'] = $feed['clicks'] > 0 ? number_format(($feed['applies'] / $feed['clicks']) * 100, 2) . '%' : '0.00%';

        $data[] = [
            'Campaign Name' => $feed['feedname'],
            'Campaign ID' => $feed['feedid'],
            'Status' => $feed['status'],
            'Mo. Budget' => $feed['budget'],
            'Spend' => $feed['formatted_spend'],
            'Clicks' => $feed['clicks'],
            'Applies' => $feed['applies'],
            'Spend/Apply' => $feed['spend_per_apply'],
            'Spend/Click' => $feed['spend_per_click'],
            'Conv. Rate' => $feed['conversion_rate'],
            'Job Exported' => $feed['numjobs'],
        ];
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Output the data as a CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="feeds_data.csv"');

// Check if $data is defined and is an array
if (isset($data) && is_array($data) && count($data) > 0) {
    $fp = fopen('php://output', 'w');

    // Output the headers (column names) only if $data[0] is valid
    if (isset($data[0]) && is_array($data[0])) {
        fputcsv($fp, array_keys($data[0]));

        // Loop through and output each row of data
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
    } else {
        // Output a message or log an error if data structure is invalid
        echo "Invalid data structure or no data available.";
    }

    fclose($fp);
} else {
    // Output a message or log an error if no data is available
    echo "No data available for export.";
}

exit();
