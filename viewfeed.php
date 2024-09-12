<?php
include 'database/db.php';
 
if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php");
    exit();
}

// Check if feedid is present in the query string
if (!isset($_GET['feedid'])) {
    setToastMessage('error', "Feed ID not provided.");
    header("Location: applmasterview.php");
    exit;
}

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
    $feedUrl = "https://appljack.com/applfeeds/" . urlencode($_SESSION['custid']) . "-" . urlencode($feed['feedid']) . ".xml";
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
    <a href="applportal.php?custid=<?php echo urlencode($_SESSION['custid']); ?>"><-- Back to your portal</a>

            <div class="content-container">
                <div class="left-column">
                    <div class="details-container">
                        <h2>Campaign: <?php echo htmlspecialchars($feed['feedname']); ?></h2>
                        <p><b>Campaign ID:</b> <?php echo htmlspecialchars($feedid); ?></p>
                        <p><b>Status:</b> <?php echo htmlspecialchars($feed['status']); ?></p><br>
                        <?php
                        // Construct the local file path from the feed URL
                        // Assuming the feed URL structure and local file path are directly related
                        $localFilePath = '/chroot/home/appljack/appljack.com/html/applfeeds/' . urlencode($_SESSION['custid']) . "-" . urlencode($feed['feedid']) . ".xml";

                        // Check if the XML file exists
                        $fileExistsMessage = file_exists($localFilePath) ? "(feed is ready!)" : "(feed under construction)";
                        ?>
                        <p><strong>Campaign URL:</strong> <a href="<?php echo $feedUrl; ?>" target="_blank"><?php echo $feedUrl; ?></a> <?php echo $fileExistsMessage; ?><br></p><br>
                        <p><strong>Jobs Last Exported:</strong> <?php echo htmlspecialchars(number_format($feed['numjobs'])); ?></p>
                    </div>
                </div>
                <div class="middle-column">
                    <div class="details-container">
                        <!-- Display feed details -->
                        <p><span class="camp-data">Campaign Name:</span> <?php echo htmlspecialchars($feed['feedname']); ?></p>
                        <p><span class="camp-data">Monthly Budget:</span> $<?php echo htmlspecialchars($feed['budget']); ?></p>
                        <p><span class="camp-data">CPC Amount:</span> $<?php echo htmlspecialchars($feed['cpc']); ?></p>
                        <p><span class="camp-data">Keywords:</span> <?php echo htmlspecialchars($feed['custquerykws']); ?></p>
                        <p><span class="camp-data">Industries:</span> <?php echo htmlspecialchars($feed['custqueryindustry']); ?></p>
                        <p><span class="camp-data">Cities:</span> <?php echo htmlspecialchars($feed['custquerycity']); ?></p>
                        <p><span class="camp-data">States:</span> <?php echo htmlspecialchars($feed['custquerystate']); ?></p>
                        <p><span class="camp-data">Companies:</span> <?php echo htmlspecialchars($feed['custqueryco']); ?></p>
                        <button class="edit-feed-button" onclick="window.location.href='editfeed.php?feedid=<?php echo $feedid; ?>'">Edit Campaign</button>
                    </div>
                </div>
                <div class="right-column">
                    <form action="" method="GET">
                        <input type="hidden" name="feedid" value="<?php echo htmlspecialchars($feedid); ?>">
                        <label for="startdate">Start:</label>
                        <input type="date" id="startdate" name="startdate" required>
                        <label for="enddate">End:</label>
                        <input type="date" id="enddate" name="enddate" required>
                        <button type="submit">Show Data</button>
                    </form>
                </div>
            </div>
            <div class="tab">
                <button class="tablinks active" onclick="openTab(event, 'Active')">Active</button>
                <button class="tablinks" onclick="openTab(event, 'Inactive')">Inactive</button>
                <button class="tablinks" onclick="openTab(event, 'Referrers')">Referrers</button>
            </div>

            <div id="Active" class="tabcontent" style="display:block;">
                <div style="width: 100%; height: 250px;">
                    <canvas id="spendChart"></canvas>
                </div>

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
                // Set default dates to the current month's first day and today
                $defaultStartDate = date('Y-m-01'); // First day of the current month
                $defaultEndDate = date('Y-m-d'); // Today's date

                // Check if the user has provided dates, otherwise use the default dates
                $startdate = isset($_GET['startdate']) ? $_GET['startdate'] : $defaultStartDate;
                $enddate = isset($_GET['enddate']) ? $_GET['enddate'] : $defaultEndDate;

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
                $queryReferrers = "SELECT refurl AS Domain, SUM(cpc) AS Spend, COUNT(DISTINCT CASE WHEN eventtype = 'cpc' THEN eventid END) AS Clicks, IF(COUNT(DISTINCT eventid) = 0, 0, SUM(cpc)/COUNT(DISTINCT eventid)) AS SpendPerClick FROM applevents WHERE feedid = ? GROUP BY refurl ORDER BY Spend DESC";

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
                }
            </script>

</body>

</html>