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
$error = null;

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
} else {
    $error = $db->error;
}

// Get total count
$countResult = $db->query("SELECT COUNT(*) as total FROM applevents");
$totalCount = 0;
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $totalCount = $countRow['total'];
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
    'success' => $error === null,
    'events' => $events,
    'total_count' => $totalCount,
    'stats' => $stats,
    'query' => $query,
    'error' => $error,
    'debug' => [
        'event_count' => count($events),
        'database_connected' => $db->ping(),
        'query_executed' => $result !== false
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);