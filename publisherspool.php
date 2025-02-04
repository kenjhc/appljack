<?php
include 'database/db.php';
require 'PublisherController.php'; 
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
$applies = '';
$clicks  = '';

try {
    // Fetch current publishers 
    $publishers = [];
    $stmt = $conn->prepare("
        SELECT 
            p.publisherid, 
            p.publishername, 
            p.publisher_contact_name, 
            p.publisher_contact_email,
            f.feedid,
            f.feedname,
            f.budget,
            f.status,
            f.numjobs
        FROM applpubs p
        LEFT JOIN applcustfeeds f ON p.publisherid = f.activepubs
        WHERE p.acctnum = ?
    ");
    $stmt->execute([$_SESSION['acctnum']]);
    $publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Modify the status and update the budget for each publisher
    foreach ($publishers as &$publisher) {
        $publisher['status'] = $publisher['status'] > 0 ? 'Active' : 'Inactive';

        // Fetch and update the budget for each publisher
        $budgetStmt = $conn->prepare("SELECT SUM(budget) FROM applcustfeeds WHERE activepubs = :publisherid");
        $budgetStmt->execute(['publisherid' => $publisher['publisherid']]);
        $publisher['budget'] = $budgetStmt->fetchColumn() ?? 0;

        // Fetch and update the total spend, clicks, and applies for each publisher
        $eventStmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN eventtype = 'cpc' THEN cpc ELSE 0 END) AS total_cpc,
                SUM(CASE WHEN eventtype = 'cpa' THEN cpa ELSE 0 END) AS total_cpa,
                COUNT(CASE WHEN eventtype = 'cpc' THEN 1 ELSE NULL END) AS clicks,
                COUNT(CASE WHEN eventtype = 'cpa' THEN 1 ELSE NULL END) AS applies
            FROM applevents
            WHERE publisherid = :publisherid AND timestamp BETWEEN :startdate AND :enddate
        ");
        $eventStmt->execute(['publisherid' => $publisher['publisherid'], 'startdate' => $startdate, 'enddate' => $enddate]);
        $eventData = $eventStmt->fetch(PDO::FETCH_ASSOC);
        $total_cpc = $eventData['total_cpc'] ?? 0;
        $total_cpa = $eventData['total_cpa'] ?? 0;
        $publisher['spend'] = $total_cpc + $total_cpa;
        $publisher['clicks'] = $eventData['clicks'] ?? 0;
        $publisher['applies'] = $eventData['applies'] ?? 0;

        $cpa = $applies > 0 ? $spend / $applies : 0;
        $cpc = $clicks > 0 ? $spend / $clicks : 0;

        // Conversion Rate
        $conversion_rate = $clicks > 0 ? ($applies / $clicks) * 100 : 0;

        // Num Jobs
        $numJobsStmt = $conn->prepare("SELECT SUM(numjobs) FROM applcustfeeds WHERE activepubs = :publisherid");
        $numJobsStmt->execute(['publisherid' => $publisher['publisherid']]);
        $numJobs = $numJobsStmt->fetchColumn() ?? 0;
    }
    unset($publisher); // Break the reference with the last element

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Handle job pool deletion
        if (isset($_POST['delete_jobpoolid'])) {
            $deleteStmt = $conn->prepare("DELETE FROM appljobseed WHERE jobpoolid = :jobpoolid AND acctnum = :acctnum");
            $deleteStmt->execute([
                'jobpoolid' => $_POST['delete_jobpoolid'],
                'acctnum' => $_SESSION['acctnum']
            ]);
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // Handle customer deletion
        if (isset($_POST['delete_custid'])) {
            $custid = $_POST['delete_custid'];

            $conn->beginTransaction();
            try {
                // $conn->prepare("DELETE FROM applevents WHERE custid = :custid")->execute(['custid' => $custid]);
                // $conn->prepare("DELETE FROM applcustfeeds WHERE custid = :custid")->execute(['custid' => $custid]);
                // $conn->prepare("DELETE FROM applcust WHERE custid = :custid")->execute(['custid' => $custid]);
                // $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                setToastMessage('error', "Failed to delete customer: " . $e->getMessage());
                exit;
            }

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
        "Publishers"
    ); ?>
    <section class="job_section">
        <div class="container-fluid p-0">
            <div class="row w-100 mx-auto xml_mapping_sec py-0">
                <div class="col-sm-12 col-md-12 px-0">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <h5 class="card-title">Publishers</h5>
                                </div>
                                <a href="applcreatepub.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Publisher</a>

                                <div class="table-responsive">
                                    <div class="custom_padding">
                                    <table class="campaign-overview-table table-striped">
    <thead>
        <tr>
            <th>Publisher Name</th>
            <th>Publisher ID</th>
            <!-- <th>Status</th> -->
            <!-- <th>Budget</th> -->
            <th>Spend</th>
            <th>Clicks</th>
            <th>Applies</th>
            <th>CPA</th>
            <th>CPC</th>
            <th>Conv. Rate</th>
            <th>Num Jobs</th>
            <!-- <th>Publisher Contact Name</th>
            <th>Publisher Contact Email</th> -->
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($publishers)): ?>
            <tr>
                <td colspan="5">No publishers found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($publishers as $publisher): ?>
                <tr>
                  
                    <td><?= htmlspecialchars($publisher['publishername']) ?></td>
                    <td><?= htmlspecialchars($publisher['publisherid']) ?></td>
                    <!-- <td><?= $publisher['status'] ?></td> -->
                    <!-- <td>$<?= number_format($publisher['budget'], 2); ?></td> -->
                    <td>$<?= number_format($publisher['spend'], 2); ?></td>
                    <td><?= htmlspecialchars($publisher['clicks']); ?></td>
                    <td><?= htmlspecialchars($publisher['applies']); ?></td>
                    <td>$<?= number_format($cpa, 2); ?></td>
                    <td>$<?= number_format($cpc, 2); ?></td>
                    <td><?= number_format($conversion_rate, 2); ?>%</td>
                    <td><?= number_format($numJobs); ?></td>
                    <!-- <td><?= htmlspecialchars($publisher['publisher_contact_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($publisher['publisher_contact_email'] ?? 'N/A') ?></td> -->
                    <td>
                    <a href="view_publisher.php?publisherid=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-info" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="edit_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-success" title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="delete_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this publisher?');">
                        <i class="fas fa-trash"></i>
                    </a>
                </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

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
<?php
if (isset($_GET['message']) && isset($_GET['type'])):
    $message = htmlspecialchars($_GET['message']);
    $type = htmlspecialchars($_GET['type']); // Use 'success' or 'error'
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: '<?= $type ?>', // 'success' or 'error'
                title: '<?= $message ?>',
                showConfirmButton: false,
                timer: 3000
            });
        });
    </script>
<?php
endif;
?>

</html>