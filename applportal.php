<?php

include 'database/db.php';


// Define default dates to cover the current month's range
$defaultStartDate = date('Y-m-01');
$defaultEndDate = date('Y-m-t'); // Last day of the current month

$startdate = isset($_GET['startdate']) ? $_GET['startdate'] : date('Y-m-01');
$enddate = isset($_GET['enddate']) ? $_GET['enddate'] : date('Y-m-t');

$startdate = date('Y-m-d', strtotime($startdate)) . " 00:00:00";
$enddate = date('Y-m-d', strtotime($enddate)) . " 23:59:59";



// Convert to readable formats for display
$displayStartDate = date('F j, Y', strtotime($startdate));
$displayEndDate = date('F j, Y', strtotime($enddate));

// Output the dates for debugging
// var_dump($startdate, $enddate);

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Handle custid from dropdown or session
$custid = filter_input(INPUT_GET, 'custid', FILTER_SANITIZE_NUMBER_INT); // Get custid from dropdown selection

if ($custid) {
    $_SESSION['custid'] = $custid; // Update session with new custid
} else {
    $custid = $_SESSION['custid'] ?? null; // Fallback to session custid or null
}

// Fetch customer information
$customerInfo = [];
$jobPoolName = 'N/A'; // Default value if no job pool is associated or an error occurs

$pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

if ($custid) {
    try {
        // Fetch the customer's details
        $custInfoStmt = $pdo->prepare("SELECT custcompany, custtype, jobpoolid FROM applcust WHERE custid = ?");
        $custInfoStmt->execute([$custid]);
        $customerInfo = $custInfoStmt->fetch();

        // If a jobpoolid is associated with the customer, fetch the job pool name
        if ($customerInfo && !empty($customerInfo['jobpoolid'])) {
            $jobPoolStmt = $pdo->prepare("SELECT jobpoolname FROM appljobseed WHERE jobpoolid = ?");
            $jobPoolStmt->execute([$customerInfo['jobpoolid']]);
            $jobPoolName = $jobPoolStmt->fetchColumn() ?: 'N/A'; // Set to 'N/A' if not found
        }

        // Translate custtype value
        $customerInfo['custtype'] = (is_array($customerInfo) && $customerInfo['custtype'] === 'emp') ? 'Employer' : ((is_array($customerInfo) && $customerInfo['custtype'] === 'pub') ? 'Publisher' : 'N/A');
    } catch (PDOException $e) {
        // Handle errors and print them out for debugging
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }
}


// Fetch all customer companies for dropdown
try {
    $custCompaniesStmt = $pdo->prepare("SELECT custid, custcompany FROM applcust WHERE acctnum = ?");
    $custCompaniesStmt->execute([$_SESSION['acctnum']]);
    $custCompanies = $custCompaniesStmt->fetchAll();
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}

// Handle custid from dropdown or session
$custid = filter_input(INPUT_GET, 'custid', FILTER_SANITIZE_NUMBER_INT); // Get custid from dropdown selection

if ($custid) {
    $_SESSION['custid'] = $custid; // Update session with new custid
} else {
    $custid = $_SESSION['custid'] ?? null; // Fallback to session custid or null
}


