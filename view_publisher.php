<?php
include 'database/db.php';
require 'PublisherController.php';

// 1. Validate session
if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}



// 2. Handle date filters (start/end) from GET or defaults
$defaultStartDate = date('Y-m-01');
$defaultEndDate   = date('Y-m-t'); // Last day of the current month

$startdate = isset($_GET['startdate']) ? $_GET['startdate'] : $defaultStartDate;
$enddate   = isset($_GET['enddate'])   ? $_GET['enddate']   : $defaultEndDate;

// Convert them to full DateTime for queries
$startdate = date('Y-m-d', strtotime($startdate)) . " 00:00:00";
$enddate   = date('Y-m-d', strtotime($enddate))   . " 23:59:59";

// For display in the header
$displayStartDate = date('F j, Y', strtotime($startdate));
$displayEndDate   = date('F j, Y', strtotime($enddate));

// 3. Fetch all publishers for dropdown
try {
    $allPublishersStmt = $pdo->prepare("SELECT * FROM applpubs WHERE acctnum = :acctnum");
    $allPublishersStmt->bindParam(':acctnum', $_SESSION['acctnum'], PDO::PARAM_INT);
    $allPublishersStmt->execute();
    $allPublishers = $allPublishersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}

// 4. Determine selected publisherid
if (!isset($_GET['publisherid'])) {
    die('Publisher ID not provided.');
}


// 5. Fetch publisher record
$publisher = getPublisherById($_GET['publisherid'], $pdo); // Using your existing function




try {
    $stmt = $pdo->prepare("
        SELECT 
            f.feedid,
            f.feedname,
            f.budget,
            f.status,
            f.numjobs,
            f.custid,          
            f.activepubs,          
            c.custcompany,
            c.custtype,
            p.acctnum
        FROM applcustfeeds f
        JOIN applpubs p ON p.publisherid = f.activepubs
        LEFT JOIN applcust c ON c.custid = f.custid
        WHERE f.activepubs = :publisherid
        ORDER BY f.feedname ASC
    ");
    $stmt->execute(['publisherid' => $_GET['publisherid']]);
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}

foreach ($feeds as &$feed) {
    // Prepare queries for each feed
    // GET total clicks + sum of CPC
    $clickStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT eventid) AS clicks, 
            SUM(cpc) AS total_cpc
        FROM applevents
        WHERE publisherid    = :activepubs
          AND feedid    = :feedid
          AND eventtype = 'cpc'
          AND timestamp BETWEEN :startdate AND :enddate
    ");
    $clickStmt->execute([
        'activepubs' => $feed['activepubs'],
        'feedid' => $feed['feedid'],
        'startdate' => $startdate,
        'enddate'   => $enddate
    ]);
    $clickData = $clickStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // GET total applies + sum of CPA
    $appliesStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS applies, 
            SUM(cpa) AS total_cpa
        FROM applevents
        WHERE custid    = :custid
          AND feedid    = :feedid
          AND eventtype = 'cpa'
          AND timestamp BETWEEN :startdate AND :enddate
    ");
    $appliesStmt->execute([
        'custid' => $feed['custid'],
        'feedid' => $feed['feedid'],
        'startdate' => $startdate,
        'enddate'   => $enddate
    ]);
    $appliesData = $appliesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Store in the $feed array
    $feed['clicks']      = $clickData['clicks']         ?? 0;
    $feed['total_cpc']   = $clickData['total_cpc']      ?? 0;
    $feed['applies']     = $appliesData['applies']      ?? 0;
    $feed['total_cpa']   = $appliesData['total_cpa']    ?? 0;
    $total_spend         = $feed['total_cpc'] + $feed['total_cpa'];

    $feed['formatted_spend'] = '$' . number_format($total_spend, 2, '.', '');
    $feed['spend_per_click'] = $feed['clicks'] > 0 
        ? '$' . number_format($feed['total_cpc'] / $feed['clicks'], 2, '.', '') 
        : '$0.00';

    $feed['spend_per_apply'] = $feed['applies'] > 0
        ? '$' . number_format($total_spend / $feed['applies'], 2, '.', '')
        : '$0.00';

    $feed['conversion_rate'] = $feed['clicks'] > 0
        ? number_format(($feed['applies'] / $feed['clicks']) * 100, 2) . '%'
        : '0.00%';
}
unset($feed); // break the reference

// 8. Prepare daily spend data for Chart.js
$feedsData = [];
$colors    = ['#FF5733', '#33C1FF', '#F033FF', '#33FF57', '#FFD733'];
$colorIndex = 0;

