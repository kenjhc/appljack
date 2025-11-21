<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

$feedId = $_GET['feedId'] ?? '';

if (empty($feedId)) {
    echo json_encode(['error' => 'Feed ID required']);
    exit;
}

// Sanitize input
$feedId = $db->real_escape_string($feedId);

// Get feed details
$query = "SELECT * FROM applcustfeeds WHERE feedid = '$feedId'";
$result = $db->query($query);

if ($result && $row = $result->fetch_assoc()) {
    $response = [
        'found' => true,
        'feedid' => $row['feedid'],
        'feedname' => $row['feedname'],
        'budget_type' => $row['budget_type'] ?? 'CPC',
        'cpc' => $row['cpc'],
        'cpa' => $row['cpa'],
        'status' => $row['status'],
        'expected_behavior' => []
    ];

    // Explain expected behavior
    if ($response['budget_type'] === 'CPC') {
        $response['expected_behavior'] = [
            'mode' => 'CPC Campaign',
            'cpc_events' => "Will charge $" . $row['cpc'] . " per click",
            'cpa_events' => "Will charge $0.00 (no conversion charges)"
        ];
    } else if ($response['budget_type'] === 'CPA') {
        $response['expected_behavior'] = [
            'mode' => 'CPA Campaign',
            'cpc_events' => "Will charge $0.00 (no click charges)",
            'cpa_events' => "Will charge $" . $row['cpa'] . " per conversion"
        ];
    } else {
        $response['expected_behavior'] = [
            'mode' => 'Unknown/NULL - Defaults to CPC',
            'cpc_events' => "Will charge $" . $row['cpc'] . " per click",
            'cpa_events' => "Will charge $0.00"
        ];
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'found' => false,
        'error' => 'Feed not found',
        'query' => $query
    ]);
}