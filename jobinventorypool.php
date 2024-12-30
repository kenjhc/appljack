<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Default date range to the current month
$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-t');
$startdate = isset($_GET['startdate']) ? $_GET['startdate'] : $defaultStartDate;
$enddate = isset($_GET['enddate']) ? $_GET['enddate'] : $defaultEndDate;
$startdate = date('Y-m-d', strtotime($startdate)) . " 00:00:00";
$enddate = date('Y-m-d', strtotime($enddate)) . " 23:59:59";

try {
    // Fetch job pools
    $stmt2 = $conn->prepare("SELECT jobpoolid, jobpoolname, jobpoolurl, arbitrage FROM appljobseed WHERE acctnum = :acctnum");
    $stmt2->execute(['acctnum' => $_SESSION['acctnum']]);
    $jobPools = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Handle job pool deletion
        if (isset($_POST['delete_jobpoolid'])) {
            $deleteStmt = $conn->prepare("DELETE FROM appljobseed WHERE jobpoolid = :jobpoolid AND acctnum = :acctnum");
            $deleteStmt->execute([
                'jobpoolid' => $_POST['delete_jobpoolid'],
                'acctnum' => $_SESSION['acctnum']
            ]);

            setToastMessage('success', "Deleted Successfully.");

            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
} catch (PDOException $e) {
    setToastMessage('error', "Error: " . $e->getMessage());
    setToastMessage('error', "Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Customer Accounts</title>
    <?php include 'header.php'; ?>
    <script>
        function confirmDelete(jobPoolName) {
            return confirm('Are you sure you want to delete this Job Pool: ' + jobPoolName + '?');
        }

        function confirmDeleteCustomer(customerName) {
            return confirm('Are you sure you want to delete ' + customerName + '? All data, campaigns and events will be deleted.');
        }
    </script>
</head>

<body>
    <?php include 'appltopnav.php'; ?>
    <?php echo renderHeader(
        "Job Inventory Pools"
    ); ?>
    <section class="job_section">
        <div class="container-fluid p-0">
            <div class="row w-100 mx-auto xml_mapping_sec py-0">
                <div class="col-sm-12 col-md-12 px-0">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <h5 class="card-title">Job Inventory Pools</h5>
                                </div>
                                <a href="applcreatepool.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Job Pool</a>
                                <?php if (empty($jobPools)): ?>
                                    <p>No job pools found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <div class="custom_padding">
                                            <table class="job-pools-table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Job Pool Name</th>
                                                        <!-- <th>Arbitrage %</th> -->
                                                        <th>URL</th>
                                                        <th>Edit</th>
                                                        <th>Delete</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($jobPools as $jobPool): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($jobPool['jobpoolname']) ?></td>
                                                            <!-- <td><?= htmlspecialchars(number_format($jobPool['arbitrage'], 2)) ?></td> -->
                                                            <td><a href="<?= htmlspecialchars($jobPool['jobpoolurl']) ?>"><?= htmlspecialchars($jobPool['jobpoolurl']) ?></a></td>
                                                            <td class="edit-button-cell">
                                                                <a href="appleditjobpool.php?jobpoolid=<?= $jobPool['jobpoolid'] ?>" class="edit_btn">Edit</a>
                                                            </td>
                                                            <td class="delete-button-cell">
                                                                <form method="POST" class="delete-form" onsubmit="return confirmDelete('<?= addslashes(htmlspecialchars($jobPool['jobpoolname'])) ?>');">
                                                                    <input type="hidden" name="delete_jobpoolid" value="<?= $jobPool['jobpoolid'] ?>">
                                                                    <button type="submit" class="delete_btn">Delete</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>
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