foreach ($feeds as $feed) {
    $color = $colors[$colorIndex % count($colors)];
    $colorIndex++;

    $dailySpendStmt = $pdo->prepare("
    SELECT 
    DATE_FORMAT(timestamp, '%Y-%m-%d') AS Date, 
    SUM(cpc + cpa) AS DailySpend
FROM applevents
WHERE custid    = :custid
  AND feedid    = :feedid
  AND timestamp BETWEEN :startdate AND :enddate
GROUP BY DATE_FORMAT(timestamp, '%Y-%m-%d')
ORDER BY DATE_FORMAT(timestamp, '%Y-%m-%d')
    ");
    $dailySpendStmt->execute([
        'custid'    => $feed['custid'],
        'feedid'    => $feed['feedid'],
        'startdate' => $startdate,
        'enddate'   => $enddate
    ]);
    $dailySpends = $dailySpendStmt->fetchAll(PDO::FETCH_ASSOC);

    $spendsArray = [];
    foreach ($dailySpends as $row) {
        $spendsArray[] = [
            'x' => $row['Date'],
            'y' => (float) $row['DailySpend']
        ];
    }

    $feedsData[] = [
        'label'       => $feed['feedname'],
        'data'        => $spendsArray,
        'borderColor' => $color,
        'fill'        => false
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View Publisher</title>
    <?php include 'header.php'; ?>
</head>

<body>
<?php include 'appltopnav.php'; ?>
      <?php
    ob_start();
    ?>

    <form action="view_publisher.php" method="GET" class="customer-info-dates ml-5" style="min-width: 28rem;">
        <div class="form-group mb-0 border-white">
            <label for="publisherid" class="no-wrap text-white">Switch Publisher Account:</label>
            <select name="publisherid" id="publisherid" class="form-control" onchange="this.form.submit()">
                <?php foreach ($allPublishers as $publisherdata): ?>
                    <option value="<?= htmlspecialchars($publisherdata['publisherid']) ?>" <?= isset($_GET['publisherid']) && $_GET['publisherid'] == $publisherdata['publisherid'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($publisherdata['publishername']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php
    $formContent = ob_get_clean();

    echo renderHeader("Publisher Portal", $formContent);
    ?>

<section class="job_section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="modal fade" id="customerInfoModal" tabindex="-1" role="dialog" aria-labelledby="customerInfoModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="customerInfoModalLabel">Publisher Information</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="col-md-12">

                                        <?php if (!empty($publisher)): ?>
                                            <div class="mb-4">
                        
                                <p><strong>Name:</strong> <?= htmlspecialchars($publisher['publishername']) ?></p>
                                <p><strong>ID:</strong> <?= htmlspecialchars($publisher['publisherid']) ?></p>
                                <p><strong>Contact Name:</strong> <?= htmlspecialchars($publisher['publisher_contact_name'] ?? 'N/A') ?></p>
                                <p><strong>Contact Email:</strong> <?= htmlspecialchars($publisher['publisher_contact_email'] ?? 'N/A') ?></p>
                                <p class="mb-1"><strong>Customer-level Feed URL:</strong>
                                            <div class="bg-light border py-1 px-2 mb-1 rounded">
                                                <a href="<?= getUrl() . $envSuffix ?>/applfeeds/<?= $_SESSION['acctnum'] . '-' . $feed['feedid']; ?>.xml" target="_blank">
                                                    <?= getUrl() . $envSuffix ?>/applfeeds/<?= $_SESSION['acctnum'] . '-' . $feed['feedid']; ?>.xml
                                                </a>
                                            </div>
                                            </p>
                                            <div class="text-center">
                                <a href="publisherspool.php" class="btn btn-secondary">Back to List</a>
                                <a href="edit_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-success">Edit Publisher</a>
                                <a href="delete_publisher.php?id=<?= htmlspecialchars($publisher['publisherid']) ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this publisher?');">Delete Publisher</a>
                            </div>
                            </div>
                                      
                                        <?php else: ?>
                                            <p class="mb-0">No Publisher information available.</p>
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
                            Publisher Name: <a href="#">
                                    <?= htmlspecialchars(ucwords($publisher['publishername'])); ?>
                                </a>
                            </p>
                        </div>
                        <button class="btn_green_dark px-4 rounded-md" data-toggle="modal" data-target="#customerInfoModal">
                            Publisher information
                        </button>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="rounded-md shadow-md p-3 customer-filter-bar bg-white feed-url h-100">
                        <div class="row w-100 mx-auto">
                            <div class="col-md-9">
                                <form action="view_publisher.php" id="applPortalFilter" class="row w-100 mx-auto" method="get">
                                    <input type="hidden" name="publisherid" value="<?= htmlspecialchars($_GET['publisherid']) ?>">
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
                        <a href="appldownloadpubcsv.php?publisherid=<?= htmlspecialchars($_GET['publisherid']) ?>&startdate=<?= htmlspecialchars($startdate) ?>&enddate=<?= htmlspecialchars($enddate) ?>" class="btn_green_dark text-white w-100 rounded-md">
                            <img src="./images/ico/download.png" alt="" class="item-ico pr-1">
                            Download CSV
                        </a>
                        <a href="applcreatepub.php" class="text-white btn_green">
                            <i class="fa fa-plus"></i> Add Publisher
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
                            <th>Publisher Spend</th>
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
                                    <a href="deletepub.php?publisherid=<?= urlencode($_GET['publisherid']); ?>&feedid=<?= urlencode($feed['feedid']); ?>" class="btn btn-danger btn-sm"
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
