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
    <div class="page-heading">
        <h1>Create a New Customer</h1>

       <div class="d-flex align-items-center">

            <div class="notification_wrapper">
                <button class="notify_btn">
                    <i class="fa-regular fa-bell"></i>
                    <span>15</span>
                </button>
            </div>

            <div class="account dropdown">
                <button class="btn d-flex align-items-center text-white dropdown-toggle" type="button" id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="profile_img">
                        <img src="https://www.profilebakery.com/wp-content/uploads/2023/04/ai-headshot-generator-4.jpg" alt="profile_img">
                    </span>
                    <div class="d-flex flex-column justify-content-start align-items-start">
                        <span class="title">Claudia Bernard</span>
                        <span class="subtitle">System Admin</span>
                    </div>
                </button>
                <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                    <li><a class="dropdown-item" href="#">Profile</a></li>
                    <li><a class="dropdown-item" href="#">Accounts</a></li>
                    <li><a class="dropdown-item" href="#">Users</a></li>
                </ul>
            </div>
       </div>
       

    </div>

    <section class="create_customer_sec">
        <div class="container-fluid">
            
            <form action="applcreatecustomer.php" method="post" class="create_customer_form" >
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
        </div>
    </section>

    <?php include 'footer.php'; ?>
</body>

</html>