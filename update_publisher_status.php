<?php
include 'database/db.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['publisherid'], $data['status'])) {
    $publisherid = $data['publisherid'];
    $status = $data['status'];

    try {
        $stmt = $conn->prepare("UPDATE applpubs SET pubstatus = :status WHERE publisherid = :publisherid");
        $stmt->execute(['status' => $status, 'publisherid' => $publisherid]);

        echo json_encode(['success' => true]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit();
}
