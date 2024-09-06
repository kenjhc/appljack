<?php

include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Fetch job pool names and ids for the dropdown
try {
    $stmt = $pdo->prepare("SELECT jobpoolid, jobpoolname FROM appljobseed WHERE acctnum = :acctnum");
    $stmt->execute([':acctnum' => $_SESSION['acctnum']]);
    $jobPools = $stmt->fetchAll();
} catch (PDOException $e) {
    $jobPools = [];
    // Handle error if needed
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Generate a unique 10-digit custid
        $custid = mt_rand(1000000000, 9999999999);

        // Prepare SQL and bind parameters
        $stmt = $pdo->prepare("INSERT INTO applcust (custid, acctnum, custtype, custcompany, jobpoolid) VALUES (:custid, :acctnum, :custtype, :custcompany, :jobpoolid)");
        $stmt->execute([
            ':custid' => $custid,
            ':acctnum' => $_SESSION['acctnum'],
            ':custtype' => $_POST['custtype'],
            ':custcompany' => $_POST['custname'],
            ':jobpoolid' => $_POST['jobpoolid'] // Using the jobpoolid from the dropdown
        ]);

        setToastMessage('success', "New customer created successfully. Customer ID: $custid");

        // Redirect or display success message
    } catch (PDOException $e) {
        setToastMessage('error',  "Database error: " . $e->getMessage());

        // Handle error
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Create a New Customer | Appljack</title>
    <!-- Include your header.php where you might load stylesheets and other metadata -->
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <h1>Create a New Customer</h1>
    <form action="applcreatecustomer.php" method="post">
        <div>
            <input type="radio" id="employer" name="custtype" value="emp" required>
            <label for="employer">Employer</label>

            <input type="radio" id="publisher" name="custtype" value="pub" required>
            <label for="publisher">Publisher</label><br>
        </div>
        <div>
            <label for="custname">Customer Name:</label>
            <input type="text" id="custname" name="custname" required>
        </div>
        <div>
            <label for="jobpoolid">Select Job Pool:</label>
            <select id="jobpoolid" name="jobpoolid" required>
                <?php foreach ($jobPools as $pool): ?>
                    <option value="<?= htmlspecialchars($pool['jobpoolid']) ?>"><?= htmlspecialchars($pool['jobpoolname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Create Customer</button>
    </form>

    <?php include 'footer.php'; ?>
</body>

</html>