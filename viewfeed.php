<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Set default dates to the current month's first day and today
$defaultStartDate = date('Y-m-01'); // First day of the current month
$defaultEndDate = date('Y-m-d'); // Today's date

// Check if the user has provided dates, otherwise use the default dates
$startdate = isset($_GET['startdate']) ? $_GET['startdate'] : $defaultStartDate;
$enddate = isset($_GET['enddate']) ? $_GET['enddate'] : $defaultEndDate;

$displayStartDate = date('F j, Y', strtotime($startdate));
$displayEndDate = date('F j, Y', strtotime($enddate));

// Check if feedid is present in the query string
// if (!isset($_GET['feedid'])) {
//     setToastMessage('error', "Feed ID not provided.");
//     header("Location: applmasterview.php");
//     exit;
// }

$feedid = $_GET['feedid'];

try {
    $stmt = $pdo->prepare("SELECT * FROM applcustfeeds WHERE feedid = ? AND custid = ?");
    $stmt->execute([$feedid, $_SESSION['custid']]);
    $feed = $stmt->fetch();

    if (!$feed) {
        setToastMessage('error', "No feed found with the provided ID.");
        header("Location: applmasterview.php");
        exit;
    }

    // Construct Feed URL
    $feedUrl = "https://" . $envClean . "appljack.com/applfeeds/" . urlencode($_SESSION['custid']) . "-" . urlencode($feed['feedid']) . ".xml";
} catch (PDOException $e) {
    setToastMessage('error', "Database error: " . $e->getMessage());
    header("Location: applmasterview.php");
    exit;
}

// HTML to display the feed details
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Campaign | Appljack</title>
    <!-- Link to CSS files and other head elements -->
    <?php include 'header.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
</head>

