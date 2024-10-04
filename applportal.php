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

    <?php echo renderHeader(
        "Campaign Portal"
    ); ?>
    <section class="job_section">
        <div class="container-fluid">
            <div class="row">
                <!-- Customer Information Card -->
                <div class="col-md-4">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h2 class="fs-md mb-0 fw-bold">Customer Information</h2>
                        </div>
                        <div class="card-body">
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
                </div>

                <!-- Date Selector Card -->
                <div class="col-md-4">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h2 class="fs-md mb-0 fw-bold">Select Dates</h2>
                        </div>
                        <div class="card-body">
                            <form action="applportal.php" method="get">
                                <input type="hidden" name="custid" value="<?= htmlspecialchars($custid) ?>">
                                <div class="form-group mb-3">
                                    <label for="startdate">Start Date:</label>
                                    <input type="date" id="startdate" name="startdate" class="form-control" value="<?= htmlspecialchars(substr($startdate, 0, 10)) ?>" required>
                                </div>
                                <div class="form-group mb-3">
                                    <label for="enddate">End Date:</label>
                                    <input type="date" id="enddate" name="enddate" class="form-control" value="<?= htmlspecialchars(substr($enddate, 0, 10)) ?>" required>
                                </div>
                                <button class="btn_green w-100">Show Data</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Customer Switch Card -->
                <div class="col-md-4">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h2 class="fs-md mb-0 fw-bold">Switch Customer</h2>
                        </div>
                        <div class="card-body">
                            <form action="applportal.php" method="get" class="d-flex flex-column align-items-start">
                                <label for="custid">Switch Customer Account:</label>
                                <select name="custid" id="custid" class="form-control mb-2" onchange="this.form.submit()">
                                    <?php foreach ($custCompanies as $company): ?>
                                        <option value="<?= htmlspecialchars($company['custid']) ?>" <?= isset($_GET['custid']) && $_GET['custid'] == $company['custid'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($company['custcompany']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="fs-md mb-0 fw-bold">Your Campaigns Dashboard: <?= $displayStartDate ?> to <?= $displayEndDate ?></h2>
                    <div>
                        <a href="applcreatefeeds.php?custid=<?= htmlspecialchars($custid) ?>" class="text-white btn_green">
                            <i class="fa fa-plus"></i> Add Campaign
                        </a>
                        <a href="appldownloadcsv.php?custid=<?= htmlspecialchars($custid) ?>&startdate=<?= htmlspecialchars($startdate) ?>&enddate=<?= htmlspecialchars($enddate) ?>" class="ml-2 btn_green text-white _downloadcsv-button">
                            <i class="fa fa-download"></i> Download CSV
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="width: 100%; height: 250px;">
                        <canvas id="spendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
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
                    <tbody>
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
                                <td><?= number_format($feed['numjobs']); ?></td>
                                <td>
                                    <a href="viewfeed.php?feedid=<?= urlencode($feed['feedid']); ?>">View</a> |
                                    <a href="editfeed.php?feedid=<?= urlencode($feed['feedid']); ?>">Edit</a> |
                                    <a href="deletefeed.php?feedid=<?= urlencode($feed['feedid']); ?>" onclick="return confirm('Are you sure you want to delete this feed?');">Delete</a>
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