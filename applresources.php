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
                <div class="col-12 mb-4">
                    <div class="w-100 d-flex justify-content-between align-items-center feed-url rounded-md p-3 shadow-md">
                        <div>
                            <p class="healthy-text mb-0 text-dark-green">
                                Here you can quickly locate everything you need, from XML files to CPA tracking "pixels."
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <h4 class="job_title">CPA Tracking "Pixel"</h4>
                        <p class="healthy-text mb-0 text-dark-green text-center my-3">
                            Install this wherever you'd like to track a conversion against your CPA budget. We recommend using a tag manager and firing it only when the UTM source is "appljack."
                        </p>
                        <div class="code-box">
                            <pre><code>&lt;script&gt;
console.log("firing the cpa event");
fetch("http://appljack.test/cpa-event.php")
    .then(function(response) {
        console.log("success");
    })
    .catch(function(error) {
        console.error('Fetch error:', error);
    });
&lt;/script&gt;</code></pre>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="job_card">
                        <h4 class="job_title">Secondary Event Tracking "Pixel"</h4>
                        <p class="healthy-text mb-0 text-dark-green text-center my-3">
                            Install this wherever you'd like to track a secondary event. <strong>IMPORTANT: These events will NOT count against your set budget.</strong> We recommend using a tag manager and firing it only when the UTM source is "appljack."
                        </p>
                        <div class="code-box">
                            <pre><code>&lt;script&gt;
console.log("firing the cpa event");
fetch("<?= getUrl(1) ?>secondary-event.php")
    .then(function(response) {
        console.log("success");
    })
    .catch(function(error) {
        console.error('Fetch error:', error);
    });
&lt;/script&gt;</code></pre>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row xml_mapping_sec">
                <div class="col-sm-12 col-md-12">
                    <div class="">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between ">
                                    <h5 class="card-title">XML Feeds</h5>
                                </div>
                                <div class="table-responsive">
                                    <div class="custom_padding p-3">
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
                                                        <a href="https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($acctnum) ?>.xml">https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($acctnum) ?>.xml</a>
                                                    </td>
                                                    <td colspan="4"></td>
                                                </tr>
                                                <?php foreach ($customerFeeds as $custid => $info): ?>
                                                    <tr>
                                                        <td></td>
                                                        <td class="healthy-text text-dark-green"><?= htmlspecialchars($info['company']) ?></td>
                                                        <td><a href="https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>.xml">https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>.xml</a></td>
                                                        <td colspan="2"></td>
                                                    </tr>
                                                    <?php foreach ($info['feeds'] as $feed): ?>
                                                        <tr>
                                                            <td></td>
                                                            <td></td>
                                                            <td></td>
                                                            <td class="healthy-text text-dark-green"><?= htmlspecialchars($feed['name']) ?></td>
                                                            <td><a href="https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($feed['id']) ?>.xml">https://<?= $envClean ?>appljack.com/applfeeds/<?= htmlspecialchars($custid) ?>-<?= htmlspecialchars($feed['id']) ?>.xml</a></td>
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