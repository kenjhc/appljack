<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum']) || !isset($_GET['feedid'])) {
    header("Location: appllogin.php");
    exit();
}

$feedid = $_GET['feedid'];

try {
    // Database operation to delete feed... 
    $stmt = $pdo->prepare("DELETE FROM applcustfeeds WHERE feedid = ? AND custid = ?");
    $stmt->execute([$feedid, $_SESSION['custid']]);
    // Redirect back to the portal with a success message...

    header("Location: applportal.php");
} catch (PDOException $e) {
    // Handle error...
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}
