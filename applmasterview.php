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

    // Fetch publishers
    $stmt3 = $conn->prepare("SELECT publisherid, publishername FROM applpubs ORDER BY publishername ASC");
    $stmt3->execute();
    $publishers = $stmt3->fetchAll(PDO::FETCH_ASSOC);

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
    setToastMessage('error', "Error: " . $e->getMessage());
}

// Function to calculate the next CRON job time
function getNextCronTime()
{
    $now = new DateTime('now', new DateTimeZone('UTC')); // Get current UTC time
    $currentMinutes = (int)$now->format('i');
    $currentHours = (int)$now->format('H');

    // Find the next 4-hour interval
    $nextHours = $currentHours - ($currentHours % 4) + 4;
    if ($nextHours >= 24) {
        $nextHours = 0; // Reset to midnight if past 24 hours
    }

    // Set the next CRON job time
    $nextCron = new DateTime($now->format('Y-m-d') . " $nextHours:12:00", new DateTimeZone('UTC'));

    // If the next CRON time is earlier than now, move to the next day
    if ($now >= $nextCron) {
        $nextCron->modify('+1 day');
    }

    return $nextCron->getTimestamp(); // Return as a Unix timestamp
}

// Get the next CRON job time
$nextCronTime = getNextCronTime();

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
        "Account Master View"
    ); ?>



    <section class="account_master_view_sec">
        <?php
        // Create the XML URL using the current session's account number
        $acctnum = $_SESSION['acctnum'];
        $xmlUrl = getUrl() . "/applfeeds/{$acctnum}.xml";
        ?>


        <div class="container-fluid">

            <p class="main_view_note"><b> Account-Level XML File:</b> <a href="<?= htmlspecialchars($xmlUrl) ?>" target="_blank"><?= htmlspecialchars($xmlUrl) ?></a><br>
                This XML file combines all the jobs from all the active campaigns across every Customer. This feed has everything that is currently running.
            </p>

            <div class="row w-100 mx-auto xml_mapping_sec pt-0">
                <div class="col-sm-12 col-md-12 px-0 d-none ">
                    <div class="card ">
                        <div class="card-body">
                            <div class="d-flex justify-content-between ">
                                <h5 class="card-title">Customer Accounts</h5>
                            </div>

                            <a href="applcreatecustomer.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Customer</a>

                            <?php if (empty($customers)): ?>
                                <p>No customer accounts found.</p>
                            <?php else: ?>
                                <div class=" table-responsive">
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
                <div class="col-sm-12 col-md-12 px-0 d-none">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">Job Inventory Pools</h5>
                                </div>
                                <a href="applcreatepool.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Job Pool</a>
                                <?php if (empty($jobPools)): ?>
                                    <p>No job pools found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <div class="custom_padding">

                                            <table class="job-pools-table table-striped ">
                                                <thead>
                                                    <tr>
                                                        <th>Job Pool Name</th>
                                                        <th>Arbitrage %</th>
                                                        <th>URL</th>
                                                        <th>Edit</th>
                                                        <th>Delete</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($jobPools as $jobPool): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($jobPool['jobpoolname']) ?></td>
                                                            <td><?= htmlspecialchars(number_format($jobPool['arbitrage'], 2)) ?></td>
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
                <div class="col-sm-12 col-md-12 px-0">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between card-title">
                                    <h5 class="card-title p-0">Customer Campaign Overview <span>(Next feed update starts in: <span id="countdown">Loading...</span>)</h5>
                                    <form action="applmasterview.php" method="get" class="d-flex align-items-end gap-3">
                                        <div>
                                            <label class="mb-0" for="startdate">Start:</label>
                                            <input type="date" id="startdate" class="form-control" name="startdate" value="<?= htmlspecialchars(substr($startdate, 0, 10)) ?>" required>
                                        </div>
                                        <div>
                                            <label class="mb-0" for="enddate">End:</label>
                                            <input type="date" id="enddate" class="form-control" name="enddate" value="<?= htmlspecialchars(substr($enddate, 0, 10)) ?>" required>
                                        </div>
                                        <div>
                                            <button class="btn_green my-0 w-auto py-1 no-wrap">Show Data</button>
                                        </div>
                                    </form>
                                </div>

                                <div class="table-responsive">
                                    <div class="custom_padding">
                                        <table class="campaign-overview-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Customer Name</th>
                                                    <th>Status</th>
                                                    <th>Budget</th>
                                                    <th>Spend</th>
                                                    <th>Clicks</th>
                                                    <th>Applies</th>
                                                    <th>CPA</th>
                                                    <th>CPC</th>
                                                    <th>Conv. Rate</th>
                                                    <th>Num Jobs</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($customerData as $data): ?>
                                                    <tr>
                                                        <td><a href="<?= $path; ?>applportal.php?custid=<?= htmlspecialchars($data['custid']); ?>"><?= htmlspecialchars($data['custcompany']); ?></a></td>
                                                        <td><?= htmlspecialchars($data['status']); ?></td>
                                                        <td>$<?= number_format($data['budget'], 2); ?></td>
                                                        <td>$<?= number_format($data['spend'], 2); ?></td>
                                                        <td><?= htmlspecialchars($data['clicks']); ?></td>
                                                        <td><?= htmlspecialchars($data['applies']); ?></td>
                                                        <td>$<?= number_format($data['cpa'], 2); ?></td>
                                                        <td>$<?= number_format($data['cpc'], 2); ?></td>
                                                        <td><?= number_format($data['conversion_rate'], 2); ?>%</td>
                                                        <td><?= number_format($data['numjobs']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-12 px-0 d-none">
                    <div class="">
                        <div class="card ">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">Publishers</h5>
                                </div>
                                <a href="applcreatepool.php" class="add-customer-button"><i class="fa fa-plus"></i> Add Publisher</a>

                                <div class="table-responsive">
                                    <div class="custom_padding">
                                        <table class="campaign-overview-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Publisher Name</th>
                                                    <th>Publisher ID</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($publishers)): ?>
                                                    <p>No publishers found.</p>
                                                <?php else: ?>
                                                    <?php foreach ($publishers as $publisher): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($publisher['publishername']) ?></td>
                                                            <td><?= htmlspecialchars($publisher['publisherid']) ?></td>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get the next CRON job time passed from PHP (Unix timestamp)
            const nextCronTime = <?php echo $nextCronTime * 1000; ?>; // Multiply by 1000 to convert to milliseconds

            function updateTimer() {
                const now = new Date().getTime();
                const timeDiff = nextCronTime - now;

                // Calculate hours, minutes, and seconds
                const hours = Math.floor(timeDiff / (1000 * 60 * 60));
                const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);

                // Display the result
                document.getElementById('countdown').innerHTML = `${hours}h ${minutes}m ${seconds}s`;

                // If time is up, reload the page to recalculate the next CRON time
                if (timeDiff < 0) {
                    clearInterval(interval);
                    document.getElementById('countdown').innerHTML = "Updating...";
                    setTimeout(() => location.reload(), 1000); // Reload after 1 second
                }
            }

            // Update the timer every second
            const interval = setInterval(updateTimer, 1000);
        });
    </script>
</body>

</html>