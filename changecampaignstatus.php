<?php
include 'database/db.php';

if (!isset($_SESSION['custid'])) {
    header("Location: appllogin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedid = $_POST['feedid'] ?? '';
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'stop') {
            $status = 'stopped';
        } else if ($action === 'start') {
            $status = 'active';
        } else {
            // Invalid action
            header("Location: editfeed.php?feedid=" . urlencode($feedid));
            exit();
        }

        $stmt = $pdo->prepare("UPDATE applcustfeeds SET status = ? WHERE feedid = ? AND custid = ?");
        $stmt->execute([$status, $feedid, $_SESSION['custid']]);
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }

    // Redirect back to the edit campaign page
    header("Location: editfeed.php?feedid=" . urlencode($feedid));
    exit();
}