<body>
    <?php include 'appltopnav.php'; ?>

    <?php echo renderHeader(
        "Feeds",
        "<a href='viewfeed.php?feedid=" . urlencode($feedid) . "'>
            <p class='mb-0 fs-6 text-white'>< Back to your portal</p>
        </a>"
    ); ?>

    <section class="job_section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="modal fade" id="CampaignInfoModal" tabindex="-1" role="dialog" aria-labelledby="CampaignInfoModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="CampaignInfoModalLabel">Campaign Information</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="col-md-12">
                                        <h2 class="fs-md fw-bold">Campaign: <?php echo htmlspecialchars($feed['feedname']); ?></h2>
                                        <p class="mb-0"><b>Campaign ID:</b> <?php echo htmlspecialchars($feedid); ?></p>
                                        <p class="mb-0"><b>Status:</b> <?php echo htmlspecialchars($feed['status']); ?></p>
                                        <?php
                                        // Construct the local file path from the feed URL
                                        // Assuming the feed URL structure and local file path are directly related
                                        $localFilePath = '/chroot/home/appljack/appljack.com/html' . $envSuffix . '/applfeeds/' . urlencode($_SESSION['custid']) . "-" . urlencode($feed['feedid']) . ".xml";

                                        // Check if the XML file exists
                                        $fileExistsMessage = file_exists($localFilePath) ? "(feed is ready!)" : "(feed under construction)";
                                        ?>
                                        <p class="mb-0"><strong>Campaign URL:</strong> <a href="<?php echo $feedUrl; ?>" target="_blank"><?php echo $feedUrl; ?></a> <?php echo $fileExistsMessage; ?> </p>
                                        <p class="mb-0"><strong>Jobs Last Exported:</strong> <?php echo htmlspecialchars(number_format($feed['numjobs'])); ?></p>
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
                                Campaign: <a href="#">
                                    <?php echo htmlspecialchars($feed['feedname']); ?>
                                </a>
                            </p>
                            <p class="healthy-text mb-0">
                                Campaign ID: <a href="#">
                                    <?php echo htmlspecialchars($feedid); ?>
                                </a>
                            </p>
                        </div>
                        <button class="btn_green_dark px-4 rounded-md" data-toggle="modal" data-target="#CampaignInfoModal">
                            Campaign information
                        </button>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="rounded-md shadow-md p-3 customer-filter-bar bg-white feed-url h-100 d-flex">
                        <div class="row w-100 m-auto">
                            <div class="col-md-9">
                                <form action="viewfeed.php" id="applPortalFilter" class="row w-100 mx-auto">
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
                <div class="col-md-12 mb-4">
                    <div class="w-100 d-flex justify-content-between align-items-center feed-url rounded-md p-3 shadow-md">
                        <div class="row w-100 mx-auto">
                            <div class="col-md-3 px-0">
                                <div class="details-container w-100 my-0">
                                    <p class="mb-0"><span class="camp-data">Campaign Name:</span> <?php echo htmlspecialchars($feed['feedname']); ?></p>
                                    <p class="mb-0"><span class="camp-data">Monthly Budget:</span> $<?php echo htmlspecialchars($feed['budget']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3 pr-0">
                                <div class="details-container w-100 my-0">
                                    <p class="mb-0"><span class="camp-data">CPC Amount:</span> $<?php echo htmlspecialchars($feed['cpc']); ?></p>
                                    <p class="mb-0"><span class="camp-data">Keywords:</span> <?php echo htmlspecialchars($feed['custquerykws']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3 pr-0">
                                <div class="details-container w-100 my-0">
                                    <p class="mb-0"><span class="camp-data">Industries:</span> <?php echo htmlspecialchars($feed['custqueryindustry']); ?></p>
                                    <p class="mb-0"><span class="camp-data">Cities:</span> <?php echo htmlspecialchars($feed['custquerycity']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="details-container w-100 my-0">
                                    <p class="mb-0"><span class="camp-data">States:</span> <?php echo htmlspecialchars($feed['custquerystate']); ?></p>
                                    <p class="mb-0"><span class="camp-data">Companies:</span> <?php echo htmlspecialchars($feed['custqueryco']); ?></p>
                                </div>
                            </div>
                        </div>
                        <button class="btn_green_dark px-4 rounded-md" onclick="window.location.href='editfeed.php?feedid=<?php echo $feedid; ?>'">
                            Edit Campaign
                        </button>
                    </div>
                </div>
            </div>
            <div class="card mb-4 shadow-md cust-campaign rounded-md">
                <div class="card-header d-flex justify-content-between align-items-center p-0">
                    <h2 class="p-3 fs-md mb-0 fw-bold text-white">Your Campaign: <?= $displayStartDate ?> to <?= $displayEndDate ?></h2>
                    <!-- <div class="d-flex">
                        <div class="tab">
                         
                        </div>
                    </div> -->

                    <div class="d-flex">
                        <!-- <a href="appldownloadcsv.php?custid=3226954507&amp;startdate=2024-10-01 00:00:00&amp;enddate=2024-10-31 23:59:59" class="btn_green_dark text-white w-100 rounded-md">
                            <img src="./images/ico/download.png" alt="" class="item-ico pr-1">
                            Download CSV
                        </a> -->
                        <button class="tablinks btn_green active" onclick="openTab(event, 'Active')">Active</button>
                        <button class="tablinks btn_green" onclick="openTab(event, 'Inactive')">Inactive</button>
                        <button class="tablinks btn_green" onclick="openTab(event, 'Referrers')">Referrers</button>
                        <!-- <a href="applcreatefeeds.php?custid=3226954507" class="text-white btn_green">
                            <i class="fa fa-plus"></i> Add Campaign
                        </a> -->
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" id="chartContainer" style="width: 100%; height: 250px;">
                        <canvas id="spendChart"></canvas>
                    </div>
                    <div class="table-container shadow-md cust-portal">
                        <div id="Active" class="tabcontent" style="display:block;">
                            <?php
                            // Set default dates to the current month's first day and today
                            $defaultStartDate = date('Y-m-01'); // First day of the current month
                            $defaultEndDate = date('Y-m-d'); // Today's date

                            // Check if the user has provided dates, otherwise use the default dates
                            $startdate = isset($_GET['startdate']) ? $_GET['startdate'] : $defaultStartDate;
                            $enddate = isset($_GET['enddate']) ? $_GET['enddate'] : $defaultEndDate;

                            // Prepare the SQL query using placeholders for the parameters
                            $query = "SELECT DATE_FORMAT(timestamp, '%m/%d/%Y') AS Date, SUM(CPC) AS CPC, SUM(CPA) AS CPA, COUNT(DISTINCT CASE WHEN eventtype = 'cpc' THEN eventid END) AS Clicks, COUNT(DISTINCT CASE WHEN eventtype = 'cpa' THEN eventid END) AS Applies FROM applevents WHERE feedid = ? AND timestamp BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(timestamp) ORDER BY timestamp";

                            // Execute the query with the start and end dates
                            $stmt = $pdo->prepare($query);
                            $stmt->execute([$feedid, $startdate, $enddate . ' 23:59:59']);
                            if (!$stmt) {
                                echo "\nPDO::errorInfo():\n";
                                print_r($pdo->errorInfo());
                            }
                            $results = $stmt->fetchAll();

                            // Prepare arrays for the chart
                            $dates = [];
                            $totalSpends = [];
                            $clicksArray = [];
                            $appliesArray = [];

                            foreach ($results as $row) {
                                $dates[] = $row['Date'];
                                $totalSpend = $row['CPC'] + $row['CPA'];
                                $totalSpends[] = $totalSpend;
                                $clicksArray[] = $row['Clicks'];
                                $appliesArray[] = $row['Applies'];
                            }

                            // Display the results in a table
                            echo "<div class='table-container'><table>";
                            echo "<tr>
                                <th>Date</th>
                                <th>Total Spend</th>
                                <th>Clicks</th>
                                <th>Applies</th>
                                <th>Spend/Click</th>
                                <th>Spend/Apply</th>
                                <th>Conv. Rate</th>
                            </tr>";
                            foreach ($results as $row) {
                                $clicks = $row['Clicks'];
                                $applies = $row['Applies'];
                                $cpcSpend = $row['CPC'];
                                $cpaSpend = $row['CPA'];
                                $totalSpend = $cpcSpend + $cpaSpend;

                                $spendPerClick = $clicks > 0 ? $cpcSpend / $clicks : 0;
                                // New calculation for Spend/Apply
                                $spendPerApply = $applies > 0 ? $totalSpend / $applies : 0;
                                $conversionRate = $clicks > 0 ? ($applies / $clicks) * 100 : 0;

                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['Date']) . "</td>";
                                echo "<td>$" . number_format($totalSpend, 2) . "</td>";
                                echo "<td>" . htmlspecialchars($clicks) . "</td>";
                                echo "<td>" . htmlspecialchars($applies) . "</td>";
                                echo "<td>$" . number_format($spendPerClick, 2) . "</td>";
                                echo "<td>$" . number_format($spendPerApply, 2) . "</td>";
                                echo "<td>" . number_format($conversionRate, 2) . "%</td>";
                                echo "</tr>";
                            }
                            echo "</table></div>";
                            ?>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    var ctx = document.getElementById('spendChart').getContext('2d');
                                    var dates = <?php echo json_encode($dates); ?>;
                                    var totalSpends = <?php echo json_encode($totalSpends); ?>;
                                    var clicks = <?php echo json_encode($clicksArray); ?>;
                                    var applies = <?php echo json_encode($appliesArray); ?>;

                                    console.log(dates, totalSpends, clicks, applies); // Debugging line

                                    var spendChart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: dates,
                                            datasets: [{
                                                label: 'Total Spend',
                                                data: totalSpends,
                                                borderColor: 'rgb(75, 192, 192)',
                                                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                                tension: 0.1
                                            }]
                                        },
                                        options: {
                                            scales: {
                                                y: {
                                                    beginAtZero: true
                                                },
                                                x: {
                                                    type: 'time',
                                                    time: {
                                                        unit: 'day',
                                                        tooltipFormat: 'MM/dd/yyyy',
                                                        parser: 'MM/dd/yyyy'
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Date'
                                                    }
                                                },
                                            },
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: {
                                                legend: {
                                                    labels: {
                                                        generateLabels: function(chart) {
                                                            var data = chart.data;
                                                            return [{
                                                                text: 'Total Spend',
                                                                fillStyle: 'rgb(75, 192, 192)',
                                                                hidden: false,
                                                                index: 0,
                                                                onclick: function(e) {
                                                                    updateChart('TotalSpend', 'rgb(75, 192, 192)');
                                                                }
                                                            }, {
                                                                text: 'Clicks',
                                                                fillStyle: 'rgb(54, 162, 235)',
                                                                hidden: false,
                                                                index: 1,
                                                                onclick: function(e) {
                                                                    updateChart('Clicks', 'rgb(54, 162, 235)');
                                                                }
                                                            }, {
                                                                text: 'Applies',
                                                                fillStyle: 'rgb(255, 99, 132)',
                                                                hidden: false,
                                                                index: 2,
                                                                onclick: function(e) {
                                                                    updateChart('Applies', 'rgb(255, 99, 132)');
                                                                }
                                                            }];
                                                        }
                                                    },
                                                    onClick: function(e, legendItem, legend) {
                                                        var index = legendItem.index;
                                                        legendItem.onclick(e);
                                                    }
                                                }
                                            }
                                        }
                                    });

                                    window.updateChart = function(metric, color) {
                                        var metricData;
                                        var label;

                                        switch (metric) {
                                            case 'Clicks':
                                                metricData = clicks;
                                                label = 'Clicks';
                                                break;
                                            case 'Applies':
                                                metricData = applies;
                                                label = 'Applies';
                                                break;
                                            case 'TotalSpend':
                                            default:
                                                metricData = totalSpends;
                                                label = 'Total Spend';
                                                break;
                                        }

                                        if (metricData === undefined || metricData.length === 0) {
                                            console.error("Metric data is undefined or empty", metric, metricData);
                                            return;
                                        }

                                        spendChart.data.datasets[0].data = metricData;
                                        spendChart.data.datasets[0].label = label;
                                        spendChart.data.datasets[0].borderColor = color;
                                        spendChart.data.datasets[0].backgroundColor = color.replace('rgb', 'rgba').replace(')', ', 0.2)');
                                        spendChart.update();
                                    };
                                });
                            </script>
                        </div>
                        <div id="Inactive" class="tabcontent">
                            <?php

                            // Prepare the SQL query using placeholders for the parameters
                            $query = "SELECT DATE_FORMAT(timestamp, '%m/%d/%Y') AS Date, SUM(CPC + CPA) AS Spend, COUNT(DISTINCT eventid) AS Clicks, SUM(CPC) AS CPC, SUM(CPA) AS CPA FROM appleventsinac WHERE feedid = ? AND DATE(timestamp) BETWEEN ? AND ? GROUP BY DATE(timestamp) ORDER BY timestamp DESC";

                            // Execute the query with the start and end dates
                            $stmt = $pdo->prepare($query);
                            $stmt->execute([$feedid, $startdate, $enddate]);
                            $results = $stmt->fetchAll();

                            // Display the results in a table
                            echo "<table>";
                            echo "<tr>
        <th>Date</th>
        <th>Total Spend</th>
        <th>Clicks</th>
        <th>Applies</th>
        <th>Spend/Click</th>
        <th>Spend/Apply</th>
        <th>Conv. Rate</th>
    </tr>";

                            foreach ($results as $row) {
                                $clicks = $row['Clicks'];
                                $applies = $row['Applies'];
                                $cpcSpend = $row['CPC'];
                                $cpaSpend = $row['CPA'];
                                $totalSpend = $cpcSpend + $cpaSpend;

                                $spendPerClick = $clicks > 0 ? $cpcSpend / $clicks : 0;
                                // New calculation for Spend/Apply
                                $spendPerApply = $applies > 0 ? $totalSpend / $applies : 0;
                                $conversionRate = $clicks > 0 ? ($applies / $clicks) * 100 : 0;

                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['Date']) . "</td>";
                                echo "<td>$" . number_format($totalSpend, 2) . "</td>";
                                echo "<td>" . htmlspecialchars($clicks) . "</td>";
                                echo "<td>" . htmlspecialchars($applies) . "</td>";
                                echo "<td>$" . number_format($spendPerClick, 2) . "</td>";
                                echo "<td>$" . number_format($spendPerApply, 2) . "</td>";
                                echo "<td>" . number_format($conversionRate, 2) . "%</td>";
                                echo "</tr>";
                            }
                            echo "</table>";
                            ?>
                        </div>
                        <div id="Referrers" class="tabcontent">
                            <?php
                            // Prepare the SQL query
                            $queryReferrers = "SELECT refurl AS Domain, 
                            SUM(cpc) AS Spend, 
                            COUNT(DISTINCT eventid) AS Clicks, 
                            IF(COUNT(DISTINCT eventid) = 0, 0, SUM(cpc)/COUNT(DISTINCT eventid)) AS SpendPerClick, 
                            MAX(timestamp) AS LastEventTime
                        FROM applevents 
                        WHERE feedid = ?
                        AND MONTH(timestamp) = MONTH(CURRENT_DATE)
                        AND YEAR(timestamp) = YEAR(CURRENT_DATE)
                        GROUP BY refurl
                        ORDER BY Spend DESC;";

                          


                            $stmt = $pdo->prepare($queryReferrers);
                            $stmt->execute([$feedid]);
                            $results = $stmt->fetchAll();

                            echo "<table>";
                            echo "<tr><th>Domain</th><th>Spend</th><th>Clicks</th><th>Spend/Click</th></tr>";

                            if ($results) {
                                foreach ($results as $row) {
                                    echo "<tr>
                <td>" . ($row['Domain'] ?: '(not found)') . "</td>
                <td>$" . htmlspecialchars(number_format($row['Spend'], 2)) . "</td>
                <td>" . htmlspecialchars($row['Clicks']) . "</td>
                <td>$" . htmlspecialchars(number_format($row['SpendPerClick'], 2)) . "</td>
            </tr>";
                                }
                            } else {
                                echo "<tr><td>(not found)</td><td>$0</td><td>0</td><td>$0</td></tr>";
                            }

                            echo "</table>";
                            ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <?php include 'footer.php'; ?>
    <script>
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";

            if (tabName === "Active") {
                chartContainer.style.display = "block"
            } else {
                chartContainer.style.display = "none"
            }
        }
    </script>

</body>

</html>