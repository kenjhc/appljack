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

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Handle custid from dropdown or session
$custid = filter_input(INPUT_GET, 'custid', FILTER_SANITIZE_NUMBER_INT);
if ($custid) {
    $_SESSION['custid'] = $custid; // Update session with new custid
} else {
    $custid = $_SESSION['custid'] ?? null; // Fallback to session custid or null
}

// Fetch customer information
$customerInfo = [];
$jobPoolName = 'N/A';

if ($custid) {
    try {
        $custInfoStmt = $pdo->prepare("SELECT custcompany, custtype, jobpoolid FROM applcust WHERE custid = ?");
        $custInfoStmt->execute([$custid]);
        $customerInfo = $custInfoStmt->fetch();

        if ($customerInfo && !empty($customerInfo['jobpoolid'])) {
            $jobPoolStmt = $pdo->prepare("SELECT jobpoolname FROM appljobseed WHERE jobpoolid = ?");
            $jobPoolStmt->execute([$customerInfo['jobpoolid']]);
            $jobPoolName = $jobPoolStmt->fetchColumn() ?: 'N/A';
        }

        $customerInfo['custtype'] = ($customerInfo['custtype'] === 'emp') ? 'Employer' : (($customerInfo['custtype'] === 'pub') ? 'Publisher' : 'N/A');
    } catch (PDOException $e) {
        setToastMessage('error', "Database error: " . $e->getMessage());
        header("Location: applmasterview.php");
        exit;
    }
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

// Fetch feeds based on custid
try {
    $stmt = $pdo->prepare("SELECT feedid, feedname, budget, status, numjobs
                           FROM applcustfeeds
                           WHERE custid = ? ORDER BY feedname ASC");
    $stmt->execute([$custid]);
    $feeds = $stmt->fetchAll();

    foreach ($feeds as &$feed) {
        $clickStmt = $pdo->prepare("SELECT COUNT(DISTINCT eventid) AS clicks, SUM(cpc) AS total_cpc
                                    FROM applevents
                                    WHERE custid = ? AND feedid = ? AND eventtype = 'cpc'
                                    AND timestamp BETWEEN ? AND ?");
        $clickStmt->execute([$custid, $feed['feedid'], $startdate, $enddate]);
        $clickData = $clickStmt->fetch();

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
        $feed['spend_per_apply'] = $feed['applies'] > 0 ? '$' . number_format($total_spend / $feed['applies'], 2, '.', '') : '$0.00';
        $feed['conversion_rate'] = $feed['clicks'] > 0 ? number_format(($feed['applies'] / $feed['clicks']) * 100, 2) . '%' : '0.00%';
    }
    unset($feed);
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

    <div class="main-content">
        <div class="top-row">
            <div class="details-container">
                <h3>Customer Information</h3>
                <?php if (!empty($customerInfo)): ?>
                    <p>Customer Name: <?= htmlspecialchars($customerInfo['custcompany']) ?></p>
                    <p>Customer ID: <?= htmlspecialchars($custid) ?></p>
                    <p>Customer Type: <?= htmlspecialchars($customerInfo['custtype']) ?></p>
                    <p>Job Pool: <?= htmlspecialchars($jobPoolName) ?></p>
                    <p>Customer-level Feed URL: <a href="https://appljack.com/applfeeds/<?= htmlspecialchars($custid); ?>.xml" target="_blank">https://appljack.com/applfeeds/<?= htmlspecialchars($custid); ?>.xml</a></p>

                    <!-- New Section for Publisher Feed URLs -->
                    <?php if (!empty($publishers)): ?>
                        <p><b>Publisher Feed URLs:</b></p>
                        <?php foreach ($publishers as $publisher): ?>
                            <?= htmlspecialchars($publisher['publishername']); ?>:
                            <a href="https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($publisher['publisherid']) ?>.xml" target="_blank">
                                https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($publisher['publisherid']) ?>.xml
                            </a>
                            <br/>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No active publishers associated with this customer.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No customer information available.</p>
                <?php endif; ?>
            </div>

            <div class="date-selector">
                <form action="applportal.php" method="get">
                    <input type="hidden" name="custid" value="<?= htmlspecialchars($custid) ?>">
                    <label for="startdate">Start:</label>
                    <input type="date" id="startdate" name="startdate" value="<?= htmlspecialchars(substr($startdate, 0, 10)) ?>" required>
                    <label for="enddate">End:</label>
                    <input type="date" id="enddate" name="enddate" value="<?= htmlspecialchars(substr($enddate, 0, 10)) ?>" required>
                    <button type="submit">Show Data</button>
                </form>
            </div>

            <div class="switch-container">
                <form action="applportal.php" method="get">
                    <label for="custid">Switch Customer Account:</label>
                    <select name="custid" id="custid" onchange="this.form.submit()">
                        <?php foreach ($custCompanies as $company): ?>
                            <option value="<?= htmlspecialchars($company['custid']) ?>" <?= isset($_GET['custid']) && $_GET['custid'] == $company['custid'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($company['custcompany']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

        </div>

        <div class="dashboard-header">
            <h1>Your Campaigns Dashboard: <?= $displayStartDate ?> to <?= $displayEndDate ?></h1>
            <a href="applcreatefeeds.php?custid=<?= htmlspecialchars($custid) ?>" class="add-campaign-button">
                <i class="fa fa-plus"></i> Add Campaign
            </a>
            <a href="appldownloadcsv.php?custid=<?= htmlspecialchars($custid) ?>&startdate=<?= htmlspecialchars($startdate) ?>&enddate=<?= htmlspecialchars($enddate) ?>" class="downloadcsv-button">
                <i class="fa fa-download"></i> Download CSV
            </a>
        </div>

        <div class="chart-container" style="width: 100%; height: 250px;">
            <canvas id="spendChart"></canvas>
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
