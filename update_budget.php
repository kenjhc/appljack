<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$feedId = $input['feedId'] ?? '';
$budgetType = $input['budgetType'] ?? '';

if (empty($feedId) || empty($budgetType)) {
    echo json_encode(['success' => false, 'error' => 'Feed ID and Budget Type required']);
    exit;
}

// Validate budget type
if (!in_array($budgetType, ['CPC', 'CPA'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid budget type. Must be CPC or CPA']);
    exit;
}

// Sanitize input
$feedId = $db->real_escape_string($feedId);
$budgetType = $db->real_escape_string($budgetType);

// Update the feed
$query = "UPDATE applcustfeeds SET budget_type = '$budgetType' WHERE feedid = '$feedId'";
$result = $db->query($query);

if ($result) {
    // Log the change
    error_log(date('Y-m-d H:i:s') . " - Updated feed $feedId to $budgetType mode\n", 3, __DIR__ . "/budget_changes.log");

    echo json_encode([
        'success' => true,
        'message' => "Feed updated to $budgetType mode",
        'feedId' => $feedId,
        'budgetType' => $budgetType
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Database update failed: ' . $db->error
    ]);
}