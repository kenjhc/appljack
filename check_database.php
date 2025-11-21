<?php
// Check database feed configuration - returns JSON for the test page
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

$feedId = $_GET['feedid'] ?? 'aaa7c0e9ef';

$response = [];

try {
    // Get feed configuration
    $query = $db->prepare("SELECT feedid, feedname, budget_type, cpc, cpa, status FROM applcustfeeds WHERE feedid = ?");
    $query->bind_param("s", $feedId);
    $query->execute();
    $result = $query->get_result();

    if ($row = $result->fetch_assoc()) {
        $response = $row;
        $response['found'] = true;
    } else {
        $response = [
            'found' => false,
            'error' => 'Feed not found'
        ];
    }
    $query->close();

    // Check if budget_type column exists
    $columnCheck = $db->query("SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $column = $columnCheck->fetch_assoc();
        $response['budget_type_column'] = [
            'exists' => true,
            'type' => $column['Type'],
            'default' => $column['Default']
        ];
    } else {
        $response['budget_type_column'] = [
            'exists' => false,
            'error' => 'budget_type column does not exist!'
        ];
    }

    // Get some valid jobs for testing
    $jobQuery = $db->query("SELECT job_reference, jobpoolid FROM appljobs WHERE job_reference IS NOT NULL AND job_reference != '' LIMIT 3");
    $response['valid_jobs'] = [];
    while ($job = $jobQuery->fetch_assoc()) {
        $response['valid_jobs'][] = $job;
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

$db->close();

echo json_encode($response, JSON_PRETTY_PRINT);