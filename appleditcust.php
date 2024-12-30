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
        $updateStmt = $pdo->prepare("UPDATE applcust SET custtype = :custtype, custcompany = :custcompany, jobpoolid = :jobpoolid, arbcustcpc = :arbcustcpc, arbcustcpa = :arbcustcpa WHERE custid = :custid");
        if ($updateStmt->execute([
            ':custtype' => $_POST['custtype'],
            ':custcompany' => $_POST['custname'],
            ':jobpoolid' => $_POST['jobpoolid'],
            ':custid' => $_POST['custid'],
            ':arbcustcpc' => $_POST['arbcustcpc'],
            ':arbcustcpa' => $_POST['arbcustcpa']
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
    <?php echo renderHeader(
        "Edit Customer",
        "<a href='" . getEnvPath() . "applmasterview.php'>
            <p class='mb-0 fs-6 text-white'>< Back</p>
        </a>"
    ); ?>


    <section class="job_section">
        <div class="container-fluid">
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card rounded-md shadow-md">
                            <div class="card-header p-0 d-flex justify-content-between">
                                <h5 class="card-title">Edit Customer </h5>
                            </div>
                            <div class="card-body">
                                <div class="card styled m-4 p-4">
                                    <div class="card-body p-0">
                                        <form action="appleditcust.php" method="post">
                                            <input type="hidden" name="custid" value="<?= htmlspecialchars($customer['custid']) ?>">
                                            <div class="cust-check mb-3">
                                                <div class="custom-radio">
                                                    <input type="radio" id="employer" name="custtype" value="emp" <?= ($customer['custtype'] ?? '') == 'emp' ? 'checked' : '' ?> required>
                                                    <label for="employer">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i> Employer
                                                    </label>
                                                </div>
                                                <div class="custom-radio">
                                                    <input type="radio" id="publisher" name="custtype" value="pub" <?= ($customer['custtype'] ?? '') == 'pub' ? 'checked' : '' ?> required>
                                                    <label for="publisher">
                                                        <i class="far fa-circle"></i>
                                                        <i class="fas fa-check-circle"></i> Publisher
                                                    </label><br>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3">
                                                <div class="w-100">
                                                    <label for="cpc_adjust">CPC Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcustcpc" value="<?php echo $customer['arbcustcpc']; ?>">
                                                </div>
                                                <div class="w-100">
                                                    <label for="cpa_adjust">CPA Adjust (%)</label>
                                                    <input type="number" step="0.01" placeholder="0.01" min="0" max="100.00" class="light-input" name="arbcustcpa" value="<?php echo $customer['arbcustcpa']; ?>">
                                                </div>
                                            </div>
                                            <div>
                                                <label for="custname">Customer Name:</label>
                                                <input type="text" id="custname" name="custname" class="light-input" value="<?= htmlspecialchars($customer['custcompany']) ?? '' ?>" required>
                                            </div>
                                            <div class="mt-3">
                                                <label for="jobpoolid">Select Job Pool:</label>
                                                <select id="jobpoolid" name="jobpoolid" class="light-input" required>
                                                    <?php foreach ($jobPools as $pool): ?>
                                                        <?php if (isset($pool['jobpoolid'], $customer['jobpoolid'])) { ?>
                                                            <option value="<?= htmlspecialchars($pool['jobpoolid']) ?>" <?= $pool['jobpoolid'] == $customer['jobpoolid'] ? 'selected' : '' ?>><?= htmlspecialchars($pool['jobpoolname']) ?></option>
                                                        <?php } ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button class="btn_green_dark w-100 mt-3">Create Customer</button>
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