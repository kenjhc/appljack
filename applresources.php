<?php
include 'database/db.php';

if (!isset($_SESSION['acctnum'])) {
    header("Location: appllogin.php"); // Redirect to login page if not authenticated
    exit();
}

try {
    $acctnum = $_SESSION['acctnum'];

    // Fetch customer feeds along with their company names and campaign names, sorted by customer name
    $stmt = $conn->prepare("
        SELECT
            applcustfeeds.custid, applcustfeeds.feedid, applcustfeeds.feedname,
            applcust.custcompany
        FROM applcustfeeds
        INNER JOIN applcust ON applcustfeeds.custid = applcust.custid
        WHERE applcustfeeds.acctnum = :acctnum
        ORDER BY applcust.custcompany
    ");
    $stmt->execute(['acctnum' => $acctnum]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customerFeeds = [];
    foreach ($rows as $row) {
        $custid = $row['custid'];
        $feedid = $row['feedid'];
        $feedname = $row['feedname'];
        $custcompany = $row['custcompany'];

        if (!isset($customerFeeds[$custid])) {
            $customerFeeds[$custid] = [
                'company' => $custcompany,
                'feeds' => [],
            ];
        }

        $customerFeeds[$custid]['feeds'][] = [
            'id' => $feedid,
            'name' => $feedname,
        ];
    }
} catch (PDOException $e) {
    setToastMessage('error', "Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>XML Feeds</title>
    <?php include 'header.php'; ?>
</head>

<body>
    <?php include 'appltopnav.php'; ?> 

    <?php echo renderHeader(
        "Account Resources"
    ); ?>
 
    <section class="rescources_sec">

        <div class="container-fluid">
            <div class="row">
                    <div class="">
                    <p>Here you can quickly locate everything you need, from XML files to CPA tracking "pixels."</p>


                    <h2>CPA Tracking "Pixel"</h2>
                    <p>Install this wherever you'd like to track a conversion against your CPA budget. We recommend using a tag manager and firing it only when the UTM source is "appljack."</p>
                    <div class="details-container">
                        <p><code>&lt;script&gt;<br />
                                console.log("firing the cpa event");<br />
                                fetch("https://appljack.com/cpa-event.php")<br />
                                .then(function(response) {<br />
                                console.log("success");<br />
                                })<br />
                                .catch(function(error) {<br />
                                console.error('Fetch error:', error);<br />
                                });<br /></p>
                        &lt;/script&gt;</code>
                    </div>


                    <h2>Secondary Event Tracking "Pixel"</h2>
                    <p>Install this wherever you'd like to track a secondary event. <strong>IMPORTANT: These events will NOT count against your set budget.</strong> We recommend using a tag manager and firing it only when the UTM source is "appljack."</p>
                    <div class="details-container">
                        <p><code>&lt;script&gt;<br />
                                console.log("firing the cpa event");<br />
                                fetch("https://appljack.com/secondary-event.php")<br />
                                .then(function(response) {<br />
                                console.log("success");<br />
                                })<br />
                                .catch(function(error) {<br />
                                console.error('Fetch error:', error);<br />
                                });<br /></p>
                        &lt;/script&gt;</code>
                    </div>


                </div>
            </div>
            <div class="row xml_mapping_sec">
            <div class="col-sm-12 col-md-12 px-0">
                    <div class="">
                        <div class="card ">
                        <div class="card-body">
                            <div class="d-flex justify-content-between ">
                              <h5 class="card-title">XML Feeds</h5>
                            </div>
                         
                            
                        <div class="table-responsive">
                            <div class="custom_padding">
                            <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Customer Name</th>
                                <th>Customer XML</th>
                                <th>Campaign Name</th>
                                <th>Campaign XML</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <a href="https://appljack.com/applfeeds/<?= htmlspecialchars($acctnum) ?>.xml">https://appljack.com/applfeeds/<?= htmlspecialchars($acctnum) ?>.xml</a>
                                </td>
                                <td colspan="4"></td>
                            </tr>
                            <?php foreach ($customerFeeds as $custid => $info): ?>
                                <tr>
                                    <td></td>
                                    <td><?= htmlspecialchars($info['company']) ?></td>
                                    <td><a href="https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>.xml">https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>.xml</a></td>
                                    <td colspan="2"></td>
                                </tr>
                                <?php foreach ($info['feeds'] as $feed): ?>
                                    <tr>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td><?= htmlspecialchars($feed['name']) ?></td>
                                        <td><a href="https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($feed['id']) ?>.xml">https://appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($feed['id']) ?>.xml</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
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

</html>