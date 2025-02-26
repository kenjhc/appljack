<?php
/**
 * Example: appldownloadcsvpublisher.php
 * Description: Generate a CSV of feed/campaign data for a specific publisher.
 */

include "database/db.php";

// Make sure the user is logged in
if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Get publisherid, startdate, and enddate from GET (or set defaults)
$publisherid = $_GET['publisherid'] ?? null;
$startdate   = $_GET['startdate']   ?? date('Y-m-01');
$enddate     = $_GET['enddate']     ?? date('Y-m-d');

// Format the dates to include time components
$startdate = date('Y-m-d', strtotime($startdate)) . " 00:00:00";
$enddate   = date('Y-m-d', strtotime($enddate))   . " 23:59:59";

// If publisherid is missing, show an error or redirect
if (!$publisherid) {
    die("No publisher ID specified.");
}

try {
    // OPTIONAL: Disable ONLY_FULL_GROUP_BY if you prefer
    // $pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

    // 1. Fetch feeds associated with this publisher using a JOIN on acctnum
    $stmt = $pdo->prepare("
        SELECT f.feedid,
               f.feedname,
               f.budget,
               f.status,
               f.numjobs,
               f.custid  -- If you need the custid for reference
        FROM applcustfeeds f
        JOIN applpubs p ON p.publisherid = f.activepubs
        WHERE f.activepubs = :publisherid
        ORDER BY f.feedname ASC
    ");



    $stmt->execute(['publisherid' => $publisherid]);
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare an array to hold CSV rows
    $data = [];

    // 2. For each feed, gather CPC/CPA event stats
    foreach ($feeds as &$feed) {
        // (A) Get clicks (CPC)
        $clickStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT eventid) AS clicks, 
                   SUM(cpc)                AS total_cpc
            FROM applevents
            WHERE feedid    = :feedid
              AND eventtype = 'cpc'
              AND timestamp BETWEEN :startdate AND :enddate
        ");
        $clickStmt->execute([
            'feedid'    => $feed['feedid'],
            'startdate' => $startdate,
            'enddate'   => $enddate
        ]);
        $clickData = $clickStmt->fetch(PDO::FETCH_ASSOC);

        // (B) Get applies (CPA)
        $appliesStmt = $pdo->prepare("
            SELECT COUNT(*) AS applies,
                   SUM(cpa) AS total_cpa
            FROM applevents
            WHERE feedid    = :feedid
              AND eventtype = 'cpa'
              AND timestamp BETWEEN :startdate AND :enddate
        ");
        $appliesStmt->execute([
            'feedid'    => $feed['feedid'],
            'startdate' => $startdate,
            'enddate'   => $enddate
        ]);
        $appliesData = $appliesStmt->fetch(PDO::FETCH_ASSOC);

        // (C) Calculate metrics
        $feed['clicks']       = $clickData['clicks']       ?? 0;
        $feed['total_cpc']    = $clickData['total_cpc']    ?? 0;
        $feed['applies']      = $appliesData['applies']    ?? 0;
        $feed['total_cpa']    = $appliesData['total_cpa']  ?? 0;

        $total_spend = (float) $feed['total_cpc'] + (float) $feed['total_cpa'];
        $feed['formatted_spend']   = '$' . number_format($total_spend, 2, '.', '');
        $feed['spend_per_click']   = $feed['clicks'] > 0
            ? '$' . number_format($feed['total_cpc'] / $feed['clicks'], 2, '.', '')
            : '$0.00';
        $feed['spend_per_apply']   = $feed['applies'] > 0
            ? '$' . number_format($feed['total_cpa'] / $feed['applies'], 2, '.', '')
            : '$0.00';
        $feed['conversion_rate']   = $feed['clicks'] > 0
            ? number_format(($feed['applies'] / $feed['clicks']) * 100, 2) . '%'
            : '0.00%';

        // (D) Push into $data for CSV
        $data[] = [
            'Campaign Name'  => $feed['feedname'],
            'Campaign ID'    => $feed['feedid'],
            'Status'         => $feed['status'],
            'Mo. Budget'     => $feed['budget'],
            'Spend'          => $feed['formatted_spend'],
            'Clicks'         => $feed['clicks'],
            'Applies'        => $feed['applies'],
            'Spend/Apply'    => $feed['spend_per_apply'],
            'Spend/Click'    => $feed['spend_per_click'],
            'Conv. Rate'     => $feed['conversion_rate'],
            'Job Exported'   => $feed['numjobs'],
            // 'Customer ID'  => $feed['custid'], // In case you want it in CSV
        ];
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// 3. Output the data as a CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="publisher_feeds_data.csv"');

if (!empty($data)) {
    $fp = fopen('php://output', 'w');

    // Write column headers
    fputcsv($fp, array_keys($data[0]));

    // Write each row
    foreach ($data as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
} else {
    // If no data, just output a message
    echo "No data available for export.";
}

exit;
