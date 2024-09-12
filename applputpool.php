<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $jobpoolid = $_POST['jobpoolid'] ?? '';
        $acctnum = $_POST['acctnum'] ?? '';
        $jobpoolname = $_POST['jobpoolname'] ?? '';
        $jobpoolurl = $_POST['jobpoolurl'] ?? '';
        $jobpoolfiletype = $_POST['jobpoolfiletype'] ?? ''; // Retrieve file type from the form
        $arbitrage = floatval($_POST['arbitrage']);

        if ($arbitrage > 100) {
            $_SESSION['error'] = "Arbitrage percentage cannot be more than 100.";
            header("Location: applcreatepool.php");
            exit();
        }

        // Assume all fields are properly validated before insertion
        $stmt = $conn->prepare("INSERT INTO appljobseed (jobpoolid, acctnum, jobpoolname, jobpoolurl, jobpoolfiletype, arbitrage) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$jobpoolid, $acctnum, $jobpoolname, $jobpoolurl, $jobpoolfiletype, $arbitrage]);

        setToastMessage('success', 'Job Pool added successfully.');
        // Redirect to the master view page after successful insertion
        header("Location: applmasterview.php");
        exit();
    }
} catch (PDOException $e) {
    // Log error or handle it as required
    $error = "Database error: " . $e->getMessage();
    $_SESSION['error'] = $error; // Optionally store error in session to display in the form
    header("Location: applcreatepool.php"); // Redirect back to the form if there is an error
    exit();
}
