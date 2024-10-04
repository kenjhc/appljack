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

    // Fetch customers
    $stmt = $conn->prepare("SELECT custid, custcompany FROM applcust WHERE acctnum = :acctnum ORDER BY custcompany ASC");
    $stmt->execute(['acctnum' => $_SESSION['acctnum']]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {

        // Handle customer deletion
        if (isset($_POST['delete_custid'])) {
            $custid = $_POST['delete_custid'];

            $conn->beginTransaction();
            try {
                $conn->prepare("DELETE FROM applevents WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->prepare("DELETE FROM applcustfeeds WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->prepare("DELETE FROM applcust WHERE custid = :custid")->execute(['custid' => $custid]);
                $conn->commit();
                setToastMessage('success', "Deleted Successfully.");
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
        "Customer Accounts"
    ); ?>
    <section class="job_section">
        <div class="container-fluid p-0">
            <div class="row w-100 mx-auto xml_mapping_sec py-0">
                <div class="col-sm-12 col-md-12 px-0">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <h5 class="card-title">Customer Accounts</h5>
                                </div>
                                <a href="applcreatecustomer.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Customer</a>

                                <?php if (empty($customers)): ?>
                                    <p>No customer accounts found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <div class="custom_padding">
                                            <table class="customers-table table-striped ">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>ID</th>
                                                        <th>Edit</th>
                                                        <th>Remove</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($customers as $customer): ?>
                                                        <tr>
                                                            <td><a href="applportal.php?custid=<?= htmlspecialchars($customer['custid']) ?>"><?= htmlspecialchars($customer['custcompany']) ?></a></td>
                                                            <td><?= htmlspecialchars($customer['custid']) ?></td>
                                                            <td class="edit-button-cell">
                                                                <a href="appleditcust.php?custid=<?= $customer['custid'] ?>" class=" edit_btn">Edit</a>
                                                            </td>
                                                            <td class="delete-button-cell">
                                                                <form method="POST" class="delete-form" onsubmit="return confirmDeleteCustomer('<?= addslashes(htmlspecialchars($customer['custcompany'])) ?>');">
                                                                    <input type="hidden" name="delete_custid" value="<?= $customer['custid'] ?>">
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