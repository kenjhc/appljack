<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum']) || !isset($_GET['publisherid']) || !isset($_GET['feedid'])) {
    header("Location: appllogin.php");
    exit();
}

$publisherid = $_GET['publisherid'];
$feedid = $_GET['feedid'];

try {
    // Update operation to set activepubs to NULL where publisherid and feedid match
    $stmt = $pdo->prepare("UPDATE applcustfeeds SET activepubs = NULL WHERE activepubs = ? AND feedid = ?");
    $stmt->execute([$publisherid, $feedid]);
    // Redirect back to the portal with a success message...
    header("Location: view_publisher.php?publisherid=" . urlencode($publisherid));
} catch (PDOException $e) {
    // Handle error...
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}
