<?php

include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
} 
include 'header.php';

// Fetch job pool names and ids for the dropdown
$jobPools = [];
try {
    $stmt = $pdo->prepare("SELECT jobpoolid, jobpoolname FROM appljobseed WHERE acctnum = :acctnum");
    $stmt->execute([':acctnum' => $_SESSION['acctnum']]);
    $jobPools = $stmt->fetchAll();
} catch (PDOException $e) {
    setToastMessage('error', "Error fetching job pools: " . $e->getMessage());
}

$customer = [];
// Fetch customer data for editing
if (isset($_GET['custid'])) {
    $stmt = $pdo->prepare("SELECT * FROM applcust WHERE custid = :custid");
    $stmt->execute([':custid' => $_GET['custid']]);
    $customer = $stmt->fetch();
}

// Update customer on form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custid'])) {
    try {
        $updateStmt = $pdo->prepare("UPDATE applcust SET custtype = :custtype, custcompany = :custcompany, jobpoolid = :jobpoolid WHERE custid = :custid");
        if ($updateStmt->execute([
            ':custtype' => $_POST['custtype'],
            ':custcompany' => $_POST['custname'],
            ':jobpoolid' => $_POST['jobpoolid'],
            ':custid' => $_POST['custid']
        ])) {
            setToastMessage('success', "Customer updated successfully.");
            header("Location: applmasterview.php"); // Redirect
            exit();
        }
    } catch (PDOException $e) {
        setToastMessage('error', "Update failed: " . $e->getMessage());
    }
}
?>


<!DOCTYPE html>
<html>

<head>
    <title>Edit Customer Accounts | Appljack</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <h1>Edit Customer</h1><a href="/applmasterview.php">
        <p>
            << Back</p>
    </a>
    <form action="appleditcust.php" method="post">
        <input type="hidden" name="custid" value="<?= htmlspecialchars($customer['custid']) ?>">
        <div>
            <input type="radio" id="employer" name="custtype" value="emp" <?= $customer['custtype'] == 'emp' ? 'checked' : '' ?> required>
            <label for="employer">Employer</label>
            <input type="radio" id="publisher" name="custtype" value="pub" <?= $customer['custtype'] == 'pub' ? 'checked' : '' ?> required>
            <label for="publisher">Publisher</label><br>
        </div>
        <div>
            <label for="custname">Customer Name:</label>
            <input type="text" id="custname" name="custname" value="<?= htmlspecialchars($customer['custcompany']) ?>" required>
        </div>
        <div>
            <label for="jobpoolid">Select Job Pool:</label>
            <select id="jobpoolid" name="jobpoolid" required>
                <?php foreach ($jobPools as $pool): ?>
                    <option value="<?= htmlspecialchars($pool['jobpoolid']) ?>" <?= $pool['jobpoolid'] == $customer['jobpoolid'] ? 'selected' : '' ?>><?= htmlspecialchars($pool['jobpoolname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Update Customer</button>
    </form>

    <?php include 'footer.php'; ?>
</body>

</html>