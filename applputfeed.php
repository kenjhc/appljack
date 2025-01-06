<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $feedname = filter_input(INPUT_POST, 'feedname', FILTER_SANITIZE_STRING);

    // Check if the feed name already exists for the current user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applcustfeeds WHERE custid = ? AND feedname = ?");
    $stmt->execute([$_SESSION['custid'], $feedname]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "That Feed Name already exists. Please enter another.";
        header("Location: applcreatefeeds.php");
        exit();
    }

    $feedbudget = filter_input(INPUT_POST, 'feedbudget', FILTER_SANITIZE_STRING);
    $feedcpc = filter_input(INPUT_POST, 'feedcpc', FILTER_SANITIZE_STRING);
    $arbcampcpc = filter_input(INPUT_POST, 'arbcampcpc', FILTER_SANITIZE_STRING);
    $arbcampcpa = filter_input(INPUT_POST, 'arbcampcpa', FILTER_SANITIZE_STRING);

    // Handle the optional feedcpc field
    if ($feedcpc === '' || $feedcpc === null) {
        $feedcpc = null;
    }

    // Generate a random 10-character alphanumeric string for feedid
    $feedid = bin2hex(random_bytes(5));

    
    // Insert into database
    try {
        // Updated the INSERT statement to include the acctnum column after custid
        $stmt = $pdo->prepare("INSERT INTO applcustfeeds (custid, acctnum, feedid, feedname, budget, cpc, status, arbcampcpc, arbcampcpa) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)");
        $stmt->execute([
            $_SESSION['custid'], // Assuming custid is also part of the session and needs to be included
            $_SESSION['acctnum'], // Include the acctnum from the session
            $feedid,
            $feedname,
            $feedbudget,
            $feedcpc,
            !empty($arbcampcpc) ? $arbcampcpc : null,
            !empty($arbcampcpa) ? $arbcampcpa : null,
        ]);

        header("Location: applportal.php?custid=" . urlencode($_SESSION['custid']));
        exit();
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }
} else {
    header("Location: applcreatefeeds.php");
    exit();
}
