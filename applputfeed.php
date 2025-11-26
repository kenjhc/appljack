<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $feedname = filter_input(INPUT_POST, 'feedname', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Check if the feed name already exists for the current user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applcustfeeds WHERE custid = ? AND feedname = ?");
    $stmt->execute([$_SESSION['custid'], $feedname]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "That Feed Name already exists. Please enter another.";
        header("Location: applcreatefeeds.php");
        exit();
    }

    $feedbudget = filter_input(INPUT_POST, 'feedbudget', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $feedcpc = filter_input(INPUT_POST, 'feedcpc', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $arbcampcpc = filter_input(INPUT_POST, 'arbcampcpc', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $arbcampcpa = filter_input(INPUT_POST, 'arbcampcpa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $startdate = filter_input(INPUT_POST, 'startdate', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $enddate = filter_input(INPUT_POST, 'enddate', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    // Handle the optional feedcpc field
    // Store the original dates
    $actualStartDate = !empty($startdate) ? (new DateTime($startdate))->format('Y-m-d H:i:s') : null;
    $actualEndDate = !empty($enddate) ? (new DateTime($enddate))->format('Y-m-d H:i:s') : null;

    // Adjust the dates to "DATE MINUS 4 HOURS"
    $adjustedStartDate = !empty($startdate) ? (new DateTime($startdate))->modify('-4 hours')->format('Y-m-d H:i:s') : null;
    $adjustedEndDate = !empty($enddate) ? (new DateTime($enddate))->modify('-4 hours')->format('Y-m-d H:i:s') : null;



    $currentDate = new DateTime(); // Current date/time
    $status = 'active';

    if ($adjustedStartDate && new DateTime($adjustedStartDate) > $currentDate) {
        $status = 'date stopped'; // Start date is in the future
    } elseif ($adjustedEndDate && new DateTime($adjustedEndDate) < $currentDate) {
        $status = 'date stopped'; // End date is in the past
    }




    $enddate = !empty($enddate) ? $enddate : null;
    if ($feedcpc === '' || $feedcpc === null) {
        $feedcpc = null;
    }

    // Generate a random 10-character alphanumeric string for feedid
    $feedid = bin2hex(random_bytes(5));

    $budgetType = filter_input(INPUT_POST, 'budget_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
   
    $budgetType = ($budgetType === 'CPA') ? 'CPA' : 'CPC'; // Default to CPC if not CPA

   
    // Insert into database
    try {
        // Updated the INSERT statement to include the acctnum column after custid
        $stmt = $pdo->prepare("INSERT INTO applcustfeeds (custid, acctnum, feedid, feedname, budget, cpc, status, arbcampcpc, arbcampcpa,date_start, date_end, actual_startdate, actual_enddate,budget_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?,?,? )");
        $stmt->execute([
            $_SESSION['custid'], // Assuming custid is also part of the session and needs to be included
            $_SESSION['acctnum'], // Include the acctnum from the session
            $feedid,
            $feedname,
            $feedbudget,
            $feedcpc,
            $status,
            !empty($arbcampcpc) ? $arbcampcpc : null,
            !empty($arbcampcpa) ? $arbcampcpa : null,
            $adjustedStartDate,   // Adjusted start date
            $adjustedEndDate,     // Adjusted end date
            $actualStartDate,     // Original start date
            $actualEndDate,        // Original end date
            $budgetType
        ]);
        setToastMessage('success', "Created Successfully.");
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
