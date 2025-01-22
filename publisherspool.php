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

try {
    // Fetch current publishers 
    $publishers = [];
    $stmt = $conn->prepare("SELECT publisherid, publishername, publisher_contact_name, publisher_contact_email FROM applpubs WHERE acctnum = ?");
    $stmt->execute([$_SESSION['acctnum']]);
    $publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                $conn->prepare("DELETE FROM applevents WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->prepare("DELETE FROM applcustfeeds WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->prepare("DELETE FROM applcust WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->commit();
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
            <th>Publisher Contact Name</th>
            <th>Publisher Contact Email</th>
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
                    <td><?= htmlspecialchars($publisher['publisher_contact_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($publisher['publisher_contact_email'] ?? 'N/A') ?></td>
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