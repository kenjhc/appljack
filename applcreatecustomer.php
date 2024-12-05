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
        $stmt = $pdo->prepare("INSERT INTO applcust (custid, acctnum, custtype, custcompany, jobpoolid, arbcustcpc, arbcustcpa) VALUES (:custid, :acctnum, :custtype, :custcompany, :jobpoolid, :arbcustcpc, :arbcustcpa)");
        $stmt->execute([
            ':custid' => $custid,
            ':acctnum' => $_SESSION['acctnum'],
            ':custtype' => $_POST['custtype'],
            ':custcompany' => $_POST['custname'],
            ':jobpoolid' => $_POST['jobpoolid'], // Using the jobpoolid from the dropdown
            ':arbcustcpc' => $_POST['arbcustcpc'],
            ':arbcustcpa' => $_POST['arbcustcpa']
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

    <?php echo renderHeader("Customer"); ?>

    <section class="job_section">
        <div class="container-fluid">
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Create a New Customer </h5>
                            </div>
                            <div class="card-body">
                                <div class="card styled m-4 p-4">
                                    <div class="card-body p-0">
                                        <form action="applcreatecustomer.php" method="post">
                                            <div class="cust-check mb-3">
                                                <div class="custom-radio">
                                                    <input type="radio" id="employer" name="custtype" value="emp" required>
                                                    <label for="employer">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i> Employer
                                                    </label>
                                                </div>
                                                <div class="custom-radio">
                                                    <input type="radio" id="publisher" name="custtype" value="pub" required>
                                                    <label for="publisher">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i> Publisher
                                                    </label><br>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3">
                                                <div class="w-100">
                                                    <label for="cpc_adjust">CPC Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcustcpc">
                                                </div>
                                                <div class="w-100">
                                                    <label for="cpa_adjust">CPA Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcustcpa">
                                                </div>
                                            </div>
                                            <div>
                                                <label for="custname">Customer Name:</label>
                                                <input type="text" id="custname" name="custname" class="light-input" required>
                                            </div>
                                            <div class="mt-3">
                                                <label for="jobpoolid">Select Job Pool:</label>
                                                <select id="jobpoolid" name="jobpoolid" class="light-input" required>
                                                    <?php foreach ($jobPools as $pool): ?>
                                                        <option value="<?= htmlspecialchars($pool['jobpoolid']) ?>"><?= htmlspecialchars($pool['jobpoolname']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button class="btn_green_dark w-100 mt-3">Update Customer</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php include 'footer.php'; ?>

</body>

</html>