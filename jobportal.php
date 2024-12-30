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

    // Fetch job pools
    $stmt2 = $conn->prepare("SELECT jobpoolid, jobpoolname, jobpoolurl, arbitrage FROM appljobseed WHERE acctnum = :acctnum");
    $stmt2->execute(['acctnum' => $_SESSION['acctnum']]);
    $jobPools = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Data processing for the new table
    $customerData = [];

    foreach ($customers as $customer) { 

        $custid = $customer['custid'];

        // Status
        $statusStmt = $conn->prepare("SELECT COUNT(*) FROM applcustfeeds WHERE custid = :custid AND status = 'active'");
        $statusStmt->execute(['custid' => $custid]);
        $status = $statusStmt->fetchColumn() > 0 ? 'Active' : 'Inactive';

        // Budget
        $budgetStmt = $conn->prepare("SELECT SUM(budget) FROM applcustfeeds WHERE custid = :custid");
        $budgetStmt->execute(['custid' => $custid]);
        $budget = $budgetStmt->fetchColumn() ?? 0;

        // Spend, Clicks, and Applies
        $eventStmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN eventtype = 'cpc' THEN cpc ELSE 0 END) AS total_cpc,
                SUM(CASE WHEN eventtype = 'cpa' THEN cpa ELSE 0 END) AS total_cpa,
                COUNT(CASE WHEN eventtype = 'cpc' THEN 1 ELSE NULL END) AS clicks,
                COUNT(CASE WHEN eventtype = 'cpa' THEN 1 ELSE NULL END) AS applies
            FROM applevents
            WHERE custid = :custid AND timestamp BETWEEN :startdate AND :enddate
        ");
        $eventStmt->execute(['custid' => $custid, 'startdate' => $startdate, 'enddate' => $enddate]);
        $eventData = $eventStmt->fetch(PDO::FETCH_ASSOC);
        $total_cpc = $eventData['total_cpc'] ?? 0;
        $total_cpa = $eventData['total_cpa'] ?? 0;
        $clicks = $eventData['clicks'] ?? 0;
        $applies = $eventData['applies'] ?? 0;
        $spend = $total_cpc + $total_cpa;

        // CPA and CPC
        $cpa = $applies > 0 ? $spend / $applies : 0;
        $cpc = $clicks > 0 ? $spend / $clicks : 0;

        // Conversion Rate
        $conversion_rate = $clicks > 0 ? ($applies / $clicks) * 100 : 0;

        // Num Jobs
        $numJobsStmt = $conn->prepare("SELECT SUM(numjobs) FROM applcustfeeds WHERE custid = :custid");
        $numJobsStmt->execute(['custid' => $custid]);
        $numJobs = $numJobsStmt->fetchColumn() ?? 0;

        $customerData[] = [
            'custid' => $custid,
            'custcompany' => $customer['custcompany'],
            'status' => $status,
            'budget' => $budget,
            'spend' => $spend,
            'clicks' => $clicks,
            'applies' => $applies,
            'cpa' => $cpa,
            'cpc' => $cpc,
            'conversion_rate' => $conversion_rate,
            'numjobs' => $numJobs,
        ];
    }

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
        "Edit Setting for Job Pool #015316315"
    ); ?>

    <section class="job_section">

        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <p class="job_title">Edit Job Pool Name</p>
                        <input type="text" class="job_input" name="job_pool_name" placeholder="Enter Job Pool Name">
                        <button class="update_btn">Update Job Pool Name </button>
                    </div>
                </div>

                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <p class="job_title">Edit Job URL</p>
                        <input type="text" class="job_input" name="job_pool_url" placeholder="Enter Job Pool URL">
                        <button class="update_btn">Update Job Pool URL </button>
                    </div>
                </div>

                <!-- <div class="col-sm-12 col-md-4">
                    <div class="job_card">
                        <p class="job_title">Set Arbitrage %</p>
                        <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="Enter Arbitrage ">
                        <button class="update_btn">Set Arbitrage % </button>
                    </div>
                </div> -->

                <!-- another section  -->


            </div>
            <div class="row xml_mapping_sec">
                <div class="col-sm-12 col-md-12">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">XML Mappings for Job Pool #015316315</h5>
                                </div>
                                <div class="table-responsive">
                                    <div class="custom_padding">
                                        <table
                                            id="zero_config"
                                            class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>XML Node</th>
                                                    <th>Value</th>
                                                    <th>Database Column</th>
                                                    <th>Action</th>
                                                    <th></th>

                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Title</td>
                                                    <td>Test Job 1</td>
                                                    <td>Matched</td>
                                                    <td>
                                                        <select name="select" id="">
                                                            <option value="title">Title</option>
                                                            <option value="job">Job</option>
                                                        </select>
                                                    </td>
                                                    <td>

                                                        <button class="update_btn">Set Mapping</button>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Title</td>
                                                    <td>Test Job 1</td>
                                                    <td>Matched</td>
                                                    <td>
                                                        <select name="select" id="">
                                                            <option value="title">Title</option>
                                                            <option value="job">Job</option>
                                                        </select>
                                                    </td>
                                                    <td>

                                                        <button class="update_btn">Set Mapping</button>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Title</td>
                                                    <td>Test Job 1</td>
                                                    <td>Matched</td>
                                                    <td>
                                                        <select name="select" id="">
                                                            <option value="title">Title</option>
                                                            <option value="job">Job</option>
                                                        </select>
                                                    </td>
                                                    <td>

                                                        <button class="update_btn">Set Mapping</button>
                                                    </td>
                                                </tr>


                                            </tbody>
                                            <!-- <tfoot>
                                        <tr>
                                        <th>XML Node</th>
                                            <th>Value</th>
                                            <th>Dataabse Column</th>
                                            <th>Action</th>
                                            <th></th>
                                        </tr>
                                        </tfoot> -->
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- another section  -->
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">Custom Fields and Mappings</h5>
                                </div>
                                <div class="table-responsive">
                                    <div class="custom_padding">

                                        <table
                                            id="zero_config"
                                            class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Field Name</th>
                                                    <th>Static Value</th>
                                                    <th>App Jobs Map</th>
                                                    <th>Action</th>

                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Test Field</td>
                                                    <td>abcde</td>
                                                    <td>cpc</td>
                                                    <td class="custom_td_width">
                                                        <div class="custom_td_input">

                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="Test Field">
                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="abcde">

                                                            <select name="select" id="">
                                                                <option value="title">Title</option>
                                                                <option value="job">Job</option>
                                                            </select>

                                                        </div>
                                                        <div class="custom_td_btn">

                                                            <button class="update_btn">Update</button>
                                                            <button class="update_btn">Delete</button>
                                                        </div>
                                                    </td>

                                                </tr>


                                                <tr>
                                                    <td>Test Field</td>
                                                    <td>abcde</td>
                                                    <td>cpc</td>
                                                    <td class="custom_td_width">
                                                        <div class="custom_td_input">

                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="Test Field">
                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="abcde">

                                                            <select name="select" id="">
                                                                <option value="title">Title</option>
                                                                <option value="job">Job</option>
                                                            </select>

                                                        </div>
                                                        <div class="custom_td_btn">

                                                            <button class="update_btn">Update</button>
                                                            <button class="update_btn">Delete</button>
                                                        </div>
                                                    </td>

                                                </tr>


                                                <tr>
                                                    <td>Test Field</td>
                                                    <td>abcde</td>
                                                    <td>cpc</td>
                                                    <td class="custom_td_width">
                                                        <div class="custom_td_input">

                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="Test Field">
                                                            <input type="text" class="job_input" name="job_pool_arbitrage" placeholder="abcde">

                                                            <select name="select" id="">
                                                                <option value="title">Title</option>
                                                                <option value="job">Job</option>
                                                            </select>

                                                        </div>
                                                        <div class="custom_td_btn">

                                                            <button class="update_btn">Update</button>
                                                            <button class="update_btn">Delete</button>
                                                        </div>
                                                    </td>

                                                </tr>

                                            </tbody>
                                            <!-- <tfoot>
                                        <tr>
                                        <th>XML Node</th>
                                            <th>Value</th>
                                            <th>Dataabse Column</th>
                                            <th>Action</th>
                                            <th></th>
                                        </tr>
                                        </tfoot> -->
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- another section  -->
            <div class="row xml_mapping_sec second">
                <div class="col-sm-12 col-md-12">
                    <div class="add_field_form">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">Custom Fields and Mappings</h5>
                                </div>

                                <form action="#" class="custom_padding">
                                    <label for="fieldName">Field Name:</label>
                                    <input type="text" class="job_input" name="fieldName">
                                    <label for="staticValue">Field Name:</label>
                                    <input type="text" class="job_input" name="staticValue">
                                    <label for="select">Apply Job Map:</label>
                                    <select name="select" id="">

                                        <option value="title">Title</option>
                                        <option value="job">Job</option>
                                    </select>
                                    <button class="update_btn">Add Custom Field</button>
                                </form>
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