// Fetch feeds based on custid
try {
    $stmt = $pdo->prepare("SELECT feedid, feedname, budget, status, numjobs
                           FROM applcustfeeds
                           WHERE custid = ? ORDER BY feedname ASC");
    $stmt->execute([$custid]);
    $feeds = $stmt->fetchAll();

    foreach ($feeds as &$feed) {
        // Existing clickStmt
        $clickStmt = $pdo->prepare("SELECT COUNT(DISTINCT eventid) AS clicks, SUM(cpc) AS total_cpc
                                    FROM applevents
                                    WHERE custid = ? AND feedid = ? AND eventtype = 'cpc'
                                    AND timestamp BETWEEN ? AND ?");
        $clickStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
        $clickData = $clickStmt->fetch();

        // Existing appliesStmt
        $appliesStmt = $pdo->prepare("SELECT COUNT(*) AS applies, SUM(cpa) AS total_cpa
                                      FROM applevents
                                      WHERE custid = ? AND feedid = ? AND eventtype = 'cpa'
                                      AND timestamp BETWEEN ? AND ?");
        $appliesStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
        $appliesData = $appliesStmt->fetch();

        $feed['clicks'] = $clickData['clicks'] ?? 0;
        $feed['total_cpc'] = $clickData['total_cpc'] ?? 0;
        $feed['total_cpa'] = $appliesData['total_cpa'] ?? 0;
        $feed['applies'] = $appliesData['applies'] ?? 0;

        $total_spend = $feed['total_cpc'] + $feed['total_cpa'];
        $feed['formatted_spend'] = '$' . number_format((float)$total_spend, 2, '.', '');

        $feed['spend_per_click'] = $feed['clicks'] > 0 ? '$' . number_format($feed['total_cpc'] / $feed['clicks'], 2, '.', '') : '$0.00';
        // New calculation for spend_per_apply
        $feed['spend_per_apply'] = $feed['applies'] > 0 ? '$' . number_format($total_spend / $feed['applies'], 2, '.', '') : '$0.00';
        $feed['conversion_rate'] = $feed['clicks'] > 0 ? number_format(($feed['applies'] / $feed['clicks']) * 100, 2) . '%' : '0.00%';
    }
    unset($feed); // Unset reference to last element
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}

// Prepare to fetch daily spend data for each feed
$feedsData = [];
$colors = ['#FF5733', '#33C1FF', '#F033FF', '#33FF57', '#FFD733'];
$colorIndex = 0;
foreach ($feeds as $feed) {
    $color = $colors[$colorIndex % count($colors)];
    $colorIndex++;
    $dailySpendStmt = $pdo->prepare("SELECT DATE_FORMAT(timestamp, '%Y-%m-%d') AS Date, SUM(cpc + cpa) AS DailySpend
                                     FROM applevents
                                     WHERE custid = ? AND feedid = ? AND timestamp BETWEEN ? AND ?
                                     GROUP BY DATE(timestamp)
                                     ORDER BY DATE(timestamp)");
    $dailySpendStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
    $dailySpends = $dailySpendStmt->fetchAll();

    // Prepare data for chart
    $dates = [];
    $spends = [];
    foreach ($dailySpends as $spend) {
        $dates[] = $spend['Date'];
        $spends[] = ['x' => $spend['Date'], 'y' => $spend['DailySpend']];
    }

    $feedsData[] = [
        'label' => $feed['feedname'],
        'data' => $spends,
        'borderColor' => $color,
        'fill' => false
    ];
}

// Fetch active publishers for the current customer with multiple activepubs
$publishers = [];
if ($custid) {
    try {
        $publisherStmt = $pdo->prepare("
            SELECT DISTINCT p.publishername, p.publisherid
            FROM applcustfeeds f
            JOIN applpubs p ON FIND_IN_SET(p.publisherid, f.activepubs)
            WHERE f.custid = ? AND p.acctnum = ?
            ORDER BY p.publishername ASC
        ");
        $publisherStmt->execute([$custid, $_SESSION['acctnum']]);
        $publishers = $publisherStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        echo "Query error: " . $e->getMessage();
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Campaign Portal | Appljack</title>
    <?php include 'header.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

</head>

<body>
    <?php include 'appltopnav.php'; ?>

    <?php
    ob_start();
    ?>

    <form action="applportal.php" method="get" class="customer-info-dates ml-5" style="min-width: 28rem;">
        <div class="form-group mb-0 border-white">
            <label for="custid" class="no-wrap text-white">Switch Customer Account:</label>
            <select name="custid" id="custid" class="form-control" onchange="this.form.submit()">
                <?php foreach ($custCompanies as $company): ?>
                    <option value="<?= htmlspecialchars($company['custid']) ?>" <?= isset($_GET['custid']) && $_GET['custid'] == $company['custid'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($company['custcompany']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php
    $formContent = ob_get_clean();

    echo renderHeader("Campaign Portal", $formContent);
    ?>

    <section class="job_section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="modal fade" id="customerInfoModal" tabindex="-1" role="dialog" aria-labelledby="customerInfoModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="customerInfoModalLabel">Customer Information</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="col-md-12">

                                        <?php if (!empty($customerInfo)): ?>
                                            <p class="mb-1"><strong>Customer Name:</strong> <?= htmlspecialchars($customerInfo['custcompany']) ?></p>
                                            <p class="mb-1"><strong>Customer ID:</strong> <?= htmlspecialchars($custid) ?></p>
                                            <p class="mb-1"><strong>Customer Type:</strong> <?= htmlspecialchars($customerInfo['custtype']) ?></p>
                                            <p class="mb-1"><strong>Job Pool:</strong> <?= htmlspecialchars($jobPoolName) ?></p>
                                            <p class="mb-1"><strong>Customer-level Feed URL:</strong>
                                            <div class="bg-light border py-1 px-2 mb-1 rounded">
                                                <a href="<?= getUrl() ?>/applfeeds/<?= htmlspecialchars($custid); ?>.xml" target="_blank">
                                                    <?= getUrl() ?>/applfeeds/<?= htmlspecialchars($custid); ?>.xml
                                                </a>
                                            </div>
                                            </p>
                                            <!-- Publisher Feed URLs Section -->
                                            <?php if (!empty($publishers)): ?>
                                                <p class="mb-1"><strong>Publisher Feed URLs:</strong></p>
                                                <div class="bg-light border py-1 px-2 mb-1 rounded">
                                                    <?php foreach ($publishers as $publisher): ?>
                                                        <?= htmlspecialchars($publisher['publishername']); ?>:
                                                        <a href="<?= getUrl() ?>/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($publisher['publisherid']) ?>.xml" target="_blank">
                                                            <?= getUrl() ?>/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($publisher['publisherid']) ?>.xml
                                                        </a>
                                                        <br>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <p class="mb-0">No active publishers associated with this customer.</p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="mb-0">No customer information available.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="w-100 d-flex justify-content-between align-items-center feed-url rounded-md p-3 shadow-md">
                        <div>
                            <p class="healthy-text mb-0">
                                Customer name: <a href="#">
                                    <?= htmlspecialchars(ucwords($customerInfo['custcompany'])); ?>
                                </a>
                            </p>
                        </div>
                        <button class="btn_green_dark px-4 rounded-md" data-toggle="modal" data-target="#customerInfoModal">
                            Customer information
                        </button>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="rounded-md shadow-md p-3 customer-filter-bar bg-white feed-url h-100">
                        <div class="row w-100 mx-auto">
                            <div class="col-md-9">
                                <form action="applportal.php" id="applPortalFilter" class="row w-100 mx-auto">
                                    <div class="col-md-6 px-0 customer-info-dates">
                                        <div class="form-group mb-0">
                                            <label for="startdate">Start</label>
                                            <input type="date" id="startdate" name="startdate" class="form-control" value="<?= htmlspecialchars(substr($startdate, 0, 10)) ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6 pr-0 customer-info-dates">
                                        <div class="form-group mb-0">
                                            <label for="enddate">End</label>
                                            <input type="date" id="enddate" name="enddate" class="form-control" value="<?= htmlspecialchars(substr($enddate, 0, 10)) ?>" required>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-md-3 px-0">
                                <div class="d-flex justify-content-between gap-3">
                                    <div class="w-100">
                                        <button class="btn_green_dark w-100 rounded-md" onclick="applPortalFilter.submit()">
                                            <img src="./images/ico/file.png" alt="" class="item-ico pr-1">
                                            Show data</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card mb-4 shadow-md cust-campaign rounded-md">
                <div class="card-header d-flex justify-content-between align-items-center p-0">
                    <h2 class="p-3 fs-md mb-0 fw-bold text-white">Your Campaigns Dashboard: <?= $displayStartDate ?> to <?= $displayEndDate ?></h2>
                    <div class="d-flex">
                        <a href="appldownloadcsv.php?custid=<?= htmlspecialchars($custid) ?>&startdate=<?= htmlspecialchars($startdate) ?>&enddate=<?= htmlspecialchars($enddate) ?>" class="btn_green_dark text-white w-100 rounded-md">
                            <img src="./images/ico/download.png" alt="" class="item-ico pr-1">
                            Download CSV
                        </a>
                        <a href="applcreatefeeds.php?custid=<?= htmlspecialchars($custid) ?>" class="text-white btn_green">
                            <i class="fa fa-plus"></i> Add Campaign
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="width: 100%; height: 250px;">
                        <canvas id="spendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="table-container shadow-md cust-portal">
                <table class="table table-striped table-hover mb-0">
                    <thead class="">
                        <tr>
                            <th>Campaign</th>
                            <th>Campaign ID</th>
                            <th>Status</th>
                            <th>Mo. Budget</th>
                            <th>Spend</th>
                            <th>Clicks</th>
                            <th>Applies</th>
                            <th>Spend/Apply</th>
                            <th>Spend/Click</th>
                            <th>Conv. Rate</th>
                            <th>Job Exported</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($feeds as $feed): ?>
                            <tr>
                                <td><?= htmlspecialchars($feed['feedname']); ?></td>
                                <td><?= htmlspecialchars($feed['feedid']); ?></td>
                                <td><?= htmlspecialchars($feed['status']); ?></td>
                                <td>$<?= htmlspecialchars($feed['budget']); ?></td>
                                <td><?= htmlspecialchars($feed['formatted_spend']); ?></td>
                                <td><?= $feed['clicks']; ?></td>
                                <td><?= $feed['applies']; ?></td>
                                <td><?= $feed['spend_per_apply']; ?></td>
                                <td><?= $feed['spend_per_click']; ?></td>
                                <td><?= $feed['conversion_rate']; ?></td>
                                <td><?= number_format($feed['numjobs'] ?? 0); ?></td>
                                <td>
                                    <a href="viewfeed.php?feedid=<?= urlencode($feed['feedid']); ?>" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="editfeed.php?feedid=<?= urlencode($feed['feedid']); ?>" class="btn btn-success btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="deletefeed.php?feedid=<?= urlencode($feed['feedid']); ?>" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Are you sure you want to delete this feed?');" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php include 'footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('spendChart').getContext('2d');
            var spendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    datasets: <?= json_encode($feedsData); ?>
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Daily Spend ($)'
                            }
                        },
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day',
                                tooltipFormat: 'MMM dd, yyyy',
                                displayFormats: {
                                    day: 'MMM dd'
                                }
                            },
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        });
    </script>

</body>

</html>