<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

// Get recent events from database
$query = "SELECT eventid, eventtype, cpc, cpa, feedid, timestamp
          FROM applevents
          ORDER BY id DESC
          LIMIT 10";

$result = $db->query($query);

$events = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'eventid' => $row['eventid'],
            'eventtype' => $row['eventtype'],
            'cpc' => $row['cpc'],
            'cpa' => $row['cpa'],
            'feedid' => $row['feedid'],
            'timestamp' => $row['timestamp']
        ];
    }
}

// Get statistics
$statsQuery = "SELECT
    COUNT(*) as total_events,
    SUM(CASE WHEN cpc > 0 THEN 1 ELSE 0 END) as cpc_events,
    SUM(CASE WHEN cpa > 0 THEN 1 ELSE 0 END) as cpa_events,
    SUM(cpc) as total_cpc_value,
    SUM(cpa) as total_cpa_value
    FROM applevents
    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";

$stats = [];
$statsResult = $db->query($statsQuery);
if ($statsResult) {
    $stats = $statsResult->fetch_assoc();
}

$response = [
    'events' => $events,
    'stats' => $stats,
    'query' => $query,
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);