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
$customerData = [];
try {
    // Fetch current publishers 
    $publishers = [];
    $stmt = $conn->prepare("
        SELECT 
            p.publisherid, 
            p.publishername, 
            p.publisher_contact_name, 
            p.publisher_contact_email,
            p.pubstatus,
            GROUP_CONCAT(f.feedid) AS feedids,
            GROUP_CONCAT(f.feedname) AS feednames,
            GROUP_CONCAT(f.budget) AS budgets,
            GROUP_CONCAT(f.status) AS statuses,
            GROUP_CONCAT(f.numjobs) AS numjobs
        FROM applpubs p
        LEFT JOIN applcustfeeds f ON FIND_IN_SET(p.publisherid, f.activepubs) > 0
        WHERE p.acctnum = ?
        GROUP BY p.publisherid
    ");
    $stmt->execute([$_SESSION['acctnum']]);
    $publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Modify the status and update the budget for each publisher
    foreach ($publishers as &$publisher) {
        // Convert status to 'Active' or 'Inactive'
        $publisher['status'] = (isset($statusList[0]) && $statusList[0] > 0) ? 'Active' : 'Inactive';

        // Fetch and update the budget for each publisher
        $budgetStmt = $conn->prepare("
            SELECT SUM(budget) FROM applcustfeeds 
            WHERE FIND_IN_SET(:publisherid, activepubs) > 0
        ");
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
        $eventStmt->execute([
            'publisherid' => $publisher['publisherid'],
            'startdate' => $startdate,
            'enddate' => $enddate
        ]);
        $eventData = $eventStmt->fetch(PDO::FETCH_ASSOC);
      
        $publisher['spend'] = ($eventData['total_cpc'] ?? 0) + ($eventData['total_cpa'] ?? 0);
        $publisher['clicks'] = $eventData['clicks'] ?? 0;
        $publisher['applies'] = $eventData['applies'] ?? 0;

        // Compute CPA and CPC safely
        $publisher['cpa'] = ($publisher['applies'] > 0) ? ($publisher['spend'] / $publisher['applies']) : 0;
        $publisher['cpc'] = ($publisher['clicks'] > 0) ? ($publisher['spend'] / $publisher['clicks']) : 0;

        // Compute Conversion Rate
        $publisher['conversion_rate'] = ($publisher['clicks'] > 0) ? (($publisher['applies'] / $publisher['clicks']) * 100) : 0;

        // Fetch and update the total number of jobs
        $numJobsStmt = $conn->prepare("
            SELECT SUM(numjobs) FROM applcustfeeds 
            WHERE FIND_IN_SET(:publisherid, activepubs) > 0
        ");
        $numJobsStmt->execute(['publisherid' => $publisher['publisherid']]);
        $publisher['numjobs'] = $numJobsStmt->fetchColumn() ?? 0;
    }
    unset($publisher); // Break reference with the last element

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

function getNextCronTime()
{
    $now = new DateTime('now', new DateTimeZone('UTC')); // Get current UTC time
    error_log("Current UTC time: " . $now->format('Y-m-d H:i:s'));

    $currentMinutes = (int)$now->format('i');
    $currentHours = (int)$now->format('H');
    error_log("Current Hours: $currentHours, Current Minutes: $currentMinutes");

    // Find the next 4-hour interval
    $nextHours = $currentHours - ($currentHours % 4) + 4;
    if ($nextHours >= 24) {
        $nextHours = 0; // Reset to midnight if past 24 hours
    }
    error_log("Next 4-hour interval: $nextHours");

    // Set the next CRON job time
    $nextCron = new DateTime($now->format('Y-m-d') . " $nextHours:12:00", new DateTimeZone('UTC'));
    error_log("Initial next CRON time: " . $nextCron->format('Y-m-d H:i:s'));

    // If the next CRON time is earlier than now, move to the next day
    if ($now >= $nextCron) {
        $nextCron->modify('+1 day');
        error_log("Adjusted next CRON time to next day: " . $nextCron->format('Y-m-d H:i:s'));
    }

    $timestamp = $nextCron->getTimestamp();
    error_log("Next CRON job Unix timestamp: $timestamp");

    return $timestamp; // Return as a Unix timestamp
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
        "Publishers"
    ); ?>
    <section class="job_section">
        <div class="container-fluid p-0">
            <div class="row w-100 mx-auto xml_mapping_sec py-0">
            <div class="d-flex justify-content-between card-title mb-3">
                                    <h5 class="card-title p-0"> Publisher Overview <span>(Next feed update starts in: <span id="countdown">Loading...</span>)</h5>
                                    <form action="publisherspool.php" method="get" class="d-flex align-items-end gap-3">
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
            <th>Status</th>
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
                <td>
    <button 
        class="btn btn-sm <?= ($publisher['pubstatus'] == 'active') ? 'btn-success' : 'btn-secondary' ?>" 
        onclick="toggleStatus('<?= $publisher['publisherid'] ?>', '<?= $publisher['pubstatus'] ?>')"
    >
        <?= htmlspecialchars($publisher['pubstatus']) ?>
    </button>
</td>

                    <td><?= htmlspecialchars($publisher['publishername']) ?></td>
                    <td><?= htmlspecialchars($publisher['publisherid']) ?></td>
            
                    <!-- <td><?= $publisher['status'] ?></td> -->
                    <!-- <td>$<?= number_format($publisher['budget'], 2); ?></td> -->
                    <td>$<?= number_format($publisher['spend'], 2); ?></td>
                    <td><?= htmlspecialchars($publisher['clicks']); ?></td>
                    <td><?= htmlspecialchars($publisher['applies']); ?></td>
                    <td>$<?= number_format($publisher['cpa'], 2); ?></td>
                    <td>$<?= number_format($publisher['cpc'], 2); ?></td>
                    <td><?= number_format(number_format($publisher['cpc'], 2), 2); ?>%</td>
                    <td><?= number_format($publisher['numjobs']); ?></td>
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
        function toggleStatus(publisherId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'Active';

        // Send an AJAX request to update the status
        fetch('update_publisher_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                publisherid: publisherId,
                status: newStatus
            }),
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Status Updated',
                    text: `Publisher status changed to ${newStatus}.`,
                    timer: 2000,
                    showConfirmButton: false
                });
                location.reload(); // Reload the page to reflect changes
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to update status.',
            });
        });
    }
</script>
</html>